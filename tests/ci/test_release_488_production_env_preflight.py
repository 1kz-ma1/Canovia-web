"""Synthetic-only security tests for the secretless Release #488 operator checker."""
import base64
import json
from pathlib import Path
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[2]
SCRIPT = ROOT / "scripts/ops/release_488_production_env_preflight.php"
SECRET = "SYNTHETIC_NEVER_PRINT_THIS_TOKEN_3489"


def safe_env():
    return {
        "CANOVIA_PRODUCTION_ENV_AUDIT_APPROVED": "YES",
        "APP_ENV": "production",
        "APP_DEBUG": "false",
        "APP_KEY": "base64:" + base64.b64encode(b"a" * 32).decode(),
        "APP_URL": "https://app.canovia.com",
        "DB_CONNECTION": "mysql",
        "DB_HOST": "mysql-synthetic-ci.aivencloud.com",
        "DB_PORT": "28336",
        "DB_DATABASE": "pacekeeper",
        "DB_USERNAME": "app_synthetic",
        "DB_PASSWORD": SECRET,
        "SESSION_DRIVER": "database",
        "SESSION_CONNECTION": "mysql",
        "SESSION_COOKIE": "pace-keeper-session",
        "CANOVIA_NATIVE_AI_DRIVER": "disabled",
    }


def audit(overrides=None, remove=()):
    env = safe_env()
    env.update(overrides or {})
    for name in remove:
        env.pop(name, None)
    run = subprocess.run(["php", "-d", "display_errors=0", str(SCRIPT)],
                         env=env, capture_output=True, text=True, timeout=10)
    parsed = json.loads(run.stdout)
    return run, parsed


class ProductionEnvPreflightTests(unittest.TestCase):
    def test_good_shape_is_still_review_not_release(self):
        run, result = audit()
        self.assertEqual(0, run.returncode)
        self.assertEqual("REVIEW_REQUIRED", result["result"])
        self.assertEqual(["CONFIGURATION_SHAPE_MATCH_NOT_RELEASE"], result["codes"])
        self.assertFalse(result["release_authorized"])
        self.assertFalse(result["backup_restore_verified"])
        self.assertFalse(result["production_identity_verified"])
        self.assertFalse(result["production_database_modified"])
        self.assertNotIn(SECRET, run.stdout + run.stderr)

    def test_operator_opt_in_and_ci_refusal(self):
        for changes, removed in [
            ({}, ("CANOVIA_PRODUCTION_ENV_AUDIT_APPROVED",)),
            ({"CANOVIA_PRODUCTION_ENV_AUDIT_APPROVED": "NO"}, ()),
            ({"GITHUB_ACTIONS": "true"}, ()),
        ]:
            with self.subTest(changes=changes, removed=removed):
                run, result = audit(changes, removed)
                self.assertEqual(2, run.returncode)
                self.assertIn("OPERATOR_GUARD_NOT_SATISFIED", result["codes"])
                self.assertNotIn(SECRET, run.stdout + run.stderr)

    def test_prod_session_and_mysql_misconfig_fail_closed(self):
        for changes in [
            {"APP_ENV": "staging"},
            {"APP_DEBUG": "true"},
            {"APP_DEBUG": "not-a-boolean"},
            {"APP_KEY": "base64:invalid"},
            {"DB_CONNECTION": "pgsql"},
            {"DB_DATABASE": "defaultdb"},
            {"DB_HOST": "localhost"},
            {"DB_PORT": "not-a-port"},
            {"DB_PASSWORD": ""},
            {"DB_SOCKET": "/tmp/local.sock"},
            {"SESSION_DRIVER": "file"},
            {"SESSION_CONNECTION": "default"},
            {"SESSION_SECURE_COOKIE": "false"},
            {"SESSION_HTTP_ONLY": "false"},
            {"SESSION_COOKIE": "canovia_mcp_staging_session"},
            {"SESSION_DOMAIN": "mcp-staging.onrender.com"},
            {"APP_URL": "https://canovia-mcp-staging.onrender.com"},
            {"CANOVIA_STAGING_ISOLATED": "true"},
        ]:
            with self.subTest(changes=tuple(changes)):
                run, result = audit(changes)
                self.assertEqual(2, run.returncode)
                self.assertEqual("BLOCK", result["result"])
                self.assertFalse(result["release_authorized"])
                self.assertNotIn(SECRET, run.stdout + run.stderr)

    def test_prod_mcp_flags_cannot_be_enabled_silently(self):
        for flag in [
            "CANOVIA_MCP_DISCOVERY_ENABLED", "CANOVIA_MCP_TOOLS_ENABLED",
            "CANOVIA_MCP_PLAN_CONSENT_ENABLED", "CANOVIA_MCP_ACCOUNT_LINK_ENABLED",
            "CANOVIA_MCP_DELEGATED_POLICY_ENABLED",
            "CANOVIA_MCP_TOKEN_INTROSPECTION_ENABLED",
            "CANOVIA_MCP_AUTH0_JWT_VERIFICATION_ENABLED",
        ]:
            for value in ["true", "wrong"]:
                with self.subTest(flag=flag, value=value):
                    run, result = audit({flag: value})
                    self.assertEqual(2, run.returncode)
                    self.assertIn("UNAPPROVED_MCP_FLAG_OR_INVALID_BOOLEAN", result["codes"])
                    self.assertNotIn(flag, run.stdout)

    def test_database_url_precedence_without_leaking_password(self):
        url = "mysql://app:" + SECRET + "@mysql-synthetic-ci.aivencloud.com:28336/pacekeeper"
        run, result = audit({"DB_URL": url, "DB_DATABASE": "defaultdb"})
        self.assertEqual(0, run.returncode)
        self.assertEqual(["DATABASE_URL_OVERRIDES_INDIVIDUAL_SETTINGS"], result["codes"])
        self.assertNotIn(SECRET, run.stdout + run.stderr)
        self.assertNotIn(url, run.stdout + run.stderr)

        run, result = audit({"DB_URL": "mysql://user:" + SECRET + "@staging.invalid/defaultdb"})
        self.assertEqual(2, run.returncode)
        self.assertIn("DATABASE_URL_TARGET_UNVERIFIED", result["codes"])
        self.assertNotIn(SECRET, run.stdout + run.stderr)

    def test_staging_only_keys_warn_but_do_not_reveal(self):
        run, result = audit({
            "CANOVIA_STAGING_DB_MODE": "render_postgres",
            "CANOVIA_STAGING_POSTGRES_ID": SECRET,
            "CANOVIA_STAGING_POSTGRES_USER": SECRET,
        })
        self.assertEqual(0, run.returncode)
        self.assertIn("STAGING_ONLY_KEYS_PRESENT", result["codes"])
        self.assertNotIn(SECRET, run.stdout + run.stderr)

    def test_native_ai_requires_cost_review_and_key_presence(self):
        run, result = audit({"CANOVIA_NATIVE_AI_DRIVER": "openai"})
        self.assertEqual(2, run.returncode)
        self.assertIn("NATIVE_AI_KEY_NOT_AVAILABLE", result["codes"])
        run, result = audit({
            "CANOVIA_NATIVE_AI_DRIVER": "openai", "OPENAI_API_KEY": SECRET,
        })
        self.assertEqual(0, run.returncode)
        self.assertEqual(["NATIVE_AI_ENABLED_COST_AND_AUTH_REVIEW"], result["codes"])
        self.assertNotIn(SECRET, run.stdout + run.stderr)

    def test_redis_session_and_missing_app_url_are_review_items(self):
        run, result = audit(
            {"SESSION_DRIVER": "redis", "SESSION_CONNECTION": "default"},
            remove=("APP_URL",),
        )
        self.assertEqual(0, run.returncode)
        self.assertIn("REDIS_SESSION_TARGET_NEEDS_OPERATOR_REVIEW", result["codes"])
        self.assertIn("PUBLIC_APP_URL_NOT_EXPLICIT", result["codes"])

    def test_source_never_boots_application_or_runs_commands(self):
        source = SCRIPT.read_text()
        self.assertNotIn("bootstrap/app.php", source)
        self.assertNotIn("DB::", source)
        for bad in ["shell_exec(", "exec(", "system(", "file_put_contents(", "var_dump(", "print_r(", "getenv()"]:
            self.assertNotIn(bad, source)


if __name__ == "__main__":
    unittest.main()
