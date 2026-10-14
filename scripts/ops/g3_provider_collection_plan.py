#!/usr/bin/env python3
"""G3 R28: offline, fail-closed collection PLAN review; never a collection tool."""
from __future__ import annotations

import argparse
import json
from pathlib import Path
import sys

sys.path.insert(0, str(Path(__file__).resolve().parents[2]))
from scripts.ops.g3_provider_evidence_inventory import (
    REQUIREMENTS, SHA, InvalidInventory, git, unique_pairs,
)

SCHEMA = "canovia.g3.provider_collection_plan.v1"
# Fixed acquisition and negative-test requirements, not caller-attested facts.
COLLECTION = {
    "provider_identity_and_trust_root": ("isolated_disposable_provider", "provider_identity_review", ("unknown_root", "rotated_root")),
    "response_authenticity": ("isolated_disposable_provider", "write_response_trace", ("spoofed_header", "substituted_response")),
    "request_and_generation_binding": ("isolated_disposable_provider", "attempt_generation_trace", ("mismatched_attempt", "changed_version")),
    "anti_replay_continuity": ("isolated_disposable_provider", "persistent_replay_trace", ("duplicate_nonce", "reordered_attempt")),
    "bucket_versioning_and_iam": ("isolated_disposable_provider", "bucket_iam_review", ("suspended_versioning", "reduced_permissions")),
    "external_writer_fencing": ("isolated_disposable_provider", "writer_race_trace", ("unfenced_writer", "aba_reappearance")),
    "generation_conditional_operations": ("isolated_disposable_provider", "generation_precondition_trace", ("stale_generation", "unsupported_condition")),
    "crash_restart_recovery_test": ("isolated_disposable_process", "restart_fault_matrix", ("after_db_commit", "before_write_ack")),
    "cross_database_cleanup_reconciliation": ("isolated_disposable_process", "outbox_recovery_trace", ("outbox_interruption", "quarantine_boundary")),
    "key_rotation_and_revocation_test": ("isolated_disposable_process", "key_rotation_matrix", ("revoked_key", "changed_epoch")),
    "owner_go_and_rollout_plan": ("offline_owner_review", "release_owner_gate", ("missing_owner_approval", "stale_release_sha")),
}


def template(candidate_sha: str) -> dict:
    """Plan-shaped UNAPPROVED fixture. Does not initiate provider calls."""
    return {
        "schema": SCHEMA,
        "candidate_sha": candidate_sha,
        "environment": "disposable_only",
        "execution_mode": "offline_plan_only",
        "authorization": "not_granted",
        "requirements": [
            {
                "id": key, "owner_role": role,
                "collection_target": COLLECTION[key][0],
                "source_class": COLLECTION[key][1],
                "negative_cases": list(COLLECTION[key][2]),
                "original_custody": "restricted_external_only",
                "summary_custody": "redacted_git_candidate",
                "capture_state": "not_started",
                "review_state": "unverified",
                "separate_owner_gate": "required",
            }
            for key, role in REQUIREMENTS.items()
        ],
    }


def report(result: str) -> dict:
    return {
        "result": result,
        "coverage": {key: "NOT_COLLECTED" for key in REQUIREMENTS},
        "real_provider_authorized": False,
        "collection_performed": False,
        "independent_evidence_verified": False,
        "provider_authenticated": False,
        "release_authorized": False,
        "retry_authorized": False,
        "delete_authorized": False,
        "production_modified": False,
    }


def evaluate(repo: Path, plan: object) -> dict:
    if type(plan) is not dict or set(plan) != {
        "schema", "candidate_sha", "environment", "execution_mode",
        "authorization", "requirements",
    }:
        return report("INVALID")
    sha = plan["candidate_sha"]
    if (plan["schema"] != SCHEMA or type(sha) is not str
            or not SHA.fullmatch(sha)
            or plan["environment"] != "disposable_only"
            or plan["execution_mode"] != "offline_plan_only"
            or plan["authorization"] != "not_granted"
            or type(plan["requirements"]) is not list
            or len(plan["requirements"]) != len(REQUIREMENTS)):
        return report("INVALID")

    expected = template(sha)["requirements"]
    seen = set()
    for item in plan["requirements"]:
        if type(item) is not dict or set(item) != set(expected[0]):
            return report("INVALID")
        key = item["id"]
        if type(key) is not str or key not in REQUIREMENTS or key in seen:
            return report("INVALID")
        seen.add(key)
        target = next(entry for entry in expected if entry["id"] == key)
        if (any(type(item[field]) is not type(target[field])
                or item[field] != target[field] for field in target)):
            return report("INVALID")
    if seen != set(REQUIREMENTS):
        return report("INVALID")
    try:
        if git(repo, "cat-file", "-t", sha).strip() != b"commit":
            return report("BLOCK")
    except InvalidInventory:
        return report("BLOCK")
    # A complete plan is NOT approval to access even disposable S3.
    return report("PLAN_REVIEW_ONLY")


def load_plan(path: Path) -> object:
    try:
        if path.stat().st_size > 65536:
            raise InvalidInventory()
        with path.open(encoding="utf-8") as file:
            return json.load(file, object_pairs_hook=unique_pairs)
    except (ValueError, UnicodeError, OSError, InvalidInventory) as exc:
        raise InvalidInventory() from exc


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--repo", type=Path, default=Path("."))
    parser.add_argument("--plan", type=Path, required=True)
    args = parser.parse_args()
    try:
        result = evaluate(args.repo, load_plan(args.plan))
    except InvalidInventory:
        result = report("INVALID")
    print(json.dumps(result, ensure_ascii=True, sort_keys=True))
    # Never return an OS exit status that resembles release approval.
    return 2


if __name__ == "__main__":
    sys.exit(main())
