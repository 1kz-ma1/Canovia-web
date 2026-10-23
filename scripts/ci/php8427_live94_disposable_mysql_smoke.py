#!/usr/bin/env python3
"""CI-ONLY disposable MySQL 8.x fixture packet and redacted result verifier.

No network/database client in this module. In particular it cannot be used
as an Aiven reader or as a production release authorizer.
"""
from __future__ import annotations

import argparse
import json
import os
from pathlib import Path
import re
import sys

sys.path.insert(0, str(Path(__file__).resolve().parents[2]))

from scripts.ci.php8427_live94_frozen_sql_manifest import (
    INVENTORY, LEDGER, INVENTORY_SECTIONS, LEDGER_SECTIONS,
    build_manifest, statements_from_frozen_bytes,
)
from scripts.ci.php8427_live94_offline_source_gate import LIVE_MIGRATION_COUNT, Block
from scripts.ci.p0_mysql_metadata_grants_guard import verify_grants
from scripts.ci.p0_mysql_inventory_tsv_import import parse_tsv
from scripts.ci.p0_mysql_full_ledger_offline_check import (
    expected_migration_names, parse_full_ledger, compare_ledger,
)

DATABASE = "canovia_live94_synth_ci"
USERNAME = "live94_ci"
PASSWORD = "ci-only-live94-password"
MAX_RESULT_BYTES = 262144
VERSION = re.compile(r"^8\.(?:0|4)\.\d{1,3}(?:[-+][A-Za-z0-9_.-]{1,32})?$")
CID = re.compile(r"^[1-9]\d{0,10}$")
SERVER = re.compile(r"^[A-Za-z0-9_-]{8,96}$")


def ci_guard() -> None:
    if not (os.environ.get("GITHUB_ACTIONS") == "true"
            and os.environ.get("CANOVIA_LIVE94_DISPOSABLE_CI") == "1"
            and os.environ.get("APP_ENV") == "testing"
            and os.environ.get("DB_HOST") == "127.0.0.1"
            and os.environ.get("DB_PORT") == "3306"
            and os.environ.get("DB_DATABASE") == DATABASE
            and os.environ.get("DB_USERNAME") == "canovia_ci"
            and os.environ.get("DB_PASSWORD") == "ci-only-ephemeral-password"
            and os.environ.get("CANOVIA_P0_DISPOSABLE_MYSQL_CI") == "1"
            and os.environ.get("RUNNER_TEMP", "").startswith("/")):
        raise Block()


def private_create(path: Path, content: bytes) -> None:
    if not path.is_absolute() or path.parent.resolve() != Path(os.environ["RUNNER_TEMP"]).resolve():
        raise Block()
    fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
    try:
        with os.fdopen(fd, "wb") as file:
            file.write(content)
    except BaseException:
        path.unlink(missing_ok=True)
        raise


def prepare(checkout: Path, directory: Path) -> dict[str, object]:
    manifest = build_manifest(checkout)
    queries: list[bytes] = []
    for path, sections in ((INVENTORY, INVENTORY_SECTIONS),
                           (LEDGER, LEDGER_SECTIONS)):
        queries.extend(statements_from_frozen_bytes(
            (checkout / path).read_bytes(), sections
        ))
    if len(queries) != 7:
        raise Block()
    names = sorted(expected_migration_names(checkout / "database/migrations"))
    if len(names) != LIVE_MIGRATION_COUNT:
        raise Block()
    # Synthetic-only: dedicated ephemeral DB, no app data, exact historical
    # migration IDENTIFIER strings inserted into throwaway MySQL.
    ddl = [
        "CREATE TABLE migrations (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, "
        "migration VARCHAR(255) NOT NULL UNIQUE, batch INT NOT NULL);",
        "CREATE TABLE users (id BIGINT UNSIGNED NOT NULL PRIMARY KEY, "
        "email VARCHAR(100) NOT NULL);",
        "INSERT INTO users(id,email) VALUES (1,'ci-only@example.invalid');",
        ("INSERT INTO migrations(migration,batch) VALUES " +
         ",".join("('" + name + "',1)" for name in names) + ";"),
        "CREATE USER 'live94_ci'@'%' IDENTIFIED BY 'ci-only-live94-password';",
        "GRANT REFERENCES ON " + DATABASE + ".* TO 'live94_ci'@'%';",
        "GRANT SELECT ON " + DATABASE + ".migrations TO 'live94_ci'@'%';",
    ]
    header = [
        ("SELECT 'CI_BEGIN', CONNECTION_ID(), @@server_uuid, CURRENT_USER(), "
         "DATABASE(), CURRENT_ROLE(), @@GLOBAL.mandatory_roles;"),
        "SELECT 'CI_GRANTS_BEGIN';",
        "SHOW GRANTS FOR CURRENT_USER();",
        "SELECT 'CI_GRANTS_END';",
    ]
    footer = [
        ("SELECT 'CI_END', CONNECTION_ID(), @@server_uuid, CURRENT_USER(), "
         "DATABASE(), CURRENT_ROLE(), @@GLOBAL.mandatory_roles;"),
    ]
    private_create(directory / "setup.sql", ("\n".join(ddl) + "\n").encode("ascii"))
    private_create(directory / "audit.sql", (
        "\n".join(header).encode("ascii") + b"\n" +
        b"\n".join(queries) + b"\n" +
        "\n".join(footer).encode("ascii") + b"\n"
    ))
    return {
        "verdict": "HOLD", "packet": "SYNTHETIC_CI_LOCAL_ONLY",
        "pinned_query_count": len(queries),
        "synthetic_migration_count": len(names),
        "real_aiven_connected": False,
        "production_go": False,
    }


def identity(fields: list[str], label: str) -> tuple[str, ...]:
    if len(fields) != 7 or fields[0] != label:
        raise Block()
    _, connection, server, principal, database, roles, mandatory = fields
    if (not CID.fullmatch(connection)
            or not SERVER.fullmatch(server)
            or principal != "live94_ci@%"
            or database != DATABASE
            or roles != "NONE"
            or mandatory not in ("", "NONE")):
        raise Block()
    return tuple(fields[1:])


def verify_result(raw: bytes, checkout: Path) -> dict[str, object]:
    build_manifest(checkout)
    if type(raw) is not bytes or not 0 < len(raw) <= MAX_RESULT_BYTES:
        raise Block()
    try:
        text = raw.decode("ascii")
    except UnicodeError:
        raise Block() from None
    if "\r" in text or "\x00" in text or not text.endswith("\n"):
        raise Block()
    rows = [row.split("\t") for row in text.splitlines()]
    if len(rows) < 110:
        raise Block()
    beginning = identity(rows[0], "CI_BEGIN")
    ending = identity(rows[-1], "CI_END")
    if beginning != ending or rows[1] != ["CI_GRANTS_BEGIN"]:
        raise Block()
    ends = [i for i, row in enumerate(rows) if row == ["CI_GRANTS_END"]]
    if len(ends) != 1 or not 3 <= ends[0] <= 10:
        raise Block()
    grants = "\n".join(row[0] for row in rows[2:ends[0]]) + "\n"
    if any(len(row) != 1 for row in rows[2:ends[0]]) or not verify_grants(grants, DATABASE):
        raise Block()
    body = rows[ends[0] + 1:-1]
    if not body or body[0][0] != "connection":
        raise Block()
    ledger_starts = [i for i, row in enumerate(body) if row[0] == "migration_ledger"]
    if not ledger_starts:
        raise Block()
    index = ledger_starts[0]
    inventory = body[:index]
    ledger = body[index:]
    if not inventory or any(row[0] not in (
        "connection", "table", "column", "foreign_key", "index", "migration"
    ) for row in inventory):
        raise Block()
    if any(row[0] != "migration_ledger" for row in ledger):
        raise Block()
    # Exact historical schema importer rejects missing/surplus P0 labels.
    parsed = parse_tsv("\n".join("\t".join(row) for row in inventory) + "\n")
    if not VERSION.fullmatch(parsed["mysql_version"]):
        raise Block()
    if parsed["connection"] != "SCHEMA_SELECTED":
        raise Block()
    missing = sum(v != "PRESENT" for v in parsed["tables"].values())
    # Intentional incomplete mock proves the 94/94 ledger is not a schema GO.
    if missing < 1:
        raise Block()
    applied = parse_full_ledger("\n".join("\t".join(row) for row in ledger) + "\n")
    expected = expected_migration_names(checkout / "database/migrations")
    result = compare_ledger(expected, applied)
    if (len(applied) != LIVE_MIGRATION_COUNT
            or result.get("code") != "FULL_MIGRATION_LEDGER_MATCH_NOT_RELEASE"
            or result.get("release_authorized") is not False):
        raise Block()
    return {
        "verdict": "HOLD",
        "code": "DISPOSABLE_SAME_SESSION_94_LEDGER_NO_SCHEMA_GO",
        "pinned_live94_migrations_observed_in_fixture": 94,
        "same_client_connection_observed": True,
        "scope_limited_grants_observed": True,
        "synthetic_physical_metadata_intentionally_incomplete": True,
        "real_tls_peer_authenticated": False,
        "remote_session_termination_verified": False,
        "real_aiven_connected": False,
        "real_schema_verified": False,
        "production_go": False,
    }


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("mode", choices=("prepare", "verify"))
    parser.add_argument("--checkout", required=True, type=Path)
    parser.add_argument("--path", required=True, type=Path)
    args = parser.parse_args()
    try:
        ci_guard()
        if not args.checkout.is_absolute() or not args.path.is_absolute():
            raise Block()
        if args.mode == "prepare":
            answer = prepare(args.checkout, args.path)
        else:
            if (args.path.is_symlink() or not args.path.is_file()
                    or args.path.parent.resolve() != Path(os.environ["RUNNER_TEMP"]).resolve()):
                raise Block()
            answer = verify_result(args.path.read_bytes(), args.checkout)
        rc = 0
    except BaseException:
        answer = {
            "verdict": "BLOCK", "code": "DISPOSABLE_PACKET_OR_OBSERVATION_REJECTED",
            "real_aiven_connected": False, "production_go": False,
        }
        rc = 2
    print(json.dumps(answer, sort_keys=True))
    return rc


if __name__ == "__main__":
    sys.exit(main())
