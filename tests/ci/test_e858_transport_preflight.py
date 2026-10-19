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
from unittest.mock import MagicMock
import errno
import signal

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / 'scripts/ci'))
from e858_audit import core as original
from e858_audit.transport import Budget
from e858_transport_preflight import core, adapter
from e858_transport_preflight.adapter import bounded_query


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
    def test_positive_fake_snapshot_runs_exact_order_without_socket(self):
        payload = {key: b'synthetic\n' for key in original.CAPS}
        with patch('socket.socket') as socket, patch('subprocess.Popen', wraps=subprocess.Popen) as start:
            data = adapter.capture(adapter.FakeAdapter(payload), root=ROOT)
            self.assertEqual(set(original.CAPS), set(data)); self.assertEqual(17, start.call_count)
            socket.assert_not_called()
            for call in start.call_args_list:
                self.assertNotIn('shell', call.kwargs)
                self.assertEqual('/nonexistent', call.kwargs['env']['HOME'])

    def test_ledger_identity_and_grants_change_stop_without_retry(self):
        fixed = core.plan(ROOT)
        for key in ('ledger', 'identity', 'grants'):
            seen = {}
            def query(_cmd, query_id, _budget, **_kwargs):
                seen[query_id] = seen.get(query_id, 0) + 1
                return 'changed\n' if query_id == key and seen[query_id] > 1 else 'synthetic\n'
            with patch.object(adapter, 'bounded_query', side_effect=query) as run:
                with self.assertRaisesRegex(core.Block, 'LEDGER_CHANGED' if key == 'ledger' else 'PRIVATE_BINDING_CHANGED'):
                    adapter.capture(adapter.FakeAdapter({k: b'x\n' for k in original.CAPS}), root=ROOT)
                self.assertEqual(2, seen[key]); self.assertLessEqual(run.call_count, len(fixed))

    def test_disposable_ownership_and_loopback_scope_cannot_be_substituted(self):
        state = {'Name': '/canovia-integration-80', 'State': {'Running': True},
                 'Config': {'Image': 'mysql:8.0', 'Labels': {'canovia.integration': '2026-10-16'}},
                 'NetworkSettings': {'Ports': {'3306/tcp': [{'HostIp': '127.0.0.1', 'HostPort': '13380'}]}}}
        def response(value):
            return subprocess.CompletedProcess([], 0, json.dumps([value]).encode(), b'')
        with patch.object(adapter.subprocess, 'run', return_value=response(state)) as run:
            item = adapter.DisposableAdapter('8.0'); cmd, env = item._command('identity')
            self.assertEqual('mysql', cmd[cmd.index('mysql')]); self.assertEqual('--no-defaults', cmd[cmd.index('mysql') + 1])
            self.assertNotIn('--no-login-paths', cmd)
            self.assertIn('MYSQL_TEST_LOGIN_FILE=/nonexistent/client-login', cmd)
            self.assertIn('--no-login-paths', adapter.mysql_options('8.4'))
            self.assertIn('--host=127.0.0.1', cmd)
            self.assertNotIn('DOCKER_HOST', env); self.assertEqual(2, run.call_count)
        negatives = []
        for port in ('0.0.0.0', 'synthetic.invalid', '::1'):
            changed = copy.deepcopy(state); changed['NetworkSettings']['Ports']['3306/tcp'][0]['HostIp'] = port
            negatives.append(changed)
        changed = copy.deepcopy(state); changed['Config']['Labels'] = {}; negatives.append(changed)
        changed = copy.deepcopy(state); changed['Config']['Image'] = 'mysql:8.4'; negatives.append(changed)
        changed = copy.deepcopy(state); changed['Name'] = '/SYNTHETIC_SECRET'; negatives.append(changed)
        for changed in negatives:
            with patch.object(adapter.subprocess, 'run', return_value=response(changed)), patch('subprocess.Popen') as start:
                with self.assertRaisesRegex(core.Block, 'DISPOSABLE_TARGET_REJECTED'):
                    adapter.DisposableAdapter('8.0')
                start.assert_not_called()

    def test_disposable_identity_and_privilege_checked_before_metadata(self):
        with patch.object(adapter.DisposableAdapter, '_verify'):
            item = adapter.DisposableAdapter('8.0')
            for bad_id in ('wrong\twrong\t8.0.46\tSYNTHETIC_SECRET\n',
                           'canovia_p0_ci\te858_inspector@%\t8.4.11\tsynthetic\n'):
                with patch.object(adapter, 'bounded_query', return_value=bad_id) as run:
                    with self.assertRaisesRegex(core.Block, 'DISPOSABLE_IDENTITY_REJECTED'):
                        adapter.capture(item, root=ROOT)
                    self.assertEqual(1, run.call_count)
            values = ['canovia_p0_ci\te858_inspector@%\t8.0.46\tsynthetic\n',
                      'GRANT ALL PRIVILEGES ON *.* TO `synthetic`@`localhost`\n']
            with patch.object(adapter, 'bounded_query', side_effect=values) as run:
                with self.assertRaisesRegex(core.Block, 'METADATA_GRANTS_REJECTED'):
                    adapter.capture(item, root=ROOT)
                self.assertEqual(2, run.call_count)

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

    def test_actual_eight_mib_and_sixteen_mib_limits(self):
        with self.assertRaisesRegex(core.Block, 'QUERY_BYTES_EXCEEDED'):
            self.run_fake((b'x' * 100000 + b'\n') * 84, 'column')
        budget = Budget()
        chunk = (b'x' * 100000 + b'\n') * 60
        self.run_fake(chunk, 'column', budget=budget)
        self.run_fake(chunk, 'column', budget=budget)
        with self.assertRaisesRegex(core.Block, 'QUERY_BYTES_EXCEEDED'):
            self.run_fake(chunk, 'column', budget=budget)

    def test_inherited_default_limits_and_no_per_capture_override(self):
        before = time.monotonic(); budget = Budget()
        self.assertLessEqual(budget.until - before, 120.1)
        self.assertEqual(16 * 1024 * 1024, budget.maximum)
        with self.assertRaises(TypeError):
            adapter.capture(adapter.FakeAdapter({}), root=ROOT, seconds=999)

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
                    ('import os,signal;os.kill(os.getpid(),signal.SIGTERM)', 'CLIENT_SIGNALLED'),
                    ('import sys;sys.exit(2)', 'PRIVATE_CLIENT_FAILURE')]
        for program, code in programs:
            with self.assertRaisesRegex(core.Block, code):
                bounded_query([sys.executable, '-I', '-c', program], 'engine', Budget(), seconds=.1,
                              env=adapter.child_env())
        with patch('e858_transport_preflight.adapter.selectors.DefaultSelector') as selector:
            selector.return_value.select.side_effect = KeyboardInterrupt()
            with self.assertRaisesRegex(core.Block, 'CLIENT_INTERRUPTED'):
                bounded_query([sys.executable, '-I', '-c', 'import time;time.sleep(2)'],
                              'engine', Budget(), env=adapter.child_env())

    def test_empty_trigger_never_proves_absence(self):
        self.assertEqual('', self.run_fake(b'', 'trigger'))


class CleanupTests(unittest.TestCase):
    def fake(self, payload=b'engine\t8.0\n'):
        return adapter.FakeAdapter({'engine': payload})._command('engine')

    def test_old_reader_deterministically_masks_a_block_before_fix(self):
        from e858_audit import transport as old
        cmd, env = self.fake(b'x' * 100 + b'\n')
        real_start = subprocess.Popen; processes = []
        def start(*args, **kwargs):
            process = real_start(*args, **kwargs); processes.append(process); return process
        try:
            with patch('subprocess.Popen', side_effect=start), \
                    patch.object(old.os, 'killpg', side_effect=PermissionError(errno.EPERM, 'SYNTHETIC_SECRET')):
                with self.assertRaises(PermissionError):
                    old.bounded_query(cmd, 'engine', Budget(), env=env, max_bytes=1)
        finally:
            # The unchanged old finally aborted: this test owns its exact child.
            for process in processes:
                if process.poll() is None:process.kill()
                process.wait(timeout=1)
                for pipe in (process.stdin, process.stdout, process.stderr):
                    if pipe and not pipe.closed:pipe.close()

    def test_macos_permission_failure_preserves_all_three_cap_blocks(self):
        cases = [(b'x\nx\n', {}, 'QUERY_ROWS_EXCEEDED'),
                 (b'x' * 100 + b'\n', {'max_bytes': 1}, 'QUERY_BYTES_EXCEEDED'),
                 (b'x' * 100 + b'\n', {'cell_bytes': 1}, 'QUERY_FIELD_EXCEEDED')]
        for payload, limits, code in cases:
            cmd, env = self.fake(payload)
            with patch.object(adapter.os, 'killpg', side_effect=PermissionError(errno.EPERM, 'SYNTHETIC_SECRET')):
                with self.assertRaisesRegex(core.Block, code) as caught:
                    bounded_query(cmd, 'engine', Budget(), env=env, **limits)
                self.assertNotIn('SYNTHETIC_SECRET', str(caught.exception))

    def test_normal_childless_exit_needs_no_group_signal(self):
        cmd, env = self.fake()
        with patch.object(adapter.os, 'killpg', side_effect=PermissionError(errno.EPERM, 'SYNTHETIC_SECRET')) as kill:
            self.assertEqual('engine\t8.0\n', bounded_query(cmd, 'engine', Budget(), env=env))
            kill.assert_not_called()

    def test_live_childless_eperm_uses_direct_kill_then_bounded_wait(self):
        process = MagicMock(); process.poll.return_value = None; process.wait.return_value = -9
        with patch.object(adapter.os, 'killpg', side_effect=PermissionError(errno.EPERM, 'SYNTHETIC_SECRET')):
            self.assertIsNone(adapter._cleanup(process, 'CHILDLESS_FAKE', False))
        process.kill.assert_called_once_with(); process.wait.assert_called_once_with(timeout=0.5)

    def test_real_blocked_writer_eperm_is_reaped_without_losing_cap_error(self):
        cmd, env = self.fake(b'x' * (2*1024*1024) + b'\n')
        real_start = subprocess.Popen; processes = []
        def start(*args, **kwargs):
            process = real_start(*args, **kwargs); processes.append(process); return process
        with patch('subprocess.Popen', side_effect=start), \
                patch.object(adapter.os, 'killpg', side_effect=PermissionError(errno.EPERM, 'SYNTHETIC_SECRET')) as group:
            with self.assertRaisesRegex(core.Block, 'QUERY_BYTES_EXCEEDED'):
                bounded_query(cmd, 'engine', Budget(), env=env, max_bytes=1)
            self.assertGreaterEqual(group.call_count, 1)
        self.assertIsNotNone(processes[0].returncode)
        self.assertTrue(all(p.closed for p in (processes[0].stdin, processes[0].stdout, processes[0].stderr)))

    def test_failed_direct_kill_or_wait_cannot_be_ignored(self):
        for method in ('kill', 'wait'):
            process = MagicMock(); process.poll.return_value = None
            getattr(process, method).side_effect = PermissionError(errno.EPERM, 'SYNTHETIC_SECRET')
            with patch.object(adapter.os, 'killpg', side_effect=PermissionError(errno.EPERM, 'SYNTHETIC_SECRET')):
                self.assertEqual('PROCESS_TERMINATION_UNVERIFIED',
                                 adapter._cleanup(process, 'CHILDLESS_FAKE', False))
        process = MagicMock(); process.poll.return_value = None
        process.wait.side_effect = subprocess.TimeoutExpired('SYNTHETIC_SECRET', .5)
        with patch.object(adapter.os, 'killpg'):
            self.assertEqual('PROCESS_TERMINATION_UNVERIFIED',
                             adapter._cleanup(process, 'CHILDLESS_FAKE', False))

    def test_unproven_or_docker_eperm_cannot_use_childless_certificate(self):
        for contract in ('UNPROVEN_PROCESS_TREE', 'DISPOSABLE_DOCKER'):
            process = MagicMock(); process.poll.return_value = None; process.wait.return_value = -9
            with patch.object(adapter.os, 'killpg', side_effect=PermissionError(errno.EPERM, 'SYNTHETIC_SECRET')):
                self.assertEqual('PROCESS_GROUP_TERMINATION_UNVERIFIED',
                                 adapter._cleanup(process, contract, False))
            process.kill.assert_called_once_with()

    def test_group_probe_permission_error_blocks_even_after_parent_exit(self):
        process = MagicMock(); process.poll.return_value = 0; process.wait.return_value = 0
        with patch.object(adapter.os, 'killpg', side_effect=PermissionError(errno.EPERM, 'SYNTHETIC_SECRET')):
            self.assertEqual('PROCESS_GROUP_TERMINATION_UNVERIFIED',
                             adapter._cleanup(process, 'DISPOSABLE_DOCKER', True))

    def test_missing_group_is_verified_but_surviving_descendant_blocks(self):
        process = MagicMock(); process.poll.return_value = 0; process.wait.return_value = 0
        with patch.object(adapter.os, 'killpg', side_effect=ProcessLookupError()):
            self.assertIsNone(adapter._cleanup(process, 'UNPROVEN_PROCESS_TREE', True))
        with patch.object(adapter.os, 'killpg') as group, patch.object(adapter.time, 'monotonic', side_effect=[0, 1]):
            self.assertEqual('PROCESS_GROUP_TERMINATION_UNVERIFIED',
                             adapter._cleanup(process, 'UNPROVEN_PROCESS_TREE', True))
            self.assertTrue(all(call.args[1] == 0 for call in group.call_args_list))
        with patch.object(adapter.os, 'killpg', side_effect=[None, None, ProcessLookupError()]):
            self.assertEqual('UNEXPECTED_DESCENDANT_DETECTED',
                             adapter._cleanup(process, 'UNPROVEN_PROCESS_TREE', True))

    def test_docker_normal_exit_and_abort_have_different_remote_proofs(self):
        process = MagicMock(); process.poll.return_value = 0; process.wait.return_value = 0
        with patch.object(adapter.os, 'killpg', side_effect=ProcessLookupError()):
            self.assertIsNone(adapter._cleanup(process, 'DISPOSABLE_DOCKER', True))
            self.assertEqual('DISPOSABLE_REMOTE_TERMINATION_UNVERIFIED',
                             adapter._cleanup(process, 'DISPOSABLE_DOCKER', False))

    def test_certificate_requires_exact_program_and_clean_environment(self):
        cmd, env = self.fake()
        self.assertEqual('CHILDLESS_FAKE', adapter._contract(cmd, env))
        for command, environment in [(cmd + ['SYNTHETIC_SECRET'], env),
                (cmd, dict(env, PYTHONPATH='/synthetic')), ([sys.executable, '-c', adapter.FAKE], env),
                ([sys.executable, '-I', '-c', 'import os;os.fork()'], adapter.child_env())]:
            self.assertEqual('UNPROVEN_PROCESS_TREE', adapter._contract(command, environment))
        self.assertEqual('DISPOSABLE_DOCKER',
                         adapter._contract(adapter._disposable_command('8.0'), adapter.child_env()))

    def test_unproven_command_or_environment_never_starts_a_process(self):
        cmd, env = self.fake()
        for command, environment in [(cmd + ['SYNTHETIC_SECRET'], env),
                (cmd, dict(env, PYTHONPATH='/synthetic')),
                ([sys.executable, '-I', '-c', 'import os;os.fork()'], adapter.child_env())]:
            with patch('subprocess.Popen') as start:
                with self.assertRaisesRegex(core.Block, 'CLIENT_CONTRACT_REJECTED'):
                    bounded_query(command, 'engine', Budget(), env=environment)
                start.assert_not_called()

    def test_cleanup_block_retains_fixed_primary_code_without_raw_error(self):
        cmd, env = self.fake(b'x' * 100 + b'\n')
        cleanup = adapter._cleanup
        def injected(*args):
            cleanup(*args)
            return 'PROCESS_TERMINATION_UNVERIFIED'
        with patch.object(adapter, '_cleanup', side_effect=injected):
            with self.assertRaisesRegex(core.Block, 'PROCESS_TERMINATION_UNVERIFIED') as caught:
                bounded_query(cmd, 'engine', Budget(), env=env, max_bytes=1)
            self.assertEqual('QUERY_BYTES_EXCEEDED', caught.exception.primary_code)

    def test_limits_cannot_be_increased_even_by_direct_internal_call(self):
        cmd, env = self.fake()
        for kwargs in ({'seconds': 11}, {'max_bytes': 8*1024*1024+1},
                       {'row_bytes': 256*1024+1}, {'cell_bytes': 128*1024+1}):
            with patch('subprocess.Popen') as start:
                with self.assertRaisesRegex(core.Block, 'STREAM_LIMIT_OVERRIDE_REJECTED'):
                    bounded_query(cmd, 'engine', Budget(), env=env, **kwargs)
                start.assert_not_called()


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
