"""New readiness/TCP ownership/pre-auth boundaries. No DNS or IP networking."""
import contextlib
import io
import os
import socket
import struct
import subprocess
import sys
import time
import unittest
from unittest.mock import patch

import test_e858_practical as fixture
from e858_phase1 import backend_candidate as backend, b1_package
from e858_phase1.connection_handoff_candidate import ConnectedPeer, AuthenticationBoundary
from e858_phase1.opener_candidate import OpenerCandidate, Peer

Block=fixture.binding.Block
CANARY=fixture.CANARY


class Readiness(unittest.TestCase):
    def launch(self,code):
        p=subprocess.Popen([sys.executable,'-I','-B','-c',code],stdin=subprocess.PIPE,
            stdout=subprocess.PIPE,stderr=subprocess.PIPE,close_fds=True,env={})
        self.addCleanup(lambda:backend.reap(p,time.monotonic()+.25))
        return p

    def test_real_child_ready_before_any_request_and_single_exchange(self):
        p=self.launch("import sys;sys.stdout.buffer.write(b'E858_DNS_READY\\n');sys.stdout.flush();v=sys.stdin.buffer.read();sys.stdout.buffer.write(v)")
        child=backend.PreparedChild(p,time.monotonic()+2)
        self.assertTrue(child.ready);self.assertFalse(child.used);self.assertIsNone(p.poll())
        self.assertEqual(child.exchange(b'{}',time.monotonic()+2),b'{}')
        with self.assertRaises(Block):child.exchange(b'{}',time.monotonic()+2)
        self.assertIsNotNone(p.poll())

    def test_startup_timeout_exit_signal_bad_header_and_stderr_are_reaped(self):
        cases=("import time;time.sleep(60)","raise SystemExit(2)",
            "import os,signal;os.kill(os.getpid(),signal.SIGTERM)",
            "import sys;sys.stdout.write('"+CANARY+"');sys.stdout.flush()",
            "import sys;sys.stderr.write('"+CANARY+"');sys.stderr.flush()")
        for code in cases:
            with self.subTest(code=cases.index(code)):
                p=self.launch(code);out,err=io.StringIO(),io.StringIO()
                with contextlib.redirect_stdout(out),contextlib.redirect_stderr(err):
                    with self.assertRaises(Block) as caught:backend.PreparedChild(p,time.monotonic()+.5)
                self.assertTrue(caught.exception.dns_reaped);self.assertIsNotNone(p.poll())
                self.assertEqual(out.getvalue()+err.getvalue(),'');self.assertNotIn(CANARY,str(caught.exception))

    def test_readiness_does_not_hide_cleanup_permission_failure(self):
        p=self.launch('import time;time.sleep(60)');kill=p.kill
        try:
            with patch.object(p,'kill',side_effect=PermissionError(CANARY)):
                with self.assertRaises(Block) as caught:backend.PreparedChild(p,time.monotonic()+.4)
            self.assertFalse(caught.exception.dns_reaped)
            self.assertEqual(caught.exception.dns_status,'DNS_CHILD_CLEANUP_UNVERIFIED')
        finally:kill();p.wait(timeout=2)

    def test_interrupt_or_selector_cleanup_failure_reaps_before_r1(self):
        for mode in ('INTERRUPT','CLOSE'):
            p=self.launch("import sys;sys.stdout.buffer.write(b'E858_DNS_READY\\n');sys.stdout.flush();sys.stdin.buffer.read()")
            cls=backend.selectors.DefaultSelector
            method='select' if mode=='INTERRUPT' else 'close'
            original=cls.close;selectors=[];create=backend.selectors.DefaultSelector
            def build():
                value=create();selectors.append(value);return value
            try:
                with patch.object(backend.selectors,'DefaultSelector',side_effect=build),patch.object(cls,method,side_effect=KeyboardInterrupt(CANARY)):
                    with self.assertRaises(Block) as caught:backend.PreparedChild(p,time.monotonic()+2)
                self.assertTrue(caught.exception.dns_reaped);self.assertIsNotNone(p.poll())
                self.assertNotIn(CANARY,str(caught.exception))
                if mode=='CLOSE':self.assertEqual(caught.exception.dns_status,'DNS_CHILD_CLEANUP_UNVERIFIED')
            finally:
                for value in selectors:original(value)

    def test_changed_pipe_and_parent_rejected_without_request(self):
        for mode in ('FD','PARENT'):
            p=self.launch("import sys;sys.stdout.buffer.write(b'E858_DNS_READY\\n');sys.stdout.flush();sys.stdin.buffer.read()")
            child=backend.PreparedChild(p,time.monotonic()+2)
            if mode=='FD':os.set_inheritable(p.stdin.fileno(),True)
            else:child.parent=-1
            with self.assertRaises(Block):child.exchange(b'{}',time.monotonic()+2)
            self.assertIsNotNone(p.poll());self.assertTrue(child.used)


class Handoff(unittest.TestCase):
    def plan(self,raw):
        # Unit test only: no ReviewedEntry/credential/endpoint is admitted.
        plan=object.__new__(OpenerCandidate)
        plan.peer=Peer(raw.family,raw.getpeername());plan.before=False;plan.verified=False
        return plan

    def pair(self):
        a,b=socket.socketpair();self.addCleanup(a.close);self.addCleanup(b.close)
        return a,b

    def test_existing_socket_transfer_single_use_no_dial_and_no_retry(self):
        raw,_=self.pair();plan=self.plan(raw)
        with patch.object(OpenerCandidate,'remaining',return_value=.2),patch('socket.getaddrinfo') as dns,patch.object(socket.socket,'connect') as dial:
            handoff=ConnectedPeer(plan,raw);self.assertIs(handoff.take(),raw)
            self.assertIsNone(handoff.raw);self.assertIsNone(handoff.plan)
            with self.assertRaises(Block):handoff.take()
            dns.assert_not_called();dial.assert_not_called()
        self.assertGreaterEqual(raw.fileno(),0)  # recipient now owns it

    def test_peer_switch_expiry_and_inheritable_fd_close_before_driver(self):
        for mode in ('PEER','EXPIRED','INHERITED'):
            raw,_=self.pair();plan=self.plan(raw)
            if mode=='PEER':plan.peer=Peer(raw.family,('not-our-peer',3306))
            if mode=='INHERITED':os.set_inheritable(raw.fileno(),True)
            with patch.object(OpenerCandidate,'remaining',side_effect=Block() if mode=='EXPIRED' else None,return_value=.2):
                with self.assertRaises(Block):ConnectedPeer(plan,raw)
            self.assertEqual(raw.fileno(),-1)

    def test_change_between_validation_and_take_consumes_socket(self):
        raw,_=self.pair();plan=self.plan(raw)
        with patch.object(OpenerCandidate,'remaining',return_value=.2):
            handoff=ConnectedPeer(plan,raw);plan.before=True
            with self.assertRaises(Block):handoff.take()
        self.assertEqual(raw.fileno(),-1);self.assertTrue(handoff.used)

    def test_plaintext_only_exact_single_ssl_request_no_auth_fallback(self):
        header=struct.pack('<IIB23s',0x800,16*1024*1024,11,b'')
        for mode in ('NORMAL','AUTH_FIRST','NO_SSL','NONZERO','SECOND','TLS_BYPASS','FALSE_VERIFIED'):
            raw,_=self.pair();plan=self.plan(raw);boundary=AuthenticationBoundary(plan)
            with patch.object(OpenerCandidate,'remaining',return_value=.2):
                if mode=='NORMAL':boundary.packet(raw,header)
                else:
                    if mode in ('SECOND','TLS_BYPASS','FALSE_VERIFIED'):boundary.packet(raw,header)
                    if mode=='FALSE_VERIFIED':plan.verified=True
                    payload=(header if mode in ('SECOND','FALSE_VERIFIED') else
                        struct.pack('<IIB23s',0,16*1024*1024,11,b'') if mode=='NO_SSL' else
                        header[:-1]+b'x' if mode=='NONZERO' else CANARY.encode())
                    with self.assertRaises(Block):boundary.packet(raw,payload)
                    self.assertTrue(boundary.stopped);self.assertIsNone(boundary.plan)
            self.assertNotIn(CANARY,repr(boundary))

    def test_inert_components_in_review_bundle_have_no_network_entry(self):
        self.assertIn('scripts/ci/e858_phase1/connection_handoff_candidate.py',b1_package.FILES)
        for cls in (ConnectedPeer,AuthenticationBoundary,backend.PreparedChild):
            for name in ('connect','dial','open','launch','approve','retry','read_credentials'):
                self.assertFalse(hasattr(cls,name))


if __name__=='__main__':unittest.main()
