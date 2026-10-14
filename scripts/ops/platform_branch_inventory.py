#!/usr/bin/env python3
"""Offline read-only Git branch classification. Never deletes or closes anything.

All classifications are review queues, never authorization to remove a ref.
"""
from __future__ import annotations

import argparse
import json
from pathlib import Path
import re
import subprocess
import sys

SHA = re.compile(r"[0-9a-f]{40}\Z")
MAX_SNAPSHOT_BYTES = 2_000_000
PROTECTED = {"main", "production"}


class InventoryUnavailable(Exception):
    pass


def git(repo: Path, *args: str) -> str:
    try:
        p = subprocess.run(["git", "-C", str(repo), *args], text=True, capture_output=True, timeout=20)
    except (OSError, subprocess.TimeoutExpired) as exc:
        raise InventoryUnavailable() from exc
    if p.returncode != 0:
        raise InventoryUnavailable()
    return p.stdout.strip()


def read_open_prs(path: Path) -> dict[str, list[dict]]:
    try:
        if path.is_symlink() or not path.is_file() or path.stat().st_size > MAX_SNAPSHOT_BYTES:
            raise InventoryUnavailable()
        data = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, UnicodeError, ValueError) as exc:
        raise InventoryUnavailable() from exc
    if not isinstance(data, list) or len(data) > 10000:
        raise InventoryUnavailable()
    prs: dict[str, list[dict]] = {}
    for row in data:
        if (not isinstance(row, dict) or type(row.get("number")) is not int
            or row["number"] < 1 or not isinstance(row.get("headRefName"), str)
            or not isinstance(row.get("headRefOid"), str)
            or not SHA.fullmatch(row["headRefOid"])):
            raise InventoryUnavailable()
        branch = row["headRefName"]
        if not branch or len(branch) > 255 or "\n" in branch or "\r" in branch:
            raise InventoryUnavailable()
        prs.setdefault(branch, []).append({"number": row["number"], "sha": row["headRefOid"]})
    return prs


def classify(repo: Path, open_prs: dict[str, list[dict]] | None) -> dict:
    """Always emits REVIEW/HOLD, never DELETE_SAFE or an auto-removal action."""
    try:
        main = git(repo, "rev-parse", "--verify", "refs/remotes/origin/main^{commit}")
        prod = git(repo, "rev-parse", "--verify", "refs/remotes/origin/production^{commit}")
        listing = git(repo, "for-each-ref", "--format=%(refname) %(objectname)", "refs/remotes/origin/")
        rows = []
        for line in listing.splitlines():
            ref, sha = line.split(" ", 1)
            prefix = "refs/remotes/origin/"
            if not ref.startswith(prefix) or not SHA.fullmatch(sha):
                raise InventoryUnavailable()
            branch = ref[len(prefix):]
            if branch == "HEAD":
                continue
            if branch in PROTECTED or branch.startswith("release/"):
                code = "HOLD_PRODUCTION_OR_RELEASE_HISTORY"
            elif open_prs is None:
                code = "REVIEW_OPEN_PR_EVIDENCE_MISSING"
            elif branch in open_prs:
                code = ("HOLD_OPEN_PR" if any(p["sha"] == sha for p in open_prs[branch])
                        else "HOLD_OPEN_PR_HEAD_MISMATCH")
            else:
                # Ancestor proof establishes integration, never absence of active external work.
                p = subprocess.run(["git", "-C", str(repo), "merge-base", "--is-ancestor", sha, main],
                                   text=True, capture_output=True, timeout=20)
                if p.returncode not in (0, 1):
                    raise InventoryUnavailable()
                code = "REVIEW_IN_MAIN_NO_OPEN_PR" if p.returncode == 0 else "REVIEW_UNMERGED_OR_DIVERGED"
            rows.append({"branch": branch, "head_sha": sha, "classification": code})
        rows.sort(key=lambda x: x["branch"])
        if not rows or not any(x["branch"] == "main" for x in rows) or not any(x["branch"] == "production" for x in rows):
            raise InventoryUnavailable()
        counts: dict[str, int] = {}
        for row in rows:
            counts[row["classification"]] = counts.get(row["classification"], 0) + 1
        return {"result": "REVIEW_REQUIRED", "base_main_sha": main, "base_production_sha": prod,
                "snapshot_complete_attested": open_prs is not None, "branch_count": len(rows),
                "counts": counts, "branches": rows, "deletion_authorized": False,
                "note": "Review classification only; verify current remote refs, PR ownership and release history before requesting any deletion."}
    except (InventoryUnavailable, ValueError, OSError, subprocess.TimeoutExpired):
        return {"result": "BLOCK", "code": "LOCAL_GIT_EVIDENCE_UNAVAILABLE", "deletion_authorized": False}


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--repo", type=Path, default=Path("."))
    parser.add_argument("--open-prs-json", type=Path, help="Complete same-repository gh pr list JSON export")
    parser.add_argument("--attest-complete-pr-snapshot", action="store_true", help="Operator confirms the export includes every open PR")
    args = parser.parse_args()
    if args.attest_complete_pr_snapshot != bool(args.open_prs_json):
        result = {"result": "BLOCK", "code": "PR_SNAPSHOT_ATTESTATION_MISMATCH", "deletion_authorized": False}
    else:
        try:
            prs = read_open_prs(args.open_prs_json) if args.open_prs_json else None
            result = classify(args.repo, prs)
        except InventoryUnavailable:
            result = {"result": "BLOCK", "code": "OPEN_PR_SNAPSHOT_INVALID", "deletion_authorized": False}
    print(json.dumps(result, sort_keys=True))
    return 0 if result["result"] == "REVIEW_REQUIRED" else 2


if __name__ == "__main__":
    sys.exit(main())
