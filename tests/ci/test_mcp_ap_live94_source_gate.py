"""Synthetic negative tests for the offline AP owner Live94 source gate."""
import importlib.util
from pathlib import Path
import subprocess
import sys
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[2]
MODULE_PATH = ROOT / "scripts" / "ci" / "mcp_ap_live94_source_gate.py"
spec = importlib.util.spec_from_file_location("mcp_ap_live94_source_gate", MODULE_PATH)
gate = importlib.util.module_from_spec(spec)
spec.loader.exec_module(gate)
BASE = gate.LIVE94
HEAD = "a" * 40


class Live94SourceGateTests(unittest.TestCase):
    def setUp(self):
        self.delta = [
            "app/Services/McpReadOnlyStudyContextTool.php",
            "config/canovia_mcp.php",
            "composer.json",
            "composer.lock",
        ]
        self.migration_delta = ""
        self.config = "\n".join(gate.FLAGS)
        self.current_base = BASE
        self.ancestor = BASE

    def fake_git(self, *args):
        command = args[0]
        if command == "rev-parse":
            return args[2][:40]
        if command == "merge-base":
            return self.ancestor
        if command == "ls-tree":
            return "\n".join(
                f"database/migrations/migration_{i:03d}.php" for i in range(94)
            )
        if command == "show":
            return self.config
        if command == "diff":
            if "database/migrations/" in args:
                return self.migration_delta
            if "docker/" in args:
                return ""
            if "composer.json" in args:
                return "composer.json\ncomposer.lock"
            return "\n".join(self.delta)
        raise AssertionError(args)

    def evaluate(self, head=HEAD, base=BASE):
        with patch.object(gate, "git", side_effect=self.fake_git):
            return gate.evaluate(head, base)

    def test_safe_bounded_delta_is_review_only_not_promotion(self):
        verdict = self.evaluate()
        self.assertEqual(verdict["result"], "REVIEW_REQUIRED")
        self.assertEqual(verdict["migration_sources"], 94)
        self.assertFalse(verdict["production_release_authorized"])
        self.assertFalse(verdict["real_db_verified"])

    def test_production_base_move_fails_closed(self):
        self.assertEqual(self.evaluate(base="b" * 40)["code"], "PRODUCTION_BASE_MOVED")

    def test_nonancestor_candidate_fails_closed(self):
        self.ancestor = "c" * 40
        self.assertEqual(self.evaluate()["code"], "HEAD_NOT_DESCENDED_FROM_LIVE94")

    def test_extra_auth_path_does_not_get_implicitly_approved(self):
        self.delta.append("app/Http/Middleware/UnreviewedAuthChange.php")
        verdict = self.evaluate()
        self.assertEqual(verdict["code"], "OUTSIDE_REVIEWED_ALLOWLIST")
        self.assertEqual(verdict["unexpected_paths"], ["app/Http/Middleware/UnreviewedAuthChange.php"])

    def test_any_migration_source_change_blocks(self):
        self.migration_delta = "database/migrations/2026_10_18_extra.php"
        self.assertEqual(self.evaluate()["code"], "MIGRATION_SOURCE_CHANGED")

    def test_mcp_flag_must_remain_default_off(self):
        self.config = self.config.replace(
            "'ap_pilot_enabled' => env('CANOVIA_MCP_AP_PILOT_ENABLED', false)",
            "'ap_pilot_enabled' => env('CANOVIA_MCP_AP_PILOT_ENABLED', true)",
        )
        self.assertEqual(self.evaluate()["code"], "MCP_OFF_BY_DEFAULT_CONTRACT_CHANGED")

    def test_unavailable_source_never_authorizes_release(self):
        with patch.object(
            gate, "git",
            side_effect=subprocess.CalledProcessError(128, "git"),
        ):
            verdict = gate.evaluate(HEAD, BASE)
        self.assertEqual(verdict["result"], "BLOCK")
        self.assertFalse(verdict["production_release_authorized"])

    def test_bad_sha_is_rejected_before_git(self):
        with patch.object(gate, "git") as called:
            verdict = gate.evaluate("not-a-sha", BASE)
        self.assertEqual(verdict["code"], "INVALID_PIN")
        called.assert_not_called()


if __name__ == "__main__":
    unittest.main()
