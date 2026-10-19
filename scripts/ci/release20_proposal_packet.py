#!/usr/bin/env python3
"""Offline source facts ONLY; no DB connection, exception acceptance or adoption."""
import argparse
from contextlib import redirect_stderr
import hashlib
import json
from pathlib import Path
import subprocess
import sys
import tempfile

sys.dont_write_bytecode = True
from integrated_release20_audit import ROOT, git, source
from integrated_release20_diagnose import CANDIDATE

PARENT = 'e6124170e4ab8f92709d7683544d999822a96581'
CHANGED_IDS = (0, 7, 17, 18, 19)


def packet(sha):
    fixed, proposal = source(ROOT, CANDIDATE), source(ROOT, sha)
    if (git(ROOT, 'rev-parse', 'HEAD') != sha or git(ROOT, 'status', '--porcelain', '--untracked-files=all')
            or git(ROOT, 'rev-parse', '--is-shallow-repository') != 'false'):
        raise ValueError()
    git(ROOT, 'merge-base', '--is-ancestor', PARENT, sha)
    additions = sorted(set(fixed['candidate']) - set(fixed['baseline']))
    changed = {k for k in fixed['candidate'] if fixed['candidate'][k] != proposal['candidate'].get(k)}
    if (proposal['baseline'] != fixed['baseline'] or set(proposal['candidate']) != set(fixed['candidate'])
            or changed != {additions[i] for i in CHANGED_IDS}):
        raise ValueError()
    protected = ['scripts/ci/release20_proposal_packet.py', 'app/Support/Release20IdentityMigrationGuard.php',
                 'app/Support/Release20PendingSchemaGuard.php', 'resources/release20_pending_schema_contract.json']
    protected += ['database/migrations/' + name + '.php' for name in changed]
    for path in protected:
        local = ROOT / path
        if local.is_symlink() or not local.is_file():
            raise ValueError()
        data = local.read_bytes()
        blob = hashlib.sha1(b'blob ' + str(len(data)).encode() + b'\0' + data).hexdigest()
        if blob != git(ROOT, 'rev-parse', sha + ':' + path):
            raise ValueError()
    for path in ['scripts/ci/integrated_release20_audit.py', 'scripts/ci/integrated_release20_diagnose.py',
                 'scripts/ci/integrated_release20_semantics.py', 'scripts/ci/p0_mysql_metadata_grants_guard.py',
                 'scripts/ci/integrated_release20_reference/8.0.json', 'scripts/ci/integrated_release20_reference/8.4.json']:
        if git(ROOT, 'rev-parse', PARENT + ':' + path) != git(ROOT, 'rev-parse', sha + ':' + path):
            raise ValueError()
    return dict(result='REVIEW_REQUIRED', code='OFFLINE_PROPOSAL_PACKET_ONLY', proposal_sha=sha,
                diagnostic_base_sha=PARENT, fixed_candidate=CANDIDATE, baseline_migration_count=94,
                total_migration_count=114, changed_pending_migration_ids=['M01', 'M08', 'M18', 'M19', 'M20'],
                migration_seal=proposal['migration_seal'], original_audit_unchanged=True,
                old94_unchanged=True, new_candidate_adopted=False, real_aiven_connected=False,
                production_database_modified=False, strict_block_waived=False, release_authorized=False)


def main():
    p = argparse.ArgumentParser(prog='release20-offline-packet', description=__doc__, exit_on_error=False)
    p.add_argument('--proposal-sha', required=True)
    try:
        with tempfile.TemporaryFile() as errors, redirect_stderr(errors):
            args = p.parse_args()
        result = packet(args.proposal_sha)
    except (Exception, SystemExit, KeyboardInterrupt):
        result = dict(result='BLOCK', code='OFFLINE_SOURCE_PACKET_REJECTED', release_authorized=False)
    print(json.dumps(result, sort_keys=True))
    return 0 if result['result'] == 'REVIEW_REQUIRED' else 2


if __name__ == '__main__':
    sys.exit(main())
