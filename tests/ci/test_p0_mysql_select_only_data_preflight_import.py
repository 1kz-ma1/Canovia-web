"""P0 SELECT-only MySQL data preflight: strict offline evidence tests."""
import json
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest

from scripts.ci.p0_mysql_select_only_data_preflight_import import (
    EXPECTED, InvalidPreflight, parse_status_tsv,
)

ROOT = Path(__file__).resolve().parents[2]
SCRIPT = ROOT / "scripts/ci/p0_mysql_select_only_data_preflight_import.py"
SQL = ROOT / "scripts/sql/p0_mysql_select_only_data_preflight.sql"


def sample(statuses=None):
    statuses = statuses or {}
    return "".join(
        f"{section}\t{key}\t{statuses.get((section, key), 'PASS')}\n"
        for section, keys in EXPECTED.items() for key in sorted(keys)
    )


class DataPreflightOfflineTest(unittest.TestCase):
    def test_all_expected_checks_require_human_review_not_authorization(self):
        self.assertEqual(13, sum(len(v) for v in EXPECTED.values()))
        result = parse_status_tsv(sample())
        self.assertEqual("REVIEW_REQUIRED", result["result"])
        self.assertEqual(["SELECT_ONLY_DATA_PREFLIGHT_MATCH_NOT_RELEASE"], result["codes"])
        for field in ("release_authorized", "production_database_modified",
                      "database_identity_independently_verified",
                      "backup_restore_verified"):
            self.assertIs(result[field], False)

    def test_orphan_and_fk_and_unique_blocks_are_classified(self):
        results = parse_status_tsv(sample({
            ("fk_metadata", "laea_user"): "BLOCK",
            ("orphan", "idt_plan"): "BLOCK",
            ("unique", "idt_decision_reference"): "BLOCK",
        }))
        self.assertEqual("BLOCK", results["result"])
        self.assertEqual([
            "FK_METADATA_INCOMPATIBLE",
            "ORPHANED_REFERENCES_PRESENT",
            "DUPLICATE_UNIQUE_VALUES_PRESENT",
        ], results["codes"])
        self.assertIs(results["release_authorized"], False)

    def test_unsupported_engine_blocks(self):
        r = parse_status_tsv(sample({("engine", "mysql8"): "BLOCK"}))
        self.assertEqual("BLOCK", r["result"])
        self.assertEqual(["UNSUPPORTED_MYSQL_ENGINE"], r["codes"])

    def test_missing_or_duplicate_row_blocks(self):
        valid = sample()
        rows = valid.splitlines(keepends=True)
        with self.assertRaises(InvalidPreflight):
            parse_status_tsv("".join(rows[:-1]))
        with self.assertRaises(InvalidPreflight):
            parse_status_tsv(valid + rows[0])

    def test_invalid_status_extra_section_or_object_blocks(self):
        row = "unique\tlaea_answer_event\tPASS\n"
        valid = sample()
        for corrupted in (
            valid.replace(row, row.replace("PASS", "VALID")),
            valid.replace(row, "customers\tlaea_answer_event\tPASS\n"),
            valid.replace(row, "unique\tpassword\tPASS\n"),
            valid.replace(row, "unique\tlaea_answer_event\tPASS\textra\n"),
            valid.replace(row, "unique,laea_answer_event,PASS\n"),
        ):
            with self.subTest(sample_len=len(corrupted)):
                with self.assertRaises(InvalidPreflight):
                    parse_status_tsv(corrupted)

    def test_truncation_crlf_and_unicode_rejected(self):
        valid = sample()
        for corrupted in (valid[:-1], valid.replace("\n", "\r\n"),
                          valid + "credential-secret", "\ufffd" + valid,
                          valid + "x" * 8192):
            with self.subTest(n=len(corrupted)):
                with self.assertRaises(InvalidPreflight):
                    parse_status_tsv(corrupted)

    def test_sql_source_contains_only_select_statements(self):
        content = SQL.read_text()
        statements = "\n".join(
            line for line in content.splitlines()
            if line.strip() and not line.lstrip().startswith("--")
        ).split(";")
        statements = [s.strip() for s in statements if s.strip()]
        self.assertEqual(9, len(statements))
        for statement in statements:
            self.assertTrue(statement.startswith("SELECT "), statement[:35])
        self.assertIn("information_schema.COLUMNS", content)
        self.assertIn("HAVING COUNT(*) > 1", content)
        self.assertNotIn("FROM sessions", content)

    def test_cli_never_reflects_untrusted_input_even_with_secret_marker(self):
        marker = "my-test-password-secret-private-value"
        with tempfile.TemporaryDirectory() as d:
            file = Path(d) / "untrusted.tsv"
            file.write_text(sample() + marker, encoding="utf-8")
            cmd = subprocess.run([sys.executable, str(SCRIPT), "--input", str(file)],
                                 text=True, capture_output=True, check=False)
        self.assertEqual(2, cmd.returncode)
        self.assertNotIn(marker, cmd.stdout + cmd.stderr)
        result = json.loads(cmd.stdout)
        self.assertEqual(["DATA_PREFLIGHT_IMPORT_REJECTED"], result["codes"])
        self.assertIs(result["release_authorized"], False)

    def test_cli_accepts_only_valid_comprehensive_report(self):
        with tempfile.TemporaryDirectory() as d:
            file = Path(d) / "synthetic.tsv"
            file.write_text(sample(), encoding="ascii")
            cmd = subprocess.run([sys.executable, str(SCRIPT), "--input", str(file)],
                                 text=True, capture_output=True, check=False)
        self.assertEqual(0, cmd.returncode, cmd.stderr)
        self.assertEqual("REVIEW_REQUIRED", json.loads(cmd.stdout)["result"])


if __name__ == "__main__":
    unittest.main()
