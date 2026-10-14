#!/usr/bin/env python3
"""R23 offline source-backed V5872 scoped review, never a deduplication approval."""
from __future__ import annotations
import json
import re
from pathlib import Path
import sys
try:
    from .platform_workspace_context_intent_matrix import audit, WORKFLOW, IncompleteEvidence, EXPECTED_BASELINE
    from .platform_workspace_test_inventory import ROOT
except ImportError:
    from platform_workspace_context_intent_matrix import audit, WORKFLOW, IncompleteEvidence, EXPECTED_BASELINE
    from platform_workspace_test_inventory import ROOT

TARGET = "tests/Feature/PlanActionDraftV5872Test.php"
METHODS = (
    "test_multiple_work_logs_create_sourced_proposal_without_modifying_existing_tasks",
    "test_cross_plan_evidence_and_invalid_selection_never_create_proposals",
    "test_diff_preview_is_read_only_and_explicit_application_creates_revision",
    "test_user_edits_and_work_log_changes_invalidate_stale_previews",
    "test_editor_viewer_and_unrelated_users_cannot_compose_or_refresh",
    "test_accepted_and_dismissed_drafts_cannot_be_regenerated",
)
SIGNALS = {
    "multiple_evidence_no_task_mutation": "test_multiple_work_logs_create_sourced_proposal_without_modifying_existing_tasks",
    "cross_plan_evidence_and_validation": "test_cross_plan_evidence_and_invalid_selection_never_create_proposals",
    "preview_readonly_revision_idempotency": "test_diff_preview_is_read_only_and_explicit_application_creates_revision",
    "stale_preview_rejection": "test_user_edits_and_work_log_changes_invalidate_stale_previews",
    "collaborator_authorization": "test_editor_viewer_and_unrelated_users_cannot_compose_or_refresh",
    "terminal_draft_state": "test_accepted_and_dismissed_drafts_cannot_be_regenerated",
}

def review(workflow: str, php: str) -> dict:
    r=audit(workflow)
    counts=tuple(r[x] for x in ("artisan_test_process_invocations","explicit_test_file_invocations","unique_test_file_paths","repeated_file_paths","additional_repeated_file_invocations"))
    if counts!=EXPECTED_BASELINE:
        raise IncompleteEvidence("normal test inventory drift")
    item=next((x for x in r["contexts"] if x["test_file"]==TARGET),None)
    if not item or item["invocations"]!=4 or item["distinct_companion_sets"]!=4:
        raise IncompleteEvidence("V5872 invocation or companion-context drift")
    if not isinstance(php,str) or len(php)>256000 or not php.startswith("<?php") or "use RefreshDatabase;" not in php:
        raise IncompleteEvidence("invalid test class or state signal")
    found=re.findall(r"^\s*public function (test_[a-zA-Z0-9_]+)\(\): void",php,re.M)
    if tuple(found)!=METHODS:
        raise IncompleteEvidence("six V5872 method identities drifted")
    contexts=[]
    for x in item["occurrences"]:
        if TARGET in x["companion_test_files"] or not x["companion_test_files"]:
            raise IncompleteEvidence("unsafe companion inventory")
        contexts.append({
            "ordinal":x["command_ordinal"],
            "step":x["declared_regression_step"],
            "companions":x["companion_test_files"],
            "ordered_command_sha256":x["ordered_command_sha256"],
            "step_specific_semantics_verified":False,
            "cross_context_db_state_equivalence_proven":False,
            "safe_to_omit":False,
        })
    return {
        "result":"HOLD_V5872_DOMAIN_REVIEW_REQUIRED",
        "test_file":TARGET,
        "current_manifest_fingerprint":r["workflow_invocation_fingerprint_sha256"],
        "invocations":len(contexts),
        "additional_invocations":len(contexts)-1,
        "source_observed_method_count":len(found),
        "source_observed_method_names":list(found),
        "source_observed_risk_areas":SIGNALS,
        "refresh_database_source_signal_only":True,
        "source_signal_is_runtime_isolation_proof":False,
        "contexts":contexts,
        "missing_evidence":[
            "DOMAIN_OWNER_ACCEPTS_INDIVIDUAL_REGRESSION_INTENT",
            "JUNIT_A_B_A_PRIME_AND_NEGATIVE_CONTROL_IN_DISPOSABLE_RUNNER",
            "APPLICATION_LEVEL_MUTATION_COVERAGE_FOR_V5872",
            "ASSERTION_AND_DB_SESSION_SIDE_EFFECT_EQUIVALENCE",
            "INDEPENDENT_RUNNER_AND_FULL_72_COMMAND_ORDER_REPLAY",
            "MEASURED_CI_COST_AND_SEPARATE_REMOVAL_APPROVAL",
        ],
        "safe_to_remove":False,
        "permission_to_remove_repeated_invocations":False,
        "production_release_authorized":False,
    }

def main():
    try:
        path=ROOT/TARGET
        if (WORKFLOW.is_symlink() or not WORKFLOW.is_file() or
            path.is_symlink() or not path.is_file() or path.stat().st_size>256000):
            raise IncompleteEvidence("unsafe source")
        result=review(WORKFLOW.read_text(encoding="utf-8"),path.read_text(encoding="utf-8"))
    except (IncompleteEvidence,OSError,UnicodeError,ValueError,TypeError,StopIteration):
        result={"result":"BLOCK","safe_to_remove":False,"permission_to_remove_repeated_invocations":False,"production_release_authorized":False}
    print(json.dumps(result,ensure_ascii=True,sort_keys=True))
    return 0 if result["result"]=="HOLD_V5872_DOMAIN_REVIEW_REQUIRED" else 2
if __name__=="__main__":
    sys.exit(main())
