#!/usr/bin/env python3
"""Bounded, read-only GitHub Actions runtime audit with an offline test mode.

Live mode calls only GitHub REST GET through an existing gh CLI login.
Never prints raw GitHub responses, secrets, log contents, or local paths.
All estimates are observed job runtime, NOT billable Actions minutes.
"""
from __future__ import annotations

import argparse
from datetime import datetime
import json
import math
from pathlib import Path
import re
import statistics
import subprocess
import sys

REPO = "1kz-ma1/Canovia-web"
MAX_RUNS = 30
MAX_BYTES = 3_000_000
SETUP_STEPS = (
    ("composer_install", "composer install"),
    ("npm_install", "npm ci"),
    ("frontend_build", "npm run build"),
    ("php_setup", "shivammathur/setup-php@"),
    ("node_setup", "actions/setup-node@"),
)


class InvalidEvidence(Exception):
    pass


def api(path: str) -> dict:
    """The path is made exclusively from constants and checked numeric run IDs."""
    try:
        proc = subprocess.run(
            ["gh", "api", "--method", "GET", path],
            capture_output=True, check=False, timeout=30,
        )
        if proc.returncode != 0 or len(proc.stdout) > MAX_BYTES:
            raise InvalidEvidence()
        return json.loads(proc.stdout)
    except (OSError, subprocess.TimeoutExpired, UnicodeError, ValueError) as exc:
        raise InvalidEvidence() from exc


def when(value: str) -> datetime:
    if not isinstance(value, str):
        raise InvalidEvidence()
    try:
        d = datetime.fromisoformat(value.replace("Z", "+00:00"))
    except ValueError as exc:
        raise InvalidEvidence() from exc
    if d.tzinfo is None:
        raise InvalidEvidence()
    return d


def seconds(a: str, b: str) -> int:
    elapsed = (when(b) - when(a)).total_seconds()
    if elapsed < 0 or elapsed > 86400:
        raise InvalidEvidence()
    return int(round(elapsed))


def p90(numbers: list[int]) -> int:
    return sorted(numbers)[max(0, math.ceil(len(numbers) * 0.9) - 1)]


def safe_name(value: object) -> str:
    if not isinstance(value, str) or not (1 <= len(value) <= 120):
        return "UNNAMED"
    if not re.fullmatch(r"[a-zA-Z0-9_. /():,@+-]+", value):
        return "UNNAMED"
    return value


def compile_report(runs: object, jobs_by_run: object, event: str) -> dict:
    if event not in ("pull_request", "push"):
        raise InvalidEvidence()
    if not isinstance(runs, list) or not (1 <= len(runs) <= MAX_RUNS):
        raise InvalidEvidence()
    if not isinstance(jobs_by_run, dict):
        raise InvalidEvidence()
    seen: set[int] = set()
    samples: dict[str, list[int]] = {}
    setup: dict[str, list[int]] = {k: [] for k, _ in SETUP_STEPS}
    zero_job_runs = 0
    completed_runs = 0
    measured_jobs = 0
    unmeasured_jobs = 0
    for run in runs:
        if not isinstance(run, dict) or type(run.get("id")) is not int:
            raise InvalidEvidence()
        run_id = run["id"]
        if run_id <= 0 or run_id in seen or run.get("event") != event:
            raise InvalidEvidence()
        seen.add(run_id)
        if run.get("status") != "completed":
            continue
        completed_runs += 1
        page = jobs_by_run.get(str(run_id))
        if not isinstance(page, dict):
            raise InvalidEvidence()
        count, jobs = page.get("total_count"), page.get("jobs")
        if (type(count) is not int or count < 0 or count > 100
            or not isinstance(jobs, list) or len(jobs) != count):
            raise InvalidEvidence()
        if not jobs:
            zero_job_runs += 1
        for job in jobs:
            if not isinstance(job, dict) or job.get("run_id") not in (None, run_id):
                raise InvalidEvidence()
            if job.get("conclusion") != "success":
                unmeasured_jobs += 1
                continue
            if not job.get("started_at") or not job.get("completed_at"):
                unmeasured_jobs += 1
                continue
            duration = seconds(job["started_at"], job["completed_at"])
            workflow = safe_name(run.get("name"))
            samples.setdefault(workflow, []).append(duration)
            measured_jobs += 1
            if not isinstance(job.get("steps", []), list):
                raise InvalidEvidence()
            for step in job.get("steps", []):
                if not isinstance(step, dict):
                    raise InvalidEvidence()
                if step.get("conclusion") != "success":
                    continue
                title = step.get("name", "")
                if not isinstance(title, str):
                    raise InvalidEvidence()
                for group, match in SETUP_STEPS:
                    if match in title:
                        if step.get("started_at") and step.get("completed_at"):
                            setup[group].append(seconds(step["started_at"], step["completed_at"]))
                        break
    groups = [
        {"workflow": name, "successful_job_samples": len(values),
         "median_job_seconds": int(round(statistics.median(values))),
         "p90_job_seconds": p90(values)}
        for name, values in sorted(samples.items())
    ]
    groups.sort(key=lambda x: (-x["median_job_seconds"], x["workflow"]))
    repeated = [
        {"step_type": key, "successful_step_samples": len(values),
         "observed_total_seconds": sum(values),
         "median_step_seconds": int(round(statistics.median(values)))}
        for key, values in setup.items() if values
    ]
    repeated.sort(key=lambda x: -x["observed_total_seconds"])
    return {
        "result": "REVIEW_REQUIRED" if measured_jobs else "INSUFFICIENT_EVIDENCE",
        "source": "GitHub Actions run/job metadata (observed, not billable)",
        "event": event, "sampled_runs": len(runs),
        "completed_runs": completed_runs, "zero_job_runs": zero_job_runs,
        "measured_successful_jobs": measured_jobs, "unmeasured_or_failed_jobs": unmeasured_jobs,
        "workflow_runtime": groups, "observed_setup_steps": repeated,
        "test_coverage_equivalence_verified": False,
        "ci_changes_authorized": False, "release_authorized": False,
    }


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    mode = parser.add_mutually_exclusive_group(required=True)
    mode.add_argument("--live", action="store_true", help="Read metadata from gh api GET only")
    mode.add_argument("--snapshot", type=Path, help="Offline synthetic or saved JSON snapshot")
    parser.add_argument("--event", choices=("pull_request", "push"), default="pull_request")
    parser.add_argument("--limit", type=int, default=20)
    args = parser.parse_args()
    try:
        if not (1 <= args.limit <= MAX_RUNS):
            raise InvalidEvidence()
        if args.live:
            root = api(f"repos/{REPO}/actions/runs?event={args.event}&per_page={args.limit}")
            runs = root.get("workflow_runs")
            if not isinstance(runs, list) or not runs or len(runs) > args.limit:
                raise InvalidEvidence()
            pages = {}
            for run in runs:
                if not isinstance(run, dict) or type(run.get("id")) is not int or run["id"] <= 0:
                    raise InvalidEvidence()
                if run.get("status") == "completed":
                    pages[str(run["id"])] = api(
                        f"repos/{REPO}/actions/runs/{run['id']}/jobs?per_page=100"
                    )
        else:
            path = args.snapshot
            if path is None or path.is_symlink() or not path.is_file() or path.stat().st_size > MAX_BYTES:
                raise InvalidEvidence()
            root = json.loads(path.read_text(encoding="utf-8"))
            runs, pages = root["workflow_runs"], root["jobs_by_run"]
        report = compile_report(runs, pages, args.event)
    except (InvalidEvidence, OSError, UnicodeError, ValueError, KeyError, TypeError):
        report = {"result": "BLOCK", "code": "INCOMPLETE_OR_INVALID_CI_EVIDENCE",
                  "ci_changes_authorized": False, "release_authorized": False}
    print(json.dumps(report, sort_keys=True, ensure_ascii=True))
    return 0 if report["result"] == "REVIEW_REQUIRED" else 2


if __name__ == "__main__":
    sys.exit(main())
