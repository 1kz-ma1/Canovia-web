#!/usr/bin/env python3
"""Build *only* an Aiven MySQL service-user request BODY with zero grants.

No API calls, tokens, connection data, SQL, admin access or account creation.
Operators must separately approve/provision, check the actual response and
verify SHOW GRANTS before assigning any precisely scoped permissions.

Official Aiven contract: mysql_grants=[] is a connecting-only new user;
omitting mysql_grants may result in an administrator user.
"""
from __future__ import annotations

import argparse
import json
import re
import sys

USERNAME = re.compile(r"^[a-z][a-z0-9_]{2,31}$")
RESERVED = {"avnadmin", "root", "mysql", "admin", "canovia", "defaultdb"}


class UnsafeIdentity(ValueError):
    pass


def zero_grants_payload(username: str) -> dict:
    if not isinstance(username, str) or not USERNAME.fullmatch(username):
        raise UnsafeIdentity
    if username in RESERVED or not username.startswith("canovia_p0_"):
        raise UnsafeIdentity
    # Intentional explicit empty field. Do NOT replace with omission or
    # ["SELECT", "REFERENCES"] — the latter may expose private records.
    return {"username": username, "mysql_grants": []}


def main() -> int:
    p = argparse.ArgumentParser(description="Offline, non-executing Aiven API body builder")
    p.add_argument("--username", required=True, help="Non-secret audit username, canovia_p0_*")
    args = p.parse_args()
    try:
        body = zero_grants_payload(args.username)
    except UnsafeIdentity:
        print("P0_AIVEN_USER_PAYLOAD: BLOCK_INVALID_USERNAME", file=sys.stderr)
        return 2
    print(json.dumps(body, separators=(",", ":"), ensure_ascii=True))
    print("P0_AIVEN_USER_PAYLOAD: OFFLINE_ONLY_NOT_PROVISIONED", file=sys.stderr)
    return 0


if __name__ == "__main__":
    sys.exit(main())
