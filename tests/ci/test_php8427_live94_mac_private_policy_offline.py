"""Offline-only operator target review: no sockets, SQL, credentials or mysql."""
from pathlib import Path
import json
import os
import subprocess
import sys
import tempfile
import unittest
from unittest import mock

sys.path.insert(0, str(Path(__file__).resolve().parents[2]))
from scripts.ops import php8427_live94_mac_private_policy_offline as m
from scripts.ci.php8427_live94_offline_source_gate import Block

PACKET = (
    b"SELECT 'CI_BEGIN';\nSELECT 'CI_GRANTS_BEGIN';\n"
    b"SELECT 'CI_GRANTS_END';\n" +
    b"".join(b"SELECT '" + str(x).encode() + b"';\n" for x in range(7)) +
    b"SELECT 'CI_END', @@GLOBAL.mandatory_roles;\n"
)


class OperatorOfflinePrivatePolicyTests(unittest.TestCase):
    def setUp(self):
        root = tempfile.TemporaryDirectory()
        self.addCleanup(root.cleanup)
        self.root = Path(root.name)
        self.old = self.root / "old-live-source"
        self.old.mkdir(mode=0o700)
        self.private = self.root / "private"
        self.private.mkdir(mode=0o700)
        self.policy_file = self.private / "policy.json"
        self.ca_file = self.private / "ca.pem"
        r = subprocess.run(
            ["openssl", "req", "-x509", "-newkey", "rsa:2048",
             "-sha256", "-nodes", "-days", "1",
             "-subj", "/CN=ephemeral-fixture-only-not-provider",
             "-addext", "basicConstraints=critical,CA:TRUE",
             "-keyout", str(self.private / "key.pem"),
             "-out", str(self.ca_file)],
            stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
            timeout=8, check=True,
        )
        del r
        self.ca_file.chmod(0o600)
        self.data = {
            "protocol": "MYSQL_TCP",
            "service_host": "mysql-disposable.example.invalid",
            "service_port": 25000,
            "database": "live94audit",
            "reader_user": "reader_live94",
            "ca_file": str(self.ca_file),
            "tls_mode": "VERIFY_IDENTITY",
            "query_scope": "OLD_LIVE94_PINNED_7_SELECTS",
            "credential_handling": "SEPARATE_SECURITY_DESIGN_REQUIRED",
            "execution": "OFFLINE_REVIEW_ONLY",
        }
        self.save()

    def save(self):
        self.policy_file.write_text(json.dumps(self.data), encoding="utf-8")
        self.policy_file.chmod(0o600)

    def inspect(self, builder=None):
        return m.inspect_private_policy(
            self.old, self.policy_file,
            packet_builder=(lambda _: PACKET) if builder is None else builder,
        )

    def assert_hold(self, report):
        self.assertEqual(report["verdict"], "HOLD")
        self.assertFalse(report["mysql_invoked"])
        self.assertFalse(report["dns_or_network_attempted"])
        self.assertFalse(report["credentials_read"])
        for key, value in m.ALLOWED_FALSE.items():
            self.assertIs(report[key], value)
        self.assertNotIn(self.data["service_host"], json.dumps(report))
        self.assertNotIn(str(self.private), json.dumps(report))

    def test_clean_local_ca_and_policy_returns_hard_hold(self):
        r = self.inspect()
        self.assert_hold(r)
        self.assertTrue(r["private_ca_pem_loadable"])
        self.assertTrue(r["frozen_seven_queries_checked"])
        self.assertTrue(r["safe_client_flags_reviewed_no_execute"])
        self.assertEqual(
            r["code"],
            "OFFLINE_POLICY_AND_PINNED_SQL_REVIEWED_PRIVATE_OWNER_ATTESTATION_REQUIRED",
        )

    def test_no_secret_keys_extra_fields_or_duplicate_json_keys(self):
        for forbidden in ("password", "token", "owner_go", "aiven_secret", "sql"):
            with self.subTest(field=forbidden):
                self.data[forbidden] = "DO_NOT_ECHO_THIS"
                self.save()
                with self.assertRaises(Block):
                    self.inspect()
                self.data.pop(forbidden)
        self.save()
        raw = self.policy_file.read_text()
        self.policy_file.write_text(raw[:-1] + ',"service_host":"override.invalid"}')
        with self.assertRaises(Block):
            self.inspect()

    def test_refuses_unsafe_host_user_db_port_tls_and_execution_flags(self):
        invalid = (
            ("service_host", "localhost"),
            ("service_host", "127.0.0.1"),
            ("service_host", "two hosts.invalid"),
            ("service_host", "-bad.example.org"),
            ("service_port", True),
            ("service_port", 0),
            ("service_port", 65536),
            ("database", "live;DROP"),
            ("reader_user", "avnadmin"),
            ("reader_user", "root"),
            ("reader_user", "qa audit"),
            ("tls_mode", "REQUIRED"),
            ("tls_mode", "VERIFY_CA"),
            ("protocol", "MYSQL_SOCKET"),
            ("execution", "GO_LIVE"),
            ("credential_handling", "MYSQL_PWD"),
            ("query_scope", "ANY_QUERY"),
        )
        for key, value in invalid:
            with self.subTest(key=key, value=value):
                original = self.data[key]
                self.data[key] = value
                self.save()
                with self.assertRaises(Block):
                    self.inspect()
                self.data[key] = original

    def test_policy_symlink_writable_or_inside_repo_denied(self):
        self.policy_file.chmod(0o644)
        with self.assertRaises(Block):
            self.inspect()
        self.policy_file.chmod(0o600)
        alias = self.private / "alias.json"
        alias.symlink_to(self.policy_file)
        with self.assertRaises(Block):
            m.inspect_private_policy(self.old, alias, packet_builder=lambda _: PACKET)
        inside = self.old / "policy.json"
        inside.write_text(self.policy_file.read_text())
        inside.chmod(0o600)
        with self.assertRaises(Block):
            m.inspect_private_policy(self.old, inside, packet_builder=lambda _: PACKET)

    def test_ca_invalid_pem_symlink_or_path_drift_denied(self):
        ca_copy = self.ca_file.read_bytes()
        self.ca_file.write_bytes(b"Not a CA trust store")
        with self.assertRaises(Block):
            self.inspect()
        self.ca_file.write_bytes(ca_copy)
        self.ca_file.chmod(0o666)
        with self.assertRaises(Block):
            self.inspect()
        self.ca_file.chmod(0o600)
        alias = self.private / "ca-alias.pem"
        alias.symlink_to(self.ca_file)
        self.data["ca_file"] = str(alias)
        self.save()
        with self.assertRaises(Block):
            self.inspect()

    def test_sql_packet_missing_or_unpinned_rejected(self):
        for packet in (b"", b"SELECT 1;", PACKET[:-1], PACKET + b"SELECT 'extra';"):
            with self.subTest(length=len(packet)):
                with self.assertRaises(Block):
                    self.inspect(builder=lambda _: packet)
        with self.assertRaises(Block):
            self.inspect(builder=lambda _: (_ for _ in ()).throw(Block()))

    def test_unexecuted_options_have_no_password_or_downgrade(self):
        args = m.review_unexecuted_cli(self.data)
        self.assertEqual(args[:3], ("mysql", "--no-defaults", "--no-login-paths"))
        self.assertIn("--ssl-mode=VERIFY_IDENTITY", args)
        self.assertIn("--skip-reconnect", args)
        self.assertIn("--local-infile=0", args)
        self.assertIn("--host=" + self.data["service_host"], args)
        self.assertIn("--database=live94audit", args)
        self.assertTrue(all("--password" not in x and "--login-path=" not in x for x in args))
        self.assertNotIn("--ssl-mode=REQUIRED", args)

    def test_actual_linux_cli_fails_without_inspecting_private_policy(self):
        script = Path(m.__file__).resolve()
        with mock.patch.object(m, "inspect_private_policy") as inspect:
            env = {"PATH": os.defpath, "GITHUB_ACTIONS": "true"}
            p = subprocess.run(
                [sys.executable, "-I", "-B", str(script),
                 "--checkout", str(self.old),
                 "--private-policy", str(self.policy_file)],
                env=env, capture_output=True, text=True, timeout=8,
            )
            self.assertEqual(p.returncode, 2)
            r = json.loads(p.stdout)
            self.assert_hold(r)
            self.assertEqual(r["code"], "OFFLINE_MAC_POLICY_OR_FROZEN_SQL_REJECTED")
            inspect.assert_not_called()
            self.assertEqual(p.stderr, "")


if __name__ == "__main__":
    unittest.main()
