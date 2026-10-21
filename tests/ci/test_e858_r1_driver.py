"""New driver/controlling-TTY boundary only; no real endpoint or credentials."""
import contextlib
import hashlib
import io
import os
from pathlib import Path
import socket
import ssl
import sys
from types import SimpleNamespace
import unittest
from unittest.mock import patch

ROOT=Path(__file__).resolve().parents[2]
sys.path.insert(0,str(ROOT/'scripts/ci'))
from e858_phase1 import runtime as r, r1_driver as d, r1_driver_fixtures as f
from e858_phase1.protocol import Gate, INIT
from e858_phase1.r1_adapter import SYNTHETIC_PASSWORD
import e858_phase1_host as host

Q0=b'SELECT DATABASE(), CURRENT_USER(), VERSION(), @@server_uuid;\n'
Q1=b'SHOW GRANTS FOR CURRENT_USER();\n'
CA=b'SYNTHETIC_PUBLIC_CA_BYTES_ONLY'
CANARY='SYNTHETIC_PRIVATE_DIAGNOSTIC_NEVER_RENDER'


def config():
    digest=hashlib.sha256(CA).hexdigest()
    pin=dict(kind='OWNED_DOCKER_R1_ONLY',host=r.HOST,port=3306,database=r.DB,principal=r.USER,san=r.HOST,
             source_sha=r.SOURCE,release_ref=r.RELEASE,tooling_sha='a'*40,sql_sha256=r.SQL_SHA,ca_sha256=digest,ipv4='172.18.0.2')
    return dict(r1=pin,pins=dict(tooling_sha='a'*40),expected=dict(binding=dict(ca=digest)),q0=Q0,q1=Q1)


class DriverBoundaryTests(unittest.TestCase):
    def filesystem(self):
        return contextlib.ExitStack()

    def enter_fs(self, stack):
        stack.enter_context(patch.object(Path,'is_symlink',return_value=False))
        stack.enter_context(patch.object(Path,'is_file',return_value=True))
        stack.enter_context(patch.object(Path,'stat',return_value=SimpleNamespace(st_size=len(CA))))
        stack.enter_context(patch.object(Path,'read_bytes',return_value=CA))

    def test_fresh_controlling_pty_captures_only_synthetic_password(self):
        out,err=io.StringIO(),io.StringIO()
        with contextlib.redirect_stdout(out),contextlib.redirect_stderr(err): value=f.trial('TTY_ONLY')
        self.assertEqual(value['result'],'SYNTHETIC_CONTROLLING_TTY_VERIFIED')
        self.assertTrue(value['owned_buffer_erased'] and value['controlling_tty_closed'])
        self.assertEqual(out.getvalue()+err.getvalue(),'')
        self.assertFalse(value['execution_allowed'] or value['owner_approval_verified'])

    def test_parent_interruption_is_sanitized_and_only_owned_child_reaped(self):
        owned=[]
        original_fork=f.pty.fork
        def fork_fixture():
            child,master=original_fork()
            if child:owned.append(child)
            return child,master
        with patch.object(f.pty,'fork',side_effect=fork_fixture), \
                patch.object(f.select,'select',side_effect=KeyboardInterrupt(CANARY)):
            with self.assertRaises(r.Block) as raised:f.trial('TTY_ONLY')
        self.assertNotIn(CANARY,str(raised.exception))
        self.assertEqual(len(owned),1)
        with self.assertRaises(ChildProcessError):os.waitpid(owned[0],os.WNOHANG)

    def test_nonchild_cannot_open_callers_controlling_terminal(self):
        with patch.object(f.os,'getsid',return_value=-1),patch.object(f.os,'open') as opened:
            with self.assertRaises(r.Block): f.capture_controlling_tty(f.os.getppid())
            opened.assert_not_called()

    def test_unknown_trial_scope_never_forks(self):
        with patch.object(f.pty,'fork') as forked:
            with self.assertRaises(r.Block): f.trial(CANARY)
            forked.assert_not_called()

    def test_fixed_binding_fields_tamper_rejected_without_connect(self):
        for key in config()['r1']:
            value=config();value['r1'][key]=CANARY
            attempt=d.IsolatedAttempt(value,bytearray(SYNTHETIC_PASSWORD))
            with self.subTest(field=key),self.filesystem() as stack:
                self.enter_fs(stack)
                with patch.object(socket,'create_connection') as connected:
                    with self.assertRaises(r.Block) as error: attempt.seal()
                    self.assertNotIn(CANARY,str(error.exception));connected.assert_not_called()
            attempt.close()

    def test_modified_ca_or_q0_and_symlink_are_rejected(self):
        for fault in ('ca','q0','symlink'):
            cfg=config()
            if fault=='q0':cfg['q0']=Q0+b'SELECT 1;'
            with self.filesystem() as stack:
                self.enter_fs(stack)
                if fault=='ca':stack.enter_context(patch.object(Path,'read_bytes',return_value=b'TAMPER'))
                if fault=='symlink':stack.enter_context(patch.object(Path,'is_symlink',return_value=True))
                with self.assertRaises(r.Block):d.IsolatedAttempt(cfg,None).seal()

    def test_debug_logging_or_wrong_dependency_is_rejected(self):
        for obj,name,value in ((d._auth,'DEBUG',True),(d.connections,'DEBUG',True),(d,'version',lambda _: '0')):
            with self.filesystem() as stack:
                self.enter_fs(stack)
                with patch.object(obj,name,value):
                    with self.assertRaises(r.Block):d.IsolatedAttempt(config(),None).seal()

    def test_actual_pymysql_constructor_aliases_mutable_secret(self):
        secret=bytearray(SYNTHETIC_PASSWORD)
        attempt=d.IsolatedAttempt(config(),secret)
        with self.filesystem() as stack:
            self.enter_fs(stack)
            ctx=ssl.SSLContext(ssl.PROTOCOL_TLS_CLIENT)
            ctx.minimum_version=ssl.TLSVersion.TLSv1_2
            stack.enter_context(patch.object(r,'context',return_value=ctx))
            stack.enter_context(patch.object(socket,'create_connection',side_effect=AssertionError('NO_NETWORK')))
            wires=[]
            for role in ('control','data'):
                gate=Gate(role,Q0,Q1,attempt.until)
                wire=attempt.factory(gate);wires.append(wire)
                self.assertIs(wire.password,secret)
                self.assertTrue(wire.alias_verified)
            self.assertTrue(attempt.close())
        self.assertEqual(secret,b'')
        self.assertTrue(all(w.password==b'' for w in wires))

    def test_constructor_failure_clears_owned_secret_and_refuses_retry(self):
        secret=bytearray(SYNTHETIC_PASSWORD);attempt=d.IsolatedAttempt(config(),secret)
        with self.filesystem() as stack:
            self.enter_fs(stack)
            ctx=ssl.SSLContext(ssl.PROTOCOL_TLS_CLIENT);ctx.minimum_version=ssl.TLSVersion.TLSv1_2
            stack.enter_context(patch.object(r,'context',return_value=ctx))
            with patch.object(r.StrictConnection,'__init__',side_effect=OSError(CANARY)):
                with self.assertRaises(r.Block) as error:attempt.factory(Gate('control',Q0,Q1,attempt.until))
            self.assertNotIn(CANARY,str(error.exception))
            with self.assertRaises(r.Block):attempt.factory(Gate('control',Q0,Q1,attempt.until))
        self.assertTrue(attempt.closed);self.assertEqual(secret,b'')
        self.assertTrue(all(w.password==b'' for w in attempt.wires))

    def test_unknown_password_rejected_before_constructor(self):
        wire=object.__new__(d.R1Wire)
        with patch.object(r.StrictConnection,'__init__') as constructor:
            with self.assertRaises(r.Block):wire.initialize(None,bytearray(CANARY.encode()),config()['r1'])
            constructor.assert_not_called()

    def test_actual_tcp_peer_checked_before_auth_and_reference_cleared(self):
        wire=object.__new__(d.R1Wire);wire.password=bytearray(SYNTHETIC_PASSWORD);wire.pin=config()['r1']
        wire._sock=SimpleNamespace(getpeername=lambda:('8.8.8.8',3306))
        with patch.object(r.Wire,'_request_authentication') as authenticate:
            with self.assertRaises(r.Block):wire._request_authentication()
            authenticate.assert_not_called()
        self.assertEqual(wire.password,b'');wire._sock=None

    def test_dns_or_socket_override_cannot_connect(self):
        wire=object.__new__(d.R1Wire);wire.pin=config()['r1'];wire._sock=None
        with patch.object(r.Wire,'connect') as connect:
            with self.assertRaises(r.Block):wire.connect(sock=object())
            with patch.object(socket,'getaddrinfo',return_value=[(0,0,0,'',('8.8.8.8',3306))]):
                with self.assertRaises(r.Block):wire.connect()
            connect.assert_not_called()

    def test_new_profile_or_real_arguments_rejected_before_resources(self):
        with patch.object(host.h,'run') as resources:
            with self.assertRaises(host.h.Block):host.rehearse('8.4','a'*40,'live')
            resources.assert_not_called()
        import subprocess
        result=subprocess.run([sys.executable,'-I','-B',str(ROOT/'scripts/ci/e858_r1_driver_host.py'),
                               '--host',CANARY,'--password',CANARY],capture_output=True,text=True)
        self.assertEqual(result.returncode,2)
        self.assertNotIn(CANARY,result.stdout+result.stderr)


if __name__=='__main__':unittest.main()
