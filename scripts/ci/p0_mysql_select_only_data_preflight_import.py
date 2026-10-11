#!/usr/bin/env python3
"""Offline fail-closed importer for the P0 SELECT-only data preflight.

MySQL CLI input must use --batch --raw --skip-column-names, exactly as
produced by the checked-in SQL. No DB/network access or raw values echoed.
This output is NOT permission to run a migration.
"""
import argparse
import json
from pathlib import Path
import sys

MAX_BYTES = 8192
EXPECTED = {
    "engine": {"mysql8"},
    "fk_metadata": {"idt_user", "idt_plan", "idt_snapshot", "laea_event", "laea_user"},
    "orphan": {"idt_user", "idt_plan", "idt_snapshot", "laea_event", "laea_user"},
    "unique": {"idt_decision_reference", "laea_answer_event"},
}
STATUS_CODES = {
    "engine": "UNSUPPORTED_MYSQL_ENGINE",
    "fk_metadata": "FK_METADATA_INCOMPATIBLE",
    "orphan": "ORPHANED_REFERENCES_PRESENT",
    "unique": "DUPLICATE_UNIQUE_VALUES_PRESENT",
}


class InvalidPreflight(ValueError):
    """Reject invalid source evidence without echoing its contents."""


def parse_status_tsv(raw: str) -> dict:
    if (not isinstance(raw, str)
        or len(raw.encode("utf-8")) > MAX_BYTES
        or not raw.isascii()
        or "\r" in raw or "\x00" in raw
        or not raw.endswith("\n")):
        raise InvalidPreflight()
    seen = {name: {} for name in EXPECTED}
    lines = raw.split("\n")
    if lines[-1] != "":
        raise InvalidPreflight()
    for row in lines[:-1]:
        fields = row.split("\t")
        if len(fields) != 3:
            raise InvalidPreflight()
        section, key, status = fields
        if (section not in EXPECTED or key not in EXPECTED[section]
            or status not in ("PASS", "BLOCK")
            or key in seen[section]):
            raise InvalidPreflight()
        seen[section][key] = status
    if any(set(seen[s]) != keys for s, keys in EXPECTED.items()):
        raise InvalidPreflight()
    if len(lines) != sum(len(keys) for keys in EXPECTED.values()) + 1:
        raise InvalidPreflight()
    codes = [
        STATUS_CODES[section] for section, observed in seen.items()
        if any(status == "BLOCK" for status in observed.values())
    ]
    return {
        "result": "BLOCK" if codes else "REVIEW_REQUIRED",
        "codes": codes if codes else ["SELECT_ONLY_DATA_PREFLIGHT_MATCH_NOT_RELEASE"],
        "release_authorized": False,
        "production_database_modified": False,
        "database_identity_independently_verified": False,
        "backup_restore_verified": False,
    }


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--input", required=True, metavar="LOCAL_TSV_OR_DASH",
                        help="Private output from reviewed SELECT-only SQL or '-'.")
    args = parser.parse_args()
    try:
        if args.input == "-":
            data = sys.stdin.buffer.read(MAX_BYTES + 1)
        else:
            path = Path(args.input)
            if not path.is_file() or path.stat().st_size > MAX_BYTES:
                raise InvalidPreflight()
            data = path.read_bytes()
        result = parse_status_tsv(data.decode("ascii", errors="strict"))
    except (InvalidPreflight, UnicodeError, ValueError, TypeError, OSError):
        result = {
            "result": "BLOCK",
            "codes": ["DATA_PREFLIGHT_IMPORT_REJECTED"],
            "release_authorized": False,
            "production_database_modified": False,
            "database_identity_independently_verified": False,
            "backup_restore_verified": False,
        }
    print(json.dumps(result, sort_keys=True))
    return 2 if result["result"] == "BLOCK" else 0


if __name__ == "__main__":
    sys.exit(main())
