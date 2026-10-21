"""Offline R1 ownership/handoff contracts; all terminals and secrets synthetic."""
import ast
import contextlib
from dataclasses import fields, replace
import hashlib
import io
import json
import os
from pathlib import Path
import sys
import termios
import time
import traceback
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / 'scripts/ci'))
from e858_phase1 import r1_adapter as a, runtime as r
from e858_phase1.protocol import INIT, CID, CONTROL_ID, PROCESS, Gate
from e858_b_offline import git, SOURCE, RELEASE, COLLECTOR, TRANSPORT, CONTRACT, template, DECLARATIONS

HEAD = git(ROOT, 'rev-parse', 'HEAD').decode().strip()
Q0 = git(ROOT, 'show', COLLECTOR + ':scripts/ci/e858_audit/queries/identity.sql')
Q1 = git(ROOT, 'show', COLLECTOR + ':scripts/ci/e858_audit/queries/grants.sql')
DIGESTS = {name: hashlib.sha256(git(ROOT, 'show', COLLECTOR + ':scripts/ci/e858_audit/queries/' + name + '.sql')).hexdigest()
           for name in ('identity','grants','ledger','engine','table','column','index','foreign_key','check','trigger',
                        'semantic_version','semantic_index','semantic_foreign_key','column_extra')}
UUID = '11111111-1111-1111-1111-111111111111'
ACTION = a.InputAction.SYNTHETIC_OPERATOR_GESTURE
CANARY = 'SYNTHETIC_PRIVATE_ERROR_NEVER_RENDER'


def adapter(**kwargs):
    return a.SyntheticAdapter(HEAD, Q0, Q1, **kwargs)


def config(series):
    pins = dict(source_sha=SOURCE, release_ref=RELEASE, tooling_sha=HEAD,
                collector_sha=COLLECTOR, transport_sha=TRANSPORT, contract_sha=CONTRACT,
                sql_sha256=a.SQL_SHA, reference_sha256={'8.0':'ab7a27ff4f95be54fa802d1d801082ac508f1611d32215003a5f1d6c0d9270b6',
                    '8.4':'c23bb40ad94a4c33392e7a237d81cad1d576816065f5bb48f4ee86ea323e86d7'})
    packet = template(pins, int(time.time()))
    packet.update(declarations=DECLARATIONS, attempt_id='a'*32, engine=series)
    return dict(q0=Q0, q1=Q1, pins=pins, packet=packet, caps=dict(grants=8), series=series, sql_digests=DIGESTS,
                expected=dict(uuid=UUID, binding=dict(target=hashlib.sha256(b'synthetic').hexdigest(),
                    server_uuid=UUID, principal=a.USER+'@%', ca=hashlib.sha256(a.CA_MARKER).hexdigest(),
                    san=hashlib.sha256(a.HOST.encode()).hexdigest())))


class MockChannel:
    """Fixture oracle only. Not TLS/server-side proof, never an executor."""
    def __init__(self, boundary, role, cfg, budget):
        self.boundary, self.config = boundary, cfg
        self.conn = boundary.open_mock(role)
        self.gate = self.conn.gate
        self.original, self.cid = self.conn, 124 if role == 'control' else 123
        self.closed = False
        # Driver init SQL is checked by the unchanged Gate, never sent anywhere.
        self.conn.claim_mock(INIT)
        self.gate.sent.append(INIT.decode())

    def check_owned(self):
        if self.conn is not self.original:
            raise a.Block('R1_ADAPTER_BLOCKED')

    def read(self, sql, cap=1):
        if self.closed: raise a.Block('R1_ADAPTER_BLOCKED')
        self.check_owned()
        self.conn.claim_mock(sql)
        self.gate.sent.append(sql.decode('ascii'))
        identity = [a.DB, a.USER+'@%', self.config['series']+'.99-synthetic', UUID]
        if sql == CONTROL_ID: return '\t'.join(identity+[str(self.cid)])+'\n'
        if sql == Q0: return '\t'.join(identity)+'\n'
        if sql == CID: return str(self.cid)+'\n'
        if sql == Q1:
            account = '`'+a.USER+'`@`%`'
            return (f'GRANT REFERENCES ON `{a.DB}`.* TO {account}\n'
                    f'GRANT SELECT ON `{a.DB}`.`migrations` TO {account}\n')
        if sql.startswith(PROCESS):
            if self.gate.owner.closed: return ''
            return f'{self.gate.owner.cid}\t{a.USER}\t{a.DB}\tSleep\n'
        raise a.Block('R1_ADAPTER_BLOCKED')

    def normal_close(self):
        self.check_owned()
        self.conn.quit_written = True  # MOCK marker, not a COM_QUIT write proof
        self.conn.close()
        self.closed = True

    def drop(self):
        self.conn.close()
        self.closed = True


class AdapterTests(unittest.TestCase):
    def assert_disposed(self, value):
        self.assertTrue(value._closed)
        self.assertEqual(value._input._password, b'')
        self.assertEqual(value._drivers, [])
        self.assertEqual(value._leases, [])
        self.assertFalse(value.report()['execution_allowed'])
        self.assertFalse(value.report()['owner_approval_verified'])
        self.assertFalse(value.report()['real_aiven_connected'])

    def blocked_capture(self, value, action=ACTION):
        out, err = io.StringIO(), io.StringIO()
        with contextlib.redirect_stdout(out), contextlib.redirect_stderr(err):
            with self.assertRaises(a.Block) as raised:
                with value: value.capture(action)
        rendered = ''.join(traceback.format_exception(type(raised.exception), raised.exception, raised.exception.__traceback__))
        self.assertNotIn(CANARY, rendered + out.getvalue() + err.getvalue() + repr(value) + json.dumps(value.report()))
        self.assert_disposed(value)

    def test_explicit_action_required_before_any_tty_acquisition(self):
        for action in (None, True, 'APPROVED=true', CANARY):
            with self.subTest(action=type(action).__name__), patch.object(a.pty, 'openpty') as opened:
                self.blocked_capture(adapter(), action)
                opened.assert_not_called()

    def test_contract_mutation_rejected_before_input(self):
        original = a.Contract(HEAD)
        for field in fields(original):
            fixed = getattr(original, field.name)
            changed = (False if type(fixed) is bool else 3307 if type(fixed) is int else
                       b'changed' if type(fixed) is bytes else (3,) if type(fixed) is tuple else CANARY)
            with self.subTest(field=field.name), patch.object(a.pty, 'openpty') as opened:
                self.blocked_capture(adapter(candidate=replace(original, **{field.name:changed})))
                opened.assert_not_called()

    def test_type_confusion_in_port_and_limits_rejected(self):
        for candidate in (replace(a.Contract(HEAD), port=True), replace(a.Contract(HEAD), limits=(True,15,42,10,120,a.CELL,a.ROW,a.SECTION,a.TOTAL)), object()):
            self.blocked_capture(adapter(candidate=candidate))

    def test_wrong_source_tooling_and_q0_q1_bytes(self):
        self.blocked_capture(a.SyntheticAdapter('invalid', Q0, Q1))
        self.blocked_capture(a.SyntheticAdapter(HEAD, Q0.strip(), Q1))
        self.blocked_capture(a.SyntheticAdapter(HEAD, Q0, Q1+b'SELECT 1;'))
        self.assertEqual(hashlib.sha256(Q0).hexdigest(), a.Q0_SHA)
        self.assertEqual(hashlib.sha256(Q1).hexdigest(), a.Q1_SHA)

    def test_owned_tty_noninheritance_restoration_and_close(self):
        value = adapter()
        with value:
            result = value.capture(ACTION)
            terminal = value._tty
            terminal.check()
            self.assertFalse(os.get_inheritable(terminal.slave))
            self.assertTrue(termios.tcgetattr(terminal.slave)[3] & termios.ECHO)
            descriptors = (terminal.master, terminal.slave)
        self.assertEqual(result['status'], 'R1_CAPTURED_OFFLINE')
        self.assert_disposed(value)
        self.assertTrue(value.report()['cleanup_verified'])
        for fd in descriptors:
            with self.assertRaises(OSError): os.fstat(fd)

    def test_capture_outside_managed_scope_refused(self):
        value = adapter()
        with self.assertRaises(a.Block): value.capture(ACTION)
        self.assert_disposed(value)

    def test_tty_acquisition_failure_is_fixed_and_no_retry(self):
        value = adapter()
        with patch.object(a.pty, 'openpty', side_effect=OSError(CANARY)):
            self.blocked_capture(value)
        self.blocked_capture(value)

    def test_non_tty_pair_rejected_and_owned_descriptors_closed(self):
        read_fd, write_fd = os.pipe()
        with patch.object(a.pty, 'openpty', return_value=(read_fd, write_fd)):
            self.blocked_capture(adapter())
        for fd in (read_fd, write_fd):
            with self.assertRaises(OSError): os.fstat(fd)

    def test_unknown_or_standard_descriptor_refused_without_fallback(self):
        for changed in (0, -1, None):
            value = adapter()
            with self.assertRaises(a.Block):
                with value:
                    value.capture(ACTION)
                    value._tty.slave = changed
                    value.open_mock('control')
            self.assert_disposed(value)
            self.assertTrue(os.isatty(0) or os.fstat(0))  # stdin was never closed

    def test_input_failure_does_not_reach_driver(self):
        with patch.object(a.PrivateTTYAttempt, 'capture', return_value='R1_TERMINAL_RESTORE_UNVERIFIED'), \
                patch.object(a, 'SyntheticDriver') as driver:
            self.blocked_capture(adapter())
            driver.assert_not_called()

    def test_same_readonly_buffer_for_only_two_fixed_mock_roles(self):
        value = adapter()
        with value:
            value.capture(ACTION)
            control, data = value.open_mock('control'), value.open_mock('data')
            leases = (control._lease, data._lease)
            original = value._input._password
            same = all(view.obj is original and view.readonly for view in leases)
        self.assertTrue(same)
        self.assertEqual(original, b'')
        self.assertIsNone(control._lease)
        self.assertIsNone(data._lease)
        for view in leases:
            with self.assertRaises(ValueError): len(view)
        self.assert_disposed(value)

    def test_unknown_role_order_and_third_connection_rejected(self):
        for roles in (('root',), ('data',), ('control','control'), ('control','data','control')):
            value = adapter()
            with self.assertRaises(a.Block):
                with value:
                    value.capture(ACTION)
                    for role in roles: value.open_mock(role)
            self.assert_disposed(value)

    def test_control_closed_before_data_requires_new_attempt(self):
        value = adapter()
        with self.assertRaises(a.Block):
            with value:
                value.capture(ACTION)
                value.open_mock('control').close()
                value.open_mock('data')
        self.assert_disposed(value)

    def test_contract_change_after_capture_refused(self):
        value = adapter()
        with self.assertRaises(a.Block):
            with value:
                value.capture(ACTION)
                value.candidate = replace(value.candidate, host=CANARY)
                value.open_mock('control')
        self.assert_disposed(value)

    def test_driver_creation_failure_releases_inflight_lease(self):
        value = adapter()
        with self.assertRaises(a.Block):
            with value:
                value.capture(ACTION)
                with patch.object(a, 'SyntheticDriver', side_effect=OSError(CANARY)):
                    value.open_mock('control')
        self.assert_disposed(value)

    def test_mock_close_failure_does_not_preserve_secret(self):
        value = adapter()
        with value:
            value.capture(ACTION)
            driver = value.open_mock('control')
            view = driver._lease
            with patch.object(driver, 'close', side_effect=OSError(CANARY)):
                result = value.close()
        self.assertEqual(result['status'], 'R1_CLEANUP_UNVERIFIED')
        self.assertFalse(result['cleanup_verified'])
        with self.assertRaises(ValueError): len(view)
        self.assert_disposed(value)

    def test_foreign_error_or_interrupt_sanitized_after_handoff(self):
        for error in (OSError(CANARY), KeyboardInterrupt(CANARY)):
            value = adapter()
            with self.assertRaises(a.Block) as raised:
                with value:
                    value.capture(ACTION)
                    value.open_mock('control')
                    value.open_mock('data')
                    raise error
            rendered = ''.join(traceback.format_exception(type(raised.exception), raised.exception, raised.exception.__traceback__))
            self.assertNotIn(CANARY, rendered + json.dumps(value.report()))
            self.assert_disposed(value)

    def test_retry_after_capture_or_closed_scope_refused(self):
        value = adapter()
        with self.assertRaises(a.Block):
            with value:
                value.capture(ACTION)
                value.capture(ACTION)
        self.assert_disposed(value)
        self.blocked_capture(value)

    def test_environment_cannot_override_target_or_load_credentials(self):
        value = adapter()
        with patch.dict(os.environ, MYSQL_HOST=CANARY, MYSQL_PWD=CANARY, MYSQL_TEST_LOGIN_FILE=CANARY, SSL_CERT_FILE=CANARY):
            with value:
                value.capture(ACTION)
                driver = value.open_mock('control')
                self.assertFalse(hasattr(driver, 'connect'))
        self.assertNotIn(CANARY, repr(value) + repr(value.candidate) + json.dumps(value.report()))
        self.assert_disposed(value)

    def test_existing_bootstrap_unchanged_with_adapter_mock_both_series(self):
        for series in ('8.0', '8.4'):
            value = adapter()
            with value:
                value.capture(ACTION)
                factory = lambda role,cfg,budget: MockChannel(value,role,cfg,budget)
                with patch.object(r,'load',return_value=config(series)), patch.object(r,'Channel',side_effect=factory):
                    bootstrap = r.Bootstrap()
                    result = bootstrap.run()
                    traces = bootstrap.private_trace()
            self.assertEqual(result['phase1'], 'ISOLATED_PHASE1_VERIFIED')
            self.assertEqual(sum(len(t['sql']) for t in traces), 15)
            self.assertEqual(len(traces), 2)
            self.assertTrue(all(t['quit_written'] for t in traces))
            self.assertTrue(result['data_connection_absent'] and result['query_ended'])
            self.assertFalse(result['execution_allowed'])
            self.assert_disposed(value)

    def test_bootstrap_remote_absence_unknown_and_interrupt_do_not_upgrade(self):
        original_read = MockChannel.read
        for fault in ('unobservable', 'interrupted'):
            value = adapter()
            def failed_read(channel, sql, cap=1):
                if fault == 'interrupted' and channel.gate.used == 3:
                    raise KeyboardInterrupt(CANARY)
                raw = original_read(channel, sql, cap)
                if fault == 'unobservable' and sql.startswith(PROCESS) and channel.gate.owner.closed:
                    return f'{channel.gate.owner.cid}\t{a.USER}\t{a.DB}\tSleep\n'
                return raw
            with value:
                value.capture(ACTION)
                factory = lambda role,cfg,budget: MockChannel(value,role,cfg,budget)
                with patch.object(r,'load',return_value=config('8.4')), \
                        patch.object(r,'Channel',side_effect=factory), \
                        patch.object(MockChannel,'read',failed_read):
                    bootstrap = r.Bootstrap()
                    result = bootstrap.run()
            self.assertEqual(result['phase1'], 'BLOCK')
            self.assertEqual(result['remote_termination'], 'REMOTE_TERMINATION_UNVERIFIED')
            self.assertFalse(result['data_connection_absent'] or result['query_ended'])
            self.assert_disposed(value)

    def test_gate_change_extra_sql_and_closed_driver_rejected(self):
        for change in ('sql','q0','deadline','closed'):
            value = adapter()
            with value:
                value.capture(ACTION)
                driver = value.open_mock('control')
                if change=='q0': driver.gate.q0 = b'SELECT 1;'
                if change=='deadline': driver.gate.deadline += 1
                if change=='closed': driver.close()
                sql = b'SELECT * FROM users;' if change=='sql' else INIT
                with self.assertRaises(a.Block): driver.claim_mock(sql)
                with self.assertRaises(a.Block): driver.claim_mock(INIT)
                with self.assertRaises(a.Block): value.open_mock('data')
            self.assert_disposed(value)

    def test_new_adapter_has_no_live_io_cli_or_configuration_loader(self):
        source = (ROOT/'scripts/ci/e858_phase1/r1_adapter.py').read_text()
        tree = ast.parse(source)
        forbidden_modules = {'socket','ssl','subprocess','argparse','getpass','pymysql','runtime'}
        for node in ast.walk(tree):
            if isinstance(node, ast.Import):
                self.assertTrue(forbidden_modules.isdisjoint(alias.name for alias in node.names))
            if isinstance(node, ast.ImportFrom):
                self.assertNotIn(node.module, forbidden_modules)
            if isinstance(node, ast.Call):
                self.assertNotIn(getattr(node.func,'id',getattr(node.func,'attr','')),
                                 {'connect','open','getenv','input','getpass','system','popen','exec','eval'})
        self.assertNotIn('r1_adapter', (ROOT/'scripts/ci/e858_phase1/runtime.py').read_text())
        self.assertNotIn('r1_adapter', (ROOT/'scripts/ci/e858_phase1_host.py').read_text())


if __name__ == '__main__': unittest.main()
