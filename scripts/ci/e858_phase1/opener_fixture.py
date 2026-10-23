"""Owned internal-Docker opener backend ONLY, excluded from public bundle.

No arbitrary endpoint, credential input, CLI backend switch or live authority.
DNS child is fixed, non-spawning and receives only a synthetic name/mode over
a pipe. The supervised process may fail closed if child reaping is unknown.
"""
import json
import os
from pathlib import Path
import socket
import ssl
import subprocess
import sys
import time
from unittest.mock import patch

from . import practical_fixture as old, practical as p, runtime as r
from .opener_candidate import OpenerCandidate, Peer
from .phase1_application import _phase1_program
from .backend_candidate import exchange, reap
from .launcher_candidate import LauncherCandidate
from .private_binding import Block

HOSTS=('mysql.synthetic.invalid','mismatch.synthetic.invalid')
DNS_CHILD='''import json,socket,sys,time,os,signal
v=json.loads(sys.stdin.buffer.read(256))
if set(v)!={'host','mode'} or v['host'] not in ('mysql.synthetic.invalid','mismatch.synthetic.invalid'):sys.exit(2)
mode=v['mode']
if mode=='timeout':time.sleep(60);sys.exit(2)
elif mode=='malformed':sys.stdout.write('not-json');sys.exit(0)
elif mode=='oversize':sys.stdout.write('x'*8193);sys.exit(0)
elif mode=='stderr':sys.stderr.write('SYNTHETIC_PRIVATE_NEVER_RENDER');sys.exit(2)
elif mode=='signal':os.kill(os.getpid(),signal.SIGTERM);sys.exit(2)
elif mode=='flood':
 while True:sys.stdout.write('x'*8192);sys.stdout.flush()
elif mode!='normal':sys.exit(2)
rows=socket.getaddrinfo(v['host'],3306,type=socket.SOCK_STREAM,proto=socket.IPPROTO_TCP)
if not 1<=len(rows)<=32:sys.exit(2)
raw=json.dumps(rows)
if len(raw)>8192:sys.exit(2)
sys.stdout.write(raw)
'''


def namespace():
    value=json.loads(Path('/synthetic/practical.json').read_bytes())
    if value['kind']!='OWNED_INTERNAL_DOCKER_ONLY' or not value['owned_ips']:raise Block()
    return value


_reap=reap  # existing fixture callers; portable supervisor owns pipe cleanup


class DNSWorker:
    """Fixed child starts BEFORE R1 capture; no password-bearing fork/exec."""
    created=[]
    def __init__(self):
        self.used=False;self.process=subprocess.Popen([sys.executable,'-I','-B','-c',DNS_CHILD],stdin=subprocess.PIPE,
            stdout=subprocess.PIPE,stderr=subprocess.PIPE,close_fds=True,cwd='/',
            env={'PYTHONDONTWRITEBYTECODE':'1'})
        self.created.append(self)
    def close(self,until):return _reap(self.process,until)


def owned_resolve(host,until,mode='normal',worker=None):
    proc=None;reaped=False;failed=False;result=None;status='DNS_SCOPE_REJECTED'
    try:
        if host not in HOSTS or mode not in ('normal','timeout','malformed','oversize','stderr','signal','flood'):raise Block()
        namespace()  # only the host-attested internal fixture can spawn
        remaining=until-time.monotonic()-.25  # reserve within same deadline for reaping
        if remaining<=0:raise Block()
        status='DNS_CHILD_ERROR'
        # Direct creation is used only by the no-input unit tests. The real
        # owned backend MUST pass its pre-input child, never spawn after R1.
        if worker is None:worker=DNSWorker()
        if type(worker) is not DNSWorker or worker.used:raise Block()
        worker.used=True;proc=worker.process
        try:raw=exchange(proc,json.dumps(dict(host=host,mode=mode)).encode('ascii'),until)
        except Block as error:
            reaped=error.dns_reaped;status=error.dns_status;raise
        reaped=True
        status='DNS_RESPONSE_INVALID'
        result=json.loads(raw)
    except subprocess.TimeoutExpired:failed=True;status='DNS_TIMEOUT'
    except BaseException:failed=True
    finally:
        if proc is not None:reaped=_reap(proc,until)
    if failed or not reaped:
        error=Block();error.dns_reaped=reaped
        error.dns_status=status if reaped or proc is None else 'DNS_CHILD_CLEANUP_UNVERIFIED'
        raise error from None
    return result


class OwnedTLS:
    def __init__(self,wire):self.wire=wire
    def wrap_socket(self,raw,*,server_hostname):
        wrapped=None;wire=self.wire;plan=wire.plan
        try:
            if wire.fault=='SNI_CHANGED':server_hostname='mismatch.synthetic.invalid'
            if wire.fault=='CA_STORE_CHANGED':
                plan.context.load_verify_locations(cadata=Path('/synthetic/wrong-ca.pem').read_text())
            if wire.fault=='PEER_CHANGED':plan.peer=Peer(plan.peer.family,('198.51.100.9',3306))
            plan.before_tls(raw,server_hostname)
            raw.settimeout(plan.remaining())
            wire.tls_handshake_started=True
            wrapped=plan.context.wrap_socket(raw,server_hostname=server_hostname)
            plan.after_tls(wrapped)  # must complete BEFORE driver gets TLS/auth socket
            return wrapped
        except BaseException:
            if wrapped is not None:wrapped.close()
            raise


class OwnedWire(old.FixtureWire):
    fault='NORMAL'
    created=[]
    def initialize_fixture(self,gate,secret,target):
        super().initialize_fixture(gate,secret,target)  # fixed owned name/CA/DB/principal/password guard
        self.created.append(self);self.plan=None;self.dns_started=False;self.dns_reaped=False
        self.authentication_packets=0;self.tls_handshake_started=False
    def connect(self,sock=None):
        raw=None
        try:
            if (sock is not None or self.connect_count or self.plan is not None
                    or type(self.worker) is not DNSWorker or self.worker.used):raise Block()
            self.plan=OpenerCandidate(self.entry,self.gate,self.ctx)
            mode={'DNS_TIMEOUT':'timeout','DNS_MALFORMED':'malformed','DNS_OVERSIZE':'oversize','DNS_SIGNAL':'signal','DNS_STDERR':'stderr','DNS_FLOOD':'flood'}.get(self.fault,'normal')
            self.dns_started=True
            try:
                answers=owned_resolve(self.target.host,self.plan.until,mode,self.worker)
                self.dns_reaped=True;self.dns_status='DNS_ANSWERED'
            except Block as error:
                self.dns_reaped=getattr(error,'dns_reaped',False)
                self.dns_status=getattr(error,'dns_status','DNS_CHILD_CLEANUP_UNVERIFIED')
                raise
            if self.fault=='DNS_UNOWNED':answers.append([socket.AF_INET,socket.SOCK_STREAM,6,'',['198.51.100.9',3306]])
            if self.fault=='DNS_BAD_PORT':answers[0][4][1]=3307
            peer=self.plan.resolution(answers)
            # Synthetic containment only. This list is NOT an Aiven IP policy.
            if any(a[4][0] not in self.namespace['owned_ips'] for a in answers):raise Block()
            raw=socket.socket(peer.family,socket.SOCK_STREAM,socket.IPPROTO_TCP)
            raw.settimeout(self.plan.remaining());raw.connect(peer.address)
            if raw.getpeername()!=peer.address:raise Block()
            self.ctx=OwnedTLS(self)
            r.StrictConnection.connect(self,sock=raw);raw=None
            if not self.plan.verified:raise Block()
            self.tls_verified=True
        except BaseException as error:
            original=getattr(error,'original_exception',error)
            self.failure=('CHAIN' if isinstance(original,ssl.SSLCertVerificationError) and original.verify_code in (18,19,20,21)
                else 'HOSTNAME' if isinstance(original,ssl.SSLCertVerificationError) and original.verify_code==62
                else 'AUTH_REJECTED' if error.args and error.args[0]==1045 else 'OTHER_FIXED_FAILURE')
            if raw is not None:raw.close()
            self.close();raise Block() from None
    def _read_bytes(self,count):
        previous=self.gate.deadline
        try:
            if not self.authenticated and self.plan is not None:
                self.gate.deadline=min(previous,self.plan.until)
                self.plan.remaining()
            return super()._read_bytes(count)
        finally:self.gate.deadline=previous
    def _write_bytes(self,data):
        previous=self.gate.deadline
        try:
            if not self.authenticated and self.plan is not None:
                self.gate.deadline=min(previous,self.plan.until)
                self.plan.remaining()
            return super()._write_bytes(data)
        finally:self.gate.deadline=previous
    def write_packet(self,data):
        if isinstance(getattr(self,'_sock',None),ssl.SSLSocket) and not self.authenticated:
            if not self.plan.verified:raise Block()
            self.authentication_packets+=1
        return super().write_packet(data)
    def close(self):
        try:return super().close()
        finally:
            if getattr(self,'plan',None) is not None:self.plan.close()


class OwnedBoundary(old.FixtureBoundary):
    created=[]
    def __init__(self,entry):
        super().__init__(entry);self.workers=[];self.launcher=None
        self.created.append(self)
        try:
            if entry.secret is not None or entry.target.host not in HOSTS:raise Block()
            value=namespace()
            self.launcher=LauncherCandidate(entry,value['pins']['tooling_sha'],value['bundle_sha256'])
            for _ in range(2):
                entry.check();self.workers.append(DNSWorker())
        except BaseException:
            self.close();raise Block() from None
    def factory(self,gate,fault=None):
        try:self.launcher._connection(gate)
        except BaseException:self.close();raise Block() from None
        wire=super().factory(gate,fault)
        wire.entry=self.entry;wire.worker=self.workers[len(self.wires)-1]
        return wire
    def close(self):
        if self.launcher is not None:self.launcher.close()
        okay=super().close()
        for worker in self.workers:okay=worker.close(self.entry.until) and okay
        return okay


class OwnedAdapter(old.IsolatedAdapter):
    def prepare(self):
        value=namespace();_phase1_program(self.entry,value['pins']['tooling_sha'],value['bundle_sha256'])
        config,bundle=self.prepare_reviewed()
        self.boundary=OwnedBoundary(self.entry)
        return config,bundle


CASES=('NORMAL','DNS_TIMEOUT','DNS_MALFORMED','DNS_OVERSIZE','DNS_UNOWNED','DNS_BAD_PORT',
       'PEER_CHANGED','SNI_CHANGED','CA_STORE_CHANGED','WRONG_CA','WRONG_HOST','AUTH_FAILURE',
       'GRANTS_MISSING','GRANTS_EXCESS','SERVER_CHANGED','CONTROL_LOST','TIMEOUT','QUIT_FAILURE',
       'DNS_SIGNAL','DNS_STDERR','DNS_FLOOD','INTERRUPT','RECONNECT','REVIEW_REVOKED','DATA_LOST')


def child_case(fault,parent):
    OwnedWire.created=[];OwnedWire.fault=fault;DNSWorker.created=[];OwnedBoundary.created=[]
    mapped='PHASE1' if fault=='NORMAL' else fault
    capture=p.ReviewedEntry.capture;captures=[]
    def before_input(entry):
        if len(DNSWorker.created)!=2 or any(w.used or w.process.poll() is not None for w in DNSWorker.created):raise Block()
        if len(OwnedBoundary.created)!=1:raise Block()
        OwnedBoundary.created[0].launcher._before_input()
        captures.append(True)
        return capture(entry)
    original_read=old.b.Channel.read
    def read(channel,sql,cap=1):
        if fault=='DATA_LOST' and channel.gate.role=='data' and channel.gate.used==4:channel.conn.close()
        return original_read(channel,sql,cap)
    with patch.object(old.b.Channel,'read',read),patch.object(old,'FixtureWire',OwnedWire),patch.object(old,'IsolatedAdapter',OwnedAdapter),\
            patch.object(p.ReviewedEntry,'capture',before_input):
        result=old.child(mapped,parent)
    wires=OwnedWire.created
    if fault in CASES[1:9] and (result['sql_count'] or any(w.authenticated for w in wires)):raise Block()
    if not wires or not all(w.dns_reaped for w in wires):raise Block()
    if len(DNSWorker.created)!=2 or any(w.process.poll() is None for w in DNSWorker.created):raise Block()
    if len(captures)!=1 or not all(not w.authentication_packets or w.plan.verified for w in wires):raise Block()
    if fault in CASES[1:11] and any(w.authentication_packets for w in wires):raise Block()
    if fault in ('DNS_TIMEOUT','DNS_MALFORMED','DNS_OVERSIZE','DNS_SIGNAL','DNS_STDERR','DNS_FLOOD'):
        expected={'DNS_TIMEOUT':'DNS_TIMEOUT','DNS_SIGNAL':'DNS_CHILD_SIGNAL','DNS_STDERR':'DNS_CHILD_ERROR'}.get(fault,'DNS_RESPONSE_INVALID')
        if (len(wires)!=1 or wires[0].dns_status!=expected or result['sql_count']
                or any(w.authentication_packets for w in wires)):raise Block()
    if any(v.launcher.state!='STOPPED' or v.launcher.entry is not None for v in OwnedBoundary.created):raise Block()
    result.update(launcher_stopped=True,tls_verified_before_auth=(fault=='NORMAL' and all(w.tls_verified for w in wires)),
        dns_workers_started_before_input=True,dns_children_reaped=True,dns_status=[w.dns_status for w in wires],
        authentication_packets=sum(w.authentication_packets for w in wires),
        tls_handshakes_started=sum(w.tls_handshake_started for w in wires),
        server_sessions_observed=sum(hasattr(w,'server_thread_id') for w in wires),
        private_cids=[int(w.thread_id()) for w in wires if hasattr(w,'server_thread_id')])
    return result


def run():
    # Host ran the existing suite once, reset only the owned auth cache and
    # preserved private traces. No duplicate migration/schema construction.
    previous=json.loads(Path('/b1/practical-result.json').read_text())
    if previous['result']!='B1_INTEGRATION_VERIFIED' or previous['real_aiven_connected']:raise Block()
    traces=json.loads(Path('/b1/traces.json').read_text());cids=[]
    for fault in CASES:
        with patch.object(old.tty,'child_trial',side_effect=lambda _,parent:child_case(fault,parent)):
            result=old.tty.trial('NORMAL')
        traces.extend(result.pop('traces'));cids.extend(result.pop('private_cids'))
        previous['cases']['OPENER_'+fault]=result
    Path('/b1/traces.json').write_text(json.dumps(traces));os.chmod('/b1/traces.json',0o600)
    Path('/b1/opener-cids.json').write_text(json.dumps(sorted(set(cids))));os.chmod('/b1/opener-cids.json',0o600)
    previous.update(opener_cases=len(CASES),activation='OWNED_INTERNAL_FIXTURE_ONLY')
    return previous


if __name__=='__main__':
    try:value=run();code=0
    except BaseException:value=dict(result='BLOCK',code='ISOLATED_OPENER_BLOCK',real_aiven_connected=False);code=2
    print(json.dumps(value,sort_keys=True));raise SystemExit(code)
