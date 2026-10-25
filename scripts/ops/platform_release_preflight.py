#!/usr/bin/env python3
"""Offline, read-only Git topology preflight for a pinned Canovia release.

Default mode has no network or credential access. Preparation mode optionally
reads bounded GitHub GET metadata; never Render/Aiven or deployment. Neither
REVIEW_REQUIRED nor any PASS field authorizes production.
"""
from __future__ import annotations

import argparse
import json
from pathlib import Path
import re
import subprocess
import sys

# A read-only CLI must not create repository __pycache__ on optional imports.
sys.dont_write_bytecode = True

SHA = re.compile(r"[0-9a-f]{40}\Z")
BRANCH = re.compile(r"release/[A-Za-z0-9][A-Za-z0-9._/-]*\Z")
MANUAL_GATES = (
    "actual_render_live_sha_operator_verified",
    "required_ci_for_exact_release_sha_verified",
    "aiven_live_schema_and_ledger_operator_audited",
    "fresh_encrypted_backup_and_offsite_copy_verified",
    "isolated_restore_or_explicit_recovery_risk_accepted",
    "production_env_and_startup_migrations_reviewed",
    "independent_staging_and_web_ios_smoke_verified",
    "explicit_owner_production_go_recorded",
)


class EvidenceUnavailable(Exception):
    """Untrusted or missing local Git evidence; details must not be printed."""


def git(repo: Path, *args: str) -> str:
    try:
        result = subprocess.run(
            ["git", "-C", str(repo), *args],
            capture_output=True, text=True, check=False, timeout=20,
        )
    except (OSError, subprocess.TimeoutExpired) as exc:
        raise EvidenceUnavailable() from exc
    if result.returncode != 0:
        raise EvidenceUnavailable()
    return result.stdout.strip()


def ancestor(repo: Path, earlier: str, later: str) -> bool:
    try:
        result = subprocess.run(
            ["git", "-C", str(repo), "merge-base", "--is-ancestor", earlier, later],
            capture_output=True, text=True, check=False, timeout=20,
        )
    except (OSError, subprocess.TimeoutExpired) as exc:
        raise EvidenceUnavailable() from exc
    if result.returncode not in (0, 1):
        raise EvidenceUnavailable()
    return result.returncode == 0


def verdict(result: str, checks: dict[str, str], changes: list[str] | None = None,
            migrations: list[str] | None = None) -> dict:
    return {
        "result": result,
        "checks": checks,
        "changed_path_count": len(changes or []),
        "migration_files": migrations or [],
        "manual_gates": {key: "NOT_VERIFIED" for key in MANUAL_GATES},
        "release_authorized": False,
        "production_modified": False,
        "note": "Local Git evidence only; never a production GO or a live-service attestation.",
    }


def evaluate(repo: Path, release_sha: str, live_sha: str, release_branch: str) -> dict:
    checks: dict[str, str] = {}
    if not SHA.fullmatch(release_sha) or not SHA.fullmatch(live_sha):
        return verdict("BLOCK", {"sha_input": "INVALID"})
    if (not BRANCH.fullmatch(release_branch) or ".." in release_branch
            or "//" in release_branch or release_branch.endswith("/")):
        return verdict("BLOCK", {"release_branch": "INVALID"})
    try:
        main = git(repo, "rev-parse", "--verify", "refs/remotes/origin/main^{commit}")
        prod = git(repo, "rev-parse", "--verify", "refs/remotes/origin/production^{commit}")
        pinned = git(repo, "rev-parse", "--verify", f"refs/remotes/origin/{release_branch}^{{commit}}")
        git(repo, "cat-file", "-e", f"{release_sha}^{{commit}}")
        checks["release_branch_exact_sha"] = "PASS" if pinned == release_sha else "BLOCK"
        checks["operator_supplied_live_matches_production"] = "PASS" if prod == live_sha else "BLOCK"
        checks["candidate_is_ancestor_of_main"] = "PASS" if ancestor(repo, release_sha, main) else "BLOCK"
        checks["production_is_ancestor_of_candidate"] = "PASS" if ancestor(repo, prod, release_sha) else "BLOCK"
        checks["candidate_differs_from_production"] = "PASS" if prod != release_sha else "BLOCK"
        if "BLOCK" in checks.values():
            return verdict("BLOCK", checks)
        changed = git(repo, "diff", "--name-only", "--no-renames", prod, release_sha, "--").splitlines()
        migration_names = [path for path in changed if path.startswith("database/migrations/")]
        return verdict("REVIEW_REQUIRED", checks, changed, migration_names)
    except EvidenceUnavailable:
        checks["git_evidence"] = "UNAVAILABLE"
        return verdict("BLOCK", checks)


# Preparation is a code-readiness report, not a second release authority.
PREPARATION_CI = {
    "ownership-and-persistence": ".github/workflows/prelaunch-shared-preparation.yml",
    "publication-and-session": ".github/workflows/integration-exposure.yml",
    "migration-recovery": ".github/workflows/v56-8-migration-recovery.yml",
    "future-browser (chromium)": ".github/workflows/future-carousel.yml",
    "future-browser (webkit)": ".github/workflows/future-carousel.yml",
    "Platform release preflight offline tests": ".github/workflows/platform-release-preflight.yml",
}
PREPARATION_LIVE = (
    "all_old_writers_stopped_and_no_supervisor_restart", "all_runtime_source_config_cache_match",
    "aiven_current_schema_ledger_trigger_and_ddl_principal", "actual_backup_restore_and_account_plan_integrity",
    "actual_auth_cookie_csrf_and_installed_service_worker", "render_live_branch_and_auto_deploy",
)


def preparation_github(sha: str, get) -> dict:
    """GET metadata only. No logs, artifacts, credentials, providers or deployment."""
    checks = {}
    prefix = "repos/1kz-ma1/Canovia-web/"
    try:
        page = get(prefix + "commits/" + sha + "/check-runs?per_page=100")
        rows = page.get("check_runs")
        if (not isinstance(rows, list) or not rows or len(rows) > 100
                or page.get("total_count") != len(rows) or any(not isinstance(r, dict) for r in rows)):
            raise EvidenceUnavailable()
        seen, runs = {}, {}
        all_status = "PASS"
        for row in rows:
            if row.get("head_sha") != sha or row.get("app", {}).get("slug") != "github-actions":
                raise EvidenceUnavailable()
            if row.get("status") != "completed" or row.get("conclusion") != "success":
                all_status = "FAIL" if row.get("conclusion") in ("failure", "timed_out", "cancelled", "action_required") else (all_status if all_status == "FAIL" else "UNKNOWN")
            name = row.get("name")
            if name not in PREPARATION_CI:
                continue
            if name in seen:
                raise EvidenceUnavailable()
            match = re.fullmatch(r"https://github.com/1kz-ma1/Canovia-web/actions/runs/([1-9][0-9]*)/job/[1-9][0-9]*", row.get("details_url", ""))
            if not match:
                raise EvidenceUnavailable()
            rid = match[1]
            if rid not in runs:
                runs[rid] = get(prefix + "actions/runs/" + rid)
            run = runs[rid]
            if (run.get("head_sha") != sha or run.get("repository", {}).get("full_name") != "1kz-ma1/Canovia-web"
                    or run.get("path") != PREPARATION_CI[name]):
                raise EvidenceUnavailable()
            state = "PASS" if row.get("status") == "completed" and row.get("conclusion") == "success" and run.get("status") == "completed" and run.get("conclusion") == "success" else "UNKNOWN"
            if row.get("conclusion") in ("failure", "timed_out", "cancelled", "action_required") or run.get("conclusion") in ("failure", "timed_out", "cancelled", "action_required"):
                state = "FAIL"
                all_status = "FAIL"
            seen[name] = state
        checks["exact_head_ci"] = ("FAIL" if all_status == "FAIL" else
                                   "PASS" if set(seen) == set(PREPARATION_CI) and all(v == "PASS" for v in seen.values()) and all_status == "PASS" else "UNKNOWN")
        checks["preparation_route_job_account_plan_ci"] = seen.get("ownership-and-persistence", "UNKNOWN")
        checks["selected_recovery_ci"] = seen.get("migration-recovery", "UNKNOWN")
        checks["synthetic_browser_ci"] = ("FAIL" if any(seen.get(n) == "FAIL" for n in ["future-browser (chromium)", "future-browser (webkit)"]) else "PASS" if all(seen.get(n) == "PASS" for n in ["future-browser (chromium)", "future-browser (webkit)"]) else "UNKNOWN")
        # Inventory is observable. Its adequacy/owner acceptance is a separate gate.
        for branch in ("main", "production"):
            rules = get(prefix + "rules/branches/" + branch)
            if not isinstance(rules, list) or len(rules) > 100:
                raise EvidenceUnavailable()
            checks[branch + "_rules_read"] = "PASS"
        return {"checks": checks, "ci_check_count": len(rows), "evidence_origin": "GITHUB_GET_METADATA"}
    except Exception:
        # API/JSON/subprocess errors can contain URLs or private data. Never echo.
        return {"checks": {"github_evidence": "UNKNOWN"}, "ci_check_count": 0, "evidence_origin": "UNAVAILABLE"}


def preparation_evaluate(repo: Path, sha: str, baseline_sha: str, get=None, ci_sha=None) -> dict:
    checks = {name: "LIVE_VERIFICATION_REQUIRED" for name in PREPARATION_LIVE}
    checks.update({"real_device_acceptance": "OWNER_ACCEPTANCE_REQUIRED",
                   "production_branch_rules_acceptance": "OWNER_ACCEPTANCE_REQUIRED",
                   "preparation_effect_on_existing_ap_use": "OWNER_ACCEPTANCE_REQUIRED",
                   "exact_head_ci": "UNKNOWN", "old_new_writer_coexistence": "UNKNOWN",
                   "recovery_readiness": "LIVE_VERIFICATION_REQUIRED", "worker_cutover": "LIVE_VERIFICATION_REQUIRED"})
    out = {"result": "BLOCKING_SHARED_REMAINING", "checks": checks,
           "release_authorized": False, "production_modified": False, "source_adopted": False,
           "scope": "PINNED_SOURCE_AND_OPTIONAL_GITHUB_ONLY", "ci_check_count": 0}
    if not SHA.fullmatch(sha) or not SHA.fullmatch(baseline_sha):
        checks["source_integrity"] = "FAIL"
        out["result"] = "FAIL"
        return out
    out.update(source_sha=sha, historical_baseline_sha=baseline_sha)
    ci_sha = sha if ci_sha is None else ci_sha
    try:
        main = git(repo, "rev-parse", "--verify", "refs/remotes/origin/main^{commit}")
        git(repo, "cat-file", "-e", sha + "^{commit}")
        checks["source_integrity"] = "PASS" if ancestor(repo, sha, main) and ancestor(repo, baseline_sha, sha) else "FAIL"
        def inventory(ref):
            rows = git(repo, "ls-tree", "-r", ref, "--", "database/migrations").splitlines()
            result = {}
            for row in rows:
                meta, path = row.split("\t", 1)
                mode, kind, blob = meta.split()
                if mode != "100644" or kind != "blob" or not SHA.fullmatch(blob) or not path.endswith(".php"):
                    raise EvidenceUnavailable()
                result[path] = blob
            return result
        old, new = inventory(baseline_sha), inventory(sha)
        preserved = all(new.get(p) == blob for p, blob in old.items())
        out["migration_inventory"] = {"historical_count": len(old), "candidate_count": len(new),
                                      "added_count": len(new.keys() - old.keys()), "old_blobs_preserved": preserved}
        checks["migration_inventory_source_only"] = "PASS" if len(old) == 94 and len(new) == 114 and preserved else "FAIL"
        out["changed_path_count"] = len(git(repo, "diff", "--name-only", "--no-renames", baseline_sha, sha, "--").splitlines())
        checks["historical_live_not_current_attestation"] = "LIVE_VERIFICATION_REQUIRED"
        # No dynamic verification is inferred from finding source files.
        shared = git(repo, "show", sha + ":.github/workflows/prelaunch-shared-preparation.yml")
        checks["worker_stop_regression_in_ci_source"] = "PASS" if "PreparationWorkerStopTest.php" in shared else "UNKNOWN"
        if not SHA.fullmatch(ci_sha):
            raise EvidenceUnavailable()
        tree_match = git(repo, "rev-parse", sha + "^{tree}") == git(repo, "rev-parse", ci_sha + "^{tree}")
        checks["ci_tree_binding"] = "PASS" if tree_match else "FAIL"
        out["ci_head_sha"] = ci_sha
        if get is not None and tree_match:
            evidence = preparation_github(ci_sha, get)
            checks.update(evidence["checks"])
            out["ci_check_count"] = evidence["ci_check_count"]
            out["evidence_origin"] = evidence["evidence_origin"]
    except (EvidenceUnavailable, ValueError, TypeError):
        checks["source_evidence"] = "FAIL"
    if "FAIL" in checks.values():
        out["result"] = "FAIL"
    return out


CUTOVER_ORDER = (
    "SOURCE_AND_CONFIG", "RECOVERY_PRECONDITIONS", "OLD_WEB_STOPPED", "CONSUMERS_STOPPED",
    "IN_FLIGHT_DRAINED", "WRITERS_ABSENT", "SCHEMA_PRECONDITIONS", "MIGRATION_ONCE",
    "RUNTIMES_ALIGNED", "LIMITED_WEB_STARTED", "ACCEPTANCE", "SAFE_SHUTDOWN",
)


def cutover_order(events):
    """Pure plan-order check, NEVER verification of facts or execution permission."""
    position, stopped = 0, False
    if not isinstance(events, (list, tuple)) or len(events) > 32:
        return {"state": "BLOCKING", "release_authorized": False}
    for event in events:
        if event == "STOP" and not stopped:
            stopped = True
            continue
        if stopped or position >= len(CUTOVER_ORDER) or event != CUTOVER_ORDER[position]:
            return {"state": "BLOCKING", "release_authorized": False}
        position += 1
    return {"state": "BLOCKING" if stopped else "VERIFIED_SOURCE" if position == len(CUTOVER_ORDER) else "UNKNOWN",
            "scope": "PLAN_ORDER_ONLY_NOT_OBSERVATION_OR_APPROVAL", "release_authorized": False}


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--repo", type=Path, default=Path("."), help="Local full-history checkout with origin refs")
    parser.add_argument("--release-sha", required=True, help="40-character immutable release commit")
    parser.add_argument("--live-sha", required=True, help="Operator-observed Render Live SHA (not independently verified)")
    parser.add_argument("--preparation", action="store_true", help="Pinned-source preparation report; never release GO")
    parser.add_argument("--ci-head", help="Preparation only: tested commit with exactly the candidate tree (e.g. squash PR HEAD)")
    parser.add_argument("--github-readonly", action="store_true", help="Preparation only: bounded GitHub GET metadata through existing gh login")
    parser.add_argument("--release-branch", help="release/* branch without refs/remotes/origin prefix")
    args = parser.parse_args()
    if (args.github_readonly or args.ci_head) and not args.preparation:
        parser.error("GitHub metadata is available only for the preparation report")
    if args.preparation:
        get = None
        if args.github_readonly:
            from platform_ci_runtime_report import api
            get = api
        result = preparation_evaluate(args.repo, args.release_sha, args.live_sha, get, args.ci_head)
    else:
        if not args.release_branch:
            parser.error("--release-branch is required for the original release topology check")
        result = evaluate(args.repo, args.release_sha, args.live_sha, args.release_branch)
    print(json.dumps(result, ensure_ascii=True, sort_keys=True))
    return 0 if result["result"] == "REVIEW_REQUIRED" else 2


if __name__ == "__main__":
    sys.exit(main())
