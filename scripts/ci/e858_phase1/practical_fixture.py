"""Actual communication adapter ONLY for the owned Docker fixture namespace.

Not in the candidate archive. No endpoint/password/CA CLI, no live switch.
Fixture IP membership confines tests, it is NOT a proposed Aiven IP policy.
"""
import json
import os
from pathlib import Path
import socket
import ssl
import tempfile
import time
from unittest.mock import patch

from . import practical as p, b1_bridge as b, runtime as r
from . import r1_driver_fixtures as tty
from .r1_adapter import SYNTHETIC_PASSWORD
from .private_binding import Block, sha, Q0_SHA,Q1_SHA,SQL
from .private_distribution import runtime_components
from e858_b_offline import canonical,digest,SOURCE,RELEASE


class FixtureWire(p.PrivateWire):
    def initialize_fixture(self,gate,secret,target):
        cfg=json.loads(Path('/synthetic/practical.json').read_text())
        if (cfg['kind']!='OWNED_INTERNAL_DOCKER_ONLY' or target.host not in ('mysql.synthetic.invalid','mismatch.synthetic.invalid')
                or target.port!=3306 or target.database!=r.DB or target.principal!='e858_review_inspector'
                or sha(target.ca) not in cfg['ca_hashes'] or type(secret) is not bytearray
                or secret not in (SYNTHETIC_PASSWORD,SYNTHETIC_PASSWORD[:-1]+b'x')):raise Block()
        self.namespace=cfg
        self.initialize(gate,secret,target)
    def connect(self,sock=None):
        raw=None
        try:
            if sock is not None or self.connect_count:raise Block()
            addresses=socket.getaddrinfo(self.target.host,self.target.port,type=socket.SOCK_STREAM)
            if not addresses or len(addresses)>32:raise Block()
            # Fresh DNS per connection. The independent host lifecycle attested
            # these internal endpoints. Never impose this list on real Aiven.
            if any(a[0] not in (socket.AF_INET,socket.AF_INET6) or a[4][0] not in self.namespace['owned_ips'] for a in addresses):raise Block()
            family,kind,proto,_,peer=addresses[0]
            raw=socket.socket(family,kind,proto);raw.settimeout(min(2,self.gate.deadline-time.monotonic()))
            raw.connect(peer)
            if raw.getpeername()[:2]!=peer[:2]:raise Block()
            r.StrictConnection.connect(self,sock=raw);raw=None
            if (not isinstance(self._sock,ssl.SSLSocket) or not self._sock.cipher()
                    or self._sock.server_hostname!=self.target.host or not self._sock.getpeercert().get('subjectAltName')):raise Block()
            self.tls_verified=True
        except BaseException:
            if raw is not None:raw.close()
            self.close();raise Block() from None


class FixtureBoundary(p.PrivateBoundary):
    def factory(self,gate,fault=None):
        try:
            self.entry.check()
            cfg=self.entry.review.program.config()
            if (self.closed or fault is not None or len(self.wires)>=2
                    or gate.role!=('control','data')[len(self.wires)]
                    or gate.q0!=cfg['q0'] or gate.q1!=cfg['q1']
                    or gate.deadline>self.entry.until):raise Block()
            wire=object.__new__(FixtureWire);self.wires.append(wire)
            wire.initialize_fixture(gate,self.secret,self.entry.target)
            return wire
        except BaseException:self.close();raise Block() from None


class IsolatedAdapter(p.Adapter):
    def prepare(self):
        config,bundle=self.prepare_reviewed()
        self.boundary=FixtureBoundary(self.entry)
        return config,bundle


CASES=('PHASE1','METADATA','WRONG_CA','WRONG_HOST','AUTH_FAILURE','GRANTS_EXCESS','GRANTS_MISSING',
       'SERVER_CHANGED','CONTROL_LOST','RECONNECT','SQL','EXPORT','TIMEOUT','INTERRUPT','QUIT_FAILURE')


def child_case(fault,parent):
    if os.getppid()!=parent:raise Block()
    namespace=json.loads(Path('/synthetic/practical.json').read_text())
    program=p.Program(Path('/synthetic/b1-candidate.zip').read_bytes(),namespace['bundle_sha256'],namespace['pins'])
    entry=p.ReviewedEntry();review=None;adapter=None
    with tempfile.TemporaryDirectory(dir='/b1',prefix='practical-') as temporary:
        base=Path(temporary);private=base/'private';private.mkdir(mode=0o700)
        independent=base/'owner-record';independent.mkdir(mode=0o700)
        now=int(time.time());scope='metadata' if fault=='METADATA' else 'phase1'
        ca=Path('/synthetic/'+('wrong-ca.pem' if fault=='WRONG_CA' else 'ca.pem')).read_bytes()
        host='mismatch.synthetic.invalid' if fault=='WRONG_HOST' else 'mysql.synthetic.invalid'
        record=dict(kind='E858_PHASE1_PRIVATE_BINDING_V1',provenance='OWNER_PRIVATE_2026_10_21',source_sha=SOURCE,
            release_ref=RELEASE,tooling_sha=program.pins['tooling_sha'],sql_sha256=SQL,q0_sha256=Q0_SHA,q1_sha256=Q1_SHA,
            starts_at=now-1,expires_at=now+120,target=dict(service='ONE_SYNTHETIC_SERVICE',database=r.DB,host=host,
                port=3306,principal='e858_review_inspector'),tls=dict(mode='VERIFY_IDENTITY',minimum='TLS1.2',san=host,ca_sha256=sha(ca)))
        raw=canonical(record)
        value=dict(kind='E858_B1_PRIVATE_REVIEW_V1',state='OWNER_REVIEW_PENDING_NOT_GO',source_sha=SOURCE,release_ref=RELEASE,
            tooling_sha=program.pins['tooling_sha'],bundle_sha256=program.sha,sql_sha256=SQL,
            reference_sha256=program.pins['reference_sha256'][namespace['series']],wheel_sha256=p.dist.WHEEL_SHA,
            runtime_sha256=program.runtime,binding_sha256=sha(raw),scope=scope,engine=namespace['series'],attempt_id='a'*32,
            starts_at=now-1,expires_at=now+120)
        for directory,name,data in ((private,'target.json',raw),(private,'ca.pem',ca),(independent,'review.json',canonical(value))):
            path=directory/name;path.write_bytes(data);path.chmod(0o600)
        original_read=b.Channel.read;original_write=r.Wire._write_bytes;capture=p.ReviewedEntry.capture
        secret_refs=[]
        def capture_once(obj):
            result=capture(obj);secret_refs.append(result._password)
            if fault=='AUTH_FAILURE':result._password[-1]=ord('x')
            return result
        def read(channel,sql,cap=1):
            role=channel.gate.role;used=channel.gate.used
            if role=='data' and used==4:
                if fault=='INTERRUPT':raise KeyboardInterrupt()
                if fault=='TIMEOUT':channel.query_seconds=0
                if fault=='RECONNECT':channel.conn.connect()
                if fault in ('SQL','EXPORT'):
                    return original_read(channel,b'SELECT * FROM users;' if fault=='SQL' else b'SELECT 1 INTO OUTFILE "x";',cap)
            if role=='control' and used==3 and fault=='CONTROL_LOST':channel.conn.close()
            result=original_read(channel,sql,cap)
            if role=='control' and used==2:
                if fault=='GRANTS_EXCESS':return 'GRANT ALL PRIVILEGES ON *.* TO `e858_review_inspector`@`%`\n'
                if fault=='GRANTS_MISSING':return ''
            if role=='data' and used==1 and fault=='SERVER_CHANGED':
                fields=result.rstrip('\n').split('\t');fields[3]='12345678-1234-1234-1234-123456789012'
                return '\t'.join(fields)+'\n'
            return result
        def send(wire,data):
            if fault=='QUIT_FAILURE' and wire.gate.role=='data' and data==b'\x01\x00\x00\x00\x01':raise OSError()
            return original_write(wire,data)
        try:
            review=p.PrivateReview(str(independent),sha(canonical(value)),program)
            entry.begin(str(private),human_supervised=True);entry.bind_review(review)
            adapter=IsolatedAdapter(entry,scope)
            with patch.object(p.ReviewedEntry,'capture',capture_once),patch.object(b.Channel,'read',read),patch.object(r.Wire,'_write_bytes',send):
                result=adapter.run()
            wires=adapter.boundary.wires
            traces=[dict(role=w.gate.role,cid=w.thread_id(),sql=w.gate.sent,quit_written=w.quit_written) for w in wires if w.gate.sent]
            if fault in ('PHASE1','METADATA'):
                if (result['result']!='ISOLATED_B1_BRIDGE_VERIFIED' or not result['connection_ended']
                        or result['sql_count']!=(30 if fault=='METADATA' else 15)
                        or len(wires)!=2 or not all(w.alias_verified and w.tls_verified for w in wires)):raise Block()
                if fault=='METADATA' and result['strict_result']!='REVIEW_REQUIRED':raise Block()
                if fault=='PHASE1' and not wires[0].full_auth_packet_seen:raise Block()
            elif result['result']!='BLOCK' or result['remote_termination']!='REMOTE_TERMINATION_UNVERIFIED':raise Block()
            if any(w.password for w in wires) or any(secret_refs) or not entry.closed:raise Block()
            try:adapter.run()
            except Block:pass
            else:raise Block()
            result.update(result='PRACTICAL_CASE_VERIFIED',traces=traces,driver_refs_cleared=True,owned_buffer_erased=True)
            return result
        finally:
            entry.close()
            if review is not None:review.close()


def child(fault,parent):
    try:return child_case(fault,parent)
    except BaseException as error:
        import traceback
        Path('/b1/failure.json').write_text(json.dumps(dict(case=fault,type=type(error).__name__,frames=[(Path(f.filename).name,f.lineno) for f in traceback.extract_tb(error.__traceback__)])))
        os.chmod('/b1/failure.json',0o600)
        raise


def run():
    reports={};traces=[]
    for fault in CASES:
        with patch.object(tty,'child_trial',side_effect=lambda _,parent:child(fault,parent)):
            value=tty.trial('NORMAL')
        traces.extend(value.pop('traces'));reports[fault]=value
    # Regression of the affected common engine, same owned servers.
    from . import b1_fixtures
    previous=b1_fixtures.run()
    traces.extend(json.loads(Path('/b1/traces.json').read_text()))
    Path('/b1/traces.json').write_text(json.dumps(traces));os.chmod('/b1/traces.json',0o600)
    return dict(result='B1_INTEGRATION_VERIFIED',cases=reports,legacy_cases=previous['cases'],real_aiven_connected=False,execution_allowed=False,
        production_go=False,live_tooling_adopted=False,owner_approval_verified=False,
        evidence=['SYNTHETIC_TESTED','ISOLATED_MYSQL_TLS_TESTED','REAL_AIVEN_UNVERIFIED'])


if __name__=='__main__':
    try:value=run();code=0
    except BaseException:value=dict(result='BLOCK',code='ISOLATED_PRACTICAL_FIXTURE_BLOCK',real_aiven_connected=False);code=2
    print(json.dumps(value,sort_keys=True));raise SystemExit(code)
