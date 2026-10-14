#!/usr/bin/env python3
"""G3 R29: offline request-package validation, never actual provider approval."""
from __future__ import annotations

import argparse
import json
from pathlib import Path
import sys

sys.path.insert(0, str(Path(__file__).resolve().parents[2]))
from scripts.ops.g3_provider_evidence_inventory import (
    SHA, InvalidInventory, unique_pairs,
)
from scripts.ops.g3_provider_collection_plan import evaluate as review_plan

SCHEMA = "canovia.g3.disposable_approval_packet.v1"
ACTIONS = (
    "inspect_disposable_bucket_settings",
    "put_disposable_synthetic_object",
    "head_disposable_synthetic_object",
    "test_disposable_worker_restart",
    "test_generation_conditional_delete_on_disposable_object",
)
STOPS = (
    "unverified_provider_identity",
    "missing_owner_authorization",
    "crossed_budget_or_time_limit",
    "unexpected_account_bucket_or_principal",
    "unverified_versioning_or_conditional_semantics",
    "writer_fence_or_generation_mismatch",
    "credential_exposure_or_missing_recovery_evidence",
)
STOP_RESPONSES = (
    "revoke_temporary_principal",
    "stop_all_candidate_actions",
    "quarantine_disposable_objects_without_blind_delete",
    "preserve_restricted_original_evidence",
    "return_for_manual_security_and_owner_review",
)


def template(sha: str, plan: dict) -> dict:
    """Proposed maximums are placeholders, NOT owner-approved spending."""
    return {
        "schema": SCHEMA,
        "candidate_sha": sha,
        "collection_plan": plan,
        "approval_state": "not_requested",
        "authorization": "not_granted",
        "environment": "new_isolated_disposable_account_only",
        "account_selector": "owner_must_specify_securely",
        "bucket_selector": "new_throwaway_bucket_no_user_objects",
        "principal": "temporary_scoped_test_identity",
        "region_selector": "owner_must_specify_securely",
        "requested_actions": list(ACTIONS),
        "conditional_delete_phase": "requires_distinct_owner_and_security_go",
        "proposed_limits": {
            "currency": "USD",
            "max_spend_usd": 10,
            "max_duration_minutes": 60,
            "max_test_objects": 5,
            "max_provider_requests": 100,
        },
        "credential_storage": "owner_controlled_vault_never_repo",
        "raw_evidence_custody": "restricted_owner_controlled_vault",
        "published_evidence": "redacted_reviewed_summary_only",
        "test_data": "synthetic_disposable_only",
        "stop_conditions": list(STOPS),
        "abort_and_recovery": list(STOP_RESPONSES),
        "owner_identity_verification": "pending_out_of_band",
        "security_review": "pending_out_of_band",
        "release_permission": "not_in_scope",
        "production_migration_permission": "not_in_scope",
    }


def response(state: str) -> dict:
    return {
        "result": state,
        "scope_proposed_only": True,
        "owner_approval_verified": False,
        "security_review_verified": False,
        "provider_access_authorized": False,
        "real_provider_test_executed": False,
        "budget_authorized": False,
        "conditional_delete_authorized": False,
        "production_migration_authorized": False,
        "release_authorized": False,
        "cleanup_authorized": False,
        "production_modified": False,
    }


def evaluate(repo: Path, packet: object) -> dict:
    if type(packet) is not dict or set(packet) != set(template("", {})):
        return response("INVALID")
    sha = packet["candidate_sha"]
    if type(sha) is not str or SHA.fullmatch(sha) is None:
        return response("INVALID")
    expected = template(sha, packet["collection_plan"])
    for key, required in expected.items():
        if key == "proposed_limits" or key == "collection_plan":
            continue
        if type(packet[key]) is not type(required) or packet[key] != required:
            return response("INVALID")
    limits = packet["proposed_limits"]
    if type(limits) is not dict or set(limits) != set(expected["proposed_limits"]):
        return response("INVALID")
    if limits["currency"] != "USD":
        return response("INVALID")
    bounds = {
        "max_spend_usd": 25,
        "max_duration_minutes": 120,
        "max_test_objects": 10,
        "max_provider_requests": 200,
    }
    for key, bound in bounds.items():
        if type(limits[key]) is not int or not 1 <= limits[key] <= bound:
            return response("INVALID")

    plan = packet["collection_plan"]
    if type(plan) is not dict or plan.get("candidate_sha") != sha:
        return response("INVALID")
    reviewed = review_plan(repo, plan)
    if reviewed["result"] == "INVALID":
        return response("INVALID")
    if reviewed["result"] != "PLAN_REVIEW_ONLY":
        return response("BLOCK")
    # Even a complete request package has no verified owner approval,
    # credentials, IAM, authorized budget or permission to contact S3.
    return response("OWNER_DECISION_REQUIRED")


def load_packet(path: Path) -> object:
    try:
        if path.stat().st_size > 65536:
            raise InvalidInventory()
        with path.open(encoding="utf-8") as file:
            return json.load(file, object_pairs_hook=unique_pairs)
    except (OSError, ValueError, UnicodeError, InvalidInventory) as exc:
        raise InvalidInventory() from exc


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--repo", type=Path, default=Path("."))
    parser.add_argument("--packet", type=Path, required=True)
    args = parser.parse_args()
    try:
        verdict = evaluate(args.repo, load_packet(args.packet))
    except InvalidInventory:
        verdict = response("INVALID")
    print(json.dumps(verdict, sort_keys=True))
    return 2


if __name__ == "__main__":
    sys.exit(main())
