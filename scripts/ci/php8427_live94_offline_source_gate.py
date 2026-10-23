#!/usr/bin/env python3
"""Read-only, offline integrity gate for the exact *old Live* 94-migration audit.

This is a source/fixture review helper, NOT the Aiven collector, a credential
reader, an owner-approval issuer, or a production release gate. It does not
import Laravel, open sockets, contact GitHub/Aiven, or run SQL.
"""
from __future__ import annotations

import argparse
import hashlib
import json
import os
from pathlib import Path
import re
import subprocess
import sys

LIVE_SHA = "15abdc9de6f24b5250fb255c579d1aa55facaf1a"
LIVE_MIGRATION_TREE = "62dd8ce9bae3f73d1a390ea27242094e8165f9a2"
LIVE_MIGRATION_COUNT = 94
# Immutable, previously reviewed Git objects from the deployed source commit.
AUDIT_BLOBS = {
    "scripts/ops/p0_mysql_operator_select_only_collect.sh": "be4bc3ff3ceb5bfb6fef29fc3dae473665c64299",
    "scripts/sql/p0_mysql_readonly_schema_inventory.sql": "a80067515d3555b20b46c7d3d875d9b545611a45",
    "scripts/sql/p0_mysql_readonly_full_migration_ledger.sql": "6d186f87075e6aec6fbceecac3c1e4eef9d47bda",
    "scripts/ci/p0_mysql_inventory_tsv_import.py": "d432601bfe5b9930280c9629314e6676907ed81a",
    "scripts/ci/p0_mysql_inventory_offline_triage.py": "a4f7295d9663e30cba6ec8565f958c2f92c4fb6f",
    "scripts/ci/p0_mysql_metadata_grants_guard.py": "06876fa6bf1ddb749027f296a33fb11dd8fee0ae",
    "scripts/ci/p0_mysql_full_ledger_offline_check.py": "bbe6b8456727c3aac7992ecd88c0380b7a7a7f85",
}
MIGRATION_RE = re.compile(r"^\d{4}_\d{2}_\d{2}_\d{6}_[a-z][a-z0-9_]*\.php$")


class Block(ValueError):
    """No sensitive context is ever included in this exception."""


def git(root: Path, *args: str) -> str:
    try:
        p = subprocess.run(
            ["git", "-c", "core.fsmonitor=false", "-c",
             "core.hooksPath=/dev/null", "-C", str(root), *args],
            check=True, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL,
            text=True, timeout=15,
        )
    except (OSError, subprocess.CalledProcessError, subprocess.TimeoutExpired):
        raise Block() from None
    return p.stdout.strip()


def sha1_blob(raw: bytes) -> str:
    return hashlib.sha1(b"blob " + str(len(raw)).encode("ascii") + b"\x00" + raw).hexdigest()


def safe_regular_path(root: Path, relative: str) -> Path:
    current = root
    for piece in Path(relative).parts:
        current = current / piece
        if current.is_symlink():
            raise Block()
    if not current.is_file():
        raise Block()
    return current


def verify_source(root: Path) -> dict:
    # Resolve neither a symlink root nor a nested checkout belonging to a
    # different repository. All checks are local Git/object/file reads.
    if root.is_symlink() or not root.is_dir():
        raise Block()
    root = Path(os.path.abspath(root))
    if git(root, "rev-parse", "--show-toplevel") != str(root):
        raise Block()
    if git(root, "rev-parse", "--verify", "HEAD") != LIVE_SHA:
        raise Block()
    if git(root, "rev-parse", "HEAD:database/migrations") != LIVE_MIGRATION_TREE:
        raise Block()
    if git(root, "status", "--porcelain", "--untracked-files=all"):
        raise Block()

    for relative, expected_blob in AUDIT_BLOBS.items():
        file = safe_regular_path(root, relative)
        if git(root, "rev-parse", f"HEAD:{relative}") != expected_blob:
            raise Block()
        try:
            raw = file.read_bytes()
        except OSError:
            raise Block() from None
        if sha1_blob(raw) != expected_blob:
            raise Block()

    directory = root / "database" / "migrations"
    if directory.is_symlink() or not directory.is_dir():
        raise Block()
    try:
        files = list(directory.iterdir())
    except OSError:
        raise Block() from None
    if len(files) != LIVE_MIGRATION_COUNT:
        raise Block()
    for file in files:
        if file.is_symlink() or not file.is_file() or MIGRATION_RE.fullmatch(file.name) is None:
            raise Block()
        relative = "database/migrations/" + file.name
        tracked = git(root, "rev-parse", f"HEAD:{relative}")
        try:
            raw = file.read_bytes()
        except OSError:
            raise Block() from None
        if sha1_blob(raw) != tracked:
            raise Block()
    return {"source_head": LIVE_SHA, "audit_file_count": len(AUDIT_BLOBS),
            "migration_count": LIVE_MIGRATION_COUNT}


def _no_duplicate_pairs(items: list[tuple[str, object]]) -> dict:
    o = {}
    for k, v in items:
        if k in o:
            raise Block()
        o[k] = v
    return o


def verify_sanitized_ledger_summary(raw: bytes) -> None:
    """Optional *offline* interpretation of an already-sanitized OLD collector result.

    This neither obtains nor authenticates a DB observation. It intentionally
    rejects the legacy collector's REVIEW_REQUIRED with known pending migrations.
    """
    if not raw or len(raw) > 4096:
        raise Block()
    try:
        v = json.loads(raw.decode("utf-8", "strict"), object_pairs_hook=_no_duplicate_pairs)
    except (UnicodeError, json.JSONDecodeError, TypeError, ValueError):
        raise Block() from None
    if not isinstance(v, dict) or set(v) != {
        "result", "code", "release_authorized", "production_database_modified",
        "expected_count", "applied_count", "pending_count",
        "forward_repair_pending", "other_pending_count",
    }:
        raise Block()
    if v["result"] != "REVIEW_REQUIRED" or v["code"] != "FULL_MIGRATION_LEDGER_MATCH_NOT_RELEASE":
        raise Block()
    if v["release_authorized"] is not False or v["production_database_modified"] is not False:
        raise Block()
    for key, expected in (("expected_count", 94), ("applied_count", 94),
                          ("pending_count", 0), ("other_pending_count", 0)):
        if type(v[key]) is not int or v[key] != expected:
            raise Block()
    if v["forward_repair_pending"] is not False:
        raise Block()


def run(root: Path, summary: Path | None = None) -> dict:
    try:
        v = verify_source(root)
        if summary is not None:
            if summary.is_symlink() or not summary.is_file():
                raise Block()
            verify_sanitized_ledger_summary(summary.read_bytes())
    except (Block, OSError):
        return {
            "result": "BLOCK",
            "code": "LIVE94_OFFLINE_INTEGRITY_OR_SUMMARY_REJECTED",
            "real_aiven_connected": False,
            "release_authorized": False,
        }

    return {
        "result": "REVIEW_REQUIRED",
        "code": "LIVE94_SOURCE_PINNED_NO_AIVEN" if summary is None
                else "LIVE94_SANITIZED_LEDGER_CONSISTENT_UNAUTHENTICATED",
        "live_sha": v["source_head"],
        "migration_source_count": v["migration_count"],
        "audit_source_count": v["audit_file_count"],
        "real_aiven_connected": False,
        "live_db_schema_verified": False,
        "backup_restore_verified": False,
        "release_authorized": False,
    }


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--checkout", required=True, type=Path,
                        help="Absolute path to a clean detached checkout at exact old Live SHA.")
    parser.add_argument("--sanitized-ledger-summary", type=Path,
                        help="Optional local fixed-format JSON; no raw MySQL output or connection.")
    args = parser.parse_args()
    if not args.checkout.is_absolute():
        output = {"result": "BLOCK", "code": "LIVE94_OFFLINE_INTEGRITY_OR_SUMMARY_REJECTED",
                  "real_aiven_connected": False, "release_authorized": False}
    else:
        output = run(args.checkout, args.sanitized_ledger_summary)
    print(json.dumps(output, sort_keys=True))
    return 0 if output["result"] == "REVIEW_REQUIRED" else 2


if __name__ == "__main__":
    sys.exit(main())
