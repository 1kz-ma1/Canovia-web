#!/usr/bin/env python3
"""Owner offline acceptance. No target/binding/credential input and no DB action."""
import argparse
from contextlib import redirect_stderr
import importlib.util
import io
import json
from pathlib import Path
import subprocess
import sys

sys.dont_write_bytecode = True
ROOT = Path(__file__).resolve().parents[3]
sys.path.insert(0, str(ROOT / 'scripts/ci'))
from e858_audit import core as original
from e858_transport_preflight.core import BASE, SQL_HASH, REFS, Block, boundary, receipt

spec = importlib.util.spec_from_file_location('old_packet', ROOT / 'scripts/ops/e858_audit_packet.py')
old = importlib.util.module_from_spec(spec)
spec.loader.exec_module(old)
BASE_JOBS = ('E858 offline safety', 'E858 reference (MySQL 8.0)', 'E858 reference (MySQL 8.4)',
             'migration-regression / upgrade (8.0)', 'migration-regression / upgrade (8.4)',
             'migration-regression / startup-policy',
             'fixed-diagnostic-regression / Schema diagnosis (MySQL 8.0)',
             'fixed-diagnostic-regression / Schema diagnosis (MySQL 8.4)')
NEW_JOBS = ('Transport offline safety', 'Transport disposable (MySQL 8.0)',
            'Transport disposable (MySQL 8.4)',
            'migration-regression / upgrade (8.0)', 'migration-regression / upgrade (8.4)',
            'migration-regression / startup-policy',
            'fixed-diagnostic-regression / Schema diagnosis (MySQL 8.0)',
            'fixed-diagnostic-regression / Schema diagnosis (MySQL 8.4)')


def packet(head, base_receipt, transport_receipt):
    facts = boundary(ROOT, head)
    baseline = receipt(old.private_json(base_receipt, 1024 * 1024), BASE, 'E858 Source-bound Audit', BASE_JOBS)
    current = receipt(old.private_json(transport_receipt, 1024 * 1024), head,
                      'E858 Transport Offline Preflight', NEW_JOBS)
    if baseline == current:
        raise Block('CI_RECEIPT_REJECTED')
    p = subprocess.run([sys.executable, '-I', '-B', '-m', 'unittest', 'discover', '-s', 'tests/ci',
                        '-p', 'test_e858_transport_preflight.py'], cwd=ROOT,
                       stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=120)
    if p.returncode:
        raise Block('OFFLINE_FIXTURE_TESTS_FAILED')
    return dict(result='TRANSPORT_OFFLINE_PREFLIGHT_VERIFIED', candidate_source_sha=original.CANDIDATE,
                transport_sha=head, dependency_tooling_sha=BASE, migration_seal=original.SEAL,
                baseline_count=len(facts['baseline']), total_count=len(facts['candidate']),
                changed_pending_ids=['M01', 'M08', 'M18', 'M19', 'M20'],
                sql_bundle_sha256=SQL_HASH, reference_hashes=REFS,
                ci_run_ids=[baseline, current], ci_evidence='OWNER_FETCHED_NOT_CRYPTOGRAPHIC_ATTESTATION',
                local_fixtures='EXECUTED_WITHOUT_DATABASE', local_mysql='NOT_RUN_CI_EVIDENCE_ONLY',
                tooling_mac_acceptance='PENDING_OWNER_RUN', binding_authentication='UNIMPLEMENTED',
                real_aiven='UNVERIFIED', production='NO_GO_HOLD', real_aiven_connected=False,
                production_modified=False, strict_block_waived=False, release_authorized=False)


def main():
    try:
        # Even malformed/extra arguments cannot unlock --collect.
        if '--collect' in sys.argv[1:]:
            raise Block('REAL_CONNECTION_DISABLED')
        parser = argparse.ArgumentParser(exit_on_error=False, add_help=False, allow_abbrev=False)
        parser.add_argument('--transport-sha', required=True)
        parser.add_argument('--base-receipt', required=True)
        parser.add_argument('--transport-receipt', required=True)
        with redirect_stderr(io.StringIO()):
            args = parser.parse_args()
        result = packet(args.transport_sha, args.base_receipt, args.transport_receipt)
        code = 0
    except Block as error:
        result = {'result': 'BLOCK', 'code': error.code}; code = 2
    except (Exception, SystemExit, KeyboardInterrupt):
        result = {'result': 'BLOCK', 'code': 'OFFLINE_INPUT_OR_SOURCE_REJECTED'}; code = 2
    result.update(real_aiven_connected=False, production_modified=False, strict_block_waived=False,
                  release_authorized=False, real_aiven='UNVERIFIED', production='NO_GO_HOLD')
    print(json.dumps(result, sort_keys=True))
    return code


if __name__ == '__main__':
    sys.exit(main())
