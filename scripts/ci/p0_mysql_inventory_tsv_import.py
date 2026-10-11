#!/usr/bin/env python3
"""Offline importer for reviewed SELECT-only P0 MySQL inventory rows.

Accepts MySQL CLI --batch --raw --skip-column-names TSV only.
No connections, mysql subprocess, DDL or auth data. Never copies raw
observed values or error details to output. Local operator use only.
"""

import argparse
import json
from pathlib import Path
import re
import sys

# Support both `python3 scripts/ci/...` and package-imported CI tests from
# the repository root; only checked-in local code is imported.
sys.path.insert(0, str(Path(__file__).resolve().parents[2]))

from scripts.ci.p0_mysql_inventory_offline_triage import (
    STATUS_ALLOWED, expected_keys, template,
)

MAX_BYTES = 32768
SECTIONS = {
    "table": ("tables", 3),
    "column": ("columns", 3),
    "foreign_key": ("foreign_keys", 4),
    "index": ("indexes", 3),
    "migration": ("migrations", 4),
}

class InventoryRejected(ValueError):
    """Deliberately context-free error; never echo raw input."""


def parse_tsv(contents: str) -> dict:
    """Return only known status labels; reject a partial export or surplus rows."""
    if len(contents.encode("utf-8")) > MAX_BYTES or not contents.endswith("\n"):
        raise InventoryRejected()
    record = template()
    groups = expected_keys()
    seen = {group: set() for group in groups}
    saw_connection = False

    for line in contents.splitlines():
        if not line or "\r" in line or "\x00" in line:
            raise InventoryRejected()
        fields = line.split("\t")
        if fields[0] == "connection":
            if (saw_connection or len(fields) != 4 or
                fields[1] != "mysql_version" or
                fields[3] not in {"SCHEMA_SELECTED", "NO_SCHEMA_SELECTED"}):
                raise InventoryRejected()
            # Suppress server packaging suffix. Only a fixed, short version
            # triple ever reaches the sanitized JSON; never echo raw text.
            v = re.fullmatch(r"(\d{1,2}\.\d{1,3}\.\d{1,3})(?:[-+][A-Za-z0-9_.-]{1,32})?", fields[2])
            if v is None:
                raise InventoryRejected()
            record["mysql_version"] = v.group(1)
            record["connection"] = fields[3]
            saw_connection = True
            continue

        spec = SECTIONS.get(fields[0])
        if spec is None or not saw_connection:
            raise InventoryRejected()
        group, width = spec
        if len(fields) != width:
            raise InventoryRejected()
        key = fields[1]
        state = fields[-1]
        if (key not in groups[group] or key in seen[group] or
            state not in STATUS_ALLOWED[group] or state == "UNVERIFIED"):
            raise InventoryRejected()
        if fields[0] == "foreign_key":
            # The observed FK name is untrusted and is NOT exported. The
            # reviewed SQL itself verified exact parent/action/name.
            if not re.fullmatch(r"[A-Za-z0-9_]{1,64}", fields[2]):
                raise InventoryRejected()
        elif fields[0] == "migration":
            batch = fields[2]
            if (state == "APPLIED" and not re.fullmatch(r"[1-9]\d{0,9}", batch)) or (
                state == "PENDING" and batch != "NONE"
            ):
                raise InventoryRejected()
        seen[group].add(key)
        record[group][key] = state

    if not saw_connection or any(seen[k] != groups[k] for k in groups):
        raise InventoryRejected()
    return record


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument(
        "--input", metavar="LOCAL_SQL_TSV_OR_DASH", required=True,
        help="Strict local MySQL batch output path or '-' for stdin; never GitHub uploads.",
    )
    args = parser.parse_args()
    try:
        if args.input == "-":
            data = sys.stdin.buffer.read(MAX_BYTES + 1)
        else:
            path = Path(args.input)
            if not path.is_file() or path.stat().st_size > MAX_BYTES:
                raise InventoryRejected()
            data = path.read_bytes()
        record = parse_tsv(data.decode("utf-8", errors="strict"))
    except (InventoryRejected, UnicodeError, OSError, ValueError, TypeError):
        print(json.dumps({
            "result": "BLOCK", "code": "INVENTORY_IMPORT_REJECTED",
            "release_authorized": False,
        }))
        return 2

    print(json.dumps(record, sort_keys=True))
    return 0


if __name__ == "__main__":
    sys.exit(main())
