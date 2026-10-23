"""Fail-closed Mac operator offline preflight. Never invoke mysql/Aiven."""
import json
import os
from pathlib import Path
import stat
import subprocess
import sys
import tempfile
import unittest

sys.path.insert(0, str(Path(__file__).resolve().parents[2]))

from scripts.ops.php8427_live94_mac_offline_preflight import (
    BINARY_NAMES, NEVER_APPROVED, binary_is_locally_restricted, preflight,
)
from scripts.ci.php8427_live94_offline_source_gate import Block


class MacOfflinePreflightTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.source = self.root / "old-live94"
        self.source.mkdir()
        for name in BINARY_NAMES:
            path = self.root / name
            path.write_bytes(b"synthetic ordinary local binary")
            path.chmod(0o700)
        self.seen = []

    def safe(self, **kwargs):
        defaults = dict(
            checkout=self.source, environ={}, platform_name="darwin",
            python_isolated=True, python_no_bytecode=True,
            executable_lookup=lambda name: str(self.root / name),
            source_checker=lambda root: self.seen.append(root),
            effective_uid=os.getuid(),
        )
        defaults.update(kwargs)
        return preflight(**defaults)

    def assert_hold(self, actual):
        self.assertEqual(actual["verdict"], "HOLD")
        self.assertFalse(actual["mysql_invoked"])
        self.assertFalse(actual["network_access_attempted"])
        self.assertFalse(actual["credential_material_read"])
        self.assertFalse(actual["owner_connection_go"])
        self.assertFalse(actual["production_go"])
        for name, value in NEVER_APPROVED.items():
            self.assertIs(actual[name], value)

    def test_mocked_mac_exact_source_never_becomes_live_go(self):
        r = self.safe()
        self.assert_hold(r)
        self.assertTrue(r["source_exact"])
        self.assertTrue(r["mac_platform"])
        self.assertTrue(r["local_binary_permissions_sane"])
        self.assertFalse(r["binary_provenance_authenticated"])
        self.assertEqual(len(self.seen), 1)
        self.assertEqual(r["expected_migration_source_count"], 94)
        self.assertNotIn(str(self.root), json.dumps(r))

    def test_linux_and_ci_cannot_claim_mac_operator(self):
        for case in (
            {"platform_name": "linux"},
            {"environ": {"GITHUB_ACTIONS": "true"}},
        ):
            with self.subTest(case=case):
                self.seen.clear()
                r = self.safe(**case)
                self.assert_hold(r)
                self.assertFalse(r["source_exact"])
                self.assertEqual(r["code"], "NOT_PRIVATE_OPERATOR_MAC")
                self.assertEqual(self.seen, [])

    def test_requires_isolated_no_bytecode_absolute_source(self):
        for case in (
            {"python_isolated": False},
            {"python_no_bytecode": False},
            {"checkout": Path("relative-checkout")},
        ):
            with self.subTest(case=case):
                r = self.safe(**case)
                self.assert_hold(r)
                self.assertFalse(r["source_exact"])
                self.assertEqual(r["code"], "ISOLATED_PYTHON_OR_ABSOLUTE_CHECKOUT_REQUIRED")

    def test_denies_ambient_secrets_and_fake_owner_approval(self):
        for name in (
            "MYSQL_PWD", "MYSQL_TEST_LOGIN_FILE", "MYSQL_HOST",
            "DB_PASSWORD", "DATABASE_URL", "CANOVIA_P0_LOGIN_PATH",
            "CANOVIA_P0_OPERATOR_APPROVED", "CANOVIA_P0_CA_FILE",
            "CANOVIA_APPROVED_MIGRATION_SHA",
        ):
            with self.subTest(name=name):
                r = self.safe(environ={name: "DO_NOT_ECHO_SECRET_CANARY"})
                self.assert_hold(r)
                self.assertFalse(r["source_exact"])
                self.assertEqual(r["code"], "AMBIENT_CREDENTIAL_OR_RELEASE_OVERRIDE_PRESENT")
                self.assertNotIn("DO_NOT_ECHO_SECRET_CANARY", json.dumps(r))

    def test_rejects_synthetic_ci_flags_and_missing_mysql(self):
        r = self.safe(environ={"CANOVIA_LIVE94_DISPOSABLE_CI": "1"})
        self.assertEqual(r["code"], "SYNTHETIC_CI_CONTEXT_NOT_AN_OPERATOR")
        r = self.safe(executable_lookup=lambda name: None if name == "mysql" else str(self.root / name))
        self.assert_hold(r)
        self.assertEqual(r["code"], "BINARY_MISSING_OR_UNSAFE_LOCAL_PERMISSIONS")

    def test_rejects_writable_binary_and_symlink_to_untrusted_target(self):
        file = self.root / "mysql"
        file.chmod(0o777)
        self.assertFalse(binary_is_locally_restricted(str(file), os.getuid()))
        r = self.safe()
        self.assertFalse(r["source_exact"])
        self.assertEqual(r["code"], "BINARY_MISSING_OR_UNSAFE_LOCAL_PERMISSIONS")
        file.chmod(0o700)
        alias = self.root / "mysql-alias"
        alias.symlink_to(file)
        self.assertTrue(binary_is_locally_restricted(str(alias), os.getuid()))

    def test_unpinned_git_checkout_and_source_exception_always_hold(self):
        for err in (Block(), OSError("DO_NOT_LEAK"), RuntimeError("DO_NOT_LEAK")):
            def deny(_source):
                raise err
            r = self.safe(source_checker=deny)
            self.assert_hold(r)
            self.assertFalse(r["source_exact"])
            self.assertEqual(r["code"], "SOURCE_PIN_OR_LOCAL_EXECUTABLE_CHECK_FAILED")
            self.assertNotIn("DO_NOT_LEAK", json.dumps(r))

    def test_actual_cli_on_linux_rejects_without_loading_credentials(self):
        script = Path(__file__).resolve().parents[2] / "scripts/ops/php8427_live94_mac_offline_preflight.py"
        env = {"PATH": os.defpath, "GITHUB_ACTIONS": "true"}
        r = subprocess.run(
            [sys.executable, "-I", "-B", str(script), "--checkout", str(self.source)],
            env=env, capture_output=True, text=True, timeout=8,
        )
        self.assertEqual(r.returncode, 2)
        response = json.loads(r.stdout)
        self.assert_hold(response)
        self.assertFalse(response["source_exact"])
        self.assertNotIn(str(self.source), r.stdout)


if __name__ == "__main__":
    unittest.main()
