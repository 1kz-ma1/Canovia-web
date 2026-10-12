"""Offline fail-closed release #488 two-migration gate tests; no Aiven access."""
import json
import subprocess
import sys
import tempfile
from pathlib import Path
import unittest

from scripts.ci.release_488_mysql_ledger_gate import (
    RELEASE_MIGRATION, EPOCH_MIGRATION, REPAIR_MIGRATION, compare_release, parse_schema,
)
from scripts.ci.p0_mysql_full_ledger_offline_check import (
    InvalidLedger, expected_migration_names, parse_full_ledger,
)

ONE = "2026_10_04_000200_create_intelligence_decision_traces_table"
UNKNOWN = "2026_10_30_000000_private_unapproved_migration"
MIGRATIONS = {RELEASE_MIGRATION, EPOCH_MIGRATION}
EXPECTED = {ONE, REPAIR_MIGRATION} | MIGRATIONS


def ledger(names):
    return "".join(f"migration_ledger\t{n}\t1\n" for n in sorted(names))


def schema(table="PRESENT", stamp="ABSENT", revision="ABSENT",
           epoch="ABSENT", index="ABSENT"):
    return (f"release_488_schema\tbase_table\t{table}\n"
            f"release_488_schema\tsource_updated_at\t{stamp}\n"
            f"release_488_schema\tsource_revision_id\t{revision}\n"
            f"release_488_schema\tsource_epoch\t{epoch}\n"
            f"release_488_schema\tsource_epoch_index\t{index}\n")


class Release488OfflineGateTest(unittest.TestCase):
    def test_exact_two_pending_and_both_applied_are_review_only(self):
        pending = compare_release(
            EXPECTED, parse_full_ledger(ledger(EXPECTED - MIGRATIONS)),
            parse_schema(schema()),
        )
        self.assertEqual("EXACT_TWO_SOURCE_OBSERVATION_MIGRATIONS_PENDING", pending["code"])
        self.assertEqual("REVIEW_REQUIRED", pending["result"])
        self.assertFalse(pending["release_authorized"])
        self.assertFalse(pending["production_database_modified"])
        self.assertFalse(pending["backup_restore_verified"])

        applied = compare_release(
            EXPECTED, parse_full_ledger(ledger(EXPECTED)),
            parse_schema(schema(stamp="VALID", revision="VALID", epoch="VALID", index="VALID")),
        )
        self.assertEqual("BOTH_SOURCE_OBSERVATION_MIGRATIONS_ALREADY_APPLIED", applied["code"])
        self.assertEqual("REVIEW_REQUIRED", applied["result"])
        self.assertFalse(applied["release_authorized"])

    def test_missing_repair_or_unknown_applied_blocks(self):
        self.assertEqual("BASELINE_UNVERIFIED",
                         compare_release(EXPECTED, parse_full_ledger(ledger([ONE])),
                                         parse_schema(schema()))["code"])
        bad = compare_release(EXPECTED, parse_full_ledger(ledger([ONE, REPAIR_MIGRATION, UNKNOWN])),
                              parse_schema(schema()))
        self.assertEqual("UNKNOWN_APPLIED_MIGRATION", bad["code"])
        self.assertNotIn(UNKNOWN, json.dumps(bad))

    def test_partial_ddl_and_partial_ledger_always_block(self):
        cases = [
            (EXPECTED - MIGRATIONS, schema(stamp="VALID")),
            (EXPECTED - MIGRATIONS, schema(revision="VALID")),
            (EXPECTED - MIGRATIONS, schema(epoch="VALID")),
            (EXPECTED - MIGRATIONS, schema(index="VALID")),
            (EXPECTED - MIGRATIONS, schema(epoch="VALID", index="VALID")),
            (EXPECTED - {EPOCH_MIGRATION}, schema(stamp="VALID", revision="VALID")),
            (EXPECTED - {RELEASE_MIGRATION}, schema(epoch="VALID", index="VALID")),
            (EXPECTED, schema()),
            (EXPECTED, schema(stamp="VALID", revision="VALID", epoch="VALID")),
            (EXPECTED, schema(stamp="VALID", revision="VALID", epoch="VALID", index="MISMATCH")),
            (EXPECTED, schema(stamp="VALID", revision="VALID", epoch="MISMATCH", index="VALID")),
        ]
        for migrations, fields in cases:
            with self.subTest(applied=len(migrations), schema=fields[-70:]):
                out = compare_release(EXPECTED, parse_full_ledger(ledger(migrations)),
                                      parse_schema(fields))
                self.assertEqual("BLOCK", out["result"])

    def test_extra_pending_unknown_blocks_even_if_two_known_pending(self):
        out = compare_release(EXPECTED | {UNKNOWN},
                              parse_full_ledger(ledger(EXPECTED - MIGRATIONS)),
                              parse_schema(schema()))
        self.assertEqual("UNEXPECTED_MIGRATION_STATE", out["code"])
        self.assertFalse(out["release_authorized"])

    def test_invalid_schema_rejected_and_table_missing_blocks(self):
        actual = parse_full_ledger(ledger(EXPECTED - MIGRATIONS))
        self.assertEqual("OBSERVATION_TABLE_NOT_VERIFIED",
                         compare_release(EXPECTED, actual,
                                         parse_schema(schema(table="MISSING")))["code"])
        malformed = [
            "", schema().replace("PRESENT", "LEAKED_PASSWORD"),
            schema().replace("source_epoch_index", "source_epoch"),
            schema().replace("source_revision_id", "other_field"),
            schema().replace("\n", "\r\n"),
            schema() + "extra\tfoo\tbar\n",
            schema()[:-1],
        ]
        for raw in malformed:
            with self.subTest(raw_length=len(raw)):
                with self.assertRaises(InvalidLedger):
                    parse_schema(raw)

    def test_full_repo_expected_exactly_two_pending(self):
        all_names = expected_migration_names()
        self.assertTrue(MIGRATIONS <= all_names)
        self.assertIn(REPAIR_MIGRATION, all_names)
        out = compare_release(all_names,
                              parse_full_ledger(ledger(all_names - MIGRATIONS)),
                              parse_schema(schema()))
        self.assertEqual("EXACT_TWO_SOURCE_OBSERVATION_MIGRATIONS_PENDING", out["code"])

    def test_cli_never_echoes_secret_or_authorizes(self):
        cli = Path(__file__).resolve().parents[2] / "scripts/ci/release_488_mysql_ledger_gate.py"
        names = expected_migration_names()
        with tempfile.TemporaryDirectory() as tmp:
            ledger_path, schema_path = Path(tmp)/"ledger.tsv", Path(tmp)/"schema.tsv"
            ledger_path.write_text(ledger(names - MIGRATIONS))
            schema_path.write_text(schema())
            result = subprocess.run(
                [sys.executable, str(cli), "--ledger", str(ledger_path),
                 "--schema", str(schema_path)], capture_output=True, text=True,
            )
            self.assertEqual(0, result.returncode, result.stderr)
            self.assertEqual("EXACT_TWO_SOURCE_OBSERVATION_MIGRATIONS_PENDING",
                             json.loads(result.stdout)["code"])
            ledger_path.write_text(ledger(names - MIGRATIONS) + "secret-session-value\n")
            rejected = subprocess.run(
                [sys.executable, str(cli), "--ledger", str(ledger_path),
                 "--schema", str(schema_path)], capture_output=True, text=True,
            )
            self.assertEqual(2, rejected.returncode)
            self.assertEqual("INPUT_INVALID", json.loads(rejected.stdout)["code"])
            self.assertNotIn("secret-session-value", rejected.stdout + rejected.stderr)

    def test_sql_is_metadata_only_select(self):
        sql = (Path(__file__).resolve().parents[2] /
               "scripts/sql/release_488_mysql_schema_snapshot.sql").read_text()
        source = "\n".join(l for l in sql.splitlines()
                           if l.strip() and not l.lstrip().startswith("--")).lower()
        self.assertTrue(source.startswith("select "))
        self.assertEqual(1, source.count(";"))
        self.assertIn("information_schema.columns", source)
        self.assertIn("information_schema.statistics", source)
        self.assertNotIn("from users", source)
        for phrase in (" alter ", " update ", " delete ", " insert ", " drop "):
            self.assertNotIn(phrase, source)


if __name__ == "__main__":
    unittest.main()
