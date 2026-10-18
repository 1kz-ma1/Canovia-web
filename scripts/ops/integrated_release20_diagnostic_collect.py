#!/usr/bin/env python3
"""Owner-only diagnosis: separate tooling/release pins; default never connects.

No new grants, no application boot, no raw output and no release authorization.
The fixed release checkout/ref is NOT modified to run this independent tooling.
"""
import argparse
from contextlib import redirect_stderr
import hashlib
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile

ROOT = Path(__file__).resolve().parents[2]
sys.dont_write_bytecode = True
sys.path.insert(0, str(ROOT / 'scripts/ci'))
from integrated_release20_audit import git, outcome, source, parse_full_ledger, snapshot
from integrated_release20_diagnose import CANDIDATE, RELEASE_REF, KIND, diagnose
from integrated_release20_collect import preflight as original_preflight, release_boundary
from p0_mysql_metadata_grants_guard import verify_grants

ORIGINAL = [
    'scripts/ops/integrated_release20_collect.py', 'scripts/ci/integrated_release20_audit.py',
    'scripts/ci/p0_mysql_full_ledger_offline_check.py', 'scripts/ci/p0_mysql_metadata_grants_guard.py',
    'scripts/sql/p0_mysql_readonly_full_migration_ledger.sql', 'scripts/sql/integrated_release20_metadata.sql',
    'scripts/ci/integrated_release20_reference/8.0.json', 'scripts/ci/integrated_release20_reference/8.4.json']
ADDITIONAL = [
    'scripts/ops/integrated_release20_diagnostic_collect.py', 'scripts/ci/integrated_release20_diagnose.py',
    'scripts/ci/integrated_release20_diagnostic_reference/8.0.json',
    'scripts/ci/integrated_release20_diagnostic_reference/8.4.json']


def preflight(tooling_sha, candidate_sha):
    if candidate_sha != CANDIDATE:
        raise ValueError()
    tooling_src = original_preflight(tooling_sha)
    src = source(ROOT, candidate_sha)
    if src != tooling_src:
        raise ValueError()
    git(ROOT, 'merge-base', '--is-ancestor', candidate_sha, tooling_sha)
    for path in ORIGINAL + ADDITIONAL:
        local = ROOT / path
        if local.is_symlink() or not local.is_file():
            raise ValueError()
        data = local.read_bytes()
        blob = hashlib.sha1(b'blob ' + str(len(data)).encode() + b'\0' + data).hexdigest()
        if git(ROOT, 'rev-parse', tooling_sha + ':' + path) != blob:
            raise ValueError()
        if path in ORIGINAL and git(ROOT, 'rev-parse', candidate_sha + ':' + path) != blob:
            raise ValueError()
    for series in ('8.0', '8.4'):
        ref = json.loads((ROOT / 'scripts/ci/integrated_release20_diagnostic_reference' / (series + '.json')).read_text())
        exact = json.loads((ROOT / 'scripts/ci/integrated_release20_reference' / (series + '.json')).read_text())
        if (ref['kind'] != KIND or ref['candidate_sha'] != candidate_sha or ref['engine'] != series
                or ref['migration_seal'] != src['migration_seal'] or ref['baseline_sha'] != exact['baseline_sha']
                or any(ref[stage]['physical'] != exact[stage] for stage in ('baseline94', 'candidate114'))):
            raise ValueError()
    return src


def private_query(args, sql):
    # No stderr propagation, no persistent raw files, no host/SQL in exceptions.
    with tempfile.TemporaryDirectory(prefix='canovia-diagnosis-private-') as tmp:
        os.chmod(tmp, 0o700)
        with (Path(tmp) / 'out').open('w+b') as out, (Path(tmp) / 'err').open('w+b') as err:
            proc = subprocess.run(args, input=sql.encode('ascii'), stdout=out, stderr=err, timeout=60)
            if proc.returncode or out.tell() > 8 * 1024 * 1024:
                raise ValueError()
            out.seek(0)
            return out.read().decode('ascii')


def collect(tooling_sha, candidate_sha):
    # A previous release/audit approval is deliberately NOT sufficient.
    if (os.environ.get('CI') == 'true' or os.environ.get('GITHUB_ACTIONS') == 'true'
            or os.environ.get('CANOVIA_RELEASE20_DIAGNOSTIC_APPROVED') != 'YES'
            or os.environ.get('CANOVIA_P0_TARGET_VERIFIED') != 'YES'):
        raise ValueError()
    src = preflight(tooling_sha, candidate_sha)
    if os.environ.get('CANOVIA_RELEASE20_RELEASE_REF') != RELEASE_REF:
        raise ValueError()
    release_boundary(candidate_sha)
    import re
    login, database = (os.environ.get(k, '') for k in ('CANOVIA_P0_LOGIN_PATH', 'CANOVIA_P0_DATABASE'))
    ca = Path(os.environ.get('CANOVIA_P0_CA_FILE', ''))
    if (not re.fullmatch('[A-Za-z0-9_-]{1,60}', login) or not re.fullmatch('[A-Za-z0-9_-]{1,64}', database)
            or not ca.is_absolute() or not ca.is_file() or not os.access(ca, os.R_OK)):
        raise ValueError()
    args = ['mysql', '--no-defaults', '--login-path=' + login, '--ssl-mode=VERIFY_IDENTITY',
            '--ssl-ca=' + str(ca), '--database=' + database, '--connect-timeout=10',
            '--batch', '--raw', '--skip-column-names', '--skip-auto-rehash']
    # Three queries only: grant proof, ledger, existing information_schema SQL.
    if not verify_grants(private_query(args, 'SHOW GRANTS FOR CURRENT_USER()'), database):
        raise ValueError()
    ledger = parse_full_ledger(private_query(args, (ROOT / 'scripts/sql/p0_mysql_readonly_full_migration_ledger.sql').read_text()))
    metadata = private_query(args, (ROOT / 'scripts/sql/integrated_release20_metadata.sql').read_text())
    series = snapshot(metadata)['engine']
    reference = json.loads((ROOT / 'scripts/ci/integrated_release20_reference' / (series + '.json')).read_text())
    diagnostic = json.loads((ROOT / 'scripts/ci/integrated_release20_diagnostic_reference' / (series + '.json')).read_text())
    result = diagnose(src, ledger, metadata, reference, diagnostic)
    result['metadata_visibility'] = 'NARROW_REFERENCES_AND_LEDGER_SELECT_CONFIRMED'
    return result


def main():
    # argparse's ordinary error text echoes arbitrary input; suppress that too.
    parser = argparse.ArgumentParser(prog='canovia-schema-diagnosis', description=__doc__, exit_on_error=False)
    parser.add_argument('--tooling-sha', required=True)
    parser.add_argument('--candidate-sha', required=True)
    parser.add_argument('--collect', action='store_true')
    try:
        with tempfile.TemporaryFile() as error:
            with redirect_stderr(error):
                args = parser.parse_args()
        if args.collect:
            result = collect(args.tooling_sha, args.candidate_sha)
        else:
            preflight(args.tooling_sha, args.candidate_sha)
            result = outcome('OFFLINE_DIAGNOSTIC_READY_NO_CONNECTION', 'REVIEW_REQUIRED')
    except (Exception, SystemExit, KeyboardInterrupt):
        result = outcome('DIAGNOSTIC_PREFLIGHT_OR_PRIVATE_INPUT_BLOCKED')
    print(json.dumps(result, sort_keys=True))
    return 0 if result['result'] == 'REVIEW_REQUIRED' else 2


if __name__ == '__main__':
    sys.exit(main())
