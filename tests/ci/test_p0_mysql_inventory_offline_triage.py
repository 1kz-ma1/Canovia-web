"""P0 offline schema inventory triage is conservative and never authorizes deploy."""

import json
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest

from scripts.ci.p0_mysql_inventory_offline_triage import (
    ADJUSTMENT, DECISION, MIGRATIONS, RECONCILE_MIGRATION,
    classify, template,
)


def complete() -> dict:
    data = template()
    data["connection"] = "SCHEMA_SELECTED"
    data["mysql_version"] = "8.0.46"
    for group, statuses in {
        "tables": "PRESENT",
        "columns": "PRESENT",
        "foreign_keys": "PASS",
        "indexes": "PASS",
        "migrations": "APPLIED",
    }.items():
        data[group] = dict.fromkeys(data[group], statuses)
    return data


class P0OfflineSchemaTriageTest(unittest.TestCase):
    def check(self, data: dict, expected: str, code: str) -> None:
        result = classify(data)
        self.assertEqual(expected, result["result"])
        self.assertIn(code, result["codes"])
        self.assertIs(result["release_authorized"], False)
        self.assertIs(result["production_database_modified"], False)

    def test_default_template_is_not_ever_accepted_as_schema_evidence(self):
        self.check(template(), "BLOCK", "EVIDENCE_INCOMPLETE")

    def test_complete_target_schema_still_requires_release_review(self):
        self.check(complete(), "REVIEW_REQUIRED", "TARGETED_METADATA_MATCH_NOT_RELEASE")

    def test_no_database_is_selected_is_blocked(self):
        data = complete()
        data["connection"] = "NO_SCHEMA_SELECTED"
        self.check(data, "BLOCK", "SCHEMA_NOT_SELECTED")

    def test_unsupported_engine_is_blocked(self):
        data = complete()
        data["mysql_version"] = "10.11.6-MariaDB"
        self.check(data, "BLOCK", "MYSQL_ENGINE_REVIEW_REQUIRED")

    def test_any_missing_core_table_blocks(self):
        data = complete()
        data["tables"]["users"] = "MISSING"
        self.check(data, "BLOCK", "CORE_TABLE_MISSING")

    def test_unapplied_table_and_empty_ddl_is_review_only(self):
        data = complete()
        data["migrations"][MIGRATIONS[ADJUSTMENT]] = "PENDING"
        data["tables"][ADJUSTMENT] = "MISSING"
        for group in ("columns",):
            for key in data[group]:
                if key.startswith(ADJUSTMENT + "."):
                    data[group][key] = "MISSING"
        for k in ("laea_answer_event_fk", "laea_user_fk"):
            data["foreign_keys"][k] = "MISSING"
        data["indexes"]["learning_eval_adjustment_event_unique"] = "MISSING"
        self.check(data, "REVIEW_REQUIRED", "ADJUSTMENT_PENDING_CREATE_REVIEW")

    def test_applied_missing_table_is_blocked_even_if_expected_metadata_missing(self):
        data = complete()
        data["tables"][DECISION] = "MISSING"
        self.check(data, "BLOCK", "TRACE_APPLIED_TABLE_MISSING")

    def test_partial_missing_column_fails_closed(self):
        data = complete()
        data["columns"][DECISION + ".domain"] = "MISSING"
        self.check(data, "BLOCK", "TRACE_PARTIAL_COLUMNS")

    def test_pending_migration_missing_key_is_review_only_not_deploy_authorization(self):
        data = complete()
        data["migrations"][MIGRATIONS[ADJUSTMENT]] = "PENDING"
        data["foreign_keys"]["laea_answer_event_fk"] = "MISSING"
        self.check(data, "REVIEW_REQUIRED", "ADJUSTMENT_PENDING_REPAIR_REVIEW")

    def test_applied_migration_missing_key_is_blocked_not_silently_replayed(self):
        data = complete()
        data["foreign_keys"]["laea_answer_event_fk"] = "MISSING"
        self.check(data, "BLOCK", "ADJUSTMENT_APPLIED_CONSTRAINT_DRIFT")

    def test_applied_migration_missing_unique_index_is_blocked(self):
        data = complete()
        data["indexes"]["learning_eval_adjustment_event_unique"] = "MISSING"
        self.check(data, "BLOCK", "ADJUSTMENT_APPLIED_CONSTRAINT_DRIFT")

    def test_wrong_reference_or_delete_action_is_blocked(self):
        data = complete()
        data["foreign_keys"]["idt_snapshot_fk"] = "MISMATCH"
        self.check(data, "BLOCK", "TRACE_CONSTRAINT_MISMATCH")

    def test_ledger_reverse_order_is_blocked(self):
        data = complete()
        data["migrations"][MIGRATIONS[DECISION]] = "PENDING"
        self.assertEqual(data["migrations"][RECONCILE_MIGRATION], "APPLIED")
        self.check(data, "BLOCK", "MIGRATION_LEDGER_ORDER_INCONSISTENT")

    def test_unexpected_input_fields_or_keys_are_blocked(self):
        data = complete()
        data["db_password"] = "DONT_PUT_SECRETS_IN_THIS_FILE"
        self.check(data, "BLOCK", "INVALID_INVENTORY")
        data = complete()
        data["foreign_keys"]["some_extra_constraint"] = "PASS"
        self.check(data, "BLOCK", "INVALID_INVENTORY")

    def test_unknown_status_cannot_pass_validation(self):
        data = complete()
        data["foreign_keys"]["idt_snapshot_fk"] = "looks good"
        self.check(data, "BLOCK", "INVALID_INVENTORY")

    def test_command_line_cannot_print_untrusted_input(self):
        data = complete()
        data["db_password"] = "LEAK_PROBE_DO_NOT_EMIT"
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "synthetic.json"
            path.write_text(json.dumps(data), encoding="utf-8")
            script = Path(__file__).parents[2] / "scripts/ci/p0_mysql_inventory_offline_triage.py"
            proc = subprocess.run(
                [sys.executable, str(script), "--report", str(path)],
                capture_output=True, text=True, check=False,
            )
            self.assertEqual(proc.returncode, 2)
            self.assertNotIn("LEAK_PROBE_DO_NOT_EMIT", proc.stdout + proc.stderr)
            self.assertIn("INVALID_INVENTORY", proc.stdout)


if __name__ == "__main__":
    unittest.main()
