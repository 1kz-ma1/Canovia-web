"""Synthetic no-network contract tests for bounded Workspace group replay."""
from __future__ import annotations

from pathlib import Path
import json
import os
import subprocess
import sys
from unittest import TestCase, main, mock

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT))
from scripts.ci.platform_workspace_group_repeatability import (
    MAX_RUNS, REPETITIONS, VARIANTS, Inconclusive, collect,
)


def junit(files: list[str], *, missing: str = "", reduce_target: bool = False,
          fail: bool = False) -> str:
    items = []
    for path in files:
        cls = Path(path).stem
        if cls == missing:
            continue
        is_target = cls in ("AdaptiveLearningSingleQuestionV5878Test", "PlanActionDraftV5871Test")
        methods = ("test_a",) if (is_target and reduce_target) else (
            ("test_a", "test_b") if is_target else ("test_fixture",)
        )
        for method in methods:
            nested = "<failure/>" if fail and method == "test_a" else ""
            items.append('<testcase classname="Tests\\Feature\\' + cls
                         + '" name="' + method + '">' + nested + "</testcase>")
    return "<testsuites><testsuite>" + "".join(items) + "</testsuite></testsuites>"


class ReplayTests(TestCase):
    def setUp(self):
        self.calls = []

    def replay(self, mutation=None):
        def fake_runner(argv, report):
            self.assertEqual(argv[:3], ["php", "artisan", "test"])
            self.assertIn("--without-tty", argv)
            offset = argv.index("--log-junit")
            self.assertEqual(argv[offset + 1], str(report))
            paths = list(argv[offset + 2:])
            self.assertTrue(all(x.startswith("tests/Feature/") for x in paths))
            self.calls.append((tuple(paths), report.name))
            options = mutation(report.name, paths) if mutation else {}
            options = options or {}
            if options.get("no_report"):
                return 0.25
            display_paths = list(reversed(paths)) if options.get("reverse") else paths
            report.write_text(junit(
                display_paths,
                missing=options.get("missing", ""),
                reduce_target=options.get("reduce_target", False),
                fail=options.get("fail", False),
            ), encoding="utf-8")
            return options.get("elapsed", 0.25)
        with mock.patch.dict(os.environ, {"GITHUB_ACTIONS": "true"}), mock.patch(
            "scripts.ci.platform_workspace_group_repeatability.runner",
            side_effect=fake_runner,
        ):
            return collect()

    def test_three_replays_per_original_and_reverse_scenario(self):
        result = self.replay()
        self.assertEqual(result["result"], "OBSERVED_FIXED_GROUP_REPEATABILITY")
        self.assertEqual(result["total_group_replays"], MAX_RUNS)
        self.assertEqual(REPETITIONS, 3)
        self.assertEqual(VARIANTS, ("original_group", "reverse_group"))
        self.assertEqual(len(self.calls), 12)
        self.assertEqual([x["replay_count"] for x in result["scenarios"]], [6, 6])
        self.assertTrue(all(v["all_repetitions_passed"] for x in result["scenarios"]
                            for v in x["group_variants"]))
        self.assertEqual([x["group_testcases"] for x in result["scenarios"]], [4, 5])
        self.assertFalse(result["permission_to_remove_repeated_invocations"])
        self.assertFalse(result["production_release_authorized"])

    def test_different_actual_execution_order_per_variant(self):
        result = self.replay()
        for scenario in result["scenarios"]:
            original, reverse = scenario["group_variants"]
            self.assertNotEqual(original["observed_class_order"], reverse["observed_class_order"])
            self.assertEqual(original["testcases_per_repeat"], reverse["testcases_per_repeat"])
            self.assertEqual(original["repetitions"], 3)

    def test_one_repetition_missing_one_method_blocks(self):
        def mutate(name, files):
            if "adaptive_single_question_original_group_repeat_2" in name:
                return {"reduce_target": True}
            return {}
        with self.assertRaisesRegex(Inconclusive, "inventory"):
            self.replay(mutate)

    def test_one_repetition_changes_class_order_blocks(self):
        def mutate(name, files):
            if "adaptive_single_question_original_group_repeat_3" in name:
                return {"reverse": True}
            return {}
        with self.assertRaisesRegex(Inconclusive, "order"):
            self.replay(mutate)

    def test_one_variant_omits_a_regression_class_blocks(self):
        def mutate(name, files):
            if "plan_action_draft_reverse_group_repeat_1" in name:
                return {"missing": Path(files[-1]).stem}
            return {}
        with self.assertRaisesRegex(Inconclusive, "missing expected JUnit class"):
            self.replay(mutate)

    def test_repeated_junit_failure_blocks(self):
        def mutate(name, files):
            if "adaptive_single_question_reverse_group_repeat_2" in name:
                return {"fail": True}
            return {}
        with self.assertRaisesRegex(Inconclusive, "failed or skipped"):
            self.replay(mutate)

    def test_missing_junit_report_blocks(self):
        def mutate(name, files):
            if "plan_action_draft_original_group_repeat_2" in name:
                return {"no_report": True}
            return {}
        with self.assertRaisesRegex(Inconclusive, "missing or oversized"):
            self.replay(mutate)

    def test_non_finite_or_negative_duration_blocks(self):
        for duration in (float("nan"), float("inf"), -1.0):
            def mutate(name, files):
                return {"elapsed": duration}
            with self.subTest(duration=str(duration)), self.assertRaisesRegex(Inconclusive, "duration"):
                self.replay(mutate)

    def test_only_expected_four_groups_replayed(self):
        result = self.replay()
        self.assertEqual(len({(x[0], x[1].split("_repeat_")[0])
                              for x in self.calls}), 4)
        for case in result["scenarios"]:
            self.assertEqual({v["variant"] for v in case["group_variants"]}, set(VARIANTS))

    def test_cannot_run_outside_github_actions(self):
        with mock.patch.dict(os.environ, {"GITHUB_ACTIONS": "false"}):
            with self.assertRaisesRegex(Inconclusive, "GitHub Actions"):
                collect()

    def test_default_cli_fails_closed_without_php_or_network(self):
        script = ROOT / "scripts/ci/platform_workspace_group_repeatability.py"
        p = subprocess.run([sys.executable, str(script)], cwd=ROOT,
                           capture_output=True, text=True, timeout=15)
        self.assertEqual(p.returncode, 2)
        payload = json.loads(p.stdout)
        self.assertEqual(payload["result"], "BLOCK")
        self.assertFalse(payload["permission_to_remove_repeated_invocations"])

    def test_durations_report_only_observed_and_no_billing_claim(self):
        result = self.replay()
        for case in result["scenarios"]:
            for variant in case["group_variants"]:
                self.assertEqual(variant["elapsed_min_seconds"], 0.25)
                self.assertEqual(variant["elapsed_median_seconds"], 0.25)
                self.assertEqual(variant["elapsed_max_seconds"], 0.25)
        self.assertFalse(result["repeatability_beyond_this_runner_proven"])
        self.assertFalse(result["assertion_semantics_equivalence_proven"])


    def test_cross_runner_fingerprint_stable_when_timing_differs(self):
        first = self.replay()
        second = self.replay(lambda name, files: {"elapsed": 11.5})
        signature = first["comparison_fingerprint_sha256"]
        self.assertRegex(signature, r"^[a-f0-9]{64}$")
        self.assertEqual(signature, second["comparison_fingerprint_sha256"])
        self.assertFalse(first["permission_to_remove_repeated_invocations"])

    def test_cross_runner_fingerprint_detects_inventory_drift(self):
        first = self.replay()
        # Consistent within runner B, but different from runner A.
        second = self.replay(
            lambda name, files: {"reduce_target": True}
            if name.startswith("adaptive_single_question_") else {}
        )
        self.assertEqual(first["total_group_replays"], second["total_group_replays"])
        self.assertNotEqual(first["comparison_fingerprint_sha256"],
                            second["comparison_fingerprint_sha256"])
        self.assertFalse(second["permission_to_remove_repeated_invocations"])


if __name__ == "__main__":
    main()
