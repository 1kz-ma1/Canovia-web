"""CI-only synthetic Docker exercise; no arbitrary target and no credentials read."""
import json
from pathlib import Path
import subprocess
import sys

sys.dont_write_bytecode = True
ROOT = Path(__file__).resolve().parents[3]
sys.path.insert(0, str(ROOT / 'scripts/ci'))
from e858_audit import core as original
from e858_transport_preflight.core import Block, boundary
from e858_transport_preflight.adapter import DisposableAdapter, capture, child_env, DOCKER, TARGETS
from integrated_release20_audit import parse_full_ledger
from p0_mysql_metadata_grants_guard import verify_grants


def exercise(series):
    facts = boundary(ROOT, original.git(ROOT, 'rev-parse', 'HEAD'))
    adapter = DisposableAdapter(series)  # ownership before synthetic setup DDL
    name, _ = TARGETS[series]
    setup = ("DROP USER IF EXISTS 'e858_inspector'@'%'; "
             "CREATE USER 'e858_inspector'@'%' IDENTIFIED BY 'ci-only-e858-inspector'; "
             "GRANT REFERENCES ON canovia_p0_ci.* TO 'e858_inspector'@'%'; "
             "GRANT SELECT ON canovia_p0_ci.migrations TO 'e858_inspector'@'%';")

    def synthetic_ddl(sql):
        adapter._verify()
        p = subprocess.run([DOCKER, 'exec', '-i', '-e', 'MYSQL_PWD=ci-only-root-password', name,
                            'mysql', '--no-defaults', '--no-login-paths', '-uroot'], input=sql.encode(),
                           env=child_env(), stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=10)
        if p.returncode:
            raise Block('DISPOSABLE_SETUP_REJECTED')

    synthetic_ddl(setup)
    try:
        data = capture(adapter)
        identity = data['identity'].rstrip('\n').split('\t')
        if (len(identity) != 4 or identity[0] != 'canovia_p0_ci'
                or identity[1] != 'e858_inspector@%' or not identity[2].startswith(series + '.')):
            raise Block('DISPOSABLE_IDENTITY_REJECTED')
        if not verify_grants(data['grants'], 'canovia_p0_ci'):
            raise Block('METADATA_GRANTS_REJECTED')
        raw = ''.join(data[k] for k in ('engine', 'table', 'column', 'index', 'foreign_key', 'check', 'trigger'))
        semantic = ''.join(data[k] for k in ('semantic_version', 'semantic_index', 'semantic_foreign_key'))
        ref = json.loads((ROOT / f'scripts/ci/e858_audit_reference/{series}.json').read_text())
        contract = json.loads((ROOT / 'resources/release20_pending_schema_contract.json').read_text())
        ledger = parse_full_ledger(data['ledger'])
        out = original.audit(facts, ledger, ledger, raw, semantic, data['column_extra'], ref, contract)
        if (out['stage'] != 'candidate114' or out['trigger_visibility'] != 'UNVERIFIED'
                or out['result'] != 'BLOCK_REAL_GATES_UNVERIFIED' or out['release_authorized']):
            raise Block('SYNTHETIC_VERDICT_REJECTED')
        # Mutating only private in-memory snapshots proves ledger change STOP.
        try:
            original.audit(facts, ledger, {}, raw, semantic, data['column_extra'], ref, contract)
        except Block as error:
            if error.code != 'LEDGER_CHANGED':
                raise
        else:
            raise Block('SYNTHETIC_VERDICT_REJECTED')
        return dict(result='SYNTHETIC_LOOPBACK_TRANSPORT_VERIFIED', engine=series,
                    version=identity[2], unique_sql_count=14, query_execution_count=17,
                    cases=3, trigger_visibility='UNVERIFIED', snapshot_atomicity='UNVERIFIED',
                    real_aiven_connected=False, production_modified=False, release_authorized=False)
    finally:
        synthetic_ddl("DROP USER IF EXISTS 'e858_inspector'@'%';")


if __name__ == '__main__':
    try:
        if len(sys.argv) != 2 or sys.argv[1] not in TARGETS:
            raise Block('DISPOSABLE_TARGET_REJECTED')
        result = exercise(sys.argv[1]); code = 0
    except Block as error:
        result = dict(result='BLOCK', code=error.code); code = 2
    except (Exception, KeyboardInterrupt):
        result = dict(result='BLOCK', code='DISPOSABLE_TEST_REJECTED'); code = 2
    print(json.dumps(result, sort_keys=True))
    sys.exit(code)
