#!/usr/bin/env python3
"""Offline old-Live94 immutable SQL query inventory. NOT a SQL executor.

This reads only a detached, clean checkout of the historical production
source and emits non-sensitive *hashes* of the seven previously reviewed SQL
statements. No DB, DNS, sockets, login-path, TLS, shell or Aiven connection.
"""
from __future__ import annotations

import argparse
import hashlib
import json
from pathlib import Path
import re
import sys

# Allow an isolated Python (-I) invocation to resolve only repository source.
sys.path.insert(0, str(Path(__file__).resolve().parents[2]))

from scripts.ci.php8427_live94_offline_source_gate import (
    AUDIT_BLOBS, Block, LIVE_MIGRATION_COUNT, LIVE_SHA, verify_source,
)

INVENTORY = "scripts/sql/p0_mysql_readonly_schema_inventory.sql"
LEDGER = "scripts/sql/p0_mysql_readonly_full_migration_ledger.sql"
# Only these seven statements in this exact sequence are acknowledged.
INVENTORY_SECTIONS = (
    "connection", "table", "column", "foreign_key", "index", "migration",
)
LEDGER_SECTIONS = ("migration_ledger",)
MAX_FILE_BYTES = 16384
MAX_QUERY_BYTES = 8192
MAX_TOTAL_QUERIES = 7
SECTION_RE = re.compile(r"^SELECT\s+'([a-z_]+)'\s+AS\s+section\s*,", re.ASCII | re.IGNORECASE)
FORBIDDEN = re.compile(
    r"\b(?:INTO\s+(?:OUTFILE|DUMPFILE)|LOAD_FILE\s*\(|"
    r"SLEEP\s*\(|BENCHMARK\s*\(|GET_LOCK\s*\(|RELEASE_LOCK\s*\(|"
    r"FOR\s+UPDATE|LOCK\s+IN\s+SHARE\s+MODE)\b", re.ASCII | re.IGNORECASE,
)


def statements_from_frozen_bytes(raw: bytes, sections: tuple[str, ...]) -> tuple[bytes, ...]:
    """Narrow tokenizer for exact pinned legacy SQL, not a general SQL parser.

    A real client implementation MUST separately prove it sends identical SQL,
    and handle SQL escapes/comments correctly. This tokenizer is never
    permitted to transform arbitrary SQL into a runnable command.
    """
    if (type(raw) is not bytes or not raw or len(raw) > MAX_FILE_BYTES
            or not raw.isascii() or b"\x00" in raw or b"\r" in raw):
        raise Block()
    try:
        text = raw.decode("ascii")
        lines = text.split("\n")
        sql = "\n".join(line for line in lines if not line.lstrip().startswith("--"))
        parts = sql.split(";")
        if len(parts) != len(sections) + 1 or parts[-1].strip():
            raise Block()
        result = []
        for section, part in zip(sections, parts[:-1]):
            statement = part.strip()
            if not statement or len(statement.encode("ascii")) > MAX_QUERY_BYTES:
                raise Block()
            match = SECTION_RE.match(statement)
            if match is None or match.group(1) != section or FORBIDDEN.search(statement):
                raise Block()
            if "--" in statement or "/*" in statement or "*/" in statement or "#" in statement:
                raise Block()
            result.append(statement.encode("ascii") + b";")
        return tuple(result)
    except (UnicodeError, ValueError, TypeError):
        raise Block() from None


def build_manifest(checkout: Path) -> dict:
    """Verify all 7 audit blobs and all 94 migrations before reading SQL.

    Static absence of writes in a previously reviewed SQL object is not a
    substitute for a real privilege restriction or authenticated DB check.
    """
    verify_source(checkout)
    hashes = []
    for relative, sections in (
        (INVENTORY, INVENTORY_SECTIONS), (LEDGER, LEDGER_SECTIONS)
    ):
        file = checkout / relative
        if file.is_symlink() or not file.is_file():
            raise Block()
        try:
            raw = file.read_bytes()
        except OSError:
            raise Block() from None
        # verify_source has already checked the precise Git blob of the bytes.
        from_hash = hashlib.sha1(
            b"blob " + str(len(raw)).encode("ascii") + b"\x00" + raw
        ).hexdigest()
        if from_hash != AUDIT_BLOBS[relative]:
            raise Block()
        statements = statements_from_frozen_bytes(raw, sections)
        hashes.extend((section, hashlib.sha256(sql).hexdigest()) for section, sql in zip(sections, statements))
    if len(hashes) != MAX_TOTAL_QUERIES:
        raise Block()
    return {
        "verdict": "REVIEW_REQUIRED",
        "code": "LIVE94_FROZEN_SQL_MANIFEST_OFFLINE_ONLY",
        "fixed_live_sha": LIVE_SHA,
        "migration_source_count": LIVE_MIGRATION_COUNT,
        "sql_statement_count": len(hashes),
        "statement_sections": [section for section, _ in hashes],
        "statement_sha256": [digest for _, digest in hashes],
        "statement_content_exposed": False,
        "sql_executed": False,
        "real_aiven_connected": False,
        "tls_server_authenticated": False,
        "effective_grants_verified": False,
        "physical_schema_complete": False,
        "remote_session_end_verified": False,
        "backup_restored": False,
        "production_go": False,
    }


def main() -> int:
    p = argparse.ArgumentParser(description=__doc__)
    p.add_argument("--checkout", required=True, type=Path)
    args = p.parse_args()
    try:
        if not args.checkout.is_absolute():
            raise Block()
        result = build_manifest(args.checkout)
    except (Block, OSError):
        result = {
            "verdict": "BLOCK", "code": "FROZEN_SQL_SOURCE_OR_LAYOUT_REJECTED",
            "sql_executed": False, "real_aiven_connected": False,
            "production_go": False,
        }
    print(json.dumps(result, sort_keys=True))
    return 0 if result["verdict"] == "REVIEW_REQUIRED" else 2


if __name__ == "__main__":
    sys.exit(main())
