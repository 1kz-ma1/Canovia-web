#!/usr/bin/env python3
"""Old Live94 private-Mac operator policy adapter: OFFLINE ONLY, permanently HOLD.

No mysql, socket, subprocess, DNS, network, credential or connection path.
A local policy describes independently reviewed target metadata. Validating
its syntax is NOT validating Aiven account, host, trust or owner consent.
"""
from __future__ import annotations

import argparse
import json
import os
from pathlib import Path
import re
import ssl
import stat
import sys

sys.path.insert(0, str(Path(__file__).resolve().parents[2]))

from scripts.ci.php8427_live94_offline_source_gate import Block, LIVE_SHA
from scripts.ci.php8427_live94_ci_single_session_executor import immutable_packet
from scripts.ops.php8427_live94_mac_offline_preflight import preflight

MAX_POLICY_BYTES = 8192
MAX_CA_BYTES = 65536
POLICY_KEYS = frozenset({
    "protocol", "service_host", "service_port", "database",
    "reader_user", "ca_file", "tls_mode", "query_scope",
    "credential_handling", "execution",
})
HOST_RE = re.compile(
    r"(?=.{4,253}\Z)[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?"
    r"(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)+\Z",
    re.ASCII,
)
IDENT_RE = re.compile(r"[A-Za-z][A-Za-z0-9_]{2,63}\Z", re.ASCII)
ALLOWED_FALSE = {
    "server_identity_authenticated": False,
    "ca_provider_origin_authenticated": False,
    "reader_effective_grants_verified": False,
    "credential_transport_approved": False,
    "remote_session_termination_verified": False,
    "real_aiven_connected": False,
    "live_migrations_94_verified": False,
    "physical_schema_verified": False,
    "backup_restorable_verified": False,
    "owner_aiven_read_go": False,
    "production_go": False,
}


def fixed_result(code: str, **safe: bool) -> dict[str, object]:
    return {
        "verdict": "HOLD",
        "code": code,
        "source_sha": LIVE_SHA,
        "read_only_policy_syntax_checked": False,
        "private_ca_pem_loadable": False,
        "frozen_seven_queries_checked": False,
        "safe_client_flags_reviewed_no_execute": False,
        "mysql_invoked": False,
        "dns_or_network_attempted": False,
        "credentials_read": False,
        **ALLOWED_FALSE,
        **safe,
    }


def _private_regular_file(file: Path, *, uid: int, mode_private: bool,
                          max_size: int, scope: Path | None = None) -> Path:
    if not file.is_absolute() or file.is_symlink():
        raise Block()
    actual = file.resolve(strict=True)
    # No file may be created/read from checkout or this reviewed code repo.
    repo_root = Path(__file__).resolve().parents[2]
    forbidden_roots = [repo_root]
    if scope is not None:
        forbidden_roots.append(scope.resolve())
    if any(actual == root or root in actual.parents for root in forbidden_roots):
        raise Block()
    parent = actual.parent
    pst = parent.stat()
    if (pst.st_uid != uid or not stat.S_ISDIR(pst.st_mode)
            or pst.st_mode & (stat.S_IWGRP | stat.S_IWOTH)):
        raise Block()
    st = actual.stat()
    if (not stat.S_ISREG(st.st_mode)
            or st.st_uid != uid
            or not 0 < st.st_size <= max_size):
        raise Block()
    if mode_private:
        if stat.S_IMODE(st.st_mode) & 0o077:
            raise Block()
    elif st.st_mode & (stat.S_IWGRP | stat.S_IWOTH):
        raise Block()
    return actual


def _unique(keys: list[tuple[str, object]]) -> dict[str, object]:
    result = {}
    for key, value in keys:
        if key in result:
            raise Block()
        result[key] = value
    return result


def _policy_values(path: Path, checkout: Path, uid: int) -> dict[str, object]:
    policy_file = _private_regular_file(
        path, uid=uid, mode_private=True, max_size=MAX_POLICY_BYTES, scope=checkout,
    )
    try:
        raw = policy_file.read_bytes()
        data = json.loads(raw.decode("utf-8", "strict"), object_pairs_hook=_unique)
    except (OSError, ValueError, UnicodeError, TypeError):
        raise Block() from None
    if type(data) is not dict or set(data) != POLICY_KEYS:
        raise Block()  # Disallow password, secret, token, comments, approval flags.
    fixed = {
        "protocol": "MYSQL_TCP",
        "tls_mode": "VERIFY_IDENTITY",
        "query_scope": "OLD_LIVE94_PINNED_7_SELECTS",
        "credential_handling": "SEPARATE_SECURITY_DESIGN_REQUIRED",
        "execution": "OFFLINE_REVIEW_ONLY",
    }
    if any(data[key] != value for key, value in fixed.items()):
        raise Block()
    host, port = data["service_host"], data["service_port"]
    db, user = data["database"], data["reader_user"]
    if (type(host) is not str or HOST_RE.fullmatch(host) is None
            or host.startswith("localhost") or host.endswith(".localhost")
            or not host.rsplit(".", 1)[-1].isalpha()
            or host.endswith((".local", ".invalid"))
            or type(port) is not int or not 1 <= port <= 65535
            or type(db) is not str or IDENT_RE.fullmatch(db) is None
            or type(user) is not str or IDENT_RE.fullmatch(user) is None
            or user.lower() in {"root", "avnadmin", "admin", "mysql", "default"}):
        raise Block()
    ca_text = data["ca_file"]
    if type(ca_text) is not str or len(ca_text) > 1024:
        raise Block()
    ca = _private_regular_file(
        Path(ca_text), uid=uid, mode_private=False, max_size=MAX_CA_BYTES,
        scope=checkout,
    )
    # PEM must be locally parseable by the actual interpreter; no handshake.
    try:
        context = ssl.SSLContext(ssl.PROTOCOL_TLS_CLIENT)
        context.load_verify_locations(cafile=str(ca))
        if not context.check_hostname or context.verify_mode != ssl.CERT_REQUIRED:
            raise Block()
    except (ssl.SSLError, OSError, ValueError):
        raise Block() from None
    return data


def review_unexecuted_cli(data: dict[str, object]) -> tuple[str, ...]:
    """Build a potential command locally; do not display or execute it.

    This is an option review only, NOT a usable credential strategy.
    """
    args = (
        "mysql", "--no-defaults", "--no-login-paths",
        "--protocol=TCP", "--host=" + str(data["service_host"]),
        "--port=" + str(data["service_port"]),
        "--database=" + str(data["database"]),
        "--user=" + str(data["reader_user"]),
        "--ssl-mode=VERIFY_IDENTITY", "--ssl-ca=" + str(data["ca_file"]),
        "--connect-timeout=5", "--skip-reconnect", "--local-infile=0",
        "--skip-system-command", "--skip-named-commands",
        "--batch", "--raw", "--skip-column-names", "--skip-auto-rehash",
    )
    if (len(args) != len(set(args))
            or args[1:3] != ("--no-defaults", "--no-login-paths")
            or any("--password" in x or "--login-path=" in x for x in args)):
        raise Block()
    return args


def inspect_private_policy(
    checkout: Path, policy_path: Path, *,
    uid: int | None = None,
    packet_builder=immutable_packet,
) -> dict[str, object]:
    """Pure local inspection; no platform bypass is offered in the CLI."""
    actual_uid = os.getuid() if uid is None else uid
    if not checkout.is_absolute() or checkout.is_symlink():
        raise Block()
    data = _policy_values(policy_path, checkout, actual_uid)
    packet = packet_builder(checkout)
    if type(packet) is not bytes or packet.count(b"SELECT '") != 11:
        raise Block()  # 7 source SELECTs + 4 identity/grant/footer SELECTs.
    if not packet.endswith(b"@@GLOBAL.mandatory_roles;\n"):
        raise Block()
    opts = review_unexecuted_cli(data)
    if "--ssl-mode=VERIFY_IDENTITY" not in opts:
        raise Block()
    return fixed_result(
        "OFFLINE_POLICY_AND_PINNED_SQL_REVIEWED_PRIVATE_OWNER_ATTESTATION_REQUIRED",
        read_only_policy_syntax_checked=True,
        private_ca_pem_loadable=True,
        frozen_seven_queries_checked=True,
        safe_client_flags_reviewed_no_execute=True,
    )


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--checkout", type=Path, required=True)
    parser.add_argument("--private-policy", type=Path, required=True)
    args = parser.parse_args()
    try:
        mac = preflight(args.checkout)
        if mac["source_exact"] is not True:
            raise Block()
        answer = inspect_private_policy(args.checkout, args.private_policy)
        rc = 0  # A successful *offline inspection*, still permanently HOLD.
    except BaseException:
        answer = fixed_result("OFFLINE_MAC_POLICY_OR_FROZEN_SQL_REJECTED")
        rc = 2
    print(json.dumps(answer, sort_keys=True))
    return rc


if __name__ == "__main__":
    sys.exit(main())
