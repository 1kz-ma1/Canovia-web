import os
import contextlib
import io
from pathlib import Path
import subprocess
import socket
import sys
import tempfile
import unittest
from unittest.mock import patch, Mock

from preparation_mac_lab import check_checkout, lab_env, stop_server, serve, main, SafetyError


class MacLabTest(unittest.TestCase):
    def test_environment_drops_real_settings_and_separates_profiles(self):
        with patch.dict(os.environ, {'DB_URL': 'real-secret', 'AWS_SECRET_ACCESS_KEY': 'real-secret',
                                    'CANOVIA_PUBLIC_RELEASE_LEVEL': '4', 'CANOVIA_PREPARATION_ONLY': 'true',
                                    'PHPRC': 'secret-config', 'HTTP_PROXY': 'real-secret'}, clear=True):
            for profile, port in [('preparation', 18803), ('original', 18804)]:
                env = lab_env(Path('/tmp/synthetic-only'), profile)
                self.assertNotIn('real-secret', str(env))
                self.assertNotIn('PHPRC', env)
                self.assertEqual(env['DB_CONNECTION'], 'sqlite')
                self.assertEqual(env['CANOVIA_PUBLIC_RELEASE_LEVEL'], '0')
                self.assertEqual(env['CANOVIA_PREPARATION_ONLY'], 'false')  # migrate first
                self.assertEqual(env['APP_URL'], f'http://127.0.0.1:{port}')
                self.assertEqual(env['LARAVEL_STORAGE_PATH'], '/tmp/synthetic-only/storage')

    def checkout(self, root):
        (root / 'vendor').mkdir()
        (root / 'vendor/autoload.php').touch()
        (root / 'public/build').mkdir(parents=True)
        (root / 'public/build/manifest.json').touch()
        (root / 'bootstrap/cache').mkdir(parents=True)

    def test_clean_checkout_and_unsafe_input_rejections(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory).resolve()
            self.checkout(root)
            with patch('preparation_mac_lab.git_value', side_effect=lambda p, *args: 'a' * 40 if args[0] == 'rev-parse' else ''):
                check_checkout(root, 'a' * 40)
                with self.assertRaises(SafetyError):
                    check_checkout(root, 'b' * 40)
                for name in ['.env', '.env.production', 'public/hot', 'bootstrap/cache/config.php', 'bootstrap/cache/routes-v7.php', 'public/storage']:
                    file = root / name
                    file.touch()
                    with self.subTest(name=name), self.assertRaises(SafetyError):
                        check_checkout(root, 'a' * 40)
                    file.unlink()
                (root / 'vendor/autoload.php').unlink()
                (root / 'vendor').rmdir()
                (root / 'vendor').symlink_to(root / 'public', target_is_directory=True)
                with self.assertRaises(SafetyError):
                    check_checkout(root, 'a' * 40)

    def test_dirty_checkout_rejected(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory).resolve()
            with patch('preparation_mac_lab.git_value', side_effect=['a' * 40, ' M app/file.php']):
                with self.assertRaises(SafetyError):
                    check_checkout(root, 'a' * 40)

    def test_cleanup_owns_only_direct_process_and_waits(self):
        process = Mock()
        process.poll.side_effect = [None, 0]
        stop_server(process)
        process.terminate.assert_called_once()
        process.wait.assert_called_once_with(timeout=5)
        process.kill.assert_not_called()

    def test_cleanup_timeout_escalates_only_owned_process(self):
        process = Mock()
        process.poll.side_effect = [None, -9]
        process.wait.side_effect = [subprocess.TimeoutExpired('fake', 5), -9]
        stop_server(process)
        process.kill.assert_called_once()

    def test_real_owned_process_is_reaped_on_linux_and_mac(self):
        process = subprocess.Popen([sys.executable, '-c', 'import time; time.sleep(60)'])
        try:
            stop_server(process)
            self.assertIsNotNone(process.poll())
        finally:
            if process.poll() is None:
                process.kill()
                process.wait(timeout=5)

    def test_occupied_port_refuses_without_touching_other_process(self):
        with socket.socket() as existing:
            existing.bind(('127.0.0.1', 0))
            port = existing.getsockname()[1]
            with patch('preparation_mac_lab.PORTS', {'preparation': port}), \
                 patch('preparation_mac_lab.check_checkout'), \
                 patch('preparation_mac_lab.subprocess.Popen') as popen:
                with self.assertRaises(SafetyError):
                    with serve('preparation', 'a' * 40):
                        self.fail('An occupied listener was reused')
                popen.assert_not_called()
            self.assertEqual(existing.getsockname()[1], port)

    def test_interrupt_does_not_report_cleanup_before_owned_process_is_confirmed(self):
        @contextlib.contextmanager
        def failed_cleanup(*args):
            yield {'APP_URL': 'http://127.0.0.1:18803'}, Mock()
            raise PermissionError('synthetic cleanup failure')
        output = io.StringIO()
        with patch.object(sys, 'argv', ['lab', '--source-sha', 'a' * 40]), \
             patch('preparation_mac_lab.serve', failed_cleanup), \
             patch('preparation_mac_lab.time.sleep', side_effect=KeyboardInterrupt), \
             contextlib.redirect_stdout(output):
            with self.assertRaises(PermissionError):
                main()
        self.assertNotIn('LAB_STOPPED_DISPOSABLE_DATA_DELETED', output.getvalue())

    def test_unknown_cleanup_is_not_success(self):
        process = Mock()
        process.poll.side_effect = [None, None]
        with self.assertRaises(SafetyError):
            stop_server(process)

    def test_permission_failure_is_not_ignored(self):
        process = Mock()
        process.poll.return_value = None
        process.terminate.side_effect = PermissionError('synthetic')
        with self.assertRaises(PermissionError):
            stop_server(process)


if __name__ == '__main__':
    unittest.main()
