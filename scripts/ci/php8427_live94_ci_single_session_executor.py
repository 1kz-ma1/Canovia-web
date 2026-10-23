#!/usr/bin/env python3
"""Source-bound Live94 single-client executor, DISPOSABLE CI ONLY.

This deliberately has no Aiven/live mode, arbitrary host/user/database,
operator approval override, production release gate or secret input.
"""
from __future__ import annotations

import argparse
import json
import os
from pathlib import Path
import re
import resource
import shutil
import signal
import stat
import subprocess
import sys
import tempfile

sys.path.insert(0, str(Path(__file__).resolve().parents[2]))
from scripts.ci.php8427_live94_disposable_mysql_smoke import (
    DATABASE, MAX_RESULT_BYTES, PASSWORD, USERNAME, ci_guard, verify_result,
)
from scripts.ci.php8427_live94_frozen_sql_manifest import (
    INVENTORY, INVENTORY_SECTIONS, LEDGER, LEDGER_SECTIONS,
    build_manifest, statements_from_frozen_bytes,
)
from scripts.ci.php8427_live94_offline_source_gate import Block

# Hard-coded synthetic-only values. Any production-host configurability requires
# a SEPARATE reviewed implementation and explicit owner authorization.
HOST = "localhost"
PORT = "3306"
MAX_WALL_SECONDS = 70
CONTAINER_ID_RE = re.compile(r"[a-f0-9]{12,64}\Z")
SCRIPT_HEADER = (
    b"SELECT 'CI_BEGIN', CONNECTION_ID(), @@server_uuid, CURRENT_USER(), "
    b"DATABASE(), CURRENT_ROLE(), @@GLOBAL.mandatory_roles;\n"
    b"SELECT 'CI_GRANTS_BEGIN';\n"
    b"SHOW GRANTS FOR CURRENT_USER();\n"
    b"SELECT 'CI_GRANTS_END';\n"
)
SCRIPT_FOOTER = (
    b"SELECT 'CI_END', CONNECTION_ID(), @@server_uuid, CURRENT_USER(), "
    b"DATABASE(), CURRENT_ROLE(), @@GLOBAL.mandatory_roles;\n"
)
class SafeStage(Block):
    """Only a fixed stage name, never a MySQL or file error string."""
    def __init__(self, stage: str):
        self.stage = stage
        super().__init__()


ALWAYS_FALSE = {
    "real_aiven_connected": False,
    "real_tls_peer_authenticated": False,
    "real_roles_and_grants_verified": False,
    "remote_session_termination_verified": False,
    "real_schema_verified": False,
    "owner_connection_go": False,
    "production_go": False,
}


def synthetic_only_guard() -> Path:
    ci_guard()
    if not CONTAINER_ID_RE.fullmatch(os.environ.get("MYSQL_SERVICE_CONTAINER", "")):
        raise Block()
    if os.environ.get("CANOVIA_P0_DISPOSABLE_ROOT_PASSWORD") != "ci-only-root-password":
        raise Block()
    if os.environ.get("DB_CONNECTION") != "mysql":
        raise Block()
    # No ambient credentials or login files besides this exact fictional CI
    # fixture. Reading such values (rather than their presence) is prohibited.
    for name in (
        "DATABASE_URL", "DB_URL", "MYSQL_TEST_LOGIN_FILE", "MYSQL_HOME",
        "MYSQL_GROUP_SUFFIX", "MYSQL_HOST", "MYSQL_TCP_PORT",
        "CANOVIA_P0_LOGIN_PATH", "CANOVIA_P0_CA_FILE",
        "CANOVIA_P0_OPERATOR_APPROVED", "CANOVIA_P0_TARGET_VERIFIED",
    ):
        if name in os.environ:
            raise Block()
    temp = Path(os.environ["RUNNER_TEMP"])
    if temp.is_symlink() or not temp.is_dir():
        raise Block()
    return temp.resolve()


def immutable_packet(checkout: Path) -> bytes:
    """The seven frozen historical SELECT statements, exactly once/in order."""
    manifest = build_manifest(checkout)
    if (manifest["sql_statement_count"] != 7
            or manifest["real_aiven_connected"] is not False):
        raise Block()
    queries = []
    for source, sections in (
        (INVENTORY, INVENTORY_SECTIONS),
        (LEDGER, LEDGER_SECTIONS),
    ):
        raw = (checkout / source).read_bytes()
        queries.extend(statements_from_frozen_bytes(raw, sections))
    if len(queries) != 7 or any(not q.endswith(b";") for q in queries):
        raise Block()
    return SCRIPT_HEADER + b"\n".join(queries) + b"\n" + SCRIPT_FOOTER


def cli_arguments(executable: str, ca: Path) -> list[str]:
    """Fixed program argument vector; never shell, URL, or login-path."""
    return [
        executable, "--no-defaults", "--no-login-paths",
        "--protocol=TCP", "--host=localhost", "--port=3306",
        "--connect-timeout=5", "--user=" + USERNAME, "--database=" + DATABASE,
        "--ssl-mode=VERIFY_IDENTITY", "--ssl-ca=" + str(ca),
        "--skip-reconnect", "--local-infile=0", "--skip-system-command",
        "--skip-named-commands", "--batch", "--raw", "--skip-column-names",
        "--skip-auto-rehash",
    ]


def secure_ca(path: Path, runner_temp: Path) -> Path:
    if not path.is_absolute() or path.is_symlink() or not path.is_file():
        raise Block()
    final = path.resolve()
    if runner_temp != final and runner_temp not in final.parents:
        raise Block()
    try:
        st = final.stat()
        if not stat.S_ISREG(st.st_mode) or st.st_size < 300 or st.st_size > 8192:
            raise Block()
        if st.st_mode & (stat.S_IWGRP | stat.S_IWOTH):
            raise Block()
        # This is the disposable CA only, never a provider credential.
        if not final.read_bytes().startswith(b"-----BEGIN CERTIFICATE-----"):
            raise Block()
    except OSError:
        raise Block() from None
    return final


def _limited_file_size() -> None:
    # This protects against a runaway server/result in addition to the parser
    # cap. Not portable to Windows; GitHub runner here is Linux.
    resource.setrlimit(resource.RLIMIT_FSIZE, (MAX_RESULT_BYTES + 1, MAX_RESULT_BYTES + 1))


def execute(checkout: Path, ca: Path) -> dict[str, object]:
    try:
        runner = synthetic_only_guard()
    except BaseException:
        raise SafeStage("GUARD") from None
    if not checkout.is_absolute():
        raise SafeStage("SOURCE_PATH")
    try:
        safe_ca = secure_ca(ca, runner)
    except BaseException:
        raise SafeStage("CA") from None
    # Verify all old-Live source and SQL bytes before the *single* connection.
    try:
        packet = immutable_packet(checkout)
    except BaseException:
        raise SafeStage("SOURCE") from None
    binary = shutil.which("mysql")
    if binary is None or not Path(binary).is_file():
        raise SafeStage("MYSQL_BINARY")
    with tempfile.TemporaryDirectory(prefix="live94-single-session-", dir=runner) as name:
        private = Path(name)
        os.chmod(private, 0o700)
        out = private / "result.tsv"
        err = private / "stderr-private.txt"
        # Prevent MySQL from reading global/user defaults and login-path;
        # scrub entire inherited environment and use only CI fake password.
        mysql_env = {
            "PATH": os.defpath, "HOME": str(private), "LC_ALL": "C",
            "MYSQL_PWD": PASSWORD,
        }
        args = cli_arguments(binary, safe_ca)
        try:
            with out.open("xb") as f, err.open("xb") as error_file:
                proc = subprocess.Popen(
                    args, stdin=subprocess.PIPE, stdout=f,
                    stderr=error_file, env=mysql_env, cwd=private,
                    start_new_session=True, preexec_fn=_limited_file_size,
                )
                try:
                    _, _ = proc.communicate(packet, timeout=MAX_WALL_SECONDS)
                except subprocess.TimeoutExpired:
                    os.killpg(proc.pid, signal.SIGKILL)
                    proc.communicate()
                    raise Block() from None
            if proc.returncode != 0:
                # Classify in private memory; NEVER emit raw CLI stderr.
                message = err.read_bytes()[:8192].lower()
                if b"unknown option" in message or b"unknown variable" in message:
                    code = "UNSUPPORTED_OPTION"
                elif b"access denied" in message:
                    code = "AUTH_DENIED"
                elif b"ssl" in message or b"certificate" in message or b"tls" in message:
                    code = "TLS_NEGOTIATION"
                elif b"can't connect" in message or b"connection refused" in message:
                    code = "TCP_CONNECT"
                else:
                    code = "OTHER"
                raise SafeStage("MYSQL_CLIENT_EXIT_" + code)
            if out.stat().st_size > MAX_RESULT_BYTES:
                raise SafeStage("OUTPUT_LIMIT")
            # The source-bound observation must include matching session,
            # server, principal and role/grants, then exactly 94 fake entries.
            try:
                verdict = verify_result(out.read_bytes(), checkout)
            except BaseException:
                raise SafeStage("OUTPUT_VALIDATION") from None
        except (OSError, subprocess.SubprocessError):
            raise SafeStage("LOCAL_PROCESS_FAILURE") from None
    if (verdict["verdict"] != "HOLD"
            or verdict["same_client_connection_observed"] is not True
            or verdict["scope_limited_grants_observed"] is not True):
        raise Block()
    return {
        "verdict": "HOLD",
        "code": "DISPOSABLE_ONE_CLIENT_VERIFIED_TLS_FROZEN_SQL_NO_LIVE_GO",
        "synthetic_one_client_finished": True,
        "synthetic_tls_verify_identity_exercised": True,
        "pinned_select_count": 7,
        "synthetic_migration_ledger_count": 94,
        "physical_schema_intentionally_incomplete": True,
        **ALWAYS_FALSE,
    }


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--checkout", required=True, type=Path)
    parser.add_argument("--ca", required=True, type=Path)
    args = parser.parse_args()
    try:
        answer = execute(args.checkout, args.ca)
        rc = 0
    except BaseException as exc:
        stage = exc.stage if type(exc) is SafeStage else "OTHER"
        answer = {
            "verdict": "BLOCK",
            "code": "DISPOSABLE_SINGLE_CLIENT_REJECTED_" + stage,
            "synthetic_one_client_finished": False,
            **ALWAYS_FALSE,
        }
        rc = 2
    print(json.dumps(answer, sort_keys=True))
    return rc


if __name__ == "__main__":
    sys.exit(main())
