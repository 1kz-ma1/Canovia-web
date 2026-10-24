"""No-network TLS handoff boundary. Real handshake is exercised by owned MySQL CI."""
import socket
import unittest
from unittest.mock import Mock, patch

import test_e858_practical as fixture
from e858_phase1 import b1_package
from e858_phase1 import opener_candidate as opener
from e858_phase1.tls_handoff_candidate import VerifiedTLSHandoff

Block=fixture.binding.Block


class TLSHandoffBoundary(unittest.TestCase):
    def test_invalid_plan_fails_and_closes_its_unconnected_socket(self):
        with socket.socket(socket.AF_INET,socket.SOCK_STREAM) as raw:
            with patch('socket.getaddrinfo') as dns,patch.object(socket.socket,'connect') as dial:
                for plan in (None,object(),{'b2_owner_go':True}):
                    # A new socket per iteration: rejection consumes ownership.
                    with socket.socket(socket.AF_INET,socket.SOCK_STREAM) as candidate:
                        with self.assertRaises(Block) as caught:
                            VerifiedTLSHandoff(plan,candidate,'mysql.synthetic.invalid')
                        self.assertEqual(candidate.fileno(),-1)
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
        impostor.close.assert_called_once_with()

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
