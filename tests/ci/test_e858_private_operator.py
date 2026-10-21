"""Inert owner-terminal preflight tests. Synthetic PTYs only; no input."""
import os
from pathlib import Path
import pty
import sys
import threading
import unittest
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[2] / 'scripts/ci'))
from e858_phase1 import private_operator as operator
from e858_phase1.private_binding import Block


class ForegroundTerminalObservation(unittest.TestCase):
    def setUp(self):
        self.master, self.slave = pty.openpty()
        self.addCleanup(os.close, self.slave)
        self.addCleanup(os.close, self.master)

    def observed(self):
        with patch.object(operator.sys, 'platform', 'darwin'), \
             patch.object(operator.os, 'tcgetpgrp', return_value=os.getpgrp()):
            return operator.observe_foreground_terminal(self.slave)

    def test_simulated_foreground_is_not_owner_authentication(self):
        result = self.observed()
        self.assertEqual(result['foreground_group'], 'OBSERVED_NOT_AUTHENTICATED')
        self.assertEqual(result['operator_identity'], 'UNVERIFIED')
        self.assertEqual(result['explicit_owner_gesture'], 'UNVERIFIED')
        self.assertEqual(result['ancestor_trust'], 'UNVERIFIED')
        self.assertFalse(result['execution_allowed'])
        self.assertFalse(result['real_credential_read'])
        self.assertFalse(result['live_tooling_adopted'])
        self.assertTrue(os.fstat(self.slave))  # Caller FD remains owned by caller.

    def test_platform_and_invalid_descriptor_are_blocked(self):
        with patch.object(operator.sys, 'platform', 'linux'):
            with self.assertRaises(Block):
                operator.observe_foreground_terminal(self.slave)
        for bad in (None, True, '3', -1, 0, 1, 2):
            with self.subTest(bad=bad):
                with patch.object(operator.sys, 'platform', 'darwin'):
                    with self.assertRaises(Block):
                        operator.observe_foreground_terminal(bad)

    def test_not_foreground_no_tty_or_inheritable_blocks(self):
        with patch.object(operator.sys, 'platform', 'darwin'):
            with patch.object(operator.os, 'tcgetpgrp', return_value=os.getpgrp()+1):
                with self.assertRaises(Block):
                    operator.observe_foreground_terminal(self.slave)
            with patch.object(operator.os, 'isatty', return_value=False):
                with self.assertRaises(Block):
                    operator.observe_foreground_terminal(self.slave)
            with patch.object(operator.os, 'get_inheritable', return_value=True):
                with self.assertRaises(Block):
                    operator.observe_foreground_terminal(self.slave)
            with patch.object(operator.os, 'geteuid', return_value=os.geteuid()+1):
                with self.assertRaises(Block):
                    operator.observe_foreground_terminal(self.slave)

    def test_worker_thread_refused_before_terminal_observation(self):
        result = []
        def observe():
            try:
                operator.observe_foreground_terminal(self.slave)
            except Block:
                result.append('BLOCK')
        with patch.object(operator.sys, 'platform', 'darwin'), \
             patch.object(operator.os, 'tcgetpgrp', side_effect=AssertionError('NO_TTY_CALL')):
            worker = threading.Thread(target=observe)
            worker.start()
            worker.join(2)
        self.assertEqual(result, ['BLOCK'])

    def test_fd_swap_or_session_change_blocked(self):
        actual = operator._identity
        count = [0]
        def identity(value):
            count[0] += 1
            result = actual(value)
            if count[0] == 2:
                return result[:-1] + (result[-1]+1,)
            return result
        with patch.object(operator, '_identity', side_effect=identity):
            with self.assertRaises(Block):
                self.observed()
        get_sid = os.getsid
        count = [0]
        def changed_sid(pid):
            count[0] += 1
            return get_sid(pid) + (1 if count[0] == 2 else 0)
        with patch.object(operator.os, 'getsid', side_effect=changed_sid):
            with self.assertRaises(Block):
                self.observed()

    def test_read_write_termios_mutation_and_secret_errors_are_prohibited(self):
        with patch.object(operator.os, 'read', side_effect=AssertionError('SECRET_CANARY')), \
             patch.object(operator.os, 'write', side_effect=AssertionError('SECRET_CANARY')), \
             patch.object(operator.termios, 'tcsetattr', side_effect=AssertionError('SECRET_CANARY')):
            result = self.observed()
        self.assertEqual(result['terminal_restoration'], 'NOT_APPLICABLE_NO_MUTATION')
        with patch.object(operator.sys, 'platform', 'darwin'), \
             patch.object(operator.os, 'tcgetpgrp', side_effect=OSError('SECRET_CANARY')):
            with self.assertRaises(Block) as caught:
                operator.observe_foreground_terminal(self.slave)
        self.assertNotIn('SECRET_CANARY', str(caught.exception))

    def test_no_live_entrypoint_or_implicit_authority(self):
        for name in ('connect', 'read_binding', 'capture_password', 'run', 'main'):
            self.assertFalse(hasattr(operator, name))
        self.assertEqual(self.observed()['real_credential_read'], False)


if __name__ == '__main__':
    unittest.main()
