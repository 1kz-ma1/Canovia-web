"""Anonymous synthetic B fixtures; no remote services or database clients."""
import contextlib
import copy
import io
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / 'scripts/ci'))
import e858_b_offline as b

NOW = 1700000000
HEAD = 'a' * 40
CANARY = 'SYNTHETIC_PRIVATE_CANARY'


def pins():
    return dict(source_sha=b.SOURCE, release_ref=b.RELEASE, tooling_sha=HEAD,
                collector_sha=b.COLLECTOR, transport_sha=b.TRANSPORT, contract_sha=b.CONTRACT,
                sql_sha256='1' * 64, reference_sha256={'8.0': '2' * 64, '8.4': '3' * 64})


def packet(facts=None):
    value = b.template(facts or pins(), NOW)
    value.update(declarations=dict(b.DECLARATIONS), attempt_id='a' * 32)
    return value


class ContractTests(unittest.TestCase):
    def setUp(self):
        self.pins = pins(); self.packet = packet()

    def test_unreviewed_template_is_not_an_accepted_checklist(self):
        with self.assertRaises(b.Block):
            b.validate(b.template(self.pins, NOW), self.pins, NOW)

    def test_positive_receipt_is_not_approval_identity_tls_or_execution(self):
        value = b.receipt(self.packet, self.pins, NOW)
        self.assertEqual('B_OFFLINE_REVIEW_ONLY', value['result'])
        self.assertTrue(b.verify_receipt(value, self.packet, self.pins, NOW))
        for key in ('real_aiven_connected', 'execution_allowed', 'owner_approval_verified',
                    'release_authorized', 'production_modified', 'strict_block_waived'):
            self.assertIs(False, value[key])
        self.assertTrue(value['fresh_owner_go_required'])
        self.assertEqual('UNIMPLEMENTED', value['tls_session'])
        for key in ('execution_started_at', 'execution_ended_at', 'metadata_count'):
            self.assertIsNone(value[key])
        self.assertEqual('HUMAN_PROCEDURAL_ONLY', value['retry_control'])

    def test_different_source_tooling_sql_and_both_reference_pins_block(self):
        for key in self.pins:
            value = copy.deepcopy(self.packet); value['pins'][key] = CANARY
            with self.assertRaisesRegex(b.Block, 'PIN_MISMATCH'):
                b.validate(value, self.pins, NOW)

    def test_unknown_operator_target_scope_tls_grants_or_custody_block(self):
        for key in b.DECLARATIONS:
            for changed in ('NOT_REVIEWED', 'UNKNOWN', CANARY, True, None):
                value = copy.deepcopy(self.packet); value['declarations'][key] = changed
                with self.assertRaisesRegex(b.Block, 'PRIVATE_REVIEW_INCOMPLETE'):
                    b.validate(value, self.pins, NOW)
        for key, changed in [('tls', 'VERIFY_CA'), ('tls', 'REQUIRED'),
                             ('grants', 'TRIGGER'), ('grants', 'SELECT_ALL'),
                             ('scope', 'PLAN_TASK_ACCOUNT_ROWS')]:
            value = copy.deepcopy(self.packet); value['declarations'][key] = changed
            with self.assertRaises(b.Block): b.validate(value, self.pins, NOW)

    def test_unapproved_claims_and_unknown_fields_are_not_authority(self):
        for key in ('approved', 'owner_go', 'token', 'host', 'database', 'credential'):
            value = copy.deepcopy(self.packet); value[key] = CANARY
            with self.assertRaisesRegex(b.Block, 'PACKET_FIELDS_REJECTED'):
                b.validate(value, self.pins, NOW)
        self.packet['kind'] = 'REAL_OWNER_APPROVED'
        with self.assertRaisesRegex(b.Block, 'REAL_APPROVAL_NOT_ACCEPTED'):
            b.validate(self.packet, self.pins, NOW)

    def test_expiry_future_invalid_bool_or_enlarged_window_block(self):
        for now in (NOW - 1, NOW + 120):
            with self.assertRaisesRegex(b.Block, 'EXPIRED_OR_NOT_YET_VALID'):
                b.validate(self.packet, self.pins, now)
        for key, changed in [('starts_at', True), ('ends_at', NOW + 121),
                             ('ends_at', NOW), ('ends_at', '2026-10-20T00:00:00Z')]:
            value = copy.deepcopy(self.packet); value[key] = changed
            with self.assertRaisesRegex(b.Block, 'WINDOW_REJECTED'):
                b.validate(value, self.pins, NOW)

    def test_retry_after_completion_failure_interrupt_or_unknown_always_blocks(self):
        for state in ('COMPLETED', 'FAILED', 'INTERRUPTED', 'UNKNOWN', 'STARTED'):
            value = copy.deepcopy(self.packet); value['prior_attempt'] = state
            with self.assertRaisesRegex(b.Block, 'NEW_OWNER_GO_REQUIRED'):
                b.validate(value, self.pins, NOW)
        self.packet['request'] = 'RETRY'
        with self.assertRaisesRegex(b.Block, 'NEW_OWNER_GO_REQUIRED'):
            b.validate(self.packet, self.pins, NOW)

    def test_same_receipt_reproducible_does_not_prove_durable_consumption(self):
        first = b.receipt(self.packet, self.pins, NOW)
        second = b.receipt(self.packet, self.pins, NOW)
        self.assertEqual(first, second)
        self.assertIs(False, second['execution_allowed'])
        self.assertTrue(second['fresh_owner_go_required'])

    def test_completed_block_and_interrupted_synthetic_receipts_never_record_actual_execution(self):
        for state in ('COMPLETED', 'BLOCK', 'INTERRUPTED'):
            value = b.receipt(self.packet, self.pins, NOW, state)
            self.assertTrue(b.verify_receipt(value, self.packet, self.pins, NOW))
            self.assertIsNone(value['execution_started_at'])
            self.assertEqual('NOT_RUN', value['attempt'])
            self.assertFalse(value['execution_allowed'])

    def test_receipt_tampering_cannot_upgrade_real_execution_or_cleanup(self):
        original = b.receipt(self.packet, self.pins, NOW)
        for key, changed in [('execution_allowed', True), ('owner_approval_verified', True),
                ('strict_block_waived', True), ('cleanup', 'PASS'), ('attempt', 'COMPLETED'),
                ('packet_sha256', '0' * 64), ('execution_started_at', NOW), ('unknown', CANARY)]:
            value = copy.deepcopy(original); value[key] = changed
            with self.assertRaisesRegex(b.Block, 'SYNTHETIC_RECEIPT_REJECTED'):
                b.verify_receipt(value, self.packet, self.pins, NOW)

    def test_duplicate_deep_non_ascii_float_nan_and_huge_json_rejected(self):
        for raw in (b'{"x":1,"x":2}', b'{"x":NaN}', b'{"x":1.2}', b'\xff',
                    b'x' * 16385, b'[' * 2000 + b']' * 2000):
            with self.assertRaises(Exception): b.decode(raw)
        value = copy.deepcopy(self.packet); value['pins']['reference_sha256'] = {'8.0': True}
        with self.assertRaises(b.Block): b.validate(value, self.pins, NOW)


class CliTests(unittest.TestCase):
    def setUp(self):
        self.directory = tempfile.TemporaryDirectory()
        self.addCleanup(self.directory.cleanup)
        self.file = Path(self.directory.name) / CANARY
        self.file.write_bytes(b.canonical(packet()))
        self.args = ['--tooling-sha', HEAD, '--packet', str(self.file)]

    def run_cli(self, args=None):
        out, err = io.StringIO(), io.StringIO()
        with contextlib.redirect_stdout(out), contextlib.redirect_stderr(err):
            code = b.main(self.args if args is None else args)
        text = out.getvalue() + err.getvalue()
        self.assertNotIn(CANARY, text)
        self.assertNotIn(str(self.file.parent), text)
        self.assertNotIn('Traceback', text)
        value = json.loads(out.getvalue())
        self.assertIs(False, value['execution_allowed'])
        return code, value

    def test_invalid_real_modes_arguments_duplicate_and_secrets_never_read_files_or_git(self):
        for extra in (['--collect'], ['--execute'], ['--host', CANARY], ['--credential', CANARY],
                      ['--tooling-sha', CANARY], ['--unknown', CANARY], ['--pack', CANARY]):
            with patch.object(b, 'integrity') as integrity, patch.object(b, 'read_input') as read, \
                    patch('socket.socket') as socket, patch('subprocess.Popen') as child:
                code, _ = self.run_cli(self.args + extra)
                self.assertEqual(2, code)
                integrity.assert_not_called(); read.assert_not_called()
                socket.assert_not_called(); child.assert_not_called()

    def test_input_symlink_fifo_directory_huge_and_in_repo_block(self):
        with patch.object(b, 'integrity', return_value=pins()):
            self.file.write_bytes(b'x' * 16385)
            self.assertEqual(2, self.run_cli()[0])
            self.file.unlink(); self.file.symlink_to('/dev/null')
            self.assertEqual(2, self.run_cli()[0])
            self.file.unlink(); os.mkfifo(self.file, 0o600)
            self.assertEqual(2, self.run_cli()[0])
            self.file.unlink(); self.file.mkdir()
            self.assertEqual(2, self.run_cli()[0])
            self.assertEqual(2, self.run_cli(['--tooling-sha', HEAD, '--packet', str(ROOT / CANARY)])[0])

    def test_expired_and_private_parse_or_interrupt_error_has_fixed_output(self):
        with patch.object(b, 'integrity', return_value=pins()):
            self.assertEqual('EXPIRED_OR_NOT_YET_VALID', self.run_cli()[1]['code'])
            for error in (RuntimeError(CANARY), KeyboardInterrupt(), PermissionError(CANARY), b.Block(CANARY)):
                with patch.object(b, 'load', side_effect=error):
                    self.assertEqual('OFFLINE_INPUT_OR_SOURCE_REJECTED', self.run_cli()[1]['code'])

    def test_prior_receipt_always_requires_new_go_and_none_is_not_approval(self):
        other = self.file.parent / 'receipt'
        with patch.object(b, 'integrity', return_value=pins()), patch.object(b.time, 'time', return_value=NOW):
            self.assertEqual(0, self.run_cli()[0])
            for case in ('NOT_RUN', 'COMPLETED', 'BLOCK', 'INTERRUPTED'):
                other.write_bytes(b.canonical(b.receipt(packet(), pins(), NOW, case)))
                code, value = self.run_cli(self.args + ['--prior-receipt', str(other)])
                self.assertEqual(2, code); self.assertEqual('NEW_OWNER_GO_REQUIRED', value['code'])
            other.unlink()  # Lost/omitted history cannot establish real one-shot.
            self.assertIs(False, self.run_cli()[1]['execution_allowed'])


class SourceTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.temp = tempfile.TemporaryDirectory()
        cls.root = Path(cls.temp.name).resolve() / 'repo'
        subprocess.run(['git', 'clone', '--quiet', '--shared', str(ROOT), str(cls.root)],
                       check=True, capture_output=True)
        cls.head = b.git(ROOT, 'rev-parse', 'HEAD').decode().strip()
        for ref, sha in b.PINS.items(): b.git(cls.root, 'update-ref', 'refs/remotes/origin/' + ref, sha)

    @classmethod
    def tearDownClass(cls): cls.temp.cleanup()

    def test_full_clean_source_sql_references_and_new_tooling(self):
        facts = b.integrity(self.root, self.head)
        self.assertEqual(b.SOURCE, facts['source_sha'])
        self.assertEqual(self.head, facts['tooling_sha'])

    def test_ambient_git_credentials_or_approved_env_are_not_forwarded_or_authority(self):
        real_run = subprocess.run
        calls = []
        def observed(*args, **kwargs):
            calls.append(kwargs.get('env', {}))
            return real_run(*args, **kwargs)
        with patch.dict(os.environ, {'GIT_DIR': CANARY, 'MYSQL_PWD': CANARY, 'APPROVED': 'true'}), \
                patch.object(b.subprocess, 'run', side_effect=observed):
            facts = b.integrity(self.root, self.head)
        self.assertEqual(self.head, facts['tooling_sha'])
        self.assertTrue(calls)
        self.assertTrue(all(CANARY not in env.values() and 'APPROVED' not in env for env in calls))

    def test_wrong_head_release_dependency_or_hidden_flags_block(self):
        with self.assertRaisesRegex(b.Block, 'TOOLING_MISMATCH'): b.integrity(self.root, '0' * 40)
        for ref, old in b.PINS.items():
            try:
                b.git(self.root, 'update-ref', 'refs/remotes/origin/' + ref, self.head)
                with self.assertRaisesRegex(b.Block, 'FIXED_REF_MISMATCH'): b.integrity(self.root, self.head)
            finally: b.git(self.root, 'update-ref', 'refs/remotes/origin/' + ref, old)
        path = 'scripts/ci/e858_b_offline.py'
        for yes, no in [('--assume-unchanged', '--no-assume-unchanged'), ('--skip-worktree', '--no-skip-worktree')]:
            try:
                b.git(self.root, 'update-index', yes, path)
                with self.assertRaisesRegex(b.Block, 'INDEX_FLAGS_REJECTED'): b.integrity(self.root, self.head)
            finally: b.git(self.root, 'update-index', no, path)

    def test_dirty_symlink_and_bytes_even_with_false_clean_status(self):
        real_git = b.git
        def hidden(root, *args):
            return b'' if args[0] == 'status' else real_git(root, *args)
        file = self.root / 'scripts/ci/e858_b_offline.py'; raw = file.read_bytes()
        try:
            file.write_bytes(b'SYNTHETIC_CHANGED')
            with self.assertRaisesRegex(b.Block, 'CLEAN_CHECKOUT_REQUIRED'): b.integrity(self.root, self.head)
            with patch.object(b, 'git', side_effect=hidden):
                with self.assertRaisesRegex(b.Block, 'TRACKED_BYTES_REJECTED'): b.integrity(self.root, self.head)
            file.unlink(); file.symlink_to(ROOT / 'scripts/ci/e858_b_offline.py')
            with patch.object(b, 'git', side_effect=hidden):
                with self.assertRaisesRegex(b.Block, 'TRACKED_BYTES_REJECTED'): b.integrity(self.root, self.head)
        finally: file.unlink(); file.write_bytes(raw)

    def test_sql_reference_tampering_in_git_read_cannot_pass(self):
        real_git = b.git
        for suffix, code in [('/queries/grants.sql', 'SQL_MISMATCH'), ('/e858_audit_reference/8.4.json', 'REFERENCE_MISMATCH')]:
            def changed(root, *args):
                if args[0] == 'show' and args[1].endswith(suffix): return b'SYNTHETIC_CHANGED'
                return real_git(root, *args)
            with patch.object(b, 'git', side_effect=changed):
                with self.assertRaisesRegex(b.Block, code): b.integrity(self.root, self.head)


if __name__ == '__main__': unittest.main()
