#!/usr/bin/env python3
"""Offline, fail-closed comparison of complete Laravel migration files and SQL ledger.

Accept ONLY the SELECT output of p0_mysql_readonly_full_migration_ledger.sql
in MySQL CLI batch TSV format. Does not connect to DB or read personal data.
Never echoes untrusted migration names, file paths or input values.
"""

import argparse
import json
from pathlib import Path
import re
import sys

REPO_ROOT = Path(__file__).resolve().parents[2]
EXPECTED_DIR = REPO_ROOT / "database" / "migrations"
MAX_INPUT_BYTES = 131072
MAX_RECORDS = 4000
VALID_MIGRATION = re.compile(r"^\d{4}_\d{2}_\d{2}_\d{6}_[a-z][a-z0-9_]*$")
VALID_BATCH = re.compile(r"^[1-9][0-9]{0,8}$")


class InvalidLedger(ValueError):
    """Generic error that never contains user or SQL input."""


def expected_migration_names(directory: Path = EXPECTED_DIR) -> set[str]:
    if not directory.is_dir():
        raise InvalidLedger()
    paths = list(directory.glob("*.php"))
    if not paths or len(paths) > MAX_RECORDS:
        raise InvalidLedger()
    names = set()
    for path in paths:
        if not path.is_file() or path.is_symlink() or not VALID_MIGRATION.fullmatch(path.stem):
            raise InvalidLedger()
        if path.stem in names:
            raise InvalidLedger()
        names.add(path.stem)
    return names


def parse_full_ledger(raw: str) -> dict[str, int]:
    if not isinstance(raw, str) or len(raw.encode("utf-8")) > MAX_INPUT_BYTES:
        raise InvalidLedger()
    # str.splitlines() removes CR before per-row checks; reject CRLF and
    # Unicode line separators before splitting to avoid format ambiguity.
    if "\r" in raw or not raw.isascii():
        raise InvalidLedger()
    if not raw.endswith("\n"):
        raise InvalidLedger()
    rows = raw.splitlines()
    if not rows or len(rows) > MAX_RECORDS:
        raise InvalidLedger()
    actual = {}
    for row in rows:
        if not row or "\r" in row or "\x00" in row:
            raise InvalidLedger()
        cells = row.split("\t")
        if len(cells) != 3 or cells[0] != "migration_ledger":
            raise InvalidLedger()
        migration_name, batch = cells[1], cells[2]
        if (not VALID_MIGRATION.fullmatch(migration_name)
            or not VALID_BATCH.fullmatch(batch)
            or migration_name in actual):
            raise InvalidLedger()
        actual[migration_name] = int(batch)
    return actual


def compare_ledger(expected: set[str], applied: dict[str, int]) -> dict:
    """A successful comparison is REVIEW_REQUIRED, never deployment approval."""
    if not expected or not applied:
        return result("BLOCK", "LEDGER_EMPTY_OR_UNVERIFIED")
    # Do not emit raw migration identifiers (may expose private naming).
    if not all(isinstance(k, str) and VALID_MIGRATION.fullmatch(k)
               for k in expected | set(applied)):
        return result("BLOCK", "LEDGER_INVALID")
    if not all(isinstance(v, int) and not isinstance(v, bool) and 0 < v < 1000000000
               for v in applied.values()):
        return result("BLOCK", "LEDGER_INVALID")
    if set(applied) - expected:
        return result("BLOCK", "UNKNOWN_APPLIED_MIGRATION")
    if expected - set(applied):
        return result("REVIEW_REQUIRED", "KNOWN_PENDING_MIGRATIONS")
    return result("REVIEW_REQUIRED", "FULL_MIGRATION_LEDGER_MATCH_NOT_RELEASE")


def result(status: str, reason: str) -> dict:
    assert status in {"BLOCK", "REVIEW_REQUIRED"}
    return {
        "result": status,
        "code": reason,
        "release_authorized": False,
        "production_database_modified": False,
    }


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--input", required=True, metavar="LOCAL_TSV_OR_DASH",
                        help="Private local output file, or '-' for stdin.")
    args = parser.parse_args()

    try:
        if args.input == "-":
            data = sys.stdin.buffer.read(MAX_INPUT_BYTES + 1)
        else:
            path = Path(args.input)
            if not path.is_file() or path.stat().st_size > MAX_INPUT_BYTES:
                raise InvalidLedger()
            data = path.read_bytes()
        actual = parse_full_ledger(data.decode("utf-8", errors="strict"))
        expected = expected_migration_names()
        outcome = compare_ledger(expected, actual)
    except (InvalidLedger, UnicodeError, ValueError, TypeError, OSError):
        outcome = result("BLOCK", "FULL_LEDGER_INPUT_REJECTED")

    print(json.dumps(outcome, sort_keys=True))
    # Exit 0 only for a format-valid report requiring human review.
    # This exit status is NEVER release approval.
    return 2 if outcome["result"] == "BLOCK" else 0


if __name__ == "__main__":
    sys.exit(main())
