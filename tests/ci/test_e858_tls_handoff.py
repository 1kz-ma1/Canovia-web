"""No-network TLS handoff boundary. Real handshake is exercised by owned MySQL CI."""
from contextlib import ExitStack
from pathlib import Path
import socket
import ssl
import sys
import unittest
from unittest.mock import Mock, patch

sys.path.insert(0,str(Path(__file__).resolve().parents[2]/'scripts/ci'))
from e858_phase1 import b1_package
from e858_phase1 import opener_candidate as opener
from e858_phase1.tls_handoff_candidate import VerifiedTLSHandoff

from e858_phase1.private_binding import Block


class TLSHandoffBoundary(unittest.TestCase):
    def test_invalid_plan_leaves_unaccepted_socket_with_caller(self):
        with socket.socket(socket.AF_INET,socket.SOCK_STREAM) as raw:
            with patch('socket.getaddrinfo') as dns,patch.object(socket.socket,'connect') as dial:
                for plan in (None,object(),{'b2_owner_go':True}):
                    # No ownership transfer until both concrete types pass.
                    with socket.socket(socket.AF_INET,socket.SOCK_STREAM) as candidate:
                        with self.assertRaises(Block) as caught:
                            VerifiedTLSHandoff(plan,candidate,'mysql.synthetic.invalid')
                        self.assertGreaterEqual(candidate.fileno(),0)
                        self.assertEqual(str(caught.exception),'PRIVATE_CANDIDATE_BLOCK')
                dns.assert_not_called()
                dial.assert_not_called()
        self.assertEqual(raw.fileno(),-1)

    def test_forged_socket_is_rejected_without_dns_tcp_tls_or_secret_handling(self):
        impostor=Mock()
        with patch('socket.getaddrinfo') as dns,patch.object(socket.socket,'connect') as dial:
            with self.assertRaises(Block):
                VerifiedTLSHandoff({'approved':True},impostor,'mysql.synthetic.invalid')
            dns.assert_not_called();dial.assert_not_called()
        impostor.close.assert_not_called()

    def local_plan(self,stack):
        # Ownership-only fixture: native SSL wrapping, NO TLS authenticity claim.
        plan=object.__new__(opener.OpenerCandidate)
        plan.context=ssl.SSLContext(ssl.PROTOCOL_TLS_CLIENT)
        stack.enter_context(patch.object(opener.OpenerCandidate,'before_tls'))
        stack.enter_context(patch.object(opener.OpenerCandidate,'remaining',return_value=1))
        stack.enter_context(patch.object(opener.OpenerCandidate,'after_tls'))
        raw,other=socket.socketpair()
        stack.callback(raw.close);stack.callback(other.close)
        return plan,raw,other

    def test_exact_plan_with_fake_raw_never_invokes_callback(self):
        with ExitStack() as stack:
            plan,_,_=self.local_plan(stack);impostor=Mock()
            with self.assertRaises(Block):VerifiedTLSHandoff(plan,impostor,'synthetic.invalid')
            self.assertEqual(impostor.mock_calls,[])

    def test_socket_subclass_is_rejected_without_taking_ownership(self):
        class SubSocket(socket.socket):
            calls=0
            def close(self):self.calls+=1;super().close()
        with ExitStack() as stack:
            plan,_,_=self.local_plan(stack)
            raw=SubSocket(socket.AF_UNIX,socket.SOCK_STREAM)
            try:
                with self.assertRaises(Block):VerifiedTLSHandoff(plan,raw,'synthetic.invalid')
                self.assertEqual(raw.calls,0);self.assertGreaterEqual(raw.fileno(),0)
            finally:socket.socket.close(raw)

    def test_policy_failure_after_acceptance_closes_only_owned_raw(self):
        with ExitStack() as stack:
            plan,raw,other=self.local_plan(stack)
            with patch.object(opener.OpenerCandidate,'before_tls',side_effect=Block()):
                with self.assertRaises(Block):VerifiedTLSHandoff(plan,raw,'synthetic.invalid')
            self.assertEqual(raw.fileno(),-1);self.assertGreaterEqual(other.fileno(),0)

    def test_replaced_raw_never_changes_cleanup_target(self):
        with ExitStack() as stack:
            plan,raw,other=self.local_plan(stack)
            handoff=VerifiedTLSHandoff(plan,raw,'synthetic.invalid');fake=Mock();handoff._raw=fake
            with self.assertRaises(Block):handoff.handshake()
            self.assertEqual(fake.mock_calls,[])
            self.assertEqual(raw.fileno(),-1);self.assertGreaterEqual(other.fileno(),0)

    def test_plan_hostname_and_context_replacement_are_terminal(self):
        for field in ('_plan','_hostname','context'):
            with self.subTest(field=field),ExitStack() as stack:
                plan,raw,_=self.local_plan(stack)
                handoff=VerifiedTLSHandoff(plan,raw,'synthetic.invalid')
                if field=='context':plan.context=ssl.SSLContext(ssl.PROTOCOL_TLS_CLIENT)
                else:setattr(handoff,field,Mock())
                with self.assertRaises(Block):handoff.handshake()
                self.assertEqual(raw.fileno(),-1)
                with self.assertRaises(Block):handoff.handshake()

    def test_native_wrap_transfers_fd_once_without_closing_returned_tls(self):
        with ExitStack() as stack:
            plan,raw,other=self.local_plan(stack);fd=raw.fileno()
            handoff=VerifiedTLSHandoff(plan,raw,'synthetic.invalid')
            with patch.object(ssl.SSLSocket,'do_handshake') as tls:
                wrapped=handoff.handshake()
            try:
                tls.assert_called_once_with()
                self.assertIs(type(wrapped),ssl.SSLSocket)
                self.assertEqual(raw.fileno(),-1);self.assertEqual(wrapped.fileno(),fd)
                handoff.close();handoff.close()
                with self.assertRaises(Block):handoff.handshake()
                self.assertEqual(wrapped.fileno(),fd);self.assertGreaterEqual(other.fileno(),0)
            finally:wrapped.close()

    def test_tls_error_after_native_fd_transfer_closes_wrapped_resource(self):
        with ExitStack() as stack:
            plan,raw,_=self.local_plan(stack);created=[]
            native=ssl.SSLContext.wrap_socket
            def record(context,*args,**kwargs):
                value=native(context,*args,**kwargs);created.append(value);return value
            handoff=VerifiedTLSHandoff(plan,raw,'synthetic.invalid')
            with patch.object(ssl.SSLContext,'wrap_socket',record),patch.object(
                    ssl.SSLSocket,'do_handshake',side_effect=ssl.SSLError('SYNTHETIC_TLS_FAILURE')):
                with self.assertRaises(ssl.SSLError):handoff.handshake()
            self.assertEqual(len(created),1);self.assertEqual(created[0].fileno(),-1)
            self.assertEqual(raw.fileno(),-1)
            with self.assertRaises(Block):handoff.handshake()

    def test_postflight_identity_failure_closes_wrapped_not_detached_raw(self):
        with ExitStack() as stack:
            plan,raw,_=self.local_plan(stack);closed=[]
            handoff=VerifiedTLSHandoff(plan,raw,'synthetic.invalid');native=socket.socket.close
            def record(value):closed.append(value);return native(value)
            with patch.object(ssl.SSLSocket,'do_handshake'),patch.object(
                    opener.OpenerCandidate,'after_tls',side_effect=Block()),patch.object(socket.socket,'close',record):
                with self.assertRaises(Block):handoff.handshake()
            self.assertEqual(len(closed),1);self.assertIs(type(closed[0]),ssl.SSLSocket)
            self.assertEqual(closed[0].fileno(),-1);self.assertEqual(raw.fileno(),-1)

    def test_expiry_before_handshake_closes_raw_and_cannot_retry(self):
        with ExitStack() as stack:
            plan,raw,_=self.local_plan(stack);handoff=VerifiedTLSHandoff(plan,raw,'synthetic.invalid')
            with patch.object(opener.OpenerCandidate,'remaining',side_effect=Block()),patch.object(
                    ssl.SSLContext,'wrap_socket') as wrap:
                with self.assertRaises(Block):handoff.handshake()
                wrap.assert_not_called()
            self.assertEqual(raw.fileno(),-1)
            with self.assertRaises(Block):handoff.handshake()

    def test_close_failure_is_fixed_block_and_keeps_resource_for_cleanup_only(self):
        for failure in (OSError('SYNTHETIC_PRIVATE_ERROR'),None):
            with self.subTest(raises=failure is not None),ExitStack() as stack:
                plan,raw,_=self.local_plan(stack);handoff=VerifiedTLSHandoff(plan,raw,'synthetic.invalid')
                with patch.object(socket.socket,'close',side_effect=failure):
                    with self.assertRaises(Block) as caught:handoff.close()
                    self.assertEqual(str(caught.exception),'PRIVATE_CANDIDATE_BLOCK')
                    self.assertGreaterEqual(raw.fileno(),0)
                with self.assertRaises(Block):handoff.handshake()
                handoff.close();self.assertEqual(raw.fileno(),-1)

    def test_fake_wrapper_return_never_becomes_owned_callback(self):
        with ExitStack() as stack:
            plan,raw,_=self.local_plan(stack);handoff=VerifiedTLSHandoff(plan,raw,'synthetic.invalid');fake=Mock()
            with patch.object(ssl.SSLContext,'wrap_socket',return_value=fake):
                with self.assertRaises(Block):handoff.handshake()
            self.assertEqual(fake.mock_calls,[]);self.assertEqual(raw.fileno(),-1)

    def test_custom_ssl_socket_class_is_rejected_before_wrapper_callback(self):
        with ExitStack() as stack:
            plan,raw,_=self.local_plan(stack);fake=Mock();plan.context.sslsocket_class=fake
            with self.assertRaises(Block):VerifiedTLSHandoff(plan,raw,'synthetic.invalid')
            fake.assert_not_called();self.assertEqual(raw.fileno(),-1)

    def test_interrupt_during_handshake_closes_owned_tls_and_cannot_restart(self):
        with ExitStack() as stack:
            plan,raw,_=self.local_plan(stack);handoff=VerifiedTLSHandoff(plan,raw,'synthetic.invalid')
            with patch.object(ssl.SSLSocket,'do_handshake',side_effect=KeyboardInterrupt):
                with self.assertRaises(KeyboardInterrupt):handoff.handshake()
            self.assertIsNone(handoff._owned_socket);self.assertEqual(raw.fileno(),-1)
            with self.assertRaises(Block):handoff.handshake()

    def test_tls_cleanup_failure_is_block_and_not_successful_transfer(self):
        with ExitStack() as stack:
            plan,raw,_=self.local_plan(stack);handoff=VerifiedTLSHandoff(plan,raw,'synthetic.invalid')
            with patch.object(ssl.SSLSocket,'do_handshake'),patch.object(
                    opener.OpenerCandidate,'after_tls',side_effect=Block()),patch.object(
                    socket.socket,'close',side_effect=OSError('SYNTHETIC_PRIVATE_ERROR')):
                with self.assertRaises(Block) as caught:handoff.handshake()
                self.assertEqual(str(caught.exception),'PRIVATE_CANDIDATE_BLOCK')
                owned=handoff._owned_socket
                self.assertIs(type(owned),ssl.SSLSocket);self.assertGreaterEqual(owned.fileno(),0)
            try:
                with self.assertRaises(Block):handoff.handshake()
                handoff.close();self.assertEqual(owned.fileno(),-1)
            finally:owned.close()

    def test_new_handoff_cannot_be_shipped_in_public_b1_or_activate_opener(self):
        self.assertNotIn('scripts/ci/e858_phase1/tls_handoff_candidate.py',b1_package.FILES)
        self.assertNotIn('scripts/ci/e858_phase1/owned_activation_fixture.py',b1_package.FILES)
        with patch('socket.getaddrinfo') as dns,patch.object(socket.socket,'connect') as dial:
            with self.assertRaises(Block):
                object.__new__(opener.OpenerCandidate).open()
            dns.assert_not_called();dial.assert_not_called()

    def test_handoff_has_no_resolver_dialer_approval_or_retry_entrypoint(self):
        for method in ('open','resolve','dial','run','approve','authorize',
                       'retry','connect','read_credentials'):
            self.assertFalse(hasattr(VerifiedTLSHandoff,method))


if __name__=='__main__':
    unittest.main()
