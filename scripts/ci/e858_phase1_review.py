#!/usr/bin/env python3
"""Public Git review inventory ONLY; not an executable/live distribution.

Reuse B integrity. Never read private target/CA/TTY/credentials, install packages,
connect, produce authorization, or execute the listed transport modules.
"""
import hashlib
import json
from pathlib import Path
import sys

sys.path.insert(0, str(Path(__file__).resolve().parent))
import e858_b_offline as b

EVIDENCE = '79798ed0591f80b9dbfa74109dde376c14899e18'
# Explicit public source set: changes require fresh evidence, not automatic reuse.
RUNTIME = tuple('scripts/ci/' + path for path in (
    'e858_phase1/protocol.py', 'e858_phase1/runtime.py', 'e858_phase1/fixtures.py',
    'e858_phase1/r1_tty.py', 'e858_phase1/r1_adapter.py',
    'e858_phase1/r1_driver.py', 'e858_phase1/r1_driver_fixtures.py',
    'e858_phase1_host.py', 'e858_r1_driver_host.py',
    'e858_tls/transport.py', 'e858_tls/control_contract.py',
    'e858_tls_host.py', 'e858_b_offline.py',
    'p0_mysql_metadata_grants_input.py', 'p0_mysql_metadata_grants_guard.py',
    'release488_runtime_ddl_restore_gate.py', 'integrated_release20_audit.py',
    'p0_mysql_full_ledger_offline_check.py'))
REVIEW = ('scripts/ci/e858_phase1_review.py',
          'tests/ci/test_e858_phase1_review.py', '.github/workflows/e858-phase1.yml',
          'docs/development/integration/2026-10-18-b/e858-real-audit-route-safety.md')
WHEEL = dict(filename='pymysql-1.1.2-py3-none-any.whl',
    sha256='e6b1d89711dd51f8f74b1631fe08f039e7d76cf67a42a323d3178f0f25762ed9')


def inventory(root, head):
    pins = b.integrity(root, head)  # Existing clean checkout/source/SQL/reference gate.
    files = {}
    for path in RUNTIME + REVIEW:
        raw = b.git(root, 'show', head + ':' + path)
        if path in RUNTIME and raw != b.git(root, 'show', EVIDENCE + ':' + path):
            raise b.Block('DEPENDENCY_CHANGED')
        files[path] = hashlib.sha256(raw).hexdigest()
    value = b.outcome('OFFLINE_REVIEW_INVENTORY', 'REVIEW_ONLY')
    value.update(kind='E858_PHASE1_PUBLIC_REVIEW_V1', pins=pins,
        reused_evidence_head=EVIDENCE, public_source_sha256=files,
        public_source_index_sha256=b.digest(files), pymysql_wheel=WHEEL.copy(),
        live_distribution='NOT_BUILT', live_tooling='NOT_ADOPTED',
        runtime_artifact_lock='UNVERIFIED', mac_delta_acceptance='PENDING',
        approval='NOT_GRANTED')
    return value


def main(argv=None):
    try:
        parser = b.Parser(description=__doc__, allow_abbrev=False, add_help=False)
        parser.add_argument('--tooling-sha', required=True)
        args = parser.parse_args(argv)
        result = inventory(b.ROOT, args.tooling_sha)
        status = 0
    except BaseException:
        # Never echo argv, Git diagnostics, paths, exception text or local values.
        result = b.outcome('REVIEW_INVENTORY_REJECTED')
        status = 2
    print(json.dumps(result, sort_keys=True, separators=(',', ':')))
    return status


if __name__ == '__main__':
    raise SystemExit(main())
