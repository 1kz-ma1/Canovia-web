#!/usr/bin/env python3
"""V5.8 metadata-only audit of the two Development operational migrations.

Uses a *pinned checkout* and operator-held SELECT TSVs. No DB connection,
Laravel bootstrap, network, shell commands changing state, or release GO.
"""
from __future__ import annotations

import argparse
import json
from pathlib import Path
import re
import subprocess
import sys

sys.path.insert(0, str(Path(__file__).resolve().parents[2]))
from scripts.ci.p0_mysql_full_ledger_offline_check import (  # noqa: E402
    InvalidLedger, expected_migration_names, parse_full_ledger,
)

V53 = "2026_10_14_185000_add_manual_service_check_to_development_operational_targets_table"
V57 = "2026_10_14_201000_add_storage_revision_fence_to_development_operational_records"
PROFILE_BASE = "2026_10_14_154500_create_development_operational_profiles_table"
TARGET_BASE = "2026_10_14_164000_create_development_operational_targets_table"
KEYS = {
    "profile_table": {"PRESENT", "MISSING"},
    "target_table": {"PRESENT", "MISSING"},
    "service_checked_at": {"ABSENT", "VALID", "MISMATCH"},
    "service_checked_revision": {"ABSENT", "VALID", "MISMATCH"},
    "service_checked_environment": {"ABSENT", "VALID", "MISMATCH"},
    "data_store_revision": {"ABSENT", "VALID", "MISMATCH"},
    "backup_store_revision": {"ABSENT", "VALID", "MISMATCH"},
    "restore_store_revision": {"ABSENT", "VALID", "MISMATCH"},
}
V53_FIELDS = ("service_checked_at", "service_checked_revision", "service_checked_environment")
V57_FIELDS = ("data_store_revision", "backup_store_revision", "restore_store_revision")
MAX_LEDGER_BYTES = 131072
MAX_SCHEMA_BYTES = 4096


def report(code: str, status: str = "BLOCK") -> dict:
    # Never reflect user-provided values, paths, identifiers or secrets.
    return {
        "result": status,
        "code": code,
        "release_authorized": False,
        "production_database_modified": False,
        "production_identity_verified": False,
        "backup_restore_verified": False,
        "deployed_sha_verified": False,
        "device_acceptance_verified": False,
    }


def parse_schema(raw: str) -> dict[str, str]:
    if not isinstance(raw, str) or len(raw.encode("utf-8")) > MAX_SCHEMA_BYTES:
        raise InvalidLedger()
    if not raw.isascii() or "\r" in raw or "\x00" in raw or not raw.endswith("\n"):
        raise InvalidLedger()
    lines = raw.splitlines()
    if len(lines) != len(KEYS):
        raise InvalidLedger()
    found: dict[str, str] = {}
    for line in lines:
        cols = line.split("\t")
        if len(cols) != 3 or cols[0] != "v58_schema":
            raise InvalidLedger()
        key, state = cols[1:]
        if key not in KEYS or state not in KEYS[key] or key in found:
            raise InvalidLedger()
        found[key] = state
    if set(found) != set(KEYS):
        raise InvalidLedger()
    return found


def classify(expected: set[str], applied: dict[str, int], schema: dict[str, str]) -> dict:
    if not {PROFILE_BASE, TARGET_BASE, V53, V57} <= expected:
        return report("MIGRATION_SOURCE_INCOMPLETE")
    if not applied or {PROFILE_BASE, TARGET_BASE} - set(applied):
        return report("BASELINE_LEDGER_INCOMPLETE")
    if (set(applied) - expected
        or any(isinstance(v, bool) or not isinstance(v, int) or v < 1 or v >= 10**9
               for v in applied.values())):
        return report("MIGRATION_LEDGER_INVALID")
    if schema.get("profile_table") != "PRESENT" or schema.get("target_table") != "PRESENT":
        return report("BASE_TABLE_MISSING")
    # A pinned release candidate must inventory the FULL set of pending
    # migrations, not silently ignore unrelated pending Live changes.
    pending = expected - set(applied)
    if pending - {V53, V57}:
        return report("OTHER_PENDING_MIGRATIONS_REQUIRE_REVIEW")
    if V53 in pending and V57 not in pending:
        return report("MIGRATION_ORDER_INVALID")
    if any(schema.get(k) == "MISMATCH" for k in V53_FIELDS + V57_FIELDS):
        return report("COLUMN_SHAPE_MISMATCH")
    old_service = all(schema.get(k) == "ABSENT" for k in V53_FIELDS)
    new_service = all(schema.get(k) == "VALID" for k in V53_FIELDS)
    old_storage = all(schema.get(k) == "ABSENT" for k in V57_FIELDS)
    new_storage = all(schema.get(k) == "VALID" for k in V57_FIELDS)

    if pending == {V53, V57} and old_service and old_storage:
        return report("EXACT_V53_V57_PENDING", "REVIEW_REQUIRED")
    if pending == {V57} and new_service and old_storage:
        return report("V53_APPLIED_V57_PENDING", "REVIEW_REQUIRED")
    if not pending and new_service and new_storage:
        return report("BOTH_MIGRATIONS_APPLIED_NOT_RELEASE_GO", "REVIEW_REQUIRED")
    return report("PARTIAL_DDL_OR_LEDGER_SCHEMA_DRIFT")


def file_bytes(path_str: str, maximum: int) -> str:
    path = Path(path_str)
    if (not path.is_file() or path.is_symlink()
        or path.stat().st_size > maximum):
        raise InvalidLedger()
    return path.read_bytes().decode("ascii")


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--candidate-sha", required=True)
    parser.add_argument("--ledger", required=True)
    parser.add_argument("--schema", required=True)
    args = parser.parse_args()
    try:
        # No moving branch reference is accepted: the provided 40-character
        # identity must be this exact checked-out Git commit, not main/HEAD.
        if not re.fullmatch(r"[0-9a-f]{40}", args.candidate_sha):
            result = report("CANDIDATE_SHA_INVALID")
        else:
            checkout = subprocess.run(
                ["git", "rev-parse", "HEAD"],
                cwd=Path(__file__).resolve().parents[2],
                check=True, text=True, capture_output=True, timeout=5,
            ).stdout.strip()
            if checkout != args.candidate_sha:
                result = report("CANDIDATE_CHECKOUT_MISMATCH")
            else:
                applied = parse_full_ledger(file_bytes(args.ledger, MAX_LEDGER_BYTES))
                schema = parse_schema(file_bytes(args.schema, MAX_SCHEMA_BYTES))
                result = classify(expected_migration_names(), applied, schema)
    except (InvalidLedger, OSError, UnicodeError, TypeError, ValueError,
            subprocess.SubprocessError):
        result = report("INPUT_INVALID")
    print(json.dumps(result, sort_keys=True))
    return 0 if result["result"] == "REVIEW_REQUIRED" else 2


if __name__ == "__main__":
    sys.exit(main())
