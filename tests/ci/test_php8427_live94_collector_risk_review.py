"""Purely offline tests for legacy Live94 collector review (never runs mysql)."""
from pathlib import Path
import sys
import tempfile
import unittest
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[2]))

from scripts.ci.php8427_live94_collector_risk_review import (
    FIXED_RISKS, blocked, review, run,
)

CALL = 'mysql "' + '$' + '{mysql_args[@]}"'
FIXTURE = "\n".join((
    "#!/usr/bin/env bash",
    "mysql_args=(",
    "  --no-defaults",
    '  "--login-path=$login"',
    "  --ssl-mode=VERIFY_IDENTITY",
    '  "--ssl-ca=$ca"',
    ")",
    "CANOVIA_P0_OPERATOR_APPROVED=YES",
    "CANOVIA_P0_TARGET_VERIFIED=YES",
    "CANOVIA_P0_GRANTS_REVIEWED=YES",
    "p0_mysql_metadata_grants_guard.py",
    CALL + " -e 'SHOW GRANTS FOR CURRENT_USER()'",
    CALL + ' < "$repo/scripts/sql/p0_mysql_readonly_schema_inventory.sql"',
    CALL + ' < "$repo/scripts/sql/p0_mysql_readonly_full_migration_ledger.sql"',
    "printf 'PRODUCTION_RELEASE_AUTHORIZED: false'",
))


class ReviewTest(unittest.TestCase):
    def test_synthetic_contract_is_observed_but_hold(self):
        result = review(FIXTURE, source_verified=True)
        self.assertEqual(result["verdict"], "HOLD")
        self.assertEqual(result["code"], "PINNED_LEGACY_COLLECTOR_HAS_OPEN_LIVE_SAFETY_GATES")
        self.assertTrue(result["source_locked"])
        self.assertEqual(result["open_risks"], list(FIXED_RISKS))
        for name in ("owner_go", "production_go", "backup_restorable",
                     "schema_94_verified", "real_aiven_connection_executed"):
            self.assertIs(result[name], False)

    def test_no_verified_source_never_accepted(self):
        result = review(FIXTURE, source_verified=False)
        self.assertEqual(result["code"], "SOURCE_NOT_PINNED")
        self.assertFalse(result["source_locked"])

    def test_missing_or_reordered_critical_actions_block(self):
        variants = (
            FIXTURE.replace("--ssl-mode=VERIFY_IDENTITY", "--ssl-mode=DISABLED"),
            FIXTURE.replace('"--ssl-ca=$ca"', ""),
            FIXTURE.replace("--no-defaults", ""),
            FIXTURE.replace("SHOW GRANTS FOR CURRENT_USER()", "SHOW TABLES"),
            FIXTURE.replace("p0_mysql_metadata_grants_guard.py", "fake-grant-checker.py"),
            FIXTURE.replace("p0_mysql_readonly_schema_inventory.sql", "wrong.sql"),
            FIXTURE.replace("p0_mysql_readonly_full_migration_ledger.sql", "wrong.sql"),
            FIXTURE.replace("CANOVIA_P0_GRANTS_REVIEWED=YES", ""),
            FIXTURE.replace("PRODUCTION_RELEASE_AUTHORIZED: false", "PRODUCTION_RELEASE_AUTHORIZED: true"),
            FIXTURE.replace(CALL + ' < "$repo/scripts/sql/p0_mysql_readonly_schema_inventory.sql"',
                            CALL + ' < "$repo/scripts/sql/changed.sql"'),
            FIXTURE.replace("SHOW GRANTS FOR CURRENT_USER()", "SHOW GRANTS FROM SOMETHING"),
            FIXTURE.replace(CALL + " -e 'SHOW GRANTS FOR CURRENT_USER()'", ""),
        )
        for src in variants:
            with self.subTest(variant=variants.index(src)):
                result = review(src, source_verified=True)
                self.assertEqual(result["verdict"], "HOLD")
                self.assertEqual(result["code"], "LEGACY_COLLECTOR_CONTRACT_DRIFT")
                self.assertFalse(result["production_go"])

    def test_unexpected_source_format_and_refusal_classifications(self):
        for src in (None, 123, "", "X" * 20001):
            with self.subTest(src_type=str(type(src))):
                result = review(src, source_verified=True)
                if src == "":
                    self.assertEqual(result["code"], "LEGACY_COLLECTOR_CONTRACT_DRIFT")
                else:
                    self.assertEqual(result["code"], "SOURCE_NOT_PINNED")
        for src in (FIXTURE + "\r", FIXTURE + "\x00"):
            self.assertEqual(review(src, source_verified=True)["code"],
                             "UNEXPECTED_COLLECTOR_FORMAT")
        self.assertFalse(blocked("SOURCE_NOT_PINNED")["owner_go"])

    def test_no_clean_pinned_checkout_is_hold_and_no_secret_echo(self):
        with tempfile.TemporaryDirectory() as d:
            result = run(Path(d))
            self.assertEqual(result["verdict"], "HOLD")
            self.assertEqual(result["code"], "SOURCE_NOT_PINNED")
            self.assertNotIn("password", str(result).lower())
            self.assertFalse(result["real_aiven_connection_executed"])

    def test_gate_requires_attested_source_before_read(self):
        with tempfile.TemporaryDirectory() as d:
            x = Path(d)
            with patch("scripts.ci.php8427_live94_collector_risk_review.verify_source",
                       side_effect=Exception("CANARY_SHOULD_NOT_LEAK")):
                # Unspecified local/internal errors must not be treated as PASS.
                with self.assertRaises(Exception):
                    run(x)


if __name__ == "__main__":
    unittest.main()
