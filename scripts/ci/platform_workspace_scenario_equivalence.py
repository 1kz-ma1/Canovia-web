#!/usr/bin/env python3
"""Bounded, disposable SQLite comparison of two existing Workspace test contexts.

A passing comparison observes that the selected PHPUnit test methods pass
both alone and in the original group. It does NOT authorize deleting a
repeated regression occurrence, merging groups, or a production release.
Only runs with explicitly isolated sqlite in-memory settings; no API writes.
"""
from __future__ import annotations

import argparse
import json
import os
from pathlib import Path
import re
import subprocess
import sys
import tempfile
import time
import xml.etree.ElementTree as ET

ROOT = Path(__file__).resolve().parents[2]
WORKFLOW = ROOT / ".github/workflows/v56-12-workspace-resume.yml"
REL = "tests/Feature/"
MAX_XML_BYTES = 2_000_000

EXPERIMENTS = (
    ("adaptive_single_question", "AdaptiveLearningSingleQuestionV5878Test", (
        REL + "AdaptiveLearningCandidateQueueV5879Test.php",
        REL + "AdaptiveLearningSingleQuestionV5878Test.php",
        REL + "QuestionBankV402Test.php",
    )),
    ("plan_action_draft", "PlanActionDraftV5871Test", (
        REL + "PlanActionDraftV5874Test.php",
        REL + "PlanActionDraftV5873Test.php",
        REL + "PlanActionDraftV5872Test.php",
        REL + "PlanActionDraftV5871Test.php",
    )),
)
VALID_PATH = re.compile(r"tests/Feature/[A-Za-z0-9_]+Test\.php\Z")


class Inconclusive(Exception):
    pass


def expected_file(target: str) -> str:
    return REL + target + ".php"


def validate_manifest(source: str, read_sources: bool = True) -> None:
    if not isinstance(source, str) or not source:
        raise Inconclusive("workflow is missing")
    for key, target, group in EXPERIMENTS:
        if not (key and target and len(group) >= 2 and group.count(expected_file(target)) == 1):
            raise Inconclusive("invalid scenario manifest")
        if any(not VALID_PATH.fullmatch(path) for path in group):
            raise Inconclusive("unsafe scenario path")
        if "run: php artisan test " + " ".join(group) not in source:
            raise Inconclusive("scenario no longer matches Workspace Resume")
        if read_sources:
            for path in group:
                absolute = ROOT / path
                if absolute.is_symlink() or not absolute.is_file():
                    raise Inconclusive("scenario test file unavailable")
                if absolute.stat().st_size > 256_000:
                    raise Inconclusive("oversized scenario source")


def read_junit_all(path: Path) -> tuple[tuple[str, str], ...]:
    """Return every executed (class, method) in actual recorded order.

    Observing the target class alone is insufficient: a group invocation may
    accidentally omit an adjacent regression file and still look successful.
    """
    if path.is_symlink() or not path.is_file() or not 0 < path.stat().st_size <= MAX_XML_BYTES:
        raise Inconclusive("JUnit result missing or oversized")
    data = path.read_bytes()
    if b"<!DOCTYPE" in data.upper() or b"<!ENTITY" in data.upper():
        raise Inconclusive("unsafe XML")
    try:
        root = ET.fromstring(data)
    except ET.ParseError as exc:
        raise Inconclusive("invalid XML") from exc
    if root.tag not in ("testsuites", "testsuite"):
        raise Inconclusive("unexpected JUnit root")
    cases = root.findall(".//testcase")
    if not cases:
        raise Inconclusive("no JUnit testcases")
    observed = []
    for case in cases:
        if any(case.find(tag) is not None for tag in ("failure", "error", "skipped")):
            raise Inconclusive("failed or skipped testcase")
        cls = case.get("classname", "").replace(".", "\\")
        name = case.get("name", "")
        if (not cls or len(cls) > 300 or not name or len(name) > 300
            or any(char in cls for char in ("<", ">", "\\n"))):
            raise Inconclusive("invalid JUnit testcase identity")
        observed.append((cls, name))
    if len(observed) != len(set(observed)):
        raise Inconclusive("duplicated JUnit testcase identity")
    return tuple(observed)


def read_junit(path: Path, target: str) -> tuple[str, ...]:
    selected = [name for cls, name in read_junit_all(path)
                if cls.rsplit("\\", 1)[-1] == target]
    if not selected or len(selected) != len(set(selected)):
        raise Inconclusive("missing or duplicated target testcases")
    return tuple(sorted(selected))


def group_orders(target: str, group: tuple[str, ...]) -> tuple[tuple[str, tuple[str, ...]], ...]:
    """Fixed, bounded file permutations; never duplicate or omit group members."""
    selected = expected_file(target)
    if group.count(selected) != 1 or len(group) < 3 or len(set(group)) != len(group):
        raise Inconclusive("invalid order-sensitivity source group")
    rest = tuple(p for p in group if p != selected)
    variants = (
        ("original_group", group),
        ("reverse_group", tuple(reversed(group))),
        ("target_first", (selected, *rest)),
        ("target_last", (*rest, selected)),
        ("target_middle", (rest[0], selected, *rest[1:])),
    )
    seen = set()
    result = []
    for label, files in variants:
        if files in seen:
            continue
        seen.add(files)
        if sorted(files) != sorted(group):
            raise Inconclusive("changed regression group membership")
        result.append((label, files))
    if len(result) < 3 or len(result) > 5:
        raise Inconclusive("insufficient distinct order permutations")
    return tuple(result)


def observed_class_order(inventory: tuple[tuple[str, str], ...]) -> tuple[str, ...]:
    """First class appearance in JUnit, not merely the requested argv order."""
    return tuple(dict.fromkeys(cls.rsplit("\\", 1)[-1] for cls, _ in inventory))


def runner(command: list[str], junit: Path) -> float:
    # Source-controlled, fixed allowlist only; never use shell interpolation.
    env = os.environ.copy()
    env.update({
        "APP_ENV": "testing", "DB_CONNECTION": "sqlite", "DB_DATABASE": ":memory:",
        "DB_URL": "", "DATABASE_URL": "", "CACHE_STORE": "array",
        "SESSION_DRIVER": "array", "QUEUE_CONNECTION": "sync",
        "MAIL_MAILER": "array", "NATIVE_AI_DRIVER": "disabled",
    })
    started = time.monotonic()
    try:
        result = subprocess.run(command, cwd=ROOT, env=env, capture_output=True,
                                text=True, timeout=120, check=False)
    except (OSError, subprocess.TimeoutExpired) as exc:
        raise Inconclusive("test execution unavailable or timed out") from exc
    elapsed = time.monotonic() - started
    # Do not emit test stdout/stderr, env or raw log content.
    if result.returncode != 0:
        raise Inconclusive("scenario test subprocess returned nonzero")
    if not junit.is_file():
        raise Inconclusive("test runner did not write JUnit")
    return elapsed


def experiment() -> dict:
    if WORKFLOW.is_symlink() or not WORKFLOW.is_file():
        raise Inconclusive("workflow is unavailable")
    validate_manifest(WORKFLOW.read_text(encoding="utf-8"))
    if os.environ.get("GITHUB_ACTIONS") != "true":
        raise Inconclusive("live comparison requires isolated GitHub Actions runner")
    observations = []
    with tempfile.TemporaryDirectory(prefix="canovia-test-compare-") as tmp:
        folder = Path(tmp)
        for key, target, group in EXPERIMENTS:
            expected_classes = {Path(file).stem for file in group}
            recorded = []
            variants = (("alone", (expected_file(target),)), *group_orders(target, group))
            for label, files in variants:
                path = folder / (key + "_" + label + ".xml")
                cmd = ["php", "artisan", "test", "--without-tty",
                       "--log-junit", str(path), *files]
                try:
                    elapsed = runner(cmd, path)
                    inventory = read_junit_all(path)
                    methods = tuple(sorted(name for cls, name in inventory
                                           if cls.rsplit("\\", 1)[-1] == target))
                    observed = observed_class_order(inventory)
                    expected = {target} if label == "alone" else expected_classes
                    if not methods or len(methods) != len(set(methods)) or set(observed) != expected:
                        raise Inconclusive("missing expected class or target testcases")
                except Inconclusive as exc:
                    # Only fixed scenario labels and internal errors; no raw logs.
                    raise Inconclusive(key + "_" + label + ":" + str(exc)) from exc
                recorded.append((label, methods, inventory, observed, elapsed))
            if any(entry[1] != recorded[0][1] for entry in recorded[1:]):
                raise Inconclusive("selected testcase method set differs across contexts")
            canonical = tuple(sorted(recorded[1][2]))
            if any(tuple(sorted(entry[2])) != canonical for entry in recorded[2:]):
                raise Inconclusive("full group testcase inventory differs across orders")
            class_orders = {entry[3] for entry in recorded[1:]}
            if len(class_orders) < 2:
                raise Inconclusive("class execution order did not observably vary")
            observations.append({
                "scenario": key,
                "selected_test_class": target,
                "selected_testcases": len(recorded[0][1]),
                "alone_elapsed_seconds": round(recorded[0][4], 2),
                "original_group_elapsed_seconds": round(recorded[1][4], 2),
                "group_variants_executed": len(recorded) - 1,
                "distinct_observed_class_orders": len(class_orders),
                "total_group_testcases_per_variant": len(canonical),
                "group_execution_orders": [
                    {"variant": label, "class_order": list(order)}
                    for label, _, _, order, _ in recorded[1:]
                ],
                "same_selected_testcase_names": True,
                "same_full_group_testcase_inventory": True,
                "all_variants_passed": True,
                "both_runs_passed": True,
                "order_sensitive_failure_observed": False,
                "can_remove_duplicate": False,
            })
    return {
        "result": "OBSERVED_SCENARIO_PARITY",
        "runner": "isolated-GitHub-Actions-SQLite-memory",
        "scenarios": observations,
        "test_order_independence_proven": False,
        "tested_file_order_variations_passed": True,
        "cross_context_coverage_equivalence_proven": False,
        "permission_to_remove_repeated_invocations": False,
        "release_authorized": False,
    }


def main() -> int:
    p = argparse.ArgumentParser(description=__doc__)
    p.add_argument("--run", action="store_true", help="run fixed scenarios on disposable GitHub CI runner")
    args = p.parse_args()
    try:
        if not args.run:
            raise Inconclusive("must opt into isolated comparison")
        result = experiment()
    except Inconclusive as exc:
        # Inconclusive messages are static internal literals, never raw logs.
        result = {"result": "BLOCK", "code": "INCOMPLETE_OR_FAILED_SCENARIO_EVIDENCE",
                  "failure_stage": str(exc)[:120],
                  "permission_to_remove_repeated_invocations": False,
                  "release_authorized": False}
    except (OSError, UnicodeError, ValueError):
        result = {"result": "BLOCK", "code": "INVALID_RUNNER_ENVIRONMENT",
                  "permission_to_remove_repeated_invocations": False,
                  "release_authorized": False}
    print(json.dumps(result, ensure_ascii=True, sort_keys=True))
    return 0 if result["result"] == "OBSERVED_SCENARIO_PARITY" else 2


if __name__ == "__main__":
    sys.exit(main())
