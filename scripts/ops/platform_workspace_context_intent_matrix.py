#!/usr/bin/env python3
"""R17 static context matrix for repeated Workspace Resume test-file invocations.

Workflow labels are declared intent, not behavioral proof. No command is run,
no test is removed, and no automated safe-to-deduplicate recommendation exists.
"""
from __future__ import annotations

from collections import defaultdict
import hashlib
import json
from pathlib import Path
import sys

try:
    from .platform_workspace_repeat_context import (
        COMMAND, PATH, STEP, WORKFLOW, IncompleteEvidence, analyze, read_php,
    )
except ImportError:
    from platform_workspace_repeat_context import (
        COMMAND, PATH, STEP, WORKFLOW, IncompleteEvidence, analyze, read_php,
    )

EXPECTED_BASELINE = (72, 160, 118, 21, 42)


def audit(workflow: str, read_source=read_php) -> dict:
    """Record every named invocation and its actual co-invoked test files."""
    source_report = analyze(workflow, read_source=read_source)
    commands = []
    step = ""
    for line in workflow.splitlines():
        if line.startswith("      - name: "):
            match = STEP.fullmatch(line)
            step = match.group(1) if match else ""
        if "run: php artisan test" not in line:
            continue
        match = COMMAND.fullmatch(line)
        if not step or not match:
            raise IncompleteEvidence("unsupported or unnamed test execution")
        files = tuple(match.group(1).split(" "))
        if not files or len(files) != len(set(files)) or any(
            not PATH.fullmatch(path) or ".." in path for path in files
        ):
            raise IncompleteEvidence("invalid or duplicate test path in command")
        commands.append((step, files))

    if len(commands) != source_report["artisan_test_process_invocations"]:
        raise IncompleteEvidence("command inventory changed during audit")

    by_file = defaultdict(list)
    for ordinal, (label, files) in enumerate(commands, start=1):
        for file in files:
            by_file[file].append((ordinal, label, files))

    entries = []
    for record in source_report["contexts"]:
        file = record["test_file"]
        occurrences = by_file[file]
        if (len(occurrences) != record["invocations"]
                or [label for _, label, _ in occurrences]
                != record["named_regression_contexts"]):
            raise IncompleteEvidence("mismatched named repeat contexts")
        companion_sets = []
        argvs = []
        case_entries = []
        for ordinal, label, files in occurrences:
            companions = tuple(path for path in files if path != file)
            # Canonical set distinguishes different co-runner memberships from
            # ordering-only variation; argv signature captures exact order too.
            companion_sets.append(tuple(sorted(companions)))
            argvs.append(files)
            case_entries.append({
                "command_ordinal": ordinal,
                "declared_regression_step": label,
                "group_file_count": len(files),
                "companion_test_files": list(companions),
                "ordered_command_sha256": hashlib.sha256(
                    json.dumps(files, separators=(",", ":")).encode("utf-8")
                ).hexdigest(),
                "semantic_coverage_confirmed": False,
                "safe_to_remove": False,
            })
        unique_companions = len(set(companion_sets))
        unique_argv = len(set(argvs))
        count = len(occurrences)
        entries.append({
            "test_file": file,
            "invocations": count,
            "additional_invocations": count - 1,
            "declared_step_titles_distinct": len({
                label for _, label, _ in occurrences
            }),
            "distinct_companion_sets": unique_companions,
            "distinct_ordered_commands": unique_argv,
            "source_signals": record["source_signals"],
            "review_classification": (
                "DISTINCT_COMPANION_CONTEXTS_HOLD" if unique_companions == count
                else "REPEATED_COMPANION_CONTEXTS_HOLD"
            ),
            "context_intent_evidence": "CI_STEP_LABEL_ONLY_NOT_SEMANTIC_PROOF",
            "required_next_evidence": [
                "OWNER_REVIEW_EACH_REGRESSION_STEP_INTENT",
                "ASSERTION_AND_STATE_BOUNDARY_COMPARISON",
                "EXACT_SHA_REAL_CI_AND_CROSS_CONTEXT_ACCEPTANCE",
            ],
            "occurrences": case_entries,
            "safe_to_remove": False,
        })

    all_occurrences = sum(entry["invocations"] for entry in entries)
    if (len(entries) != source_report["repeated_file_paths"]
            or sum(entry["additional_invocations"] for entry in entries)
            != source_report["additional_repeated_file_invocations"]
            or all_occurrences != source_report["repeated_file_paths"]
            + source_report["additional_repeated_file_invocations"]):
        raise IncompleteEvidence("repeat occurrence count inconsistent")

    return {
        "result": "MANUAL_REGRESSION_INTENT_REVIEW_REQUIRED",
        "evidence": "STATIC_WORKFLOW_STEP_LABEL_AND_COMPANION_MEMBERSHIP",
        "workflow_invocation_fingerprint_sha256": source_report[
            "invocation_fingerprint_sha256"],
        "artisan_test_process_invocations": source_report[
            "artisan_test_process_invocations"],
        "explicit_test_file_invocations": source_report[
            "explicit_test_file_invocations"],
        "unique_test_file_paths": source_report["unique_test_file_paths"],
        "repeated_file_paths": len(entries),
        "additional_repeated_file_invocations": source_report[
            "additional_repeated_file_invocations"],
        "repeated_file_occurrences": all_occurrences,
        "distinct_companion_context_file_count": sum(
            entry["review_classification"] == "DISTINCT_COMPANION_CONTEXTS_HOLD"
            for entry in entries),
        "repeated_companion_context_file_count": sum(
            entry["review_classification"] == "REPEATED_COMPANION_CONTEXTS_HOLD"
            for entry in entries),
        "semantic_independence_verified": False,
        "automatically_approved_removal_candidates": 0,
        "permission_to_remove_regression_invocations": False,
        "permission_to_regroup_or_reorder": False,
        "production_release_authorized": False,
        "contexts": entries,
    }


def main() -> int:
    try:
        if (WORKFLOW.is_symlink() or not WORKFLOW.is_file()
                or WORKFLOW.stat().st_size > 500_000):
            raise IncompleteEvidence("unsafe or absent workflow")
        report = audit(WORKFLOW.read_text(encoding="utf-8"))
        actual = tuple(report[key] for key in (
            "artisan_test_process_invocations", "explicit_test_file_invocations",
            "unique_test_file_paths", "repeated_file_paths",
            "additional_repeated_file_invocations",
        ))
        if actual != EXPECTED_BASELINE:
            raise IncompleteEvidence("pinned baseline changed; review required")
    except (IncompleteEvidence, OSError, UnicodeError, ValueError, TypeError):
        report = {
            "result": "BLOCK", "code": "INCOMPLETE_OR_DRIFTED_CONTEXT_INTENT_EVIDENCE",
            "automatically_approved_removal_candidates": 0,
            "permission_to_remove_regression_invocations": False,
            "production_release_authorized": False,
        }
    print(json.dumps(report, ensure_ascii=True, sort_keys=True))
    return 0 if report["result"] == "MANUAL_REGRESSION_INTENT_REVIEW_REQUIRED" else 2


if __name__ == "__main__":
    sys.exit(main())
