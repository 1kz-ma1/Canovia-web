"""Synthetic-only exact-94 offline ledger validation: never a release GO."""
import importlib.util
import json
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]
SCRIPT = ROOT / "scripts" / "ci" / "mcp_ap_live94_ledger_zero_pending.py"
sys.path.insert(0, str(SCRIPT.parent))
spec = importlib.util.spec_from_file_location("mcp_ap_live94_ledger", SCRIPT)
gate = importlib.util.module_from_spec(spec)
spec.loader.exec_module(gate)

SENTINEL = "PRIVATE_AIVEN_CONNECTION_7c9dd_NOT_FOR_LOGS"


def serialize(names):
    return "".join("migration_ledger\t" + name + "\t1\n" for name in sorted(names))


class LegacyLedgerGateTests(unittest.TestCase):
    def test_candidate_has_exactly_94_reviewed_migrations(self):
        names = gate.expected_migration_names()
        self.assertEqual(94, len(names))
        self.assertEqual("REVIEW_REQUIRED", gate.assess(names, {n:1 for n in names})["result"])

    def test_complete_94_ledger_is_still_review_only(self):
        names = gate.expected_migration_names()
        report = gate.assess(names, {n:1 for n in names})
        self.assertEqual("EXACT_94_APPLIED_REVIEW_ONLY", report["code"])
        self.assertTrue(report["zero_pending_verified"])
        for key in ("production_release_authorized", "physical_schema_verified",
                    "real_aiven_identity_verified", "independent_backup_restore_verified",
                    "production_database_modified"):
            self.assertFalse(report[key])
        self.assertNotIn(next(iter(names)), json.dumps(report))

    def test_known_pending_is_stop_not_review(self):
        names = gate.expected_migration_names()
        applied = {n:1 for n in names}
        applied.pop(next(iter(names)))
        d = gate.assess(names, applied)
        self.assertEqual("BLOCK", d["result"])
        self.assertEqual("PENDING_MIGRATIONS_STOP", d["code"])
        self.assertFalse(d["zero_pending_verified"])

    def test_unknown_applied_or_wrong_source_count_is_stop(self):
        names = gate.expected_migration_names()
        names2 = names | {"2026_10_19_000000_unreviewed_extra"}
        self.assertEqual("CANDIDATE_IS_NOT_PINNED_94_MIGRATIONS",
                         gate.assess(names2, {n:1 for n in names2})["code"])
        applied = {n:1 for n in names}
        applied["2026_10_19_000000_unreviewed_extra"] = 2
        self.assertEqual("UNKNOWN_APPLIED_MIGRATIONS",
                         gate.assess(names, applied)["code"])

    def test_empty_and_malformed_ledger_never_pass(self):
        names = gate.expected_migration_names()
        self.assertEqual("APPLIED_LEDGER_EMPTY", gate.assess(names, {})["code"])
        malformed = (
            "", "migration_ledger\t", SENTINEL, "migration_ledger\tsecret\t1\n",
            serialize(names) + "migration_ledger\t2026_10_19_000000_hello\t1\n",
            serialize(names)[:-1],
            serialize(names).replace("\t1\n", "\t0\n", 1),
            serialize(names).replace("\t1\n", "\t1\r\n", 1),
            serialize(names) + serialize(names).splitlines(keepends=True)[0],
        )
        for raw in malformed:
            with self.subTest(size=len(raw)):
                with tempfile.TemporaryDirectory() as temp:
                    file = Path(temp) / "ledger.tsv"
                    file.write_text(raw)
                    process = subprocess.run(
                        [sys.executable, str(SCRIPT), "--input", str(file)],
                        capture_output=True, text=True, timeout=5)
                self.assertEqual(2, process.returncode)
                self.assertEqual("BLOCK", json.loads(process.stdout)["result"])
                self.assertNotIn(SENTINEL, process.stdout + process.stderr)

    def test_private_offline_file_or_stdin_read_is_not_release_authorization(self):
        with tempfile.TemporaryDirectory() as temp:
            file = Path(temp) / "ledger.tsv"
            file.write_text(serialize(gate.expected_migration_names()))
            ok = subprocess.run([sys.executable, str(SCRIPT), "--input", str(file)],
                                capture_output=True, text=True, timeout=5)
            self.assertEqual(0, ok.returncode)
            d = json.loads(ok.stdout)
            self.assertEqual("REVIEW_REQUIRED", d["result"])
            self.assertFalse(d["production_release_authorized"])
            symlink = Path(temp) / "symlink.tsv"
            symlink.symlink_to(file)
            bad = subprocess.run([sys.executable, str(SCRIPT), "--input", str(symlink)],
                                 capture_output=True, text=True, timeout=5)
            self.assertEqual(2, bad.returncode)
            pipe = subprocess.run([sys.executable, str(SCRIPT), "--input", "-"],
                                  input=serialize(gate.expected_migration_names()),
                                  capture_output=True, text=True, timeout=5)
            self.assertEqual(0, pipe.returncode)

    def test_raw_tsv_input_not_echoed_or_persisted(self):
        bogus = SENTINEL+"\n"
        out = subprocess.run([sys.executable, str(SCRIPT), "--input", "-"],
                             input=bogus, capture_output=True, text=True, timeout=5)
        self.assertEqual(2, out.returncode)
        self.assertNotIn(SENTINEL, out.stdout + out.stderr)
        self.assertEqual("LEDGER_EVIDENCE_MISSING_OR_INVALID",
                         json.loads(out.stdout)["code"])

    def test_oversized_stream_is_denied(self):
        out = subprocess.run([sys.executable, str(SCRIPT), "--input", "-"],
                             input="x" * 131073, capture_output=True, text=True, timeout=5)
        self.assertEqual(2, out.returncode)
        self.assertFalse(json.loads(out.stdout)["production_release_authorized"])


if __name__ == "__main__":
    unittest.main()
