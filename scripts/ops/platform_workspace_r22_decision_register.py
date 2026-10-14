#!/usr/bin/env python3
"""R22 offline, fail-closed repeated-regression evidence decision register.

R17 source-context inventory is actual current-workflow data.
R18–R21 are historic *documented* disposable experiments, not fresh CI proofs.
No removal, regrouping or production authorization can be produced here.
"""
from __future__ import annotations

import json
from pathlib import Path
import sys
from typing import Callable

try:
    from .platform_workspace_context_intent_matrix import (
        EXPECTED_BASELINE, WORKFLOW, IncompleteEvidence, audit,
    )
    from .platform_workspace_test_inventory import ROOT
except ImportError:
    from platform_workspace_context_intent_matrix import (
        EXPECTED_BASELINE, WORKFLOW, IncompleteEvidence, audit,
    )
    from platform_workspace_test_inventory import ROOT

TARGET = "tests/Feature/PlanActionDraftV5871Test.php"
CREATIVE_TARGET = "tests/Feature/DevelopmentCreativePlanRescueV5863Test.php"
EVIDENCE_DOCS = (
    ("R18", "PLATFORM_WORKSPACE_R18_OMISSION_SHADOW_2026_10_15.md",
     ("PR #660", "27 | 23 | 27", "no removal is approved")),
    ("R19", "PLATFORM_WORKSPACE_R19_NEGATIVE_CONTROLS_2026_10_15.md",
     ("PR #663", "20/20", "HOLD")),
    ("R20", "PLATFORM_WORKSPACE_R20_APP_MUTATION_2026_10_15.md",
     ("PR #666", "cross_plan_worklog_source_scope", "dismiss_owner_authorization",
      "HOLD")),
    ("R21", "PLATFORM_WORKSPACE_R21_FIVE_CONTEXT_MATRIX_2026_10_15.md",
     ("PR #670", "10/10", "0/10", "not an actual modified 72-step")),
    ("R23", "PLATFORM_WORKSPACE_R23_CREATIVE_RESCUE_SHADOW_2026_10_15.md",
     ("PR #676", "14/14 independent PHP", "0/4", "HOLD")),
)
CREATIVE_CONTEXTS = (
    "V58.63 Legacy Creative Development Rescue",
    "V58.69 Team Task Dependencies",
)
TARGET_CONTEXTS = (
    "V58.75 Draft Quality and Staleness Guard",
    "V58.74 Owner-approved Sequential Action Draft",
    "V58.73 Typed Evidence and Domain-aware Plan Draft",
    "V58.72 Multi-evidence Draft and Revision Diff",
    "V58.71 Action-grounded Draft and Owner Approval",
)
GATES = (
    "DOMAIN_OWNER_ACCEPT_EACH_NAMED_REGRESSION_INTENT",
    "ASSERTION_SIDE_EFFECT_AND_SECURITY_BOUNDARY_EQUIVALENCE",
    "FULL_SEQUENTIAL_CI_STATE_ISOLATION_AND_ORDER_EVIDENCE",
    "NEGATIVE_APP_MUTATIONS_AND_CROSS_CONTEXT_GENERALITY",
    "EXACT_SHA_CI_AND_RUNTIME_REPRODUCIBILITY",
    "MEASURED_NET_RUNNER_SAVINGS_VS_MAINTENANCE_COST",
    "SEPARATE_REVIEWED_APPROVAL_TO_CHANGE_NORMAL_CI",
)

MAX_DOC_BYTES = 50_000


def read_doc(name: str) -> str:
    if not name.startswith("PLATFORM_WORKSPACE_R") or not name.endswith(".md"):
        raise IncompleteEvidence("invalid historical source-document name")
    path = ROOT / "docs/development" / name
    if (path.is_symlink() or not path.is_file()
            or not 0 < path.stat().st_size <= MAX_DOC_BYTES):
        raise IncompleteEvidence("missing/unsafe historical experiment document")
    return path.read_text(encoding="utf-8")


def verify_docs(reader: Callable[[str], str] = read_doc) -> list[dict]:
    sources = []
    for stage, filename, markers in EVIDENCE_DOCS:
        text = reader(filename)
        if not isinstance(text, str) or not text.startswith("# Platform R") or len(text) > MAX_DOC_BYTES:
            raise IncompleteEvidence("missing/invalid experiment source")
        if any(text.count(marker) < 1 for marker in markers):
            raise IncompleteEvidence("experiment document changed or evidence markers missing")
        sources.append({
            "stage": stage,
            "source_document": "docs/development/" + filename,
            "evidence_level": "HISTORICAL_DOCUMENTED_DISPOSABLE_CI_RESULT_ONLY",
            "current_sha_experiment_replay": False,
            "reviewed_for_test_removal": False,
        })
    if len(sources) != 5:
        raise IncompleteEvidence("incomplete R18-R23 document provenance")
    return sources


def build_register(workflow: str, read_source=None, read_document=read_doc) -> dict:
    # Reuse the authoritative read-only R17 source inventory and its same exact
    # Laravel test-file scanner. Not a new interpretation of YAML.
    matrix = audit(workflow, read_source=read_source) if read_source else audit(workflow)
    values = tuple(matrix[field] for field in (
        "artisan_test_process_invocations", "explicit_test_file_invocations",
        "unique_test_file_paths", "repeated_file_paths",
        "additional_repeated_file_invocations",
    ))
    if values != EXPECTED_BASELINE or matrix["repeated_file_occurrences"] != 63:
        raise IncompleteEvidence("R17 exact workflow inventory drift")
    if matrix["automatically_approved_removal_candidates"] != 0 or matrix["permission_to_remove_regression_invocations"]:
        raise IncompleteEvidence("R17 removal boundary altered")
    docs = verify_docs(read_document)
    context_rows = matrix["contexts"]
    paths = [item["test_file"] for item in context_rows]
    if (len(paths) != len(set(paths)) or paths.count(TARGET) != 1
            or paths.count(CREATIVE_TARGET) != 1):
        raise IncompleteEvidence("repeated test-file census drift")
    target_item = next(item for item in context_rows if item["test_file"] == TARGET)
    target_titles = tuple(row["declared_regression_step"] for row in target_item["occurrences"])
    if (target_item["invocations"] != 5
            or target_item["additional_invocations"] != 4
            or set(target_titles) != set(TARGET_CONTEXTS)
            or target_item["distinct_companion_sets"] != 5):
        raise IncompleteEvidence("R21 directly studied contexts changed")
    creative_item = next(item for item in context_rows if item["test_file"] == CREATIVE_TARGET)
    creative_titles = tuple(row["declared_regression_step"] for row in creative_item["occurrences"])
    if (creative_item["invocations"] != 2
            or creative_item["additional_invocations"] != 1
            or set(creative_titles) != set(CREATIVE_CONTEXTS)
            or creative_item["distinct_companion_sets"] != 2):
        raise IncompleteEvidence("R23 creative rescue contexts changed")
    rows = []
    for record in context_rows:
        plan_studied = record["test_file"] == TARGET
        creative_studied = record["test_file"] == CREATIVE_TARGET
        studied = plan_studied or creative_studied
        evidence = ["R17_STATIC_NAMED_GROUP_AND_COMPANIONS"]
        if plan_studied:
            evidence += ["R18_ONE_GROUP_JUNIT_OMISSION_DOCUMENTED",
                         "R19_TEST_METHOD_TRIPWIRES_DOCUMENTED",
                         "R20_TWO_APP_MUTANTS_DOCUMENTED",
                         "R21_FIVE_GROUP_LEAVE_ONE_OUT_DOCUMENTED"]
        elif creative_studied:
            evidence += ["R23_CREATIVE_RESCUE_TWO_GROUP_SHADOW_AND_APP_MUTANTS_DOCUMENTED"]
        occurrences = []
        for step in record["occurrences"]:
            verified_scope = (
                (plan_studied and step["declared_regression_step"] in TARGET_CONTEXTS)
                or (creative_studied and step["declared_regression_step"] in CREATIVE_CONTEXTS)
            )
            if studied and not verified_scope:
                raise IncompleteEvidence("unexpected studied regression context")
            occurrences.append({
                "command_ordinal": step["command_ordinal"],
                "named_regression_step": step["declared_regression_step"],
                "ordered_command_sha256": step["ordered_command_sha256"],
                "companion_test_files": step["companion_test_files"],
                "historical_two_mutant_probe_documented": verified_scope,
                "historical_mutant_scope": (
                    "R20_R21_PLAN_DRAFT" if plan_studied
                    else "R23_CREATIVE_PLAN" if creative_studied else None
                ),
                "other_companion_files_alone_detected_these_two_mutants":
                    False if verified_scope else None,
                "step_specific_intent_accepted_by_domain_owner": False,
                "state_boundary_equivalence_proven": False,
                "remove_this_occurrence": False,
            })
        rows.append({
            "test_file": record["test_file"],
            "original_invocations": record["invocations"],
            "additional_invocations": record["additional_invocations"],
            "distinct_companion_sets": record["distinct_companion_sets"],
            "static_source_signals": record["source_signals"],
            "evidence_level": (
                "SCOPED_HISTORICAL_MUTATION_OBSERVATIONS_HOLD" if plan_studied
                else "SCOPED_HISTORICAL_CREATIVE_MUTATION_OBSERVATIONS_HOLD" if creative_studied
                else "CURRENT_STATIC_CONTEXT_INVENTORY_ONLY_HOLD"
            ),
            "historical_evidence_tags": evidence,
            "historical_experiment_context_count": 5 if plan_studied else 2 if creative_studied else 0,
            "unreviewed_security_impact": not studied,
            "manual_review_order": (
                "PRIORITIZE_KNOWN_AUTH_AND_PLAN_BOUNDARIES" if studied
                else "NEEDS_DOMAIN_TRIAGE_UNRANKED"
            ),
            "missing_decision_gates": list(GATES),
            "occurrences": occurrences,
            "safe_to_remove": False,
        })
    total_instances = sum(x["original_invocations"] for x in rows)
    total_extra = sum(x["additional_invocations"] for x in rows)
    scoped = sum(x["historical_experiment_context_count"] for x in rows)
    if (len(rows), total_instances, total_extra, scoped) != (21, 63, 42, 7):
        raise IncompleteEvidence("decision register does not account for all repetitions")
    return {
        "result": "HOLD_MANUAL_CONTEXT_DECISION_REQUIRED",
        "current_manifest_fingerprint_sha256": matrix["workflow_invocation_fingerprint_sha256"],
        "source": "CURRENT_NORMAL_WORKSPACE_CI_PLUS_DOCUMENTED_HISTORICAL_EXPERIMENTS",
        "artisan_test_process_invocations": values[0],
        "explicit_test_file_invocations": values[1],
        "unique_test_file_paths": values[2],
        "repeated_test_files": len(rows),
        "additional_repeated_invocations": total_extra,
        "all_repeated_file_occurrences": total_instances,
        "historically_experimented_file_count": 2,
        "historically_experimented_file_occurrences": scoped,
        "static_only_file_count": 19,
        "static_only_file_occurrences": total_instances - scoped,
        "historical_experiments_revalidated_at_current_main_sha": False,
        "historical_sources": docs,
        "decision_gates": list(GATES),
        "automatically_approved_removal_candidates": 0,
        "permission_to_remove_repeated_invocations": False,
        "permission_to_regroup_or_reorder": False,
        "complete_72_step_omission_equivalence_proven": False,
        "production_release_authorized": False,
        "contexts": rows,
    }


def main() -> int:
    try:
        if WORKFLOW.is_symlink() or not WORKFLOW.is_file() or WORKFLOW.stat().st_size > 500_000:
            raise IncompleteEvidence("invalid normal CI manifest")
        outcome = build_register(WORKFLOW.read_text(encoding="utf-8"))
    except (IncompleteEvidence, OSError, TypeError, ValueError, UnicodeError, KeyError):
        outcome = {
            "result": "BLOCK",
            "code": "R22_MISSING_OR_STALE_DECISION_EVIDENCE",
            "automatically_approved_removal_candidates": 0,
            "permission_to_remove_repeated_invocations": False,
            "production_release_authorized": False,
        }
    print(json.dumps(outcome, ensure_ascii=True, sort_keys=True))
    return 0 if outcome["result"] == "HOLD_MANUAL_CONTEXT_DECISION_REQUIRED" else 2


if __name__ == "__main__":
    sys.exit(main())
