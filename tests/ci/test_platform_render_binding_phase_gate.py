"""Synthetic, local-only tests for the Render release binding gate."""
from __future__ import annotations

from datetime import datetime, timedelta, timezone
import json
from pathlib import Path
import sys
import tempfile
import unittest

sys.path.insert(0, str(Path(__file__).resolve().parents[2]))
from scripts.ops.platform_render_binding_phase_gate import evaluate

NOW = datetime(2026, 10, 15, 1, 0, tzinfo=timezone.utc)
LIVE = "a" * 40
WORKSPACE = "tea-example123"
SERVICE = "srv-example123"


class RenderBindingTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.path = Path(self.temp.name) / "render-secretless.json"
        self.data = {
            "source": "render-ui-secretless-manual-observation",
            "observed_at_utc": "2026-10-15T00:55:00Z",
            "workspace_id": WORKSPACE,
            "service_id": SERVICE,
            "repo": "https://github.com/1kz-ma1/Canovia-web",
            "branch": "main",
            "auto_deploy": "no",
            "auto_deploy_trigger": "off",
            "live_deploy_status": "live",
            "live_sha": LIVE,
        }

    def check(self, phase="before-branch-switch", sha=LIVE):
        self.path.write_text(json.dumps(self.data), encoding="utf-8")
        return evaluate(self.path, phase, sha, SERVICE, WORKSPACE, NOW)

    def test_valid_before_switch_is_review_only(self):
        d = self.check()
        self.assertEqual(d["result"], "REVIEW_REQUIRED")
        self.assertFalse(d["release_authorized"])
        self.assertFalse(d["render_binding_attested"])
        self.assertTrue(all(x == "PASS" for x in d["checks"].values()))

    def test_valid_after_switch_still_requires_manual_deploy(self):
        self.data["branch"] = "production"
        for phase in ("after-branch-switch", "after-manual-deploy"):
            self.assertEqual(self.check(phase)["result"], "REVIEW_REQUIRED")
        self.assertFalse(self.check("after-manual-deploy")["device_smoke_verified"])

    def test_wrong_branch_in_each_phase_blocks(self):
        self.data["branch"] = "production"
        self.assertEqual(self.check()["checks"]["phase_branch"], "BLOCK")
        self.data["branch"] = "main"
        self.assertEqual(self.check("after-branch-switch")["checks"]["phase_branch"], "BLOCK")

    def test_auto_deploy_on_or_checks_pass_blocks_before_manual_release(self):
        for trigger in ("commit", "checksPass"):
            self.data["auto_deploy"] = "yes"
            self.data["auto_deploy_trigger"] = trigger
            self.assertEqual(self.check()["checks"]["auto_deploy_off"], "BLOCK")

    def test_live_sha_or_status_mismatch_blocks(self):
        self.assertEqual(self.check(sha="b" * 40)["checks"]["live_sha_matches_operator_target"], "BLOCK")
        self.data["live_deploy_status"] = "building"
        self.assertEqual(self.check()["checks"]["live_deploy_status"], "BLOCK")

    def test_service_workspace_or_repo_mismatch_blocks(self):
        for key, value, check in [
            ("workspace_id", "tea-another123", "workspace_identity"),
            ("service_id", "srv-another123", "service_identity"),
            ("repo", "https://github.com/other/repo", "repository_binding"),
        ]:
            before = self.data[key]
            self.data[key] = value
            self.assertEqual(self.check()["checks"][check], "BLOCK")
            self.data[key] = before

    def test_stale_future_and_invalid_timestamps_block(self):
        for value in ("2026-10-14T23:00:00Z", "2026-10-15T01:03:00Z",
                      "2026-10-15T01:00:00+00:00", "invalid"):
            self.data["observed_at_utc"] = value
            self.assertEqual(self.check()["checks"]["observation_freshness"], "BLOCK")

    def test_unknown_fields_are_never_accepted_or_echoed(self):
        self.data["secret_password"] = "MUST_NOT_APPEAR_IN_OUTPUT"
        d = self.check()
        self.assertEqual(d["result"], "BLOCK")
        self.assertNotIn("MUST_NOT_APPEAR_IN_OUTPUT", json.dumps(d))

    def test_bogus_source_claim_blocks(self):
        self.data["source"] = "independently-verified-render-api"
        self.assertEqual(self.check()["checks"]["source_kind"], "BLOCK")

    def test_bad_expected_sha_and_missing_snapshot_block(self):
        self.assertEqual(self.check(sha="main")["result"], "BLOCK")
        self.assertEqual(evaluate(self.path.with_name("missing.json"), "before-branch-switch",
                                  LIVE, SERVICE, WORKSPACE, NOW)["result"], "BLOCK")

    def test_symlink_and_oversized_snapshot_block(self):
        target = self.path.with_name("target.json")
        target.write_text(json.dumps(self.data))
        link = self.path.with_name("link.json")
        link.symlink_to(target)
        self.assertEqual(evaluate(link, "before-branch-switch", LIVE,
                                  SERVICE, WORKSPACE, NOW)["result"], "BLOCK")
        self.path.write_bytes(b"x" * 5000)
        self.assertEqual(evaluate(self.path, "before-branch-switch", LIVE,
                                  SERVICE, WORKSPACE, NOW)["result"], "BLOCK")

    def test_invalid_schema_and_input_phase_fail_closed(self):
        self.data["live_sha"] = 42
        self.assertEqual(self.check()["result"], "BLOCK")
        self.assertEqual(evaluate(self.path, "production-now", LIVE,
                                  SERVICE, WORKSPACE, NOW)["result"], "BLOCK")


if __name__ == "__main__":
    unittest.main()
