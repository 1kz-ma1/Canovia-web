#!/usr/bin/env python3
"""Fail-closed offline operator-attestation packet for Canovia release #488.

This program DOES NOT connect to Aiven, Render, or any database; it cannot
verify factual truth of human-entered attestations. No verdict authorizes a
production deployment, DDL, grant change, or restore.
"""
from __future__ import annotations

import argparse
import json
from pathlib import Path
import sys

LIVE = "15abdc9de6f24b5250fb255c579d1aa55facaf1a"
PINNED = "6d7dbcfedd35f33ba2827ed8c7c3d98ca30fc3d6"
MAX_BYTES = 4096

# All fields require separate, real, private operator evidence; a value of
# true means ONLY "a human attests reviewing this", not technical verification.
FIELDS = {
    "identity": (
        "aiven_service_independently_confirmed",
        "application_database_independently_confirmed",
        "tls_endpoint_verified",
        "migration_runtime_principal_confirmed",
    ),
    "ddl": (
        "runtime_principal_grants_readonly_reviewed",
        "create_privilege_confirmed",
        "alter_privilege_confirmed",
        "drop_privilege_confirmed",
        "index_privilege_confirmed",
        "trigger_privilege_confirmed",
        "references_privilege_confirmed",
        "data_migration_dml_privileges_confirmed",
        "binlog_and_trigger_policy_confirmed_compatible",
    ),
    "recovery": (
        "fresh_backup_confirmed",
        "independent_restore_destination_available",
        "isolated_restore_completed",
        "restored_rows_and_schema_verified",
        "restore_time_measured_and_accepted",
        "live_write_quiesce_plan_reviewed",
        "mysql_nontransactional_ddl_forward_repair_reviewed",
    ),
}

class InvalidPacket(Exception):
    """Do not place user/secret input inside error strings."""


def unique_keys(pairs: list[tuple[str, object]]) -> dict:
    out: dict = {}
    for k, v in pairs:
        if k in out:
            raise InvalidPacket()
        out[k] = v
    return out


def validate(raw: bytes) -> dict:
    if not isinstance(raw, bytes) or len(raw) > MAX_BYTES or not raw:
        raise InvalidPacket()
    try:
        obj = json.loads(raw.decode("ascii"), object_pairs_hook=unique_keys)
    except (ValueError, UnicodeError, TypeError):
        raise InvalidPacket() from None
    if not isinstance(obj, dict) or set(obj) != {
        "version", "live_sha", "candidate_sha", *FIELDS,
    } or type(obj["version"]) is not int or obj["version"] != 1:
        raise InvalidPacket()
    if obj["live_sha"] != LIVE or obj["candidate_sha"] != PINNED:
        raise InvalidPacket()
    for section, fields in FIELDS.items():
        item = obj[section]
        if not isinstance(item, dict) or set(item) != set(fields):
            raise InvalidPacket()
        if any(type(item[k]) is not bool for k in fields):
            raise InvalidPacket()
    return obj


def outcome(code: str, result: str = "BLOCK") -> dict:
    # Never echo input fields or arbitrary data (including actual grant text).
    return {
        "result": result,
        "code": code,
        "release_authorized": False,
        "production_database_modified": False,
        "aiven_service_queried_by_this_tool": False,
        "production_runtime_ddl_technically_verified_by_this_tool": False,
        "backup_restoration_technically_verified_by_this_tool": False,
        "needs_owner_release_approval": True,
    }


def decide(packet: dict) -> dict:
    for section, reason in [
        ("identity", "SERVICE_DATABASE_OR_RUNTIME_IDENTITY_UNVERIFIED"),
        ("ddl", "RUNTIME_DDL_PRIVILEGES_OR_TRIGGER_POLICY_UNVERIFIED"),
        ("recovery", "INDEPENDENT_RESTORE_AND_FORWARD_REPAIR_UNVERIFIED"),
    ]:
        if not all(packet[section].values()):
            return outcome(reason)
    # All self-attestations are claims requiring evidence review. Never GO.
    return outcome("MANUAL_ATTESTATION_PACKET_REQUIRES_INDEPENDENT_REVIEW",
                   "REVIEW_REQUIRED")


def main() -> int:
    cli = argparse.ArgumentParser(description=__doc__)
    cli.add_argument("--input", required=True)
    args = cli.parse_args()
    try:
        path = Path(args.input)
        if not path.is_file() or path.is_symlink() or path.stat().st_size > MAX_BYTES:
            raise InvalidPacket()
        verdict = decide(validate(path.read_bytes()))
    except (InvalidPacket, OSError, ValueError, TypeError):
        verdict = outcome("PACKET_INVALID_OR_UNSAFE")
    print(json.dumps(verdict, sort_keys=True))
    return 0 if verdict["result"] == "REVIEW_REQUIRED" else 2


if __name__ == "__main__":
    sys.exit(main())
