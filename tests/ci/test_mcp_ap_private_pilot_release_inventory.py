"""Offline, disposable-Git acceptance for the pinned AP release inventory."""
from pathlib import Path
import subprocess
import tempfile
import unittest

from scripts.ci.mcp_ap_private_pilot_release_inventory import (
    REQUIRED_AP_FILES, inspect, RECORDED_DEVELOPMENT_SHA, RECORDED_LIVE_SHA,
)


class McpApPilotReleaseInventoryTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.repo = Path(self.tmp.name)
        self.cmd("init", "-q")
        self.cmd("config", "user.email", "test@example.invalid")
        self.cmd("config", "user.name", "Isolated test")
        self.write("README.md", "baseline\n")
        self.commit("baseline")
        self.baseline = self.cmd("rev-parse", "HEAD").strip()
        for name in REQUIRED_AP_FILES:
            source = "class TestSurface {}\n"
            if name.endswith("McpStudyPilotAccessPolicy.php"):
                source = "function allowsOwnerId() { /* deny 'staging' */ }\n"
            if name.endswith("_create_mcp_study_result_proposals_table.php"):
                source = "Schema::create('mcp_study_result_proposals');\n"
            self.write(name, source)
        self.write(
            "database/migrations/2026_10_13_120000_enforce_authorization_epochs_in_database.php",
            "DB::unprepared('CREATE TRIGGER authorization_gate');\n",
        )
        self.write("routes/web.php", "route snapshot\n")
        self.commit("snapshot")
        self.snapshot = self.cmd("rev-parse", "HEAD").strip()

    def cmd(self, *args):
        result = subprocess.run(
            ["git", "-C", str(self.repo), *args],
            stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True, check=True,
        )
        return result.stdout

    def write(self, path, value):
        file = self.repo / path
        file.parent.mkdir(parents=True, exist_ok=True)
        file.write_text(value, encoding="utf-8")

    def commit(self, message):
        self.cmd("add", "--all")
        self.cmd("commit", "-q", "-m", message)

    def test_pinned_snapshot_is_a_hold_not_a_go(self):
        report = inspect(self.repo, self.baseline, self.snapshot)
        self.assertEqual(report["status"], "HOLD")
        self.assertEqual(report["file_change_counts"], {
            "added": len(REQUIRED_AP_FILES) + 2,
            "modified": 0, "deleted": 0, "total": len(REQUIRED_AP_FILES) + 2,
        })
        self.assertFalse(report["release_authorized"])
        self.assertFalse(report["environment_mutated"])
        self.assertEqual(len(report["added_migrations"]), 2)
        self.assertEqual(
            report["migration_review_markers_not_security_proof"][
                "database/migrations/2026_10_13_120000_enforce_authorization_epochs_in_database.php"
            ], ["TRIGGER_DDL", "RAW_SQL"],
        )
        self.assertTrue(all(v == "NOT_VERIFIED" for v in report["manual_gates"].values()))
        self.assertIn("routes/web.php", report["runtime_review_hotspots"])

    def test_reverse_ancestry_is_blocked(self):
        report = inspect(self.repo, self.snapshot, self.baseline)
        self.assertEqual(report["status"], "BLOCK")
        self.assertFalse(report["release_authorized"])

    def test_identical_or_malformed_sha_is_blocked(self):
        self.assertEqual(inspect(self.repo, self.baseline, self.baseline)["status"], "BLOCK")
        self.assertEqual(inspect(self.repo, "main", self.snapshot)["status"], "BLOCK")
        self.assertEqual(inspect(self.repo, RECORDED_LIVE_SHA, RECORDED_DEVELOPMENT_SHA)["status"], "BLOCK")

    def test_missing_required_surface_is_blocked(self):
        self.cmd("rm", "app/Services/McpStudyPilotAccessPolicy.php")
        self.commit("no pilot fence")
        later = self.cmd("rev-parse", "HEAD").strip()
        report = inspect(self.repo, self.baseline, later)
        self.assertEqual(report["reason"], "MISSING_AP_SURFACES")

    def test_missing_staging_denial_marker_is_blocked(self):
        self.write("app/Services/McpStudyPilotAccessPolicy.php", "allowsOwnerId\n")
        self.commit("incomplete guard")
        later = self.cmd("rev-parse", "HEAD").strip()
        report = inspect(self.repo, self.baseline, later)
        self.assertEqual(report["reason"], "PILOT_FENCE_NOT_OBSERVED")


if __name__ == "__main__":
    unittest.main()
