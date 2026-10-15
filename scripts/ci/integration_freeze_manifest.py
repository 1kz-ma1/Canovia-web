#!/usr/bin/env python3
"""Exact local Git source inventory. No network, DB access or release approval."""
from __future__ import annotations

import argparse
import json
from pathlib import Path
import re
import subprocess


def git(root: Path, *args: str) -> str:
    return subprocess.check_output(
        ['git', *args], cwd=root, text=True, stderr=subprocess.DEVNULL,
        timeout=30,
    ).strip()


def classification(path: str) -> dict:
    if path.startswith('docs/future/'):
        return dict(area='Future', state='FUTURE_NOT_IMPLEMENTATION',
                    test='not a runtime acceptance claim', rollback='no runtime effect',
                    exposure='NOT_APPROVED')
    if path.startswith('database/migrations/'):
        return dict(area='Platform/DB', state='SOURCE_ONLY',
                    test='exact-SHA MySQL 8.0/8.4 upgrade, retry and preservation',
                    rollback='forward recovery; never infer migration down safety', exposure='OWNER_GATE')
    if path.startswith(('.github/', 'docker/', 'scripts/')):
        return dict(area='Platform/Release', state='SOURCE_ONLY',
                    test='applicable workflow and offline negative controls',
                    rollback='revert code; external changes require separate approval', exposure='INTERNAL')
    lower = path.lower()
    area = next((a for term, a in [('mcp', 'MCP'), ('learningcontext', 'Intelligence'),
                ('learning_context', 'Intelligence'), ('study', 'Learning'),
                ('practice', 'Learning'), ('development', 'Development'),
                ('github', 'Development'), ('career', 'Expansion')] if term in lower), 'Shared/Experience')
    return dict(area=area, state='SOURCE_ONLY', test='owner-scoped targeted tests and release regression',
                rollback='retain data; lower exposure or revert compatible code', exposure='REVIEW_REQUIRED')


def manifest(root: Path, base: str, candidate: str) -> dict:
    if not all(re.fullmatch('[0-9a-f]{40}', value) for value in (base, candidate)):
        raise ValueError('Use full immutable commit SHAs, not moving refs')
    if git(root, 'rev-parse', '--is-shallow-repository') != 'false':
        raise ValueError('Full local Git history required')
    for value in (base, candidate):
        if git(root, 'rev-parse', '--verify', value + '^{commit}') != value:
            raise ValueError('Commit identity mismatch')
    git(root, 'merge-base', '--is-ancestor', base, candidate)
    paths = git(root, '-c', 'core.quotePath=false', 'diff', '--name-status', '--no-renames',
                base, candidate).splitlines()
    rows = []
    for line in paths:
        status, path = line.split('\t', 1)
        blob = None if status == 'D' else git(root, 'rev-parse', candidate + ':' + path)
        rows.append(dict(path=path, change=status, candidate_blob=blob,
                         dependency='review against shared auth/session/publication contracts',
                         owner_assignment='PATH_CLASSIFICATION_REQUIRES_REVIEW', **classification(path)))
    migration_rows = [row for row in rows if row['path'].startswith('database/migrations/')]
    def migrations(sha: str) -> list[str]:
        return [p for p in git(root, 'ls-tree', '-r', '--name-only', sha,
                               'database/migrations').splitlines() if p.endswith('.php')]
    return dict(schema_version=1, base_sha=base, candidate_sha=candidate,
                ahead_commits=int(git(root, 'rev-list', '--count', base + '..' + candidate)),
                changed_path_count=len(rows), changed_paths=rows,
                baseline_migration_count=len(migrations(base)),
                candidate_migration_count=len(migrations(candidate)),
                migration_changes=migration_rows,
                production_pending_migrations='UNKNOWN_REQUIRES_SEPARATE_OPERATOR_AUDIT',
                live_sha_verified=False, ci_verified=False, device_verified=False,
                release_authorized=False, verdict='SOURCE_INVENTORY_ONLY_PRODUCTION_NO_GO')


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--repo', type=Path, default=Path(__file__).resolve().parents[2])
    parser.add_argument('--base', required=True)
    parser.add_argument('--candidate', required=True)
    args = parser.parse_args()
    try:
        result = manifest(args.repo, args.base, args.candidate)
    except (ValueError, OSError, subprocess.SubprocessError):
        print(json.dumps(dict(verdict='BLOCK_INVALID_OR_INCOMPLETE_GIT_SOURCE', release_authorized=False)))
        return 1
    print(json.dumps(result, ensure_ascii=False, indent=2))
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
