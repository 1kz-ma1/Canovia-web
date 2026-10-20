"""Offline boundaries; actual wire/session/server facts come from Docker CI."""
import contextlib
import inspect
import io
import json
from pathlib import Path
import ssl
import subprocess
import sys
import tempfile
import threading
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / 'scripts/ci'))
from e858_tls import transport as t
import e858_tls_host as h

CANARY = 'SYNTHETIC_PRIVATE_CANARY'


class ClosedBoundaryTests(unittest.TestCase):
    def test_unknown_endpoint_credential_role_or_fault_never_connects(self):
        with patch.object(t, 'StrictConnection') as opened:
            for role, fault in [('real_operator', None), ('inspector', 'real_endpoint')]:
                with self.assertRaises(t.Block): t.connection(role, fault)
            with self.assertRaises(TypeError): t.connection(host=CANARY)
            with self.assertRaises(TypeError): t.connection(password=CANARY)
            opened.assert_not_called()

    def test_tls_weakening_never_opens_certificates_or_sockets(self):
        with patch.object(t.ssl, 'create_default_context') as context:
            for mode in ('REQUIRED', 'VERIFY_CA', 'DISABLED', None):
                with self.assertRaisesRegex(t.Block, 'TLS_WEAKENING_REJECTED'): t.context(mode=mode)
            context.assert_not_called()

    def test_server_without_ssl_cannot_start_password_authentication(self):
        c = t.StrictConnection(host=t.HOST)
        c.server_capabilities = 0
        with self.assertRaisesRegex(t.Block, 'TLS_REQUIRED_NO_FALLBACK'): c._request_authentication()

    def test_second_connect_is_rejected_before_network(self):
        c = t.StrictConnection(host=t.HOST); c.connect_count = 1
        with patch.object(t.socket, 'create_connection') as socket:
            with self.assertRaisesRegex(t.Block, 'RECONNECT_REJECTED'): c.connect()
            socket.assert_not_called()

    def test_oversized_protocol_row_rejected_before_read(self):
        c = t.StrictConnection(host=t.HOST)
        c.authenticated = True
        with patch.object(t.pymysql.connections.Connection, '_read_bytes') as read:
            with self.assertRaisesRegex(t.Block, 'WIRE_ROW_CAP'): c._read_bytes(t.ROW + 1)
            read.assert_not_called()

    def test_protocol_total_cap_rejected_before_read(self):
        c = t.StrictConnection(host=t.HOST); c.wire_bytes = t.TOTAL
        c.authenticated = True
        with patch.object(t.pymysql.connections.Connection, '_read_bytes') as read:
            with self.assertRaisesRegex(t.Block, 'WIRE_TOTAL_CAP'): c._read_bytes(1)
            read.assert_not_called()

    def test_authentication_preamble_is_bounded_before_read(self):
        c = t.StrictConnection(host=t.HOST)
        with patch.object(t.pymysql.connections.Connection, '_read_bytes') as read:
            with self.assertRaisesRegex(t.Block, 'AUTH_PREAMBLE_CAP'): c._read_bytes(t.PREAMBLE + 1)
            read.assert_not_called()

    def test_time_limits_cannot_be_increased(self):
        with patch.object(t, 'connection') as opened:
            for kwargs in ({'query_seconds': 11}, {'stream_seconds': 121}, {'query_seconds': 0}):
                with self.assertRaisesRegex(t.Block, 'LIMIT_INCREASE_REJECTED'): t.Session(None, **kwargs)
            opened.assert_not_called()

    def test_allowlist_rejection_latches_failure_and_refuses_retry(self):
        s = object.__new__(t.Session)
        s.closed = False; s.failed = False; s.lock = threading.Lock(); s.bundle = {'identity': b'SELECT 1'}
        with patch.object(s, 'abort'), self.assertRaisesRegex(t.Block, 'SQL_NOT_ALLOWLISTED'):
            s.read('identity', b'SELECT SLEEP(30)')
        self.assertTrue(s.failed)
        with self.assertRaisesRegex(t.Block, 'NEW_OWNER_GO_REQUIRED'): s.read('identity')

    def test_closed_session_refuses_every_further_request(self):
        s = object.__new__(t.Session); s.closed = True
        with self.assertRaisesRegex(t.Block, 'NEW_OWNER_GO_REQUIRED'): s.read('identity')

    def test_invisible_or_failed_observer_does_not_certify_absence(self):
        observer = object.__new__(t.Observer); observer.fault = None; observer.role = 'other'
        with self.assertRaisesRegex(t.Block, 'REMOTE_TERMINATION_UNVERIFIED'): observer.state(123)
        self.assertFalse(observer.gone(123, .01)['verified'])

    def test_kill_denied_and_unknown_thread_need_independent_observation(self):
        for mysql_code in (1094, 1095):
            observer = object.__new__(t.Observer); observer.fault = None; observer.conn = object()
            with patch.object(t, 'rows', side_effect=t.pymysql.OperationalError(mysql_code, CANARY)), \
                 patch.object(observer, 'state', return_value=dict(exists=True,running=True)), \
                 patch.object(observer, 'gone', return_value=dict(query_ended=False, connection_ended=False, verified=False)):
                result = observer.terminate(123)
                self.assertFalse(result['verified'])
                if mysql_code == 1095:
                    self.assertTrue(result['query_request_failed'] and result['connection_request_failed'])
                self.assertNotIn(CANARY, json.dumps(result))

    def test_idle_connection_does_not_prove_connection_termination(self):
        observer = object.__new__(t.Observer)
        with patch.object(observer, 'state', return_value=dict(exists=True, running=False)):
            proof = observer.gone(123, .01)
        self.assertTrue(proof['query_ended'])
        self.assertFalse(proof['connection_ended'] or proof['verified'])
        self.assertEqual('REMOTE_TERMINATION_UNVERIFIED', proof['status'])

    def test_cli_real_modes_arguments_and_duplicates_do_not_create_resources(self):
        for args in (['--host', CANARY], ['--collect'], ['--credential', CANARY],
                     ['--series', '8.0', '--tooling-sha', 'a' * 40, '--execute'],
                     ['--series', '8.0', '--series', '8.4', '--tooling-sha', 'a' * 40],
                     ['--series', '8.0', '--tooling-sha', 'a' * 40, '--tooling-sha', CANARY]):
            out = io.StringIO()
            with patch.object(sys, 'argv', ['harness'] + args), patch.object(h, 'rehearse') as run, \
                 contextlib.redirect_stdout(out), contextlib.redirect_stderr(io.StringIO()):
                self.assertEqual(2, h.main()); run.assert_not_called()
            self.assertNotIn(CANARY, out.getvalue())
            self.assertFalse(json.loads(out.getvalue())['real_aiven_connected'])

    def test_subprocess_errors_do_not_echo_argv_output_or_private_error(self):
        value = subprocess.CompletedProcess(['docker', 'inspect', CANARY], 1, CANARY.encode(), CANARY.encode())
        with patch.object(h.subprocess, 'run', return_value=value), \
             patch.dict(h.os.environ, {'GITHUB_ACTIONS': 'true'}):
            with self.assertRaises(h.Block) as error: h.run(value.args)
        self.assertNotIn(CANARY, str(error.exception))

    def test_test_certificates_have_ca_san_and_expired_negative(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp); h.certificate_material(root)
            ca = h.x509.load_pem_x509_certificate((root / 'client/ca.pem').read_bytes())
            self.assertTrue(ca.extensions.get_extension_for_class(h.x509.BasicConstraints).value.ca)
            cert = h.x509.load_pem_x509_certificate((root / 'server/server.pem').read_bytes())
            self.assertIn(t.HOST, cert.extensions.get_extension_for_class(h.x509.SubjectAlternativeName).value.get_values_for_type(h.x509.DNSName))
            expired = h.x509.load_pem_x509_certificate((root / 'expired/server.pem').read_bytes())
            self.assertLess(expired.not_valid_after_utc, h.datetime.datetime.now(h.datetime.timezone.utc))
            self.assertFalse((root / 'client/server-key.pem').exists())


if __name__ == '__main__': unittest.main()
