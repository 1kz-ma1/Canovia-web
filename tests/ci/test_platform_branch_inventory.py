import json
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest

sys.path.insert(0, str(Path(__file__).resolve().parents[2]))
from scripts.ops.platform_branch_inventory import classify, read_open_prs, InventoryUnavailable


class BranchInventoryTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.repo = Path(self.tmp.name)
        self.cmd("init", "-q", "-b", "main")
        self.cmd("config", "user.email", "ci@example.invalid")
        self.cmd("config", "user.name", "CI")
        (self.repo / "hello").write_text("ok")
        self.cmd("add", ".")
        self.cmd("commit", "-qm", "init")
        self.base = self.cmd("rev-parse", "HEAD")
        for name in ("main", "production", "feature/merged", "release/pinned"):
            self.cmd("update-ref", "refs/remotes/origin/" + name, self.base)
        (self.repo / "hello").write_text("changed")
        self.cmd("add", ".")
        self.cmd("commit", "-qm", "change")
        self.unique = self.cmd("rev-parse", "HEAD")
        self.cmd("update-ref", "refs/remotes/origin/feature/wip", self.unique)
        self.cmd("update-ref", "refs/remotes/origin/feature/with-pr", self.unique)

    def cmd(self, *args):
        return subprocess.check_output(["git", "-C", str(self.repo), *args], text=True).strip()

    def get(self, prs=None):
        return {x["branch"]: x["classification"] for x in classify(self.repo, prs)["branches"]}

    def test_production_release_never_cleanup_candidates(self):
        values = self.get({})
        self.assertEqual(values["production"], "HOLD_PRODUCTION_OR_RELEASE_HISTORY")
        self.assertEqual(values["release/pinned"], "HOLD_PRODUCTION_OR_RELEASE_HISTORY")

    def test_merged_branch_remains_review_not_delete(self):
        self.assertEqual(self.get({})["feature/merged"], "REVIEW_IN_MAIN_NO_OPEN_PR")
        self.assertFalse(classify(self.repo, {})["deletion_authorized"])

    def test_unique_branch_requires_review(self):
        self.assertEqual(self.get({})["feature/wip"], "REVIEW_UNMERGED_OR_DIVERGED")

    def test_open_pr_preserved(self):
        self.assertEqual(self.get({"feature/with-pr": [{"number": 17, "sha": self.unique}]})["feature/with-pr"], "HOLD_OPEN_PR")

    def test_stale_pr_head_is_hold_not_delete(self):
        self.assertEqual(self.get({"feature/with-pr": [{"number": 17, "sha": self.base}]})["feature/with-pr"], "HOLD_OPEN_PR_HEAD_MISMATCH")

    def test_missing_pr_evidence_no_cleanup_inference(self):
        self.assertEqual(self.get(None)["feature/merged"], "REVIEW_OPEN_PR_EVIDENCE_MISSING")

    def test_missing_remote_refs_blocks(self):
        self.cmd("update-ref", "-d", "refs/remotes/origin/main")
        self.assertEqual(classify(self.repo, {})["result"], "BLOCK")

    def test_snapshot_validation_rejects_bad_head_sha(self):
        path = self.repo / "prs.json"
        path.write_text(json.dumps([{"number": 1, "headRefName": "feature/wip", "headRefOid": "HEAD"}]))
        with self.assertRaises(InventoryUnavailable):
            read_open_prs(path)

    def test_snapshot_validation_accepts_sha_bound_pr(self):
        path = self.repo / "prs.json"
        path.write_text(json.dumps([{"number": 1, "headRefName": "feature/wip", "headRefOid": self.unique}]))
        self.assertEqual(read_open_prs(path)["feature/wip"][0]["sha"], self.unique)


if __name__ == "__main__":
    unittest.main()
