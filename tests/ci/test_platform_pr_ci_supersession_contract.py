"""Offline contract: obsolete main PR checks may cancel, production never may."""
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[2]
WORKFLOWS = {
    ".github/workflows/v56-12-workspace-resume.yml": (
        "workspace-resume:", "php artisan migrate --force",
        "npm ci --ignore-scripts",
        "MCP explicit Plan consent, fresh OAuth and scope isolation",
    ),
    ".github/workflows/v56-8-migration-recovery.yml": (
        "migration-recovery:", "php artisan migrate --force",
        "npm ci --ignore-scripts", "V56.11 MySQL constraint recovery",
    ),
}
GROUP = (
    "  group: ${{ github.workflow }}-${{ github.event_name == 'pull_request' "
    "&& github.base_ref == 'main' && format('pr-{0}', "
    "github.event.pull_request.number) || format('run-{0}', github.run_id) }}"
)
CANCEL = (
    "  cancel-in-progress: ${{ github.event_name == 'pull_request' "
    "&& github.base_ref == 'main' }}"
)


def verify(source: str, required: tuple[str, ...]) -> bool:
    """Exactly one scoped concurrency rule and unchanged critical jobs."""
    if source.count("\nconcurrency:\n") != 1:
        return False
    head, _, jobs = source.partition("\njobs:\n")
    if not jobs or head.count("  group:") != 1 or head.count("  cancel-in-progress:") != 1:
        return False
    if GROUP not in head or CANCEL not in head:
        return False
    if "  push:\n" not in head or "  pull_request:\n" not in head:
        return False
    before_pr, _, pr_and_concurrency = head.partition("  pull_request:\n")
    push = before_pr.partition("  push:\n")[2]
    pr = pr_and_concurrency.split("\nconcurrency:\n", 1)[0]
    if "\n      - production\n" not in push or "\n      - production\n" not in pr:
        return False
    return all(item in jobs for item in required)


class PrSupersessionTests(unittest.TestCase):
    def sources(self):
        return {name: (ROOT / name).read_text(encoding="utf-8") for name in WORKFLOWS}

    def test_real_workflows_keep_production_contracts(self):
        for name, source in self.sources().items():
            with self.subTest(workflow=name):
                self.assertTrue(verify(source, WORKFLOWS[name]))

    def test_cancel_scope_must_not_be_unconditional(self):
        for name, source in self.sources().items():
            self.assertFalse(verify(source.replace(CANCEL, "  cancel-in-progress: true"), WORKFLOWS[name]))

    def test_no_main_target_guard_fails(self):
        for name, source in self.sources().items():
            self.assertFalse(verify(source.replace("&& github.base_ref == 'main' }}", "}}"),
                                    WORKFLOWS[name]))

    def test_shared_production_ref_group_fails(self):
        for name, source in self.sources().items():
            self.assertFalse(verify(source.replace("format('run-{0}', github.run_id)", "github.ref"),
                                    WORKFLOWS[name]))

    def test_production_push_cannot_be_removed(self):
        for name, source in self.sources().items():
            a = source.index("  push:\n")
            b = source.index("  pull_request:\n", a)
            changed = source[:a] + source[a:b].replace("      - production\n", "") + source[b:]
            self.assertFalse(verify(changed, WORKFLOWS[name]))

    def test_production_pr_cannot_be_removed(self):
        for name, source in self.sources().items():
            a = source.index("  pull_request:\n")
            b = source.index("\nconcurrency:\n", a)
            changed = source[:a] + source[a:b].replace("      - production\n", "") + source[b:]
            self.assertFalse(verify(changed, WORKFLOWS[name]))

    def test_critical_job_commands_must_remain(self):
        for name, source in self.sources().items():
            marker = WORKFLOWS[name][1]
            self.assertFalse(verify(source.replace(marker, "REMOVED_TEST"), WORKFLOWS[name]))

    def test_missing_concurrency_block_fails(self):
        for name, source in self.sources().items():
            self.assertFalse(verify(source.replace("\nconcurrency:\n", "\n# disabled\n"),
                                    WORKFLOWS[name]))

    def test_duplicate_concurrency_block_fails(self):
        for name, source in self.sources().items():
            self.assertFalse(verify(source.replace("\nconcurrency:\n",
                                                   "\nconcurrency:\n\nconcurrency:\n"),
                                    WORKFLOWS[name]))


if __name__ == "__main__":
    unittest.main()
