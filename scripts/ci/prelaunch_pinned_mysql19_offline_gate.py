#!/usr/bin/env python3
"""Offline fail-closed metadata-only audit for the frozen 94 -> 113 MySQL release.

Never opens a database, modifies files, invokes a migration, or exposes raw
migration names, accounts, credentials, hosts or production application rows.
A successful decision is REVIEW_REQUIRED: *never* production release GO.
"""
from __future__ import annotations

import argparse
import json
from pathlib import Path
import subprocess
import sys

sys.path.insert(0, str(Path(__file__).resolve().parents[2]))

from scripts.ci.p0_mysql_full_ledger_offline_check import (
    InvalidLedger, expected_migration_names, parse_full_ledger,
)

LIVE_SHA = "15abdc9de6f24b5250fb255c579d1aa55facaf1a"
CANDIDATE_SHA = "6d7dbcfedd35f33ba2827ed8c7c3d98ca30fc3d6"
FIELDS = {
    "legacy_tables": {"PRESENT", "MISSING"},
    "plan_epoch": {"ABSENT", "VALID", "MISMATCH"},
    "member_epoch": {"ABSENT", "VALID", "MISMATCH"},
    "mcp_active_fingerprint": {"ABSENT", "VALID", "MISMATCH"},
    "mcp_old_unique": {"ABSENT", "PRESENT"},
    "mcp_active_unique": {"ABSENT", "PRESENT"},
    "mcp_history_index": {"ABSENT", "PRESENT"},
    "grant_expiry": {"OLD", "NEW", "MISMATCH"},
    "observation_source_cols": {"ABSENT", "VALID", "MISMATCH"},
    "new_tables": {"ABSENT", "PRESENT", "MISMATCH"},
    "engine_series": {"MYSQL_8_0", "MYSQL_8_4", "OTHER"},
    "binary_logging": {"ON", "OFF"},
    "trusted_function_creators": {"ON", "OFF"},
}
BASELINE = {
    "legacy_tables": "PRESENT",
    "plan_epoch": "ABSENT", "member_epoch": "ABSENT",
    "mcp_active_fingerprint": "ABSENT", "mcp_old_unique": "PRESENT",
    "mcp_active_unique": "ABSENT", "mcp_history_index": "ABSENT",
    "grant_expiry": "OLD", "observation_source_cols": "ABSENT",
    "new_tables": "ABSENT",
}
UPGRADED = {
    "legacy_tables": "PRESENT",
    "plan_epoch": "VALID", "member_epoch": "VALID",
    "mcp_active_fingerprint": "VALID", "mcp_old_unique": "ABSENT",
    "mcp_active_unique": "PRESENT", "mcp_history_index": "PRESENT",
    "grant_expiry": "NEW", "observation_source_cols": "VALID",
    "new_tables": "PRESENT",
}
MAX_SCHEMA_BYTES = 4096
MAX_LEDGER_BYTES = 131072


def parse_snapshot(raw: str) -> dict[str, str]:
    if (not isinstance(raw, str) or not raw.isascii()
        or len(raw.encode("ascii")) > MAX_SCHEMA_BYTES
        or "\r" in raw or "\x00" in raw or not raw.endswith("\n")):
        raise InvalidLedger()
    values: dict[str, str] = {}
    for row in raw.splitlines():
        parts = row.split("\t")
        if len(parts) != 3 or parts[0] != "pinned_19_schema":
            raise InvalidLedger()
        key, status = parts[1:]
        if key in values or key not in FIELDS or status not in FIELDS[key]:
            raise InvalidLedger()
        values[key] = status
    if set(values) != set(FIELDS):
        raise InvalidLedger()
    return values


def verdict(code: str, *, result: str = "BLOCK",
            applied_count: int | None = None) -> dict:
    return {
        "result": result,
        "code": code,
        "expected_baseline_count": 94,
        "expected_candidate_count": 113,
        "applied_count": applied_count,
        "release_authorized": False,
        "production_database_modified": False,
        "production_identity_independently_verified": False,
        "production_migration_permissions_verified": False,
        "production_trigger_ddl_authorized": False,
        "backup_restore_verified": False,
        "real_device_acceptance_verified": False,
    }


def compare(expected_old: set[str], expected_new: set[str],
            ledger: dict[str, int], state: dict[str, str]) -> dict:
    if (len(expected_old) != 94 or len(expected_new) != 113
        or not expected_old < expected_new or len(expected_new - expected_old) != 19):
        return verdict("SOURCE_PIN_OR_MIGRATION_SET_MISMATCH")
    if not ledger or any(not isinstance(v, int) or isinstance(v, bool)
                         or v < 1 or v >= 10**9 for v in ledger.values()):
        return verdict("LEDGER_INVALID")
    if set(ledger) - expected_new:
        return verdict("UNKNOWN_APPLIED_MIGRATION")
    if state.get("engine_series") not in {"MYSQL_8_0", "MYSQL_8_4"}:
        return verdict("ENGINE_UNVERIFIED")
    if set(state) != set(FIELDS) or any(
        state[k] not in FIELDS[k] for k in FIELDS
    ):
        return verdict("SCHEMA_INPUT_INVALID")
    applied = set(ledger)
    if applied == expected_old:
        shape = BASELINE
        code = "PINNED_94_PENDING_19_METADATA_MATCH"
    elif applied == expected_new:
        shape = UPGRADED
        code = "PINNED_113_APPLIED_METADATA_MATCH"
    else:
        return verdict("PARTIAL_OR_UNEXPECTED_MIGRATION_LEDGER",
                       applied_count=len(ledger))
    if any(state.get(k) != v for k, v in shape.items()):
        return verdict("LEDGER_AND_PHYSICAL_SCHEMA_CONFLICT",
                       applied_count=len(ledger))
    # Metadata-only audit accounts cannot prove the app/runtime principal's
    # CREATE/TRIGGER/ALTER/INDEX privileges or MySQL binary-log configuration
    # constraints. Even log_bin OFF / trusted_function_creators ON does not
    # prove those privileges. Explicit owner-operated confirmation is required.
    output = verdict(code, result="REVIEW_REQUIRED", applied_count=len(ledger))
    output["requires_runtime_principal_ddl_grants_review"] = True
    output["requires_operator_binlog_trigger_policy_review"] = True
    output["requires_real_data_and_partial_ddl_review"] = True
    return output


def pinned_migrations(root: Path, expected_sha: str) -> set[str]:
    if not root.is_dir() or root.is_symlink():
        raise InvalidLedger()
    try:
        proc = subprocess.run(
            ["git", "-C", str(root), "rev-parse", "--verify", "HEAD"],
            capture_output=True, check=False, timeout=5,
        )
    except (OSError, subprocess.TimeoutExpired):
        raise InvalidLedger() from None
    if proc.returncode != 0 or proc.stdout.decode("ascii", errors="replace").strip() != expected_sha:
        raise InvalidLedger()
    return expected_migration_names(root / "database" / "migrations")


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--live-root", required=True)
    parser.add_argument("--candidate-root", required=True)
    parser.add_argument("--ledger", required=True)
    parser.add_argument("--schema", required=True)
    args = parser.parse_args()
    try:
        a, b = Path(args.ledger), Path(args.schema)
        if (not a.is_file() or a.is_symlink() or a.stat().st_size > MAX_LEDGER_BYTES
            or not b.is_file() or b.is_symlink() or b.stat().st_size > MAX_SCHEMA_BYTES):
            raise InvalidLedger()
        old = pinned_migrations(Path(args.live_root), LIVE_SHA)
        new = pinned_migrations(Path(args.candidate_root), CANDIDATE_SHA)
        applied = parse_full_ledger(a.read_bytes().decode("ascii"))
        state = parse_snapshot(b.read_bytes().decode("ascii"))
        outcome = compare(old, new, applied, state)
    except (InvalidLedger, UnicodeError, ValueError, TypeError, OSError):
        outcome = verdict("PRIVATE_INPUT_OR_SOURCE_PIN_REJECTED")
    print(json.dumps(outcome, sort_keys=True))
    return 0 if outcome["result"] == "REVIEW_REQUIRED" else 2


if __name__ == "__main__":
    sys.exit(main())
