"""Adversarial contracts. All payloads/identities are invented; no credential reads."""
import copy
import importlib.util
import io
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import time
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / 'scripts/ci'))
from e858_audit import core as original
from e858_audit.transport import Budget, bounded_query
from e858_transport_preflight import core, adapter


def load_packet():
    spec = importlib.util.spec_from_file_location('transport_packet',
        ROOT / 'scripts/ci/e858_transport_preflight/packet.py')
    value = importlib.util.module_from_spec(spec); spec.loader.exec_module(value)
    return value


def binding():
    return dict(kind='SYNTHETIC_ONLY', host='synthetic.invalid', port=13380,
                schema='synthetic_ci', engine='8.0', server_uuid='00000000-0000-0000-0000-000000000000',
                actor='synthetic_actor', ca_sha256='0' * 64, expires_at=101,
                source_sha=original.CANDIDATE, tooling_sha=core.BASE)


class BoundaryTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.tmp = tempfile.TemporaryDirectory(prefix='e858-transport-source-')
        cls.root = Path(cls.tmp.name).resolve() / 'repo'
        subprocess.run(['git', 'clone', '--quiet', '--shared', str(ROOT), str(cls.root)],
                       check=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
        cls.head = original.git(ROOT, 'rev-parse', 'HEAD')
        for ref, sha in [(core.BRANCH, core.BASE), (original.RELEASE, original.CANDIDATE),
                         ('release/2026-10-19-c09a8fc7', original.OLD),
                         ('production', '15abdc9de6f24b5250fb255c579d1aa55facaf1a'),
                         ('main', original.CANDIDATE)]:
            cls.git('update-ref', 'refs/remotes/origin/' + ref, sha)

    @classmethod
    def git(cls, *args):
        return original.git(cls.root, *args)

    @classmethod
    def tearDownClass(cls):
        cls.tmp.cleanup()

    def test_clean_full_history_all_blobs_refs_and_source(self):
        facts = core.boundary(self.root, self.head)
        self.assertEqual((94, 114), (len(facts['baseline']), len(facts['candidate'])))
        self.assertEqual(original.SEAL, facts['migration_seal'])

    def test_head_and_dependency_ref_drift(self):
        with self.assertRaisesRegex(core.Block, 'TRANSPORT_HEAD_REJECTED'):
            core.boundary(self.root, 'a' * 40)
        ref = 'refs/remotes/origin/' + core.BRANCH
        try:
            self.git('update-ref', ref, original.CANDIDATE)
            with self.assertRaisesRegex(core.Block, 'FIXED_REF_DRIFT'):
                core.boundary(self.root, self.head)
        finally:
            self.git('update-ref', ref, core.BASE)

    def test_release_and_production_drift(self):
        for name in (original.RELEASE, 'production', 'release/2026-10-19-c09a8fc7'):
            ref = 'refs/remotes/origin/' + name; old = self.git('rev-parse', ref)
            try:
                self.git('update-ref', ref, core.BASE)
                with self.assertRaisesRegex(core.Block, 'FIXED_REF_DRIFT'):
                    core.boundary(self.root, self.head)
            finally:
                self.git('update-ref', ref, old)

    def test_dirty(self):
        path = self.root / 'synthetic-untracked'; path.write_text('synthetic')
        try:
            with self.assertRaisesRegex(core.Block, 'CLEAN_CHECKOUT_REQUIRED'):
                core.boundary(self.root, self.head)
        finally:
            path.unlink()

    def test_real_shallow_clone(self):
        shallow = Path(self.tmp.name).resolve() / 'shallow'
        subprocess.run(['git', 'clone', '--quiet', '--depth=1', ROOT.as_uri(), str(shallow)],
                       check=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
        with self.assertRaisesRegex(core.Block, 'FULL_HISTORY_REQUIRED'):
            core.boundary(shallow, self.head)

    def test_assume_unchanged_and_skip_worktree_even_without_content_changes(self):
        path = 'scripts/ci/e858_audit/queries/engine.sql'
        for yes, no in [('--assume-unchanged', '--no-assume-unchanged'),
                        ('--skip-worktree', '--no-skip-worktree')]:
            try:
                self.git('update-index', yes, path)
                with self.assertRaisesRegex(core.Block, 'INDEX_FLAGS_REJECTED'):
                    core.boundary(self.root, self.head)
            finally:
                self.git('update-index', no, path)

    def test_reference_sql_new_code_and_symlink_tamper(self):
        for relative in ('scripts/ci/e858_audit_reference/8.0.json',
                         'scripts/ci/e858_audit/queries/engine.sql', core.FILES[0]):
            path = self.root / relative; raw = path.read_bytes()
            try:
                path.write_bytes(b'SYNTHETIC_SECRET')
                with self.assertRaises(core.Block):
                    core.boundary(self.root, self.head)
                path.unlink(); path.symlink_to(ROOT / relative)
                with self.assertRaises(core.Block):
                    core.boundary(self.root, self.head)
            finally:
                path.unlink(); path.write_bytes(raw)


class SQLAndEnvironmentTests(unittest.TestCase):
    def test_fixed_14_statements_and_17_ordered_executions(self):
        plan = core.plan(ROOT)
        self.assertEqual(17, len(plan)); self.assertEqual(14, len(dict(plan)))
        self.assertEqual(b'SHOW GRANTS FOR CURRENT_USER();\n', dict(plan)['grants'])

    def test_every_sql_mutation_and_order_change_precedes_process_start(self):
        attacks = [b'SELECT 1; SELECT 2;', b'DROP TABLE synthetic;', b'GRANT USAGE ON *.* TO synthetic;',
                   b'SHOW TABLES;', b'SELECT SLEEP(1);', b'SELECT LOAD_FILE("synthetic");',
                   b'SELECT @synthetic;', b'SELECT /* synthetic */ 1;', b'SELECT /*+ SET_VAR(x=1) */ 1;']
        fixed = core.plan(ROOT)
        for attack in attacks:
            changed = (('identity', attack),) + fixed[1:]
            with patch('subprocess.Popen') as start:
                with self.assertRaises(core.Block):
                    adapter.capture(adapter.FakeAdapter({}), root=ROOT, supplied_plan=changed)
                start.assert_not_called()
        with patch('subprocess.Popen') as start:
            with self.assertRaisesRegex(core.Block, 'SQL_PLAN_REJECTED'):
                adapter.capture(adapter.FakeAdapter({}), root=ROOT, supplied_plan=fixed[::-1])
            start.assert_not_called()

    def test_environment_overrides_block_before_process_or_socket(self):
        names = ('MYSQL_PWD', 'MYSQL_HOME', 'MYSQL_TEST_LOGIN_FILE', 'MYSQL_HOST', 'MYSQL_TCP_PORT',
                 'DB_DATABASE', 'DB_FUTURE_OPTION', 'DATABASE_URL', 'CANOVIA_TRANSPORT_ENABLE',
                 'CANOVIA_E858_ALLOW_REAL', 'CANOVIA_P0_LOGIN_PATH')
        for key in names:
            with patch.dict(os.environ, {key: 'SYNTHETIC_SECRET'}, clear=True), \
                    patch('subprocess.Popen') as start, patch('socket.socket') as socket:
                with self.assertRaisesRegex(core.Block, 'ENVIRONMENT_OVERRIDE_REJECTED'):
                    adapter.capture(adapter.FakeAdapter({}), root=ROOT)
                start.assert_not_called(); socket.assert_not_called()

    def test_home_and_client_files_never_read_or_forwarded(self):
        with patch.dict(os.environ, {'HOME': '/synthetic/.my.cnf', 'PATH': '/synthetic/bin'}, clear=True), \
                patch('builtins.open') as opened:
            core.environment_guard(); env = adapter.child_env(); opened.assert_not_called()
        self.assertEqual('/nonexistent', env['HOME'])
        self.assertNotIn('/synthetic', json.dumps(env))

    def test_arbitrary_commands_targets_and_options_have_no_ingress(self):
        for target in ('synthetic.invalid', '127.0.0.2', '::1', '/synthetic.sock', '--defaults-file=x',
                       '8.0;synthetic', '8.0 --host=synthetic.invalid'):
            with patch('subprocess.run') as start, patch('socket.socket') as socket:
                with self.assertRaisesRegex(core.Block, 'DISPOSABLE_TARGET_REJECTED'):
                    adapter.DisposableAdapter(target)
                start.assert_not_called(); socket.assert_not_called()
        with self.assertRaises(TypeError):
            adapter.FakeAdapter({}, command=['sh', '-c', 'synthetic'])

    def test_unknown_adapter_is_rejected_before_process(self):
        with patch('subprocess.Popen') as start:
            with self.assertRaisesRegex(core.Block, 'ADAPTER_NOT_ALLOWLISTED'):
                adapter.capture(object(), root=ROOT)
            start.assert_not_called()

    def test_fake_client_environment_is_constant_and_shell_free(self):
        cmd, env = adapter.FakeAdapter({'engine': b'engine\t8.0\n'})._command('engine')
        self.assertEqual(sys.executable, cmd[0]); self.assertEqual(['-I', '-B', '-c'], cmd[1:4])
        self.assertNotIn('MYSQL_PWD', env); self.assertNotIn('DB_HOST', env)
        out = bounded_query(cmd, 'engine', Budget(), env=env)
        self.assertEqual('engine\t8.0\n', out)


class StreamTests(unittest.TestCase):
    def run_fake(self, raw, key='engine', **kwargs):
        cmd, env = adapter.FakeAdapter({key: raw})._command(key)
        return bounded_query(cmd, key, kwargs.pop('budget', Budget()), env=env, **kwargs)

    def test_category_cap_plus_one_all_14_categories(self):
        for key, cap in original.CAPS.items():
            with self.assertRaisesRegex(core.Block, 'QUERY_ROWS_EXCEEDED'):
                self.run_fake(b'x\n' * (cap + 1), key)

    def test_exact_cell_and_row_boundary_then_plus_one(self):
        self.assertEqual('x' * (128 * 1024 - 1) + '\n', self.run_fake(b'x' * (128 * 1024 - 1) + b'\n'))
        with self.assertRaisesRegex(core.Block, 'QUERY_FIELD_EXCEEDED'):
            self.run_fake(b'x' * (128 * 1024 + 1) + b'\n')
        row = b'x' * 100000 + b'\t' + b'y' * 100000 + b'\t' + b'z' * 63000 + b'\n'
        with self.assertRaisesRegex(core.Block, 'QUERY_FIELD_EXCEEDED'):
            self.run_fake(row)

    def test_section_and_cumulative_byte_caps(self):
        for kwargs in ({'max_bytes': 8}, {'budget': Budget(maximum=8)}):
            with self.assertRaisesRegex(core.Block, 'QUERY_BYTES_EXCEEDED'):
                self.run_fake(b'engine\t8.0\n', **kwargs)

    def test_partial_non_ascii_controls_and_del(self):
        for raw in (b'partial', b'\xff\n', b'x\x00\n', b'x\x1b\n', b'x\x7f\n', b'x\r\n'):
            with self.assertRaisesRegex(core.Block, 'PRIVATE_OUTPUT_REJECTED'):
                self.run_fake(raw)

    def test_deadline_before_any_process(self):
        with patch('subprocess.Popen') as start:
            with self.assertRaisesRegex(core.Block, 'QUERY_TIMEOUT'):
                bounded_query([], 'engine', Budget(seconds=-1))
            start.assert_not_called()

    def test_timeout_stderr_signal_and_interrupt_cleanup(self):
        programs = [('import time;time.sleep(2)', 'QUERY_TIMEOUT'),
                    ('import sys;sys.stderr.write("SYNTHETIC_SECRET")', 'PRIVATE_CLIENT_FAILURE'),
                    ('import os,signal;os.kill(os.getpid(),signal.SIGTERM)', 'PRIVATE_CLIENT_FAILURE')]
        for program, code in programs:
            with self.assertRaisesRegex(core.Block, code):
                bounded_query([sys.executable, '-I', '-c', program], 'engine', Budget(), seconds=.1,
                              env=adapter.child_env())
        with patch('e858_audit.transport.selectors.DefaultSelector') as selector:
            selector.return_value.select.side_effect = KeyboardInterrupt()
            with self.assertRaisesRegex(core.Block, 'PRIVATE_CLIENT_FAILURE'):
                bounded_query([sys.executable, '-I', '-c', 'import time;time.sleep(2)'],
                              'engine', Budget(), env=adapter.child_env())

    def test_empty_trigger_never_proves_absence(self):
        self.assertEqual('', self.run_fake(b'', 'trigger'))


class BindingAndPacketTests(unittest.TestCase):
    def test_binding_shape_is_not_authorization(self):
        b = binding(); out = core.synthetic_binding(b, copy.deepcopy(b), 100)
        self.assertFalse(out['authenticated']); self.assertEqual('UNIMPLEMENTED', out['approval'])
        with self.assertRaisesRegex(core.Block, 'APPROVAL_AUTHENTICATION_UNIMPLEMENTED'):
            core.authenticate_approval(b, signature='SELF_SIGNED_SYNTHETIC')

    def test_binding_fields_mismatch_toc_tou_and_expiry(self):
        b = binding()
        for key in b:
            changed = copy.deepcopy(b); changed[key] = 'SYNTHETIC_CHANGED'
            with self.assertRaises(core.Block):
                core.synthetic_binding(b, changed, 100)
        with self.assertRaisesRegex(core.Block, 'BINDING_EXPIRED_OR_WINDOW_REJECTED'):
            core.synthetic_binding(b, b, 102)
        for key in b:
            changed = copy.deepcopy(b); changed[key] = 'SYNTHETIC_CHANGED'
            with self.assertRaises(core.Block):
                core.synthetic_binding(changed, changed, 100)

    def test_unsigned_approval_is_never_accepted(self):
        for value in ({}, {'approved': True}, {'signature': 'SYNTHETIC'}, {'hash': 'a' * 64}):
            with self.assertRaisesRegex(core.Block, 'APPROVAL_AUTHENTICATION_UNIMPLEMENTED'):
                core.authenticate_approval(value)

    def test_new_and_original_collect_absolute_refusal_no_process_or_socket(self):
        for packet in (load_packet(), load_packet().old):
            argv = ['offline', '--collect', '--candidate-sha', original.CANDIDATE, '--tooling-sha', core.BASE]
            with patch.object(sys, 'argv', argv), patch('subprocess.Popen') as start, \
                    patch('socket.socket') as socket, patch('sys.stdout', new_callable=io.StringIO) as out:
                self.assertEqual(2, packet.main())
                self.assertEqual('REAL_CONNECTION_DISABLED', json.loads(out.getvalue())['code'])
                start.assert_not_called(); socket.assert_not_called()

    def test_cli_unknown_flags_and_exception_text_are_redacted(self):
        packet = load_packet()
        for argv in (['--help'], ['--enable-real', 'SYNTHETIC_SECRET'], ['--defaults-file=SYNTHETIC_SECRET']):
            with patch.object(sys, 'argv', ['offline', *argv]), \
                    patch('sys.stdout', new_callable=io.StringIO) as out, patch('subprocess.Popen') as start:
                self.assertEqual(2, packet.main()); start.assert_not_called()
                self.assertNotIn('SYNTHETIC_SECRET', out.getvalue()); json.loads(out.getvalue())
        for error in (RuntimeError('SYNTHETIC_SECRET'), KeyboardInterrupt()):
            with patch.object(sys, 'argv', ['offline', '--transport-sha', 'a' * 40,
                    '--base-receipt', 'unused', '--transport-receipt', 'unused']), \
                    patch.object(packet, 'packet', side_effect=error), \
                    patch('sys.stdout', new_callable=io.StringIO) as out:
                self.assertEqual(2, packet.main()); self.assertNotIn('SYNTHETIC_SECRET', out.getvalue())

    def test_independent_receipts_need_exact_head_and_all_success_jobs(self):
        value = dict(headSha='a' * 40, workflowName='synthetic', status='completed', conclusion='success',
                     url='https://github.com/1kz-ma1/Canovia-web/actions/runs/1',
                     jobs=[dict(name='synthetic', status='completed', conclusion='success')])
        self.assertEqual(1, core.receipt(value, 'a' * 40, 'synthetic', ['synthetic']))
        for field in ('headSha', 'workflowName', 'status', 'conclusion', 'url'):
            changed = copy.deepcopy(value); changed[field] = 'SYNTHETIC_SECRET'
            with self.assertRaises(core.Block):
                core.receipt(changed, 'a' * 40, 'synthetic', ['synthetic'])
        for verdict in ('skipped', 'neutral', 'cancelled', 'failure', None):
            changed = copy.deepcopy(value); changed['jobs'][0]['conclusion'] = verdict
            with self.assertRaises(core.Block):
                core.receipt(changed, 'a' * 40, 'synthetic', ['synthetic'])

    def test_legacy_offline_collectors_stay_blocked(self):
        for script, args in [('integrated_release20_collect.py', ['--source-sha', core.BASE]),
                ('integrated_release20_diagnostic_collect.py', ['--tooling-sha', core.BASE,
                  '--candidate-sha', original.OLD])]:
            p = subprocess.run([sys.executable, '-B', str(ROOT / 'scripts/ops' / script), *args],
                               stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=30)
            self.assertEqual(2, p.returncode); self.assertEqual('BLOCK', json.loads(p.stdout)['result'])


if __name__ == '__main__':
    unittest.main()
