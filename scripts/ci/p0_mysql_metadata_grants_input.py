#!/usr/bin/env python3
"""Offline private input wrapper for the immutable metadata grants validator.

No connector, credential loader, approval authority or database operation.
The frozen guard and its permission decisions are deliberately unchanged.
"""
import argparse
import os
from pathlib import Path
import stat
import sys

try:
    if __package__:
        from .p0_mysql_metadata_grants_guard import DATABASE, MAX_BYTES, verify_grants
    else:
        # -I omits the script directory; use only this fixed sibling module.
        sys.path.insert(0, str(Path(__file__).resolve().parent))
        from p0_mysql_metadata_grants_guard import DATABASE, MAX_BYTES, verify_grants
except (ImportError, OSError):
    print("P0_METADATA_GRANTS: BLOCK", file=sys.stderr)
    sys.exit(2)


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
