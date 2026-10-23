"""CI-only guard and safe documentation tests for disposable MySQL shell."""
import os
from pathlib import Path
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[2]
SCRIPT = ROOT / "scripts/ci/php8427_live94_disposable_tls_role_session.sh"


class DisposableTLSRoleSessionTest(unittest.TestCase):
    def test_bash_syntax_is_valid(self):
        result = subprocess.run(["bash", "-n", str(SCRIPT)], capture_output=True, timeout=5)
        self.assertEqual(result.returncode, 0, "Bash syntax rejected")

    def test_no_ci_environment_prevents_any_mysql_or_docker_execution(self):
        env = {"PATH": os.defpath, "GITHUB_ACTIONS": "false", "RUNNER_TEMP": "/tmp"}
        result = subprocess.run(["bash", str(SCRIPT)], env=env, capture_output=True, timeout=5)
        self.assertEqual(result.returncode, 2)
        self.assertIn(b"BLOCK_NONDISPOSABLE", result.stderr)
        self.assertNotIn(b"mysql:", result.stderr.lower())

    def test_synthetic_scope_and_finite_observer_limits_are_explicit(self):
        text = SCRIPT.read_text(encoding="utf-8")
        self.assertIn("canovia_live94_synth_ci", text)
        self.assertIn("127.0.0.1", text)
        self.assertIn("--ssl-mode=VERIFY_CA", text)
        self.assertIn("--ssl-mode=VERIFY_IDENTITY", text)
        self.assertIn("--ssl-mode=DISABLED", text)
        self.assertIn("CURRENT_ROLE()", text)
        self.assertIn("information_schema.PROCESSLIST", text)
        self.assertIn("timeout -k 2s 3s", text)
        self.assertIn("REAL_AIVEN_TLS_AND_REMOTE_END=UNVERIFIED", text)
        self.assertNotIn("aiven.io", text.lower())
        self.assertNotIn("render.com", text.lower())


if __name__ == "__main__":
    unittest.main()
