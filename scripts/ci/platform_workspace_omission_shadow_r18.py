#!/usr/bin/env python3
"""R18 disposable A/B/A' omission shadow: measure a local test inventory gap only.

NO permission to delete a repeated invocation. No production access, no logs
or test XML outside the ephemeral isolated runner.
"""
from __future__ import annotations

import argparse
import json
import math
import os
from pathlib import Path
import sys
import tempfile

try:
    from .platform_workspace_scenario_equivalence import (
        EXPERIMENTS, WORKFLOW, Inconclusive, observed_class_order,
        read_junit_all, runner, validate_manifest,
    )
except ImportError:
    from platform_workspace_scenario_equivalence import (
        EXPERIMENTS, WORKFLOW, Inconclusive, observed_class_order,
        read_junit_all, runner, validate_manifest,
    )

STEP = "V58.74 Owner-approved Sequential Action Draft"
TARGET = "PlanActionDraftV5871Test"
EXPECTED_GROUP = (
    "tests/Feature/PlanActionDraftV5874Test.php",
    "tests/Feature/PlanActionDraftV5873Test.php",
    "tests/Feature/PlanActionDraftV5872Test.php",
    "tests/Feature/PlanActionDraftV5871Test.php",
)
VARIANTS = ("original", "omitted_shadow", "restored_original")
EXPECTED_OTHERS = 4


def validate_scope(source: str) -> None:
    """Exact named step and retained historical repetitions are prerequisites."""
    validate_manifest(source)
    if not any(key == "plan_action_draft" and target == TARGET
               and group == EXPECTED_GROUP for key, target, group in EXPERIMENTS):
        raise Inconclusive("R18 group manifest drift")
    lines = source.splitlines()
    command = "        run: php artisan test " + " ".join(EXPECTED_GROUP)
    matches = [index for index in range(len(lines) - 1)
               if lines[index] == "      - name: " + STEP and lines[index + 1] == command]
    if len(matches) != 1:
        raise Inconclusive("named regression group missing or changed")
    # Check exact declared invocations, not a loose substring of PHP lint lines.
    target_commands = [
        line for line in lines
        if line.startswith("        run: php artisan test ")
        and ("tests/Feature/" + TARGET + ".php") in
        line.removeprefix("        run: php artisan test ").split(" ")
    ]
    if len(target_commands) != EXPECTED_OTHERS + 1:
        raise Inconclusive("other repeated regression contexts changed")


def summarize(full, shadow, restored) -> dict:
    """Compare complete JUnit (class, method) inventories; never make release claims."""
    if not all(isinstance(items, tuple) and items for items in (full, shadow, restored)):
        raise Inconclusive("missing JUnit inventories")
    expected = {Path(path).stem for path in EXPECTED_GROUP}
    retained_classes = expected - {TARGET}
    if set(observed_class_order(full)) != expected:
        raise Inconclusive("full group class membership drift")
    if tuple(sorted(full)) != tuple(sorted(restored)):
        raise Inconclusive("restored full JUnit inventory drift")
    if observed_class_order(full) != observed_class_order(restored):
        raise Inconclusive("restored class execution order drift")
    omitted = tuple((cls, method) for cls, method in full
                    if cls.rsplit("\\", 1)[-1] == TARGET)
    kept = tuple((cls, method) for cls, method in full
                 if cls.rsplit("\\", 1)[-1] != TARGET)
    if not omitted or set(observed_class_order(shadow)) != retained_classes:
        raise Inconclusive("expected omitted/retained classes mismatch")
    if tuple(sorted(shadow)) != tuple(sorted(kept)):
        raise Inconclusive("remaining JUnit testcase inventory drift")
    return {
        "source_named_regression_step": STEP,
        "omitted_test_file": EXPECTED_GROUP[-1],
        "full_testcases": len(full),
        "shadow_testcases": len(shadow),
        "restored_testcases": len(restored),
        "observed_missing_case_count": len(omitted),
        "observed_missing_testcase_methods": sorted(method for _, method in omitted),
        "retained_testcase_identity_parity_observed": True,
        "restored_inventory_and_class_order_parity_observed": True,
        "full_observed_class_order": list(observed_class_order(full)),
        "remaining_other_named_v5871_invocations_unchanged": EXPECTED_OTHERS,
        "cross_context_assertion_equivalence_proven": False,
        "state_boundary_equivalence_proven": False,
        "safe_to_remove": False,
    }


def collect() -> dict:
    if os.environ.get("GITHUB_ACTIONS") != "true":
        raise Inconclusive("only disposable GitHub Actions runner allowed")
    if WORKFLOW.is_symlink() or not WORKFLOW.is_file():
        raise Inconclusive("workspace manifest unavailable")
    validate_scope(WORKFLOW.read_text(encoding="utf-8"))
    variants = (
        ("original", EXPECTED_GROUP),
        ("omitted_shadow", EXPECTED_GROUP[:-1]),
        ("restored_original", EXPECTED_GROUP),
    )
    if tuple(label for label, _ in variants) != VARIANTS:
        raise Inconclusive("unexpected comparison variants")
    inventories = []
    elapsed_values = {}
    with tempfile.TemporaryDirectory(prefix="canovia-r18-shadow-") as temp:
        for label, files in variants:
            junit = Path(temp) / (label + ".xml")
            command = ["php", "artisan", "test", "--without-tty",
                       "--log-junit", str(junit), *files]
            try:
                elapsed = runner(command, junit)
                inventory = read_junit_all(junit)
                if not isinstance(elapsed, (int, float)) or not math.isfinite(elapsed) or elapsed < 0:
                    raise Inconclusive("invalid observed duration")
            except Inconclusive as exc:
                raise Inconclusive(label + ":" + str(exc)) from exc
            inventories.append(inventory)
            elapsed_values[label] = round(float(elapsed), 3)
    details = summarize(*inventories)
    return {
        "result": "OBSERVED_SCOPED_OMISSION_GAP",
        "runner": "disposable GitHub Actions / SQLite in-memory",
        "fresh_php_process_count": len(variants),
        "group_elapsed_seconds_observed_not_cost_savings": elapsed_values,
        **details,
        "normal_workspace_workflow_modified": False,
        "permission_to_remove_repeated_invocations": False,
        "production_release_authorized": False,
    }


def main() -> int:
    args = argparse.ArgumentParser(description=__doc__)
    args.add_argument("--run", action="store_true")
    opts = args.parse_args()
    try:
        if not opts.run:
            raise Inconclusive("explicit --run required")
        result = collect()
    except (Inconclusive, OSError, UnicodeError, ValueError, TypeError) as exc:
        # Fixed labels and static parser errors, no original PHP stdout/stderr.
        result = {
            "result": "BLOCK", "code": "R18_INCOMPLETE_OR_UNSAFE_SHADOW_EVIDENCE",
            "failure_stage": str(exc)[:120],
            "permission_to_remove_repeated_invocations": False,
            "production_release_authorized": False,
        }
    print(json.dumps(result, ensure_ascii=True, sort_keys=True))
    return 0 if result["result"] == "OBSERVED_SCOPED_OMISSION_GAP" else 2


if __name__ == "__main__":
    sys.exit(main())
