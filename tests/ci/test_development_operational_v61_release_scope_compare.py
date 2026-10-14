"""Isolated local Git fixtures for V6.1 two-option release scope audit."""
import json
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest

from scripts.ci.development_operational_v61_release_scope_compare import compare, blocked
from scripts.ci.development_operational_v60_git_release_delta import IncompleteSnapshot

ROOT = Path(__file__).resolve().parents[2]


class Fixture:
    def __init__(self, root):
        self.root = root
        self.git("init", "-q")
        self.git("-c", "user.name=Canovia Test", "-c", "user.email=v61@example.invalid",
                 "commit", "--allow-empty", "-m", "base")
        self.base = self.git("rev-parse", "HEAD")

    def git(self, *args):
        return subprocess.run(["git", *args], cwd=self.root,
                              capture_output=True, text=True, check=True).stdout.strip()

    def add(self, path, value):
        p = self.root / path
        p.parent.mkdir(parents=True, exist_ok=True)
        p.write_text(value)
        self.git("add", "--", path)
        self.git("-c", "user.name=Canovia Test", "-c", "user.email=v61@example.invalid",
                 "commit", "-qm", "change")
        return self.git("rev-parse", "HEAD")


class V61ScopeComparisonTest(unittest.TestCase):
    def test_existing_candidate_vs_newer_main_scope_and_nonadditive_migrations(self):
        with tempfile.TemporaryDirectory() as tmp:
            f = Fixture(Path(tmp))
            old = f.add("database/migrations/2026_10_13_010000_old.php", "<?php")
            ref = "release/2026-10-14-" + old[:7]
            f.git("branch", ref)
            f.add("database/migrations/2026_10_14_010000_new.php", "<?php")
            f.add("app/Http/Middleware/ActorCheck.php", "<?php")
            now = f.add("resources/views/home.blade.php", "New UI")
            f.git("checkout", "-q", old)

            report = compare(f.root, f.base, old, now, ref)
            self.assertEqual("BLOCK", report["result"])
            self.assertFalse(report["release_authorized"])
            self.assertFalse(report["owner_scope_selected"])
            self.assertFalse(report["production_branch_is_render_live_verified"])
            old_option, main_option = report["options"]
            self.assertEqual("existing_pinned_release", old_option["id"])
            self.assertEqual(1, old_option["commits_since_production_branch"])
            self.assertEqual(1, old_option["git_delta"]["changed_paths"])
            self.assertTrue(old_option["is_existing_pinned_release"])
            self.assertEqual(
                ["database/migrations/2026_10_13_010000_old.php"],
                old_option["git_delta"]["migration_paths"],
            )
            self.assertEqual("hypothetical_main_candidate", main_option["id"])
            self.assertEqual(4, main_option["commits_since_production_branch"])
            self.assertFalse(main_option["is_existing_pinned_release"])
            self.assertFalse(main_option["new_release_branch_created"])
            self.assertEqual(4, main_option["git_delta"]["changed_paths"])
            self.assertEqual(2, len(main_option["git_delta"]["migration_paths"]))
            self.assertEqual(3, report["deferred_if_existing_published"]["commits_in_main_after_existing_release"])
            self.assertEqual(3, report["deferred_if_existing_published"]["git_delta"]["changed_paths"])
            self.assertEqual(1, len(report["deferred_if_existing_published"]["git_delta"]["migration_paths"]))
            for item in report["options"]:
                self.assertTrue(all(g["state"] == "UNVERIFIED"
                                    for g in item["required_review_gates"]))
                self.assertFalse(item["db_migration_verified"])
                self.assertFalse(item["device_acceptance_verified"])

            # Run real CLI from a separate, currently checked-out tool tree.
            script = ROOT / "scripts/ci/development_operational_v61_release_scope_compare.py"
            cli = subprocess.run(
                [sys.executable, str(script), "--production-branch-sha", f.base,
                 "--existing-candidate-sha", old, "--main-snapshot-sha", now,
                 "--release-ref", ref, "--git-repo", str(f.root)],
                capture_output=True, text=True,
            )
            self.assertEqual(0, cli.returncode, cli.stderr)
            payload = json.loads(cli.stdout)
            self.assertEqual(4, payload["options"][1]["git_delta"]["changed_paths"])
            self.assertEqual("BLOCK", payload["result"])

    def test_same_snapshot_needs_independent_proof_not_auto_go(self):
        with tempfile.TemporaryDirectory() as tmp:
            f = Fixture(Path(tmp))
            old = f.add("resources/views/a.blade.php", "A")
            ref = "release/2026-10-14-" + old[:7]
            f.git("branch", ref)
            result = compare(f.root, f.base, old, old, ref)
            self.assertEqual(0, result["deferred_if_existing_published"]["commits_in_main_after_existing_release"])
            self.assertEqual(0, result["deferred_if_existing_published"]["git_delta"]["changed_paths"])
            self.assertFalse(result["release_authorized"])

    def test_moved_ref_incomplete_checkout_divergent_baseline_and_bad_sha_denied(self):
        with tempfile.TemporaryDirectory() as tmp:
            f = Fixture(Path(tmp))
            old = f.add("resources/views/a.blade.php", "A")
            ref = "release/2026-10-14-" + old[:7]
            f.git("branch", ref)
            new = f.add("database/migrations/2026_10_14_020000_new.php", "<?php")
            f.git("checkout", "-q", old)
            for wrong in ["0"*40, "not-a-sha", new]:
                with self.subTest(wrong=wrong):
                    with self.assertRaises(IncompleteSnapshot):
                        compare(f.root, wrong, old, new, ref)
            # The actual baseline must remain valid; a later commit cannot
            # masquerade as a prior production ancestor of the old release.
            self.assertFalse(compare(f.root, f.base, old, new, ref)["release_authorized"])
            f.git("branch", "-f", ref, new)
            with self.assertRaises(IncompleteSnapshot):
                compare(f.root, f.base, old, new, ref)
            f.git("branch", "-f", ref, old)
            f.git("checkout", "-q", new)
            with self.assertRaises(IncompleteSnapshot):
                compare(f.root, f.base, old, new, ref)

    def test_bad_cli_does_not_echo_untrusted_data_or_authorize_release(self):
        cmd = [
            sys.executable, str(ROOT / "scripts/ci/development_operational_v61_release_scope_compare.py"),
            "--production-branch-sha", "0"*40,
            "--existing-candidate-sha", "1"*40,
            "--main-snapshot-sha", "2"*40,
            "--release-ref", "release/2026-10-14-secret-data",
        ]
        p = subprocess.run(cmd, capture_output=True, text=True)
        self.assertEqual(2, p.returncode)
        self.assertEqual(blocked(), json.loads(p.stdout))
        self.assertNotIn("secret-data", p.stdout + p.stderr)
        self.assertFalse(json.loads(p.stdout)["release_authorized"])


if __name__ == "__main__":
    unittest.main()
