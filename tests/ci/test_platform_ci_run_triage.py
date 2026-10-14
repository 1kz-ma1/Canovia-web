import unittest
from pathlib import Path
import sys

sys.path.insert(0, str(Path(__file__).resolve().parents[2]))
from scripts.ops.platform_ci_run_triage import triage


class WorkflowTriageTests(unittest.TestCase):
    def data(self, status="completed", conclusion="failure", jobs=None, total=None):
        jobs = [] if jobs is None else jobs
        return triage({"id": 42, "status": status, "conclusion": conclusion},
                      {"total_count": len(jobs) if total is None else total, "jobs": jobs})

    def test_no_job_failure_not_called_test_failure(self):
        r = self.data()
        self.assertEqual(r["code"], "NO_JOBS_WORKFLOW_SETUP_OR_POLICY_REVIEW")
        self.assertFalse(r["root_cause_verified"])
        self.assertFalse(r["release_authorized"])

    def test_real_executed_job_failure(self):
        self.assertEqual(self.data(jobs=[{"conclusion": "failure", "run_id": 42}])["code"], "EXECUTED_JOB_FAILURE")

    def test_in_progress_is_not_failure(self):
        self.assertEqual(self.data(status="in_progress", conclusion=None)["code"], "WORKFLOW_STILL_PENDING")

    def test_success_still_no_release_authorization(self):
        r = self.data(conclusion="success", jobs=[{"conclusion": "success"}])
        self.assertEqual(r["code"], "ALL_REPORTED_JOBS_SUCCESS_NOT_RELEASE_GO")
        self.assertFalse(r["release_authorized"])

    def test_success_zero_jobs_not_test_pass(self):
        self.assertEqual(self.data(conclusion="success")["code"], "NO_JOBS_NO_TEST_EVIDENCE")

    def test_skipped_job_requires_review(self):
        self.assertEqual(self.data(conclusion="success", jobs=[{"conclusion": "skipped"}])["code"], "WORKFLOW_OR_JOBS_REQUIRE_REVIEW")

    def test_incomplete_jobs_list_blocks(self):
        self.assertEqual(self.data(total=102)["result"], "BLOCK")

    def test_foreign_run_id_blocks(self):
        self.assertEqual(self.data(jobs=[{"run_id": 1, "conclusion": "success"}])["result"], "BLOCK")

    def test_bad_metadata_blocks(self):
        self.assertEqual(triage({}, {})["result"], "BLOCK")


if __name__ == "__main__":
    unittest.main()
