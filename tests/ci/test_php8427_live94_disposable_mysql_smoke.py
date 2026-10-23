"""CI-only old-Live94 MySQL session verifier's synthetic negative cases."""
from pathlib import Path
import sys
import unittest
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[2]))

from scripts.ci.php8427_live94_disposable_mysql_smoke import (
    Block, DATABASE, identity, verify_result,
)

PRINCIPAL = "live94_ci@%"
SERVER = "01234567-abcd-abcd-abcd-0123456789ab"
FIELDS = ["31", SERVER, PRINCIPAL, DATABASE, "NONE", ""]
Q = chr(96)
ACCOUNT = Q + "live94_ci" + Q + "@" + Q + "%" + Q
GRANTS = [
    "GRANT USAGE ON *.* TO " + ACCOUNT,
    "GRANT REFERENCES ON " + Q + DATABASE + Q + ".* TO " + ACCOUNT,
    "GRANT SELECT ON " + Q + DATABASE + Q + "." + Q + "migrations" + Q + " TO " + ACCOUNT,
]


def mock_packet() -> bytes:
    body = [
        "CI_BEGIN\t" + "\t".join(FIELDS),
        "CI_GRANTS_BEGIN",
        *GRANTS,
        "CI_GRANTS_END",
        "connection\tmysql_version\t8.4.1\tSCHEMA_SELECTED",
        *("table\tx\tMISSING" for _ in range(24)),
        *("migration_ledger\t2026_01_01_%06d_test\t1" % i for i in range(94)),
        "CI_END\t" + "\t".join(FIELDS),
    ]
    return ("\n".join(body) + "\n").encode("ascii")


class SmokeVerifierTest(unittest.TestCase):
    def test_valid_fixture_still_hold_and_attacks_block(self):
        with patch(
            "scripts.ci.php8427_live94_disposable_mysql_smoke.build_manifest",
            return_value={"sql_statement_count": 7},
        ), patch(
            "scripts.ci.php8427_live94_disposable_mysql_smoke.parse_tsv",
            return_value={
                "mysql_version": "8.4.1", "connection": "SCHEMA_SELECTED",
                "tables": {"users": "PRESENT", "P0": "MISSING"},
            },
        ), patch(
            "scripts.ci.php8427_live94_disposable_mysql_smoke.parse_full_ledger",
            return_value={str(i): 1 for i in range(94)},
        ), patch(
            "scripts.ci.php8427_live94_disposable_mysql_smoke.expected_migration_names",
            return_value={str(i) for i in range(94)},
        ), patch(
            "scripts.ci.php8427_live94_disposable_mysql_smoke.compare_ledger",
            return_value={
                "code": "FULL_MIGRATION_LEDGER_MATCH_NOT_RELEASE",
                "release_authorized": False,
            },
        ):
            output = verify_result(mock_packet(), Path("/synthetic-only"))
            self.assertEqual(output["verdict"], "HOLD")
            self.assertTrue(output["same_client_connection_observed"])
            self.assertTrue(output["synthetic_physical_metadata_intentionally_incomplete"])
            for flag in ("real_tls_peer_authenticated", "remote_session_termination_verified",
                         "real_aiven_connected", "real_schema_verified", "production_go"):
                self.assertIs(output[flag], False)
            raw = mock_packet()
            attacks = [
                raw.replace(b"CI_END\t31\t", b"CI_END\t32\t"),
                raw.replace(b"CI_END\t31\t", b"CI_END\t0\t"),
                raw.replace(b"CI_END\t31\t" + SERVER.encode(),
                            b"CI_END\t31\t" + b"f" * 36),
                raw.replace(b"NONE\t\n", b"admin\t\n"),
                raw.replace(b"CI_GRANTS_BEGIN\n", b"CI_GRANTS_WRONG\n"),
                raw.replace(b"GRANT SELECT ON ", b"GRANT ALL PRIVILEGES ON "),
                raw.replace(b"CI_GRANTS_END\n", b"CI_GRANTS_END\nCI_GRANTS_END\n"),
                raw.replace(b"migration_ledger\t", b"other_ledger\t", 1),
                raw.replace(b"CI_END\t", b"WRONG_END\t"),
                raw[:-1],
                raw + b"EXTRA\n",
                raw + b"\x00",
                b"x" * 262145,
            ]
            for i, candidate in enumerate(attacks):
                with self.subTest(index=i), self.assertRaises(Block):
                    verify_result(candidate, Path("/synthetic-only"))

    def test_malformed_identity_rejected(self):
        for fields in (
            ["0", SERVER, PRINCIPAL, DATABASE, "NONE", ""],
            ["11", SERVER, "admin@%", DATABASE, "NONE", ""],
            ["11", SERVER, PRINCIPAL, "production", "NONE", ""],
            ["11", SERVER, PRINCIPAL, DATABASE, "admin", ""],
            ["11", SERVER, PRINCIPAL, DATABASE, "NONE", "role_admin"],
            ["11", "wrong", PRINCIPAL, DATABASE, "NONE", ""],
            ["11", SERVER, PRINCIPAL, DATABASE, "NONE"],
        ):
            with self.subTest(fields=fields), self.assertRaises(Block):
                identity(["CI_BEGIN", *fields], "CI_BEGIN")


if __name__ == "__main__":
    unittest.main()
