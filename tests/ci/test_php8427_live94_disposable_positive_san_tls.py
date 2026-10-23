"""Negative tests for the disposable-only custom-SAN MySQL TLS scenario."""
import os
from pathlib import Path
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[2]
SCRIPT = ROOT / "scripts/ci/php8427_live94_disposable_positive_san_tls.sh"


class DisposablePositiveSANTest(unittest.TestCase):
    def test_script_syntax(self):
        r = subprocess.run(["bash", "-n", str(SCRIPT)], capture_output=True, timeout=5)
        self.assertEqual(r.returncode, 0, "Fixture Bash syntax must parse")

    def test_without_ci_attestation_no_mysql_or_docker_command(self):
        env = {"PATH": os.defpath, "GITHUB_ACTIONS": "false", "RUNNER_TEMP": "/tmp"}
        r = subprocess.run(["bash", str(SCRIPT)], env=env, capture_output=True, timeout=5)
        self.assertEqual(r.returncode, 2)
        self.assertIn(b"BLOCK_NONDISPOSABLE", r.stderr)
        self.assertNotIn(b"mysql:", r.stderr.lower())
        self.assertNotIn(b"docker:", r.stderr.lower())

    def test_exact_local_identity_source_and_hold_guards_exist(self):
        src = SCRIPT.read_text(encoding="utf-8")
        for required in (
            "canovia_live94_synth_ci",
            "127.0.0.1",
            "MYSQL_SERVICE_CONTAINER",
            "--ssl-mode=VERIFY_IDENTITY",
            "subjectAltName=DNS:localhost",
            "ALTER INSTANCE RELOAD TLS",
            "DISPOSABLE_VERIFY_IDENTITY_MATCHING_SAN=PASS",
            "DISPOSABLE_VERIFY_IDENTITY_BAD_HOST_AND_CA=DENIED",
            "DISPOSABLE_POSITIVE_HOST_VERIFIED_7_PINNED_SQL=PASS; SCHEMA=HOLD",
            "REAL_AIVEN_TLS=NOT_TESTED",
            "PRODUCTION_GO=NO",
            "live94-positive-san-observations.tsv",
        ):
            self.assertIn(required, src)
        self.assertNotIn("aiven.io", src.lower())
        self.assertNotIn("srv-dagfn2id0e5s73cac7ng", src)


if __name__ == "__main__":
    unittest.main()
