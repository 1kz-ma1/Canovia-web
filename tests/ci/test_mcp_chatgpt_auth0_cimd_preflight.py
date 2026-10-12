import importlib.util
import json
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

SCRIPT = Path(__file__).resolve().parents[2] / "scripts/ci/mcp_chatgpt_auth0_cimd_preflight.py"
SPEC = importlib.util.spec_from_file_location("chatgpt_cimd_preflight", SCRIPT)
mod = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(mod)


def chatgpt():
    return {
        "client_id": mod.CHATGPT_CIMD,
        "client_name": "ChatGPT",
        "redirect_uris": [mod.CHATGPT_CALLBACK],
        "grant_types": ["authorization_code", "refresh_token"],
        "response_types": ["code"],
        "token_endpoint_auth_methods_supported": ["none", "private_key_jwt"],
        "jwks_uri": mod.CHATGPT_JWKS,
    }


def jwks():
    return {"keys": [{
        "kid": "synthetic-2026-public-key",
        "kty": "RSA",
        "use": "sig",
        "alg": "RS256",
        "n": "a" * 342,
        "e": "AQAB",
    }]}


def auth0():
    return {
        "issuer": mod.AUTH0_ISSUER,
        "code_challenge_methods_supported": ["S256"],
        "authorization_response_iss_parameter_supported": True,
        "token_endpoint_auth_methods_supported": ["client_secret_basic", "none", "private_key_jwt"],
    }


class CIMDFreeCompatibilityTest(unittest.TestCase):
    def test_positive_public_claims_never_authorize_stage_or_registration(self):
        result = mod.assess(chatgpt(), jwks(), auth0())
        self.assertTrue(result["public_metadata_compatible_with_free_candidate"])
        self.assertFalse(result["auth0_cimd_registration_proven"])
        self.assertFalse(result["current_connection_callback_verified"])
        self.assertFalse(result["current_connection_cimd_verified"])
        self.assertFalse(result["oidc_scope_interoperability_verified"])
        self.assertEqual("requires_exact_ui_match", result["checks"]["current_connection_callback"])
        self.assertEqual("requires_connection_configuration", result["checks"]["chatgpt_oidc_scope_policy"])
        self.assertFalse(result["actual_chatgpt_login_proven"])
        self.assertFalse(result["staging_activation_authorized"])
        self.assertFalse(result["production_authorized"])
        self.assertEqual("not_advertised", result["checks"]["auth0_cimd_discovery"])
        self.assertEqual("enable_tenant_cimd_toggle_and_preview_import", result["next_step"])

    def test_enterprise_only_private_key_without_public_none_is_not_free_compatible(self):
        c = chatgpt()
        c["token_endpoint_auth_methods_supported"] = ["private_key_jwt"]
        r = mod.assess(c, jwks(), auth0())
        self.assertFalse(r["public_metadata_compatible_with_free_candidate"])
        self.assertEqual("not_advertised", r["checks"]["free_tier_public_none_method"])

    def test_missing_or_wrong_client_metadata_rejects(self):
        mutations = [
            ("client_id", "https://chatgpt.com/oauth/fake/client.json"),
            ("redirect_uris", ["https://phishing.invalid/oauth"]),
            ("grant_types", ["client_credentials"]),
            ("response_types", ["token"]),
            ("jwks_uri", "https://evil.invalid/jwks.json"),
        ]
        for name, value in mutations:
            with self.subTest(name=name):
                c = chatgpt()
                c[name] = value
                self.assertFalse(mod.assess(c, jwks(), auth0())
                                 ["public_metadata_compatible_with_free_candidate"])

    def test_wrong_public_jwks_or_provider_metadata_rejects(self):
        k = jwks()
        k["keys"][0]["d"] = "synthetic-secret-must-never-log"
        self.assertFalse(mod.assess(chatgpt(), k, auth0())
                         ["public_metadata_compatible_with_free_candidate"])
        bad = [
            ("issuer", "https://other.jp.auth0.com/"),
            ("code_challenge_methods_supported", ["plain"]),
            ("authorization_response_iss_parameter_supported", False),
            ("token_endpoint_auth_methods_supported", ["client_secret_basic"]),
        ]
        for key, value in bad:
            with self.subTest(key=key):
                a = auth0()
                a[key] = value
                self.assertFalse(mod.assess(chatgpt(), jwks(), a)
                                 ["public_metadata_compatible_with_free_candidate"])

    def test_advertised_cimd_still_does_not_prove_registration(self):
        a = auth0()
        a["client_id_metadata_document_supported"] = True
        out = mod.assess(chatgpt(), jwks(), a)
        self.assertEqual("pass", out["checks"]["auth0_cimd_discovery"])
        self.assertTrue(out["public_metadata_compatible_with_free_candidate"])
        self.assertFalse(out["auth0_cimd_registration_proven"])
        self.assertEqual("verify_exact_connection_callback_cimd_and_oidc_policy", out["next_step"])
        self.assertFalse(out["current_connection_callback_verified"])

    def test_legacy_callback_must_not_count_as_current_connection_proof(self):
        # The global metadata still contains an old stable redirect and is
        # structurally compatible, but the actual newly-created connection
        # needs its own callback and CIMD identity from the management UI.
        c = chatgpt()
        self.assertEqual([mod.CHATGPT_CALLBACK], c["redirect_uris"])
        r = mod.assess(c, jwks(), auth0())
        self.assertTrue(r["public_metadata_compatible_with_free_candidate"])
        self.assertFalse(r["current_connection_callback_verified"])
        self.assertFalse(r["current_connection_cimd_verified"])
        self.assertFalse(r["oidc_scope_interoperability_verified"])
        self.assertNotIn("pass", (
            r["checks"]["current_connection_callback"],
            r["checks"]["current_connection_cimd"],
            r["checks"]["chatgpt_oidc_scope_policy"],
        ))

    def test_cli_synthetic_data_no_secret_leak(self):
        with tempfile.TemporaryDirectory() as d:
            p = Path(d)
            c = chatgpt()
            c["private_value"] = "MUST_NEVER_PRINT_CIMD_PRIVATE_VALUE"
            (p / "client.json").write_text(json.dumps(c))
            (p / "keys.json").write_text(json.dumps(jwks()))
            (p / "issuer.json").write_text(json.dumps(auth0()))
            cmd = [sys.executable, str(SCRIPT), "--fixture-client", str(p / "client.json"),
                   "--fixture-jwks", str(p / "keys.json"),
                   "--fixture-auth0", str(p / "issuer.json")]
            result = subprocess.run(cmd, check=False, capture_output=True, text=True)
            self.assertEqual(0, result.returncode)
            self.assertNotIn("MUST_NEVER_PRINT_CIMD_PRIVATE_VALUE", result.stdout)
            self.assertNotIn("chatgpt.com", result.stdout)
            self.assertTrue(json.loads(result.stdout)
                            ["public_metadata_compatible_with_free_candidate"])
            (p / "keys.json").write_text("{}")
            bad = subprocess.run(cmd, check=False, capture_output=True, text=True)
            self.assertEqual(1, bad.returncode)
            self.assertFalse(json.loads(bad.stdout)["production_authorized"])


if __name__ == "__main__":
    unittest.main()
