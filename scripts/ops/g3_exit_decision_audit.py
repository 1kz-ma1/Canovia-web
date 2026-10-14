#!/usr/bin/env python3
"""G3 R31 pinned repository-contract exit audit; NEVER provider attestation.

This checks only version-controlled source at a specified commit. It does
not authenticate the owner, query CI runs, read Aiven, contact a cloud,
or authorize a migration, cleanup, retry, deletion or deployment.
"""
from __future__ import annotations

import argparse
import json
from pathlib import Path
import re
import subprocess
import sys

SHA = re.compile(r"[0-9a-f]{40}\Z")
MIGRATIONS = (
    "database/migrations/2026_10_14_100000_create_private_file_cleanup_entries.php",
    "database/migrations/2026_10_14_120000_create_private_file_path_fences.php",
    "database/migrations/2026_10_14_130000_create_private_file_upload_intents.php",
    "database/migrations/2026_10_14_140000_create_private_file_generation_evidence.php",
    "database/migrations/2026_10_14_150000_create_private_file_write_attempts.php",
)
CONFIG_FLAGS = (
    "CANOVIA_PRIVATE_FILE_SYNTHETIC_WRITE_PROVENANCE_ENABLED",
    "CANOVIA_PRIVATE_FILE_PROVIDER_ATTESTATION_BOUNDARY_ENABLED",
    "CANOVIA_PRIVATE_FILE_PROVIDER_EVIDENCE_ADMISSION_ENABLED",
    "CANOVIA_PRIVATE_FILE_TRUST_REPLAY_PREFLIGHT_ENABLED",
)
TESTS = (
    "PrivateFileSyntheticWriteProvenanceG3Test.php",
    "PrivateFileProviderAttestationBoundaryG3Test.php",
    "PrivateFileProviderEvidenceAdmissionG3Test.php",
    "PrivateFileTrustReplayPreflightG3Test.php",
)
DOCS = (
    "docs/development/G3_PROVIDER_EVIDENCE_HANDOFF_R27_2026_10_14.md",
    "docs/development/G3_PROVIDER_COLLECTION_PLAN_R28_2026_10_14.md",
    "docs/development/G3_DISPOSABLE_APPROVAL_PACKET_R29_2026_10_14.md",
    "docs/development/G3_OWNER_SCOPE_CHECKLIST_R30_2026_10_14.md",
)
SCRIPTS = (
    ("scripts/ops/g3_provider_evidence_inventory.py", "MANUAL_REVIEW_ONLY"),
    ("scripts/ops/g3_provider_collection_plan.py", "PLAN_REVIEW_ONLY"),
    ("scripts/ops/g3_disposable_approval_packet.py", "OWNER_DECISION_REQUIRED"),
    ("scripts/ops/g3_owner_scope_review.py", "OWNER_CHECKLIST_PENDING"),
)
STATES = (
    "migration_sources",
    "test_only_provider_gates",
    "independent_verifier_unavailable",
    "provider_ci_test_references",
    "offline_review_scripts",
    "offline_review_documents",
    "g3_open_acknowledged",
)


def read_at_commit(repo: Path, sha: str, path: str) -> str | None:
    try:
        row = subprocess.run(
            ["git", "-C", str(repo), "ls-tree", sha, "--", path],
            timeout=8, capture_output=True, check=False,
        )
        if row.returncode or not row.stdout.startswith(b"100644 blob "):
            return None
        if not row.stdout.rstrip(b"\n").endswith(b"\t" + path.encode("ascii")):
            return None
        size = subprocess.run(
            ["git", "-C", str(repo), "cat-file", "-s", sha + ":" + path],
            timeout=8, capture_output=True, check=False,
        )
        if size.returncode:
            return None
        count = int(size.stdout.strip())
        if count < 1 or count > 350000:
            return None
        data = subprocess.run(
            ["git", "-C", str(repo), "show", sha + ":" + path],
            timeout=8, capture_output=True, check=False,
        )
        if data.returncode or len(data.stdout) != count:
            return None
        return data.stdout.decode("utf-8")
    except (OSError, subprocess.TimeoutExpired, ValueError, UnicodeError):
        return None


def decision(states: dict[str, str], valid_commit: bool) -> dict:
    local_complete = valid_commit and all(
        state == "SOURCE_PRESENT_UNATTESTED" for state in states.values()
    )
    return {
        "result": "HOLD_EXTERNAL_PROOF_REQUIRED" if local_complete
                  else "HOLD_LOCAL_CONTRACT_DRIFT",
        "pinned_commit_resolved": valid_commit,
        "local_contracts": states,
        "next_action": "OWNER_DECISION_DISPOSABLE_TEST_OR_KEEP_ON_HOLD",
        "synthetic_contract_expansion_recommended": False,
        "provider_attested": False,
        "real_replay_verified": False,
        "external_writer_fencing_verified": False,
        "generation_safe_delete_verified": False,
        "crash_recovery_verified": False,
        "owner_go_verified": False,
        "production_migrations_approved": False,
        "g3_exit_accepted": False,
        "retry_authorized": False,
        "delete_authorized": False,
        "production_release_authorized": False,
        "provider_calls_executed": False,
        "production_modified": False,
    }


def evaluate(repo: Path, sha: str) -> dict:
    states = {key: "MISSING_OR_DRIFTED" for key in STATES}
    if type(sha) is not str or not SHA.fullmatch(sha):
        return decision(states, False)
    try:
        check = subprocess.run(
            ["git", "-C", str(repo), "cat-file", "-t", sha],
            timeout=8, capture_output=True, check=False,
        )
        if check.returncode or check.stdout.strip() != b"commit":
            return decision(states, False)
    except (OSError, subprocess.TimeoutExpired):
        return decision(states, False)

    get = lambda path: read_at_commit(repo, sha, path)
    present = lambda path: get(path) is not None
    if all(present(path) for path in MIGRATIONS):
        states["migration_sources"] = "SOURCE_PRESENT_UNATTESTED"

    config = get("config/canovia.php")
    if config is not None and all(
        re.search(r"env\('" + re.escape(flag) + r"',\s*false\)", config)
        for flag in CONFIG_FLAGS
    ):
        states["test_only_provider_gates"] = "SOURCE_PRESENT_UNATTESTED"

    verifier = get("app/Services/PrivateFileUnavailableProviderVerifier.php")
    if verifier is not None and all(part in verifier for part in (
        "final class PrivateFileUnavailableProviderVerifier",
        "'verdict' => 'independent_verifier_unavailable'",
        "'provider_authenticated' => false",
        "'generation_write_bound' => false",
    )):
        states["independent_verifier_unavailable"] = "SOURCE_PRESENT_UNATTESTED"

    pg = get(".github/workflows/adaptive-context-g3-private-file-lifecycle.yml")
    mysql = get(".github/workflows/adaptive-context-g3-private-file-mysql.yml")
    if pg is not None and mysql is not None and all(
        name in pg and name in mysql and
        present("tests/Feature/" + name) for name in TESTS
    ):
        states["provider_ci_test_references"] = "SOURCE_PRESENT_UNATTESTED"

    if all((code := get(path)) is not None
           and state in code and "return 2" in code and
           present("tests/ci/test_" + Path(path).stem + ".py")
           for path, state in SCRIPTS):
        states["offline_review_scripts"] = "SOURCE_PRESENT_UNATTESTED"

    if all((doc := get(path)) is not None and "G3 OPEN" in doc
           for path in DOCS):
        states["offline_review_documents"] = "SOURCE_PRESENT_UNATTESTED"

    audit = get("docs/development/ADAPTIVE_CONTEXT_PIPELINE_V05_G3_EXIT_AUDIT_2026_10_12.md")
    if audit is not None and "G3 OPEN" in audit and "Five" in audit[-9000:]:
        states["g3_open_acknowledged"] = "SOURCE_PRESENT_UNATTESTED"

    return decision(states, True)


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--repo", type=Path, default=Path("."))
    parser.add_argument("--sha", required=True)
    args = parser.parse_args()
    print(json.dumps(evaluate(args.repo, args.sha), sort_keys=True))
    # Never a zero exit or an affirmative release gate.
    return 2


if __name__ == "__main__":
    sys.exit(main())
