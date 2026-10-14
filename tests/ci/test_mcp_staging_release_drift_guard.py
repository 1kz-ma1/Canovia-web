import contextlib
import io
import json
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest

sys.path.insert(0, str(Path(__file__).resolve().parents[2]))
from scripts.ops.mcp_staging_release_drift_guard import (
    assess, read_snapshot, SnapshotInvalid,
    LIVE_APPROVED_SHA, BASE_SHA, SERVICE_ID, WORKSPACE_ID, SERVICE_NAME, REPO,
    REQUIRED_CHECKS,
)


def valid_snapshot():
    return {
        "service": {
            "id": SERVICE_ID, "ownerId": WORKSPACE_ID, "name": SERVICE_NAME,
            "repo": REPO, "branch": "main",
            "autoDeploy": "no", "autoDeployTrigger": "off",
        },
        "deploys": [
            {"id": "dep-synthetic-rollback", "status": "live",
             "commit": {"id": LIVE_APPROVED_SHA}, "trigger": "rollback"},
            {"id": "dep-synthetic-unapproved", "status": "deactivated",
             "commit": {"id": "c8749324a4701e11346ba1349d45272f01e74a88"},
             "trigger": "api"},
        ],
        "candidate": {
            "head_sha": LIVE_APPROVED_SHA, "base_sha": BASE_SHA,
            "draft": True, "merged": False,
        },
        "checks": [
            {"name": name, "status": "completed", "conclusion": "success"}
            for name in sorted(REQUIRED_CHECKS)
        ],
        "stored_github_only_flag": "verified_false",
    }


class StagingDriftGuardTests(unittest.TestCase):
    def check(self, snapshot, mode="audit"):
        answer = assess(snapshot, mode=mode)
        self.assertFalse(answer["write_or_deploy_authorized"])
        self.assertFalse(answer["production_authorized"])
        self.assertFalse(answer["environment_change_executed"])
        return answer

    def test_recovered_snapshot_is_review_only_not_mutation_authority(self):
        report = self.check(valid_snapshot())
        self.assertEqual("REVIEW_ONLY", report["decision"])
        self.assertTrue(report["snapshot_precheck_passed"])
        self.assertEqual([], report["codes"])

    def test_real_incident_newer_main_as_live_is_blocked_even_with_auto_off(self):
        snap = valid_snapshot()
        snap["deploys"].insert(0, {
            "id": "dep-accidental", "status": "live", "trigger": "api",
            "commit": {"id": "c8749324a4701e11346ba1349d45272f01e74a88"},
        })
        self.assertIn("UNAPPROVED_LIVE_OR_IN_PROGRESS", self.check(snap)["codes"])

    def test_incident_build_in_progress_blocks_even_while_old_sha_is_live(self):
        snap = valid_snapshot()
        snap["deploys"].insert(0, {
            "id": "dep-in-progress", "status": "build_in_progress",
            "trigger": "api",
            "commit": {"id": "c8749324a4701e11346ba1349d45272f01e74a88"},
        })
        verdict = self.check(snap)
        self.assertEqual("BLOCK", verdict["decision"])
        self.assertIn("PENDING_DEPLOY_OR_UNKNOWN_ROW", verdict["codes"])

    def test_unexpected_service_id_workspace_branch_or_autodeploy_blocks(self):
        for name, value in [
            ("id", "srv-unrelated"),
            ("ownerId", "tea-other"),
            ("branch", "production"),
            ("repo", "https://github.com/attacker/repo"),
            ("autoDeploy", "yes"),
            ("autoDeployTrigger", "commit"),
        ]:
            with self.subTest(name=name):
                snap = valid_snapshot()
                snap["service"][name] = value
                self.assertIn("SERVICE_OR_AUTO_DEPLOY_DRIFT", self.check(snap)["codes"])

    def test_candidate_is_immutable_and_exact_head_checks_are_required(self):
        for key, value in [
            ("head_sha", "c8749324a4701e11346ba1349d45272f01e74a88"),
            ("base_sha", LIVE_APPROVED_SHA),
            ("draft", False), ("merged", True),
        ]:
            snap = valid_snapshot()
            snap["candidate"][key] = value
            self.assertIn("PINNED_CANDIDATE_DRIFT", self.check(snap)["codes"])
        for name in REQUIRED_CHECKS:
            snap = valid_snapshot()
            for item in snap["checks"]:
                if item["name"] == name:
                    item["conclusion"] = "failure"
            self.assertIn("REQUIRED_CI_MISSING", self.check(snap)["codes"])

    def test_api_connector_env_writes_are_always_blocked(self):
        report = self.check(valid_snapshot(), "render-connector-env-write")
        self.assertEqual("BLOCK", report["decision"])
        self.assertIn("CONNECTOR_WRITE_PROHIBITED_AFTER_API_DEPLOY_INCIDENT", report["codes"])

    def test_dashboard_save_only_and_exact_sha_also_require_operator_action(self):
        for mode in ("dashboard-save-only", "exact-sha-deploy-review"):
            with self.subTest(mode=mode):
                report = self.check(valid_snapshot(), mode)
                self.assertEqual("BLOCK", report["decision"])
                self.assertTrue(report["snapshot_precheck_passed"])

    def test_rollback_does_not_prove_stored_environment_flag_was_removed(self):
        snap = valid_snapshot()
        snap.pop("stored_github_only_flag")
        report = self.check(snap)
        self.assertIn("SAVED_FLAG_NOT_INDEPENDENTLY_VERIFIED", report["codes"])
        self.assertEqual("BLOCK", report["decision"])
        snap["stored_github_only_flag"] = "verified_true"
        self.assertEqual("REVIEW_ONLY", self.check(snap)["decision"])

    def test_suspicious_fields_and_secret_values_are_never_echoed(self):
        snap = valid_snapshot()
        snap["token"] = "SECRET_TOKEN_DO_NOT_PRINT"
        snap["service"]["client_secret"] = "SECRET_TOKEN_DO_NOT_PRINT"
        snap["deploys"][0]["identity"] = "SECRET_TOKEN_DO_NOT_PRINT"
        output = json.dumps(self.check(snap))
        self.assertNotIn("SECRET_TOKEN_DO_NOT_PRINT", output)
        self.assertNotIn("identity", output)

    def test_invalid_local_snapshot_rejected_with_no_file_side_effects(self):
        with tempfile.TemporaryDirectory() as directory:
            p = Path(directory) / "metadata.json"
            p.write_text(json.dumps(valid_snapshot()))
            self.assertEqual(valid_snapshot(), read_snapshot(p))
            other = Path(directory) / "other"
            other.symlink_to(p)
            with self.assertRaises(SnapshotInvalid):
                read_snapshot(other)
            p.write_text("{malformed")
            with self.assertRaises(SnapshotInvalid):
                read_snapshot(p)
            p.write_text("x" * 40000)
            with self.assertRaises(SnapshotInvalid):
                read_snapshot(p)

    def test_cli_only_returns_preflight_status_and_has_no_write_path(self):
        with tempfile.TemporaryDirectory() as directory:
            p = Path(directory) / "snapshot.json"
            p.write_text(json.dumps(valid_snapshot()))
            script = Path(__file__).resolve().parents[2] / "scripts/ops/mcp_staging_release_drift_guard.py"
            cmd = [sys.executable, str(script), "--snapshot", str(p)]
            good = subprocess.run(cmd, capture_output=True, text=True, check=False)
            self.assertEqual(0, good.returncode)
            self.assertEqual("REVIEW_ONLY", json.loads(good.stdout)["decision"])
            blocked = subprocess.run(cmd + ["--mode", "render-connector-env-write"],
                                     capture_output=True, text=True, check=False)
            self.assertEqual(1, blocked.returncode)
            self.assertEqual("BLOCK", json.loads(blocked.stdout)["decision"])


if __name__ == "__main__":
    unittest.main()
