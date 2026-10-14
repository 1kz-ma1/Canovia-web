#!/usr/bin/env python3
"""V5.9: local, read-only release candidate audit packet, never a release GO.

No DB/network calls, secret ingestion, evidence promotion, mutable Git refs,
or changes to production. Only a pinned local release/* ref may label a packet.
"""
from __future__ import annotations

import argparse
import json
from pathlib import Path
import re
import subprocess
import sys

ROOT = Path(__file__).resolve().parents[2]
SHA = re.compile(r"[0-9a-f]{40}\Z")
REF = re.compile(r"release/20[0-9]{2}-[01][0-9]-[0-3][0-9]-([0-9a-f]{7,12})\Z")
V58_KEYS = {
    "result", "code", "release_authorized", "production_database_modified",
    "production_identity_verified", "backup_restore_verified",
    "deployed_sha_verified", "device_acceptance_verified",
}
V58_REVIEW = frozenset({
    "EXACT_V53_V57_PENDING", "V53_APPLIED_V57_PENDING",
    "BOTH_MIGRATIONS_APPLIED_NOT_RELEASE_GO",
})
V58_BLOCK = frozenset({
    "MIGRATION_SOURCE_INCOMPLETE", "BASELINE_LEDGER_INCOMPLETE",
    "MIGRATION_LEDGER_INVALID", "BASE_TABLE_MISSING",
    "OTHER_PENDING_MIGRATIONS_REQUIRE_REVIEW", "MIGRATION_ORDER_INVALID",
    "COLUMN_SHAPE_MISMATCH", "PARTIAL_DDL_OR_LEDGER_SCHEMA_DRIFT",
    "CANDIDATE_SHA_INVALID", "CANDIDATE_CHECKOUT_MISMATCH", "INPUT_INVALID",
})

# Fixed, minimally scoped audit categories. There is no user-controlled text
# field, proof flag, approval checkbox or machine-verification shortcut.
REQUIREMENTS = (
    ("scope", "Audit the full unshipped source/migration delta against the known Live baseline"),
    ("identity", "Independently verify production service, account, database and effective grants"),
    ("schema", "Review exact full migration ledger and physical schema on pinned candidate"),
    ("ddl", "Review MySQL version, table size, lock budget and possible partial-DDL recovery"),
    ("compatibility", "Rehearse pinned old/new app compatibility against upgraded disposable schema"),
    ("backup", "Independently inspect backup availability, freshness and recovery risk"),
    ("restore", "Demonstrate isolated restore and read-only validation or document untested risk"),
    ("protection", "Verify production PR rules, CI requirements and auto deploy OFF before first deploy"),
    ("promotion", "Obtain explicit owner release go/no-go on exact pinned candidate"),
    ("deployment", "Verify actual Render Live SHA and complete deploy/migration/health behavior"),
    ("web", "Complete real desktop/Safari/PWA and session/login smoke on deployed SHA"),
    ("ios", "Complete physical iPhone WKWebView acceptance on deployed SHA"),
    ("rollback", "Review tested image rollback and forward-only data-preserving incident plan"),
)


class InvalidPacket(Exception):
    pass


def parse_v58_report(raw: bytes) -> str:
    if len(raw) > 4096 or not raw or b"\x00" in raw:
        raise InvalidPacket()
    try:
        data = json.loads(raw.decode("utf-8"))
    except (UnicodeError, ValueError) as exc:
        raise InvalidPacket() from exc
    if not isinstance(data, dict) or set(data) != V58_KEYS:
        raise InvalidPacket()
    if any(data[key] is not False for key in V58_KEYS - {"result", "code"}):
        raise InvalidPacket()
    if not isinstance(data["result"], str) or not isinstance(data["code"], str):
        raise InvalidPacket()
    if data["result"] == "REVIEW_REQUIRED" and data["code"] in V58_REVIEW:
        return data["code"]
    if data["result"] == "BLOCK" and data["code"] in V58_BLOCK:
        return "V58_BLOCKED"
    raise InvalidPacket()


def checked_ref(candidate_sha: str, release_ref: str, root: Path = ROOT) -> bool:
    if not isinstance(candidate_sha, str) or not SHA.fullmatch(candidate_sha):
        return False
    match = REF.fullmatch(release_ref) if isinstance(release_ref, str) else None
    if match is None or not candidate_sha.startswith(match.group(1)):
        return False
    try:
        head = subprocess.run(
            ["git", "rev-parse", "HEAD"], cwd=root, capture_output=True,
            text=True, check=True, timeout=5,
        ).stdout.strip()
        pinned = subprocess.run(
            ["git", "show-ref", "--verify", "--hash",
             "refs/heads/" + release_ref], cwd=root, capture_output=True,
            text=True, check=True, timeout=5,
        ).stdout.strip()
        return head == candidate_sha and pinned == candidate_sha
    except (OSError, subprocess.SubprocessError):
        return False


def packet(candidate_sha: str, release_ref: str, v58_code: str | None) -> dict:
    # The report is an operator-facing index, not an evidence recorder.
    # V5.8 TSV source authenticity is not independently established here.
    if v58_code in V58_REVIEW:
        schema_status = "REPORTED_REVIEW_REQUIRED_UNVERIFIED"
        schema_reason = "V58_METADATA_REPORT_NOT_INDEPENDENTLY_AUTHENTICATED"
    elif v58_code == "V58_BLOCKED":
        schema_status = "BLOCK"
        schema_reason = "V58_REPORTED_BLOCK"
    else:
        schema_status = "UNVERIFIED"
        schema_reason = "NO_V58_METADATA_REPORT"
    rows = [
        {
            "id": key,
            "requirement": purpose,
            "state": schema_status if key == "schema" else "UNVERIFIED",
            "reason": schema_reason if key == "schema" else "OPERATOR_EVIDENCE_REQUIRED",
            "independently_verified": False,
        }
        for key, purpose in REQUIREMENTS
    ]
    return {
        "version": "development-operational-v5.9",
        "candidate_sha": candidate_sha,
        "release_ref": release_ref,
        "result": "BLOCK",
        "reason": "PRODUCTION_OWNER_APPROVAL_AND_INDEPENDENT_EVIDENCE_MISSING",
        "release_authorized": False,
        "production_database_modified": False,
        "backup_restore_verified": False,
        "deployed_sha_verified": False,
        "device_acceptance_verified": False,
        "notes": "Local operator checklist only. Never upload raw ledger/TSV, credentials, screenshots or private Plan data.",
        "requirements": rows,
    }


def blocked(code: str) -> dict:
    # Never echo untrusted candidate SHA/ref/path or submitted JSON values.
    return {
        "version": "development-operational-v5.9",
        "result": "BLOCK",
        "reason": code,
        "release_authorized": False,
    }


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--candidate-sha", required=True)
    parser.add_argument("--release-ref", required=True)
    parser.add_argument("--v58-report")
    args = parser.parse_args()
    if not checked_ref(args.candidate_sha, args.release_ref):
        result = blocked("CANDIDATE_REF_NOT_PINNED")
        exit_code = 2
    else:
        try:
            v58_code = None
            if args.v58_report is not None:
                path = Path(args.v58_report)
                if not path.is_file() or path.is_symlink() or path.stat().st_size > 4096:
                    raise InvalidPacket()
                v58_code = parse_v58_report(path.read_bytes())
            result = packet(args.candidate_sha, args.release_ref, v58_code)
            exit_code = 0  # Created an UNAPPROVED packet, not release approval.
        except (InvalidPacket, OSError, ValueError):
            result = blocked("UNTRUSTED_V58_REPORT")
            exit_code = 2
    print(json.dumps(result, ensure_ascii=False, sort_keys=True))
    return exit_code


if __name__ == "__main__":
    sys.exit(main())
