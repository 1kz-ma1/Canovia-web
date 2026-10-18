"""Pure synthetic negatives for the non-authorizing legacy AP promotion preview."""
import importlib.util
from pathlib import Path
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[2]
SPEC = importlib.util.spec_from_file_location(
    "ap_mcp_release_bridge_review",
    ROOT / "scripts" / "ci" / "ap_mcp_release_bridge_review.py",
)
audit = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(audit)


class FakeRepository:
    def __init__(self):
        self.migration_count = 94
        self.diff = ""
        self.files = {
            (audit.MAIN, path)
            for path in set(audit.REQUIRED_CHECK_WORKFLOWS.values())
        } | {(audit.PILOT, audit.SOURCE_GATE_PATH)}
        self.main_policy = """production_release_branch_required
git merge-base --is-ancestor "$RELEASE_SHA" refs/remotes/origin/main
production_release_parent_not_in_main"""
        self.pilot_is_main_ancestor = False
        self.pilot_is_prod_descendant = True
        self.pilot_triggers_production = False

    def __call__(self, *args):
        if args[0] == "rev-parse":
            return args[-1]
        if args[0] == "merge-base":
            if args[2:] == (audit.LIVE, audit.PILOT):
                if not self.pilot_is_prod_descendant:
                    raise subprocess.CalledProcessError(1, args)
                return ""
            if args[2:] == (audit.PILOT, audit.MAIN):
                if not self.pilot_is_main_ancestor:
                    raise subprocess.CalledProcessError(1, args)
                return ""
            raise AssertionError(args)
        if args[0] == "cat-file":
            sha, path = args[2].split(":", 1)
            if (sha, path) not in self.files:
                raise subprocess.CalledProcessError(1, args)
            return ""
        if args[0] == "ls-tree":
            return "\n".join(
                f"database/migrations/2026_{i:03d}.php"
                for i in range(self.migration_count)
            )
        if args[0] == "diff":
            return self.diff
        if args[0] == "show":
            sha, path = args[1].split(":", 1)
            if sha == audit.MAIN:
                return self.main_policy
            if sha == audit.PILOT:
                if self.pilot_triggers_production:
                    return "on:\n  pull_request:\n    branches: [production]\n  push:\n    branches:\n      - production\n"
                return "on:\n  pull_request:\n    branches: [main]\n  push:\n    branches:\n      - fix/testing\n"
            raise AssertionError(args)
        raise AssertionError(args)


class ApLegacyReleaseBridgeReviewTests(unittest.TestCase):
    def setUp(self):
        self.repo = FakeRepository()

    def check(self, **overrides):
        params = dict(main=audit.MAIN, production=audit.LIVE,
                      pilot=audit.PILOT, pilot_branch=audit.PILOT_BRANCH,
                      runner=self.repo)
        params.update(overrides)
        return audit.inspect(**params)

    def test_realistic_preview_explicitly_cannot_authorize(self):
        v = self.check()
        self.assertEqual("REVIEW_REQUIRED", v["result"])
        self.assertEqual(94, v["candidate_migration_sources"])
        self.assertFalse(v["production_release_authorized"])
        self.assertFalse(v["github_required_status_checks_verified"])
        self.assertFalse(v["candidate_is_main_ancestor"])
        self.assertFalse(v["candidate_has_release_branch_name"])
        self.assertEqual(
            ["Production promotion contract"],
            v["required_check_workflow_files_missing_on_candidate"],
        )
        self.assertEqual(
            sorted(audit.REQUIRED_CHECK_WORKFLOWS),
            v["required_check_contexts_not_runnable_on_production_pr"],
        )
        self.assertEqual(
            sorted(audit.REQUIRED_CHECK_WORKFLOWS),
            v["required_check_contexts_not_runnable_on_production_push"],
        )

    def test_unreviewed_main_or_pilot_sha_blocks(self):
        for input_name in ("main", "pilot"):
            with self.subTest(input=input_name):
                self.assertEqual(
                    "REVIEW_PINS_DRIFTED",
                    self.check(**{input_name: "a" * 40})["code"],
                )

    def test_production_moved_blocks(self):
        self.assertEqual(
            "PRODUCTION_MOVED",
            self.check(production="a" * 40)["code"],
        )

    def test_unreviewed_branch_and_bad_sha_block(self):
        self.assertEqual(
            "UNREVIEWED_PILOT_BRANCH",
            self.check(pilot_branch="release/something")["code"],
        )
        self.assertEqual("INVALID_SHA", self.check(pilot="not-a-sha")["code"])

    def test_missing_main_required_workflow_blocks(self):
        self.repo.files.remove((
            audit.MAIN, ".github/workflows/production-promotion-policy.yml",
        ))
        self.assertEqual("MAIN_REQUIRED_WORKFLOW_MISSING", self.check()["code"])

    def test_modified_main_promote_policy_blocks(self):
        self.repo.main_policy = "echo bypass"
        self.assertEqual(
            "MAIN_PROMOTION_POLICY_CONTRACT_CHANGED", self.check()["code"],
        )

    def test_missing_pilot_source_gate_blocks(self):
        self.repo.files.remove((audit.PILOT, audit.SOURCE_GATE_PATH))
        self.assertEqual("PILOT_SOURCE_GATE_MISSING", self.check()["code"])

    def test_schema_change_and_missing_migration_block(self):
        self.repo.diff = "database/migrations/new.php"
        self.assertEqual("MIGRATION_SOURCE_CHANGED", self.check()["code"])
        self.repo.diff = ""
        self.repo.migration_count = 93
        self.assertEqual("EXPECTED_94_MIGRATIONS_NOT_FOUND", self.check()["code"])

    def test_non_descendant_and_missing_git_evidence_block(self):
        self.repo.pilot_is_prod_descendant = False
        self.assertEqual("SOURCE_EVIDENCE_UNAVAILABLE", self.check()["code"])
        self.repo.pilot_is_prod_descendant = True

        def unavailable(*args):
            raise subprocess.CalledProcessError(128, args)
        self.assertEqual(
            "SOURCE_EVIDENCE_UNAVAILABLE",
            self.check(runner=unavailable)["code"],
        )

    def test_even_passing_main_ancestry_and_present_workflows_cannot_grant_release(self):
        self.repo.pilot_is_main_ancestor = True
        self.repo.files.update(
            (audit.PILOT, path)
            for path in set(audit.REQUIRED_CHECK_WORKFLOWS.values())
        )
        v = self.check()
        self.assertEqual("REVIEW_REQUIRED", v["result"])
        self.assertTrue(v["candidate_is_main_ancestor"])
        self.assertEqual([], v["required_check_workflow_files_missing_on_candidate"])
        self.assertEqual(
            sorted(audit.REQUIRED_CHECK_WORKFLOWS),
            v["required_check_contexts_not_runnable_on_production_pr"],
        )
        self.assertFalse(v["production_release_authorized"])

    def test_triggers_are_real_production_event_branches_not_incidental_text(self):
        false_positive = (
            "on:\n  pull_request:\n    branches: [main]\n"
            "    paths:\n      - 'docs/production.md'\n"
            "  push:\n    branches:\n      - feature/production-docs\n"
        )
        self.assertFalse(audit.triggers_production(false_positive, "pull_request"))
        self.assertFalse(audit.triggers_production(false_positive, "push"))
        self.assertTrue(audit.triggers_production(
            "on:\n  pull_request:\n    branches: [main, production]\n", "pull_request"
        ))
        self.assertTrue(audit.triggers_production(
            "on:\n  push:\n    branches:\n      - main\n      - production\n", "push"
        ))

    def test_even_active_production_triggers_are_not_a_release_go(self):
        self.repo.pilot_triggers_production = True
        self.repo.files.update(
            (audit.PILOT, path)
            for path in set(audit.REQUIRED_CHECK_WORKFLOWS.values())
        )
        v = self.check()
        self.assertEqual("REVIEW_REQUIRED", v["result"])
        self.assertEqual([], v["required_check_contexts_not_runnable_on_production_pr"])
        self.assertEqual([], v["required_check_contexts_not_runnable_on_production_push"])
        self.assertFalse(v["production_release_authorized"])
        self.assertFalse(v["independent_backup_restore_verified"])


if __name__ == "__main__":
    unittest.main()
