"""R21 offline contracts: ten app-mutant context cells and five leave-one-out cases."""
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
from scripts.ci.platform_workspace_r21_five_context_matrix import (
    EXPECTED_PHP_RUNS, GROUPS, METHODS, MUTATIONS, SOURCE, TARGET, WORKFLOW,
    Inconclusive, baseline_membership, collect, compare_baselines,
    shadow_groups, summarize_matrix, validate_matrix_source,
)
from scripts.ci.platform_workspace_r20_app_mutation import mutate


def make_inventory(classes):
    return tuple(
        ("Tests\\Feature\\" + cls, name)
        for cls in classes
        for name in (METHODS if cls == TARGET else ("test_neighbour",))
    )


def observations(full_bad=False, shadow_detected=False):
    out = []
    for mindex in range(2):
        rows = []
        for j in range(5):
            full = {
                "target_method_detected": not(full_bad and mindex == 0 and j == 0),
                "failure_count": 0 if full_bad and mindex == 0 and j == 0 else 1,
                "failing_classes": [] if full_bad and mindex == 0 and j == 0 else [TARGET],
            }
            shadow_hit = shadow_detected and j == mindex
            shadow = {
                "target_method_detected": False,
                "failure_count": int(shadow_hit),
                "failing_classes": [shadow_groups()[j][0]] if shadow_hit else [],
            }
            rows.append({"full": full, "shadow": shadow})
        out.append(tuple(rows))
    return tuple(out)


class R21MatrixContractTests(unittest.TestCase):
    def test_real_source_scope_all_five_named_contexts(self):
        validate_matrix_source(WORKFLOW.read_text(encoding="utf-8"), SOURCE.read_bytes())
        self.assertEqual(EXPECTED_PHP_RUNS, 30)
        self.assertEqual(len(shadow_groups()), 5)
        self.assertEqual(len({tuple(group) for group in shadow_groups()}), 5)
        self.assertTrue(all(TARGET not in group for group in shadow_groups()))
        self.assertEqual(len(MUTATIONS), 2)

    def test_missing_historical_context_blocks(self):
        source = WORKFLOW.read_text(encoding="utf-8")
        modified = source.replace("      - name: " + GROUPS[2][0],
                                  "      - name: Broken experiment", 1)
        with self.assertRaises(Inconclusive):
            validate_matrix_source(modified, SOURCE.read_bytes())

    def test_missing_controller_mutation_anchor_blocks(self):
        original = SOURCE.read_bytes()
        altered = original.replace(MUTATIONS[0][2].encode("utf-8"), b"changed anchor", 1)
        with self.assertRaisesRegex(Inconclusive, "anchor"):
            validate_matrix_source(WORKFLOW.read_text(encoding="utf-8"), altered)

    def test_baseline_exact_classes_and_methods(self):
        for j, (_, group) in enumerate(GROUPS):
            full = make_inventory(group)
            shadow = make_inventory(shadow_groups()[j])
            baseline_membership(full, group, shadow=False)
            baseline_membership(shadow, shadow_groups()[j], shadow=True)
            compare_baselines(full, shadow)

    def test_baseline_missing_target_case_blocks(self):
        files = GROUPS[0][1]
        baseline = make_inventory(files)
        filtered = tuple(entry for entry in baseline if entry[1] != METHODS[0])
        with self.assertRaisesRegex(Inconclusive, "method"):
            baseline_membership(filtered, files, shadow=False)

    def test_baseline_retained_identity_drift_blocks(self):
        group = GROUPS[0][1]
        actual = list(make_inventory(shadow_groups()[0]))
        actual[-1] = (actual[-1][0], "wrong_case")
        with self.assertRaisesRegex(Inconclusive, "retained"):
            compare_baselines(make_inventory(group), tuple(actual))

    def test_all_green_shadows_still_cannot_approve_removal(self):
        report = summarize_matrix(observations())
        self.assertEqual(report["intact_context_detection_count"], 10)
        self.assertEqual(report["total_omitted_contexts_detecting"], 0)
        self.assertEqual(report["total_omitted_contexts_not_detecting"], 10)
        for mutation in report["mutation_results"]:
            self.assertTrue(mutation["all_five_full_contexts_detected"])
            self.assertTrue(mutation["every_one_group_omission_retains_four_other_detecting_contexts"])
            self.assertEqual(
                [r["simulated_five_step_corridor_detection_count"]
                 for r in mutation["named_contexts"]],
                [4, 4, 4, 4, 4],
            )

    def test_neighbor_detection_reported_not_hidden(self):
        report = summarize_matrix(observations(shadow_detected=True))
        self.assertEqual(report["total_omitted_contexts_detecting"], 2)
        self.assertEqual(report["total_omitted_contexts_not_detecting"], 8)
        for mutation in report["mutation_results"]:
            self.assertEqual(sum(x["shadow_without_v5871_detected"]
                                 for x in mutation["named_contexts"]), 1)
            self.assertEqual(sum(x["simulated_five_step_corridor_detection_count"] == 5
                                 for x in mutation["named_contexts"]), 1)

    def test_missing_target_detection_blocks(self):
        with self.assertRaisesRegex(Inconclusive, "real mutation"):
            summarize_matrix(observations(full_bad=True))

    def test_spoofed_target_reappearance_blocks(self):
        raw = [[dict(cell) for cell in row] for row in observations()]
        raw[0][2]["shadow"] = {
            "target_method_detected": False,
            "failure_count": 1,
            "failing_classes": [TARGET],
        }
        with self.assertRaisesRegex(Inconclusive, "target class reappeared"):
            summarize_matrix(tuple(tuple(row) for row in raw))

    def test_incomplete_matrix_blocks(self):
        with self.assertRaisesRegex(Inconclusive, "missing application"):
            summarize_matrix(observations()[:1])
        with self.assertRaisesRegex(Inconclusive, "incomplete named"):
            summarize_matrix((observations()[0][:-1], observations()[1]))

    def test_real_source_restored_after_synthetic_success(self):
        self._run_mocked(fail=False)

    def test_real_source_restored_after_synthetic_failure(self):
        self._run_mocked(fail=True)

    def _run_mocked(self, fail: bool):
        original = SOURCE.read_bytes()
        with tempfile.TemporaryDirectory() as tmp:
            temporary_controller = Path(tmp) / "PlanActionDraftController.php"
            temporary_controller.write_bytes(original)
            seen = []

            def fake_runner(command, junit_file):
                classes = tuple(Path(x).stem for x in
                                command[command.index(str(junit_file))+1:])
                expected = make_inventory(classes)
                lines = [
                    f'<testcase classname="{cls}" name="{method}"/>'
                    for cls, method in expected
                ]
                junit_file.write_text("<testsuite>"+"".join(lines)+"</testsuite>",
                                      encoding="utf-8")
                return 0.1

            def fake_mutated(classes, junit_file, baseline, method):
                current = temporary_controller.read_bytes()
                self.assertTrue(any(mutate(original, key) == current for key, _, _, _ in MUTATIONS))
                seen.append((classes, method))
                if fail:
                    raise Inconclusive("synthetic injected process failure")
                return {
                    "target_method_detected": method is not None,
                    "failure_count": 1 if method else 0,
                    "failing_classes": [TARGET] if method else [],
                }

            env = {
                "GITHUB_ACTIONS": "true", "CI": "true",
                "GITHUB_EVENT_NAME": "pull_request",
                "GITHUB_BASE_REF": "main", "GITHUB_WORKSPACE": str(ROOT),
            }
            with mock.patch.dict(os.environ, env), \
                 mock.patch("scripts.ci.platform_workspace_r21_five_context_matrix.SOURCE",
                            temporary_controller), \
                 mock.patch("scripts.ci.platform_workspace_r21_five_context_matrix.runner",
                            side_effect=fake_runner), \
                 mock.patch("scripts.ci.platform_workspace_r21_five_context_matrix.run_mutated",
                            side_effect=fake_mutated):
                if fail:
                    with self.assertRaisesRegex(Inconclusive, "synthetic injected"):
                        collect()
                else:
                    out = collect()
                    self.assertEqual(out["result"], "OBSERVED_FIVE_CONTEXT_LEAVE_ONE_OUT_MATRIX")
                    self.assertEqual(out["independent_php_processes"], 30)
                    self.assertEqual(out["intact_context_detection_count"], 10)
                    self.assertEqual(out["total_omitted_contexts_not_detecting"], 10)
                    self.assertFalse(out["permission_to_remove_repeated_invocations"])
                    self.assertFalse(out["complete_workspace_resume_equivalence_proven"])
                    self.assertFalse(out["cross_context_state_isolation_proven"])
                    self.assertFalse(out["production_release_authorized"])
                    self.assertEqual(len(seen), 20)
            self.assertEqual(temporary_controller.read_bytes(), original)
            self.assertEqual(SOURCE.read_bytes(), original)

    def test_not_in_disposable_runner_fails_closed(self):
        with mock.patch.dict(os.environ, {
            "GITHUB_ACTIONS": "true", "CI": "true",
            "GITHUB_EVENT_NAME": "workflow_dispatch",
            "GITHUB_BASE_REF": "main", "GITHUB_WORKSPACE": str(ROOT),
        }):
            with self.assertRaisesRegex(Inconclusive, "ephemeral"):
                collect()

    def test_read_only_default_cli_blocks(self):
        proc = subprocess.run([
            sys.executable, str(ROOT / "scripts/ci/platform_workspace_r21_five_context_matrix.py")
        ], cwd=ROOT, capture_output=True, text=True, timeout=15)
        self.assertEqual(proc.returncode, 2)
        record = json.loads(proc.stdout)
        self.assertEqual(record["result"], "BLOCK")
        self.assertFalse(record["permission_to_remove_repeated_invocations"])


if __name__ == "__main__":
    unittest.main()
