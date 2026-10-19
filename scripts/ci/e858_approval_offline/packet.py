#!/usr/bin/env python3
"""Source/CI-bound OFFLINE packet. No target, key, approval, or collection input."""
import argparse
from contextlib import redirect_stdout,redirect_stderr
import io
import json
from pathlib import Path
import sys
import unittest

sys.dont_write_bytecode=True
ROOT=Path(__file__).resolve().parents[3]
sys.path.insert(0,str(ROOT/'scripts/ci'))
from e858_approval_offline.contracts import Block,CANDIDATE,TOOLING,TRANSPORT,SQL,REFS,REAL_GAPS
from e858_approval_offline.guard import boundary,private_receipts,receipts


def packet(head,directory):
    facts=boundary(ROOT,head)
    ids=receipts(private_receipts(directory),head)
    # Owner runs pure fixtures in-process. Git/source tamper subprocess fixtures
    # and pinned dependency subprocess suites run separately in CI, NOT here.
    output=io.StringIO()
    with redirect_stdout(output),redirect_stderr(output):
        suite=unittest.defaultTestLoader.discover(str(ROOT/'tests/ci'),pattern='test_e858_approval_offline.py')
        result=unittest.TextTestRunner(stream=output,verbosity=0).run(suite)
    if not result.wasSuccessful() or result.skipped or result.testsRun<1:
        raise Block('SYNTHETIC_FIXTURES_FAILED')
    return dict(result='AUTH_TLS_APPROVAL_OFFLINE_CONTRACT_VERIFIED',
                state='READY_FOR_OWNER_REVIEW',candidate_source_sha=CANDIDATE,
                tooling_sha=TOOLING,transport_sha=TRANSPORT,verifier_sha=head,
                baseline_count=len(facts['baseline']),total_count=len(facts['candidate']),
                changed_pending_ids=['M01','M08','M18','M19','M20'],migration_seal=facts['migration_seal'],
                sql_bundle_sha256=SQL,reference_hashes=REFS,ci_run_ids=ids,
                synthetic_test_count=result.testsRun,synthetic_skipped=0,
                local_fixtures='IN_PROCESS_SYNTHETIC_ONLY',local_mysql='NOT_RUN_DEPENDENCY_CI_ONLY',
                ci_receipts='OWNER_FETCHED_NOT_CRYPTOGRAPHIC_ATTESTATION',
                authority='MISSING_REAL_AUTHORITY',real_operator_authenticated=False,
                real_gaps=list(REAL_GAPS),tooling_mac_acceptance='PENDING_OWNER_RUN',
                release_authorized=False,real_aiven_connected=False,production_modified=False,strict_block_waived=False)


def main():
    try:
        if '--collect' in sys.argv[1:]:
            raise Block('REAL_CONNECTION_DISABLED')
        parser=argparse.ArgumentParser(add_help=False,exit_on_error=False,allow_abbrev=False)
        parser.add_argument('--gate-sha',required=True)
        parser.add_argument('--receipts-dir',required=True)
        with redirect_stderr(io.StringIO()):args=parser.parse_args()
        value=packet(args.gate_sha,args.receipts_dir);code=0
    except Block as error:
        value=dict(result='BLOCK',code=error.code);code=2
    except (Exception,SystemExit,KeyboardInterrupt):
        value=dict(result='BLOCK',code='OFFLINE_INPUT_OR_SOURCE_REJECTED');code=2
    value.update(real_gaps=list(REAL_GAPS),real_operator_authenticated=False,
                 release_authorized=False,real_aiven_connected=False,production_modified=False,strict_block_waived=False)
    print(json.dumps(value,sort_keys=True))
    return code


if __name__=='__main__':
    sys.exit(main())
