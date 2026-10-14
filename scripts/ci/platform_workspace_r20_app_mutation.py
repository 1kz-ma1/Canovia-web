#!/usr/bin/env python3
"""R20 isolated application-code mutation detection; never mutate production.

Two exact PlanActionDraftController mutations are installed separately in
a disposable PR GitHub Actions checkout, then fully restored on every exit.
Passing reports detection coverage, NEVER authorization to remove CI tests.
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
        Inconclusive, MAX_XML_BYTES, ROOT, WORKFLOW, observed_class_order,
        read_junit_all, runner,
    )
    from .platform_workspace_r19_negative_controls import (
        GROUPS, METHODS, TARGET, manifest,
    )
except ImportError:
    from platform_workspace_scenario_equivalence import (
        Inconclusive, MAX_XML_BYTES, ROOT, WORKFLOW, observed_class_order,
        read_junit_all, runner,
    )
    from platform_workspace_r19_negative_controls import (
        GROUPS, METHODS, TARGET, manifest,
    )

SOURCE = ROOT / "app/Http/Controllers/PlanActionDraftController.php"
REL = "tests/Feature/"
SHADOW = GROUPS[1][1][:-1]
MUTATIONS = (
    (
        "cross_plan_worklog_source_scope",
        "test_legacy_work_log_is_scoped_to_plan_and_snapshot_is_kept",
        "$log = WorkLog::where('plan_id', $plan->id)->findOrFail($input['source_work_log_id']);",
        "$log = WorkLog::query()->findOrFail($input['source_work_log_id']);",
    ),
    (
        "dismiss_owner_authorization",
        "test_collaborative_editor_viewer_and_stranger_are_forbidden",
        "public function dismiss(Request $request, Plan $plan, PlanActionDraft $draft, PlanOwnershipService $ownership)\n"
        "    {\n        $ownership->authorizePlan($request, $plan);\n"
        "        $this->withinPlan($plan, $draft);",
        "public function dismiss(Request $request, Plan $plan, PlanActionDraft $draft, PlanOwnershipService $ownership)\n"
        "    {\n        // R20 disposable simulated absent authorization. NEVER COMMIT THIS TO APP.\n"
        "        $this->withinPlan($plan, $draft);",
    ),
)
MAX_RUNS = len(GROUPS) + 1 + len(MUTATIONS) * (len(GROUPS) + 1)
EXPECTED_CLASS = "PlanActionDraftV5871Test"


def isolated_environment() -> bool:
    return (
        os.environ.get("GITHUB_ACTIONS") == "true"
        and os.environ.get("CI") == "true"
        and os.environ.get("GITHUB_EVENT_NAME") == "pull_request"
        and os.environ.get("GITHUB_BASE_REF") == "main"
        and Path(os.environ.get("GITHUB_WORKSPACE", "/nonexistent")).resolve() == ROOT.resolve()
    )


def mutate(original: bytes, key: str) -> bytes:
    if not isinstance(original, bytes) or len(original) > 256_000:
        raise Inconclusive("invalid controller source length")
    try:
        source = original.decode("utf-8")
    except UnicodeError as exc:
        raise Inconclusive("invalid UTF-8 controller") from exc
    if not source.startswith("<?php") or "final class PlanActionDraftController extends Controller" not in source:
        raise Inconclusive("wrong controller source")
    if "R20 disposable simulated absent authorization" in source:
        raise Inconclusive("already mutated application source")
    if len(MUTATIONS) != 2 or MAX_RUNS != 18 or len({x[0] for x in MUTATIONS}) != 2:
        raise Inconclusive("R20 experiment bounds drift")
    matching = [entry for entry in MUTATIONS if entry[0] == key]
    if len(matching) != 1:
        raise Inconclusive("unknown mutation")
    _, _, old, new = matching[0]
    if source.count(old) != 1 or new in source:
        raise Inconclusive("mutation anchor changed or already present")
    return source.replace(old, new, 1).encode("utf-8")


def parse_mutated_junit(path: Path) -> tuple[tuple[tuple[str, str], ...], tuple[tuple[str, str], ...]]:
    """Allow multiple failing testcases; require complete, safe JUnit identities."""
    if path.is_symlink() or not path.is_file() or not 0 < path.stat().st_size <= MAX_XML_BYTES:
        raise Inconclusive("missing or oversized application-mutation JUnit")
    data = path.read_bytes()
    if b"<!DOCTYPE" in data.upper() or b"<!ENTITY" in data.upper():
        raise Inconclusive("unsafe XML")
    try:
        root = ET.fromstring(data)
    except ET.ParseError as exc:
        raise Inconclusive("invalid application-mutation JUnit") from exc
    if root.tag not in ("testsuites", "testsuite"):
        raise Inconclusive("unexpected JUnit root")
    cases = root.findall(".//testcase")
    if not cases:
        raise Inconclusive("empty application-mutation JUnit")
    observed = []
    failures = []
    for case in cases:
        cls = case.get("classname", "").replace(".", "\\")
        method = case.get("name", "")
        if not cls or not method or len(cls) > 300 or len(method) > 300 or any(
            x in cls for x in ("<", ">", "\n")
        ):
            raise Inconclusive("invalid testcase identity")
        if case.find("skipped") is not None:
            raise Inconclusive("skipped mutated testcase")
        markers = case.findall("error") + case.findall("failure")
        if len(markers) > 1:
            raise Inconclusive("multiple failure markers")
        identity = (cls, method)
        observed.append(identity)
        if markers:
            failures.append(identity)
    if len(observed) != len(set(observed)):
        raise Inconclusive("duplicate mutated testcase")
    return tuple(observed), tuple(failures)


def checked_outcome(junit: Path, exit_code: int, baseline: tuple, expected_method: str | None) -> dict:
    """No raw output. Require complete inventory and intended security regression."""
    inventory, failures = parse_mutated_junit(junit)
    if tuple(sorted(inventory)) != tuple(sorted(baseline)):
        raise Inconclusive("mutated testcase inventory changed from baseline")
    if bool(failures) != (exit_code != 0):
        raise Inconclusive("JUnit and process exit disagree")
    if expected_method is not None and not any(
        cls.rsplit("\\", 1)[-1] == EXPECTED_CLASS and method == expected_method
        for cls, method in failures
    ):
        raise Inconclusive("expected application mutation not detected by target testcase")
    return {
        "failure_count": len(failures),
        "failing_classes": sorted({cls.rsplit("\\", 1)[-1] for cls, _ in failures}),
        "target_method_detected": expected_method is not None,
    }


def _command(classes: tuple[str, ...], output: Path) -> list[str]:
    return ["php", "artisan", "test", "--without-tty", "--log-junit",
            str(output), *(REL + cls + ".php" for cls in classes)]


def run_mutated(classes: tuple[str, ...], junit: Path, baseline: tuple,
                method: str | None) -> dict:
    env = os.environ.copy()
    env.update({
        "APP_ENV": "testing", "DB_CONNECTION": "sqlite", "DB_DATABASE": ":memory:",
        "DB_URL": "", "DATABASE_URL": "", "CACHE_STORE": "array",
        "SESSION_DRIVER": "array", "QUEUE_CONNECTION": "sync", "MAIL_MAILER": "array",
        "NATIVE_AI_DRIVER": "disabled",
    })
    try:
        result = subprocess.run(_command(classes, junit), cwd=ROOT, env=env,
                                capture_output=True, timeout=120, check=False)
    except (OSError, subprocess.TimeoutExpired) as exc:
        raise Inconclusive("mutated PHP runner failed to start or timed out") from exc
    # stdout, stderr, mutated XML and source contents are never emitted.
    return checked_outcome(junit, result.returncode, baseline, method)


def collect() -> dict:
    if not isolated_environment():
        raise Inconclusive("R20 requires disposable PR-to-main CI checkout")
    if SOURCE.is_symlink() or not SOURCE.is_file() or WORKFLOW.is_symlink() or not WORKFLOW.is_file():
        raise Inconclusive("source checkout invalid")
    manifest(WORKFLOW.read_text(encoding="utf-8"))
    original = SOURCE.read_bytes()
    for key, _, _, _ in MUTATIONS:
        mutate(original, key)
    before = hashlib.sha256(original).hexdigest()
    groups = [classes for _, classes in GROUPS] + [SHADOW]
    baselines = []
    runs = 0
    observations = []
    try:
        with tempfile.TemporaryDirectory(prefix="canovia-r20-app-mutation-") as temp:
            folder = Path(temp)
            for index, classes in enumerate(groups):
                report = folder / f"baseline_{index}.xml"
                runner(_command(classes, report), report)
                cases = read_junit_all(report)
                if set(observed_class_order(cases)) != set(classes):
                    raise Inconclusive("positive baseline class set drift")
                if index < len(GROUPS):
                    selected = {method for cls, method in cases
                                if cls.rsplit("\\", 1)[-1] == TARGET}
                    if selected != set(METHODS):
                        raise Inconclusive("target method baseline drift")
                elif any(cls.rsplit("\\", 1)[-1] == TARGET for cls, _ in cases):
                    raise Inconclusive("shadow unexpectedly executes target class")
                baselines.append(cases)
                runs += 1
            expected_shadow = tuple(sorted(
                identity for identity in baselines[1]
                if identity[0].rsplit("\\", 1)[-1] != TARGET
            ))
            if tuple(sorted(baselines[-1])) != expected_shadow:
                raise Inconclusive("R18 shadow baseline identity drift")

            for key, method, _, _ in MUTATIONS:
                try:
                    SOURCE.write_bytes(mutate(original, key))
                    outcomes = []
                    for index, (label, classes) in enumerate(GROUPS):
                        xml = folder / f"{key}_group_{index}.xml"
                        result = run_mutated(classes, xml, baselines[index], method)
                        outcomes.append({
                            "declared_regression_step": label,
                            "expected_v5871_method_detected": result["target_method_detected"],
                            "total_failed_cases_in_group": result["failure_count"],
                            "failing_class_names": result["failing_classes"],
                        })
                        runs += 1
                    shadow_xml = folder / f"{key}_shadow.xml"
                    shadow_result = run_mutated(SHADOW, shadow_xml, baselines[-1], None)
                    runs += 1
                    observations.append({
                        "mutation": key,
                        "selected_v5871_method": method,
                        "historical_contexts": outcomes,
                        "shadow_without_v5871": {
                            "failed_testcase_count": shadow_result["failure_count"],
                            "failing_classes": shadow_result["failing_classes"],
                        },
                    })
                finally:
                    SOURCE.write_bytes(original)
                    if hashlib.sha256(SOURCE.read_bytes()).hexdigest() != before:
                        raise Inconclusive("mutated application source not restored")
    finally:
        if SOURCE.read_bytes() != original:
            SOURCE.write_bytes(original)
        if hashlib.sha256(SOURCE.read_bytes()).hexdigest() != before:
            raise Inconclusive("application source restoration failure")
    if runs != MAX_RUNS or len(observations) != len(MUTATIONS):
        raise Inconclusive("R20 incomplete mutation execution matrix")
    return {
        "result": "OBSERVED_SCOPED_APPLICATION_MUTANT_DETECTION",
        "runner": "disposable PR GitHub Actions / in-memory SQLite",
        "mutants": observations,
        "independent_php_processes": runs,
        "mutants_exercised": len(MUTATIONS),
        "expected_target_detections": len(MUTATIONS) * len(GROUPS),
        "restored_application_source_sha256": before,
        "app_mutations_committed": False,
        "other_context_assertions_substitute_target_proven": False,
        "permission_to_remove_repeated_invocations": False,
        "production_release_authorized": False,
    }


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--run", action="store_true")
    args = parser.parse_args()
    try:
        if not args.run:
            raise Inconclusive("--run is required")
        result = collect()
    except (Inconclusive, OSError, UnicodeError, ValueError, TypeError) as exc:
        result = {
            "result": "BLOCK", "code": "R20_APP_MUTATION_EVIDENCE_INCOMPLETE",
            "failure_stage": str(exc)[:120],
            "permission_to_remove_repeated_invocations": False,
            "production_release_authorized": False,
        }
    print(json.dumps(result, ensure_ascii=True, sort_keys=True))
    return 0 if result["result"] == "OBSERVED_SCOPED_APPLICATION_MUTANT_DETECTION" else 2


if __name__ == "__main__":
    sys.exit(main())
