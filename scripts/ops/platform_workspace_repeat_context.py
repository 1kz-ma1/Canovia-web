#!/usr/bin/env python3
"""Offline, fail-closed Workspace Resume repeated-test context evidence.

Lists exact named regression steps sharing the same PHP test file. Source
signals are conservative observations, NOT a proof of isolation or permission
to remove repeated checks. Does not execute PHP or access services.
"""
from __future__ import annotations

from collections import Counter
import hashlib
import json
from pathlib import Path
import re
import sys

try:
    from .platform_workspace_test_inventory import ROOT, WORKFLOW, inventory
except ImportError:
    from platform_workspace_test_inventory import ROOT, WORKFLOW, inventory

PATH = re.compile(r"tests/(?:Feature|Unit)/[A-Za-z0-9_.-]+\.php\Z")
STEP = re.compile(r"^      - name: ([A-Za-z0-9_. /():,@+#&-]{1,120})$")
COMMAND = re.compile(r"^        run: php artisan test (.+)$")
MAX_SOURCE_BYTES = 256_000


class IncompleteEvidence(ValueError):
    pass


def read_php(path: str) -> str:
    if not PATH.fullmatch(path) or ".." in path:
        raise IncompleteEvidence("unsafe test path")
    full = ROOT / path
    if full.is_symlink() or not full.is_file() or full.stat().st_size > MAX_SOURCE_BYTES:
        raise IncompleteEvidence("missing, oversized or symlinked test source")
    return full.read_text(encoding="utf-8")


def analyze(workflow: str, read_source=read_php) -> dict:
    if not isinstance(workflow, str) or not workflow or len(workflow) > 500_000:
        raise IncompleteEvidence("invalid workflow")
    recorded: dict[str, list[str]] = {}
    ordered_invocations: list[list[str]] = []
    current = ""
    for line in workflow.splitlines():
        if line.startswith("      - name: "):
            match = STEP.fullmatch(line)
            current = match.group(1) if match else ""
        if "run: php artisan test" not in line:
            continue
        match = COMMAND.fullmatch(line)
        if not match or not current:
            raise IncompleteEvidence("unattributed or unsupported test invocation")
        files = match.group(1).split(" ")
        if not files or any(not PATH.fullmatch(p) or ".." in p for p in files):
            raise IncompleteEvidence("unsafe test invocation")
        ordered_invocations.append(files)
        for path in files:
            recorded.setdefault(path, []).append(current)

    baseline = inventory(workflow)
    if (len(ordered_invocations) != baseline["artisan_test_process_invocations"]
        or sum(map(len, ordered_invocations)) != baseline["explicit_test_file_invocations"]):
        raise IncompleteEvidence("invocation inventory disagrees")
    repeats = [(p, steps) for p, steps in recorded.items() if len(steps) > 1]
    if len(repeats) > 100:
        raise IncompleteEvidence("oversized repeated-file inventory")
    grouped = []
    for path, contexts in sorted(repeats, key=lambda item: (-len(item[1]), item[0])):
        source = read_source(path)
        if not isinstance(source, str) or not source.startswith("<?php") or len(source) > MAX_SOURCE_BYTES:
            raise IncompleteEvidence("invalid PHP source evidence")
        refresh = (
            "use Illuminate\\Foundation\\Testing\\RefreshDatabase;" in source
            and bool(re.search(r"\buse\s+RefreshDatabase\s*;", source))
        )
        uses_laravel_testcase = "use Tests\\TestCase;" in source and bool(
            re.search(r"\bextends\s+TestCase\b", source)
        )
        # Unknown/clean static signals never mean statelessness or safe removal.
        grouped.append({
            "test_file": path,
            "invocations": len(contexts),
            "additional_invocations": len(contexts) - 1,
            "named_regression_contexts": list(contexts),
            "different_context_count": len(set(contexts)),
            "source_signals": {
                "feature_test_path": path.startswith("tests/Feature/"),
                "laravel_testcase": uses_laravel_testcase,
                "refresh_database": refresh,
                "database_transaction_or_migration": bool(re.search(
                    r"\b(?:DatabaseTransactions|DatabaseMigrations)\b", source)),
            },
            "disposition": "REVIEW_ISOLATION_AND_CROSS_CONTEXT_COVERAGE",
            "safe_to_remove": False,
        })
    fingerprint = hashlib.sha256(json.dumps(
        ordered_invocations, sort_keys=True, separators=(",", ":")
    ).encode("utf-8")).hexdigest()
    return {
        "result": "REVIEW_REQUIRED",
        "workflow": "V56.12 Workspace Resume Validation",
        "invocation_fingerprint_sha256": fingerprint,
        "artisan_test_process_invocations": len(ordered_invocations),
        "explicit_test_file_invocations": baseline["explicit_test_file_invocations"],
        "unique_test_file_paths": len(recorded),
        "repeated_file_paths": len(grouped),
        "additional_repeated_file_invocations": sum(
            group["additional_invocations"] for group in grouped),
        "repeated_feature_paths_with_refresh_database": sum(
            x["source_signals"]["feature_test_path"]
            and x["source_signals"]["refresh_database"] for x in grouped),
        "repeated_paths_without_static_refresh_signal": sum(
            not x["source_signals"]["refresh_database"] for x in grouped),
        "contexts": grouped,
        "static_scan_proves_independence": False,
        "permission_to_deduplicate_or_reorder": False,
        "permission_to_group_feature_tests": False,
        "release_authorized": False,
    }


def main() -> int:
    try:
        if WORKFLOW.is_symlink() or not WORKFLOW.is_file() or WORKFLOW.stat().st_size > 500_000:
            raise IncompleteEvidence("missing or unsafe workflow")
        report = analyze(WORKFLOW.read_text(encoding="utf-8"))
    except (IncompleteEvidence, OSError, UnicodeError, ValueError, TypeError):
        report = {"result": "BLOCK", "code": "INCOMPLETE_OR_INVALID_REPEAT_EVIDENCE",
                  "permission_to_deduplicate_or_reorder": False, "release_authorized": False}
    print(json.dumps(report, ensure_ascii=True, sort_keys=True))
    return 0 if report["result"] == "REVIEW_REQUIRED" else 2


if __name__ == "__main__":
    sys.exit(main())
