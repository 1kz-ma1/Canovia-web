"""Fixed synthetic R1 trials in fresh controlling PTYs. No live target CLI."""
import json
import os
from pathlib import Path
import pty
import select
import signal
import stat
import termios
import time
from unittest.mock import patch

from . import runtime as r
from . import fixtures as legacy
from .r1_tty import PrivateTTYAttempt
from .r1_adapter import SYNTHETIC_PASSWORD
from .r1_driver import IsolatedAttempt, R1Wire
from .protocol import CONTROL_ID

CASES = ('NORMAL', 'AUTH_FAILURE', 'INTERRUPTED', 'QUIT_WRITE_FAILURE',
         'CA_SEAL', 'TARGET_SEAL', 'TOOLING_SEAL', 'TLS_WEAKENING')


def capture_controlling_tty(parent):
    """Private child of pty.fork only, never the caller's interactive terminal."""
    fd = None
    attempt = PrivateTTYAttempt()
    original = None
    try:
        if os.getppid() != parent or os.getsid(0) != os.getpid():
            raise r.Block('R1_TTY_BLOCK')
        fd = os.open('/dev/tty', os.O_RDWR | os.O_NOCTTY | getattr(os,'O_CLOEXEC',0) | getattr(os,'O_NOFOLLOW',0))
        own, inherited = os.fstat(fd), os.fstat(0)
        if (fd <= 2 or not os.isatty(fd) or os.get_inheritable(fd)
                or not stat.S_ISCHR(own.st_mode) or inherited.st_uid != os.geteuid()
                or not os.isatty(0) or os.tcgetpgrp(fd) != os.getpgrp()
                or os.tcgetpgrp(0) != os.getpgrp()
                or termios.tcgetattr(fd) != termios.tcgetattr(0)):
            raise r.Block('R1_TTY_BLOCK')
        original = termios.tcgetattr(fd)
        if attempt.capture(fd) != 'R1_CAPTURED_OFFLINE' or termios.tcgetattr(fd) != original:
            raise r.Block('R1_TTY_BLOCK')
        if attempt._password != SYNTHETIC_PASSWORD:
            raise r.Block('R1_SYNTHETIC_SECRET_REQUIRED')
        return attempt
    except BaseException:
        attempt.discard()
        raise r.Block('R1_TTY_BLOCK') from None
    finally:
        if fd is not None:
            try: os.close(fd)
            except BaseException:
                attempt.discard()
                raise r.Block('R1_TTY_CLEANUP_UNVERIFIED') from None


def child_trial(fault, parent):
    captured = None; boundary = None; bootstrap = None; traces = []
    wires = []; result = {}; cleanup = False
    try:
        if fault != 'TTY_ONLY':
            cfg = r.load()
            boundary = IsolatedAttempt(cfg, None)
            if fault in ('CA_SEAL','TARGET_SEAL','TOOLING_SEAL'):
                key = {'CA_SEAL':'ca_sha256','TARGET_SEAL':'host','TOOLING_SEAL':'tooling_sha'}[fault]
                cfg['r1'][key] = 'SYNTHETIC_TAMPER'
            boundary.seal()  # no input/connection before seal
        captured = capture_controlling_tty(parent)
        if fault == 'TTY_ONLY':
            return dict(result='SYNTHETIC_CONTROLLING_TTY_VERIFIED', traces=[],
                        owner_approval_verified=False, execution_allowed=False)
        boundary.secret = captured._password
        original_read, original_write = r.Channel.read, R1Wire._write_bytes
        original_factory = boundary.factory
        def factory(gate, fixture_fault=None):
            if fault=='AUTH_FAILURE' and gate.role=='data':
                boundary.secret[-1] = ord('x')  # only this fixed synthetic negative
            value=original_factory(gate,fixture_fault)
            wires.append(value)
            return value
        def read(channel,sql,cap=1):
            if fault=='INTERRUPTED' and channel.gate.role=='control' and channel.gate.used==3:
                raise KeyboardInterrupt()
            return original_read(channel,sql,cap)
        def write(wire,data):
            if fault=='QUIT_WRITE_FAILURE' and wire.gate.role=='data' and data==b'\x01\x00\x00\x00\x01':
                raise OSError('SYNTHETIC_QUIT_FAILURE')
            return original_write(wire,data)
        def weak_context(_):
            import ssl
            value=ssl.SSLContext(ssl.PROTOCOL_TLS_CLIENT)
            value.check_hostname=False;value.verify_mode=ssl.CERT_NONE
            return value
        with patch.object(r,'load',return_value=cfg),patch.object(r,'Wire',side_effect=factory),\
             patch.object(r.Channel,'read',read),patch.object(R1Wire,'_write_bytes',write):
            if fault=='TLS_WEAKENING':
                with patch.object(r,'context',side_effect=weak_context):
                    bootstrap=r.Bootstrap();result=bootstrap.run()
            else:
                bootstrap=r.Bootstrap();result=bootstrap.run()
        for wire in boundary.wires:
            if getattr(wire,'gate',None) and wire.gate.sent:
                traces.append(dict(role=wire.gate.role,cid=wire.thread_id(),sql=wire.gate.sent,quit_written=wire.quit_written))
        if fault=='NORMAL':
            legacy.require(result['phase1']=='ISOLATED_PHASE1_VERIFIED')
            legacy.require(len(wires)==2 and sum(len(t['sql']) for t in traces)==15)
            legacy.require(all(w.alias_verified and w.tls_verified and w.password==b'' and w.connect_count==1 for w in wires))
            legacy.require(wires[0].full_auth_packet_seen)  # fresh server: TLS full auth
            legacy.require(result['query_ended'] and result['data_connection_absent'])
        else:
            legacy.require(result['phase1']=='BLOCK')
            legacy.require(result['remote_termination']=='REMOTE_TERMINATION_UNVERIFIED')
        legacy.require(len(boundary.wires)<=2)
        # No re-input/reconnect/retry on this same trial.
        try: bootstrap.run()
        except r.Block: pass
        else: raise r.Block('R1_RETRY_NOT_REJECTED')
        return dict(result='R1_ISOLATED_CASE_VERIFIED', traces=traces,
                    full_tls_auth_packet_seen=any(getattr(w,'full_auth_packet_seen',False) for w in wires),
                    constructor_alias_verified=bool(wires) and all(getattr(w,'alias_verified',False) for w in wires),
                    phase1=result['phase1'], remote_termination=result['remote_termination'],
                    execution_allowed=False,owner_approval_verified=False)
    except BaseException:
        if fault in ('CA_SEAL','TARGET_SEAL','TOOLING_SEAL') and captured is None and boundary and not boundary.wires:
            return dict(result='R1_PREINPUT_SEAL_BLOCK_VERIFIED',traces=[],execution_allowed=False,owner_approval_verified=False)
        raise r.Block('R1_ISOLATED_CASE_BLOCK') from None
    finally:
        if boundary is not None:
            cleanup=boundary.close()
            legacy.require(cleanup and boundary.secret is None and all(w.password==b'' for w in boundary.wires))
        if captured is not None:
            captured.discard()
            legacy.require(not captured._password)


def trial(fault='TTY_ONLY'):
    if fault not in CASES+('TTY_ONLY',): raise r.Block('R1_FIXTURE_SCOPE_BLOCK')
    # A fresh session/controlling PTY is always created. No real terminal input.
    read_fd,write_fd=os.pipe();parent=os.getpid();start=time.monotonic()
    pid,master=pty.fork()
    if pid==0:
        os.close(read_fd)
        try: value=child_trial(fault,parent)
        except BaseException: value=dict(result='BLOCK',traces=[],execution_allowed=False)
        try:
            with os.fdopen(write_fd,'wb') as output: output.write(json.dumps(value).encode('ascii'))
        finally: os._exit(0)
    os.close(write_fd)
    raw=bytearray();terminal=bytearray();sent=False;exited=False;status=None
    try:
        streams={read_fd,master}
        while streams or not exited:
            if time.monotonic()-start>=120: raise r.Block('R1_TRIAL_TIMEOUT')
            if not sent and not exited:
                try: hidden=not termios.tcgetattr(master)[3] & (termios.ECHO|termios.ECHONL)
                except OSError: hidden=False
                if hidden:
                    os.write(master,SYNTHETIC_PASSWORD+b'\n');sent=True
            ready,_,_=select.select(list(streams),[],[],.01)
            for fd in ready:
                try: data=os.read(fd,4096)
                except OSError:
                    if fd!=master: raise
                    data=b''
                if not data:streams.remove(fd);continue
                target=raw if fd==read_fd else terminal
                target.extend(data)
                if len(target)>65536: raise r.Block('R1_FIXTURE_CAP')
            if not exited:
                done,status=os.waitpid(pid,os.WNOHANG);exited=done==pid
        if terminal or status!=0: raise r.Block('R1_TTY_OUTPUT_BLOCK')
        value=json.loads(raw)
        if value['result']=='BLOCK':raise r.Block('R1_CHILD_BLOCK')
        value.update(driver_refs_cleared=True,owned_buffer_erased=True,controlling_tty_closed=True)
        return value
    except BaseException:
        raise r.Block('R1_TRIAL_BLOCK') from None
    finally:
        os.close(read_fd);os.close(master)
        if not exited:
            # Reconfirm unreaped child ownership before signalling. A PID from
            # a child already reaped on an interrupted path must never be killed.
            try: done,_=os.waitpid(pid,os.WNOHANG)
            except ChildProcessError: done=pid
            if done!=pid:
                # Local child only; never MySQL KILL. Remote remains unverified.
                try: os.kill(pid,signal.SIGKILL)
                except ProcessLookupError: pass
                except BaseException: raise r.Block('R1_CHILD_CLEANUP_UNVERIFIED') from None
                until=time.monotonic()+2
                while time.monotonic()<until:
                    if os.waitpid(pid,os.WNOHANG)[0]==pid:break
                    time.sleep(.01)
                else: raise r.Block('R1_CHILD_CLEANUP_UNVERIFIED')



def run():
    reports={};traces=[]
    # Run normal first on the fresh owned server, so full TLS auth is observed.
    for fault in CASES:
        value=trial(fault);traces.extend(value.pop('traces'));reports[fault]=value
    baseline=legacy.run()  # existing 18 cases, same servers; no repeated rebuild
    traces.extend(json.loads(Path('/out/traces.json').read_text()))
    Path('/out/traces.json').write_text(json.dumps(traces))
    os.chmod('/out/traces.json',0o600)
    baseline['r1_driver_cases']=reports
    baseline['r1_driver_level']='ISOLATED_REAL_PYMYSQL_TESTED'
    baseline['real_aiven_connected']=False
    return baseline


if __name__=='__main__':
    try: value=run();code=0
    except BaseException: value=dict(result='BLOCK',code='R1_ISOLATED_FIXTURE_BLOCK',real_aiven_connected=False);code=2
    print(json.dumps(value,sort_keys=True));raise SystemExit(code)
