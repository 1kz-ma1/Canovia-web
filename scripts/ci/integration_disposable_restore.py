#!/usr/bin/env python3
"""Logical restore of synthetic owned Docker DBs; no provider/production access."""
import argparse
import hashlib
import json
from pathlib import Path
import subprocess
import time

ROOT = Path(__file__).resolve().parents[2]

parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--artifacts', type=Path, required=True)
parser.add_argument('--series', choices=['8.0', '8.4', 'all'], default='all')
args = parser.parse_args()
subprocess.run(['git', '-C', str(ROOT), 'diff', '--quiet', 'HEAD'], check=True)
candidate = subprocess.check_output(['git', '-C', str(ROOT), 'rev-parse', 'HEAD'], text=True).strip()
out = args.artifacts.resolve()
out.mkdir(parents=True, exist_ok=True)
for series, name, port in [('8.0','canovia-integration-80','13380'), ('8.4','canovia-integration-84','13384')]:
    if args.series != 'all' and args.series != series: continue
    state = json.loads(subprocess.check_output(['docker','inspect',name],text=True))[0]
    assert state['Config']['Labels'].get('canovia.integration') == '2026-10-16'
    assert state['NetworkSettings']['Ports']['3306/tcp'] == [{'HostIp':'127.0.0.1','HostPort':port}]
    prefix = ['docker','exec','-e','MYSQL_PWD=ci-only-root-password',name]
    def sql(statement, database='canovia_p0_ci'):
        return subprocess.check_output(prefix+['mysql','-uroot','--batch','--raw','-N',database,'-e',statement])
    if sql('SELECT COUNT(*) FROM migrations').strip() != b'114':
        raise RuntimeError('Expected complete disposable ledger')
    start=time.monotonic()
    dump_args=['mysqldump','-uroot','--single-transaction','--routines','--triggers','--set-gtid-purged=OFF','--column-statistics=0']
    data=subprocess.check_output(prefix+dump_args+['canovia_p0_ci'])
    sql('DROP DATABASE IF EXISTS canovia_restore_ci; CREATE DATABASE canovia_restore_ci CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;')
    try:
        subprocess.run(['docker','exec','-i','-e','MYSQL_PWD=ci-only-root-password',name,
                        'mysql','-uroot','canovia_restore_ci'], input=data,check=True)
        data_args=['mysqldump','-uroot','--no-create-info','--skip-triggers','--skip-add-locks',
                   '--skip-comments','--compact','--set-gtid-purged=OFF','--column-statistics=0',
                   '--order-by-primary','--hex-blob']
        before=subprocess.check_output(prefix+data_args+['canovia_p0_ci'])
        after=subprocess.check_output(prefix+data_args+['canovia_restore_ci'])
        assert before == after, 'Restored data differs'
        trigger_query="SELECT TRIGGER_NAME,ACTION_TIMING,EVENT_MANIPULATION,EVENT_OBJECT_TABLE,ACTION_STATEMENT FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() ORDER BY TRIGGER_NAME"
        source_triggers=sql(trigger_query)
        assert source_triggers and source_triggers == sql(trigger_query,'canovia_restore_ci'), 'Trigger definitions differ'
        # Row equality cannot prove the newly enforced Plan unique/FK index
        # survived restore. Compare ALL columns/indexes/FKs as well as triggers.
        schema_query=(Path(__file__).resolve().parents[2] / 'scripts/sql/p0_mysql_disposable_full_schema_fingerprint.sql').read_text()
        source_schema=sql(schema_query)
        assert source_schema == sql(schema_query,'canovia_restore_ci'), 'Restored physical schema differs'
        assert sql('SELECT COUNT(*) FROM migrations','canovia_restore_ci').strip() == b'114'
        result=dict(container=name,result='passed',source_ledger=114,restored_ledger=114,
                    dump_bytes=len(data),data_sha256=hashlib.sha256(before).hexdigest(),
                    trigger_sha256=hashlib.sha256(source_triggers).hexdigest(),
                    schema_sha256=hashlib.sha256(source_schema).hexdigest(),
                    candidate_sha=candidate,source_clean_verified=True,
                    elapsed_seconds=round(time.monotonic()-start,2),
                    scope='DISPOSABLE_LOGICAL_RESTORE_ONLY',aiven_backup_verified=False,release_authorized=False)
        (out/(name+'-restore.json')).write_text(json.dumps(result,indent=2)+'\n')
        print(json.dumps(result))
    finally:
        sql('DROP DATABASE canovia_restore_ci;')
