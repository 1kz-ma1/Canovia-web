#!/usr/bin/env python3
"""R19 negative-controls for V5871: disposable PHP processes only.

A tripwire intentionally fails one method in a throwaway CI checkout.
Other historical groups must detect the tripwire; an omitted group must not.
This is NOT evidence for deduplication, production release or assertion equivalence.
"""
from __future__ import annotations

import argparse
import hashlib
import json
import os
from pathlib import Path
import re
import subprocess
import sys
import tempfile
import xml.etree.ElementTree as ET

try:
    from .platform_workspace_scenario_equivalence import (
        Inconclusive, MAX_XML_BYTES, ROOT, WORKFLOW, read_junit_all,
        observed_class_order, runner,
    )
    from .platform_workspace_omission_shadow_r18 import (
        EXPECTED_GROUP, STEP, TARGET, validate_scope,
    )
except ImportError:
    from platform_workspace_scenario_equivalence import (
        Inconclusive, MAX_XML_BYTES, ROOT, WORKFLOW, read_junit_all,
        observed_class_order, runner,
    )
    from platform_workspace_omission_shadow_r18 import (
        EXPECTED_GROUP, STEP, TARGET, validate_scope,
    )

SOURCE = ROOT / "tests/Feature/PlanActionDraftV5871Test.php"
METHODS = (
    "test_manual_evidence_creates_inert_proposal_and_accept_once",
    "test_legacy_work_log_is_scoped_to_plan_and_snapshot_is_kept",
    "test_rejection_and_cross_plan_draft_do_not_mutate_tasks",
    "test_collaborative_editor_viewer_and_stranger_are_forbidden",
)
GROUPS = (
    ("V58.75 Draft Quality and Staleness Guard", (
        "PlanActionDraftV5875Test", "PlanActionDraftV5874Test",
        "PlanActionDraftV5873Test", "PlanActionDraftV5872Test", TARGET)),
    (STEP, ("PlanActionDraftV5874Test", "PlanActionDraftV5873Test",
            "PlanActionDraftV5872Test", TARGET)),
    ("V58.73 Typed Evidence and Domain-aware Plan Draft", (
        "PlanActionDraftV5873Test", "PlanActionDraftV5872Test", TARGET)),
    ("V58.72 Multi-evidence Draft and Revision Diff", (
        "PlanActionDraftV5872Test", TARGET)),
    ("V58.71 Action-grounded Draft and Owner Approval", (
        TARGET, "PlanActionFirstCreationV5866Test")),
)
BASELINES = len(GROUPS) + 1
EXPECTED_PHP_RUNS = BASELINES + len(METHODS) * (len(GROUPS) + 1)
TRIPWIRE = 'throw new \\RuntimeException("R19_DISPOSABLE_NEGATIVE_CONTROL");'
REL = "tests/Feature/"
SIGNATURE = re.compile(r"^[ \t]*public function (test_[A-Za-z0-9_]+)\(\): void[ \t]*$", re.M)


def manifest(source: str) -> None:
    validate_scope(source)
    for label, classes in GROUPS:
        command = "        run: php artisan test " + " ".join(REL + c + ".php" for c in classes)
        pair = "      - name: " + label + "\n" + command
        if source.count(pair) != 1:
            raise Inconclusive("R19 exact historical named command drift")
    if len(GROUPS) != 5 or BASELINES != 6 or EXPECTED_PHP_RUNS != 30:
        raise Inconclusive("R19 experiment bounds changed")
    if tuple(REL + c + ".php" for c in GROUPS[1][1]) != EXPECTED_GROUP:
        raise Inconclusive("R19 target group no longer equals R18")


def inject(original: bytes, method: str) -> bytes:
    if method not in METHODS or len(original) > 256_000:
        raise Inconclusive("unsafe or unexpected source")
    try:
        source = original.decode("utf-8")
    except UnicodeDecodeError as exc:
        raise Inconclusive("not UTF-8") from exc
    if not source.startswith("<?php") or TRIPWIRE in source:
        raise Inconclusive("invalid or already modified PHP")
    found = SIGNATURE.findall(source)
    if len(found) != len(METHODS) or set(found) != set(METHODS):
        raise Inconclusive("target method set drift")
    target = "    public function " + method + "(): void\n    {\n"
    if source.count(target) != 1:
        raise Inconclusive("target method declaration drift")
    if ("use RefreshDatabase;" not in source
            or "final class PlanActionDraftV5871Test extends TestCase" not in source):
        raise Inconclusive("target isolation contract drift")
    return source.replace(target, target + "        " + TRIPWIRE + "\n", 1).encode("utf-8")


def failed_junit(path: Path) -> tuple[tuple[tuple[str, str], ...], tuple[str, str]]:
    """Parse one expected negative JUnit result; reject unrelated failures/skips."""
    if path.is_symlink() or not path.is_file() or not 0 < path.stat().st_size <= MAX_XML_BYTES:
        raise Inconclusive("negative JUnit missing or oversized")
    data = path.read_bytes()
    if b"<!DOCTYPE" in data.upper() or b"<!ENTITY" in data.upper():
        raise Inconclusive("unsafe XML")
    try:
        root = ET.fromstring(data)
    except ET.ParseError as exc:
        raise Inconclusive("invalid negative JUnit") from exc
    if root.tag not in ("testsuites", "testsuite"):
        raise Inconclusive("invalid negative JUnit root")
    cases = root.findall(".//testcase")
    if not cases:
        raise Inconclusive("missing negative testcases")
    items = []
    affected = []
    for case in cases:
        cls = case.get("classname", "").replace(".", "\\")
        method = case.get("name", "")
        if (not cls or not method or len(cls) > 300 or len(method) > 300
                or any(x in cls for x in ("<", ">", "\n"))):
            raise Inconclusive("invalid negative testcase identity")
        if case.find("skipped") is not None:
            raise Inconclusive("unexpected skipped negative testcase")
        markers = tuple(case.findall("failure")) + tuple(case.findall("error"))
        if len(markers) > 1:
            raise Inconclusive("multiple failure markers")
        identity = (cls, method)
        items.append(identity)
        if markers:
            affected.append(identity)
    if len(items) != len(set(items)) or len(affected) != 1:
        raise Inconclusive("negative JUnit unexpected identity or failure count")
    return tuple(items), affected[0]


def check_negative(junit: Path, baseline: tuple, method: str) -> None:
    observed, failure = failed_junit(junit)
    if tuple(sorted(observed)) != tuple(sorted(baseline)):
        raise Inconclusive("negative case inventory differs from baseline")
    if failure[0].rsplit("\\", 1)[-1] != TARGET or failure[1] != method:
        raise Inconclusive("wrong failing class or method")


def _command(files: tuple[str, ...], path: Path) -> list[str]:
    return ["php", "artisan", "test", "--without-tty", "--log-junit",
            str(path), *(REL + cls + ".php" for cls in files)]


def negative_run(files: tuple[str, ...], junit: Path, baseline: tuple, method: str) -> None:
    """Require an actual test failure in exactly the injected fixture method."""
    env = os.environ.copy()
    env.update({
        "APP_ENV": "testing", "DB_CONNECTION": "sqlite", "DB_DATABASE": ":memory:",
        "DB_URL": "", "DATABASE_URL": "", "CACHE_STORE": "array",
        "SESSION_DRIVER": "array", "QUEUE_CONNECTION": "sync",
        "MAIL_MAILER": "array", "NATIVE_AI_DRIVER": "disabled",
    })
    try:
        result = subprocess.run(_command(files, junit), cwd=ROOT, env=env,
                                capture_output=True, timeout=120, check=False)
    except (OSError, subprocess.TimeoutExpired) as exc:
        raise Inconclusive("negative test process unavailable or timed out") from exc
    # Never print stdout/stderr or raw XML; a nonzero exit alone is not proof of
    # the intended tripwire firing.
    if result.returncode == 0:
        raise Inconclusive("negative control unexpectedly succeeded")
    check_negative(junit, baseline, method)


def collect() -> dict:
    if os.environ.get("GITHUB_ACTIONS") != "true":
        raise Inconclusive("disposable GitHub Actions only")
    if WORKFLOW.is_symlink() or not WORKFLOW.is_file() or SOURCE.is_symlink() or not SOURCE.is_file():
        raise Inconclusive("unsafe or missing source manifest")
    manifest(WORKFLOW.read_text(encoding="utf-8"))
    original = SOURCE.read_bytes()
    if len(original) > 256_000:
        raise Inconclusive("oversized test source")
    for method in METHODS:
        inject(original, method)
    baseline_hash = hashlib.sha256(original).hexdigest()
    baseline_cases = {}
    shadow = GROUPS[1][1][:-1]
    total = 0
    try:
        with tempfile.TemporaryDirectory(prefix="canovia-r19-negative-") as tmp:
            folder = Path(tmp)
            for index, (_, classes) in enumerate(GROUPS):
                junit = folder / f"baseline_{index}.xml"
                runner(_command(classes, junit), junit)
                baseline = read_junit_all(junit)
                expected_classes = set(classes)
                if set(observed_class_order(baseline)) != expected_classes:
                    raise Inconclusive("baseline missing expected class")
                selected = {method for cls, method in baseline
                            if cls.rsplit("\\", 1)[-1] == TARGET}
                if selected != set(METHODS):
                    raise Inconclusive("baseline target method inventory drift")
                baseline_cases[index] = baseline
                total += 1
            shadow_junit = folder / "baseline_shadow.xml"
            runner(_command(shadow, shadow_junit), shadow_junit)
            shadow_cases = read_junit_all(shadow_junit)
            if (tuple(sorted(shadow_cases)) != tuple(sorted(
                    identity for identity in baseline_cases[1]
                    if identity[0].rsplit("\\", 1)[-1] != TARGET))
                    or set(observed_class_order(shadow_cases)) != set(shadow)):
                raise Inconclusive("omission baseline drift")
            total += 1
            for method in METHODS:
                try:
                    SOURCE.write_bytes(inject(original, method))
                    for index, (_, classes) in enumerate(GROUPS):
                        junit = folder / f"{method}_{index}.xml"
                        negative_run(classes, junit, baseline_cases[index], method)
                        total += 1
                    # The same deliberate failure must not affect the group
                    # from which that file was omitted.
                    shadow_result = folder / f"{method}_omitted.xml"
                    runner(_command(shadow, shadow_result), shadow_result)
                    observed = read_junit_all(shadow_result)
                    if tuple(sorted(observed)) != tuple(sorted(shadow_cases)):
                        raise Inconclusive("omitted group case inventory drift")
                    total += 1
                finally:
                    SOURCE.write_bytes(original)
                    if hashlib.sha256(SOURCE.read_bytes()).hexdigest() != baseline_hash:
                        raise Inconclusive("ephemeral target test file not restored")
    finally:
        # A second outer guard, even if setup/baselines fail.
        if SOURCE.read_bytes() != original:
            SOURCE.write_bytes(original)
        if hashlib.sha256(SOURCE.read_bytes()).hexdigest() != baseline_hash:
            raise Inconclusive("target PHP source integrity failure")
    if total != EXPECTED_PHP_RUNS:
        raise Inconclusive("incomplete negative control run matrix")
    return {
        "result": "OBSERVED_FOUR_METHOD_NEGATIVE_CONTROLS",
        "source": "disposable GitHub Actions / fresh SQLite in-memory",
        "named_regression_steps": [label for label, _ in GROUPS],
        "method_names": list(METHODS),
        "unique_method_negative_controls": len(METHODS),
        "normal_group_negative_detections": len(METHODS) * len(GROUPS),
        "omitted_group_negative_non_detections": len(METHODS),
        "total_independent_php_processes": total,
        "no_unexpected_non_target_failures": True,
        "ephemeral_test_source_restored_sha256": baseline_hash,
        "method_execution_detected_in_other_contexts": True,
        "assertion_or_state_semantics_equivalence_proven": False,
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
        report = collect()
    except (Inconclusive, OSError, UnicodeError, ValueError, TypeError) as exc:
        report = {
            "result": "BLOCK", "code": "INCOMPLETE_R19_NEGATIVE_EVIDENCE",
            "failure_stage": str(exc)[:120],
            "permission_to_remove_repeated_invocations": False,
            "production_release_authorized": False,
        }
    print(json.dumps(report, ensure_ascii=True, sort_keys=True))
    return 0 if report["result"] == "OBSERVED_FOUR_METHOD_NEGATIVE_CONTROLS" else 2


if __name__ == "__main__":
    sys.exit(main())
