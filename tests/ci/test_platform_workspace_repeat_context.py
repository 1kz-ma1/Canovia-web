"""Offline regression checks for repeated Workspace Resume test contexts."""
from __future__ import annotations

import json
from pathlib import Path
import subprocess
import sys
import unittest

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT))

from scripts.ops.platform_workspace_repeat_context import (
    IncompleteEvidence, WORKFLOW, analyze, read_php,
)


def simple(lines=None):
    commands = lines if lines is not None else (
        ("First regression", "tests/Feature/FooTest.php"),
        ("Second regression", "tests/Feature/FooTest.php"),
        ("Third regression", "tests/Unit/BarTest.php"),
    )
    return "\n".join(
        "      - name: " + label + "\n"
        "        run: php artisan test " + filename
        for label, filename in commands
    )


class RepeatContextTests(unittest.TestCase):
    def test_real_workflow_keeps_all_42_extra_invocations(self):
        report = analyze(WORKFLOW.read_text(encoding="utf-8"))
        self.assertEqual(report["result"], "REVIEW_REQUIRED")
        self.assertEqual(report["artisan_test_process_invocations"], 72)
        self.assertEqual(report["explicit_test_file_invocations"], 160)
        self.assertEqual(report["unique_test_file_paths"], 118)
        self.assertEqual(report["repeated_file_paths"], 21)
        self.assertEqual(report["additional_repeated_file_invocations"], 42)
        self.assertEqual(len(report["invocation_fingerprint_sha256"]), 64)
        self.assertFalse(report["permission_to_deduplicate_or_reorder"])
        self.assertFalse(report["permission_to_group_feature_tests"])
        self.assertFalse(report["release_authorized"])

    def test_most_repeated_file_is_preserved_in_seven_named_contexts(self):
        report = analyze(WORKFLOW.read_text(encoding="utf-8"))
        record = report["contexts"][0]
        self.assertEqual(record["test_file"],
                         "tests/Feature/AdaptiveLearningSingleQuestionV5878Test.php")
        self.assertEqual(record["invocations"], 7)
        self.assertEqual(record["additional_invocations"], 6)
        self.assertEqual(record["different_context_count"], 7)
        self.assertEqual(record["named_regression_contexts"][0],
                         "V58.81 Explainable Learning Mode Ranking")
        self.assertEqual(record["named_regression_contexts"][-1],
                         "V58.78 Adaptive Learning Single Question and Legacy Regression")

    def test_high_repeat_classes_are_stateful_laravel_feature_tests(self):
        report = analyze(WORKFLOW.read_text(encoding="utf-8"))
        for entry in report["contexts"][:8]:
            with self.subTest(test_file=entry["test_file"]):
                self.assertTrue(entry["source_signals"]["feature_test_path"])
                self.assertTrue(entry["source_signals"]["laravel_testcase"])
                self.assertTrue(entry["source_signals"]["refresh_database"])
                self.assertFalse(entry["safe_to_remove"])
                self.assertEqual(entry["disposition"], "REVIEW_ISOLATION_AND_CROSS_CONTEXT_COVERAGE")

    def test_unknown_state_does_not_auto_allow_deduplication(self):
        php = "<?php\nclass FooTest {}\n"
        result = analyze(simple(), lambda _: php)
        self.assertEqual(result["repeated_file_paths"], 1)
        self.assertEqual(result["additional_repeated_file_invocations"], 1)
        self.assertFalse(result["contexts"][0]["source_signals"]["refresh_database"])
        self.assertFalse(result["contexts"][0]["safe_to_remove"])

    def test_repeated_context_names_remain_in_execution_order(self):
        result = analyze(simple(), lambda _: "<?php\n")
        self.assertEqual(result["contexts"][0]["named_regression_contexts"],
                         ["First regression", "Second regression"])

    def test_changing_order_changes_fingerprint_not_permissions(self):
        before = analyze(simple(), lambda _: "<?php\n")
        after = analyze(simple((
            ("Third regression", "tests/Unit/BarTest.php"),
            ("Second regression", "tests/Feature/FooTest.php"),
            ("First regression", "tests/Feature/FooTest.php"),
        )), lambda _: "<?php\n")
        self.assertNotEqual(before["invocation_fingerprint_sha256"],
                            after["invocation_fingerprint_sha256"])
        self.assertFalse(after["permission_to_deduplicate_or_reorder"])

    def test_different_files_not_reported_as_duplicate(self):
        data = analyze(simple((
            ("First regression", "tests/Feature/A.php"),
            ("Second regression", "tests/Feature/B.php"),
        )), lambda _: "<?php\n")
        self.assertEqual(data["repeated_file_paths"], 0)
        self.assertEqual(data["additional_repeated_file_invocations"], 0)

    def test_unknown_php_command_options_fail_closed(self):
        with self.assertRaises(IncompleteEvidence):
            analyze(simple((("Invalid", "--filter=example tests/Feature/A.php"),)), lambda _: "<?php\n")

    def test_traversal_or_shell_operator_fails_closed(self):
        for arg in ("../private.php", "tests/Feature/A.php && echo unsafe",
                    "tests/Feature/A.php;echo"):
            with self.subTest(arg=arg):
                with self.assertRaises(IncompleteEvidence):
                    analyze(simple((("Invalid", arg),)), lambda _: "<?php\n")

    def test_missing_step_identity_fails_closed(self):
        with self.assertRaises(IncompleteEvidence):
            analyze("        run: php artisan test tests/Feature/A.php", lambda _: "<?php\n")

    def test_missing_and_oversized_source_fails_closed(self):
        def broken(_):
            raise IncompleteEvidence("missing test")
        with self.assertRaises(IncompleteEvidence):
            analyze(simple(), broken)
        with self.assertRaises(IncompleteEvidence):
            analyze(simple(), lambda _: "not a PHP source")
        with self.assertRaises(IncompleteEvidence):
            analyze(simple(), lambda _: "<?php\n" + "x" * 256_001)

    def test_reader_rejects_unsafe_paths(self):
        for path in ("../secret.php", "tests/Feature/../../secret.php",
                     "tests/Feature/NoSuchFile.php"):
            with self.subTest(path=path), self.assertRaises(IncompleteEvidence):
                read_php(path)

    def test_cli_reports_json_without_source_or_mutations(self):
        script = ROOT / "scripts/ops/platform_workspace_repeat_context.py"
        p = subprocess.run([sys.executable, str(script)], capture_output=True,
                           text=True, timeout=25)
        self.assertEqual(p.returncode, 0, p.stderr)
        result = json.loads(p.stdout)
        self.assertEqual(result["repeated_file_paths"], 21)
        self.assertEqual(result["additional_repeated_file_invocations"], 42)
        self.assertNotIn("RefreshDatabase;", p.stdout)
        self.assertFalse(result["release_authorized"])


if __name__ == "__main__":
    unittest.main()
