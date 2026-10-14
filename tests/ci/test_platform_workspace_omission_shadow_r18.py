"""R18 offline fail-closed tests for one scoped Plan Draft shadow omission."""
from __future__ import annotations

import json
import os
from pathlib import Path
import subprocess
import sys
import unittest
from unittest import mock

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT))
from scripts.ci.platform_workspace_omission_shadow_r18 import (
    EXPECTED_GROUP, EXPECTED_OTHERS, STEP, TARGET, VARIANTS,
    WORKFLOW, Inconclusive, collect, summarize, validate_scope,
)


def xml(files, *, target_missing=False, fail=False, extra=False, drop=False, rename=False):
    cases = []
    for path in files:
        cls = Path(path).stem
        if target_missing and cls == TARGET:
            continue
        if drop and cls.endswith("V5872Test"):
            continue
        for method in (("test_a", "test_b") if cls == TARGET else ("test_fixture",)):
            cases.append('<testcase classname="Tests\\Feature\\' + cls
                         + '" name="' + ("test_changed" if rename and cls.endswith("V5872Test") else method) + '">'
                         + ("<failure/>" if fail else "")
                         + "</testcase>")
    if extra:
        cases.append('<testcase classname="Tests\\Feature\\SurpriseTest" name="test_extra"/>')
    return "<testsuites><testsuite>" + "".join(cases) + "</testsuite></testsuites>"


class OmissionShadowTests(unittest.TestCase):
    def replay(self, mutation=None):
        calls = []

        def fake_runner(command, junit):
            self.assertEqual(command[:3], ["php", "artisan", "test"])
            self.assertIn("--without-tty", command)
            offset = command.index("--log-junit")
            self.assertEqual(command[offset + 1], str(junit))
            files = tuple(command[offset + 2:])
            label = junit.stem
            calls.append((label, files))
            opts = mutation(label, files) if mutation else {}
            opts = opts or {}
            if not opts.get("no_report"):
                junit.write_text(xml(
                    files, target_missing=opts.get("target_missing", False),
                    fail=opts.get("fail", False),
                    extra=opts.get("extra", False),
                    drop=opts.get("drop", False),
                    rename=opts.get("rename", False),
                ), encoding="utf-8")
            return opts.get("elapsed", 0.5)

        with mock.patch.dict(os.environ, {"GITHUB_ACTIONS": "true"}), mock.patch(
            "scripts.ci.platform_workspace_omission_shadow_r18.runner",
            side_effect=fake_runner,
        ):
            result = collect()
        return result, calls

    def test_real_named_group_has_five_preserved_v5871_occurrences(self):
        validate_scope(WORKFLOW.read_text(encoding="utf-8"))
        self.assertEqual(EXPECTED_OTHERS, 4)
        self.assertEqual(VARIANTS, ("original", "omitted_shadow", "restored_original"))

    def test_three_distinct_php_process_commands_and_scoped_gap(self):
        report, calls = self.replay()
        self.assertEqual(report["result"], "OBSERVED_SCOPED_OMISSION_GAP")
        self.assertEqual(report["source_named_regression_step"], STEP)
        self.assertEqual(calls, [
            ("original", EXPECTED_GROUP),
            ("omitted_shadow", EXPECTED_GROUP[:-1]),
            ("restored_original", EXPECTED_GROUP),
        ])
        self.assertEqual(report["fresh_php_process_count"], 3)
        self.assertEqual([report[k] for k in (
            "full_testcases", "shadow_testcases", "restored_testcases",
            "observed_missing_case_count",
        )], [5, 3, 5, 2])
        self.assertEqual(report["observed_missing_testcase_methods"], ["test_a", "test_b"])
        self.assertTrue(report["retained_testcase_identity_parity_observed"])
        self.assertTrue(report["restored_inventory_and_class_order_parity_observed"])
        self.assertEqual(report["remaining_other_named_v5871_invocations_unchanged"], 4)
        for key in ("permission_to_remove_repeated_invocations",
                    "production_release_authorized", "safe_to_remove",
                    "cross_context_assertion_equivalence_proven",
                    "state_boundary_equivalence_proven"):
            self.assertFalse(report[key])

    def test_scope_change_in_named_step_fails_closed(self):
        original = WORKFLOW.read_text(encoding="utf-8")
        modified = original.replace("      - name: " + STEP,
                                    "      - name: Changed step title")
        with self.assertRaisesRegex(Inconclusive, "named regression"):
            validate_scope(modified)

    def test_other_repeat_context_removed_fails_closed(self):
        original = WORKFLOW.read_text(encoding="utf-8")
        modified = original.replace(
            "tests/Feature/PlanActionDraftV5871Test.php",
            "tests/Feature/PlanActionDraftV5872Test.php", 1,
        )
        with self.assertRaises(Inconclusive):
            validate_scope(modified)

    def test_omitted_shadow_changed_retained_cases_blocks(self):
        with self.assertRaisesRegex(Inconclusive, "remaining JUnit"):
            self.replay(lambda label, _: {"rename": True}
                        if label == "omitted_shadow" else {})

    def test_omitted_shadow_adds_unexpected_class_blocks(self):
        with self.assertRaisesRegex(Inconclusive, "retained classes"):
            self.replay(lambda label, _: {"extra": True}
                        if label == "omitted_shadow" else {})

    def test_original_missing_selected_class_blocks(self):
        with self.assertRaisesRegex(Inconclusive, "full group"):
            self.replay(lambda label, _: {"target_missing": True}
                        if label == "original" else {})

    def test_restored_drift_blocks(self):
        with self.assertRaisesRegex(Inconclusive, "restored"):
            self.replay(lambda label, _: {"target_missing": True}
                        if label == "restored_original" else {})

    def test_junit_failure_blocks_even_on_shadow_only(self):
        with self.assertRaisesRegex(Inconclusive, "failed or skipped"):
            self.replay(lambda label, _: {"fail": True}
                        if label == "omitted_shadow" else {})

    def test_missing_junit_blocks(self):
        with self.assertRaisesRegex(Inconclusive, "missing or oversized"):
            self.replay(lambda label, _: {"no_report": True}
                        if label == "original" else {})

    def test_invalid_timing_blocks(self):
        for bad in (float("nan"), float("inf"), -1.0):
            with self.subTest(bad=str(bad)), self.assertRaisesRegex(
                Inconclusive, "duration"
            ):
                self.replay(lambda label, _: {"elapsed": bad})

    def test_read_only_default_cli_blocks(self):
        p = subprocess.run([
            sys.executable, str(ROOT / "scripts/ci/platform_workspace_omission_shadow_r18.py")
        ], cwd=ROOT, text=True, capture_output=True, timeout=15)
        self.assertEqual(p.returncode, 2)
        report = json.loads(p.stdout)
        self.assertEqual(report["result"], "BLOCK")
        self.assertFalse(report["permission_to_remove_repeated_invocations"])

    def test_no_github_actions_blocks_even_when_called_directly(self):
        with mock.patch.dict(os.environ, {"GITHUB_ACTIONS": "false"}):
            with self.assertRaisesRegex(Inconclusive, "GitHub Actions"):
                collect()


if __name__ == "__main__":
    unittest.main()
