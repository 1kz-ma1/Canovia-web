"""Integration-style offline tests: all Git refs and data are disposable."""
from __future__ import annotations

from pathlib import Path
import subprocess
import sys
import tempfile
import unittest

sys.path.insert(0, str(Path(__file__).resolve().parents[2]))
from scripts.ops.platform_release_preflight import evaluate

BRANCH = "release/2026-10-13-pinned"


class PreflightTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.repo = Path(self.temp.name)
        self.git("init", "-q", "-b", "main")
        self.git("config", "user.name", "CI")
        self.git("config", "user.email", "ci@example.invalid")
        (self.repo / "README.md").write_text("baseline\n", encoding="utf-8")
        self.commit()
        self.live = self.git("rev-parse", "HEAD")
        self.git("update-ref", "refs/remotes/origin/production", self.live)
        migration = self.repo / "database/migrations/2026_10_14_000000_safe.php"
        migration.parent.mkdir(parents=True)
        migration.write_text("<?php // disposable example\n", encoding="utf-8")
        self.commit()
        self.release = self.git("rev-parse", "HEAD")
        self.git("update-ref", "refs/remotes/origin/main", self.release)
        self.git("update-ref", f"refs/remotes/origin/{BRANCH}", self.release)

    def git(self, *args):
        return subprocess.check_output(["git", "-C", str(self.repo), *args], text=True, stderr=subprocess.DEVNULL).strip()

    def commit(self):
        self.git("add", ".")
        self.git("commit", "-qm", "test")

    def check(self, sha=None, live=None, branch=BRANCH):
        return evaluate(self.repo, sha or self.release, live or self.live, branch)

    def test_valid_local_topology_never_authorizes_release(self):
        result = self.check()
        self.assertEqual(result["result"], "REVIEW_REQUIRED")
        self.assertEqual(result["changed_path_count"], 1)
        self.assertEqual(result["migration_files"], ["database/migrations/2026_10_14_000000_safe.php"])
        self.assertFalse(result["release_authorized"])
        self.assertTrue(all(v == "NOT_VERIFIED" for v in result["manual_gates"].values()))

    def test_moved_release_branch_blocks(self):
        self.git("update-ref", f"refs/remotes/origin/{BRANCH}", self.live)
        self.assertEqual(self.check()["checks"]["release_branch_exact_sha"], "BLOCK")

    def test_mismatched_live_sha_blocks(self):
        self.assertEqual(self.check(live=self.release)["result"], "BLOCK")

    def test_release_not_ancestor_of_main_blocks(self):
        self.git("update-ref", "refs/remotes/origin/main", self.live)
        self.assertEqual(self.check()["checks"]["candidate_is_ancestor_of_main"], "BLOCK")

    def test_missing_remote_release_ref_blocks(self):
        self.assertEqual(self.check(branch="release/nonexistent")["result"], "BLOCK")

    def test_same_release_as_production_blocks(self):
        self.git("update-ref", f"refs/remotes/origin/{BRANCH}", self.live)
        self.assertEqual(self.check(sha=self.live)["result"], "BLOCK")

    def test_invalid_sha_rejected_without_git_access(self):
        self.assertEqual(self.check(sha="HEAD;echo leaked")["checks"], {"sha_input": "INVALID"})

    def test_invalid_release_branch_rejected_without_git_access(self):
        self.assertEqual(self.check(branch="release/../../production")["checks"], {"release_branch": "INVALID"})

    def test_unavailable_repo_fails_closed(self):
        self.assertEqual(evaluate(self.repo / "missing", self.release, self.live, BRANCH)["result"], "BLOCK")


if __name__ == "__main__":
    unittest.main()
