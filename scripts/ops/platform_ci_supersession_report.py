#!/usr/bin/env python3
"""Read-only CI supersession evidence (elapsed time != billed/avoided minutes).

Only completed runs with fully enumerated jobs contribute time. Pairing requires
same GitHub workflow ID, same main-target PR number and different head SHAs.
No API mutations, checkout, log access, credential arguments or release GO.
"""
from __future__ import annotations

import argparse
import json
from pathlib import Path
import re
import statistics
import sys

try:
    from .platform_ci_runtime_report import api, InvalidEvidence, MAX_BYTES, MAX_RUNS, p90, seconds, safe_name, when
except ImportError:  # CLI invocation: python3 scripts/ops/platform_ci_supersession_report.py
    from platform_ci_runtime_report import api, InvalidEvidence, MAX_BYTES, MAX_RUNS, p90, seconds, safe_name, when


SHA = re.compile(r"[0-9a-f]{40}\Z")


def pr_number(run: dict) -> int | None:
    pulls = run.get("pull_requests")
    if not isinstance(pulls, list) or len(pulls) != 1:
        return None
    pr = pulls[0]
    if not isinstance(pr, dict) or type(pr.get("number")) is not int or pr["number"] < 1:
        return None
    base = pr.get("base")
    if not isinstance(base, dict) or base.get("ref") != "main":
        return None
    return pr["number"]


def summarize(runs: object, jobs_by_run: object) -> dict:
    if not isinstance(runs, list) or not 1 <= len(runs) <= MAX_RUNS or not isinstance(jobs_by_run, dict):
        raise InvalidEvidence()

    ids: set[int] = set()
    by_pr_workflow: dict[tuple[int, int], list[dict]] = {}
    observations: dict[int, dict] = {}
    start_latencies: list[int] = []
    cancelled_elapsed_all = 0
    jobs_cancelled = 0
    incomplete_run_identity = 0
    no_jobs = 0
    incomplete_job_timing = 0
    completed_runs = 0

    for run in runs:
        if not isinstance(run, dict):
            raise InvalidEvidence()
        rid, wid, sha = run.get("id"), run.get("workflow_id"), run.get("head_sha")
        if (type(rid) is not int or rid <= 0 or rid in ids or type(wid) is not int
            or wid <= 0 or not isinstance(sha, str) or not SHA.fullmatch(sha)
            or run.get("event") != "pull_request"):
            raise InvalidEvidence()
        ids.add(rid)
        timestamp = run.get("created_at")
        when(timestamp)
        if run.get("status") not in ("completed", "queued", "in_progress", "pending", "waiting", "requested"):
            raise InvalidEvidence()
        if run["status"] != "completed":
            continue

        completed_runs += 1
        page = jobs_by_run.get(str(rid))
        if not isinstance(page, dict):
            raise InvalidEvidence()
        count, jobs = page.get("total_count"), page.get("jobs")
        if (type(count) is not int or count < 0 or count > 100
            or not isinstance(jobs, list) or len(jobs) != count):
            raise InvalidEvidence()
        if count == 0:
            no_jobs += 1
        successful_jobs = 0
        cancelled_jobs = 0
        cancelled_seconds = 0
        timing_complete = True
        for job in jobs:
            if not isinstance(job, dict) or job.get("run_id") not in (None, rid):
                raise InvalidEvidence()
            conclusion = job.get("conclusion")
            if conclusion == "success":
                successful_jobs += 1
            if conclusion == "cancelled":
                cancelled_jobs += 1
                jobs_cancelled += 1
            a, b = job.get("started_at"), job.get("completed_at")
            if a is None and b is None:
                timing_complete = False
                incomplete_job_timing += 1
                continue
            if not isinstance(a, str) or not isinstance(b, str):
                timing_complete = False
                incomplete_job_timing += 1
                continue
            start_latencies.append(seconds(timestamp, a))
            elapsed = seconds(a, b)
            if conclusion == "cancelled":
                cancelled_seconds += elapsed
                cancelled_elapsed_all += elapsed

        if run.get("conclusion") != "cancelled" and cancelled_jobs:
            raise InvalidEvidence()
        identity = pr_number(run)
        if identity is None:
            incomplete_run_identity += 1
            continue
        entry = {
            "run_id": rid,
            "workflow": safe_name(run.get("name")),
            "sha": sha,
            "created_at": timestamp,
            "cancelled_run": run.get("conclusion") == "cancelled",
            "cancelled_jobs": cancelled_jobs,
            "cancelled_elapsed_seconds": cancelled_seconds,
            "complete_job_timing": timing_complete,
            "complete_success": bool(jobs) and successful_jobs == len(jobs) and run.get("conclusion") == "success",
        }
        by_pr_workflow.setdefault((wid, identity), []).append(entry)
        observations[rid] = entry

    confirmed_pairs = []
    candidate_pairs = 0
    for (_, number), sequence in sorted(by_pr_workflow.items()):
        # Old and new must be from distinct commits, not reruns of one commit.
        sequence.sort(key=lambda x: (when(x["created_at"]), x["run_id"]))
        for old, new in zip(sequence, sequence[1:]):
            if old["sha"] == new["sha"]:
                continue
            candidate_pairs += 1
            if (old["cancelled_run"] and old["cancelled_jobs"] > 0 and
                old["complete_job_timing"] and new["complete_success"]):
                confirmed_pairs.append({
                    "pr_number": number, "workflow": old["workflow"],
                    "old_run_id": old["run_id"], "new_run_id": new["run_id"],
                    "old_sha_prefix": old["sha"][:12], "new_sha_prefix": new["sha"][:12],
                    "old_cancelled_job_elapsed_seconds": old["cancelled_elapsed_seconds"],
                })

    return {
        "result": "REVIEW_REQUIRED" if completed_runs else "INSUFFICIENT_EVIDENCE",
        "source": "GitHub Actions metadata, sampled PR runs, job wall-clock only",
        "sampled_runs": len(runs), "completed_runs": completed_runs,
        "runs_without_jobs": no_jobs, "runs_without_main_pr_identity": incomplete_run_identity,
        "jobs_without_complete_timing": incomplete_job_timing,
        "observed_cancelled_job_count": jobs_cancelled,
        "observed_cancelled_job_seconds": cancelled_elapsed_all,
        "job_start_latency_samples": len(start_latencies),
        "median_start_latency_seconds": int(round(statistics.median(start_latencies))) if start_latencies else None,
        "p90_start_latency_seconds": p90(start_latencies) if start_latencies else None,
        "adjacent_revision_pairs_examined": candidate_pairs,
        "confirmed_supersession_pairs": len(confirmed_pairs),
        "confirmed_examples": confirmed_pairs,
        "snapshot_exhaustive": False, "billed_minutes_verified": False,
        "avoided_runtime_verified": False, "production_isolation_verified_by_live": False,
        "release_authorized": False, "ci_changes_authorized": False,
    }


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    source = parser.add_mutually_exclusive_group(required=True)
    source.add_argument("--live", action="store_true", help="Read GitHub run and job metadata via gh api GET")
    source.add_argument("--snapshot", type=Path, help="Offline synthetic run+jobs JSON")
    parser.add_argument("--limit", type=int, default=20)
    args = parser.parse_args()
    try:
        if not 1 <= args.limit <= MAX_RUNS:
            raise InvalidEvidence()
        if args.live:
            response = api(f"repos/1kz-ma1/Canovia-web/actions/runs?event=pull_request&per_page={args.limit}")
            runs = response.get("workflow_runs")
            if not isinstance(runs, list) or not runs or len(runs) > args.limit:
                raise InvalidEvidence()
            pages = {}
            for run in runs:
                if not isinstance(run, dict) or type(run.get("id")) is not int or run["id"] < 1:
                    raise InvalidEvidence()
                if run.get("status") == "completed":
                    pages[str(run["id"])] = api(
                        f"repos/1kz-ma1/Canovia-web/actions/runs/{run['id']}/jobs?per_page=100"
                    )
        else:
            path = args.snapshot
            if path is None or path.is_symlink() or not path.is_file() or path.stat().st_size > MAX_BYTES:
                raise InvalidEvidence()
            snap = json.loads(path.read_text(encoding="utf-8"))
            runs, pages = snap["workflow_runs"], snap["jobs_by_run"]
        report = summarize(runs, pages)
    except (InvalidEvidence, OSError, UnicodeError, ValueError, KeyError, TypeError):
        report = {"result": "BLOCK", "code": "INCOMPLETE_OR_INVALID_CI_EVIDENCE",
                  "release_authorized": False, "ci_changes_authorized": False}
    print(json.dumps(report, ensure_ascii=True, sort_keys=True))
    return 0 if report["result"] == "REVIEW_REQUIRED" else 2


if __name__ == "__main__":
    sys.exit(main())
