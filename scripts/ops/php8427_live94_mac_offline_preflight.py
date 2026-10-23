#!/usr/bin/env python3
"""Mac operator *offline-only* source/runtime preflight for old Live94.

This is NOT a real Aiven connector, nor permission to use the historical
collector. It never invokes mysql, fetches a CA, checks remote DNS/TLS, reads a
login-path, asks for credentials, or creates a signed owner approval.
"""
from __future__ import annotations

import argparse
import json
import os
from pathlib import Path
import shutil
import stat
import sys

from scripts.ci.php8427_live94_offline_source_gate import (
    Block, LIVE_SHA, LIVE_MIGRATION_COUNT, verify_source,
)

# Presence only; never read/log credential or target values.
DANGEROUS_AMBIENT = frozenset({
    "MYSQL_PWD", "MYSQL_TEST_LOGIN_FILE", "MYSQL_HOME", "MYSQL_HOST",
    "MYSQL_TCP_PORT", "MYSQL_UNIX_PORT", "MYSQL_GROUP_SUFFIX",
    "DB_PASSWORD", "DB_URL", "DATABASE_URL", "DATABASE_PASSWORD",
    "CANOVIA_P0_OPERATOR_APPROVED", "CANOVIA_P0_TARGET_VERIFIED",
    "CANOVIA_P0_GRANTS_REVIEWED", "CANOVIA_P0_LOGIN_PATH",
    "CANOVIA_P0_DATABASE", "CANOVIA_P0_CA_FILE",
    "CANOVIA_APPROVED_MIGRATION_SHA",
})
BINARY_NAMES = ("git", "mysql", "python3")
NEVER_APPROVED = {
    "real_aiven_connected": False,
    "real_target_tls_authenticated": False,
    "real_roles_and_grants_verified": False,
    "remote_session_end_verified": False,
    "real_schema_94_verified": False,
    "backup_restore_verified": False,
    "owner_connection_go": False,
    "production_go": False,
}


def binary_is_locally_restricted(path: str | None, uid: int) -> bool:
    """Local permission sanity check, NOT a code-signing/provenance proof."""
    if not path or not os.path.isabs(path):
        return False
    try:
        final = Path(os.path.realpath(path))
        st = final.stat()
    except OSError:
        return False
    return (stat.S_ISREG(st.st_mode)
            and st.st_uid in (0, uid)
            and not st.st_mode & (stat.S_IWGRP | stat.S_IWOTH))


def result(code: str, *, source_exact: bool = False,
           local_binary_permissions_sane: bool = False,
           mac_platform: bool = False) -> dict[str, object]:
    return {
        "verdict": "HOLD",
        "code": code,
        "expected_deployed_source": LIVE_SHA,
        "expected_migration_source_count": LIVE_MIGRATION_COUNT,
        "source_exact": source_exact,
        "mac_platform": mac_platform,
        "local_binary_permissions_sane": local_binary_permissions_sane,
        "binary_provenance_authenticated": False,
        "credential_material_read": False,
        "mysql_invoked": False,
        "network_access_attempted": False,
        **NEVER_APPROVED,
    }


def preflight(
    checkout: Path, *,
    environ: dict[str, str] | None = None,
    platform_name: str | None = None,
    python_isolated: bool | None = None,
    python_no_bytecode: bool | None = None,
    executable_lookup=None,
    source_checker=None,
    effective_uid: int | None = None,
) -> dict[str, object]:
    """Review local invariants and return a safe, fixed-code HOLD only.

    Injectable dependencies exist to test the policy on Linux CI using a
    mocked Mac environment. The CLI exposes NO flags for bypassing them.
    """
    env = os.environ if environ is None else environ
    platform_name = sys.platform if platform_name is None else platform_name
    isolated = bool(sys.flags.isolated) if python_isolated is None else python_isolated
    no_bytecode = bool(sys.dont_write_bytecode) if python_no_bytecode is None else python_no_bytecode
    lookup = shutil.which if executable_lookup is None else executable_lookup
    checker = verify_source if source_checker is None else source_checker
    uid = os.getuid() if effective_uid is None else effective_uid

    if platform_name != "darwin" or env.get("GITHUB_ACTIONS") == "true":
        return result("NOT_PRIVATE_OPERATOR_MAC")
    if not isolated or not no_bytecode or not os.path.isabs(str(checkout)):
        return result("ISOLATED_PYTHON_OR_ABSOLUTE_CHECKOUT_REQUIRED", mac_platform=True)
    if any(env.get(name) for name in DANGEROUS_AMBIENT):
        return result("AMBIENT_CREDENTIAL_OR_RELEASE_OVERRIDE_PRESENT", mac_platform=True)
    if env.get("CANOVIA_LIVE94_DISPOSABLE_CI") or env.get("CANOVIA_P0_DISPOSABLE_MYSQL_CI"):
        return result("SYNTHETIC_CI_CONTEXT_NOT_AN_OPERATOR", mac_platform=True)

    try:
        resolved = {name: lookup(name) for name in BINARY_NAMES}
        if not all(binary_is_locally_restricted(loc, uid) for loc in resolved.values()):
            return result("BINARY_MISSING_OR_UNSAFE_LOCAL_PERMISSIONS", mac_platform=True)
        # This checker reads local Git/fs metadata only. It never executes
        # the old collector/SQL, mysql, or anything involving Aiven secrets.
        checker(checkout)
    except (OSError, Block, ValueError, TypeError):
        return result(
            "SOURCE_PIN_OR_LOCAL_EXECUTABLE_CHECK_FAILED",
            mac_platform=True, local_binary_permissions_sane=True,
        )
    return result(
        "OFFLINE_SOURCE_AND_LOCAL_PERMISSIONS_CHECKED_PRIVATE_ATTESTATION_REQUIRED",
        source_exact=True, local_binary_permissions_sane=True, mac_platform=True,
    )


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument(
        "--checkout", type=Path, required=True,
        help="Absolute path to clean, detached old Live94 checkout. No passwords, CA, or host.",
    )
    args = parser.parse_args()
    assessment = preflight(args.checkout)
    print(json.dumps(assessment, sort_keys=True))
    # Exit 0 means the inert checker finished, NOT permission to connect.
    return 0 if assessment["source_exact"] is True else 2


if __name__ == "__main__":
    raise SystemExit(main())
