#!/usr/bin/env python3
"""G3 R30: offline owner-checklist and hypothetical scope-drift gate.

This never verifies an owner identity or grants a capability. The caller
controls both proposal and snapshot. No SDK, cloud, DB, credentials or writes.
"""
from __future__ import annotations

import argparse
import hashlib
import json
from pathlib import Path
import sys

sys.path.insert(0, str(Path(__file__).resolve().parents[2]))
from scripts.ops.g3_provider_evidence_inventory import InvalidInventory, unique_pairs
from scripts.ops.g3_disposable_approval_packet import (
    evaluate as evaluate_r29, load_packet,
)

SCHEMA = "canovia.g3.owner_scope_review.v1"
CHECKPOINTS = {
    "owner_identity_out_of_band": "owner",
    "isolated_account_and_region": "owner",
    "new_bucket_no_user_data": "storage",
    "temporary_principal_least_privilege": "security",
    "budget_and_expiry": "owner",
    "operations_and_second_phase_fence": "storage",
    "secrets_and_evidence_custody": "security",
    "stop_and_quarantine_procedure": "platform",
    "generation_and_writer_safety": "storage",
    "independent_security_signoff": "security",
    "explicit_owner_go": "owner",
}
# Hypothetical cases only; conditional deletion is ALWAYS excluded.
ALLOWED = {
    "inspect_disposable_bucket_settings": ("isolated_disposable_bucket", 1, 0),
    "put_disposable_synthetic_object": ("isolated_disposable_bucket", 1, 1),
    "head_disposable_synthetic_object": ("isolated_disposable_bucket", 1, 0),
    "test_disposable_worker_restart": ("isolated_disposable_process", 0, 0),
}
INTENT_KEYS = {
    "sequence", "action", "target", "data_class", "operation_phase",
    "requests", "new_objects", "estimated_cost_cents", "elapsed_minutes",
}


def canonical_digest(packet: dict) -> str:
    payload = json.dumps(packet, ensure_ascii=True, sort_keys=True,
                         separators=(",", ":"), allow_nan=False).encode("ascii")
    return hashlib.sha256(payload).hexdigest()


def sample_review(packet: dict) -> dict:
    """Generate deliberately pending review and harmless simulated intents."""
    return {
        "schema": SCHEMA,
        "candidate_sha": packet["candidate_sha"],
        "packet_sha256": canonical_digest(packet),
        "review_type": "offline_hypothetical_only",
        "checklist": [
            {"id": key, "role": role, "state": "pending_out_of_band"}
            for key, role in CHECKPOINTS.items()
        ],
        "intents": [
            {"sequence": i, "action": action, "target": ALLOWED[action][0],
             "data_class": "disposable_synthetic_only",
             "operation_phase": "simulation_only",
             "requests": ALLOWED[action][1],
             "new_objects": ALLOWED[action][2],
             "estimated_cost_cents": 1,
             "elapsed_minutes": i}
            for i, action in enumerate(ALLOWED, 1)
        ],
        "owner_authorization": "not_granted",
        "security_attestation": "not_verified",
        "provider_access": "not_started",
    }


def result(state: str) -> dict:
    return {
        "result": state,
        "checklist": {key: "PENDING" for key in CHECKPOINTS},
        "packet_integrity_checked_locally": state == "OWNER_CHECKLIST_PENDING",
        "owner_identity_verified": False,
        "owner_approval_verified": False,
        "security_review_verified": False,
        "cloud_scope_enforced": False,
        "provider_access_authorized": False,
        "provider_requests_executed": False,
        "budget_authorized": False,
        "conditional_delete_authorized": False,
        "retry_authorized": False,
        "delete_authorized": False,
        "production_migration_authorized": False,
        "release_authorized": False,
        "production_modified": False,
    }


def evaluate(repo: Path, packet: object, review: object) -> dict:
    if type(packet) is not dict or type(review) is not dict:
        return result("INVALID")
    prior = evaluate_r29(repo, packet)
    if prior["result"] == "INVALID":
        return result("INVALID")
    if prior["result"] != "OWNER_DECISION_REQUIRED":
        return result("BLOCK")
    expected = sample_review(packet)
    if set(review) != set(expected):
        return result("INVALID")
    for key in ("schema", "candidate_sha", "packet_sha256", "review_type",
                "owner_authorization", "security_attestation", "provider_access"):
        if type(review[key]) is not str or review[key] != expected[key]:
            return result("INVALID")

    rows = review["checklist"]
    if type(rows) is not list or len(rows) != len(CHECKPOINTS):
        return result("INVALID")
    seen = set()
    for row in rows:
        if type(row) is not dict or set(row) != {"id", "role", "state"}:
            return result("INVALID")
        key = row["id"]
        if type(key) is not str or key not in CHECKPOINTS or key in seen:
            return result("INVALID")
        if (type(row["role"]) is not str or row["role"] != CHECKPOINTS[key]
                or row["state"] != "pending_out_of_band"):
            return result("INVALID")
        seen.add(key)
    if seen != set(CHECKPOINTS):
        return result("INVALID")

    intents = review["intents"]
    if type(intents) is not list or not 1 <= len(intents) <= 8:
        return result("INVALID")
    proposed = packet["proposed_limits"]
    total_requests, total_objects, total_cents, elapsed = 0, 0, 0, 0
    for pos, item in enumerate(intents, 1):
        if type(item) is not dict or set(item) != INTENT_KEYS:
            return result("INVALID")
        action = item["action"]
        if type(action) is not str or action not in ALLOWED:
            return result("BLOCK")
        target, requests, new_objects = ALLOWED[action]
        if (type(item["sequence"]) is not int or item["sequence"] != pos
                or item["target"] != target
                or item["data_class"] != "disposable_synthetic_only"
                or item["operation_phase"] != "simulation_only"
                or type(item["requests"]) is not int or item["requests"] != requests
                or type(item["new_objects"]) is not int or item["new_objects"] != new_objects
                or type(item["estimated_cost_cents"]) is not int
                or not 0 <= item["estimated_cost_cents"] <= 2500
                or type(item["elapsed_minutes"]) is not int
                or not 1 <= item["elapsed_minutes"] <= 120
                or item["elapsed_minutes"] < elapsed):
            return result("INVALID")
        elapsed = item["elapsed_minutes"]
        total_requests += requests
        total_objects += new_objects
        total_cents += item["estimated_cost_cents"]

    if (total_requests > proposed["max_provider_requests"]
            or total_objects > proposed["max_test_objects"]
            or total_cents > proposed["max_spend_usd"] * 100
            or elapsed > proposed["max_duration_minutes"]):
        return result("BLOCK")
    # Only syntactic, untrusted forecast comparisons. A later real
    # enforcement implementation needs authenticated meter/identity and
    # a trusted operation interception point, reviewed separately.
    return result("OWNER_CHECKLIST_PENDING")


def load_review(path: Path) -> object:
    try:
        if path.stat().st_size > 65536:
            raise InvalidInventory()
        with path.open(encoding="utf-8") as file:
            return json.load(file, object_pairs_hook=unique_pairs)
    except (OSError, UnicodeError, ValueError, InvalidInventory) as exc:
        raise InvalidInventory() from exc


def main() -> int:
    p = argparse.ArgumentParser(description=__doc__)
    p.add_argument("--repo", type=Path, default=Path("."))
    p.add_argument("--packet", type=Path, required=True)
    p.add_argument("--review", type=Path, required=True)
    args = p.parse_args()
    try:
        verdict = evaluate(args.repo, load_packet(args.packet),
                           load_review(args.review))
    except (InvalidInventory, TypeError, OverflowError):
        verdict = result("INVALID")
    print(json.dumps(verdict, sort_keys=True))
    return 2


if __name__ == "__main__":
    sys.exit(main())
