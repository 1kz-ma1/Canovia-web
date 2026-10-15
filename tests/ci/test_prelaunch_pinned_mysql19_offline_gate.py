"""No-network, no-database tests for the 94->113 pinned read-only auditor."""
import json
import re
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

from scripts.ci.prelaunch_pinned_mysql19_offline_gate import (
    FIELDS, BASELINE, UPGRADED, LIVE_SHA, CANDIDATE_SHA,
    compare, parse_snapshot, pinned_migrations,
)
from scripts.ci.p0_mysql_full_ledger_offline_check import InvalidLedger

ROOT = Path(__file__).resolve().parents[2]
OLD = {"2026_10_01_010000_synthetic_%03d" % i for i in range(94)}
NEW = OLD | {"2026_10_15_020000_candidate_%03d" % i for i in range(19)}


def make_snapshot(shape=BASELINE, engine="MYSQL_8_4", log="ON", trusted="OFF"):
    state = dict(shape)
    state.update(engine_series=engine, binary_logging=log,
                 trusted_function_creators=trusted)
    return "".join("pinned_19_schema\t%s\t%s\n" % (k, state[k]) for k in FIELDS)


def make_ledger(names):
    return {name: 1 for name in names}


class PinnedMigrationAuditTest(unittest.TestCase):
    def test_exact_94_pending_19_and_113_applied_review_only(self):
        for expected, shape, code in [
            (OLD, BASELINE, "PINNED_94_PENDING_19_METADATA_MATCH"),
            (NEW, UPGRADED, "PINNED_113_APPLIED_METADATA_MATCH"),
        ]:
            with self.subTest(expected=len(expected)):
                state = parse_snapshot(make_snapshot(shape))
                r = compare(OLD, NEW, make_ledger(expected), state)
                self.assertEqual("REVIEW_REQUIRED", r["result"])
                self.assertEqual(code, r["code"])
                self.assertEqual(len(expected), r["applied_count"])
                for k in [
                    "release_authorized", "production_database_modified",
                    "production_identity_independently_verified",
                    "production_migration_permissions_verified",
                    "production_trigger_ddl_authorized", "backup_restore_verified",
                    "real_device_acceptance_verified",
                ]:
                    self.assertIs(r[k], False)
                self.assertIs(r["requires_runtime_principal_ddl_grants_review"], True)
                self.assertNotIn("synthetic", json.dumps(r))
                self.assertNotIn("mcp_linked", json.dumps(r))

    def test_schema_partial_ddl_and_ledger_disagreement_block(self):
        for changes in [
            {"mcp_old_unique": "ABSENT"},
            {"mcp_active_unique": "PRESENT"},
            {"plan_epoch": "VALID"},
            {"grant_expiry": "NEW"},
            {"new_tables": "PRESENT"},
            {"mcp_active_fingerprint": "VALID"},
            {"observation_source_cols": "MISMATCH"},
            {"legacy_tables": "MISSING"},
        ]:
            with self.subTest(changes=changes):
                s = parse_snapshot(make_snapshot(dict(BASELINE, **changes)))
                r = compare(OLD, NEW, make_ledger(OLD), s)
                self.assertEqual("BLOCK", r["result"])
                self.assertEqual("LEDGER_AND_PHYSICAL_SCHEMA_CONFLICT", r["code"])
        for changes in [
            {"mcp_old_unique": "PRESENT"},
            {"mcp_active_unique": "ABSENT"},
            {"member_epoch": "ABSENT"},
            {"new_tables": "MISMATCH"},
        ]:
            with self.subTest(changes=changes):
                s = parse_snapshot(make_snapshot(dict(UPGRADED, **changes)))
                self.assertEqual("BLOCK", compare(OLD, NEW, make_ledger(NEW), s)["result"])

    def test_unexpected_applied_or_intermediate_ledger_block(self):
        s = parse_snapshot(make_snapshot())
        for names in [set(), OLD - {next(iter(OLD))},
                      OLD | {next(iter(NEW - OLD))},
                      OLD | {"2026_10_31_000000_unknown"}]:
            with self.subTest(size=len(names)):
                r = compare(OLD, NEW, make_ledger(names), s)
                self.assertEqual("BLOCK", r["result"])
        self.assertEqual("BLOCK", compare(set(), NEW, make_ledger(OLD), s)["result"])
        self.assertEqual("BLOCK", compare(OLD, NEW | {"extra"}, make_ledger(OLD), s)["result"])

    def test_capability_signal_never_grants_app_ddl_privileges(self):
        for log in ("ON", "OFF"):
            for trusted in ("ON", "OFF"):
                state = parse_snapshot(make_snapshot(log=log, trusted=trusted))
                r = compare(OLD, NEW, make_ledger(OLD), state)
                self.assertEqual("REVIEW_REQUIRED", r["result"])
                self.assertFalse(r["production_trigger_ddl_authorized"])
                self.assertFalse(r["production_migration_permissions_verified"])
        self.assertEqual("BLOCK", compare(
            OLD, NEW, make_ledger(OLD),
            parse_snapshot(make_snapshot(engine="OTHER"))
        )["result"])

    def test_schema_rejects_duplicates_injected_values_and_truncated_rows(self):
        raw = make_snapshot()
        for bad in [
            "", raw[:-1], raw + "pinned_19_schema\tplan_epoch\tABSENT\n",
            raw.replace("\n", "\r\n"),
            raw.replace("plan_epoch\tABSENT", "plan_epoch\tSECRET"),
            raw.replace("legacy_tables", "secret_host"),
            raw.replace("MYSQL_8_4", "root@private:3306"),
            raw.encode().decode() + "A" * 4096,
        ]:
            with self.subTest(size=len(bad)):
                with self.assertRaises(InvalidLedger):
                    parse_snapshot(bad)
        self.assertEqual(set(FIELDS), set(parse_snapshot(raw)))

    def test_unpinned_or_missing_live_checkout_fails_closed(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            with self.assertRaises(InvalidLedger):
                pinned_migrations(root, LIVE_SHA)
            self.assertNotEqual(LIVE_SHA, CANDIDATE_SHA)

    def test_cli_malformed_private_inputs_does_not_echo_them(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            (root / "ledger").write_text("password=never_echo\n")
            (root / "snapshot").write_text(make_snapshot())
            p = subprocess.run([
                sys.executable, str(ROOT / "scripts/ci/prelaunch_pinned_mysql19_offline_gate.py"),
                "--live-root", str(root), "--candidate-root", str(root),
                "--ledger", str(root / "ledger"), "--schema", str(root / "snapshot"),
            ], capture_output=True, text=True)
            self.assertEqual(2, p.returncode)
            payload = json.loads(p.stdout)
            self.assertEqual("BLOCK", payload["result"])
            self.assertFalse(payload["release_authorized"])
            self.assertNotIn("password", p.stdout + p.stderr)
            self.assertNotIn(str(root), p.stdout + p.stderr)

    def test_metadata_sql_and_operator_collector_are_read_only(self):
        sql = (ROOT / "scripts/sql/prelaunch_pinned_mysql19_select_only_snapshot.sql").read_text()
        active = "\n".join(line for line in sql.splitlines() if not line.strip().startswith("--"))
        for forbidden in ("INSERT", "UPDATE", "DELETE", "DROP", "CREATE",
                          "ALTER", "GRANT", "REVOKE", "SET", "INTO OUTFILE"):
            self.assertIsNone(re.search(r"\b" + forbidden + r"\b", active, re.I),
                              forbidden)
        self.assertIn("information_schema", active)
        sh = (ROOT / "scripts/ops/prelaunch_pinned_mysql19_collect.sh").read_text()
        self.assertIn("--ssl-mode=VERIFY_IDENTITY", sh)
        self.assertIn("p0_mysql_metadata_grants_guard.py", sh)
        self.assertIn("CI_NOT_OPERATOR", sh)
        self.assertNotIn("artisan migrate", sh)
        self.assertIn("PRODUCTION_RELEASE_AUTHORIZED: false", sh)
