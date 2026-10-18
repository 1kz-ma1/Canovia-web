#!/usr/bin/env python3
"""Read-only offline gate: old-Live AP pilot must need ZERO DB migrations.

Operator collects migrations-only TSV through independently approved, restricted
Aiven metadata connection, not through a production Laravel process.
Do not submit the raw TSV or database identifiers to GitHub, CI or ChatGPT.
Successful source/ledger matching is NEVER a backup, schema or release GO.
"""
from __future__ import annotations

import argparse
import json
from pathlib import Path
import sys

from p0_mysql_full_ledger_offline_check import (
    InvalidLedger,
    expected_migration_names,
    parse_full_ledger,
)

LIVE94_EXPECTED_COUNT = 94
MAX_LEDGER_BYTES = 131072


def verdict(code: str, status: str = "BLOCK") -> dict:
    return {
        "result": status,
        "code": code,
        "expected_migrations": LIVE94_EXPECTED_COUNT,
        "zero_pending_verified": status == "REVIEW_REQUIRED",
        "production_release_authorized": False,
        "physical_schema_verified": False,
        "real_aiven_identity_verified": False,
        "independent_backup_restore_verified": False,
        "production_database_modified": False,
    }


def assess(source_names: set[str], applied: dict[str, int]) -> dict:
    if len(source_names) != LIVE94_EXPECTED_COUNT:
        return verdict("CANDIDATE_IS_NOT_PINNED_94_MIGRATIONS")
    if not applied:
        return verdict("APPLIED_LEDGER_EMPTY")
    if set(applied) - source_names:
        return verdict("UNKNOWN_APPLIED_MIGRATIONS")
    if source_names - set(applied):
        return verdict("PENDING_MIGRATIONS_STOP")
    # parse_full_ledger rejects duplicate/invalid names, batch IDs and format.
    return verdict("EXACT_94_APPLIED_REVIEW_ONLY", "REVIEW_REQUIRED")


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--input", required=True, metavar="PRIVATE_TSV_OR_DASH")
    args = parser.parse_args()
    try:
        if args.input == "-":
            data = sys.stdin.buffer.read(MAX_LEDGER_BYTES + 1)
        else:
            path = Path(args.input)
            if path.is_symlink() or not path.is_file() or path.stat().st_size > MAX_LEDGER_BYTES:
                raise InvalidLedger()
            data = path.read_bytes()
        if len(data) > MAX_LEDGER_BYTES:
            raise InvalidLedger()
        applied = parse_full_ledger(data.decode("ascii"))
        expected = expected_migration_names()
        result = assess(expected, applied)
    except (InvalidLedger, UnicodeError, ValueError, TypeError, OSError):
        result = verdict("LEDGER_EVIDENCE_MISSING_OR_INVALID")
    print(json.dumps(result, sort_keys=True))
    return 0 if result["result"] == "REVIEW_REQUIRED" else 2


if __name__ == "__main__":
    sys.exit(main())
