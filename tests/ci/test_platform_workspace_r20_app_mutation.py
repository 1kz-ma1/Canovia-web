"""Offline R20 controller-mutation contract: source integrity and JUnit gates."""
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
from scripts.ci.platform_workspace_r20_app_mutation import (
    EXPECTED_CLASS, GROUPS, Inconclusive, MAX_RUNS, METHODS, MUTATIONS, ROOT as SCRIPT_ROOT,
    SHADOW, SOURCE, TARGET, checked_outcome, collect, isolated_environment,
    mutate, parse_mutated_junit,
)


def xml(classes, failure=None, missing=None, skip=None, extra=False):
    cases = []
    for cls in classes:
        for method in METHODS if cls == TARGET else ("test_fixture",):
            if (cls, method) == missing:
                continue
            mark = "<error/>" if (cls, method) == failure else (
                "<skipped/>" if (cls, method) == skip else ""
            )
            cases.append(f'<testcase classname="Tests\Feature\{cls}" name="{method}">{mark}</testcase>')
    if extra:
        cases.append('<testcase classname="Tests\Feature\SurpriseTest" name="test_extra"/>')
    return "<testsuites><testsuite>" + "".join(cases) + "</testsuite></testsuites>"


class AppMutationContracts(unittest.TestCase):
    def report(self, content):
        t = tempfile.TemporaryDirectory()
        self.addCleanup(t.cleanup)
        output = Path(t.name) / "junit.xml"
        output.write_text(content, encoding="utf-8")
        return output

    def test_exact_two_controller_mutations_only_in_memory(self):
        original = SOURCE.read_bytes()
        self.assertEqual(len(MUTATIONS), 2)
        self.assertEqual(MAX_RUNS, 18)
        for key, _, _, _ in MUTATIONS:
            with self.subTest(key=key):
                modified = mutate(original, key)
                self.assertNotEqual(modified, original)
                self.assertEqual(modified, original.replace(
                    next(item[2] for item in MUTATIONS if item[0] == key).encode(),
                    next(item[3] for item in MUTATIONS if item[0] == key).encode(), 1,
                ))
        self.assertEqual(SOURCE.read_bytes(), original)

    def test_incomplete_source_or_changed_anchor_fails_closed(self):
        original = SOURCE.read_bytes()
        with self.assertRaises(Inconclusive):
            mutate(original, "unlisted_mutation")
        with self.assertRaisesRegex(Inconclusive, "anchor"):
            mutate(original.replace(MUTATIONS[0][2].encode(), b"irrelevant", 1), MUTATIONS[0][0])
        with self.assertRaises(Inconclusive):
            mutate(b"<?php\nclass Other {}\n", MUTATIONS[0][0])

    def test_junit_exact_injected_failure_detected(self):
        classes = GROUPS[1][1]
        target = MUTATIONS[0][1]
        fixture = self.report(xml(classes, (TARGET, target)))
        inventory, failures = parse_mutated_junit(fixture)
        self.assertEqual(len(failures), 1)
        result = checked_outcome(fixture, 1, inventory, target)
        self.assertEqual(result["failure_count"], 1)
        self.assertEqual(result["failing_classes"], [TARGET])

    def test_wrong_target_failure_rejected_even_if_other_class_fails(self):
        classes = GROUPS[1][1]
        fixture = self.report(xml(classes, (classes[0], "test_fixture")))
        baseline, _ = parse_mutated_junit(fixture)
        with self.assertRaisesRegex(Inconclusive, "expected application"):
            checked_outcome(fixture, 1, baseline, MUTATIONS[0][1])

    def test_failure_exit_and_junit_must_agree(self):
        classes = GROUPS[1][1]
        fixture = self.report(xml(classes, (TARGET, MUTATIONS[0][1])))
        baseline, _ = parse_mutated_junit(fixture)
        with self.assertRaisesRegex(Inconclusive, "exit disagree"):
            checked_outcome(fixture, 0, baseline, MUTATIONS[0][1])
        success = self.report(xml(classes))
        with self.assertRaisesRegex(Inconclusive, "exit disagree"):
            checked_outcome(success, 1, baseline, MUTATIONS[0][1])

    def test_missing_skipped_and_unexpected_class_rejected(self):
        classes = GROUPS[1][1]
        original = self.report(xml(classes))
        baseline, _ = parse_mutated_junit(original)
        for opts in (
            {"missing": (TARGET, MUTATIONS[0][1])},
            {"skip": (TARGET, MUTATIONS[0][1])},
            {"extra": True},
        ):
            with self.subTest(opts=opts), self.assertRaises(Inconclusive):
                fixture = self.report(xml(classes, (TARGET, MUTATIONS[0][1]), **opts))
                checked_outcome(fixture, 1, baseline, MUTATIONS[0][1])

    def test_doctype_and_duplicate_identity_rejected(self):
        with self.assertRaisesRegex(Inconclusive, "unsafe XML"):
            parse_mutated_junit(self.report('<!DOCTYPE x><testsuite></testsuite>'))
        item = '<testcase classname="Tests\Feature\Foo" name="test_a"/>'
        with self.assertRaisesRegex(Inconclusive, "duplicate"):
            parse_mutated_junit(self.report("<testsuite>" + item + item + "</testsuite>"))

    def test_shadow_can_survive_or_detect_via_other_test(self):
        baseline_path = self.report(xml(SHADOW))
        baseline, _ = parse_mutated_junit(baseline_path)
        survivor = checked_outcome(baseline_path, 0, baseline, None)
        self.assertEqual(survivor["failure_count"], 0)
        killed = self.report(xml(SHADOW, (SHADOW[0], "test_fixture")))
        observed = checked_outcome(killed, 1, baseline, None)
        self.assertEqual(observed["failure_count"], 1)
        self.assertEqual(observed["failing_classes"], [SHADOW[0]])

    def test_disposable_environment_must_be_pr_to_main(self):
        with mock.patch.dict(os.environ, {
            "GITHUB_ACTIONS": "true", "CI": "true", "GITHUB_EVENT_NAME": "pull_request",
            "GITHUB_BASE_REF": "main", "GITHUB_WORKSPACE": str(SCRIPT_ROOT),
        }):
            self.assertTrue(isolated_environment())
            for key in ("GITHUB_BASE_REF", "GITHUB_EVENT_NAME", "GITHUB_WORKSPACE", "CI"):
                with mock.patch.dict(os.environ, {key: "wrong"}):
                    self.assertFalse(isolated_environment())
        with mock.patch.dict(os.environ, {"GITHUB_ACTIONS": "false"}):
            self.assertFalse(isolated_environment())

    def test_temporary_app_source_restored_after_success(self):
        self._collect_synthetic(fail=False)

    def test_temporary_app_source_restored_after_failing_mutation_probe(self):
        self._collect_synthetic(fail=True)

    def _collect_synthetic(self, fail):
        original = SOURCE.read_bytes()
        with tempfile.TemporaryDirectory() as tmp:
            scratch = Path(tmp) / "PlanActionDraftController.php"
            scratch.write_bytes(original)
            mutated_keys = []
            def fake_runner(command, output):
                filenames = tuple(Path(p).stem for p in command[command.index(str(output))+1:])
                output.write_text(xml(filenames), encoding="utf-8")
                return .5
            def fake_probe(classes, output, baseline, method):
                observed_source = scratch.read_bytes()
                key = next(item[0] for item in MUTATIONS if item[1] == method) if method else (
                    next((item[0] for item in MUTATIONS
                          if mutate(original, item[0]) == observed_source), None)
                )
                self.assertIsNotNone(key)
                self.assertEqual(observed_source, mutate(original, key))
                mutated_keys.append(key)
                if fail:
                    raise Inconclusive("synthetic controller mutation failure")
                fixture = xml(classes, (TARGET, method) if method else None)
                output.write_text(fixture, encoding="utf-8")
                return checked_outcome(output, 1 if method else 0, baseline, method)

            overrides={
                "GITHUB_ACTIONS":"true", "CI":"true",
                "GITHUB_EVENT_NAME":"pull_request", "GITHUB_BASE_REF":"main",
                "GITHUB_WORKSPACE":str(ROOT),
            }
            with mock.patch.dict(os.environ, overrides), \
                 mock.patch("scripts.ci.platform_workspace_r20_app_mutation.SOURCE", scratch), \
                 mock.patch("scripts.ci.platform_workspace_r20_app_mutation.runner", side_effect=fake_runner), \
                 mock.patch("scripts.ci.platform_workspace_r20_app_mutation.run_mutated", side_effect=fake_probe):
                if fail:
                    with self.assertRaisesRegex(Inconclusive, "synthetic controller"):
                        collect()
                else:
                    result = collect()
                    self.assertEqual(result["result"], "OBSERVED_SCOPED_APPLICATION_MUTANT_DETECTION")
                    self.assertEqual(result["independent_php_processes"], 18)
                    self.assertEqual(result["expected_target_detections"], 10)
                    self.assertFalse(result["permission_to_remove_repeated_invocations"])
                    self.assertFalse(result["production_release_authorized"])
                    self.assertFalse(result["app_mutations_committed"])
                    self.assertEqual(len(mutated_keys), 12)
            self.assertEqual(scratch.read_bytes(), original)
            self.assertEqual(SOURCE.read_bytes(), original)

    def test_cli_requires_opt_in_without_mutation(self):
        p = subprocess.run(
            [sys.executable, str(ROOT / "scripts/ci/platform_workspace_r20_app_mutation.py")],
            cwd=ROOT, capture_output=True, text=True, timeout=10,
        )
        self.assertEqual(p.returncode, 2)
        report = json.loads(p.stdout)
        self.assertEqual(report["result"], "BLOCK")
        self.assertFalse(report["permission_to_remove_repeated_invocations"])


if __name__ == "__main__":
    unittest.main()
