"""Synthetic tests for the PHP emergency production required-check contract."""
import unittest

from scripts.ci.php8427_production_required_checks_coverage import (
    WORKFLOW_SOURCES, REQUIRED_CONTEXTS, assess, event_scope, get_top_block,
)

EVENTS = """on:
  pull_request:
    branches: [production]
  push:
    branches: [production]
permissions:
  contents: read
"""

SUFFIX = {
    "contract": """jobs:
  promotion-contract:
    name: Production promotion contract
    runs-on: ubuntu-latest
    steps:
      - name: Audit fixed Live and emergency candidate
        run: |
          test 15abdc9de6f24b5250fb255c579d1aa55facaf1a
          test 0ac198acf3c087652d0a164c55602fb60922a8d0
""",
    "workspace": """jobs:
  workspace-resume:
    runs-on: ubuntu-latest
    steps:
      - run: echo synthetic
""",
    "recovery": """jobs:
  migration-recovery:
    runs-on: ubuntu-latest
    steps:
      - run: echo synthetic
""",
    "mysql": """jobs:
  mysql-p0-schema-integrity:
    name: "P0 schema integrity (MySQL matrix.mysql_series)"
    runs-on: ubuntu-latest
    strategy:
      matrix:
        mysql_series: ['8.0', '8.4']
    services:
      mysql:
        image: mysql:ci
    env:
      CANOVIA_P0_DISPOSABLE_MYSQL_CI: '1'
    steps:
      - run: echo synthetic
""",
}


def templates():
    return {path: EVENTS + SUFFIX[kind] for path, kind in WORKFLOW_SOURCES.items()}


class RequiredChecksCoverageTest(unittest.TestCase):
    def test_synthetic_production_event_contract_is_covered_but_no_go(self):
        output = assess(templates())
        self.assertEqual(output["source_coverage"], "PASS")
        self.assertEqual(output["verdict"], "NO_GO")
        self.assertEqual(output["expected_checks"], list(REQUIRED_CONTEXTS))
        self.assertEqual(output["live_database_queries"], 0)

    def test_old_branch_only_and_path_filtered_events_fail(self):
        files = templates()
        file = next(iter(files))
        files[file] = files[file].replace("branches: [production]", "branches: [main]", 1)
        self.assertEqual(assess(files)["source_coverage"], "BLOCK")
        files = templates()
        file = next(iter(files))
        files[file] = files[file].replace(
            "branches: [production]\n  push:",
            "branches: [production]\n    paths: ['Dockerfile']\n  push:",
        )
        self.assertIn("contract:PULL_REQUEST_RESTRICTED_EVENT", assess(files)["blockers"])

    def test_workflow_dispatch_only_cannot_satisfy_required_context(self):
        files = templates()
        path = next(iter(files))
        files[path] = files[path].replace(
            "on:\n  pull_request:\n    branches: [production]\n",
            "on:\n  workflow_dispatch:\n",
        )
        self.assertIn("contract:PULL_REQUEST_NOT_EXPLICIT", assess(files)["blockers"])

    def test_missing_workflow_missing_or_conditional_job_fail(self):
        files = templates()
        file = next(iter(files))
        files.pop(file)
        self.assertIn("MISSING_WORKFLOW:contract", assess(files)["blockers"])
        files = templates()
        files[file] = files[file].replace(
            "    runs-on: ubuntu-latest", "    if: false\n    runs-on: ubuntu-latest"
        )
        self.assertIn("CONDITIONAL_REQUIRED_JOB:contract", assess(files)["blockers"])

    def test_mysql_two_engines_and_isolation_are_necessary(self):
        files = templates()
        path = next(k for k, v in WORKFLOW_SOURCES.items() if v == "mysql")
        files[path] = files[path].replace("['8.0', '8.4']", "['8.4']")
        self.assertIn("DISPOSABLE_MYSQL_TWO_ENGINES_MISSING", assess(files)["blockers"])
        files = templates()
        files[path] = files[path].replace("CANOVIA_P0_DISPOSABLE_MYSQL_CI", "REMOVED_CI_MARKER")
        self.assertIn("ISOLATED_MYSQL_GUARD_MISSING", assess(files)["blockers"])

    def test_missing_exact_pinned_candidates_fail(self):
        files = templates()
        path = next(k for k, v in WORKFLOW_SOURCES.items() if v == "contract")
        files[path] = files[path].replace(
            "0ac198acf3c087652d0a164c55602fb60922a8d0", "f" * 40
        )
        self.assertIn("EXACT_EMERGENCY_SOURCE_UNPROVEN", assess(files)["blockers"])

    def test_duplicate_and_rootless_on_mapping_fails(self):
        self.assertEqual(get_top_block("jobs: {}\n", "on"), "")
        ok, reason = event_scope("on: [push,pull_request]\n", "pull_request")
        self.assertFalse(ok)
        self.assertEqual(reason, "MISSING_TOP_LEVEL_ON_MAPPING")
        bad = EVENTS + "on:\n  push:\n    branches: [production]\n"
        self.assertEqual(get_top_block(bad, "on"), "")

    def test_no_real_promotion_can_be_inferred_from_static_triggers(self):
        result = assess(templates())
        self.assertIn("PROTECTED_POST_MERGE_SHA_CHECKS", result["unverified"])
        self.assertIn("EXPLICIT_OWNER_PRODUCTION_APPROVAL", result["unverified"])
        self.assertEqual(result["mutations"], 0)


if __name__ == "__main__":
    unittest.main()
