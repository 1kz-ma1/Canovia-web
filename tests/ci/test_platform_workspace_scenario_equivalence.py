"""No-network tests for the disposable Workspace scenario parity probe."""
from pathlib import Path
from unittest import TestCase, main, mock
import os
import sys
import tempfile

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT))
from scripts.ci.platform_workspace_scenario_equivalence import (
    EXPERIMENTS, WORKFLOW, Inconclusive, expected_file, experiment,
    read_junit, validate_manifest,
)


def xml(target: str, *names: str) -> bytes:
    items = "".join(
        '<testcase classname="Tests\\Feature\\' + target + '" name="' + name + '" />'
        for name in names
    )
    return ('<?xml version="1.0" ?><testsuites><testsuite name="Fixture">'
            + items + '</testsuite></testsuites>').encode("utf-8")


class ScenarioEquivalenceTests(TestCase):
    def test_manifest_matches_existing_original_step_order(self):
        validate_manifest(WORKFLOW.read_text(encoding="utf-8"))
        self.assertEqual(len(EXPERIMENTS), 2)
        for _, target, group in EXPERIMENTS:
            self.assertEqual(group.count(expected_file(target)), 1)
            self.assertTrue(all((ROOT / file).is_file() for file in group))

    def test_valid_junit_methods_are_sorted_and_selected(self):
        with tempfile.TemporaryDirectory() as tmp:
            path = Path(tmp) / "report.xml"
            path.write_bytes(xml("FooTest", "test_two", "test_one") +
                             b"")  # plain XML fixture
            self.assertEqual(read_junit(path, "FooTest"), ("test_one", "test_two"))

    def test_xml_with_failure_or_skip_is_blocked(self):
        with tempfile.TemporaryDirectory() as tmp:
            path = Path(tmp) / "bad.xml"
            for flag in ("failure", "error", "skipped"):
                path.write_text('<testsuites><testsuite>'
                                '<testcase classname="FooTest" name="a"><'
                                + flag + ' /></testcase></testsuite></testsuites>')
                with self.subTest(flag=flag), self.assertRaises(Inconclusive):
                    read_junit(path, "FooTest")

    def test_xml_with_missing_target_is_blocked(self):
        with tempfile.TemporaryDirectory() as tmp:
            path = Path(tmp) / "missing.xml"
            path.write_bytes(xml("OtherTest", "test_one"))
            with self.assertRaises(Inconclusive):
                read_junit(path, "FooTest")

    def test_xml_with_duplicate_target_case_is_blocked(self):
        with tempfile.TemporaryDirectory() as tmp:
            path = Path(tmp) / "dup.xml"
            path.write_bytes(xml("FooTest", "test_one", "test_one"))
            with self.assertRaises(Inconclusive):
                read_junit(path, "FooTest")

    def test_xml_dtd_or_entity_is_blocked(self):
        with tempfile.TemporaryDirectory() as tmp:
            path = Path(tmp) / "danger.xml"
            path.write_bytes(b'<!DOCTYPE testsuites [<!ENTITY x "secret">]>'
                             + xml("FooTest", "test_one"))
            with self.assertRaises(Inconclusive):
                read_junit(path, "FooTest")

    def test_missing_and_oversized_xml_are_blocked(self):
        with tempfile.TemporaryDirectory() as tmp:
            path = Path(tmp) / "missing.xml"
            with self.assertRaises(Inconclusive):
                read_junit(path, "FooTest")
            path.write_bytes(b"x" * 2_000_001)
            with self.assertRaises(Inconclusive):
                read_junit(path, "FooTest")

    def test_missing_or_changed_original_scenario_is_blocked(self):
        workflow = WORKFLOW.read_text(encoding="utf-8")
        modified = workflow.replace("AdaptiveLearningCandidateQueueV5879Test.php",
                                    "MissingTest.php")
        with self.assertRaises(Inconclusive):
            validate_manifest(modified, read_sources=False)

    def test_sqlite_only_guard_and_successful_mock_parity(self):
        seen = []
        def fake_runner(argv, path):
            self.assertEqual(argv[:3], ["php", "artisan", "test"])
            self.assertIn("--without-tty", argv)
            idx = argv.index("--log-junit")
            files = argv[idx + 2:]
            target = "AdaptiveLearningSingleQuestionV5878Test" if "adaptive" in path.name else "PlanActionDraftV5871Test"
            cases = []
            for file in files:
                classname = Path(file).stem
                names = ("test_alpha", "test_beta") if classname == target else ("test_fixture",)
                for name in names:
                    cases.append('<testcase classname="Tests\\\\Feature\\\\' + classname
                                 + '" name="' + name + '" />')
            path.write_text("<testsuites><testsuite>" + "".join(cases)
                            + "</testsuite></testsuites>", encoding="utf-8")
            seen.append(tuple(argv))
            return 0.4
        with mock.patch.dict(os.environ, {"GITHUB_ACTIONS": "true"}):
            with mock.patch("scripts.ci.platform_workspace_scenario_equivalence.runner",
                            side_effect=fake_runner):
                result = experiment()
        self.assertEqual(len(seen), 10)
        self.assertEqual(result["result"], "OBSERVED_SCENARIO_PARITY")
        self.assertEqual([x["selected_testcases"] for x in result["scenarios"]], [2, 2])
        self.assertEqual([x["group_variants_executed"] for x in result["scenarios"]], [4, 4])
        self.assertTrue(all(x["distinct_observed_class_orders"] >= 2 for x in result["scenarios"]))
        self.assertTrue(all(x["same_full_group_testcase_inventory"] for x in result["scenarios"]))
        self.assertFalse(result["test_order_independence_proven"])
        self.assertFalse(result["cross_context_coverage_equivalence_proven"])
        self.assertFalse(result["permission_to_remove_repeated_invocations"])
        self.assertFalse(result["release_authorized"])

    def test_context_method_set_difference_is_blocked(self):
        def fake_runner(argv, path):
            target = "AdaptiveLearningSingleQuestionV5878Test" if "adaptive" in path.name else "PlanActionDraftV5871Test"
            names = ["test_one"] if "alone" in path.name else ["test_one", "test_two"]
            path.write_bytes(xml(target, *names))
            return 1.0
        with mock.patch.dict(os.environ, {"GITHUB_ACTIONS": "true"}):
            with mock.patch("scripts.ci.platform_workspace_scenario_equivalence.runner",
                            side_effect=fake_runner):
                with self.assertRaises(Inconclusive):
                    experiment()

    def test_non_actions_runner_is_blocked(self):
        with mock.patch.dict(os.environ, {"GITHUB_ACTIONS": "false"}):
            with self.assertRaises(Inconclusive):
                experiment()

    def test_parser_never_allows_non_xml_or_untrusted_root(self):
        with tempfile.TemporaryDirectory() as tmp:
            path = Path(tmp) / "wrong.xml"
            path.write_text("<root><testcase classname='FooTest' name='ok'/></root>")
            with self.assertRaises(Inconclusive):
                read_junit(path, "FooTest")


if __name__ == "__main__":
    main()
