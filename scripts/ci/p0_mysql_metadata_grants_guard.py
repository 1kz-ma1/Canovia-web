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
import os
import re
import stat
import sys

MAX_BYTES = 16384
DATABASE = re.compile(r"^[A-Za-z0-9_-]{1,64}$")
GRANT_LINE = re.compile(r"^GRANT ([A-Z_ ,]+) ON (\S+) TO (\S+)$")


def verify_grants(raw: str, database: str) -> bool:
    if not DATABASE.fullmatch(database) or not raw or len(raw.encode("utf-8")) > MAX_BYTES:
        return False
    if not raw.isascii() or "\r" in raw or "\x00" in raw or not raw.endswith("\n"):
        return False

    # MySQL SHOW GRANTS can quote object names and account identifiers with
    # either backticks or double quotes (e.g. under ANSI_QUOTES). Accept those
    # *exact* forms only. Never normalize arbitrary SQL or strip quote chars.
    permitted = {("USAGE", "*.*"): "usage"}
    for quote in ("`", '"'):
        permitted[("REFERENCES", f"{quote}{database}{quote}.*")] = "metadata"
        permitted[("SELECT", f"{quote}{database}{quote}.{quote}migrations{quote}")] = "ledger"

    found = set()
    seen_account = None
    for line in raw.splitlines():
        match = GRANT_LINE.fullmatch(line)
        if match is None:
            return False
        privilege, target, account = match.groups()
        # Reject WITH GRANT OPTION, roles, arbitrary targets, broad SELECT,
        # cross-database access, mismatched accounts and unknown decorations.
        # The current account is intentionally never written to output.
        if not re.fullmatch(
            r'([`"])[A-Za-z0-9_%-]{1,64}\1@([`"])[A-Za-z0-9_%.:-]{1,255}\2',
            account,
        ):
            return False
        if seen_account is not None and account != seen_account:
            return False
        seen_account = account
        kind = permitted.get((privilege, target))
        if kind is None or kind in found:
            return False
        found.add(kind)
    return "metadata" in found and "ledger" in found


class PrivateParser(argparse.ArgumentParser):
    def error(self, message):
        # argparse's default diagnostic echoes untrusted argument values.
        raise ValueError("INPUT_REJECTED")


def read_grants(path: str) -> bytes:
    # Nonblocking open prevents a FIFO from hanging before fstat. NOFOLLOW
    # protects the final path component; this is not a trusted-directory or
    # credential loader, and a local file is never evidence of authorization.
    if not hasattr(os, "O_NOFOLLOW") or not hasattr(os, "O_NONBLOCK"):
        raise ValueError("INPUT_REJECTED")
    fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    try:
        info = os.fstat(fd)
        if not stat.S_ISREG(info.st_mode) or info.st_size > MAX_BYTES:
            raise ValueError("INPUT_REJECTED")
        # Also bound the read if the regular file grows after fstat.
        with os.fdopen(fd, "rb", closefd=False) as stream:
            raw = stream.read(MAX_BYTES + 1)
        if len(raw) > MAX_BYTES:
            raise ValueError("INPUT_REJECTED")
        return raw
    finally:
        os.close(fd)


def main(argv=None) -> int:
    try:
        parser = PrivateParser(add_help=False, allow_abbrev=False)
        parser.add_argument("--database", required=True, action="append")
        parser.add_argument("--input", required=True, action="append")
        args = parser.parse_args(argv)
        if len(args.database) != 1 or len(args.input) != 1:
            raise ValueError("INPUT_REJECTED")
        if not DATABASE.fullmatch(args.database[0]):
            raise ValueError("INPUT_REJECTED")
        raw = read_grants(args.input[0])
        accepted = verify_grants(raw.decode("ascii", errors="strict"), args.database[0])
    except (OSError, UnicodeError, ValueError, TypeError, KeyboardInterrupt):
        accepted = False
    if accepted:
        print("P0_METADATA_GRANTS: ACCEPT_METADATA_ONLY_LEDGER_SELECT")
        return 0
    print("P0_METADATA_GRANTS: BLOCK", file=sys.stderr)
    return 2


if __name__ == "__main__":
    sys.exit(main())
