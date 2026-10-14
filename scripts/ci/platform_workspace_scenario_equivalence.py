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


def read_junit(path: Path, target: str) -> tuple[str, ...]:
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
    for case in cases:
        if case.find("failure") is not None or case.find("error") is not None or case.find("skipped") is not None:
            raise Inconclusive("failed or skipped testcase")
    selected = []
    for case in cases:
        cls = case.get("classname", "").replace(".", "\\").rsplit("\\", 1)[-1]
        name = case.get("name", "")
        if cls == target:
            if not name or len(name) > 300:
                raise Inconclusive("invalid test method name")
            selected.append(name)
    if not selected or len(selected) != len(set(selected)):
        raise Inconclusive("missing or duplicated target testcases")
    return tuple(sorted(selected))


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
            baseline = []
            for label, files in (("alone", [expected_file(target)]), ("original_group", list(group))):
                path = folder / (key + "_" + label + ".xml")
                cmd = ["php", "artisan", "test", "--without-tty",
                       "--log-junit", str(path), *files]
                try:
                    elapsed = runner(cmd, path)
                    methods = read_junit(path, target)
                except Inconclusive as exc:
                    # Only fixed scenario labels and fixed internal failure codes.
                    raise Inconclusive(key + "_" + label + ":" + str(exc)) from exc
                baseline.append((label, methods, elapsed))
            if baseline[0][1] != baseline[1][1]:
                raise Inconclusive("selected testcase method set differs across contexts")
            observations.append({
                "scenario": key,
                "selected_test_class": target,
                "selected_testcases": len(baseline[0][1]),
                "alone_elapsed_seconds": round(baseline[0][2], 2),
                "original_group_elapsed_seconds": round(baseline[1][2], 2),
                "same_selected_testcase_names": True,
                "both_runs_passed": True,
                "can_remove_duplicate": False,
            })
    return {
        "result": "OBSERVED_SCENARIO_PARITY",
        "runner": "isolated-GitHub-Actions-SQLite-memory",
        "scenarios": observations,
        "test_order_independence_proven": False,
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
