"""R22 static decision register contracts: no deduplication eligibility."""
from __future__ import annotations

from copy import deepcopy
import json
from pathlib import Path
import subprocess
import sys
import unittest
from unittest import mock

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT))

from scripts.ops.platform_workspace_r22_decision_register import (
    EVIDENCE_DOCS, GATES, TARGET, CREATIVE_TARGET, TARGET_CONTEXTS, CREATIVE_CONTEXTS, WORKFLOW, IncompleteEvidence,
    build_register, read_doc, verify_docs,
)
from scripts.ops.platform_workspace_context_intent_matrix import audit

SOURCE = WORKFLOW.read_text(encoding="utf-8")


class DecisionRegisterTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.report = build_register(SOURCE)

    def test_real_workflow_accounts_for_all_repeated_file_occurrences(self):
        r = self.report
        self.assertEqual(r["result"], "HOLD_MANUAL_CONTEXT_DECISION_REQUIRED")
        self.assertEqual((r["artisan_test_process_invocations"],
                          r["explicit_test_file_invocations"],
                          r["unique_test_file_paths"],
                          r["repeated_test_files"],
                          r["additional_repeated_invocations"],
                          r["all_repeated_file_occurrences"]), (72, 160, 118, 21, 42, 63))
        self.assertEqual(len(r["contexts"]), 21)
        self.assertEqual(sum(len(x["occurrences"]) for x in r["contexts"]), 63)
        self.assertEqual(sum(x["additional_invocations"] for x in r["contexts"]), 42)

    def test_every_context_is_held_and_none_approved(self):
        r = self.report
        self.assertEqual(r["automatically_approved_removal_candidates"], 0)
        self.assertFalse(r["permission_to_remove_repeated_invocations"])
        self.assertFalse(r["permission_to_regroup_or_reorder"])
        self.assertFalse(r["production_release_authorized"])
        self.assertFalse(r["complete_72_step_omission_equivalence_proven"])
        for item in r["contexts"]:
            self.assertFalse(item["safe_to_remove"])
            self.assertEqual(item["missing_decision_gates"], list(GATES))
            self.assertEqual(len(item["occurrences"]), item["original_invocations"])
            for step in item["occurrences"]:
                self.assertFalse(step["remove_this_occurrence"])
                self.assertFalse(step["state_boundary_equivalence_proven"])
                self.assertFalse(step["step_specific_intent_accepted_by_domain_owner"])
                self.assertEqual(len(step["ordered_command_sha256"]), 64)

    def test_studied_target_is_exactly_five_not_five_removal_candidates(self):
        r = self.report
        self.assertEqual(r["historically_experimented_file_count"], 2)
        self.assertEqual(r["historically_experimented_file_occurrences"], 7)
        self.assertEqual(r["static_only_file_count"], 19)
        self.assertEqual(r["static_only_file_occurrences"], 56)
        item = next(x for x in r["contexts"] if x["test_file"] == TARGET)
        self.assertEqual(item["additional_invocations"], 4)
        self.assertEqual({x["named_regression_step"] for x in item["occurrences"]},
                         set(TARGET_CONTEXTS))
        self.assertEqual(item["evidence_level"],
                         "SCOPED_HISTORICAL_MUTATION_OBSERVATIONS_HOLD")
        self.assertTrue(all(x["historical_two_mutant_probe_documented"]
                            for x in item["occurrences"]))
        self.assertTrue(all(x["other_companion_files_alone_detected_these_two_mutants"] is False
                            for x in item["occurrences"]))
        self.assertTrue(all(not x["safe_to_remove"] for x in r["contexts"]))

    def test_r23_creative_contexts_explicitly_scoped_and_held(self):
        record = next(x for x in self.report["contexts"] if x["test_file"] == CREATIVE_TARGET)
        self.assertEqual(record["historical_experiment_context_count"], 2)
        self.assertEqual(record["additional_invocations"], 1)
        self.assertEqual(record["evidence_level"],
                         "SCOPED_HISTORICAL_CREATIVE_MUTATION_OBSERVATIONS_HOLD")
        self.assertEqual({x["named_regression_step"] for x in record["occurrences"]},
                         set(CREATIVE_CONTEXTS))
        self.assertTrue(all(x["historical_mutant_scope"] == "R23_CREATIVE_PLAN"
                            for x in record["occurrences"]))
        self.assertTrue(all(x["other_companion_files_alone_detected_these_two_mutants"] is False
                            for x in record["occurrences"]))
        self.assertFalse(record["safe_to_remove"])

    def test_unexamined_files_not_inferred_safe_or_security_clean(self):
        rows = [x for x in self.report["contexts"]
                if x["test_file"] not in (TARGET, CREATIVE_TARGET)]
        self.assertEqual(len(rows), 19)
        self.assertTrue(all(x["unreviewed_security_impact"] for x in rows))
        self.assertTrue(all(x["evidence_level"] ==
                            "CURRENT_STATIC_CONTEXT_INVENTORY_ONLY_HOLD" for x in rows))
        self.assertTrue(all(x["historical_experiment_context_count"] == 0 for x in rows))
        self.assertTrue(all(step["other_companion_files_alone_detected_these_two_mutants"] is None
                            for x in rows for step in x["occurrences"]))

    def test_historical_documents_are_not_current_sha_ci_attestations(self):
        self.assertEqual(len(self.report["historical_sources"]), 5)
        self.assertFalse(self.report["historical_experiments_revalidated_at_current_main_sha"])
        for doc in self.report["historical_sources"]:
            self.assertFalse(doc["current_sha_experiment_replay"])
            self.assertFalse(doc["reviewed_for_test_removal"])
            self.assertEqual(doc["evidence_level"],
                             "HISTORICAL_DOCUMENTED_DISPOSABLE_CI_RESULT_ONLY")

    def test_all_evidence_source_documents_are_present(self):
        evidence = verify_docs()
        self.assertEqual([x["stage"] for x in evidence],
                         ["R18", "R19", "R20", "R21", "R23"])
        self.assertEqual(len(evidence), len(EVIDENCE_DOCS))

    def test_missing_document_blocks_instead_of_fallback_success(self):
        def missing_document(_):
            raise IncompleteEvidence("test document missing")
        with self.assertRaises(IncompleteEvidence):
            build_register(SOURCE, read_document=missing_document)

    def test_doc_marker_drift_blocks(self):
        def drift(name):
            if name.startswith("PLATFORM_WORKSPACE_R21_"):
                return read_doc(name).replace("not an actual modified 72-step", "unknown")
            return read_doc(name)
        with self.assertRaisesRegex(IncompleteEvidence, "markers missing"):
            build_register(SOURCE, read_document=drift)

    def test_missing_historical_step_blocks_even_when_source_counts_match(self):
        source = SOURCE.replace("      - name: V58.74 Owner-approved Sequential Action Draft",
                                "      - name: Changed R22 regression intent", 1)
        with self.assertRaisesRegex(IncompleteEvidence, "R21 directly studied"):
            build_register(source)

    def test_mutated_source_inventory_blocks(self):
        original = (
            "        run: php artisan test "
            "tests/Feature/PlanActionDraftV5875Test.php "
            "tests/Feature/PlanActionDraftV5874Test.php "
            "tests/Feature/PlanActionDraftV5873Test.php "
            "tests/Feature/PlanActionDraftV5872Test.php "
            "tests/Feature/PlanActionDraftV5871Test.php"
        )
        self.assertEqual(SOURCE.count(original), 1)
        changed = SOURCE.replace(
            original,
            original.replace(" tests/Feature/PlanActionDraftV5871Test.php", ""),
            1,
        )
        with self.assertRaises(IncompleteEvidence):
            build_register(changed)

    def test_modified_r17_removal_flag_blocks(self):
        matrix = audit(SOURCE)
        matrix["permission_to_remove_regression_invocations"] = True
        with mock.patch("scripts.ops.platform_workspace_r22_decision_register.audit",
                        return_value=matrix), self.assertRaisesRegex(IncompleteEvidence, "removal boundary"):
            build_register(SOURCE)

    def test_missing_extra_target_occurrence_blocks(self):
        matrix = deepcopy(audit(SOURCE))
        target = next(x for x in matrix["contexts"] if x["test_file"] == TARGET)
        target["occurrences"].pop()
        target["invocations"] = 4
        with mock.patch("scripts.ops.platform_workspace_r22_decision_register.audit",
                        return_value=matrix), self.assertRaisesRegex(IncompleteEvidence, "contexts changed"):
            build_register(SOURCE)

    def test_default_cli_is_offline_and_never_approves(self):
        run = subprocess.run(
            [sys.executable, str(ROOT / "scripts/ops/platform_workspace_r22_decision_register.py")],
            cwd=ROOT, capture_output=True, text=True, timeout=30)
        self.assertEqual(run.returncode, 0, run.stderr)
        data = json.loads(run.stdout)
        self.assertEqual(data["result"], "HOLD_MANUAL_CONTEXT_DECISION_REQUIRED")
        self.assertFalse(data["permission_to_remove_repeated_invocations"])
        self.assertFalse(data["production_release_authorized"])
        self.assertNotIn("Traceback", run.stdout)


if __name__ == "__main__":
    unittest.main()
