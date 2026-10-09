#!/usr/bin/env python3
"""Validate a narrow, operator-reviewed MySQL metadata inspector grant set.

Do not print raw SHOW GRANTS rows, DB identifiers, accounts or secrets.
Allowed: REFERENCES on the verified schema (metadata visibility) and SELECT
on that schema's Laravel migrations table (ledger only). Optional USAGE
does not grant data/DDL privileges. Dynamic roles/other grants fail closed.
This validator makes NO database connection or permission changes.
"""
from __future__ import annotations

import argparse
import re
import sys
from pathlib import Path

MAX_BYTES = 16384
DATABASE = re.compile(r"^[A-Za-z0-9_-]{1,64}$")
GRANT_LINE = re.compile(r"^GRANT ([A-Z_ ,]+) ON (\S+) TO (\S+)$")


def verify_grants(raw: str, database: str) -> bool:
    if not DATABASE.fullmatch(database) or not raw or len(raw.encode("utf-8")) > MAX_BYTES:
        return False
    if not raw.isascii() or "\r" in raw or "\x00" in raw or not raw.endswith("\n"):
        return False

    # MySQL SHOW GRANTS normally quotes identifiers using backticks.
    permitted = {
        ("USAGE", "*.*"): "usage",
        ("REFERENCES", f"`{database}`.*"): "metadata",
        ("SELECT", f"`{database}`.`migrations`"): "ledger",
    }
    found = set()
    for line in raw.splitlines():
        match = GRANT_LINE.fullmatch(line)
        if match is None:
            return False
        privilege, target, account = match.groups()
        # Reject WITH GRANT OPTION, roles, arbitrary targets, broad SELECT,
        # and unknown user/account decorations. The actual account is not echoed.
        if not re.fullmatch(r"`[A-Za-z0-9_%-]{1,64}`@`[A-Za-z0-9_%.:-]{1,255}`", account):
            return False
        kind = permitted.get((privilege, target))
        if kind is None or kind in found:
            return False
        found.add(kind)
    return "metadata" in found and "ledger" in found


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--database", required=True)
    parser.add_argument("--input", required=True)
    args = parser.parse_args()
    try:
        raw = Path(args.input).read_bytes()
        accepted = verify_grants(raw.decode("ascii", errors="strict"), args.database)
    except (OSError, UnicodeError, ValueError, TypeError):
        accepted = False
    if accepted:
        print("P0_METADATA_GRANTS: ACCEPT_METADATA_ONLY_LEDGER_SELECT")
        return 0
    print("P0_METADATA_GRANTS: BLOCK", file=sys.stderr)
    return 2


if __name__ == "__main__":
    sys.exit(main())
