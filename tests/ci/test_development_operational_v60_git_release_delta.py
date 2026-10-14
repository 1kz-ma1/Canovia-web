"""No-network tests for V6.0 exact Git release-candidate delta collector."""
from pathlib import Path
import json
import subprocess
import sys
import tempfile
import unittest

from scripts.ci.development_operational_v60_git_release_delta import (
    IncompleteSnapshot, blocked, inspect, summary, changed_paths,
)

ROOT = Path(__file__).resolve().parents[2]


class GitFixture:
    def __init__(self, path: Path):
        self.path = path
        self.git("init", "-q")
        self.git("-c", "user.name=Canovia Fixture", "-c", "user.email=v60@example.invalid",
                 "commit", "--allow-empty", "-m", "baseline")
        self.baseline = self.git("rev-parse", "HEAD")

    def git(self, *args):
        return subprocess.run(["git", *args], cwd=self.path, check=True, text=True,
                              capture_output=True).stdout.strip()

    def add(self, path, content):
        p = self.path / path
        p.parent.mkdir(parents=True, exist_ok=True)
        p.write_text(content)
        self.git("add", "--", path)
        self.git("-c", "user.name=Canovia Fixture", "-c", "user.email=v60@example.invalid",
                 "commit", "-qm", "Synthetic risk delta")
        return self.git("rev-parse", "HEAD")


class V60GitDeltaTest(unittest.TestCase):
    def test_audits_entire_local_git_diff_without_truncation_or_release_go(self):
        with tempfile.TemporaryDirectory() as tmp:
            f = GitFixture(Path(tmp))
            candidate = f.add("database/migrations/2026_10_14_000001_add_source.php", "migration")
            name = "release/2026-10-14-" + candidate[:7]
            f.git("branch", name)
            newer = f.add("app/Http/Middleware/Policy.php", "<?php")
            final = f.add("resources/views/workspace/home.blade.php", "Hello")

            # Release operator checks out the pinned candidate, not moving main.
            f.git("checkout", "-q", candidate)
            r = inspect(f.path, f.baseline, candidate, final, name)
            self.assertEqual(1, r["candidate_commits_from_production_branch"])
            self.assertEqual(2, r["commits_after_candidate_in_main_snapshot"])
            self.assertEqual(1, r["candidate_delta"]["changed_paths"])
            self.assertEqual(2, r["not_in_candidate_delta"]["changed_paths"])
            self.assertEqual(
                ["database/migrations/2026_10_14_000001_add_source.php"],
                r["candidate_delta"]["migration_paths"],
            )
            self.assertEqual(1, r["not_in_candidate_delta"]["risk_area_path_counts"]["security_and_identity"])
            self.assertEqual(1, r["not_in_candidate_delta"]["risk_area_path_counts"]["web_and_mobile_surface"])
            self.assertEqual(sum(r["candidate_delta"]["risk_area_path_counts"].values()),
                             r["candidate_delta"]["changed_paths"])
            self.assertEqual("BLOCK", r["result"])
            self.assertEqual("NEWER_MAIN_NOT_IN_PINNED_CANDIDATE", r["reason"])
            for field in ("release_authorized", "production_database_modified",
                          "backup_restore_verified", "deployed_sha_verified",
                          "device_acceptance_verified", "production_branch_is_render_live_verified",
                          "main_snapshot_is_current_remotely_verified"):
                self.assertFalse(r[field])

            current = inspect(f.path, f.baseline, candidate, candidate, name)
            self.assertEqual("INDEPENDENT_PRODUCTION_EVIDENCE_REQUIRED", current["reason"])
            self.assertTrue(current["candidate_equal_to_main_snapshot"])
            self.assertFalse(current["release_authorized"])

            # A moving local release ref cannot masquerade as its earlier pin.
            f.git("branch", "-f", name, final)
            with self.assertRaises(IncompleteSnapshot):
                inspect(f.path, f.baseline, candidate, final, name)
            f.git("branch", "-f", name, candidate)
            with self.assertRaises(IncompleteSnapshot):
                inspect(f.path, f.baseline, candidate, newer, "main")
            with self.assertRaises(IncompleteSnapshot):
                inspect(f.path, f.baseline, candidate, final, "release/2026-10-14-wrong")

    def test_invalid_ancestry_or_sha_or_checkout_fails_closed(self):
        with tempfile.TemporaryDirectory() as tmp:
            f = GitFixture(Path(tmp))
            candidate = f.add("README.md", "safe")
            release_ref = "release/2026-10-14-" + candidate[:7]
            f.git("branch", release_ref)
            f.git("checkout", "-q", f.baseline)
            with self.assertRaises(IncompleteSnapshot):
                inspect(f.path, f.baseline, candidate, candidate, release_ref)
            f.git("checkout", "-q", candidate)
            for invalid in ("main", "0123", "0"*40, "bad;rm -rf /"):
                with self.subTest(invalid=invalid):
                    with self.assertRaises(IncompleteSnapshot):
                        inspect(f.path, f.baseline, invalid, candidate, release_ref)

            # Git object exists, but base is not an ancestor of candidate.
            alternate = f.add("later.txt", "other")
            with self.assertRaises(IncompleteSnapshot):
                inspect(f.path, alternate, candidate, alternate, release_ref)

    def test_migration_filenames_are_auditable_but_unusual_paths_do_not_escape(self):
        fields = summary(["app/Other.php", "tests/XTest.php", "resources/js/app.js",
                          "database/migrations/2026_10_14_000001_example.php"])
        self.assertEqual(4, fields["changed_paths"])
        self.assertEqual(1, fields["risk_area_path_counts"]["database_migrations"])
        self.assertEqual(1, fields["risk_area_path_counts"]["other_source"])
        for path in ("database/migrations/a\nsecret.php",
                     "database/migrations/../oops.php", "database/migrations/token.txt"):
            with self.subTest(path=path):
                with self.assertRaises(IncompleteSnapshot):
                    summary([path])

    def test_cli_invalid_source_emits_constant_block_without_input_reflection(self):
        script = ROOT / "scripts/ci/development_operational_v60_git_release_delta.py"
        cmd = [sys.executable, str(script),
               "--production-branch-sha", "0"*40, "--candidate-sha", "1"*40,
               "--main-snapshot-sha", "2"*40, "--release-ref", "release/2026-10-14-secret"]
        r = subprocess.run(cmd, capture_output=True, text=True)
        self.assertEqual(2, r.returncode)
        self.assertEqual(blocked(), json.loads(r.stdout))
        self.assertNotIn("secret", r.stdout+r.stderr)
        self.assertNotIn("1"*40, r.stdout)
        self.assertFalse(json.loads(r.stdout)["release_authorized"])

    def test_source_script_is_read_only_no_remote_providers(self):
        s = (ROOT / "scripts/ci/development_operational_v60_git_release_delta.py").read_text()
        for forbidden in ("git push", "git fetch", "git branch -f", "requests.get(",
                          "urllib.request", "migrate --force", "pymysql.connect",
                          "render deploy", "release_authorized\": True"):
            self.assertNotIn(forbidden, s)


if __name__ == "__main__":
    unittest.main()
