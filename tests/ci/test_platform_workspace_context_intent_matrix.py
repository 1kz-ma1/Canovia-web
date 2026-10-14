"""Synthetic offline contract tests for the R17 repeated regression context matrix."""
from __future__ import annotations

import json
from pathlib import Path
import subprocess
import sys
import unittest

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT))

from scripts.ops.platform_workspace_context_intent_matrix import (
    EXPECTED_BASELINE, WORKFLOW, IncompleteEvidence, audit,
)

A = "tests/Feature/FooTest.php"
B = "tests/Feature/BarTest.php"
C = "tests/Feature/BazTest.php"
PHP = "<?php\nclass SyntheticTest {}\n"


def workflow(groups):
    return "\n".join(
        "      - name: " + title + "\n"
        "        run: php artisan test " + " ".join(files)
        for title, files in groups
    )


class IntentMatrixTests(unittest.TestCase):
    def test_real_workflow_is_fully_accounted_without_removal_permission(self):
        report = audit(WORKFLOW.read_text(encoding="utf-8"))
        self.assertEqual(report["result"], "MANUAL_REGRESSION_INTENT_REVIEW_REQUIRED")
        actual = tuple(report[k] for k in (
            "artisan_test_process_invocations", "explicit_test_file_invocations",
            "unique_test_file_paths", "repeated_file_paths",
            "additional_repeated_file_invocations",
        ))
        self.assertEqual(actual, EXPECTED_BASELINE)
        self.assertEqual(report["repeated_file_occurrences"], 63)
        self.assertEqual(report["distinct_companion_context_file_count"], 21)
        self.assertEqual(report["repeated_companion_context_file_count"], 0)
        self.assertEqual(report["automatically_approved_removal_candidates"], 0)
        self.assertFalse(report["permission_to_remove_regression_invocations"])
        self.assertFalse(report["permission_to_regroup_or_reorder"])
        self.assertFalse(report["production_release_authorized"])
        self.assertFalse(report["semantic_independence_verified"])

    def test_real_cross_context_occurrences_are_named_and_complete(self):
        report = audit(WORKFLOW.read_text(encoding="utf-8"))
        paths = set()
        for record in report["contexts"]:
            with self.subTest(path=record["test_file"]):
                self.assertNotIn(record["test_file"], paths)
                paths.add(record["test_file"])
                self.assertFalse(record["safe_to_remove"])
                self.assertEqual(record["invocations"], len(record["occurrences"]))
                self.assertEqual(record["invocations"],
                                 record["declared_step_titles_distinct"])
                self.assertEqual(record["invocations"],
                                 record["distinct_companion_sets"])
                self.assertEqual(record["review_classification"],
                                 "DISTINCT_COMPANION_CONTEXTS_HOLD")
                self.assertEqual(record["context_intent_evidence"],
                                 "CI_STEP_LABEL_ONLY_NOT_SEMANTIC_PROOF")
                for occurrence in record["occurrences"]:
                    self.assertGreater(occurrence["command_ordinal"], 0)
                    self.assertGreater(occurrence["group_file_count"], 1)
                    self.assertEqual(occurrence["group_file_count"] - 1,
                                     len(occurrence["companion_test_files"]))
                    self.assertEqual(len(occurrence["ordered_command_sha256"]), 64)
                    self.assertFalse(occurrence["safe_to_remove"])
                    self.assertFalse(occurrence["semantic_coverage_confirmed"])

    def test_distinct_companions_allow_review_but_never_removal(self):
        source = workflow((
            ("Feature", (A, B)),
            ("Legacy", (A, C)),
        ))
        report = audit(source, lambda _: PHP)
        self.assertEqual(report["repeated_file_paths"], 1)
        item = report["contexts"][0]
        self.assertEqual(item["test_file"], A)
        self.assertEqual(item["review_classification"],
                         "DISTINCT_COMPANION_CONTEXTS_HOLD")
        self.assertEqual([x["declared_regression_step"]
                          for x in item["occurrences"]], ["Feature", "Legacy"])
        self.assertEqual([x["command_ordinal"]
                          for x in item["occurrences"]], [1, 2])
        self.assertEqual(item["distinct_companion_sets"], 2)
        self.assertFalse(item["safe_to_remove"])

    def test_identical_or_reordered_companions_still_hold(self):
        for second in ((A, B, C), (A, C, B)):
            with self.subTest(second=second):
                report = audit(workflow((
                    ("One", (A, B, C)),
                    ("Two", second),
                )), lambda _: PHP)
                item = report["contexts"][0]
                self.assertEqual(item["review_classification"],
                                 "REPEATED_COMPANION_CONTEXTS_HOLD")
                self.assertEqual(item["distinct_companion_sets"], 1)
                self.assertEqual(item["distinct_ordered_commands"],
                                 1 if second == (A, B, C) else 2)
                self.assertEqual(report["automatically_approved_removal_candidates"], 0)
                self.assertFalse(report["permission_to_remove_regression_invocations"])

    def test_equal_labels_do_not_prove_equivalence(self):
        report = audit(workflow((
            ("Same named step", (A, B)),
            ("Same named step", (A, C)),
        )), lambda _: PHP)
        self.assertEqual(report["contexts"][0]["declared_step_titles_distinct"], 1)
        self.assertFalse(report["contexts"][0]["safe_to_remove"])

    def test_file_order_or_companion_changes_stable_inventory_but_new_fingerprint(self):
        before = audit(workflow((("One", (A, B)), ("Two", (A, C)))), lambda _: PHP)
        after = audit(workflow((("One", (B, A)), ("Two", (A, C)))), lambda _: PHP)
        self.assertNotEqual(before["workflow_invocation_fingerprint_sha256"],
                            after["workflow_invocation_fingerprint_sha256"])
        self.assertNotEqual(before["contexts"][0]["occurrences"][0]["ordered_command_sha256"],
                            after["contexts"][0]["occurrences"][0]["ordered_command_sha256"])

    def test_duplicated_file_in_one_command_is_not_classified(self):
        with self.assertRaises(IncompleteEvidence):
            audit(workflow((("Bad", (A, A)), ("Good", (A, B)))), lambda _: PHP)

    def test_unattributed_unsafe_or_nonliteral_command_fails_closed(self):
        invalid = (
            "        run: php artisan test " + A,
            workflow((("Bad", (A, "--filter=test")) ,)),
            workflow((("Bad", ("../private.php", A)),)),
        )
        for source in invalid:
            with self.subTest(source=source), self.assertRaises(
                (IncompleteEvidence, ValueError)
            ):
                audit(source, lambda _: PHP)

    def test_no_repeat_never_creates_removal_candidate(self):
        result = audit(workflow((("Only", (A, B)),)), lambda _: PHP)
        self.assertEqual(result["repeated_file_paths"], 0)
        self.assertEqual(result["contexts"], [])
        self.assertEqual(result["automatically_approved_removal_candidates"], 0)

    def test_cli_report_is_read_only_and_preserves_false_decisions(self):
        script = ROOT / "scripts/ops/platform_workspace_context_intent_matrix.py"
        process = subprocess.run([sys.executable, str(script)],
                                 cwd=ROOT, text=True, capture_output=True, timeout=30)
        self.assertEqual(process.returncode, 0, process.stderr)
        report = json.loads(process.stdout)
        self.assertEqual(report["repeated_file_occurrences"], 63)
        self.assertFalse(report["permission_to_remove_regression_invocations"])
        self.assertFalse(report["production_release_authorized"])
        self.assertNotIn("class SyntheticTest", process.stdout)
        self.assertNotIn("RefreshDatabase;", process.stdout)


if __name__ == "__main__":
    unittest.main()
