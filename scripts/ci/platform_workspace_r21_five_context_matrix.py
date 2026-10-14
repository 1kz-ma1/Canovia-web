#!/usr/bin/env python3
"""R21 isolated five-context leave-one-invocation-out application mutation probe.

Each original group runs separately. For each named group only its V5871
test file is removed in a shadow command; normal CI remains UNCHANGED.
This is not permission to deduplicate, reorder or release anything.
"""
from __future__ import annotations

import argparse
import hashlib
import json
from pathlib import Path
import sys
import tempfile

try:
    from .platform_workspace_scenario_equivalence import (
        Inconclusive, ROOT, WORKFLOW, read_junit_all, observed_class_order, runner,
    )
    from .platform_workspace_r19_negative_controls import (
        GROUPS, METHODS, TARGET, manifest,
    )
    from .platform_workspace_r20_app_mutation import (
        MUTATIONS, SOURCE, checked_outcome, isolated_environment, mutate,
        run_mutated, _command,
    )
except ImportError:
    from platform_workspace_scenario_equivalence import (
        Inconclusive, ROOT, WORKFLOW, read_junit_all, observed_class_order, runner,
    )
    from platform_workspace_r19_negative_controls import (
        GROUPS, METHODS, TARGET, manifest,
    )
    from platform_workspace_r20_app_mutation import (
        MUTATIONS, SOURCE, checked_outcome, isolated_environment, mutate,
        run_mutated, _command,
    )

TOTAL_ORIGINAL_GROUPS = 5
TOTAL_MUTANTS = 2
EXPECTED_PHP_RUNS = TOTAL_ORIGINAL_GROUPS * 2 * (TOTAL_MUTANTS + 1)  # 30
EXPECTED_TARGET_CASES = 4


def shadow_groups() -> tuple[tuple[str, ...], ...]:
    if len(GROUPS) != TOTAL_ORIGINAL_GROUPS or len(MUTATIONS) != TOTAL_MUTANTS:
        raise Inconclusive("R21 experiment or mutant count drift")
    shadows = []
    for name, classes in GROUPS:
        if classes.count(TARGET) != 1 or len(classes) < 2:
            raise Inconclusive("target missing or duplicate within " + name)
        shadows.append(tuple(cls for cls in classes if cls != TARGET))
    return tuple(shadows)


def validate_matrix_source(manifest_source: str, controller: bytes) -> None:
    manifest(manifest_source)
    shadows = shadow_groups()
    if (len(METHODS) != EXPECTED_TARGET_CASES
            or EXPECTED_PHP_RUNS != 30
            or len(shadows) != 5
            or len({label for label, _ in GROUPS}) != TOTAL_ORIGINAL_GROUPS
            or len({classes for classes in shadows}) != TOTAL_ORIGINAL_GROUPS):
        raise Inconclusive("R21 control matrix or distinct companion sets drift")
    for key, method, _, _ in MUTATIONS:
        if method not in METHODS:
            raise Inconclusive("app mutant lost baseline target method")
        mutate(controller, key)


def baseline_membership(cases: tuple, classes: tuple[str, ...], *, shadow: bool) -> None:
    actual = set(observed_class_order(cases))
    if actual != set(classes):
        raise Inconclusive("baseline JUnit class membership drift")
    selected = {method for cls, method in cases if cls.rsplit("\\", 1)[-1] == TARGET}
    if selected != (set() if shadow else set(METHODS)):
        raise Inconclusive("baseline V5871 method inventory drift")


def compare_baselines(full: tuple, omitted: tuple) -> None:
    expected = tuple(sorted((cls, method) for cls, method in full
                            if cls.rsplit("\\", 1)[-1] != TARGET))
    if tuple(sorted(omitted)) != expected:
        raise Inconclusive("V5871 omission changed retained JUnit identities")


def summarize_matrix(raw: tuple[tuple[dict, ...], ...]) -> dict:
    """Prove expected target detection in each normal group, report all shadow outcomes.

    A leave-one-out corridor uses one shadow plus the other four intact
    groups. This represents a scoped logical detection ledger, not a real
    sequential full Workspace Resume replay or a safety verdict.
    """
    if len(raw) != TOTAL_MUTANTS:
        raise Inconclusive("missing application mutant observations")
    entries = []
    total_full = 0
    total_shadow = 0
    for mindex, cells in enumerate(raw):
        if len(cells) != TOTAL_ORIGINAL_GROUPS:
            raise Inconclusive("incomplete named-group matrix")
        key, method, _, _ = MUTATIONS[mindex]
        by_context = []
        for j, pair in enumerate(cells):
            if set(pair) != {"full", "shadow"}:
                raise Inconclusive("incomplete context observation")
            full, shadow = pair["full"], pair["shadow"]
            if full.get("target_method_detected") is not True or full.get("failure_count", 0) < 1:
                raise Inconclusive("real mutation not detected in unchanged group")
            if TARGET not in full.get("failing_classes", []):
                raise Inconclusive("target test not among observed failing classes")
            if shadow.get("target_method_detected") is not False:
                raise Inconclusive("omitted class incorrectly counted as executed")
            if TARGET in shadow.get("failing_classes", []):
                raise Inconclusive("target class reappeared in shadow")
            full_found = full["failure_count"] > 0
            shadow_found = shadow["failure_count"] > 0
            total_full += int(full_found)
            total_shadow += int(shadow_found)
            by_context.append({
                "step": GROUPS[j][0],
                "full_detected": full_found,
                "full_failed_cases": full["failure_count"],
                "shadow_without_v5871_detected": shadow_found,
                "shadow_failed_cases": shadow["failure_count"],
                "shadow_failing_classes": shadow["failing_classes"],
                # For each individually omitted group, the four *other*
                # original groups are unchanged and detect this exact fault.
                "other_four_unchanged_groups_detected": TOTAL_ORIGINAL_GROUPS - 1,
                "simulated_five_step_corridor_detection_count":
                    TOTAL_ORIGINAL_GROUPS - 1 + int(shadow_found),
            })
        entries.append({
            "mutation": key,
            "required_v5871_method": method,
            "named_contexts": by_context,
            "all_five_full_contexts_detected": all(c["full_detected"] for c in by_context),
            "every_one_group_omission_retains_four_other_detecting_contexts":
                all(c["other_four_unchanged_groups_detected"] == 4 for c in by_context),
        })
    if total_full != TOTAL_MUTANTS * TOTAL_ORIGINAL_GROUPS:
        raise Inconclusive("unexpected original detection count")
    return {
        "mutation_results": entries,
        "intact_context_detection_count": total_full,
        "total_omitted_contexts_detecting": total_shadow,
        "total_omitted_contexts_not_detecting":
            TOTAL_MUTANTS * TOTAL_ORIGINAL_GROUPS - total_shadow,
    }


def collect() -> dict:
    if not isolated_environment():
        raise Inconclusive("only ephemeral GitHub PR-to-main CI checkout is allowed")
    if (WORKFLOW.is_symlink() or not WORKFLOW.is_file()
            or SOURCE.is_symlink() or not SOURCE.is_file()):
        raise Inconclusive("unsafe source checkout")
    original = SOURCE.read_bytes()
    validate_matrix_source(WORKFLOW.read_text(encoding="utf-8"), original)
    fingerprint = hashlib.sha256(original).hexdigest()
    shadows = shadow_groups()
    baseline_full: list[tuple] = []
    baseline_shadow: list[tuple] = []
    raw = []
    processes = 0
    try:
        with tempfile.TemporaryDirectory(prefix="canovia-r21-five-ctx-") as temp:
            directory = Path(temp)
            for j, (_, full_classes) in enumerate(GROUPS):
                path = directory / f"full_{j}.xml"
                runner(_command(full_classes, path), path)
                full_cases = read_junit_all(path)
                baseline_membership(full_cases, full_classes, shadow=False)
                baseline_full.append(full_cases)
                processes += 1
                path = directory / f"shadow_{j}.xml"
                runner(_command(shadows[j], path), path)
                shadow_cases = read_junit_all(path)
                baseline_membership(shadow_cases, shadows[j], shadow=True)
                compare_baselines(full_cases, shadow_cases)
                baseline_shadow.append(shadow_cases)
                processes += 1

            for mindex, (key, method, _, _) in enumerate(MUTATIONS):
                rows = []
                try:
                    SOURCE.write_bytes(mutate(original, key))
                    for j, (_, full_classes) in enumerate(GROUPS):
                        full_xml = directory / f"m{mindex}_full_{j}.xml"
                        full = run_mutated(full_classes, full_xml, baseline_full[j], method)
                        processes += 1
                        shadow_xml = directory / f"m{mindex}_shadow_{j}.xml"
                        shadow = run_mutated(shadows[j], shadow_xml, baseline_shadow[j], None)
                        processes += 1
                        rows.append({"full": full, "shadow": shadow})
                finally:
                    SOURCE.write_bytes(original)
                    if hashlib.sha256(SOURCE.read_bytes()).hexdigest() != fingerprint:
                        raise Inconclusive("ephemeral controller did not restore")
                raw.append(tuple(rows))
    finally:
        if SOURCE.read_bytes() != original:
            SOURCE.write_bytes(original)
        if hashlib.sha256(SOURCE.read_bytes()).hexdigest() != fingerprint:
            raise Inconclusive("controller integrity restoration failed")
    if processes != EXPECTED_PHP_RUNS:
        raise Inconclusive("missing planned PHP process evidence")
    evidence = summarize_matrix(tuple(raw))
    return {
        "result": "OBSERVED_FIVE_CONTEXT_LEAVE_ONE_OUT_MATRIX",
        "runner": "disposable PR Actions / isolated SQLite :memory:",
        "baseline_normal_group_runs": len(GROUPS),
        "baseline_omission_group_runs": len(shadows),
        "mutated_original_and_omission_group_runs": len(MUTATIONS) * len(GROUPS) * 2,
        "independent_php_processes": processes,
        **evidence,
        "controller_restored_sha256": fingerprint,
        "normal_workspace_workflow_modified": False,
        "complete_workspace_resume_equivalence_proven": False,
        "cross_context_state_isolation_proven": False,
        "permission_to_remove_repeated_invocations": False,
        "production_release_authorized": False,
    }


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--run", action="store_true")
    args = parser.parse_args()
    try:
        if not args.run:
            raise Inconclusive("explicit --run required")
        result = collect()
    except (Inconclusive, OSError, ValueError, TypeError, UnicodeError) as exc:
        result = {
            "result": "BLOCK",
            "code": "R21_SCOPED_MUTATION_MATRIX_INCOMPLETE",
            "failure_stage": str(exc)[:120],
            "permission_to_remove_repeated_invocations": False,
            "production_release_authorized": False,
        }
    print(json.dumps(result, ensure_ascii=True, sort_keys=True))
    return 0 if result["result"] == "OBSERVED_FIVE_CONTEXT_LEAVE_ONE_OUT_MATRIX" else 2


if __name__ == "__main__":
    sys.exit(main())
