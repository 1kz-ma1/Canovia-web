"""Regression tests for offline P0 MySQL TSV import / evidence handoff."""

import json
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest

from scripts.ci.p0_mysql_inventory_offline_triage import classify, expected_keys
from scripts.ci.p0_mysql_inventory_tsv_import import InventoryRejected, parse_tsv


def fake_mysql_export(mutation=None):
    # Exactly the six SELECT result sets' batch/skip-column-names row shape.
    # All test data are synthetic; never attach actual Aiven output to CI.
    data = ["connection\tmysql_version\t8.0.46-Aiven\tSCHEMA_SELECTED"]
    checks = expected_keys()
    for group, prefix, healthy in (
        ("tables", "table", "PRESENT"),
        ("columns", "column", "PRESENT"),
        ("foreign_keys", "foreign_key", "PASS"),
        ("indexes", "index", "PASS"),
        ("migrations", "migration", "APPLIED"),
    ):
        for name in sorted(checks[group]):
            row = [prefix, name]
            if prefix == "foreign_key":
                row.append(name)
            elif prefix == "migration":
                row.append("9")
            row.append(healthy)
            data.append("\t".join(row))
    if mutation is not None:
        mutation(data)
    return "\n".join(data) + "\n"


class P0MysqlTsvImportTest(unittest.TestCase):
    def test_full_export_is_exactly_allowlisted_and_still_never_release_authorized(self):
        result = parse_tsv(fake_mysql_export())
        self.assertEqual("8.0.46", result["mysql_version"])
        self.assertEqual("SCHEMA_SELECTED", result["connection"])
        for group, keys in expected_keys().items():
            self.assertEqual(keys, set(result[group]))
        outcome = classify(result)
        self.assertEqual("REVIEW_REQUIRED", outcome["result"])
        self.assertEqual(["TARGETED_METADATA_MATCH_NOT_RELEASE"], outcome["codes"])
        self.assertIs(outcome["release_authorized"], False)

    def test_missing_fk_with_applied_ledger_stops_release(self):
        def mutation(data):
            i = next(i for i, line in enumerate(data)
                     if line.startswith("foreign_key\tlaea_answer_event_fk\t"))
            data[i] = data[i].replace("\tPASS", "\tMISSING")
        output = classify(parse_tsv(fake_mysql_export(mutation)))
        self.assertEqual("BLOCK", output["result"])
        self.assertIn("ADJUSTMENT_APPLIED_CONSTRAINT_DRIFT", output["codes"])

    def test_pending_ledger_stays_review_only(self):
        def mutation(data):
            i = next(i for i, line in enumerate(data)
                     if line.startswith("migration\t2026_10_08_230000_"))
            data[i] = data[i].replace("\t9\tAPPLIED", "\tNONE\tPENDING")
        status = classify(parse_tsv(fake_mysql_export(mutation)))
        self.assertEqual("REVIEW_REQUIRED", status["result"])
        self.assertIn("ADJUSTMENT_PENDING_LEDGER_REPLAY_REVIEW", status["codes"])

    def test_missing_row_duplicate_and_unexpected_item_rejected(self):
        original = fake_mysql_export()
        lines = original.splitlines()
        for bad in (
            "\n".join(lines[:-1]) + "\n",
            original + lines[-1] + "\n",
            original + "table\tsuper_admin_password\tPRESENT\n",
            original.replace("table\tusers\tPRESENT", "table\tusers\tUNKNOWN"),
        ):
            with self.subTest(case=bad[-70:]):
                with self.assertRaises(InventoryRejected):
                    parse_tsv(bad)

    def test_wrong_delimiters_nonascii_errors_and_invalid_batch_are_rejected(self):
        original = fake_mysql_export()
        for bad in (
            original.replace("\t", ","),
            original.replace("migration\t", "migration\t0\t", 1),
            original.replace("\t9\tAPPLIED", "\tNONE\tAPPLIED", 1),
            original.replace("\tPASS", "\tNOT_A_REAL_STATUS", 1),
            original.replace("8.0.46-Aiven", "8.0.46-SECRET/password"),
            original.rstrip("\n"),
        ):
            with self.subTest(case=bad[:80]):
                with self.assertRaises(InventoryRejected):
                    parse_tsv(bad)

    def test_raw_mysql_export_with_secret_like_unexpected_row_is_not_echoed(self):
        marker = "NEVER_ECHO_PRIVATE_DATABASE_CREDENTIAL_OR_EMAIL"
        malicious = fake_mysql_export() + "request_password\t" + marker + "\n"
        with tempfile.TemporaryDirectory() as root:
            path = Path(root) / "raw-synthetic.tsv"
            path.write_text(malicious, encoding="utf-8")
            script = Path(__file__).parents[2] / "scripts/ci/p0_mysql_inventory_tsv_import.py"
            proc = subprocess.run(
                [sys.executable, str(script), "--input", str(path)],
                capture_output=True, text=True, check=False,
            )
            self.assertEqual(2, proc.returncode)
            self.assertNotIn(marker, proc.stdout + proc.stderr)
            self.assertEqual("INVENTORY_IMPORT_REJECTED", json.loads(proc.stdout)["code"])

    def test_cli_success_outputs_sanitized_statuses_not_raw_observed_metadata(self):
        with tempfile.TemporaryDirectory() as root:
            path = Path(root) / "mysql-synthetic.tsv"
            path.write_text(fake_mysql_export(), encoding="utf-8")
            script = Path(__file__).parents[2] / "scripts/ci/p0_mysql_inventory_tsv_import.py"
            proc = subprocess.run(
                [sys.executable, str(script), "--input", str(path)],
                capture_output=True, text=True, check=False,
            )
            self.assertEqual(0, proc.returncode, proc.stderr)
            self.assertNotIn("8.0.46-Aiven", proc.stdout)
            payload = json.loads(proc.stdout)
            self.assertEqual("8.0.46", payload["mysql_version"])
            self.assertEqual("REVIEW_REQUIRED", classify(payload)["result"])


if __name__ == "__main__":
    unittest.main()
