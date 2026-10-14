#!/usr/bin/env python3
"""Offline inventory of Workspace Resume's explicit artisan test invocations.

Repeated tests may be intentional isolation checks. This is NOT permission to
skip, reorder, parallelize, or merge DB/Feature tests. No network/credentials.
"""
from __future__ import annotations

from collections import Counter
import json
from pathlib import Path
import re
import sys

ROOT = Path(__file__).resolve().parents[2]
WORKFLOW = ROOT / ".github/workflows/v56-12-workspace-resume.yml"
COMMAND = re.compile(r"^\s+run: php artisan test (.+)$")
FILE = re.compile(r"tests/(?:Feature|Unit)/[A-Za-z0-9_.-]+\.php\Z")


def inventory(source: str) -> dict:
    if not isinstance(source, str):
        raise ValueError("not a workflow")
    commands: list[list[str]] = []
    labels: list[str] = []
    label = ""
    for line in source.splitlines():
        if line.startswith("      - name: "):
            label = line.split(": ", 1)[1]
        match = COMMAND.fullmatch(line)
        if not match:
            continue
        files = match.group(1).split(" ")
        if not files or any(not FILE.fullmatch(value) for value in files):
            raise ValueError("unexpected test argv; manual audit needed")
        commands.append(files)
        labels.append(label)

    if not commands or len(commands) > 200:
        raise ValueError("empty/oversized test list")
    paths = Counter(f for command in commands for f in command)
    if len(paths) > 500:
        raise ValueError("unexpected inventory size")
    unit_only = sum(all(f.startswith("tests/Unit/") for f in files) for files in commands)
    return {
        "result": "REVIEW_REQUIRED",
        "artisan_test_process_invocations": len(commands),
        "explicit_test_file_invocations": sum(paths.values()),
        "unique_test_file_paths": len(paths),
        "repeated_test_file_invocations": sum(paths.values()) - len(paths),
        "phpunit_unit_only_commands": unit_only,
        "top_repeated_test_files": [
            {"path": path, "invocations": count}
            for path, count in sorted(paths.items(), key=lambda x: (-x[1], x[0]))
            if count > 1
        ][:10],
        "test_order_and_isolation_equivalence_verified": False,
        "permission_to_remove_repeats": False,
        "permission_to_group_feature_tests": False,
        "release_authorized": False,
    }


def main() -> int:
    try:
        if WORKFLOW.is_symlink() or not WORKFLOW.is_file() or WORKFLOW.stat().st_size > 500_000:
            raise ValueError("invalid workflow")
        result = inventory(WORKFLOW.read_text(encoding="utf-8"))
    except (ValueError, OSError, UnicodeError):
        print(json.dumps({"result": "BLOCK", "release_authorized": False}))
        return 2
    print(json.dumps(result, sort_keys=True, ensure_ascii=True))
    return 0


if __name__ == "__main__":
    sys.exit(main())
