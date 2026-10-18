"""Synthetic-only tests: AP operator predeploy checker does not leak secrets."""
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import sys
import unittest

ROOT = Path(__file__).resolve().parents[2]
SCRIPT = ROOT / "scripts" / "ops" / "mcp_ap_live94_auth0_preflight.py"
spec = importlib.util.spec_from_file_location("mcp_ap_predeploy", SCRIPT)
checker = importlib.util.module_from_spec(spec)
spec.loader.exec_module(checker)
SECRET = "NEVER_OUTPUT_SYNTHETIC_PRIVATE_SECRET_492a"
FINGERPRINT = "NEVER_OUTPUT_SYNTHETIC_HMAC_KEY_991d"


def healthy():
    issuer = "https://fixture-prod.jp.auth0.com/"
    url = "https://fixture-canovia.example.com"
    return {
        "APP_ENV": "production", "APP_DEBUG": "false",
        "DB_CONNECTION": "mysql", "APP_URL": url,
        "CANOVIA_MCP_OAUTH_ISSUER": issuer,
        "CANOVIA_MCP_RESOURCE_URL": url + "/api/mcp",
        "CANOVIA_MCP_ACCOUNT_LINK_METADATA_URL":
            issuer + ".well-known/openid-configuration",
        "CANOVIA_MCP_ACCOUNT_LINK_AUTHORIZATION_ENDPOINT": issuer + "authorize",
        "CANOVIA_MCP_ACCOUNT_LINK_TOKEN_ENDPOINT": issuer + "oauth/token",
        "CANOVIA_MCP_ACCOUNT_LINK_REDIRECT_URI":
            url + "/account/mcp/link/callback",
        "CANOVIA_MCP_ALLOWED_CLIENT_ID": "tpc_synthetic_chatgpt_internal",
        "CANOVIA_MCP_ACCOUNT_LINK_CLIENT_ID": "synthetic_first_party_link",
        "CANOVIA_MCP_ACCOUNT_LINK_CLIENT_SECRET": SECRET,
        "CANOVIA_MCP_IDENTITY_FINGERPRINT_KEY": FINGERPRINT,
        "CANOVIA_MCP_AP_PILOT_OWNER_ID": "99",
        "CANOVIA_MCP_TOKEN_VERIFICATION_DRIVER": "auth0_jwt",
    }


class OperatorPredeployTests(unittest.TestCase):
    def test_correct_shape_is_never_an_authorization(self):
        d = checker.evaluate(healthy())
        self.assertEqual("REVIEW_REQUIRED", d["result"])
        self.assertEqual(["OFFLINE_SHAPE_ONLY_NOT_GO"], d["codes"])
        for field in ("production_release_authorized", "auth0_real_token_verified",
                      "real_aiven_ledger_verified", "restorable_backup_verified",
                      "github_required_checks_verified",
                      "credentials_or_identifiers_disclosed",
                      "network_or_database_access_performed"):
            self.assertFalse(d[field])
        # Generic words like "production" legitimately appear in fixed
        # verdict field names; only actual identifiers and secrets are private.
        for name in (
            "CANOVIA_MCP_OAUTH_ISSUER",
            "CANOVIA_MCP_RESOURCE_URL",
            "CANOVIA_MCP_ALLOWED_CLIENT_ID",
            "CANOVIA_MCP_ACCOUNT_LINK_CLIENT_ID",
            "CANOVIA_MCP_ACCOUNT_LINK_CLIENT_SECRET",
            "CANOVIA_MCP_IDENTITY_FINGERPRINT_KEY",
            "CANOVIA_MCP_AP_PILOT_OWNER_ID",
        ):
            self.assertNotIn(healthy()[name], json.dumps(d))

    def test_every_sensitive_mcp_flag_must_stay_off_for_first_deploy(self):
        for flag in checker.FLAGS:
            with self.subTest(flag=flag):
                env = healthy()
                env[flag] = "true"
                d = checker.evaluate(env)
                self.assertEqual("BLOCK", d["result"])
                self.assertIn("MCP_PREDEPLOY_OFF_CONTRACT_VIOLATED", d["codes"])
                self.assertNotIn(flag, json.dumps(d))

    def test_invalid_bool_does_not_silently_count_as_off(self):
        for flag in checker.FLAGS:
            with self.subTest(flag=flag):
                env = healthy()
                env[flag] = "MAYBE"
                self.assertEqual("BLOCK", checker.evaluate(env)["result"])

    def test_prod_or_mysql_misclassification_denied(self):
        for field, value in (
            ("APP_ENV", "staging"), ("APP_DEBUG", "true"),
            ("DB_CONNECTION", "pgsql"),
        ):
            with self.subTest(field=field):
                env = healthy()
                env[field] = value
                self.assertEqual("BLOCK", checker.evaluate(env)["result"])

    def test_db_url_precedence_must_get_separate_operator_audit(self):
        for var in ("DB_URL", "DATABASE_URL"):
            with self.subTest(var=var):
                env = healthy()
                env[var] = "mysql://private:do-not-print@different.invalid/other"
                d = checker.evaluate(env)
                self.assertEqual("BLOCK", d["result"])
                self.assertIn("DATABASE_URL_PRECEDENCE_NEEDS_SEPARATE_AUDIT", d["codes"])
                self.assertNotIn("private", json.dumps(d))
        env = healthy()
        env["DB_URL"] = ""
        self.assertEqual("REVIEW_REQUIRED", checker.evaluate(env)["result"])

    def test_resource_origin_drift_and_untrusted_urls_denied(self):
        for app in (
            "http://fixture-canovia.example.com",
            "https://fixture-canovia.example.com/callback",
            "https://user:pass@fixture-canovia.example.com",
            "https://localhost", "https://127.0.0.1",
            "https://fixture-canovia.example.com?token=123",
            "https://fixture-canovia.example.com:8443",
        ):
            with self.subTest(app=app):
                env = healthy()
                env["APP_URL"] = app
                env["CANOVIA_MCP_RESOURCE_URL"] = app + "/api/mcp"
                self.assertIn("APP_ORIGIN_UNTRUSTED",
                              checker.evaluate(env)["codes"])

        env = healthy()
        env["CANOVIA_MCP_RESOURCE_URL"] = "https://other.example/api/mcp"
        self.assertIn("MCP_RESOURCE_ORIGIN_MISMATCH",
                      checker.evaluate(env)["codes"])

    def test_wrong_issuer_or_endpoint_cannot_link(self):
        for issuer in (
            "https://fixture-prod.jp.auth0.com.attacker.test/",
            "http://fixture-prod.jp.auth0.com/",
            "https://fixture-prod.jp.auth0.com/other/",
            "https://localhost/",
        ):
            env = healthy()
            env["CANOVIA_MCP_OAUTH_ISSUER"] = issuer
            self.assertIn("AUTH0_ISSUER_UNTRUSTED",
                          checker.evaluate(env)["codes"])

        for name in (
            "CANOVIA_MCP_ACCOUNT_LINK_METADATA_URL",
            "CANOVIA_MCP_ACCOUNT_LINK_AUTHORIZATION_ENDPOINT",
            "CANOVIA_MCP_ACCOUNT_LINK_TOKEN_ENDPOINT",
            "CANOVIA_MCP_ACCOUNT_LINK_REDIRECT_URI",
        ):
            with self.subTest(name=name):
                env = healthy()
                env[name] += "?another=1"
                self.assertIn("MCP_OWNED_ENDPOINT_MISMATCH",
                              checker.evaluate(env)["codes"])

    def test_client_identity_confusion_denied(self):
        for k, v in (
            ("CANOVIA_MCP_ALLOWED_CLIENT_ID", "https://chatgpt.com/oauth/client.json"),
            ("CANOVIA_MCP_ALLOWED_CLIENT_ID", "plain_oauth_client"),
            ("CANOVIA_MCP_ACCOUNT_LINK_CLIENT_ID", "tpc_should_be_separate"),
            ("CANOVIA_MCP_ACCOUNT_LINK_CLIENT_ID", "tpc_synthetic_chatgpt_internal"),
        ):
            with self.subTest(key=k):
                env = healthy()
                env[k] = v
                self.assertEqual("BLOCK", checker.evaluate(env)["result"])

    def test_missing_owner_hmac_or_link_secret_denied(self):
        for key, value in (
            ("CANOVIA_MCP_AP_PILOT_OWNER_ID", ""),
            ("CANOVIA_MCP_AP_PILOT_OWNER_ID", "0"),
            ("CANOVIA_MCP_AP_PILOT_OWNER_ID", "9999x"),
            ("CANOVIA_MCP_IDENTITY_FINGERPRINT_KEY", "tiny"),
            ("CANOVIA_MCP_ACCOUNT_LINK_CLIENT_SECRET", "short"),
            ("CANOVIA_MCP_TOKEN_VERIFICATION_DRIVER", "introspection"),
        ):
            env = healthy()
            env[key] = value
            self.assertEqual("BLOCK", checker.evaluate(env)["result"])

    def test_reusing_laravel_app_key_is_denied(self):
        for key in ("CANOVIA_MCP_ACCOUNT_LINK_CLIENT_SECRET",
                    "CANOVIA_MCP_IDENTITY_FINGERPRINT_KEY"):
            env = healthy()
            env["APP_KEY"] = env[key]
            self.assertEqual("BLOCK", checker.evaluate(env)["result"])
        env = healthy()
        env["CANOVIA_MCP_IDENTITY_FINGERPRINT_KEY"] = SECRET
        self.assertIn("IDENTITY_HMAC_KEY_MISSING_OR_REUSED",
                      checker.evaluate(env)["codes"])

    def test_cli_has_only_redacted_codes_and_exit_status(self):
        ok = subprocess.run([sys.executable, str(SCRIPT)],
                            env=healthy(), text=True, capture_output=True, timeout=5)
        self.assertEqual(0, ok.returncode)
        self.assertEqual("REVIEW_REQUIRED", json.loads(ok.stdout)["result"])
        block = healthy()
        block["CANOVIA_MCP_TOOLS_ENABLED"] = "true"
        bad = subprocess.run([sys.executable, str(SCRIPT)],
                             env=block, text=True, capture_output=True, timeout=5)
        self.assertEqual(2, bad.returncode)
        self.assertEqual("BLOCK", json.loads(bad.stdout)["result"])
        for stream in (ok.stdout, ok.stderr, bad.stdout, bad.stderr):
            self.assertNotIn(SECRET, stream)
            self.assertNotIn(FINGERPRINT, stream)
            self.assertNotIn("fixture-prod.jp.auth0.com", stream)
            self.assertNotIn("tpc_synthetic_chatgpt_internal", stream)
            self.assertNotIn('"99"', stream)


if __name__ == "__main__":
    unittest.main()
