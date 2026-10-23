"""Negative guarantees for the Live94 synthetic-only one-process executor."""
from pathlib import Path
import os
import stat
import sys
import tempfile
import unittest
from unittest import mock

sys.path.insert(0, str(Path(__file__).resolve().parents[2]))
from scripts.ci.php8427_live94_offline_source_gate import Block
from scripts.ci import php8427_live94_ci_single_session_executor as m


class SingleSessionExecutorTest(unittest.TestCase):
    def test_exact_cli_disallows_default_login_path_reconnect_write_and_downgrade(self):
        args = m.cli_arguments("/ci/mysql", Path("/tmp/synthetic/ca.pem"))
        self.assertEqual(args[0:3], ["/ci/mysql", "--no-defaults", "--no-login-paths"])
        self.assertIn("--host=localhost", args)
        self.assertIn("--port=3306", args)
        self.assertIn("--protocol=TCP", args)
        self.assertIn("--ssl-mode=VERIFY_IDENTITY", args)
        self.assertIn("--skip-reconnect", args)
        self.assertIn("--local-infile=0", args)
        self.assertIn("--skip-system-command", args)
        self.assertIn("--skip-named-commands", args)
        self.assertEqual(len([a for a in args if a.startswith("--database=")]), 1)
        self.assertEqual(len([a for a in args if a.startswith("--user=")]), 1)
        self.assertFalse(any(
            a.startswith(prefix) for a in args
            for prefix in ("--password", "--login-path=", "--execute", "-e", "--ssl-mode=REQUIRED")
        ))

    def test_non_ci_never_spawns_mysql_even_if_checkout_and_ca_exist(self):
        with mock.patch.dict(os.environ, {"GITHUB_ACTIONS": "false"}, clear=True):
            with mock.patch.object(m.subprocess, "Popen") as popen:
                with self.assertRaises(Block):
                    m.execute(Path("/tmp/no-db"), Path("/tmp/no-ca"))
                popen.assert_not_called()

    def test_bad_synthetic_service_and_fake_approval_denied(self):
        base = {
            "GITHUB_ACTIONS": "true", "CANOVIA_LIVE94_DISPOSABLE_CI": "1",
            "CANOVIA_P0_DISPOSABLE_MYSQL_CI": "1", "APP_ENV": "testing",
            "DB_CONNECTION": "mysql", "DB_HOST": "127.0.0.1", "DB_PORT": "3306",
            "DB_DATABASE": "canovia_live94_synth_ci", "DB_USERNAME": "canovia_ci",
            "DB_PASSWORD": "ci-only-ephemeral-password",
            "CANOVIA_P0_DISPOSABLE_ROOT_PASSWORD": "ci-only-root-password",
            "MYSQL_SERVICE_CONTAINER": "0123456789abcdef0123456789ab",
            "RUNNER_TEMP": "/tmp",
        }
        # This test does not connect, only checks a synthetic environment
        # acknowledgement. Changing any marker must reject.
        for name, value in (
            ("MYSQL_SERVICE_CONTAINER", "wrong"),
            ("DB_HOST", "any.production.example.invalid"),
            ("DB_DATABASE", "production_db"),
            ("DB_USERNAME", "production_reader"),
            ("GITHUB_ACTIONS", "false"),
            ("CANOVIA_P0_DISPOSABLE_ROOT_PASSWORD", "changed"),
            ("APP_ENV", "production"),
        ):
            with self.subTest(name=name):
                env = dict(base)
                env[name] = value
                with mock.patch.dict(os.environ, env, clear=True):
                    with self.assertRaises(Block):
                        m.synthetic_only_guard()
        for injected in (
            "MYSQL_TEST_LOGIN_FILE", "MYSQL_HOME", "DATABASE_URL",
            "CANOVIA_P0_CA_FILE", "CANOVIA_P0_OPERATOR_APPROVED",
        ):
            with self.subTest(injected=injected):
                with mock.patch.dict(os.environ, {**base, injected: "secret"}, clear=True):
                    with self.assertRaises(Block):
                        m.synthetic_only_guard()

    def test_ca_symlink_permission_scope_and_content_rejected(self):
        with tempfile.TemporaryDirectory() as td:
            root = Path(td)
            subdir = root / "nested"
            subdir.mkdir()
            ca = subdir / "ca.pem"
            ca.write_bytes(b"-----BEGIN CERTIFICATE-----\n" + b"a" * 330)
            ca.chmod(0o600)
            self.assertEqual(m.secure_ca(ca, root), ca)
            ca.chmod(0o666)
            with self.assertRaises(Block):
                m.secure_ca(ca, root)
            ca.chmod(0o600)
            alias = root / "alias.pem"
            alias.symlink_to(ca)
            with self.assertRaises(Block):
                m.secure_ca(alias, root)
            ca.write_bytes(b"BAD " + b"x" * 350)
            with self.assertRaises(Block):
                m.secure_ca(ca, root)

    def test_packet_exact_header_and_footer_and_number_of_queries(self):
        with mock.patch.object(m, "build_manifest", return_value={
            "sql_statement_count": 7,
            "real_aiven_connected": False,
        }):
            with mock.patch.object(m, "statements_from_frozen_bytes", side_effect=[
                (b"SELECT 'connection';", b"SELECT 'table';",
                 b"SELECT 'column';", b"SELECT 'foreign_key';",
                 b"SELECT 'index';", b"SELECT 'migration';"),
                (b"SELECT 'migration_ledger';",),
            ]):
                with mock.patch.object(Path, "read_bytes", return_value=b"synthetic") as read:
                    raw = m.immutable_packet(Path("/tmp/synthetic_checkout"))
        self.assertTrue(raw.startswith(m.SCRIPT_HEADER))
        self.assertTrue(raw.endswith(m.SCRIPT_FOOTER))
        self.assertEqual(raw.count(b"SELECT '"), 11)
        self.assertEqual(read.call_count, 2)
        self.assertNotIn(b"INTO OUTFILE", raw)
        self.assertNotIn(b"DROP TABLE", raw)

    def test_packet_rejects_unpinned_or_wrong_count(self):
        with mock.patch.object(m, "build_manifest", side_effect=Block()):
            with self.assertRaises(Block):
                m.immutable_packet(Path("/tmp/invalid"))
        with mock.patch.object(m, "build_manifest", return_value={
            "sql_statement_count": 8, "real_aiven_connected": False,
        }):
            with self.assertRaises(Block):
                m.immutable_packet(Path("/tmp/invalid"))

    def test_failed_cli_reports_only_public_block_and_no_credentials(self):
        script = Path(m.__file__).resolve()
        import subprocess
        r = subprocess.run(
            [sys.executable, "-I", "-B", str(script),
             "--checkout", "/tmp/secret-checkout-do-not-echo",
             "--ca", "/tmp/secret-ca-do-not-echo"],
            env={"PATH": os.defpath, "GITHUB_ACTIONS": "false"},
            capture_output=True, timeout=8, text=True,
        )
        self.assertEqual(r.returncode, 2)
        self.assertIn('"verdict": "BLOCK"', r.stdout)
        self.assertIn('"real_aiven_connected": false', r.stdout)
        self.assertNotIn("secret-checkout", r.stdout)
        self.assertNotIn("secret-ca", r.stdout)
        self.assertEqual(r.stderr, "")


if __name__ == "__main__":
    unittest.main()
