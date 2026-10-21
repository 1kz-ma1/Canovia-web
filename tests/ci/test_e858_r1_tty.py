"""Synthetic secrets and real local PTYs only. No password prompt or network."""
import contextlib
import importlib.util
import io
import os
from pathlib import Path
import pty
import select
import signal
import termios
import threading
import time
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[2]
SOURCE = ROOT / 'scripts/ci/e858_phase1/r1_tty.py'
spec = importlib.util.spec_from_file_location('r1_tty', SOURCE)
r1 = importlib.util.module_from_spec(spec)
spec.loader.exec_module(r1)
CANARY = b'SYNTHETIC_R1_ONLY_8c7ad4'


class PrivateTTYTests(unittest.TestCase):
    def setUp(self):
        self.master, self.slave = pty.openpty()
        self.original = termios.tcgetattr(self.slave)
        self.attempt = r1.PrivateTTYAttempt()
        self.addCleanup(os.close, self.master)
        self.addCleanup(os.close, self.slave)
        self.addCleanup(self.attempt.discard)

    def assert_restored(self):
        self.assertEqual(termios.tcgetattr(self.slave), self.original)
        self.assertTrue(os.isatty(self.slave))  # caller descriptor stays open

    def capture_line(self, data=CANARY + b'\n'):
        writer_error = []
        def send_synthetic_input():
            deadline = time.monotonic() + 3
            while termios.tcgetattr(self.slave)[3] & (termios.ECHO | termios.ECHONL):
                if time.monotonic() > deadline:
                    writer_error.append('synthetic terminal did not enter hidden mode')
                    return
                time.sleep(.001)
            # Small writes also avoid depending on a platform PTY queue size.
            for position in range(0, len(data), 64):
                os.write(self.master, data[position:position + 64])
        writer = threading.Thread(target=send_synthetic_input, daemon=True)
        writer.start()
        with patch.object(r1, 'INPUT_TIMEOUT_SECONDS', 4):
            result = self.attempt.capture(self.slave)
        writer.join(4)
        self.assertFalse(writer.is_alive())
        self.assertEqual(writer_error, [])
        self.assert_restored()
        return result

    def test_pty_echo_off_and_no_secret_in_output_or_result(self):
        out, err = io.StringIO(), io.StringIO()
        with contextlib.redirect_stdout(out), contextlib.redirect_stderr(err):
            result = self.capture_line()
        self.assertEqual(result, 'R1_CAPTURED_OFFLINE')
        self.assertEqual(self.attempt._password, CANARY)
        self.assertEqual(out.getvalue() + err.getvalue(), '')
        self.assertNotIn(CANARY.decode(), repr(self.attempt) + str(self.attempt) + result)
        self.assertFalse(select.select([self.master], [], [], .02)[0])
        self.attempt.discard()
        self.assertEqual(self.attempt._password, b'')

    def test_non_tty_consumes_attempt_without_stdin_fallback(self):
        read_fd, write_fd = os.pipe()
        try:
            with patch.object(r1.os, 'read', side_effect=AssertionError('must not read')):
                self.assertEqual(self.attempt.capture(read_fd), 'R1_NON_TTY')
                self.assertEqual(self.attempt.capture(self.slave), 'R1_RETRY_REFUSED')
        finally:
            os.close(read_fd)
            os.close(write_fd)
        self.assert_restored()

    def test_second_input_refused(self):
        self.assertEqual(self.capture_line(), 'R1_CAPTURED_OFFLINE')
        with patch.object(r1.os, 'dup', side_effect=AssertionError('must not open')):
            self.assertEqual(self.attempt.capture(self.slave), 'R1_RETRY_REFUSED')

    def test_empty_input(self):
        self.assertEqual(self.capture_line(b'\n'), 'R1_EMPTY')
        self.assertEqual(self.attempt._password, b'')
        self.assertEqual(self.attempt.capture(self.slave), 'R1_RETRY_REFUSED')

    def test_exact_maximum(self):
        self.assertEqual(self.capture_line(b'x' * r1.MAX_PASSWORD_BYTES + b'\n'), 'R1_CAPTURED_OFFLINE')

    def test_oversize_is_rejected_and_suffix_flushed(self):
        self.assertEqual(self.capture_line(b'x' * (r1.MAX_PASSWORD_BYTES + 10) + b'\n'), 'R1_OVERSIZE')
        self.assertEqual(self.attempt._password, b'')
        self.assertFalse(select.select([self.slave], [], [], .02)[0])
        self.assertFalse(select.select([self.master], [], [], .02)[0])

    def test_eof(self):
        self.assertEqual(self.capture_line(b'\x04'), 'R1_EOF')
        self.assertEqual(self.attempt._password, b'')

    def test_eof_after_text_is_not_accepted(self):
        self.assertEqual(self.capture_line(CANARY + b'\x04'), 'R1_EOF')
        self.assertEqual(self.attempt._password, b'')

    def test_control_character(self):
        self.assertEqual(self.capture_line(CANARY + b'\x00\n'), 'R1_INPUT_BLOCKED')

    def test_read_error_and_interrupt_restore_and_hide_exception(self):
        for exception in (OSError(CANARY.decode()), KeyboardInterrupt(CANARY.decode())):
            with self.subTest(kind=type(exception).__name__):
                attempt = r1.PrivateTTYAttempt()
                with patch.object(r1.select, 'select', return_value=([self.slave], [], [])), \
                        patch.object(r1.os, 'read', side_effect=exception):
                    result = attempt.capture(self.slave)
                self.assertIn(result, ('R1_INPUT_BLOCKED', 'R1_INTERRUPTED'))
                self.assertNotIn(CANARY.decode(), result + repr(attempt))
                self.assertEqual(attempt._password, b'')
                self.assertEqual(attempt.capture(self.slave), 'R1_RETRY_REFUSED')
                self.assert_restored()

    def test_actual_sigint_restores_terminal_without_traceback(self):
        out, err = io.StringIO(), io.StringIO()
        def interrupt(fd, size):
            self.assertFalse(termios.tcgetattr(self.slave)[3] & termios.ECHO)
            os.kill(os.getpid(), signal.SIGINT)
        with contextlib.redirect_stdout(out), contextlib.redirect_stderr(err), \
                patch.object(r1.select, 'select', return_value=([self.slave], [], [])), \
                patch.object(r1.os, 'read', side_effect=interrupt):
            self.assertEqual(self.attempt.capture(self.slave), 'R1_INTERRUPTED')
        self.assertEqual(out.getvalue() + err.getvalue(), '')
        self.assert_restored()
        self.assertEqual(self.attempt.capture(self.slave), 'R1_RETRY_REFUSED')

    def test_restore_readback_mismatch_is_not_success(self):
        real_get = termios.tcgetattr
        calls = []
        def changed_restore(fd):
            calls.append(1)
            attrs = real_get(fd)
            if len(calls) == 3:
                attrs[3] ^= termios.ECHO
            return attrs
        with patch.object(r1.termios, 'tcgetattr', side_effect=changed_restore), \
                patch.object(r1.select, 'select', return_value=([self.slave], [], [])), \
                patch.object(r1.os, 'read', side_effect=[bytes([value]) for value in CANARY + b'\n']):
            self.assertEqual(self.attempt.capture(self.slave), 'R1_TERMINAL_RESTORE_UNVERIFIED')
        self.assertEqual(self.attempt._password, b'')
        self.assert_restored()

    def test_duplicate_close_error_is_not_success(self):
        real_close = os.close
        def ambiguous_close(fd):
            real_close(fd)
            raise OSError(CANARY.decode())
        with patch.object(r1.os, 'close', side_effect=ambiguous_close), \
                patch.object(r1.select, 'select', return_value=([self.slave], [], [])), \
                patch.object(r1.os, 'read', side_effect=[bytes([value]) for value in CANARY + b'\n']):
            self.assertEqual(self.attempt.capture(self.slave), 'R1_TERMINAL_CLEANUP_UNVERIFIED')
        self.assertEqual(self.attempt._password, b'')
        self.assert_restored()

    def test_timeout_restores_and_refuses_retry(self):
        with patch.object(r1.select, 'select', return_value=([], [], [])):
            self.assertEqual(self.attempt.capture(self.slave), 'R1_INPUT_TIMEOUT')
        self.assert_restored()
        self.assertEqual(self.attempt.capture(self.slave), 'R1_RETRY_REFUSED')

    def test_setup_failure_even_after_partial_change_restores(self):
        real_set = termios.tcsetattr
        calls = []
        def partial_failure(fd, when, attrs):
            calls.append(1)
            real_set(fd, when, attrs)
            if len(calls) == 1:
                raise OSError(CANARY.decode())
        with patch.object(r1.termios, 'tcsetattr', side_effect=partial_failure), \
                patch.object(r1.os, 'read', side_effect=AssertionError('must not read')):
            self.assertEqual(self.attempt.capture(self.slave), 'R1_INPUT_BLOCKED')
        self.assertEqual(len(calls), 2)
        self.assert_restored()

    def test_echo_still_enabled_refuses_to_read(self):
        real_set = termios.tcsetattr
        def refuse_setup(fd, when, attrs):
            if attrs == self.original:
                real_set(fd, when, attrs)
        with patch.object(r1.termios, 'tcsetattr', side_effect=refuse_setup), \
                patch.object(r1.os, 'read', side_effect=AssertionError('must not read')):
            self.assertEqual(self.attempt.capture(self.slave), 'R1_TERMINAL_SETUP_BLOCKED')
        self.assert_restored()

    def test_restore_failure_discards_secret_and_refuses_retry(self):
        real_set = termios.tcsetattr
        def fail_restore(fd, when, attrs):
            if attrs == self.original:
                raise OSError(CANARY.decode())
            real_set(fd, when, attrs)
        try:
            with patch.object(r1.termios, 'tcsetattr', side_effect=fail_restore):
                # This failure intentionally cannot assert successful restoration.
                with patch.object(r1.select, 'select', return_value=([self.slave], [], [])), \
                        patch.object(r1.os, 'read', side_effect=[bytes([value]) for value in CANARY + b'\n']):
                    self.assertEqual(self.attempt.capture(self.slave), 'R1_TERMINAL_RESTORE_UNVERIFIED')
            self.assertEqual(self.attempt._password, b'')
            self.assertEqual(self.attempt.capture(self.slave), 'R1_RETRY_REFUSED')
        finally:
            real_set(self.slave, termios.TCSAFLUSH, self.original)
        self.assert_restored()

    def test_worker_thread_refused_before_terminal_change(self):
        result = []
        worker = threading.Thread(target=lambda: result.append(self.attempt.capture(self.slave)))
        worker.start()
        worker.join(2)
        self.assertEqual(result, ['R1_INPUT_BLOCKED'])
        self.assertEqual(self.attempt.capture(self.slave), 'R1_RETRY_REFUSED')
        self.assert_restored()

    def test_concurrent_input_refused(self):
        self.attempt._lock.acquire()
        try:
            self.assertEqual(self.attempt.capture(self.slave), 'R1_RETRY_REFUSED')
        finally:
            self.attempt._lock.release()

    def test_invalid_descriptor_and_initial_attributes_failure(self):
        for value in (-1, None, True, 0, 1, 2):
            with self.subTest(value=value):
                self.assertEqual(r1.PrivateTTYAttempt().capture(value), 'R1_INPUT_BLOCKED')
        with patch.object(r1.termios, 'tcgetattr', side_effect=OSError(CANARY.decode())):
            self.assertEqual(self.attempt.capture(self.slave), 'R1_INPUT_BLOCKED')
        self.assert_restored()

    def test_module_has_no_live_path_or_entry_point(self):
        import ast
        tree = ast.parse(SOURCE.read_text())
        imports = {node.names[0].name for node in ast.walk(tree) if isinstance(node, ast.Import)}
        self.assertEqual(imports, {'copy', 'os', 'select', 'termios', 'threading', 'time'})
        self.assertEqual([node for node in ast.walk(tree) if isinstance(node, ast.ImportFrom)], [])
        for path in (ROOT / 'scripts/ci/e858_phase1/runtime.py', ROOT / 'scripts/ci/e858_phase1_host.py'):
            self.assertNotIn('r1_tty', path.read_text())
        forbidden = {'open', 'getenv', 'getpass', 'input', 'system', 'popen', 'connect', 'exec', 'eval'}
        for node in ast.walk(tree):
            if isinstance(node, ast.Call):
                name = getattr(node.func, 'id', getattr(node.func, 'attr', ''))
                self.assertNotIn(name, forbidden)
        self.assertFalse(any(isinstance(node, ast.If) and '__name__' in ast.unparse(node.test)
                             for node in tree.body))


if __name__ == '__main__':
    unittest.main()
