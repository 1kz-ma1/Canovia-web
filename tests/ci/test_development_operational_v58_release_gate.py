"""V5.8 offline MySQL operational release matrix; no provider connection."""
import json
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest

from scripts.ci.development_operational_v58_release_gate import (
    PROFILE_BASE, TARGET_BASE, V53, V57, KEYS, classify, parse_schema,
)
from scripts.ci.p0_mysql_full_ledger_offline_check import (
    InvalidLedger, expected_migration_names, parse_full_ledger,
)

ROOT = Path(__file__).resolve().parents[2]
EXPECTED = expected_migration_names()
BASE = EXPECTED - {V53, V57}
FIELDS_V53 = {"service_checked_at", "service_checked_revision", "service_checked_environment"}
FIELDS_V57 = {"data_store_revision", "backup_store_revision", "restore_store_revision"}


def as_ledger(names):
    return "".join(f"migration_ledger\t{name}\t1\n" for name in sorted(names))


def as_schema(service=False, storage=False, overrides=None):
    states = {}
    for key in KEYS:
        if key in {"profile_table", "target_table"}:
            states[key] = "PRESENT"
        elif key in FIELDS_V53:
            states[key] = "VALID" if service else "ABSENT"
        else:
            states[key] = "VALID" if storage else "ABSENT"
    states.update(overrides or {})
    return "".join(f"v58_schema\t{k}\t{v}\n" for k, v in sorted(states.items()))


class DevelopmentOperationalV58ReleaseTest(unittest.TestCase):
    def test_pinned_schema_states_are_review_only_never_release_permission(self):
        self.assertTrue({PROFILE_BASE, TARGET_BASE, V53, V57} <= EXPECTED)
        cases = [
            (BASE, as_schema(), "EXACT_V53_V57_PENDING"),
            (BASE | {V53}, as_schema(service=True), "V53_APPLIED_V57_PENDING"),
            (EXPECTED, as_schema(service=True, storage=True),
             "BOTH_MIGRATIONS_APPLIED_NOT_RELEASE_GO"),
        ]
        for names, raw, code in cases:
            with self.subTest(code=code):
                result = classify(EXPECTED, parse_full_ledger(as_ledger(names)), parse_schema(raw))
                self.assertEqual("REVIEW_REQUIRED", result["result"])
                self.assertEqual(code, result["code"])
                for field in ("release_authorized", "production_database_modified",
                              "production_identity_verified", "backup_restore_verified",
                              "deployed_sha_verified", "device_acceptance_verified"):
                    self.assertFalse(result[field])

    def test_fails_closed_for_partial_ddl_order_and_other_pending(self):
        cases = [
            (BASE, as_schema(service=True), "PARTIAL_DDL_OR_LEDGER_SCHEMA_DRIFT"),
            (BASE, as_schema(storage=True), "PARTIAL_DDL_OR_LEDGER_SCHEMA_DRIFT"),
            (BASE | {V53}, as_schema(service=True, storage=True),
             "PARTIAL_DDL_OR_LEDGER_SCHEMA_DRIFT"),
            (EXPECTED, as_schema(service=True), "PARTIAL_DDL_OR_LEDGER_SCHEMA_DRIFT"),
            (BASE | {V57}, as_schema(storage=True), "MIGRATION_ORDER_INVALID"),
            (EXPECTED, as_schema(service=True, storage=True,
                                  overrides={"restore_store_revision": "MISMATCH"}),
             "COLUMN_SHAPE_MISMATCH"),
            (BASE, as_schema(overrides={"profile_table": "MISSING"}), "BASE_TABLE_MISSING"),
            (BASE - {PROFILE_BASE}, as_schema(), "BASELINE_LEDGER_INCOMPLETE"),
            (BASE | {V53, V57, "2099_01_01_000000_unknown_migration"},
             as_schema(service=True, storage=True), "MIGRATION_LEDGER_INVALID"),
        ]
        for names, raw, code in cases:
            with self.subTest(code=code):
                result = classify(EXPECTED, parse_full_ledger(as_ledger(names)), parse_schema(raw))
                self.assertEqual("BLOCK", result["result"])
                self.assertEqual(code, result["code"])
                self.assertFalse(result["release_authorized"])

        # A checked-out source revision with some unrelated new migration
        # cannot pass a narrowly scoped gate on an older Live ledger.
        synthetic_pending = EXPECTED | {"2099_01_01_000000_extra_pending"}
        out = classify(synthetic_pending, parse_full_ledger(as_ledger(BASE)), parse_schema(as_schema()))
        self.assertEqual("OTHER_PENDING_MIGRATIONS_REQUIRE_REVIEW", out["code"])

    def test_untrusted_malformed_schema_must_not_echo_tokens(self):
        malformed = [
            "", as_schema()[:-1], as_schema() + "api_key\tSECRET\n",
            as_schema().replace("ABSENT", "SECRET-PASSWORD", 1),
            as_schema().replace("service_checked_at", "service_checked_revision"),
            as_schema().replace("\n", "\r\n"),
            as_schema() + as_schema(),
        ]
        for raw in malformed:
            with self.subTest(length=len(raw)):
                with self.assertRaises(InvalidLedger):
                    parse_schema(raw)

    def test_cli_pinned_checkout_and_bad_data_are_safe(self):
        script = ROOT / "scripts/ci/development_operational_v58_release_gate.py"
        head = subprocess.run(["git", "rev-parse", "HEAD"], cwd=ROOT, check=True,
                              text=True, capture_output=True).stdout.strip()
        with tempfile.TemporaryDirectory() as tmp:
            ledger = Path(tmp) / "ledger.tsv"
            schema = Path(tmp) / "schema.tsv"
            ledger.write_text(as_ledger(EXPECTED))
            schema.write_text(as_schema(service=True, storage=True))
            cmd = [sys.executable, str(script), "--candidate-sha", head,
                   "--ledger", str(ledger), "--schema", str(schema)]
            ok = subprocess.run(cmd, capture_output=True, text=True)
            self.assertEqual(0, ok.returncode, ok.stderr)
            self.assertEqual("BOTH_MIGRATIONS_APPLIED_NOT_RELEASE_GO",
                             json.loads(ok.stdout)["code"])
            denied = subprocess.run(
                cmd[:3] + ["0" * 40] + cmd[4:], capture_output=True, text=True)
            self.assertEqual(2, denied.returncode)
            self.assertEqual("CANDIDATE_CHECKOUT_MISMATCH",
                             json.loads(denied.stdout)["code"])
            ledger.write_text(as_ledger(EXPECTED) + "password=mypassword\n")
            invalid = subprocess.run(cmd, capture_output=True, text=True)
            self.assertEqual(2, invalid.returncode)
            self.assertEqual("INPUT_INVALID", json.loads(invalid.stdout)["code"])
            self.assertNotIn("mypassword", invalid.stdout + invalid.stderr)

    def test_schema_sql_is_fixed_select_only_metadata(self):
        sql = (ROOT / "scripts/sql/development_operational_v58_schema_snapshot.sql").read_text()
        statements = "\n".join(
            line for line in sql.splitlines()
            if line.strip() and not line.lstrip().startswith("--")
        ).lower()
        self.assertTrue(statements.startswith("select "))
        self.assertEqual(1, statements.count(";"))
        self.assertIn("information_schema.tables", statements)
        self.assertIn("information_schema.columns", statements)
        self.assertNotIn("from users", statements)
        for phrase in (" alter ", " drop ", " truncate ", " update ", " delete ",
                       " insert ", " create ", " grant ", " call "):
            self.assertNotIn(phrase, statements)


if __name__ == "__main__":
    unittest.main()
