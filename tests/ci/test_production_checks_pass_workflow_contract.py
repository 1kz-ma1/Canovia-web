"""Fail-closed checks for production merge-SHA CI before Render checksPass CD.

These tests inspect workflow source only. No GitHub API, Aiven, Render or
production secrets are accessed.
"""

from pathlib import Path
import unittest


ROOT = Path(__file__).resolve().parents[2]


def workflow(name: str) -> str:
    return (ROOT / ".github" / "workflows" / name).read_text(encoding="utf-8")


class ProductionChecksPassWorkflowContract(unittest.TestCase):
    def test_promotion_policy_runs_on_production_push_and_pr(self) -> None:
        content = workflow("production-promotion-policy.yml")
        self.assertRegex(content, r"(?m)^  pull_request:\n    branches: \[production\]")
        self.assertRegex(content, r"(?m)^  push:\n    branches: \[production\]")
        self.assertIn("uses: ./.github/workflows/p0-disposable-mysql-schema.yml", content)
        self.assertIn("production-mysql-integrity:", content)
        self.assertIn("name: Production promotion contract", content)
        self.assertNotRegex(content, r"production-mysql-integrity:\n\s+if:")
        self.assertNotRegex(content, r"promotion-contract:\n\s+if:")

    def test_pushed_production_commit_requires_merge_of_main_ancestor(self) -> None:
        content = workflow("production-promotion-policy.yml")
        self.assertIn("if: github.event_name == 'push'", content)
        self.assertIn("refs/heads/production", content)
        self.assertIn("git show -s --format=%P HEAD", content)
        self.assertIn('if [ "$#" -ne 2 ]; then', content)
        self.assertIn('git merge-base --is-ancestor "$2" refs/remotes/origin/main', content)
        self.assertIn("production_merge_commit_required", content)
        self.assertIn("production_release_parent_not_in_main", content)

    def test_critical_workspace_and_recovery_ci_run_after_production_push(self) -> None:
        for file_name in ("v56-12-workspace-resume.yml", "v56-8-migration-recovery.yml"):
            with self.subTest(file=file_name):
                content = workflow(file_name)
                self.assertRegex(
                    content,
                    r"(?ms)^  push:\n    branches:\n(?:      - [^\n]+\n)*      - production\n",
                )
                self.assertRegex(
                    content,
                    r"(?m)^  pull_request:\n    branches:\n      - main\n      - production",
                )

    def test_policy_requires_checks_pass_not_unsafe_on_commit(self) -> None:
        policy = (ROOT / "docs" / "CANOVIA_DEVELOPMENT_WORKFLOW.md").read_text()
        self.assertIn("autoDeployTrigger=checksPass", policy)
        self.assertIn("Do **not** choose", policy)
        self.assertIn("branch protection", policy.lower())


if __name__ == "__main__":
    unittest.main()
