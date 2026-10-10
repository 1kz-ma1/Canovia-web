"""Complete Laravel migration ledger offline comparison safety tests."""

import json
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest

from scripts.ci.p0_mysql_full_ledger_offline_check import (
    InvalidLedger,
    compare_ledger,
    expected_migration_names,
    parse_full_ledger,
)


ONE = "2026_10_04_000200_create_intelligence_decision_traces_table"
TWO = "2026_10_08_230000_create_learning_answer_evaluation_adjustments"
THREE = "2026_10_09_235959_reconcile_p0_mysql_applied_constraints"


def output(rows):
    return "".join(f"migration_ledger\t{name}\t{batch}\n" for name, batch in rows)


class FullMigrationLedgerAuditTest(unittest.TestCase):
    def test_repository_has_unique_current_migration_files(self):
        names = expected_migration_names()
        self.assertIn(ONE, names)
        self.assertIn(TWO, names)
        self.assertIn(THREE, names)
        self.assertGreater(len(names), 30)

    def test_complete_ledger_cannot_grant_release(self):
        names = {ONE, TWO}
        result = compare_ledger(names, parse_full_ledger(output([(ONE, 5), (TWO, 7)])))
        self.assertEqual("REVIEW_REQUIRED", result["result"])
        self.assertEqual("FULL_MIGRATION_LEDGER_MATCH_NOT_RELEASE", result["code"])
        self.assertIs(result["release_authorized"], False)
        self.assertIs(result["production_database_modified"], False)

    def test_known_pending_migrations_require_human_review(self):
        result = compare_ledger({ONE, TWO, THREE}, parse_full_ledger(output([(ONE, 5), (TWO, 7)])))
        self.assertEqual("REVIEW_REQUIRED", result["result"])
        self.assertEqual("KNOWN_PENDING_MIGRATIONS", result["code"])
        self.assertIs(result["release_authorized"], False)

    def test_unknown_applied_migrations_are_blocked(self):
        unknown = "2026_10_09_235958_removed_or_renamed_migration"
        result = compare_ledger({ONE}, parse_full_ledger(output([(ONE, 5), (unknown, 8)])))
        self.assertEqual("BLOCK", result["result"])
        self.assertEqual("UNKNOWN_APPLIED_MIGRATION", result["code"])

    def test_empty_inventory_blocks(self):
        with self.assertRaises(InvalidLedger):
            parse_full_ledger("")
        result = compare_ledger({ONE}, {})
        self.assertEqual("BLOCK", result["result"])

    def test_duplicate_migration_id_rejected(self):
        with self.assertRaises(InvalidLedger):
            parse_full_ledger(output([(ONE, 1), (ONE, 1)]))

    def test_malformed_batches_and_names_rejected(self):
        malformed = [
            output([(ONE, "0")]),
            output([(ONE, "-3")]),
            output([(ONE, "9foo")]),
            output([(ONE, "99999999999999")]),
            output([("users@example.com", 2)]),
            "migration_ledger\t" + ONE + "\t2\tsecret\n",
            "migration_ledger," + ONE + ",2\n",
            "migration_ledger\t" + ONE + "\t2",
            "migration_ledger\t" + ONE + "\t2\r\n",
            "migration\t" + ONE + "\t2\n",
        ]
        for data in malformed:
            with self.subTest(invalid_length=len(data)):
                with self.assertRaises(InvalidLedger):
                    parse_full_ledger(data)

    def test_enforce_input_size_limit(self):
        data = output([(ONE, 1)]) * 3200
        with self.assertRaises(InvalidLedger):
            parse_full_ledger(data)

    def test_actual_sql_is_a_single_read_only_migrations_select(self):
        sql = (Path(__file__).parents[2] / "scripts/sql"
               / "p0_mysql_readonly_full_migration_ledger.sql").read_text()
        commands = "\n".join(
            line for line in sql.splitlines()
            if line.strip() and not line.lstrip().startswith("--")
        )
        self.assertTrue(commands.startswith("SELECT 'migration_ledger' AS section,"))
        self.assertEqual(1, commands.count("SELECT"))
        self.assertEqual(1, commands.count(";"))
        self.assertIn("FROM migrations", commands)
        self.assertNotIn("FROM users", commands)

    def test_unknown_raw_value_never_appears_in_cli_output(self):
        marker = "MY_PRIVATE_USERNAME_EMAIL_AND_PASSWORD"
        invalid_input = output([(ONE, 1)]) + marker + "\n"
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "untrusted-mysql-output.tsv"
            path.write_text(invalid_input, encoding="utf-8")
            script = (Path(__file__).parents[2] / "scripts/ci"
                      / "p0_mysql_full_ledger_offline_check.py")
            result = subprocess.run(
                [sys.executable, str(script), "--input", str(path)],
                text=True, capture_output=True, check=False,
            )
            self.assertEqual(2, result.returncode)
            self.assertNotIn(marker, result.stdout + result.stderr)
            self.assertEqual("FULL_LEDGER_INPUT_REJECTED", json.loads(result.stdout)["code"])

    def test_cli_report_without_real_db_never_auto_approves(self):
        expected = expected_migration_names()
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "disposable.tsv"
            path.write_text(output([(name, 1) for name in sorted(expected)]))
            script = (Path(__file__).parents[2] / "scripts/ci"
                      / "p0_mysql_full_ledger_offline_check.py")
            result = subprocess.run(
                [sys.executable, str(script), "--input", str(path)],
                text=True, capture_output=True, check=False,
            )
            self.assertEqual(0, result.returncode, result.stderr)
            report = json.loads(result.stdout)
            self.assertEqual("FULL_MIGRATION_LEDGER_MATCH_NOT_RELEASE", report["code"])
            self.assertIs(report["release_authorized"], False)


if __name__ == "__main__":
    unittest.main()
