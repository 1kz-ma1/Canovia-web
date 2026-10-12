"""Pure offline release #488 schema+ledger gate: no production access."""
import json
import subprocess
import sys
import tempfile
from pathlib import Path
import unittest

from scripts.ci.release_488_mysql_ledger_gate import (
    RELEASE_MIGRATION, REPAIR_MIGRATION, compare_release, parse_schema,
)
from scripts.ci.p0_mysql_full_ledger_offline_check import (
    InvalidLedger, expected_migration_names, parse_full_ledger,
)

ONE = "2026_10_04_000200_create_intelligence_decision_traces_table"
UNKNOWN = "2026_10_30_000000_private_unapproved_migration"
EXPECTED = {ONE, REPAIR_MIGRATION, RELEASE_MIGRATION}


def ledger(names):
    return "".join(f"migration_ledger\t{n}\t1\n" for n in sorted(names))


def schema(table="PRESENT", stamp="ABSENT", revision="ABSENT"):
    return (f"release_488_schema\tbase_table\t{table}\n"
            f"release_488_schema\tsource_updated_at\t{stamp}\n"
            f"release_488_schema\tsource_revision_id\t{revision}\n")


class Release488OfflineGateTest(unittest.TestCase):
    def test_exact_pending_and_previously_applied_are_review_only(self):
        applied = parse_full_ledger(ledger(EXPECTED - {RELEASE_MIGRATION}))
        result = compare_release(EXPECTED, applied, parse_schema(schema()))
        self.assertEqual("REVIEW_REQUIRED", result["result"])
        self.assertEqual("EXACT_ONE_SOURCE_REVISION_MIGRATION_PENDING", result["code"])
        self.assertFalse(result["release_authorized"])
        self.assertFalse(result["production_database_modified"])

        applied_all = parse_full_ledger(ledger(EXPECTED))
        result = compare_release(EXPECTED, applied_all,
                                 parse_schema(schema(stamp="VALID", revision="VALID")))
        self.assertEqual("SOURCE_REVISION_MIGRATION_ALREADY_APPLIED", result["code"])
        self.assertEqual("REVIEW_REQUIRED", result["result"])
        self.assertFalse(result["backup_restore_verified"])

    def test_missing_repair_migration_fails_closed(self):
        result = compare_release(EXPECTED, parse_full_ledger(ledger([ONE])),
                                 parse_schema(schema()))
        self.assertEqual("BASELINE_UNVERIFIED", result["code"])

    def test_unknown_applied_cannot_hide_missing_known(self):
        bad_applied = parse_full_ledger(ledger([ONE, REPAIR_MIGRATION, UNKNOWN]))
        result = compare_release(EXPECTED, bad_applied, parse_schema(schema()))
        self.assertEqual("UNKNOWN_APPLIED_MIGRATION", result["code"])
        self.assertNotIn(UNKNOWN, json.dumps(result))

    def test_second_pending_even_with_same_count_is_blocked(self):
        expected = EXPECTED | {UNKNOWN}
        applied = parse_full_ledger(ledger([ONE, REPAIR_MIGRATION, UNKNOWN]))
        result = compare_release(expected, applied, parse_schema(schema()))
        self.assertEqual("EXACT_ONE_SOURCE_REVISION_MIGRATION_PENDING", result["code"])
        # But with two pending migrations the release is always blocked.
        result = compare_release(expected,
                                 parse_full_ledger(ledger([ONE, REPAIR_MIGRATION])),
                                 parse_schema(schema()))
        self.assertEqual("UNEXPECTED_MIGRATION_STATE", result["code"])

    def test_migration_and_schema_must_agree(self):
        cases = [
            (EXPECTED - {RELEASE_MIGRATION}, schema(stamp="VALID")),
            (EXPECTED - {RELEASE_MIGRATION}, schema(revision="VALID")),
            (EXPECTED, schema()),
            (EXPECTED, schema(stamp="VALID", revision="MISMATCH")),
            (EXPECTED, schema(stamp="VALID")),
        ]
        for names, fields in cases:
            with self.subTest(names=len(names), field=fields[-22:]):
                result = compare_release(EXPECTED, parse_full_ledger(ledger(names)),
                                         parse_schema(fields))
                self.assertEqual("MIGRATION_SCHEMA_LEDGER_CONFLICT", result["code"])

    def test_absent_base_table_and_schema_malformed_are_blocked(self):
        actual = parse_full_ledger(ledger(EXPECTED - {RELEASE_MIGRATION}))
        self.assertEqual(
            "OBSERVATION_TABLE_NOT_VERIFIED",
            compare_release(EXPECTED, actual, parse_schema(schema(table="MISSING")))["code"],
        )
        malformed = [
            "", schema().replace("PRESENT", "LEAKED_PASSWORD"),
            schema().replace("base_table", "secret_email"),
            schema().replace("source_revision_id", "source_updated_at"),
            schema().replace("\n", "\r\n"), schema() + "secret\tfoo\tbar\n",
            schema()[:-1],
        ]
        for raw in malformed:
            with self.subTest(length=len(raw)):
                with self.assertRaises(InvalidLedger):
                    parse_schema(raw)

    def test_full_repo_expected_exactly_one_possible_pending(self):
        names = expected_migration_names()
        self.assertIn(RELEASE_MIGRATION, names)
        self.assertIn(REPAIR_MIGRATION, names)
        before = names - {RELEASE_MIGRATION}
        result = compare_release(names, parse_full_ledger(ledger(before)), parse_schema(schema()))
        self.assertEqual("EXACT_ONE_SOURCE_REVISION_MIGRATION_PENDING", result["code"])

    def test_cli_cannot_expose_raw_input_and_never_grants_release(self):
        script = Path(__file__).resolve().parents[2] / "scripts/ci/release_488_mysql_ledger_gate.py"
        names = expected_migration_names()
        with tempfile.TemporaryDirectory() as tmp:
            ledger_file, schema_file = Path(tmp)/"ledger.tsv", Path(tmp)/"schema.tsv"
            ledger_file.write_text(ledger(names - {RELEASE_MIGRATION}))
            schema_file.write_text(schema())
            process = subprocess.run(
                [sys.executable, str(script), "--ledger", str(ledger_file),
                 "--schema", str(schema_file)], capture_output=True, text=True,
            )
            self.assertEqual(0, process.returncode, process.stderr)
            self.assertEqual("EXACT_ONE_SOURCE_REVISION_MIGRATION_PENDING",
                             json.loads(process.stdout)["code"])
            ledger_file.write_text(ledger(names - {RELEASE_MIGRATION}) + "my-private-raw-token\n")
            process = subprocess.run(
                [sys.executable, str(script), "--ledger", str(ledger_file),
                 "--schema", str(schema_file)], capture_output=True, text=True,
            )
            self.assertEqual(2, process.returncode)
            self.assertEqual("INPUT_INVALID", json.loads(process.stdout)["code"])
            self.assertNotIn("my-private-raw-token", process.stdout + process.stderr)
            self.assertFalse(json.loads(process.stdout)["release_authorized"])

    def test_sql_is_only_read_only_metadata(self):
        sql = (Path(__file__).resolve().parents[2] /
               "scripts/sql/release_488_mysql_schema_snapshot.sql").read_text()
        source = "\n".join(l for l in sql.splitlines()
                           if l.strip() and not l.lstrip().startswith("--")).lower()
        self.assertTrue(source.startswith("select "))
        self.assertEqual(1, source.count(";"))
        self.assertIn("information_schema.columns", source)
        self.assertNotIn("from users", source)
        for phrase in (" alter ", " update ", " delete ", " insert ", " drop "):
            self.assertNotIn(phrase, source)


if __name__ == "__main__":
    unittest.main()
