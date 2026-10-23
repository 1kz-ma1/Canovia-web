"""No-network negative controls for the pinned legacy SQL manifest."""
import hashlib
from pathlib import Path
import sys
import tempfile
import unittest
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[2]))

from scripts.ci.php8427_live94_frozen_sql_manifest import (
    INVENTORY_SECTIONS, LEDGER_SECTIONS, Block, build_manifest,
    statements_from_frozen_bytes,
)


class FrozenSQLManifestTest(unittest.TestCase):
    def test_fixed_inventory_and_ledger_sections(self):
        self.assertEqual(INVENTORY_SECTIONS, (
            "connection", "table", "column", "foreign_key", "index", "migration",
        ))
        self.assertEqual(LEDGER_SECTIONS, ("migration_ledger",))
        self.assertEqual(len(INVENTORY_SECTIONS) + len(LEDGER_SECTIONS), 7)

    def test_known_synthetic_readonly_queries_are_separated_by_exact_order(self):
        raw = b"-- only a comment\nSELECT 'connection' AS section, 1 AS observed_value;\nSELECT 'table' AS section, 2 AS status;\n"
        statements = statements_from_frozen_bytes(raw, ("connection", "table"))
        self.assertEqual(len(statements), 2)
        self.assertEqual(statements[0], b"SELECT 'connection' AS section, 1 AS observed_value;")
        self.assertEqual(statements[1], b"SELECT 'table' AS section, 2 AS status;")
        self.assertEqual(len(hashlib.sha256(statements[0]).hexdigest()), 64)

    def test_write_and_exfiltration_shapes_are_denied(self):
        suffixes = (
            " INTO OUTFILE '/tmp/x'",
            " INTO DUMPFILE '/tmp/x'",
            " FOR UPDATE",
            " LOCK IN SHARE MODE",
            " UNION SELECT SLEEP(60)",
            " UNION SELECT BENCHMARK(5, SHA1('x'))",
            " UNION SELECT LOAD_FILE('/etc/passwd')",
            " UNION SELECT GET_LOCK('x', 1)",
            " UNION SELECT RELEASE_LOCK('x')",
        )
        for suffix in suffixes:
            sql = ("SELECT 'migration_ledger' AS section, 1" + suffix + ";\n").encode()
            with self.subTest(suffix=suffix), self.assertRaises(Block):
                statements_from_frozen_bytes(sql, LEDGER_SECTIONS)
        for raw in (
            b"UPDATE users SET admin=1;",
            b"SELECT 'migration_ledger' AS section, 1; DELETE FROM users;",
            b"SELECT 'migration_ledger' AS section, 1;",
            b"SELECT 'migration_ledger' AS section, 1;\r\n",
            b"SELECT 'migration_ledger' AS section, 1;\x00",
            b"\xff",
            b"",
            b"SELECT 'migration_ledger' AS section, 1;\n-- comment ;\n",
            b"SELECT 'migration_ledger' AS section, 1 /*x*/;",
            b"SELECT 'migration_ledger' AS section, 1 #x;",
        ):
            # The plain SELECT without a final newline is deliberately valid:
            # it is an exact single statement; only format/SQL mutation is blocked.
            if raw == b"SELECT 'migration_ledger' AS section, 1;":
                self.assertEqual(len(statements_from_frozen_bytes(raw, LEDGER_SECTIONS)), 1)
                continue
            if raw == b"SELECT 'migration_ledger' AS section, 1;\n-- comment ;\n":
                self.assertEqual(len(statements_from_frozen_bytes(raw, LEDGER_SECTIONS)), 1)
                continue
            with self.subTest(raw=raw[:30]), self.assertRaises(Block):
                statements_from_frozen_bytes(raw, LEDGER_SECTIONS)

    def test_unexpected_section_extra_statement_and_query_length_block(self):
        bad = (
            (b"SELECT 'ledger' AS section, 1;", LEDGER_SECTIONS),
            (b"SELECT 'migration_ledger' AS section, 1; SELECT 1;", LEDGER_SECTIONS),
            (b"SELECT 'migration_ledger' AS section, 1", LEDGER_SECTIONS),
            (b"SELECT 'migration_ledger' AS section, 1;", INVENTORY_SECTIONS),
            (b"SELECT 'migration_ledger' AS section, 1, '" + b"x" * 8192 + b"';", LEDGER_SECTIONS),
        )
        for raw, phases in bad:
            with self.subTest(phase=phases, n=len(raw)), self.assertRaises(Block):
                statements_from_frozen_bytes(raw, phases)

    def test_no_source_pin_means_no_manifest_or_sql_receipt(self):
        with tempfile.TemporaryDirectory() as d:
            with self.assertRaises(Block):
                build_manifest(Path(d))
        with patch(
            "scripts.ci.php8427_live94_frozen_sql_manifest.verify_source",
            side_effect=Block,
        ):
            with self.assertRaises(Block):
                build_manifest(Path("/not-a-real-94-checkout"))


if __name__ == "__main__":
    unittest.main()
