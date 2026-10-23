#!/usr/bin/env python3
"""Offline review of the pinned old-Live MySQL collector, never execution.

A successful source review does not make the legacy collector safe to run.
This outputs HOLD/NO_GO and public fixed codes only. No credentials, CA,
hostnames, socket calls, mysql invocations, live runner or approvals.
"""
from __future__ import annotations

import argparse
import json
from pathlib import Path

from scripts.ci.php8427_live94_offline_source_gate import (
    AUDIT_BLOBS, Block, LIVE_SHA, verify_source,
)

COLLECTOR = "scripts/ops/p0_mysql_operator_select_only_collect.sh"
FIXED_RISKS = (
    "P0_LEGACY_COLLECTOR_NOT_CHAINED_TO_SOURCE_GATE",
    "P0_LEGACY_MYSQL_CLIENT_LOGIN_IDENTITY_NOT_ATTESTED",
    "P0_LEGACY_THREE_SESSIONS_NOT_BOUND_BY_SERVER_IDENTITY",
    "P0_LEGACY_NO_WALL_CLOCK_AND_REMOTE_TERMINATION_BOUND",
    "P0_LEGACY_EFFECTIVE_ROLE_SCOPE_NOT_VERIFIED",
    "P0_LEGACY_RAW_SCRATCH_LIFETIME_NOT_ACCEPTED",
    "P0_OPERATOR_APPROVAL_AND_INCIDENT_RESPONSE_NOT_SIGNED",
    "P0_LIVE_SCHEMA_AND_RESTORABLE_BACKUP_NOT_OBSERVED",
)


def shell_code(source: str) -> str:
    """Exclude full comments and blank lines; do not evaluate shell."""
    return "\n".join(
        line for line in source.splitlines()
        if line.strip() and not line.lstrip().startswith("#")
    )


def blocked(code: str) -> dict[str, object]:
    return {
        "verdict": "HOLD", "code": code, "source_locked": False,
        "open_risks": list(FIXED_RISKS),
        "real_aiven_connection_executed": False,
        "schema_94_verified": False, "backup_restorable": False,
        "owner_go": False, "production_go": False,
    }


def review(collector: str, *, source_verified: bool) -> dict[str, object]:
    if not source_verified or not isinstance(collector, str) or len(collector) > 20000:
        return blocked("SOURCE_NOT_PINNED")
    if "\x00" in collector or "\r" in collector:
        return blocked("UNEXPECTED_COLLECTOR_FORMAT")
    code = shell_code(collector)
    # Narrow observations of source only. Not a bash parser or TLS trust proof.
    mysql_call = 'mysql "' + '$' + '{mysql_args[@]}"'
    checks = {
        "three_separate_mysql_invocations": code.count(mysql_call) == 3,
        "tls_configured": "--ssl-mode=VERIFY_IDENTITY" in code
                          and '"--ssl-ca=$ca"' in code,
        "normal_options_disabled": "--no-defaults" in code
                                   and '"--login-path=$login"' in code,
        "grants_review_precedes_inventory": (
            "SHOW GRANTS FOR CURRENT_USER()" in code
            and "p0_mysql_metadata_grants_guard.py" in code
            and code.index("SHOW GRANTS FOR CURRENT_USER()")
                < code.rindex(' < "$repo/scripts/sql/p0_mysql_readonly_schema_inventory.sql"')
        ),
        "two_known_sql_sources": (
            '< "$repo/scripts/sql/p0_mysql_readonly_schema_inventory.sql"' in code
            and '< "$repo/scripts/sql/p0_mysql_readonly_full_migration_ledger.sql"' in code
        ),
        "operator_flags_present": all(x in code for x in (
            "CANOVIA_P0_OPERATOR_APPROVED", "CANOVIA_P0_TARGET_VERIFIED",
            "CANOVIA_P0_GRANTS_REVIEWED",
        )),
        "declared_not_release": "PRODUCTION_RELEASE_AUTHORIZED: false" in code,
    }
    if not all(checks.values()):
        return blocked("LEGACY_COLLECTOR_CONTRACT_DRIFT")
    return {
        "verdict": "HOLD",
        "code": "PINNED_LEGACY_COLLECTOR_HAS_OPEN_LIVE_SAFETY_GATES",
        "live_sha": LIVE_SHA,
        "source_locked": True,
        "audit_source_blob": AUDIT_BLOBS[COLLECTOR],
        "static_read_only_contract": "OBSERVED_SOURCE_ONLY",
        "open_risks": list(FIXED_RISKS),
        "real_aiven_connection_executed": False,
        "schema_94_verified": False,
        "backup_restorable": False,
        "owner_go": False,
        "production_go": False,
    }


def run(checkout: Path) -> dict[str, object]:
    # Only local source bytes are read: no credentials, login paths or SQL.
    try:
        verify_source(checkout)
        src = (checkout / COLLECTOR).read_text(encoding="utf-8")
    except (Block, OSError, UnicodeError):
        return blocked("SOURCE_NOT_PINNED")
    return review(src, source_verified=True)


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--checkout", type=Path, required=True)
    args = parser.parse_args()
    if not args.checkout.is_absolute():
        result = blocked("ABSOLUTE_CHECKOUT_REQUIRED")
    else:
        result = run(args.checkout)
    print(json.dumps(result, sort_keys=True))
    # Success only signifies a completed offline denial review; never real GO.
    return 0 if result["code"] == "PINNED_LEGACY_COLLECTOR_HAS_OPEN_LIVE_SAFETY_GATES" else 2


if __name__ == "__main__":
    raise SystemExit(main())
