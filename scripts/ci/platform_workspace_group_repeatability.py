#!/usr/bin/env python3
"""Fail-closed replay of original/reversed Workspace test groups on disposable CI.

A successful three-repeat observation is scoped to this runner and exact SHA.
It is not evidence permitting deletion of any regression invocation.
"""
from __future__ import annotations

import argparse
import json
import math
import os
from pathlib import Path
import statistics
import sys
import tempfile

try:
    from .platform_workspace_scenario_equivalence import (
        EXPERIMENTS, WORKFLOW, Inconclusive, group_orders,
        observed_class_order, read_junit_all, runner, validate_manifest,
    )
except ImportError:
    from platform_workspace_scenario_equivalence import (
        EXPERIMENTS, WORKFLOW, Inconclusive, group_orders,
        observed_class_order, read_junit_all, runner, validate_manifest,
    )

REPETITIONS = 3
VARIANTS = ("original_group", "reverse_group")
MAX_RUNS = 12


def collect() -> dict:
    """Run fixed historical groups three times apiece, in separate PHP processes."""
    if os.environ.get("GITHUB_ACTIONS") != "true":
        raise Inconclusive("GitHub Actions disposable runner required")
    if WORKFLOW.is_symlink() or not WORKFLOW.is_file():
        raise Inconclusive("workflow unavailable")
    validate_manifest(WORKFLOW.read_text(encoding="utf-8"))
    if REPETITIONS != 3 or len(EXPERIMENTS) != 2 or MAX_RUNS != 12:
        raise Inconclusive("unexpected replay bounds")
    results = []
    with tempfile.TemporaryDirectory(prefix="canovia-repeat-") as temp:
        folder = Path(temp)
        for key, target, original in EXPERIMENTS:
            variants = dict(group_orders(target, original))
            expected_classes = {Path(name).stem for name in original}
            canonical_inventory = None
            order_signatures = []
            variant_reports = []
            for label in VARIANTS:
                files = variants.get(label)
                if not files or sorted(files) != sorted(original):
                    raise Inconclusive("missing or changed replay group")
                first_inventory = None
                first_order = None
                elapsed_samples = []
                for iteration in range(1, REPETITIONS + 1):
                    output = folder / f"{key}_{label}_repeat_{iteration}.xml"
                    command = [
                        "php", "artisan", "test", "--without-tty",
                        "--log-junit", str(output), *files,
                    ]
                    try:
                        elapsed = runner(command, output)
                        inventory = read_junit_all(output)
                        observed_order = observed_class_order(inventory)
                        if set(observed_order) != expected_classes:
                            raise Inconclusive("missing expected JUnit class")
                        if not isinstance(elapsed, (int, float)) or not math.isfinite(elapsed) or elapsed < 0:
                            raise Inconclusive("invalid duration evidence")
                        normalized = tuple(sorted(inventory))
                        if first_inventory is None:
                            first_inventory, first_order = normalized, observed_order
                        elif normalized != first_inventory or observed_order != first_order:
                            raise Inconclusive("repeat changed JUnit inventory or class order")
                        if canonical_inventory is None:
                            canonical_inventory = normalized
                        elif normalized != canonical_inventory:
                            raise Inconclusive("original and reverse group testcase inventory differ")
                    except Inconclusive as exc:
                        # Only source-controlled scenario labels and internal literal errors.
                        raise Inconclusive(f"{key}_{label}_repeat_{iteration}:{exc}") from exc
                    elapsed_samples.append(round(float(elapsed), 3))
                if first_inventory is None or first_order is None or len(elapsed_samples) != REPETITIONS:
                    raise Inconclusive("missing repetitions")
                order_signatures.append(first_order)
                variant_reports.append({
                    "variant": label,
                    "repetitions": REPETITIONS,
                    "all_repetitions_passed": True,
                    "testcases_per_repeat": len(first_inventory),
                    "observed_class_order": list(first_order),
                    "elapsed_min_seconds": min(elapsed_samples),
                    "elapsed_median_seconds": round(statistics.median(elapsed_samples), 3),
                    "elapsed_max_seconds": max(elapsed_samples),
                })
            if len(set(order_signatures)) != len(VARIANTS):
                raise Inconclusive("original and reversed class orders indistinguishable")
            results.append({
                "scenario": key,
                "target": target,
                "group_testcases": len(canonical_inventory),
                "group_variants": variant_reports,
                "replay_count": len(VARIANTS) * REPETITIONS,
                "same_testcase_inventory_across_replays": True,
                "stable_observed_class_order_per_variant": True,
                "different_original_reverse_class_order": True,
                "repeatability_beyond_this_runner_proven": False,
            })
    if len(results) != 2 or sum(x["replay_count"] for x in results) != MAX_RUNS:
        raise Inconclusive("bounded replay count incomplete")
    return {
        "result": "OBSERVED_FIXED_GROUP_REPEATABILITY",
        "source": "disposable GitHub Actions / SQLite in-memory",
        "scenarios": results,
        "total_group_replays": MAX_RUNS,
        "all_replay_testcases_passed": True,
        "repeatability_beyond_this_runner_proven": False,
        "assertion_semantics_equivalence_proven": False,
        "cross_context_regression_equivalence_proven": False,
        "permission_to_remove_repeated_invocations": False,
        "production_release_authorized": False,
    }


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--run", action="store_true", help="replay on a disposable GitHub Actions runner")
    args = parser.parse_args()
    try:
        if not args.run:
            raise Inconclusive("explicit isolated run flag required")
        report = collect()
    except (Inconclusive, OSError, UnicodeError, ValueError, TypeError) as exc:
        message = str(exc)
        # These diagnostics are limited to internal literal reasons, never stdout/stderr or XML.
        report = {
            "result": "BLOCK", "code": "INCOMPLETE_OR_UNSTABLE_REPLAY",
            "failure_stage": message[:120],
            "permission_to_remove_repeated_invocations": False,
            "production_release_authorized": False,
        }
    print(json.dumps(report, ensure_ascii=True, sort_keys=True))
    return 0 if report["result"] == "OBSERVED_FIXED_GROUP_REPEATABILITY" else 2


if __name__ == "__main__":
    sys.exit(main())
