"""R23 offline synthetic failure guards, exact sources and restoration."""
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
import scripts.ci.platform_workspace_r23_creative_rescue_shadow as r23

PREFIX = "Tests\\Feature\\"


def cases(classes):
    return tuple((PREFIX + name, method)
                 for name in classes
                 for method in (r23.METHODS if name == r23.TARGET else ("test_fixture",)))


def make_xml(classes):
    return ("<testsuite>" + "".join(
        f'<testcase classname="{name}" name="{method}"/>'
        for name, method in cases(classes)
    ) + "</testsuite>")


class CreativeRescueR23Tests(unittest.TestCase):
    def junit(self, data):
        temporary = tempfile.TemporaryDirectory()
        self.addCleanup(temporary.cleanup)
        path = Path(temporary.name) / "cases.xml"
        path.write_text(data, encoding="utf-8")
        return path

    def test_real_exact_two_regression_contexts_and_four_methods(self):
        r23.manifest(r23.WORKFLOW.read_text(encoding="utf-8"))
        r23.source_test_contract(r23.TEST_SOURCE.read_bytes())
        self.assertEqual(r23.PLANNED_PHP_RUNS, 14)
        self.assertEqual(len(r23.GROUPS), 2)
        self.assertEqual(len(r23.METHODS), 4)
        self.assertEqual(len(r23.MUTATIONS), 2)
        self.assertTrue(all(r23.TARGET in group for _, group in r23.GROUPS))

    def test_historical_named_context_or_companion_drift_rejected(self):
        original = r23.WORKFLOW.read_text(encoding="utf-8")
        for label, classes in r23.GROUPS:
            text = original.replace("      - name: " + label,
                                    "      - name: R23 renamed context", 1)
            with self.subTest(label=label), self.assertRaises(r23.Inconclusive):
                r23.manifest(text)
            target = " ".join("tests/Feature/" + x + ".php" for x in classes)
            modified = original.replace(target, target.replace(
                "tests/Feature/" + r23.TARGET + ".php", ""), 1)
            with self.subTest(label=label+"_files"), self.assertRaises(r23.Inconclusive):
                r23.manifest(modified)

    def test_source_assertion_security_and_state_guards(self):
        original = r23.TEST_SOURCE.read_bytes()
        r23.source_test_contract(original)
        damaged = original.replace(
            b"use RefreshDatabase;", b"use OtherThing;", 1
        )
        with self.assertRaisesRegex(r23.Inconclusive, "state baseline"):
            r23.source_test_contract(damaged)
        missing_security = original.replace(b"->assertForbidden()", b"->assertOk()", 1)
        with self.assertRaisesRegex(r23.Inconclusive, "auth/state"):
            r23.source_test_contract(missing_security)

    def test_mutation_anchors_exact_and_read_only_to_real_source(self):
        initial = r23.SOURCE.read_bytes()
        for key, _, old, new in r23.MUTATIONS:
            with self.subTest(key=key):
                modified = r23.mutate(initial, key)
                self.assertNotEqual(initial, modified)
                self.assertEqual(modified, initial.replace(old.encode(), new.encode(), 1))
        self.assertEqual(r23.SOURCE.read_bytes(), initial)

    def test_unknown_or_double_mutation_fails_closed(self):
        initial = r23.SOURCE.read_bytes()
        with self.assertRaises(r23.Inconclusive):
            r23.mutate(initial, "not_allowlisted")
        with self.assertRaisesRegex(r23.Inconclusive, "pre-mutated"):
            r23.mutate(r23.mutate(initial, r23.MUTATIONS[0][0]), r23.MUTATIONS[1][0])
        with self.assertRaises(r23.Inconclusive):
            r23.mutate(b"<?php class Fake {}", r23.MUTATIONS[0][0])

    def test_a_b_a_prime_checks_all_retained_case_identities(self):
        for label, full in r23.GROUPS:
            shadow = tuple(x for x in full if x != r23.TARGET)
            a, b = cases(full), cases(shadow)
            with self.subTest(label=label):
                r23.A_B_A_prime(a, b, a, full, shadow)
                with self.assertRaisesRegex(r23.Inconclusive, "neighboring"):
                    r23.A_B_A_prime(a, b[:-1], a, full, shadow)

    def test_wrong_order_or_missing_target_case_is_blocked(self):
        group = r23.GROUPS[0][1]
        shadow = tuple(x for x in group if x != r23.TARGET)
        a, b = cases(group), cases(shadow)
        with self.assertRaisesRegex(r23.Inconclusive, "restored"):
            r23.A_B_A_prime(a, b, tuple(reversed(a)), group, shadow)
        with self.assertRaisesRegex(r23.Inconclusive, "method coverage"):
            r23.A_B_A_prime(a[:-1], b, a[:-1], group, shadow)

    def test_junit_expected_target_method_must_fail_in_full_group(self):
        group = r23.GROUPS[0][1]
        original = cases(group)
        bad = self.junit(make_xml(group))
        with mock.patch.object(r23.subprocess, "run", return_value=mock.Mock(returncode=0)):
            with self.assertRaisesRegex(r23.Inconclusive, "undetected"):
                r23.mutated_outcome(group, bad, original, r23.MUTATIONS[0][1])

    def test_mutant_results_refuse_changed_junit_inventory(self):
        group = r23.GROUPS[0][1]
        bad = self.junit(make_xml((r23.TARGET,)))
        with mock.patch.object(r23.subprocess, "run", return_value=mock.Mock(returncode=1)):
            with self.assertRaisesRegex(r23.Inconclusive, "identity drift"):
                r23.mutated_outcome(group, bad, cases(group), r23.MUTATIONS[0][1])

    def test_positive_mutant_detection_in_target_and_shadow_does_not_approve(self):
        self._trial(failure=False, neighbor_detected=True)

    def test_positive_mutant_detection_only_in_target_keeps_hold(self):
        self._trial(failure=False, neighbor_detected=False)

    def test_failure_during_mutation_still_restores_application_source(self):
        self._trial(failure=True, neighbor_detected=False)

    def _trial(self, failure: bool, neighbor_detected: bool):
        original = r23.SOURCE.read_bytes()
        with tempfile.TemporaryDirectory() as temp:
            scratch = Path(temp) / "DevelopmentCreativePlanSelectionController.php"
            scratch.write_bytes(original)
            seen = []

            def fake_runner(command, out):
                named = tuple(Path(p).stem for p in command[command.index(str(out)) + 1:])
                out.write_text(make_xml(named), encoding="utf-8")
                return 0.33

            def fake_mutated(classes, output, baseline, required_target):
                source = scratch.read_bytes()
                self.assertTrue(any(source == r23.mutate(original, key)
                                    for key, _, _, _ in r23.MUTATIONS))
                seen.append((classes, required_target))
                if failure:
                    raise r23.Inconclusive("synthetic R23 mid-mutant failure")
                if required_target:
                    return {"failed_testcases": 1, "failing_class_names": [r23.TARGET],
                            "v5863_expected_method_detected": True}
                return {
                    "failed_testcases": int(neighbor_detected),
                    "failing_class_names": ([classes[0]] if neighbor_detected else []),
                    "v5863_expected_method_detected": False,
                }

            env = {"GITHUB_ACTIONS": "true", "CI": "true",
                   "GITHUB_EVENT_NAME": "pull_request", "GITHUB_BASE_REF": "main",
                   "GITHUB_WORKSPACE": str(ROOT)}
            with mock.patch.dict(os.environ, env), \
                 mock.patch.object(r23, "SOURCE", scratch), \
                 mock.patch.object(r23, "runner", side_effect=fake_runner), \
                 mock.patch.object(r23, "mutated_outcome", side_effect=fake_mutated):
                if failure:
                    with self.assertRaisesRegex(r23.Inconclusive, "synthetic R23"):
                        r23.collect()
                else:
                    result = r23.collect()
                    self.assertEqual(result["result"],
                                     "OBSERVED_CREATIVE_RESCUE_CONTEXT_MUTATION_SHADOW")
                    self.assertEqual(result["independent_php_processes"], 14)
                    self.assertEqual(result["historical_named_step_count"], 2)
                    self.assertEqual(result["method_count"], 4)
                    self.assertFalse(result["permission_to_remove_repeated_invocations"])
                    self.assertFalse(result["production_release_authorized"])
                    self.assertFalse(result["saved_runtime_improvement_proven"])
                    self.assertFalse(result["full_workflow_state_equivalence_proven"])
                    self.assertEqual(len(seen), 8)
                    for context in result["contexts"]:
                        self.assertEqual(len(context["controller_mutations"]), 2)
                        self.assertEqual(context["omitted_v5863_testcases"], 4)
                        for mutant in context["controller_mutations"]:
                            self.assertEqual(mutant["omitting_v5863"]["failed_testcases"],
                                             int(neighbor_detected))
            self.assertEqual(scratch.read_bytes(), original)
            self.assertEqual(r23.SOURCE.read_bytes(), original)

    def test_outside_disposable_pull_request_blocks(self):
        with mock.patch.dict(os.environ, {"GITHUB_ACTIONS": "false"}):
            with self.assertRaisesRegex(r23.Inconclusive, "PR-to-main"):
                r23.collect()

    def test_default_cli_blocks_and_never_writes_source(self):
        before = r23.SOURCE.read_bytes()
        p = subprocess.run([sys.executable, str(ROOT / "scripts/ci/platform_workspace_r23_creative_rescue_shadow.py")],
                           cwd=ROOT, capture_output=True, text=True, timeout=15)
        self.assertEqual(p.returncode, 2)
        data = json.loads(p.stdout)
        self.assertEqual(data["result"], "BLOCK")
        self.assertFalse(data["permission_to_remove_repeated_invocations"])
        self.assertEqual(r23.SOURCE.read_bytes(), before)


if __name__ == "__main__":
    unittest.main()
