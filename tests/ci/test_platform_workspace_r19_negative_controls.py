"""No-network R19 negative-control contracts (all source edits are temp-only)."""
from __future__ import annotations

import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest
from unittest import mock

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT))
from scripts.ci.platform_workspace_r19_negative_controls import (
    EXPECTED_PHP_RUNS, GROUPS, METHODS, SOURCE, TARGET, TRIPWIRE, WORKFLOW,
    Inconclusive, check_negative, collect, failed_junit, inject, manifest,
)

CLASS_PREFIX = "Tests\\Feature\\"


def junit(classes, *, failure=None, missing=None, skip=None, duplicate=False):
    cases = []
    for cls in classes:
        for method in (METHODS if cls == TARGET else ("test_fixture",)):
            if (cls, method) == missing:
                continue
            payload = "<error/>" if (cls, method) == failure else (
                "<skipped/>" if (cls, method) == skip else ""
            )
            case = ('<testcase classname="' + CLASS_PREFIX + cls +
                    '" name="' + method + '">' + payload + '</testcase>')
            cases.append(case)
            if duplicate and (cls, method) == failure:
                cases.append(case)
    return ("<testsuites><testsuite>" + "".join(cases) +
            "</testsuite></testsuites>")


class R19NegativeTests(unittest.TestCase):
    def report(self, text):
        tmp = tempfile.TemporaryDirectory()
        self.addCleanup(tmp.cleanup)
        path = Path(tmp.name) / "junit.xml"
        path.write_text(text, encoding="utf-8")
        return path

    def test_original_workflow_groups_are_pinned(self):
        manifest(WORKFLOW.read_text(encoding="utf-8"))
        self.assertEqual(len(GROUPS), 5)
        self.assertEqual(EXPECTED_PHP_RUNS, 30)
        self.assertEqual(len(METHODS), 4)

    def test_scope_change_is_rejected(self):
        source = WORKFLOW.read_text(encoding="utf-8")
        mutated = source.replace("      - name: " + GROUPS[3][0],
                                 "      - name: MISSING R19 step", 1)
        with self.assertRaisesRegex(Inconclusive, "historical named"):
            manifest(mutated)

    def test_injection_is_one_method_only_and_does_not_change_disk(self):
        original = SOURCE.read_bytes()
        for method in METHODS:
            with self.subTest(method=method):
                mutated = inject(original, method)
                self.assertEqual(mutated.count(TRIPWIRE.encode("utf-8")), 1)
                self.assertIn(("public function " + method).encode(), mutated)
                self.assertNotEqual(mutated, original)
        self.assertEqual(SOURCE.read_bytes(), original)

    def test_unknown_method_is_rejected(self):
        with self.assertRaises(Inconclusive):
            inject(SOURCE.read_bytes(), "test_other")
        with self.assertRaises(Inconclusive):
            inject(b"not PHP", METHODS[0])

    def test_method_source_drift_is_rejected(self):
        before = SOURCE.read_bytes()
        changed = before.replace(("public function " + METHODS[0]).encode(),
                                 b"public function test_changed", 1)
        with self.assertRaisesRegex(Inconclusive, "method set drift"):
            inject(changed, METHODS[0])

    def test_expected_target_error_accepted(self):
        group = GROUPS[1][1]
        path = self.report(junit(group, failure=(TARGET, METHODS[0])))
        observed, failure = failed_junit(path)
        self.assertEqual(failure, (CLASS_PREFIX + TARGET, METHODS[0]))
        self.assertEqual(len(observed), 4 + len(group) - 1)
        check_negative(path, observed, METHODS[0])

    def test_unrelated_error_is_rejected(self):
        group = GROUPS[1][1]
        path = self.report(junit(group, failure=(group[0], "test_fixture")))
        from scripts.ci.platform_workspace_r19_negative_controls import check_negative
        with self.assertRaisesRegex(Inconclusive, "wrong failing"):
            check_negative(path, failed_junit(path)[0], METHODS[0])

    def test_unexpected_success_cannot_count_as_negative_control(self):
        group = GROUPS[1][1]
        path = self.report(junit(group))
        with self.assertRaisesRegex(Inconclusive, "failure count"):
            failed_junit(path)

    def test_missing_skipped_and_duplicated_case_rejected(self):
        group = GROUPS[1][1]
        for options in (
            {"missing": (TARGET, METHODS[0])},
            {"skip": (TARGET, METHODS[1])},
            {"duplicate": True},
        ):
            with self.subTest(opts=str(options)):
                path = self.report(junit(group, failure=(TARGET, METHODS[0]),
                                         **options))
                with self.assertRaises(Inconclusive):
                    check_negative(path, tuple((CLASS_PREFIX + cls, method)
                        for cls in group
                        for method in (METHODS if cls == TARGET else ("test_fixture",))),
                        METHODS[0])

    def test_xml_doctype_rejected(self):
        path = self.report('<!DOCTYPE x [ <!ENTITY a "b">]><testsuite></testsuite>')
        with self.assertRaisesRegex(Inconclusive, "unsafe XML"):
            failed_junit(path)

    def test_collection_uses_ephemeral_source_and_restores_after_success(self):
        self._collection_trial(fail=False)

    def test_collection_restores_test_source_even_after_negative_failure(self):
        self._collection_trial(fail=True)

    def _collection_trial(self, *, fail):
        original = SOURCE.read_bytes()
        with tempfile.TemporaryDirectory() as tmp:
            scratch = Path(tmp) / "PlanActionDraftV5871Test.php"
            scratch.write_bytes(original)
            attempts = []
            def fake_runner(command, junit_path):
                # Synthetic all-passing JUnit inventory; no PHP executed.
                files = [Path(p).stem for p in command[command.index(str(junit_path)) + 1:]]
                junit_path.write_text(junit(files), encoding="utf-8")
                return 0.5

            def fake_negative(files, path, baseline, method):
                modified = scratch.read_text(encoding="utf-8")
                self.assertIn(TRIPWIRE, modified)
                self.assertEqual(modified.count(TRIPWIRE), 1)
                self.assertIn("public function " + method + "(): void", modified)
                attempts.append((files, method))
                if fail:
                    raise Inconclusive("synthetic negative test failed")

            with mock.patch.dict(os.environ, {"GITHUB_ACTIONS": "true"}), \
                 mock.patch("scripts.ci.platform_workspace_r19_negative_controls.SOURCE", scratch), \
                 mock.patch("scripts.ci.platform_workspace_r19_negative_controls.runner", side_effect=fake_runner), \
                 mock.patch("scripts.ci.platform_workspace_r19_negative_controls.negative_run", side_effect=fake_negative):
                if fail:
                    with self.assertRaisesRegex(Inconclusive, "synthetic negative"):
                        collect()
                else:
                    result = collect()
                    self.assertEqual(result["result"],
                                     "OBSERVED_FOUR_METHOD_NEGATIVE_CONTROLS")
                    self.assertEqual(result["normal_group_negative_detections"], 20)
                    self.assertEqual(result["omitted_group_negative_non_detections"], 4)
                    self.assertEqual(result["total_independent_php_processes"], 30)
                    self.assertFalse(result["permission_to_remove_repeated_invocations"])
                    self.assertFalse(result["assertion_or_state_semantics_equivalence_proven"])
                    self.assertEqual(len(attempts), 20)
            self.assertEqual(scratch.read_bytes(), original)
            self.assertEqual(SOURCE.read_bytes(), original)

    def test_run_outside_github_actions_is_blocked(self):
        with mock.patch.dict(os.environ, {"GITHUB_ACTIONS": "false"}):
            with self.assertRaisesRegex(Inconclusive, "GitHub Actions"):
                collect()

    def test_default_cli_fails_closed(self):
        result = subprocess.run(
            [sys.executable, str(ROOT / "scripts/ci/platform_workspace_r19_negative_controls.py")],
            cwd=ROOT, capture_output=True, text=True, timeout=15,
        )
        self.assertEqual(result.returncode, 2)
        payload = json.loads(result.stdout)
        self.assertEqual(payload["result"], "BLOCK")
        self.assertFalse(payload["permission_to_remove_repeated_invocations"])


if __name__ == "__main__":
    unittest.main()
