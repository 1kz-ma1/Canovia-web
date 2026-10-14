"""Offline ordered JUnit parity and fail-closed experiment contracts."""
from pathlib import Path
from unittest import TestCase, main, mock
import os
import sys
import tempfile

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT))
from scripts.ci.platform_workspace_scenario_equivalence import (
    EXPERIMENTS, Inconclusive, experiment, expected_file,
    group_orders, observed_class_order, read_junit_all,
)


def junit(files: list[str], target: str, omitted: str = "", variable_target=False) -> str:
    cases = []
    for path in files:
        cls = Path(path).stem
        if cls == omitted:
            continue
        names = ["test_a", "test_b"] if cls == target else ["test_other"]
        if variable_target and cls == target and path != files[0]:
            names = ["test_a"]
        for name in names:
            cases.append('<testcase classname="Tests\\Feature\\' + cls
                         + '" name="' + name + '" />')
    return "<testsuites><testsuite>" + "".join(cases) + "</testsuite></testsuites>"


class ScenarioOrderTests(TestCase):
    def test_manifest_has_at_least_three_distinct_orders_without_changed_members(self):
        for key, target, group in EXPERIMENTS:
            with self.subTest(scenario=key):
                orders = group_orders(target, group)
                self.assertEqual(len(orders), 4)
                self.assertEqual(orders[0], ("original_group", group))
                self.assertTrue(any(name == "reverse_group" for name, _ in orders))
                self.assertEqual(len({tuple(paths) for _, paths in orders}), 4)
                for _, paths in orders:
                    self.assertEqual(sorted(paths), sorted(group))
                    self.assertEqual(paths.count(expected_file(target)), 1)

    def test_order_variation_places_target_at_different_positions(self):
        for _, target, group in EXPERIMENTS:
            orders = group_orders(target, group)
            positions = {list(paths).index(expected_file(target)) for _, paths in orders}
            self.assertIn(0, positions)
            self.assertIn(len(group)-1, positions)
            self.assertTrue(any(0 < p < len(group)-1 for p in positions))

    def test_invalid_group_membership_rejected(self):
        for target, paths in (
            ("FooTest", ("tests/Feature/FooTest.php",)),
            ("FooTest", ("tests/Feature/FooTest.php", "tests/Feature/FooTest.php", "tests/Feature/XTest.php")),
            ("FooTest", ("tests/Feature/A.php", "tests/Feature/B.php", "tests/Feature/C.php")),
        ):
            with self.subTest(paths=paths), self.assertRaises(Inconclusive):
                group_orders(target, paths)

    def test_all_group_junit_classes_and_execution_order_are_observed(self):
        with tempfile.TemporaryDirectory() as tmp:
            path = Path(tmp) / "cases.xml"
            group = list(EXPERIMENTS[0][2])
            target = EXPERIMENTS[0][1]
            path.write_text(junit(group, target), encoding="utf-8")
            cases = read_junit_all(path)
            self.assertEqual(len(cases), 4)
            self.assertEqual(observed_class_order(cases), tuple(Path(p).stem for p in group))

    def test_duplicate_full_group_testcase_is_rejected(self):
        with tempfile.TemporaryDirectory() as tmp:
            path = Path(tmp) / "duplicated.xml"
            path.write_text("<testsuites><testsuite>"
                            '<testcase classname="FooTest" name="test_a" />'
                            '<testcase classname="FooTest" name="test_a" />'
                            "</testsuite></testsuites>", encoding="utf-8")
            with self.assertRaises(Inconclusive):
                read_junit_all(path)

    def test_incomplete_regression_group_is_blocked_not_reported_equivalent(self):
        def incomplete(argv, path):
            idx = argv.index("--log-junit")
            files = list(argv[idx+2:])
            target = "AdaptiveLearningSingleQuestionV5878Test" if "adaptive" in path.name else "PlanActionDraftV5871Test"
            omission = Path(files[0]).stem if len(files) > 1 and Path(files[0]).stem != target else ""
            path.write_text(junit(files, target, omitted=omission), encoding="utf-8")
            return 0.1
        with mock.patch.dict(os.environ, {"GITHUB_ACTIONS": "true"}), mock.patch(
            "scripts.ci.platform_workspace_scenario_equivalence.runner", side_effect=incomplete
        ):
            with self.assertRaises(Inconclusive):
                experiment()

    def test_same_class_order_for_every_permutation_is_inconclusive(self):
        def always_sorted(argv, path):
            idx = argv.index("--log-junit")
            files = list(argv[idx+2:])
            target = "AdaptiveLearningSingleQuestionV5878Test" if "adaptive" in path.name else "PlanActionDraftV5871Test"
            path.write_text(junit(sorted(files), target), encoding="utf-8")
            return 0.1
        with mock.patch.dict(os.environ, {"GITHUB_ACTIONS": "true"}), mock.patch(
            "scripts.ci.platform_workspace_scenario_equivalence.runner", side_effect=always_sorted
        ):
            with self.assertRaisesRegex(Inconclusive, "order did not observably vary"):
                experiment()

    def test_subset_of_methods_in_one_order_is_rejected(self):
        def missing_one(argv, path):
            idx = argv.index("--log-junit")
            files = list(argv[idx+2:])
            target = "AdaptiveLearningSingleQuestionV5878Test" if "adaptive" in path.name else "PlanActionDraftV5871Test"
            path.write_text(junit(files, target, variable_target="reverse_group" in path.name),
                            encoding="utf-8")
            return 0.1
        with mock.patch.dict(os.environ, {"GITHUB_ACTIONS": "true"}), mock.patch(
            "scripts.ci.platform_workspace_scenario_equivalence.runner", side_effect=missing_one
        ):
            with self.assertRaises(Inconclusive):
                experiment()

    def test_unsafe_junit_content_is_blocked(self):
        with tempfile.TemporaryDirectory() as tmp:
            path = Path(tmp) / "unsafe.xml"
            for payload in (
                "<root/>",
                "<testsuites><testsuite><testcase classname='X' /></testsuite></testsuites>",
                "<!DOCTYPE a><testsuites></testsuites>",
                "<testsuites><testsuite><testcase classname='X' name='a'><skipped /></testcase></testsuite></testsuites>",
            ):
                path.write_text(payload, encoding="utf-8")
                with self.subTest(payload=payload[:20]), self.assertRaises(Inconclusive):
                    read_junit_all(path)

    def test_reporting_never_authorizes_deduplication(self):
        script = (ROOT/"scripts/ci/platform_workspace_scenario_equivalence.py").read_text(encoding="utf-8")
        self.assertIn('"test_order_independence_proven": False', script)
        self.assertIn('"cross_context_coverage_equivalence_proven": False', script)
        self.assertIn('"permission_to_remove_repeated_invocations": False', script)
        self.assertIn('"release_authorized": False', script)


if __name__ == "__main__":
    main()
