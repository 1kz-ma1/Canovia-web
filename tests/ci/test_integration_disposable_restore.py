"""Offline negative contracts; real restore scenarios run in the MySQL matrix."""
import copy
from contextlib import redirect_stdout
import io
import json
from pathlib import Path
import subprocess
import sys
import unittest
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[2] / 'scripts/ci'))
import integration_disposable_restore as restore


class RestoreSafetyTest(unittest.TestCase):
    def test_only_expected_loopback_source_is_accepted(self):
        state = {'Config': {'Labels': {'canovia.integration': '2026-10-16'}},
                 'NetworkSettings': {'Ports': {'3306/tcp': [{'HostIp': '127.0.0.1', 'HostPort': '13384'}]}}}
        restore.identity(state, '8.4')
        for address, port in [('0.0.0.0', '13384'), ('127.0.0.1', '3306'), ('::', '13384')]:
            bad = copy.deepcopy(state)
            bad['NetworkSettings']['Ports']['3306/tcp'] = [{'HostIp': address, 'HostPort': port}]
            with self.assertRaisesRegex(restore.Block, 'IDENTITY_REJECTED'):
                restore.identity(bad, '8.4')
        state['Config']['Labels'] = {}
        with self.assertRaises(restore.Block):
            restore.identity(state, '8.4')

    def test_privileged_empty_inventory_is_not_a_valid_snapshot(self):
        for raw in [b'', b'0\t0\t0\t0\t0', b'91\t0\t0\t0\tUNKNOWN', b'91\t0\t0\t0']:
            with self.subTest(raw=raw), self.assertRaises(restore.Block):
                restore.inventory(raw)

    def test_nontransactional_engine_or_unsupported_objects_block(self):
        restore.inventory(b'91\t0\t0\t0\t0\n')
        for offset in range(1, 5):
            values = ['91', '0', '0', '0', '0']; values[offset] = '1'
            with self.subTest(offset=offset), self.assertRaisesRegex(restore.Block, 'UNSUPPORTED'):
                restore.inventory('\t'.join(values).encode())

    def test_equal_row_counts_cannot_hide_data_or_schema_difference(self):
        restore.parity(b'same', b'same', 'PARITY_BLOCK')
        with self.assertRaisesRegex(restore.Block, 'PARITY_BLOCK'):
            restore.parity(b'one row original', b'one row altered', 'PARITY_BLOCK')

    def test_metadata_cannot_inject_sql_identifiers(self):
        self.assertEqual(restore.quote('valid_1'), '`valid_1`')
        for value in ['a`b', 'a.b', 'a;DROP', '', '非ASCII']:
            with self.assertRaises(restore.Block):
                restore.quote(value)

    def test_failed_subprocess_does_not_disclose_client_error_or_command(self):
        result = subprocess.CompletedProcess(['fake-secret-client'], 1, b'PRIVATE SQL', b'PRIVATE CONNECTION')
        with patch.object(restore.subprocess, 'run', return_value=result):
            with self.assertRaises(restore.Block) as ctx:
                restore.execute(['fake-secret-client'])
        self.assertEqual(str(ctx.exception), 'SYNTHETIC_COMMAND_FAILED')

    def test_timeout_does_not_disclose_input_or_connection(self):
        error = subprocess.TimeoutExpired(['secret'], 1, output=b'PRIVATE ROW', stderr=b'PRIVATE')
        with patch.object(restore.subprocess, 'run', side_effect=error):
            with self.assertRaisesRegex(restore.Block, '^SYNTHETIC_COMMAND_UNAVAILABLE$'):
                restore.execute(['secret'])

    def test_wrong_error_code_is_not_expected_rejection(self):
        for status, error in [(0, b'ERROR 1142'), (1, b'ERROR 1045 auth'), (1, b'ERROR 11420')]:
            with self.subTest(status=status, error=error):
                with patch.object(restore.subprocess, 'run', return_value=subprocess.CompletedProcess([], status, b'', error)):
                    with self.assertRaisesRegex(restore.Block, 'EXPECTED_ENGINE_REJECTION_NOT_OBSERVED'):
                        restore.execute(['fake'], deny=1142)
        with patch.object(restore.subprocess, 'run', return_value=subprocess.CompletedProcess([], 1, b'', b'ERROR 1142 (42000)')):
            self.assertEqual(restore.execute(['fake'], deny=1142), b'')

    def test_cleanup_mismatch_never_removes_foreign_container_and_still_drops_owned_users(self):
        state = {'Id': 'other', 'Config': {'Labels': {restore.LABEL: 'run'}}}
        with patch.object(restore, 'execute', return_value=json.dumps([state]).encode()) as call, \
                patch.object(restore, 'sql') as sql:
            with self.assertRaisesRegex(restore.Block, 'CLEANUP_UNVERIFIED'):
                restore.cleanup('owned', 'run', 'source', ['canovia_export_ci'])
            self.assertEqual(call.call_count, 1)
            sql.assert_called_once()

    def test_cleanup_command_failure_or_residual_container_blocks(self):
        state = {'Id': 'owned', 'Config': {'Labels': {restore.LABEL: 'run'}}}
        for final in [restore.Block('failed'), b'owned\n']:
            replies = [json.dumps([state]).encode(), b'', final]
            with self.subTest(final=type(final).__name__), patch.object(restore, 'execute', side_effect=replies), \
                    patch.object(restore, 'sql') as sql:
                with self.assertRaisesRegex(restore.Block, 'CLEANUP_UNVERIFIED'):
                    restore.cleanup('owned', 'run', 'source', ['canovia_export_ci'])
                sql.assert_called_once()

    def test_user_cleanup_failure_blocks(self):
        with patch.object(restore, 'sql', side_effect=restore.Block('failed')):
            with self.assertRaisesRegex(restore.Block, 'CLEANUP_UNVERIFIED'):
                restore.cleanup(None, 'run', 'source', ['canovia_export_ci'])

    def test_cli_reports_fixed_block_and_never_reports_client_text(self):
        output = io.StringIO()
        with patch.object(sys, 'argv', ['restore', '--artifacts', '/tmp/unused']), \
                patch.object(restore, 'rehearse', side_effect=ValueError('PRIVATE ROW / CONNECTION')), redirect_stdout(output):
            self.assertEqual(restore.main(), 2)
        result = json.loads(output.getvalue())
        self.assertEqual(result['code'], 'SYNTHETIC_RESTORE_NOT_VERIFIED')
        self.assertFalse(result['release_authorized'])
        self.assertNotIn('PRIVATE', output.getvalue())


if __name__ == '__main__':
    unittest.main()
