#!/usr/bin/env python3
"""Offline fail-closed Render binding phase classifier; never a release GO.

Reads a deliberately secretless operator snapshot. No Render API, GitHub API,
database, environment, or credential access; never prints supplied values.
"""
from __future__ import annotations

import argparse
from datetime import datetime, timezone
import json
from pathlib import Path
import re
import sys

SHA = re.compile(r"[0-9a-f]{40}\Z")
STAMP = re.compile(r"\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\Z")
MAX_BYTES = 4096
MAX_AGE_SECONDS = 1800
MAX_FUTURE_SKEW_SECONDS = 120
REPO = "https://github.com/1kz-ma1/Canovia-web"
FIELDS = frozenset({
    "source", "observed_at_utc", "workspace_id", "service_id", "repo",
    "branch", "auto_deploy", "auto_deploy_trigger", "live_deploy_status",
    "live_sha",
})
PHASE_BRANCH = {
    "before-branch-switch": "main",
    "after-branch-switch": "production",
    "after-manual-deploy": "production",
}


def result(code: str, checks: dict[str, str] | None = None) -> dict:
    return {
        "result": code,
        "checks": checks or {},
        "render_binding_attested": False,
        "live_deploy_attested": False,
        "runtime_environment_verified": False,
        "migration_verified": False,
        "device_smoke_verified": False,
        "owner_production_go_recorded": False,
        "release_authorized": False,
        "production_modified": False,
        "note": "Sanitized operator-supplied snapshot only; confirm source in Render UI.",
    }


def evaluate(snapshot: Path, phase: str, expected_live_sha: str,
             expected_service_id: str, expected_workspace_id: str,
             now: datetime | None = None) -> dict:
    if (phase not in PHASE_BRANCH or not SHA.fullmatch(expected_live_sha)
            or not re.fullmatch(r"srv-[a-z0-9]+", expected_service_id)
            or not re.fullmatch(r"tea-[a-z0-9]+", expected_workspace_id)):
        return result("BLOCK", {"input": "INVALID"})
    try:
        if (snapshot.is_symlink() or not snapshot.is_file()
                or not 0 < snapshot.stat().st_size <= MAX_BYTES):
            return result("BLOCK", {"snapshot": "INVALID"})
        data = json.loads(snapshot.read_text(encoding="utf-8"))
    except (OSError, UnicodeError, ValueError):
        return result("BLOCK", {"snapshot": "INVALID"})
    if (not isinstance(data, dict) or data.keys() != FIELDS
            or any(type(v) is not str for v in data.values())):
        return result("BLOCK", {"snapshot_schema": "INVALID"})
    checks = {}
    checks["source_kind"] = "PASS" if data["source"] == "render-ui-secretless-manual-observation" else "BLOCK"
    checks["workspace_identity"] = "PASS" if data["workspace_id"] == expected_workspace_id else "BLOCK"
    checks["service_identity"] = "PASS" if data["service_id"] == expected_service_id else "BLOCK"
    checks["repository_binding"] = "PASS" if data["repo"] == REPO else "BLOCK"
    checks["phase_branch"] = "PASS" if data["branch"] == PHASE_BRANCH[phase] else "BLOCK"
    checks["auto_deploy_off"] = "PASS" if (data["auto_deploy"] == "no"
                                                 and data["auto_deploy_trigger"] == "off") else "BLOCK"
    checks["live_deploy_status"] = "PASS" if data["live_deploy_status"] == "live" else "BLOCK"
    checks["live_sha_matches_operator_target"] = "PASS" if data["live_sha"] == expected_live_sha else "BLOCK"
    try:
        if not STAMP.fullmatch(data["observed_at_utc"]):
            raise ValueError()
        stamp = datetime.strptime(data["observed_at_utc"], "%Y-%m-%dT%H:%M:%SZ").replace(tzinfo=timezone.utc)
        current = now or datetime.now(timezone.utc)
        age = (current - stamp).total_seconds()
        fresh = -MAX_FUTURE_SKEW_SECONDS <= age <= MAX_AGE_SECONDS
    except (ValueError, OverflowError, TypeError):
        fresh = False
    checks["observation_freshness"] = "PASS" if fresh else "BLOCK"
    return result("REVIEW_REQUIRED" if all(v == "PASS" for v in checks.values()) else "BLOCK", checks)


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--snapshot", type=Path, required=True,
                        help="Private, hand-verified, secretless snapshot outside repository")
    parser.add_argument("--phase", choices=tuple(PHASE_BRANCH), required=True)
    parser.add_argument("--expected-live-sha", required=True,
                        help="Operator-confirmed expected Live commit; never taken from moving main")
    parser.add_argument("--expected-service-id", required=True)
    parser.add_argument("--expected-workspace-id", required=True)
    args = parser.parse_args()
    output = evaluate(args.snapshot, args.phase, args.expected_live_sha,
                      args.expected_service_id, args.expected_workspace_id)
    print(json.dumps(output, sort_keys=True))
    return 0 if output["result"] == "REVIEW_REQUIRED" else 2


if __name__ == "__main__":
    sys.exit(main())
