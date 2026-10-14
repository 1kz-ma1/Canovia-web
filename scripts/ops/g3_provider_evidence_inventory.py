#!/usr/bin/env python3
"""G3 R27 offline evidence inventory. No provider validation or authorization."""
from __future__ import annotations
import hashlib
import json
import re
import subprocess
from pathlib import Path

REQUIREMENTS = {
    'provider_identity_and_trust_root': 'security',
    'response_authenticity': 'security',
    'request_and_generation_binding': 'storage',
    'anti_replay_continuity': 'security',
    'bucket_versioning_and_iam': 'storage',
    'external_writer_fencing': 'storage',
    'generation_conditional_operations': 'storage',
    'crash_restart_recovery_test': 'platform',
    'cross_database_cleanup_reconciliation': 'platform',
    'key_rotation_and_revocation_test': 'security',
    'owner_go_and_rollout_plan': 'release',
}

SHA = re.compile(r'[0-9a-f]{40}\\Z')
DIGEST = re.compile(r'[0-9a-f]{64}\\Z')
PATH = re.compile(r'docs/development/[A-Za-z0-9_./-]+\\.md\\Z')
SCHEMA = 'canovia.g3.provider_evidence_inventory.v1'


class InvalidInventory(Exception):
    """Never emit untrusted evidence or credentials."""


def unique_pairs(pairs):
    result = {}
    for key, value in pairs:
        if key in result:
            raise InvalidInventory()
        result[key] = value
    return result


def exact(obj, required):
    return type(obj) is dict and set(obj) == set(required)


def git(repo, *args):
    try:
        done = subprocess.run(
            ['git', '-C', str(repo), *args],
            capture_output=True, timeout=10, check=False,
        )
        if done.returncode == 0:
            return done.stdout
    except (OSError, subprocess.TimeoutExpired):
        pass
    raise InvalidInventory()


def allowed_path(path):
    return (
        type(path) is str and PATH.fullmatch(path) is not None
        and '..' not in path.split('/') and '//' not in path
    )


def pinned_document(repo, sha, ref):
    name, digest = ref['path'], ref['sha256']
    if not allowed_path(name) or not DIGEST.fullmatch(digest):
        return False
    try:
        meta = git(repo, 'ls-tree', sha, '--', name).decode('ascii').strip()
        if not meta.startswith('100644 blob ') or not meta.endswith('\\t' + name):
            return False
        size = int(git(repo, 'cat-file', '-s', sha + ':' + name).strip())
        if size < 1 or size > 262144:
            return False
        data = git(repo, 'show', sha + ':' + name)
        return len(data) == size and hashlib.sha256(data).hexdigest() == digest
    except (InvalidInventory, ValueError, UnicodeError):
        return False


def report(state, checks=None):
    values = checks if checks is not None else {k: 'UNVERIFIED' for k in REQUIREMENTS}
    return {
        'result': state,
        'requirements': values,
        'pinned_document_count': sum(x == 'PIN_MATCH_UNTRUSTED' for x in values.values()),
        'provider_verified': False,
        'generation_verified': False,
        'replay_protection_verified': False,
        'writer_fencing_verified': False,
        'crash_recovery_verified': False,
        'release_authorized': False,
        'retry_authorized': False,
        'delete_authorized': False,
        'production_modified': False,
    }
