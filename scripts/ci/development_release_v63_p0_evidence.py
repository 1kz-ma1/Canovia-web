#!/usr/bin/env python3
"""Fail-closed V6.3 offline review of historical PR #513 P0 source evidence.

Does not establish end-to-end runtime acceptance or release GO. Optionally checks
the exact candidate Git checkout to verify the listed test method definitions.
No GitHub, Render, Aiven, network, DB connections or repository changes.
"""
from __future__ import annotations

import argparse
import json
from pathlib import Path
import re
import subprocess
import sys

ROOT = Path(__file__).resolve().parents[2]
MANIFEST = ROOT / "docs/development/audits/release_513_p0_evidence_2026_10_14.json"
EXPECTED_SHA = "bb8248f25545ae0d600fbb68f66c41c360004e2c"
GATES = tuple(f"P0-{n:02}" for n in range(1, 9))
STATUSES = {"TEST_REFERENCES_FOUND_NOT_E2E", "CRITICAL_FLOW_GAP"}
TEST_PATH = re.compile(r"tests/Feature/[A-Za-z0-9_]+Test\.php\Z")
TEST_METHOD = re.compile(r"test_[a-z0-9_]+\Z")
MAX_FILE_BYTES = 65536


class InvalidEvidence(Exception):
    pass


def load_manifest(raw: bytes) -> dict:
    if not raw or len(raw) > MAX_FILE_BYTES:
        raise InvalidEvidence()
    try:
        d = json.loads(raw.decode("utf-8"))
    except (UnicodeError, ValueError) as exc:
        raise InvalidEvidence() from exc
    if not isinstance(d, dict) or set(d) != {
        "version", "audit_date", "repository", "release_pr", "candidate_sha",
        "baseline_branch", "baseline_sha", "evidence_origin", "candidate_unchanged",
        "all_p0_end_to_end_verified", "release_authorized", "selection_status",
        "live_render_sha_verified", "live_aiven_schema_verified",
        "isolated_restore_verified", "physical_iphone_verified", "gates",
    }:
        raise InvalidEvidence()
    if (d["version"] != "v6.3"
        or d["audit_date"] != "2026-10-14"
        or d["repository"] != "1kz-ma1/Canovia-web"
        or type(d["release_pr"]) is not int or d["release_pr"] != 513
        or d["candidate_sha"] != EXPECTED_SHA
        or d["baseline_branch"] != "production"
        or d["baseline_sha"] != "15abdc9de6f24b5250fb255c579d1aa55facaf1a"
        or type(d["evidence_origin"]) is not str
        or len(d["evidence_origin"]) > 300
        or d["candidate_unchanged"] is not True
        or d["selection_status"] != "BLOCKED_NEEDS_P0_E2E"
        or any(d[k] is not False for k in (
            "all_p0_end_to_end_verified", "release_authorized", "live_render_sha_verified",
            "live_aiven_schema_verified", "isolated_restore_verified", "physical_iphone_verified"
        ))):
        raise InvalidEvidence()
    entries = d["gates"]
    if not isinstance(entries, list) or len(entries) != len(GATES):
        raise InvalidEvidence()
    if tuple(g.get("id") if isinstance(g, dict) else None for g in entries) != GATES:
        raise InvalidEvidence()
    refs = set()
    for item in entries:
        if set(item) != {"id", "title", "status", "reason", "source_tests", "ci_evidence", "runtime_evidence"}:
            raise InvalidEvidence()
        if (not isinstance(item["status"], str)
            or item["status"] not in STATUSES
            or item["ci_evidence"] != "EXACT_HEAD_CI_NOT_FULL_E2E"
            or item["runtime_evidence"] != "UNVERIFIED"
            or not isinstance(item["title"], str)
            or not isinstance(item["reason"], str)
            or not 1 <= len(item["title"]) <= 100
            or not 1 <= len(item["reason"]) <= 500):
            raise InvalidEvidence()
        tests = item["source_tests"]
        if not isinstance(tests, list) or not 1 <= len(tests) <= 5:
            raise InvalidEvidence()
        for t in tests:
            if not isinstance(t, dict) or set(t) != {"path", "method"}:
                raise InvalidEvidence()
            if (not isinstance(t["path"], str) or not TEST_PATH.fullmatch(t["path"])
                or not isinstance(t["method"], str) or not TEST_METHOD.fullmatch(t["method"])):
                raise InvalidEvidence()
            refs.add((t["path"], t["method"]))
    if len(refs) < 8:
        raise InvalidEvidence()
    return d


def verify_candidate_source(root: Path, manifest: dict) -> bool:
    """Verify exact checkout and test method declarations; not proof tests passed."""
    try:
        p = subprocess.run(
            ["git", "rev-parse", "HEAD"], cwd=root,
            text=True, capture_output=True, check=True, timeout=6,
        )
        if p.stdout.strip() != manifest["candidate_sha"]:
            return False
        for gate in manifest["gates"]:
            for evidence in gate["source_tests"]:
                file = root / evidence["path"]
                if file.is_symlink() or not file.is_file() or file.stat().st_size > 250000:
                    return False
                source = file.read_text(encoding="utf-8")
                if not re.search(
                    r"\bfunction\s+" + re.escape(evidence["method"]) + r"\s*\(",
                    source,
                ):
                    return False
        return True
    except (OSError, UnicodeError, subprocess.SubprocessError):
        return False


def decision(manifest: dict, source_revalidated: bool) -> dict:
    # A syntactically valid manifest can be inaccurate and MUST NEVER be
    # elevated to independent runtime or operator evidence.
    return {
        "version": "v6.3",
        "candidate_sha": manifest["candidate_sha"],
        "release_pr": 513,
        "gates": len(manifest["gates"]),
        "referenced_test_methods": sum(len(x["source_tests"]) for x in manifest["gates"]),
        "critical_flow_e2e_gaps": sum(x["status"] == "CRITICAL_FLOW_GAP" for x in manifest["gates"]),
        "source_methods_revalidated_at_exact_git_sha": source_revalidated,
        "whole_onboarding_to_replan_e2e_verified": False,
        "production_environment_verified": False,
        "release_authorized": False,
        "selection": "BLOCKED_NEEDS_P0_E2E",
        "result": "BLOCK",
    }


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--manifest", default=str(MANIFEST))
    parser.add_argument("--candidate-repo", help="Optional existing exact pinned checkout; no fetch")
    args = parser.parse_args()
    try:
        path = Path(args.manifest)
        if not path.is_file() or path.is_symlink() or path.stat().st_size > MAX_FILE_BYTES:
            raise InvalidEvidence()
        manifest = load_manifest(path.read_bytes())
        verified = (verify_candidate_source(Path(args.candidate_repo), manifest)
                    if args.candidate_repo is not None else False)
        if args.candidate_repo is not None and not verified:
            raise InvalidEvidence()
        result = decision(manifest, verified)
        code = 0  # A blocked evidence inventory was generated, NOT approval.
    except (InvalidEvidence, OSError, ValueError):
        result = {"version": "v6.3", "result": "BLOCK", "reason": "EVIDENCE_INVALID_OR_MISMATCHED",
                  "release_authorized": False}
        code = 2
    print(json.dumps(result, sort_keys=True))
    return code


if __name__ == "__main__":
    sys.exit(main())
