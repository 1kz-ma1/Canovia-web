import importlib.util
import json
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

SCRIPT = Path(__file__).resolve().parents[2] / "scripts" / "ci" / "mcp_auth0_metadata_characterization.py"
SPEC = importlib.util.spec_from_file_location("auth0_metadata", SCRIPT)
mod = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(mod)
ISSUER = "https://synthetic.us.auth0.com/"


def sample():
    return {
        "issuer": ISSUER,
        "authorization_endpoint": ISSUER + "authorize",
        "token_endpoint": ISSUER + "oauth/token",
        "jwks_uri": ISSUER + ".well-known/jwks.json",
        "code_challenge_methods_supported": ["S256"],
        "authorization_response_iss_parameter_supported": True,
        "token_endpoint_auth_methods_supported": ["none", "private_key_jwt"],
        "client_id_metadata_document_supported": True,
    }


class Auth0DiscoveryCharacterizationTest(unittest.TestCase):
    def test_positive_metadata_does_not_authorize_tokens_or_staging(self):
        result = mod.assess(sample(), ISSUER)
        self.assertTrue(result["metadata_gate_passed"])
        for key in ("stage_mcp_activation_authorized", "production_authorized", "real_chatgpt_oauth_verified", "real_token_verified"):
            self.assertFalse(result[key])
        self.assertEqual("requires_real_token_validation", result["checks"]["single_exact_audience"])
        self.assertEqual("requires_real_registration", result["checks"]["actual_chatgpt_client_id"])

    def test_fail_closed_metadata_dimensions(self):
        bad = [
            ("issuer", "https://wrong.us.auth0.com/"),
            ("authorization_endpoint", "https://evil.test/authorize"),
            ("token_endpoint", "http://synthetic.us.auth0.com/oauth/token"),
            ("jwks_uri", "https://synthetic.us.auth0.com:8443/.well-known/jwks.json"),
            ("code_challenge_methods_supported", ["plain"]),
            ("authorization_response_iss_parameter_supported", False),
            ("token_endpoint_auth_methods_supported", ["client_secret_basic"]),
        ]
        for key, value in bad:
            with self.subTest(key=key):
                m = sample()
                m[key] = value
                self.assertFalse(mod.assess(m, ISSUER)["metadata_gate_passed"])

    def test_missing_cimd_does_not_misstate_registration(self):
        m = sample()
        del m["client_id_metadata_document_supported"]
        out = mod.assess(m, ISSUER)
        self.assertEqual("not_advertised", out["checks"]["cimd_discovery"])
        self.assertFalse(out["real_chatgpt_oauth_verified"])

    def test_auth0_domain_rejects_paths_ip_ports_lookalikes(self):
        for domain in ("localhost", "127.0.0.1", "example.auth0.com.evil.test", "example.auth0.com:443", "https://x.auth0.com/", ".auth0.com", "x..auth0.com"):
            self.assertIsNone(mod.TENANT_RE.fullmatch(domain), domain)
        self.assertIsNotNone(mod.TENANT_RE.fullmatch("example.us.auth0.com"))

    def test_cli_hides_sensitive_fixture_data_and_rejects_invalid_metadata(self):
        with tempfile.TemporaryDirectory() as t:
            file = Path(t) / "metadata.json"
            m = sample()
            m["confidential_token"] = "MUST_NEVER_PRINT_PRIVATE_VALUE"
            file.write_text(json.dumps(m))
            res = subprocess.run([sys.executable, str(SCRIPT), "--fixture", str(file), "--expected-issuer", ISSUER], capture_output=True, text=True, check=False)
            self.assertEqual(0, res.returncode)
            self.assertTrue(json.loads(res.stdout)["metadata_gate_passed"])
            self.assertNotIn("MUST_NEVER_PRINT_PRIVATE_VALUE", res.stdout)
            self.assertNotIn("synthetic.us.auth0.com", res.stdout)
            file.write_text("{")
            rejected = subprocess.run([sys.executable, str(SCRIPT), "--fixture", str(file), "--expected-issuer", ISSUER], capture_output=True, text=True, check=False)
            self.assertEqual(1, rejected.returncode)
            self.assertFalse(json.loads(rejected.stdout)["production_authorized"])

    def test_non_object_metadata_cannot_pass(self):
        for raw in ([], None, "hello", 42):
            self.assertFalse(mod.assess(raw, ISSUER)["metadata_gate_passed"])


if __name__ == "__main__":
    unittest.main()
