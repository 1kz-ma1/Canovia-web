#!/usr/bin/env python3
"""Release #488: offline, exact and fail-closed Laravel ledger + schema preflight.

Consumes only operator-held MySQL SELECT TSV files. Never opens a database,
prints source names, displays credentials, or grants deployment permission.
"""
from __future__ import annotations

import argparse
import json
from pathlib import Path
import sys

sys.path.insert(0, str(Path(__file__).resolve().parents[2]))

from scripts.ci.p0_mysql_full_ledger_offline_check import (
    InvalidLedger,
    expected_migration_names,
    parse_full_ledger,
)

RELEASE_MIGRATION = "2026_10_12_010000_add_source_revision_to_development_activity_observations"
EPOCH_MIGRATION = "2026_10_13_021000_add_source_epoch_to_development_activity_observations"
REPAIR_MIGRATION = "2026_10_09_235959_reconcile_p0_mysql_applied_constraints"
SCHEMA_ROWS = {
    "base_table": {"PRESENT", "MISSING"},
    "source_updated_at": {"ABSENT", "VALID", "MISMATCH"},
    "source_revision_id": {"ABSENT", "VALID", "MISMATCH"},
    "source_epoch": {"ABSENT", "VALID", "MISMATCH"},
    "source_epoch_index": {"ABSENT", "VALID", "MISMATCH"},
}
MAX_SCHEMA_BYTES = 1024
MAX_LEDGER_BYTES = 131072


def parse_schema(raw: str) -> dict[str, str]:
    if not isinstance(raw, str) or len(raw.encode("utf-8")) > MAX_SCHEMA_BYTES:
        raise InvalidLedger()
    if not raw.isascii() or "\r" in raw or "\x00" in raw or not raw.endswith("\n"):
        raise InvalidLedger()
    lines = raw.splitlines()
    if len(lines) != len(SCHEMA_ROWS):
        raise InvalidLedger()
    values: dict[str, str] = {}
    for row in lines:
        cells = row.split("\t")
        if len(cells) != 3 or cells[0] != "release_488_schema":
            raise InvalidLedger()
        key, value = cells[1:]
        if key not in SCHEMA_ROWS or value not in SCHEMA_ROWS[key] or key in values:
            raise InvalidLedger()
        values[key] = value
    if set(values) != set(SCHEMA_ROWS):
        raise InvalidLedger()
    return values


def verdict(code: str, result: str = "BLOCK") -> dict:
    return {
        "result": result,
        "code": code,
        "release_authorized": False,
        "production_database_modified": False,
        "production_identity_verified": False,
        "backup_restore_verified": False,
    }


def compare_release(
    expected: set[str], applied: dict[str, int], schema: dict[str, str]
) -> dict:
    # All expected migration files are read directly from a pinned checkout.
    # Validate exact sets; a count alone can hide one unknown ledger entry.
    if (not expected or not {RELEASE_MIGRATION, EPOCH_MIGRATION, REPAIR_MIGRATION} <= expected
        or REPAIR_MIGRATION not in applied):
        return verdict("BASELINE_UNVERIFIED")
    if not applied or any(
        not isinstance(v, int) or isinstance(v, bool) or v <= 0 or v >= 10**9
        for v in applied.values()
    ):
        return verdict("LEDGER_INVALID")
    if set(applied) - expected:
        return verdict("UNKNOWN_APPLIED_MIGRATION")
    if schema.get("base_table") != "PRESENT":
        return verdict("OBSERVATION_TABLE_NOT_VERIFIED")
    pending = expected - set(applied)
    columns = tuple(schema.get(k) for k in (
        "source_updated_at", "source_revision_id", "source_epoch", "source_epoch_index"
    ))
    # MySQL DDL is nontransactional; intermediate or partial states are BLOCK.
    if pending == {RELEASE_MIGRATION, EPOCH_MIGRATION} and columns == ("ABSENT",) * 4:
        return verdict("EXACT_TWO_SOURCE_OBSERVATION_MIGRATIONS_PENDING", "REVIEW_REQUIRED")
    if not pending and columns == ("VALID",) * 4:
        return verdict("BOTH_SOURCE_OBSERVATION_MIGRATIONS_ALREADY_APPLIED", "REVIEW_REQUIRED")
    if pending not in ({RELEASE_MIGRATION, EPOCH_MIGRATION}, set()):
        return verdict("UNEXPECTED_MIGRATION_STATE")
    return verdict("MIGRATION_SCHEMA_LEDGER_CONFLICT")


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--ledger", required=True)
    parser.add_argument("--schema", required=True)
    args = parser.parse_args()
    try:
        ledger = Path(args.ledger)
        schema = Path(args.schema)
        if (not ledger.is_file() or ledger.is_symlink()
            or not schema.is_file() or schema.is_symlink()
            or ledger.stat().st_size > MAX_LEDGER_BYTES
            or schema.stat().st_size > MAX_SCHEMA_BYTES):
            raise InvalidLedger()
        applied = parse_full_ledger(ledger.read_bytes().decode("ascii"))
        state = parse_schema(schema.read_bytes().decode("ascii"))
        result = compare_release(expected_migration_names(), applied, state)
    except (InvalidLedger, UnicodeError, OSError, TypeError, ValueError):
        result = verdict("INPUT_INVALID")
    print(json.dumps(result, sort_keys=True))
    return 0 if result["result"] == "REVIEW_REQUIRED" else 2


if __name__ == "__main__":
    sys.exit(main())
