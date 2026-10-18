#!/usr/bin/env python3
"""Offline exact94->114 source/ledger/physical-metadata review. Never connects.

Reference hashes are generated ONLY from labelled disposable MySQL by the
integration rehearsal. Output contains fixed codes/counts, never input values.
An accepted match always remains REVIEW_REQUIRED, not release authorization.
"""
from __future__ import annotations

import argparse
import hashlib
import json
from pathlib import Path
import re
import subprocess
import sys

sys.dont_write_bytecode = True
from p0_mysql_full_ledger_offline_check import InvalidLedger, parse_full_ledger

BASE = '15abdc9de6f24b5250fb255c579d1aa55facaf1a'
ROOT = Path(__file__).resolve().parents[2]
WIDTHS = {'table': 5, 'column': 9, 'index': 10, 'foreign_key': 10, 'check': 5, 'trigger': 7}
MAX_BYTES = 8 * 1024 * 1024


def git(root: Path, *args: str) -> str:
    return subprocess.check_output(['git', '-C', str(root), *args],
                                   stderr=subprocess.DEVNULL, text=True, timeout=20).strip()


def source(root: Path, sha: str) -> dict:
    if not re.fullmatch('[0-9a-f]{40}', sha):
        raise InvalidLedger()
    if git(root, 'rev-parse', sha + '^{commit}') != sha:
        raise InvalidLedger()
    git(root, 'merge-base', '--is-ancestor', BASE, sha)

    def tree(commit: str) -> dict:
        found = {}
        for row in git(root, 'ls-tree', '-r', commit, 'database/migrations').splitlines():
            meta, path = row.split('\t')
            if path.endswith('.php'):
                found[Path(path).stem] = meta.split()[2]
        return found

    old, new = tree(BASE), tree(sha)
    if len(old) != 94 or len(new) != 114 or len(new.keys() - old.keys()) != 20:
        raise InvalidLedger()
    if any(new.get(k) != v for k, v in old.items()):
        raise InvalidLedger()
    seal = hashlib.sha256(json.dumps(new, sort_keys=True).encode()).hexdigest()
    return {'baseline': old, 'candidate': new, 'migration_seal': seal}


def snapshot(raw: str) -> dict:
    if not raw or len(raw.encode()) > MAX_BYTES or not raw.isascii() or '\r' in raw or '\0' in raw or not raw.endswith('\n'):
        raise InvalidLedger()
    rows = {key: [] for key in WIDTHS}
    engine = None
    for line in raw.splitlines():
        cells = line.split('\t')
        if cells[0] == 'engine':
            if len(cells) != 2 or engine is not None or cells[1] not in {'8.0', '8.4'}:
                raise InvalidLedger()
            engine = cells[1]
            continue
        if cells[0] not in WIDTHS or len(cells) != WIDTHS[cells[0]]:
            raise InvalidLedger()
        if any(any(ord(c) < 32 or ord(c) == 127 for c in v) for v in cells):
            raise InvalidLedger()
        rows[cells[0]].append(tuple(cells))
    if engine is None or not rows['table'] or not rows['column'] or not rows['index']:
        raise InvalidLedger()
    result = {}
    for key, entries in rows.items():
        if len(entries) != len(set(entries)):
            raise InvalidLedger()
        result[key] = {'count': len(entries), 'sha256': hashlib.sha256(
            json.dumps(sorted(entries), separators=(',', ':')).encode()).hexdigest()}
    return {'engine': engine, 'sections': result}


def outcome(code: str, result: str = 'BLOCK', **counts) -> dict:
    return dict(result=result, code=code, release_authorized=False,
                production_database_modified=False, real_restore_verified=False,
                runtime_ddl_privileges_verified=False, trigger_visibility_independently_verified=False,
                **counts)


def compare(src: dict, ledger: dict, physical: dict, reference: dict) -> dict:
    if reference.get('kind') != 'DISPOSABLE_SOURCE_REFERENCE_ONLY' or reference.get('baseline_sha') != BASE:
        return outcome('REFERENCE_UNVERIFIED')
    if reference.get('migration_seal') != src['migration_seal'] or reference.get('engine') != physical['engine']:
        return outcome('SOURCE_OR_ENGINE_REFERENCE_MISMATCH')
    old, new, applied = set(src['baseline']), set(src['candidate']), set(ledger)
    if applied - new:
        return outcome('UNKNOWN_APPLIED_MIGRATION')
    if applied == old:
        stage, code = 'baseline94', 'EXACT94_PENDING20_METADATA_REVIEW_ONLY'
    elif applied == new:
        stage, code = 'candidate114', 'EXACT114_METADATA_REVIEW_ONLY'
    else:
        return outcome('PARTIAL_OR_MISSING_HISTORICAL_LEDGER', applied_count=len(applied))
    expected = reference.get(stage, {}).get('sections')
    if not isinstance(expected, dict) or set(expected) != set(WIDTHS):
        return outcome('REFERENCE_INCOMPLETE')
    mismatches = [key for key in WIDTHS if expected[key] != physical['sections'][key]]
    if mismatches:
        # Missing trigger metadata may be invisible rather than absent. Never
        # grant TRIGGER to the narrow auditor simply to satisfy this check.
        return outcome('TRIGGER_ABSENT_OR_NOT_VISIBLE' if mismatches == ['trigger']
                       else 'PARTIAL_DDL_OR_UNEXPECTED_PHYSICAL_SCHEMA',
                       mismatched_section_count=len(mismatches), applied_count=len(applied))
    return outcome(code, 'REVIEW_REQUIRED', expected_count=114, applied_count=len(applied),
                   pending_count=len(new - applied), requires_privileged_trigger_review=True,
                   requires_runtime_grants_binlog_review=True)


def private_read(path: Path) -> str:
    if path.is_symlink() or not path.is_file() or path.stat().st_size > MAX_BYTES:
        raise InvalidLedger()
    return path.read_bytes().decode('ascii')


def main() -> int:
    p = argparse.ArgumentParser(description=__doc__)
    p.add_argument('--candidate-sha', required=True)
    p.add_argument('--ledger', type=Path, required=True)
    p.add_argument('--metadata', type=Path, required=True)
    args = p.parse_args()
    try:
        src = source(ROOT, args.candidate_sha)
        physical = snapshot(private_read(args.metadata))
        ref = ROOT / 'scripts/ci/integrated_release20_reference' / (physical['engine'] + '.json')
        result = compare(src, parse_full_ledger(private_read(args.ledger)), physical,
                         json.loads(private_read(ref)))
    except (InvalidLedger, ValueError, KeyError, TypeError, OSError, subprocess.SubprocessError):
        result = outcome('SOURCE_OR_PRIVATE_INPUT_REJECTED')
    print(json.dumps(result, sort_keys=True))
    return 0 if result['result'] == 'REVIEW_REQUIRED' else 2


if __name__ == '__main__':
    sys.exit(main())
