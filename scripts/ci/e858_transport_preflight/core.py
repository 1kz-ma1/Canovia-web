"""Offline preparation only. No target input, credentials, socket, or live builder."""
import hashlib
import json
import os
from pathlib import Path
import re

from e858_audit import core as original

Block = original.Block
BASE = 'be89af9a2cb4b13ef16579d2570f4a5605a711b8'
BRANCH = 'audit/e858-source-bound-readiness'
SQL_HASH = '8383bc16fc8b35b8413a2a6a0b0c0784c9fa646ebab119017b97c2bd8d4333d1'
REFS = {'8.0': 'ab7a27ff4f95be54fa802d1d801082ac508f1611d32215003a5f1d6c0d9270b6',
        '8.4': 'c23bb40ad94a4c33392e7a237d81cad1d576816065f5bb48f4ee86ea323e86d7'}
FILES = (
    'scripts/ci/e858_transport_preflight/core.py',
    'scripts/ci/e858_transport_preflight/adapter.py',
    'scripts/ci/e858_transport_preflight/packet.py',
    'scripts/ci/e858_transport_preflight/disposable.py',
    'tests/ci/test_e858_transport_preflight.py',
    '.github/workflows/e858-transport-offline-preflight.yml',
    'docs/development/integration/2026-10-18-b/e858-transport-offline-preflight.md',
)
ORDER = ('identity', 'grants', 'ledger', 'engine', 'table', 'column', 'index',
         'foreign_key', 'check', 'trigger', 'semantic_version', 'semantic_index',
         'semantic_foreign_key', 'column_extra', 'ledger', 'identity', 'grants')


def environment_guard(env=None):
    # Inspect names/presence only. Never open HOME, option files or credentials.
    env = os.environ if env is None else env
    exact = {'DATABASE_URL', 'HOME', 'MYSQL_HOME', 'MYSQL_TEST_LOGIN_FILE', 'MYSQL_PWD'}
    for key, value in env.items():
        if value and (key in exact - {'HOME'} or key.startswith(('DB_', 'MYSQL_', 'CANOVIA_P0_',
                       'CANOVIA_TRANSPORT_', 'CANOVIA_E858_'))):
            raise Block('ENVIRONMENT_OVERRIDE_REJECTED')
    # HOME is inert: neither read nor forwarded. The subprocess environment is rebuilt.


def tree(root, sha):
    raw = original.git(root, 'ls-tree', '-r', sha)
    return {row.split('\t', 1)[1]: row.split('\t', 1)[0].split() for row in raw.splitlines()}


def boundary(root, head):
    environment_guard()
    if not re.fullmatch('[0-9a-f]{40}', head) or original.git(root, 'rev-parse', 'HEAD') != head:
        raise Block('TRANSPORT_HEAD_REJECTED')
    if original.git(root, 'rev-parse', '--is-shallow-repository') != 'false':
        raise Block('FULL_HISTORY_REQUIRED')
    if original.git(root, 'status', '--porcelain', '--untracked-files=all'):
        raise Block('CLEAN_CHECKOUT_REQUIRED')
    flags = original.git(root, 'ls-files', '-v').splitlines()
    if any(row[0] != 'H' for row in flags):
        raise Block('INDEX_FLAGS_REJECTED')
    pins = {BRANCH: BASE, original.RELEASE: original.CANDIDATE,
            'release/2026-10-19-c09a8fc7': original.OLD,
            'production': '15abdc9de6f24b5250fb255c579d1aa55facaf1a'}
    for ref, expected in pins.items():
        if original.git(root, 'rev-parse', 'refs/remotes/origin/' + ref) != expected:
            raise Block('FIXED_REF_DRIFT')
    original.git(root, 'merge-base', '--is-ancestor', BASE, head)
    original.git(root, 'merge-base', '--is-ancestor', original.CANDIDATE, 'origin/main')
    changes = original.git(root, 'diff', '--name-status', BASE, head).splitlines()
    if set(changes) != {'A\t' + path for path in FILES}:
        raise Block('TRANSPORT_SCOPE_REJECTED')
    old, current = tree(root, BASE), tree(root, head)
    if any(current.get(p) != v for p, v in old.items()):
        raise Block('DEPENDENCY_CHANGED')
    # Check actual bytes of ALL tracked files, including hidden index modifications.
    for path, (mode, kind, blob) in current.items():
        if kind != 'blob' or mode not in ('100644', '100755'):
            raise Block('SOURCE_FILE_REJECTED')
        file = root / path
        if file.is_symlink() or any(p.is_symlink() for p in file.parents) or not file.is_file():
            raise Block('SOURCE_FILE_REJECTED')
        content = file.read_bytes()
        if hashlib.sha1(b'blob ' + str(len(content)).encode() + b'\0' + content).hexdigest() != blob:
            raise Block('SOURCE_FILE_REJECTED')
    facts = original.source_facts(root)
    # Reuse reference validation without broadening #795's scope guard.
    inputs = original.LEGACY + original.IMPLEMENTATION + original.SOURCE_FILES
    inputs += [p for p in tree(root, original.CANDIDATE) if p.startswith(original.PREFIXES)]
    blobs = {p: current[p][2] for p in sorted(set(inputs))}
    for series, expected in REFS.items():
        file = root / f'scripts/ci/e858_audit_reference/{series}.json'
        if hashlib.sha256(file.read_bytes()).hexdigest() != expected:
            raise Block('REFERENCE_HASH_REJECTED')
        original.load_reference(root, series, facts, blobs, BASE)
    plan(root)
    return facts


def plan(root=original.ROOT, supplied=None):
    bundle = original.queries(root)
    if original.hashed({k: hashlib.sha256(v).hexdigest() for k, v in bundle.items()}) != SQL_HASH:
        raise Block('SQL_BUNDLE_REJECTED')
    fixed = tuple((key, bundle[key]) for key in ORDER)
    if supplied is not None and supplied != fixed:
        raise Block('SQL_PLAN_REJECTED')
    return fixed


def synthetic_binding(binding, observed, now):
    """Non-executing schema test. A matching hash NEVER authenticates an operator."""
    keys = {'kind', 'host', 'port', 'schema', 'engine', 'server_uuid', 'actor',
            'ca_sha256', 'expires_at', 'source_sha', 'tooling_sha'}
    if not isinstance(binding, dict) or set(binding) != keys:
        raise Block('BINDING_SHAPE_REJECTED')
    if (binding['kind'] != 'SYNTHETIC_ONLY' or binding['host'] != 'synthetic.invalid'
            or binding['port'] != 13380 or binding['schema'] != 'synthetic_ci'
            or binding['actor'] != 'synthetic_actor' or binding['engine'] not in REFS
            or binding['server_uuid'] != '00000000-0000-0000-0000-000000000000'
            or binding['ca_sha256'] != '0' * 64
            or binding['source_sha'] != original.CANDIDATE or binding['tooling_sha'] != BASE):
        raise Block('SYNTHETIC_BINDING_ONLY')
    if type(binding['expires_at']) is not int or not now < binding['expires_at'] <= now + 3600:
        raise Block('BINDING_EXPIRED_OR_WINDOW_REJECTED')
    if observed != binding:
        raise Block('BINDING_CHANGED')
    return {'result': 'SYNTHETIC_BINDING_SHAPE_ONLY', 'authenticated': False,
            'approval': 'UNIMPLEMENTED', 'snapshot_atomicity': 'UNVERIFIED'}


def authenticate_approval(*_args, **_kwargs):
    raise Block('APPROVAL_AUTHENTICATION_UNIMPLEMENTED')


def receipt(value, head, workflow, names):
    # Owner-fetched GitHub JSON evidence, NOT a signature or authority.
    if (not isinstance(value, dict) or value.get('headSha') != head
            or value.get('workflowName') != workflow or value.get('status') != 'completed'
            or value.get('conclusion') != 'success'
            or not re.fullmatch(r'https://github.com/1kz-ma1/Canovia-web/actions/runs/[1-9][0-9]*', value.get('url', ''))):
        raise Block('CI_RECEIPT_REJECTED')
    jobs = value.get('jobs', [])
    if (len(jobs) != len(names) or {j.get('name') for j in jobs} != set(names)
            or any(j.get('status') != 'completed' or j.get('conclusion') != 'success' for j in jobs)):
        raise Block('CI_RECEIPT_REJECTED')
    return int(value['url'].rsplit('/', 1)[1])
