"""Synthetic-only tests. Never call GitHub or any live provider."""
from __future__ import annotations

from pathlib import Path
import json
import subprocess
import sys
import tempfile
import unittest

sys.path.insert(0, str(Path(__file__).resolve().parents[2]))
from scripts.ops.platform_ci_runtime_report import (
    InvalidEvidence, compile_report, p90, seconds, safe_name,
)

START = "2026-10-14T08:00:00Z"
END = "2026-10-14T08:01:00Z"


class RuntimeReportTests(unittest.TestCase):
    def fixture(self):
        runs = [
            {"id": 101, "name": "Workspace Resume", "event": "pull_request",
             "status": "completed", "conclusion": "success"},
            {"id": 102, "name": "Migration Recovery", "event": "pull_request",
             "status": "completed", "conclusion": "failure"},
        ]
        jobs = {
            "101": {"total_count": 1, "jobs": [
                {"run_id": 101, "conclusion": "success",
                 "started_at": START, "completed_at": END,
                 "steps": [
                    {"name": "Run composer install --no-progress", "conclusion": "success",
                     "started_at": START, "completed_at": "2026-10-14T08:00:07Z"},
                    {"name": "Run npm ci --ignore-scripts", "conclusion": "success",
                     "started_at": START, "completed_at": "2026-10-14T08:00:03Z"},
                ]}]},
            "102": {"total_count": 0, "jobs": []},
        }
        return runs, jobs

    def test_successful_runtime_is_measured(self):
        report = compile_report(*self.fixture(), "pull_request")
        self.assertEqual(report["result"], "REVIEW_REQUIRED")
        self.assertEqual(report["measured_successful_jobs"], 1)
        self.assertEqual(report["zero_job_runs"], 1)
        self.assertEqual(report["workflow_runtime"][0]["median_job_seconds"], 60)
        self.assertFalse(report["test_coverage_equivalence_verified"])
        self.assertFalse(report["release_authorized"])

    def test_setup_summary_contains_counts_not_logs(self):
        report = compile_report(*self.fixture(), "pull_request")
        self.assertEqual(report["observed_setup_steps"][0]["step_type"], "composer_install")
        self.assertEqual(report["observed_setup_steps"][0]["observed_total_seconds"], 7)
        self.assertNotIn("example secret", str(report))

    def test_zero_jobs_not_workflow_success(self):
        runs, pages = self.fixture()
        result = compile_report([runs[1]], {"102": pages["102"]}, "pull_request")
        self.assertEqual(result["result"], "INSUFFICIENT_EVIDENCE")

    def test_missing_completed_job_page_blocks(self):
        runs, pages = self.fixture()
        with self.assertRaises(InvalidEvidence):
            compile_report(runs, {"101": pages["101"]}, "pull_request")

    def test_incomplete_pagination_blocks(self):
        runs, pages = self.fixture()
        pages["101"]["total_count"] = 2
        with self.assertRaises(InvalidEvidence):
            compile_report(runs, pages, "pull_request")

    def test_duplicate_run_id_blocks(self):
        runs, pages = self.fixture()
        with self.assertRaises(InvalidEvidence):
            compile_report([runs[0], runs[0]], pages, "pull_request")

    def test_wrong_event_blocks(self):
        runs, pages = self.fixture()
        with self.assertRaises(InvalidEvidence):
            compile_report(runs, pages, "push")

    def test_foreign_job_id_blocks(self):
        runs, pages = self.fixture()
        pages["101"]["jobs"][0]["run_id"] = 999
        with self.assertRaises(InvalidEvidence):
            compile_report(runs, pages, "pull_request")

    def test_reversed_timestamps_block(self):
        with self.assertRaises(InvalidEvidence):
            seconds(END, START)

    def test_naive_timestamps_block(self):
        with self.assertRaises(InvalidEvidence):
            seconds("2026-10-14T08:00:00", END)

    def test_p90_nearest_rank(self):
        self.assertEqual(p90([5, 2, 7, 100, 9]), 100)
        self.assertEqual(p90([4]), 4)

    def test_unsafe_workflow_name_is_not_emitted(self):
        self.assertEqual(safe_name("secret\nvalue"), "UNNAMED")

    def test_cli_snapshot_success_no_provider(self):
        runs, pages = self.fixture()
        with tempfile.TemporaryDirectory() as tmp:
            target = Path(tmp) / "evidence.json"
            target.write_text(json.dumps({"workflow_runs": runs, "jobs_by_run": pages}))
            script = Path(__file__).resolve().parents[2] / "scripts/ops/platform_ci_runtime_report.py"
            p = subprocess.run(
                [sys.executable, str(script), "--snapshot", str(target)],
                capture_output=True, text=True,
            )
            self.assertEqual(p.returncode, 0)
            self.assertFalse(json.loads(p.stdout)["release_authorized"])

    def test_cli_missing_snapshot_fail_closed(self):
        script = Path(__file__).resolve().parents[2] / "scripts/ops/platform_ci_runtime_report.py"
        p = subprocess.run(
            [sys.executable, str(script), "--snapshot", "/nonexistent/evidence.json"],
            capture_output=True, text=True,
        )
        self.assertEqual(p.returncode, 2)
        self.assertEqual(json.loads(p.stdout)["result"], "BLOCK")


if __name__ == "__main__":
    unittest.main()
