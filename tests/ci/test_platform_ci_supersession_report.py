"""Offline contract tests for CI supersession, never contacting providers."""
from __future__ import annotations

import json
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest

sys.path.insert(0, str(Path(__file__).resolve().parents[2]))
from scripts.ops.platform_ci_supersession_report import summarize
from scripts.ops.platform_ci_runtime_report import InvalidEvidence

OLD = "a" * 40
NEW = "b" * 40
START = "2026-10-14T09:57:48Z"
CREATED_NEW = "2026-10-14T09:58:13Z"


def fixture():
    runs = [
        {"id": 100, "workflow_id": 99, "name": "Workspace Resume", "event": "pull_request",
         "head_sha": OLD, "created_at": START, "status": "completed", "conclusion": "cancelled",
         "pull_requests": [{"number": 123, "base": {"ref": "main"}}]},
        {"id": 101, "workflow_id": 99, "name": "Workspace Resume", "event": "pull_request",
         "head_sha": NEW, "created_at": CREATED_NEW, "status": "completed", "conclusion": "success",
         "pull_requests": [{"number": 123, "base": {"ref": "main"}}]},
    ]
    pages = {
        "100": {"total_count": 1, "jobs": [
            {"run_id": 100, "conclusion": "cancelled", "started_at": "2026-10-14T09:57:52Z",
             "completed_at": "2026-10-14T09:59:10Z"}]},
        "101": {"total_count": 1, "jobs": [
            {"run_id": 101, "conclusion": "success", "started_at": "2026-10-14T09:59:14Z",
             "completed_at": "2026-10-14T10:00:35Z"}]},
    }
    return runs, pages


class SupersessionReportTests(unittest.TestCase):
    def test_observed_cancellation_and_success_produce_confirmed_pair(self):
        x = summarize(*fixture())
        self.assertEqual(x["result"], "REVIEW_REQUIRED")
        self.assertEqual(x["confirmed_supersession_pairs"], 1)
        self.assertEqual(x["observed_cancelled_job_seconds"], 78)
        self.assertEqual(x["observed_cancelled_job_count"], 1)
        self.assertEqual(x["confirmed_examples"][0]["old_sha_prefix"], OLD[:12])
        self.assertFalse(x["avoided_runtime_verified"])
        self.assertFalse(x["billed_minutes_verified"])
        self.assertFalse(x["release_authorized"])

    def test_distinct_pr_numbers_cannot_be_paired(self):
        runs, pages = fixture()
        runs[1]["pull_requests"][0]["number"] = 124
        self.assertEqual(summarize(runs, pages)["confirmed_supersession_pairs"], 0)

    def test_production_target_cannot_be_paired(self):
        runs, pages = fixture()
        runs[1]["pull_requests"][0]["base"]["ref"] = "production"
        x = summarize(runs, pages)
        self.assertEqual(x["confirmed_supersession_pairs"], 0)
        self.assertEqual(x["runs_without_main_pr_identity"], 1)

    def test_different_workflow_id_cannot_be_paired(self):
        runs, pages = fixture()
        runs[1]["workflow_id"] = 100
        self.assertEqual(summarize(runs, pages)["confirmed_supersession_pairs"], 0)

    def test_rerun_same_sha_cannot_prove_new_head(self):
        runs, pages = fixture()
        runs[1]["head_sha"] = OLD
        self.assertEqual(summarize(runs, pages)["confirmed_supersession_pairs"], 0)

    def test_latest_failed_cannot_prove_success(self):
        runs, pages = fixture()
        runs[1]["conclusion"] = "failure"
        pages["101"]["jobs"][0]["conclusion"] = "failure"
        self.assertEqual(summarize(runs, pages)["confirmed_supersession_pairs"], 0)

    def test_previous_completed_success_not_cancelled(self):
        runs, pages = fixture()
        runs[0]["conclusion"] = "success"
        pages["100"]["jobs"][0]["conclusion"] = "success"
        self.assertEqual(summarize(runs, pages)["confirmed_supersession_pairs"], 0)

    def test_cancelled_run_without_cancelled_job_not_proven(self):
        runs, pages = fixture()
        pages["100"]["jobs"][0]["conclusion"] = "success"
        self.assertEqual(summarize(runs, pages)["confirmed_supersession_pairs"], 0)

    def test_missing_cancelled_job_timing_not_proven(self):
        runs, pages = fixture()
        pages["100"]["jobs"][0]["completed_at"] = None
        x = summarize(runs, pages)
        self.assertEqual(x["confirmed_supersession_pairs"], 0)
        self.assertGreater(x["jobs_without_complete_timing"], 0)

    def test_invalid_jobs_pagination_blocks(self):
        runs, pages = fixture()
        pages["101"]["total_count"] = 2
        with self.assertRaises(InvalidEvidence):
            summarize(runs, pages)

    def test_missing_completed_page_blocks(self):
        runs, pages = fixture()
        del pages["100"]
        with self.assertRaises(InvalidEvidence):
            summarize(runs, pages)

    def test_repeated_run_identifier_blocks(self):
        runs, pages = fixture()
        runs[1]["id"] = 100
        with self.assertRaises(InvalidEvidence):
            summarize(runs, pages)

    def test_wrong_event_blocks(self):
        runs, pages = fixture()
        runs[0]["event"] = "push"
        with self.assertRaises(InvalidEvidence):
            summarize(runs, pages)

    def test_missing_pr_identity_still_reports_unattributed_cancelled_time(self):
        runs, pages = fixture()
        runs[0]["pull_requests"] = []
        result = summarize(runs, pages)
        self.assertEqual(result["observed_cancelled_job_seconds"], 78)
        self.assertEqual(result["confirmed_supersession_pairs"], 0)

    def test_old_pending_not_successful_evidence(self):
        runs, pages = fixture()
        runs[0]["status"] = "in_progress"
        del pages["100"]
        x = summarize(runs, pages)
        self.assertEqual(x["confirmed_supersession_pairs"], 0)

    def test_job_start_latency_from_created_at(self):
        result = summarize(*fixture())
        self.assertEqual(result["job_start_latency_samples"], 2)
        self.assertEqual(result["p90_start_latency_seconds"], 61)

    def test_cli_synthetic_snapshot_never_authorizes_release(self):
        runs, pages = fixture()
        with tempfile.TemporaryDirectory() as tmp:
            path = Path(tmp) / "evidence.json"
            path.write_text(json.dumps({"workflow_runs": runs, "jobs_by_run": pages}), encoding="utf-8")
            script = Path(__file__).resolve().parents[2] / "scripts/ops/platform_ci_supersession_report.py"
            p = subprocess.run([sys.executable, str(script), "--snapshot", str(path)],
                               capture_output=True, text=True)
            self.assertEqual(p.returncode, 0, p.stderr)
            self.assertEqual(json.loads(p.stdout)["confirmed_supersession_pairs"], 1)

    def test_cli_missing_snapshot_blocks_without_leak(self):
        script = Path(__file__).resolve().parents[2] / "scripts/ops/platform_ci_supersession_report.py"
        p = subprocess.run([sys.executable, str(script), "--snapshot", "/none/secret.txt"],
                           capture_output=True, text=True)
        self.assertEqual(p.returncode, 2)
        self.assertEqual(json.loads(p.stdout)["result"], "BLOCK")
        self.assertNotIn("/none/secret.txt", p.stdout)


if __name__ == "__main__":
    unittest.main()
