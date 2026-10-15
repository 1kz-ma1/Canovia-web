#!/usr/bin/env python3
"""Read-only, exact-Git-SHA AP MCP pilot release inventory.

This never contacts Render/Auth0/Aiven, reads live user data, creates a
release candidate or gives authorization to deploy. HOLD is expected.
"""
from __future__ import annotations

import argparse
import json
from pathlib import Path
import re
import subprocess
import sys

# Snapshot observed on 2026-10-15 JST, not a deploy target or moving main.
RECORDED_LIVE_SHA = "15abdc9de6f24b5250fb255c579d1aa55facaf1a"
RECORDED_DEVELOPMENT_SHA = "e81e2ac4a82aa37012aa7f716ad3a60543b5a489"
SHA = re.compile(r"[0-9a-f]{40}\Z")
MIGRATION_PREFIX = "database/migrations/"
REQUIRED_AP_FILES = (
    "app/Services/McpStudyPilotAccessPolicy.php",
    "app/Services/McpReadOnlyStudyContextTool.php",
    "app/Services/McpReadOnlyStudyPracticeSummaryTool.php",
    "app/Services/McpStudyResultProposalService.php",
    "app/Services/McpAuth0JwtTokenVerifier.php",
    "app/Http/Controllers/McpStudyResultDecisionController.php",
    "database/migrations/2026_10_15_070000_create_mcp_study_result_proposals_table.php",
)
MIGRATION_REVIEW_PATTERNS = {
    "TRIGGER_DDL": re.compile(r"CREATE\s+(?:OR\s+REPLACE\s+)?TRIGGER|CREATE\s+OR\s+REPLACE\s+FUNCTION", re.I),
    "RAW_SQL": re.compile(r"DB::(?:unprepared|statement)\s*\(", re.I),
    "EXISTING_ROWS_UPDATE": re.compile(r"DB::table\s*\([\s\S]{0,240}?->update\s*\(", re.I),
    "DESTRUCTIVE_DDL": re.compile(r"(?:dropUnique|dropColumn|dropIndex|dropTable|dropIfExists)\s*\(", re.I),
}
MANUAL_GATES = (
    "operator_rechecks_actual_render_live_sha",
    "review_all_changed_files_and_runtime_dependency_closure",
    "pinned_source_and_per_migration_mysql_8_0_8_4_rehearsal",
    "real_aiven_read_only_ledger_and_physical_schema_audit",
    "fresh_backup_and_recovery_risk_acceptance",
    "production_env_startup_and_separate_mcp_auth0_review",
    "owner_selected_canovia_user_and_explicit_plan_consent",
    "github_production_rules_and_exact_sha_ci",
    "explicit_owner_release_and_manual_deploy_approval",
    "post_deploy_web_ios_and_deny_revoke_acceptance",
)


class EvidenceUnavailable(Exception):
    pass


def git(repo: Path, *args: str) -> bytes:
    try:
        command = subprocess.run(
            ["git", "-C", str(repo), *args],
            stdout=subprocess.PIPE,
            stderr=subprocess.DEVNULL,
            check=False,
            timeout=20,
        )
    except (OSError, subprocess.TimeoutExpired) as exc:
        raise EvidenceUnavailable from exc
    if command.returncode != 0:
        raise EvidenceUnavailable
    return command.stdout


def tree(repo: Path, sha: str) -> dict[str, str]:
    records = git(repo, "ls-tree", "-r", "-z", sha)
    blobs: dict[str, str] = {}
    for entry in records.split(b"\0"):
        if not entry:
            continue
        try:
            metadata, path = entry.split(b"\t", 1)
            _mode, obj_type, blob_sha = metadata.split(b" ", 2)
            if obj_type != b"blob":
                continue
            decoded = path.decode("utf-8", "strict")
            blobs[decoded] = blob_sha.decode("ascii")
        except (UnicodeError, ValueError) as exc:
            raise EvidenceUnavailable from exc
    if not blobs:
        raise EvidenceUnavailable
    return blobs


def blocked(code: str) -> dict:
    return {
        "status": "BLOCK",
        "reason": code,
        "release_authorized": False,
        "environment_mutated": False,
    }


def inspect(repo: Path, baseline: str, snapshot: str) -> dict:
    if not SHA.fullmatch(baseline) or not SHA.fullmatch(snapshot):
        return blocked("INVALID_SHA")
    if baseline == snapshot:
        return blocked("NO_SEPARATE_SNAPSHOT")
    try:
        if git(repo, "rev-parse", "--verify", baseline + "^{commit}").strip().decode("ascii") != baseline:
            return blocked("INVALID_BASELINE")
        if git(repo, "rev-parse", "--verify", snapshot + "^{commit}").strip().decode("ascii") != snapshot:
            return blocked("INVALID_SNAPSHOT")
        # git merge-base exits 1 when this does not hold (also fail closed).
        git(repo, "merge-base", "--is-ancestor", baseline, snapshot)
        before, after = tree(repo, baseline), tree(repo, snapshot)
        added = sorted(set(after) - set(before))
        deleted = sorted(set(before) - set(after))
        modified = sorted(p for p in set(before) & set(after) if before[p] != after[p])
        changed = sorted(set(added) | set(deleted) | set(modified))
        if not changed:
            return blocked("EMPTY_DIFF")
        missing = sorted(set(REQUIRED_AP_FILES) - set(after))
        if missing:
            return blocked("MISSING_AP_SURFACES")
        ap_fence = git(repo, "show", snapshot + ":app/Services/McpStudyPilotAccessPolicy.php")
        if b"allowsOwnerId" not in ap_fence or b"'staging'" not in ap_fence:
            return blocked("PILOT_FENCE_NOT_OBSERVED")
        migrations = sorted(p for p in changed if p.startswith(MIGRATION_PREFIX))
        risk: dict[str, list[str]] = {}
        for path in migrations:
            if path not in after:
                risk[path] = ["REMOVED_MIGRATION"]
                continue
            source = git(repo, "show", snapshot + ":" + path).decode("utf-8", "strict")
            risk[path] = [name for name, pattern in MIGRATION_REVIEW_PATTERNS.items()
                          if pattern.search(source)]
        hotspots = sorted(
            p for p in changed
            if p.startswith(("app/Http/Controllers/Mcp", "app/Services/Mcp",
                             "app/Models/Mcp", "config/", "routes/",
                             "bootstrap/", ".github/workflows/"))
            or p in ("Dockerfile", "composer.json", "composer.lock", "docker/render-start.sh")
        )
        return {
            "status": "HOLD",
            "source_evidence": "LOCAL_PINNED_GIT_ONLY",
            "baseline_sha": baseline,
            "snapshot_sha": snapshot,
            "file_change_counts": {
                "added": len(added), "modified": len(modified),
                "deleted": len(deleted), "total": len(changed),
            },
            "added_migrations": [p for p in added if p.startswith(MIGRATION_PREFIX)],
            "modified_migrations": [p for p in modified if p.startswith(MIGRATION_PREFIX)],
            "removed_migrations": [p for p in deleted if p.startswith(MIGRATION_PREFIX)],
            "migration_review_markers_not_security_proof": risk,
            "runtime_review_hotspots": hotspots,
            "all_changed_paths": changed,
            "manual_gates": {gate: "NOT_VERIFIED" for gate in MANUAL_GATES},
            "release_authorized": False,
            "environment_mutated": False,
            "note": "HOLD is an inventory success, never permission to promote, configure or deploy.",
        }
    except (EvidenceUnavailable, UnicodeError, OSError):
        return blocked("GIT_EVIDENCE_UNAVAILABLE")


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--repo", type=Path, default=Path("."))
    parser.add_argument("--baseline", default=RECORDED_LIVE_SHA)
    parser.add_argument("--snapshot", default=RECORDED_DEVELOPMENT_SHA)
    parser.add_argument("--output", type=Path, help="Optional local JSON report; never a deployment approval")
    args = parser.parse_args()
    result = inspect(args.repo, args.baseline, args.snapshot)
    if args.output:
        args.output.parent.mkdir(parents=True, exist_ok=True)
        args.output.write_text(json.dumps(result, sort_keys=True, indent=2) + "\n", encoding="utf-8")
    summary = {
        "status": result["status"],
        "reason": result.get("reason"),
        "baseline_sha": result.get("baseline_sha"),
        "snapshot_sha": result.get("snapshot_sha"),
        "counts": result.get("file_change_counts"),
        "new_migrations": len(result.get("added_migrations", [])),
        "release_authorized": False,
    }
    print(json.dumps(summary, sort_keys=True))
    return 0 if result["status"] == "HOLD" else 2


if __name__ == "__main__":
    sys.exit(main())
