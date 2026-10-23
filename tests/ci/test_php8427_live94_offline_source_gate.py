"""Negative-control tests for the legacy 94-source preflight (no DB/network)."""
import json
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

from scripts.ci.php8427_live94_offline_source_gate import (
    AUDIT_BLOBS, Block, LIVE_MIGRATION_COUNT, LIVE_MIGRATION_TREE, LIVE_SHA,
    run, safe_regular_path, sha1_blob, verify_sanitized_ledger_summary,
)

BASE = {
    "result": "REVIEW_REQUIRED",
    "code": "FULL_MIGRATION_LEDGER_MATCH_NOT_RELEASE",
    "release_authorized": False,
    "production_database_modified": False,
    "expected_count": 94,
    "applied_count": 94,
    "pending_count": 0,
    "forward_repair_pending": False,
    "other_pending_count": 0,
}


def as_bytes(v):
    return json.dumps(v, sort_keys=True).encode("utf-8")


class Legacy94GateTest(unittest.TestCase):
    def test_public_pins_and_expected_shape_are_frozen(self):
        self.assertEqual(LIVE_SHA, "15abdc9de6f24b5250fb255c579d1aa55facaf1a")
        self.assertEqual(LIVE_MIGRATION_TREE, "62dd8ce9bae3f73d1a390ea27242094e8165f9a2")
        self.assertEqual(LIVE_MIGRATION_COUNT, 94)
        self.assertEqual(len(AUDIT_BLOBS), 7)
        self.assertEqual(sha1_blob(b"test\n"), "9daeafb9864cf43055ae93beb0afd6c7d144bfa4")

    def test_exact_sanitized_94_ledger_is_review_not_release(self):
        verify_sanitized_ledger_summary(as_bytes(BASE))
        with patch("scripts.ci.php8427_live94_offline_source_gate.verify_source",
                   return_value={"source_head": LIVE_SHA, "audit_file_count": 7,
                                 "migration_count": 94}):
            with tempfile.TemporaryDirectory() as d:
                summary = Path(d) / "safe.json"
                summary.write_bytes(as_bytes(BASE))
                result = run(Path(d), summary)
                self.assertEqual(result["result"], "REVIEW_REQUIRED")
                self.assertFalse(result["live_db_schema_verified"])
                self.assertFalse(result["real_aiven_connected"])
                self.assertFalse(result["release_authorized"])
                self.assertEqual(result["code"], "LIVE94_SANITIZED_LEDGER_CONSISTENT_UNAUTHENTICATED")

    def test_any_known_pending_including_forward_repair_must_block(self):
        variants = [
            {"code": "KNOWN_PENDING_MIGRATIONS", "applied_count": 93,
             "pending_count": 1, "other_pending_count": 1},
            {"code": "KNOWN_PENDING_MIGRATIONS", "applied_count": 93,
             "pending_count": 1, "forward_repair_pending": True},
            {"code": "FULL_MIGRATION_LEDGER_MATCH_NOT_RELEASE", "applied_count": 93},
            {"result": "PASS"},
            {"release_authorized": True},
            {"production_database_modified": True},
            {"expected_count": 113},
            {"pending_count": True},
            {"forward_repair_pending": 0},
        ]
        for delta in variants:
            with self.subTest(delta=delta):
                with self.assertRaises(Block):
                    verify_sanitized_ledger_summary(as_bytes({**BASE, **delta}))

    def test_extra_duplicate_malformed_large_and_secret_fields_block_without_echo(self):
        altered = [
            b'{"result":"REVIEW_REQUIRED","result":"REVIEW_REQUIRED"}',
            as_bytes({**BASE, "database_password": "NEVER_ECHO_CANARY"}),
            as_bytes(BASE) + b"\nNEVER_ECHO_CANARY",
            b"\xff",
            b" ",
            b"0" * 4097,
        ]
        for raw in altered:
            with self.subTest(rawlen=len(raw)):
                with self.assertRaises(Block) as ctx:
                    verify_sanitized_ledger_summary(raw)
                self.assertNotIn("NEVER_ECHO_CANARY", str(ctx.exception))

    def test_symlinked_source_and_unsafe_working_directory_block(self):
        with tempfile.TemporaryDirectory() as tmp:
            d = Path(tmp)
            (d / "safe.txt").write_text("something", encoding="utf-8")
            (d / "alias").symlink_to(d / "safe.txt")
            with self.assertRaises(Block):
                safe_regular_path(d, "alias")
            result = run(d)
            self.assertEqual(result["result"], "BLOCK")
            self.assertEqual(result["code"], "LIVE94_OFFLINE_INTEGRITY_OR_SUMMARY_REJECTED")
            self.assertFalse(result["real_aiven_connected"])
            self.assertFalse(result["release_authorized"])

    def test_missing_summary_is_source_review_only_no_automatic_go(self):
        with patch("scripts.ci.php8427_live94_offline_source_gate.verify_source",
                   return_value={"source_head": LIVE_SHA, "audit_file_count": 7,
                                 "migration_count": 94}):
            result = run(Path("/nonexistent"))
            self.assertEqual(result["code"], "LIVE94_SOURCE_PINNED_NO_AIVEN")
            self.assertFalse(result["backup_restore_verified"])
            self.assertFalse(result["release_authorized"])


if __name__ == "__main__":
    unittest.main()
