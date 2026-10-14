#!/usr/bin/env python3
"""Offline GitHub Actions run/job metadata triage. No job logs or API requests.

A failed workflow with no jobs cannot be diagnosed as a failing application test.
"""
from __future__ import annotations

import argparse
import json
from pathlib import Path
import sys

LIMIT = 2_000_000


class BadEvidence(Exception):
    pass


def load(path: Path):
    try:
        if path.is_symlink() or not path.is_file() or path.stat().st_size > LIMIT:
            raise BadEvidence()
        return json.loads(path.read_text(encoding="utf-8"))
    except (OSError, UnicodeError, ValueError) as exc:
        raise BadEvidence() from exc


def triage(run: dict, job_page: dict) -> dict:
    base = {"result": "REVIEW_REQUIRED", "code": "EVIDENCE_INCOMPLETE", "release_authorized": False}
    if not isinstance(run, dict) or not isinstance(job_page, dict):
        return {**base, "result": "BLOCK", "code": "INVALID_INPUT"}
    status = run.get("status")
    conclusion = run.get("conclusion")
    run_id = run.get("id")
    total = job_page.get("total_count")
    jobs = job_page.get("jobs")
    if (type(run_id) is not int or run_id < 1 or status not in ("completed", "queued", "in_progress", "pending", "requested", "waiting")
        or type(total) is not int or total < 0 or not isinstance(jobs, list) or len(jobs) != total
        or total > 100 or any(not isinstance(j, dict) or (j.get("run_id") not in (None, run_id)) for j in jobs)):
        return {**base, "result": "BLOCK", "code": "INVALID_OR_INCOMPLETE_JOB_LIST"}
    if status != "completed":
        code = "WORKFLOW_STILL_PENDING"
    elif conclusion in ("failure", "timed_out", "action_required", "startup_failure") and total == 0:
        code = "NO_JOBS_WORKFLOW_SETUP_OR_POLICY_REVIEW"
    elif any(j.get("conclusion") in ("failure", "timed_out") for j in jobs):
        code = "EXECUTED_JOB_FAILURE"
    elif conclusion in ("cancelled", "skipped", "neutral"):
        code = "WORKFLOW_NOT_SUCCESSFULLY_VERIFIED"
    elif total == 0:
        code = "NO_JOBS_NO_TEST_EVIDENCE"
    elif conclusion == "success" and all(j.get("conclusion") == "success" for j in jobs):
        code = "ALL_REPORTED_JOBS_SUCCESS_NOT_RELEASE_GO"
    else:
        code = "WORKFLOW_OR_JOBS_REQUIRE_REVIEW"
    return {"result": "REVIEW_REQUIRED", "code": code, "run_id": run_id,
            "job_count": total, "job_failure_count": sum(j.get("conclusion") in ("failure", "timed_out") for j in jobs),
            "release_authorized": False, "root_cause_verified": False}


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--run-json", type=Path, required=True)
    parser.add_argument("--jobs-json", type=Path, required=True)
    args = parser.parse_args()
    try:
        result = triage(load(args.run_json), load(args.jobs_json))
    except BadEvidence:
        result = {"result": "BLOCK", "code": "INPUT_FILE_UNAVAILABLE", "release_authorized": False}
    print(json.dumps(result, sort_keys=True))
    return 0 if result["result"] == "REVIEW_REQUIRED" else 2


if __name__ == "__main__":
    sys.exit(main())
