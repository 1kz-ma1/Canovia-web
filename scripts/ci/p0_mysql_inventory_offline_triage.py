#!/usr/bin/env python3
"""Offline P0 MySQL incident-schema triage from manually sanitized statuses.

NO database/network access. The operator populates status values from the
reviewed SELECT-only inventory on an independently verified MySQL connection.
The tool never prints untrusted input, credentials or database identifiers.
Every outcome requires further human review; none authorizes a release.
"""

import argparse
import json
from pathlib import Path
import re
import sys

CORE_TABLES = (
    "migrations", "users", "intelligence_state_snapshots", "learning_answer_events",
)
DECISION = "intelligence_decision_traces"
ADJUSTMENT = "learning_answer_evaluation_adjustments"
TABLES = CORE_TABLES + (DECISION, ADJUSTMENT)

COLUMNS = {
    DECISION: (
        "id", "user_id", "plan_id", "intelligence_state_snapshot_id",
        "domain", "scope_type", "scope_id", "state_reference",
        "state_fingerprint", "readiness_fingerprint", "readiness_score",
        "readiness_level", "readiness_confidence", "readiness_components",
        "readiness_gaps", "readiness_metadata", "decision_reference",
        "decision_type", "reason_code", "decision_summary",
        "decision_confidence", "input_fingerprint", "decision_reasons",
        "decision_metadata", "metadata", "created_at", "updated_at",
    ),
    ADJUSTMENT: (
        "id", "learning_answer_event_id", "user_id", "reason",
        "effect", "actor_token", "created_at",
    ),
}
# These names are status report identifiers, not necessarily the physical
# MySQL name: decision user/plan FKs can use legacy Laravel auto-generated names.
FOREIGN_KEYS = {
    DECISION: ("idt_user_fk", "idt_plan_fk", "idt_snapshot_fk"),
    ADJUSTMENT: ("laea_answer_event_fk", "laea_user_fk"),
}
INDEXES = {
    DECISION: (
        "intelligence_decision_traces_domain_index",
        "intelligence_decision_traces_state_reference_index",
        "intelligence_decision_traces_state_fingerprint_index",
        "intelligence_decision_traces_readiness_fingerprint_index",
        "intelligence_decision_traces_decision_reference_unique",
        "intelligence_decision_traces_decision_type_index",
        "intelligence_decision_traces_reason_code_index",
        "intelligence_decision_traces_input_fingerprint_index",
        "intelligence_decision_scope_created_idx",
        "intelligence_decision_plan_domain_idx",
    ),
    ADJUSTMENT: ("learning_eval_adjustment_event_unique",),
}
MIGRATIONS = {
    DECISION: "2026_10_04_000200_create_intelligence_decision_traces_table",
    ADJUSTMENT: "2026_10_08_230000_create_learning_answer_evaluation_adjustments",
}
RECONCILE_MIGRATION = "2026_10_05_000400_reconcile_intelligence_schema_constraints"
STATUS_ALLOWED = {
    "tables": {"PRESENT", "MISSING", "UNVERIFIED"},
    "columns": {"PRESENT", "MISSING", "UNVERIFIED"},
    "foreign_keys": {"PASS", "MISSING", "MISMATCH", "UNVERIFIED"},
    "indexes": {"PASS", "MISSING", "MISMATCH", "UNVERIFIED"},
    "migrations": {"APPLIED", "PENDING", "UNVERIFIED"},
}
RESULTS = {"BLOCK", "REVIEW_REQUIRED"}


def expected_keys() -> dict[str, set[str]]:
    return {
        "tables": set(TABLES),
        "columns": {f"{table}.{column}" for table, columns in COLUMNS.items()
                    for column in columns},
        "foreign_keys": {key for names in FOREIGN_KEYS.values() for key in names},
        "indexes": {key for names in INDEXES.values() for key in names},
        "migrations": set(MIGRATIONS.values()) | {RECONCILE_MIGRATION},
    }


def template() -> dict:
    """Unverified by default. Never generate an apparently green report."""
    return {
        "mysql_version": "UNVERIFIED",
        "connection": "UNVERIFIED",
        **{
            group: {name: "UNVERIFIED" for name in sorted(names)}
            for group, names in expected_keys().items()
        },
    }


def decision(result: str, *codes: str) -> dict:
    assert result in RESULTS
    return {
        "result": result,
        "codes": list(dict.fromkeys(codes)),
        "release_authorized": False,
        "production_database_modified": False,
    }


def classify(snapshot: object) -> dict:
    """Fail closed for malformed, partial, unsupported or inconsistent evidence."""
    if not isinstance(snapshot, dict):
        return decision("BLOCK", "INVALID_INVENTORY")
    groups = expected_keys()
    if set(snapshot) != (set(groups) | {"mysql_version", "connection"}):
        return decision("BLOCK", "INVALID_INVENTORY")
    if snapshot["connection"] not in ("SCHEMA_SELECTED", "NO_SCHEMA_SELECTED", "UNVERIFIED"):
        return decision("BLOCK", "INVALID_INVENTORY")
    version = snapshot["mysql_version"]
    if not isinstance(version, str) or len(version) > 64:
        return decision("BLOCK", "INVALID_INVENTORY")

    for group, names in groups.items():
        values = snapshot[group]
        if not isinstance(values, dict) or set(values) != names:
            return decision("BLOCK", "INVALID_INVENTORY")
        if any(not isinstance(s, str) or s not in STATUS_ALLOWED[group]
               for s in values.values()):
            return decision("BLOCK", "INVALID_INVENTORY")

    if (snapshot["connection"] == "UNVERIFIED"
        or version == "UNVERIFIED"
        or any(v == "UNVERIFIED" for group in groups
               for v in snapshot[group].values())):
        return decision("BLOCK", "EVIDENCE_INCOMPLETE")
    if snapshot["connection"] != "SCHEMA_SELECTED":
        return decision("BLOCK", "SCHEMA_NOT_SELECTED")
    if not re.match(r"^8\.\d+\.\d+(?:[-+].*)?$", version) or "mariadb" in version.lower():
        return decision("BLOCK", "MYSQL_ENGINE_REVIEW_REQUIRED")
    if any(snapshot["tables"][t] != "PRESENT" for t in CORE_TABLES):
        return decision("BLOCK", "CORE_TABLE_MISSING")

    blocking, reviews = [], []
    if (snapshot["migrations"][RECONCILE_MIGRATION] == "APPLIED"
            and snapshot["migrations"][MIGRATIONS[DECISION]] == "PENDING"):
        blocking.append("MIGRATION_LEDGER_ORDER_INCONSISTENT")

    for table in (DECISION, ADJUSTMENT):
        state = snapshot["tables"][table]
        ledger = snapshot["migrations"][MIGRATIONS[table]]
        columns = [snapshot["columns"][f"{table}.{col}"] for col in COLUMNS[table]]
        constraints = (
            [snapshot["foreign_keys"][key] for key in FOREIGN_KEYS[table]]
            + [snapshot["indexes"][key] for key in INDEXES[table]]
        )
        label = "TRACE" if table == DECISION else "ADJUSTMENT"
        if "MISMATCH" in constraints:
            blocking.append(f"{label}_CONSTRAINT_MISMATCH")
        if state == "MISSING":
            if ledger == "APPLIED":
                blocking.append(f"{label}_APPLIED_TABLE_MISSING")
            elif any(x != "MISSING" for x in columns + constraints):
                blocking.append(f"{label}_ABSENT_TABLE_METADATA_INCONSISTENT")
            else:
                reviews.append(f"{label}_PENDING_CREATE_REVIEW")
        else:
            if any(c != "PRESENT" for c in columns):
                blocking.append(f"{label}_PARTIAL_COLUMNS")
            if any(c == "MISSING" for c in constraints):
                if ledger == "APPLIED":
                    # Edited historical migrations do NOT replay if applied.
                    blocking.append(f"{label}_APPLIED_CONSTRAINT_DRIFT")
                else:
                    reviews.append(f"{label}_PENDING_REPAIR_REVIEW")
            elif ledger == "PENDING":
                reviews.append(f"{label}_PENDING_LEDGER_REPLAY_REVIEW")

    if blocking:
        return decision("BLOCK", *blocking)
    if reviews:
        return decision("REVIEW_REQUIRED", *reviews)
    return decision("REVIEW_REQUIRED", "TARGETED_METADATA_MATCH_NOT_RELEASE")


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    group = parser.add_mutually_exclusive_group(required=True)
    group.add_argument("--template", action="store_true",
                       help="Print a synthetic UNVERIFIED template only.")
    group.add_argument("--report", metavar="SANITIZED_STATUS_JSON",
                       help="Read an operator-created sanitized status file.")
    args = parser.parse_args()
    if args.template:
        print(json.dumps(template(), ensure_ascii=True, indent=2))
        return 0

    try:
        path = Path(args.report)
        if not path.is_file() or path.stat().st_size > 16384:
            raise ValueError("invalid input")
        snapshot = json.loads(path.read_text(encoding="utf-8"))
        outcome = classify(snapshot)
    except (ValueError, OSError, UnicodeError, TypeError):
        outcome = decision("BLOCK", "INVALID_INVENTORY")
    print(json.dumps(outcome, sort_keys=True))
    return 0 if outcome["result"] == "REVIEW_REQUIRED" else 2


if __name__ == "__main__":
    sys.exit(main())
