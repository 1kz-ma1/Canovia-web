#!/usr/bin/env python3
"""Owner Mac only: default is OFFLINE. Explicit approval gates metadata reads.

No DDL, application boot, credential printing, real data rows, or raw outputs.
Run from a clean, separately reviewed full-SHA checkout of this audit tooling.
"""
import argparse
import json
import os
from pathlib import Path
import re
import subprocess
import sys
import tempfile
import hashlib

ROOT = Path(__file__).resolve().parents[2]
sys.dont_write_bytecode = True
sys.path.insert(0, str(ROOT / 'scripts/ci'))
from integrated_release20_audit import BASE, compare, outcome, parse_full_ledger, snapshot, source
from p0_mysql_metadata_grants_guard import verify_grants


def preflight(sha):
    if not re.fullmatch('[0-9a-f]{40}', sha):
        raise ValueError('SOURCE_PIN_REQUIRED')
    def git(*args):
        return subprocess.check_output(['git', '-C', str(ROOT), *args], text=True,
                                       stderr=subprocess.DEVNULL, timeout=20).strip()
    if git('rev-parse', 'HEAD') != sha or git('status', '--porcelain', '--untracked-files=all'):
        raise ValueError('SOURCE_PIN_OR_CLEAN_CHECKOUT_REQUIRED')
    if git('rev-parse', '--is-shallow-repository') != 'false':
        raise ValueError('FULL_HISTORY_REQUIRED')
    if any(os.environ.get(k) for k in ['MYSQL_TEST_LOGIN_FILE', 'MYSQL_PWD', 'DB_PASSWORD', 'DB_URL', 'DATABASE_URL']):
        raise ValueError('CREDENTIAL_ENV_OVERRIDE_FORBIDDEN')
    src = source(ROOT, sha)
    protected = ['scripts/ops/integrated_release20_collect.py',
                 'scripts/ci/integrated_release20_audit.py',
                 'scripts/ci/p0_mysql_full_ledger_offline_check.py',
                 'scripts/ci/p0_mysql_metadata_grants_guard.py',
                 'scripts/sql/p0_mysql_readonly_full_migration_ledger.sql',
                 'scripts/sql/integrated_release20_metadata.sql',
                 'scripts/ci/integrated_release20_reference/8.0.json',
                 'scripts/ci/integrated_release20_reference/8.4.json']
    for path in protected:
        local = ROOT / path
        if local.is_symlink() or not local.is_file():
            raise ValueError('AUDIT_SOURCE_MODIFIED')
        raw = local.read_bytes()
        blob = hashlib.sha1(b'blob ' + str(len(raw)).encode() + b'\0' + raw).hexdigest()
        if git('rev-parse', sha + ':' + path) != blob:
            raise ValueError('AUDIT_SOURCE_MODIFIED')
    for series in ('8.0', '8.4'):
        ref = json.loads((ROOT / 'scripts/ci/integrated_release20_reference' / (series + '.json')).read_text())
        if ref['baseline_sha'] != BASE or ref['migration_seal'] != src['migration_seal']:
            raise ValueError('REFERENCE_SOURCE_MISMATCH')
    return src


def collect(sha):
    if os.environ.get('CI') == 'true' or os.environ.get('GITHUB_ACTIONS') == 'true':
        raise ValueError('CI_NOT_OPERATOR')
    if os.environ.get('CANOVIA_RELEASE20_OPERATOR_APPROVED') != 'YES' or os.environ.get('CANOVIA_P0_TARGET_VERIFIED') != 'YES':
        raise ValueError('SEPARATE_OPERATOR_APPROVAL_AND_TARGET_REQUIRED')
    src = preflight(sha)
    login = os.environ.get('CANOVIA_P0_LOGIN_PATH', '')
    database = os.environ.get('CANOVIA_P0_DATABASE', '')
    ca = Path(os.environ.get('CANOVIA_P0_CA_FILE', ''))
    if not re.fullmatch('[A-Za-z0-9_-]{1,60}', login) or not re.fullmatch('[A-Za-z0-9_-]{1,64}', database):
        raise ValueError('VERIFIED_LOGIN_PATH_AND_DATABASE_REQUIRED')
    if not ca.is_absolute() or not ca.is_file() or not os.access(ca, os.R_OK):
        raise ValueError('VERIFIED_CA_REQUIRED')
    args = ['mysql', '--no-defaults', '--login-path=' + login, '--ssl-mode=VERIFY_IDENTITY',
            '--ssl-ca=' + str(ca), '--database=' + database, '--connect-timeout=10',
            '--batch', '--raw', '--skip-column-names', '--skip-auto-rehash']
    def query(sql):
        # Private files bound memory and keep errors/SQL away from terminal or
        # Actions. TemporaryDirectory is 0700 and is removed even on a denial.
        with tempfile.TemporaryDirectory(prefix='canovia20-private-') as tmp:
            os.chmod(tmp, 0o700)
            with (Path(tmp) / 'output').open('w+b') as out, (Path(tmp) / 'error').open('w+b') as err:
                p = subprocess.run(args, input=sql.encode(), stdout=out, stderr=err, timeout=60)
                if p.returncode or out.tell() > 8 * 1024 * 1024:
                    raise ValueError('PRIVATE_QUERY_FAILED')
                out.seek(0)
                return out.read().decode('ascii')
    grants = query('SHOW GRANTS FOR CURRENT_USER()')
    if not verify_grants(grants, database):
        raise ValueError('NARROW_METADATA_GRANTS_REQUIRED')
    ledger = parse_full_ledger(query((ROOT / 'scripts/sql/p0_mysql_readonly_full_migration_ledger.sql').read_text()))
    physical = snapshot(query((ROOT / 'scripts/sql/integrated_release20_metadata.sql').read_text()))
    ref = json.loads((ROOT / 'scripts/ci/integrated_release20_reference' / (physical['engine'] + '.json')).read_text())
    return compare(src, ledger, physical, ref)


def main():
    p = argparse.ArgumentParser(description=__doc__)
    p.add_argument('--source-sha', required=True)
    p.add_argument('--collect', action='store_true', help='Requires separate explicit operator approval; otherwise offline only')
    args = p.parse_args()
    try:
        if args.collect:
            result = collect(args.source_sha)
        else:
            preflight(args.source_sha)
            result = outcome('OFFLINE_SOURCE20_READY_NO_CONNECTION', 'REVIEW_REQUIRED')
    except Exception:
        # Exception messages may contain private host/client/file/SQL details.
        # The exit code is the only failure channel, with a fixed safe message.
        result = outcome('OPERATOR_PREFLIGHT_OR_PRIVATE_AUDIT_BLOCKED')
    print(json.dumps(result, sort_keys=True))
    return 0 if result['result'] == 'REVIEW_REQUIRED' else 2


if __name__ == '__main__':
    sys.exit(main())
