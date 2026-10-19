#!/usr/bin/env python3
"""Offline e858 tooling acceptance / private metadata input. NO live transport."""
import argparse
from contextlib import redirect_stderr
import hashlib
import io
import json
import os
from pathlib import Path
import re
import stat
import subprocess
import sys

sys.dont_write_bytecode=True
ROOT=Path(__file__).resolve().parents[2]
sys.path.insert(0,str(ROOT/'scripts/ci'))
from e858_audit.core import (CANDIDATE,SEAL,RELEASE,Block,boundary,load_reference,
                            environment_guard,query_hashes,hashed,audit)
from integrated_release20_audit import parse_full_ledger
from p0_mysql_metadata_grants_guard import verify_grants


def unique_object(pairs):
    result={}
    for key,value in pairs:
        if key in result:raise Block('PRIVATE_INPUT_REJECTED')
        result[key]=value
    return result


def private_json(path, maximum):
    path=Path(path).absolute()
    # User-owned input is never deleted; this tool creates NO raw temp files.
    if path.is_symlink() or path.parent.is_symlink() or path.parent.stat().st_mode & 0o077:
        raise Block('PRIVATE_INPUT_REJECTED')
    if path.is_relative_to(ROOT):raise Block('PRIVATE_INPUT_REJECTED')
    fd=os.open(path,os.O_RDONLY|getattr(os,'O_NOFOLLOW',0))
    try:
        info=os.fstat(fd)
        if not stat.S_ISREG(info.st_mode) or info.st_uid!=os.getuid() or info.st_mode & 0o077 or info.st_size>maximum:
            raise Block('PRIVATE_INPUT_REJECTED')
        chunks=[];count=0
        while True:
            chunk=os.read(fd,32768)
            if not chunk:break
            count+=len(chunk)
            if count>maximum:raise Block('PRIVATE_INPUT_REJECTED')
            chunks.append(chunk)
        return json.loads(b''.join(chunks).decode('ascii'),object_pairs_hook=unique_object)
    finally:os.close(fd)


def receipt_check(path,tooling):
    receipts=private_json(path,1024*1024)
    if not isinstance(receipts,list) or not 1<=len(receipts)<=30:raise Block('CI_RECEIPT_REJECTED')
    ids=[];required=False
    for r in receipts:
        if (r['headSha']!=tooling or r['conclusion']!='success' or r['status']!='completed'
                or not re.fullmatch(r'https://github.com/1kz-ma1/Canovia-web/actions/runs/[1-9][0-9]*',r['url'])
                or not r['jobs'] or any(j['conclusion']!='success' or j['status']!='completed' for j in r['jobs'])):
            raise Block('CI_RECEIPT_REJECTED')
        if r['workflowName']=='E858 Source-bound Audit':
            names={j['name'] for j in r['jobs']}
            if len(r['jobs'])!=8 or not {'E858 offline safety','E858 reference (MySQL 8.0)',
                    'E858 reference (MySQL 8.4)'}.issubset(names):raise Block('CI_RECEIPT_REJECTED')
            required=True
        ids.append(int(r['url'].rsplit('/',1)[1]))
    if not required or len(set(ids))!=len(ids):raise Block('CI_RECEIPT_REJECTED')
    return ids


def metadata_input(path,facts,refs,contract):
    data=private_json(path,16*1024*1024)
    expected={'identity_before','identity_after','approved_identity_sha256','grants',
              'ledger_before','ledger_after','metadata','semantic','column_extra'}
    if set(data)!=expected or any(not isinstance(v,str) for v in data.values()):
        raise Block('PRIVATE_INPUT_REJECTED')
    identity=data['identity_before']
    if (identity!=data['identity_after'] or len(identity)>16384
            or hashlib.sha256(identity.encode()).hexdigest()!=data['approved_identity_sha256']):
        raise Block('PRIVATE_TARGET_BINDING_REJECTED')
    cells=identity.rstrip('\n').split('\t')
    if len(cells)!=4 or not identity.endswith('\n') or not re.fullmatch('[A-Za-z0-9_-]{1,64}',cells[0]):
        raise Block('PRIVATE_TARGET_BINDING_REJECTED')
    if not verify_grants(data['grants'],cells[0]):raise Block('METADATA_GRANTS_REJECTED')
    series=next((s for s in refs if cells[2].startswith(s+'.')),None)
    if series is None:raise Block('ENGINE_REJECTED')
    for key in ('metadata','semantic','column_extra','ledger_before','ledger_after'):
        value=data[key]
        if len(value.encode())>8*1024*1024 or any(len(r)>256*1024 or any(len(c)>128*1024 for c in r.split('\t')) for r in value.splitlines()):
            raise Block('PRIVATE_INPUT_REJECTED')
    # Physical/supplement parsers enforce known sections and complete row sets.
    for key in ('ledger_before','ledger_after'):
        if len(data[key].splitlines())>115:raise Block('QUERY_ROWS_EXCEEDED')
    from e858_audit.core import CAPS
    for key in ('metadata','semantic','column_extra'):
        counts={}
        for row in data[key].splitlines():
            label=row.split('\t')[0];counts[label]=counts.get(label,0)+1
            if label not in CAPS or counts[label]>CAPS[label]:raise Block('QUERY_ROWS_EXCEEDED')
    return audit(facts,parse_full_ledger(data['ledger_before']),parse_full_ledger(data['ledger_after']),
                 data['metadata'],data['semantic'],data['column_extra'],refs[series],contract)


def packet(candidate,tooling,receipts=None,private_input=None):
    environment_guard()
    facts,blobs=boundary(ROOT,candidate,tooling)
    refs={s:load_reference(ROOT,s,facts,blobs,tooling) for s in ('8.0','8.4')}
    contract=json.loads((ROOT/'resources/release20_pending_schema_contract.json').read_text())
    if private_input:return metadata_input(private_input,facts,refs,contract)
    if receipts is None:raise Block('CI_RECEIPTS_REQUIRED')
    ids=receipt_check(receipts,tooling)
    proc=subprocess.run([sys.executable,'-B','-m','unittest','discover','-s','tests/ci','-p','test_e858_audit.py'],
                        cwd=ROOT,stdout=subprocess.PIPE,stderr=subprocess.PIPE,timeout=90)
    if proc.returncode!=0:raise Block('OFFLINE_FIXTURE_TESTS_FAILED')
    # Deliberately execute only the legacy collector's OFFLINE mode.
    for script,args in [('integrated_release20_collect.py',['--source-sha',tooling]),
                        ('integrated_release20_diagnostic_collect.py',['--tooling-sha',tooling,'--candidate-sha',
                          'c09a8fc718c338f98a4045a4b5f2260c7571fdaa'])]:
        old=subprocess.run([sys.executable,'-B',str(ROOT/'scripts/ops'/script),*args],
                           stdout=subprocess.PIPE,stderr=subprocess.PIPE,timeout=30)
        if old.returncode!=2 or json.loads(old.stdout)['result']!='BLOCK':
            raise Block('LEGACY_BLOCK_NOT_PRESERVED')
    return dict(result='SOURCE_BOUND_TOOLING_OFFLINE_VERIFIED',packet_version=1,candidate_source_sha=candidate,
                tooling_sha=tooling,candidate_adopted=True,adoption_scope='AUDIT_CANDIDATE_ONLY',
                release_ref=RELEASE,release_pin_matches=True,migration_seal=SEAL,baseline_count=94,total_count=114,
                changed_pending_ids=['M01','M08','M18','M19','M20'],source_manifest_sha256=hashed(facts['candidate']),
                sql_bundle_sha256=hashed(query_hashes()),reference_hashes={s:hashlib.sha256(
                    (ROOT/f'scripts/ci/e858_audit_reference/{s}.json').read_bytes()).hexdigest() for s in refs},
                protected_blobs_sha256=hashed(blobs),ci_run_ids=ids,
                ci_evidence='OWNER_FETCHED_RECEIPTS_NOT_CRYPTOGRAPHIC_ATTESTATION',
                local_fixtures='EXECUTED_WITHOUT_DATABASE',local_mysql='NOT_RUN_CI_EVIDENCE_ONLY',
                tooling_mac_acceptance='PENDING_OWNER_CONFIRMATION',tooling_ready_for_owner_review=True,
                real_aiven_connected=False,production_modified=False,strict_block_waived=False,release_authorized=False,
                invalidation='SOURCE_TOOLING_BLOBS_REFS_SQL_REFERENCE_CI_OR_TARGET_DRIFT')


def main():
    try:
        parser=argparse.ArgumentParser(exit_on_error=False,add_help=False)
        parser.add_argument('--candidate-sha',required=True)
        parser.add_argument('--tooling-sha',required=True)
        parser.add_argument('--ci-receipts')
        parser.add_argument('--private-input')
        parser.add_argument('--collect',action='store_true')
        with redirect_stderr(io.StringIO()):args=parser.parse_args()
        if args.collect:raise Block('REAL_CONNECTION_DISABLED')
        result=packet(args.candidate_sha,args.tooling_sha,args.ci_receipts,args.private_input)
        code=0 if result['result']=='SOURCE_BOUND_TOOLING_OFFLINE_VERIFIED' else 2
    except Block as error:
        result=dict(result='BLOCK',code=error.code,real_aiven_connected=False,production_modified=False,
                    strict_block_waived=False,release_authorized=False,tooling_ready_for_owner_review=False);code=2
    except (Exception,SystemExit,KeyboardInterrupt):
        result=dict(result='BLOCK',code='OFFLINE_INPUT_OR_SOURCE_REJECTED',real_aiven_connected=False,
                    production_modified=False,strict_block_waived=False,release_authorized=False,
                    tooling_ready_for_owner_review=False);code=2
    print(json.dumps(result,sort_keys=True))
    return code


if __name__=='__main__':sys.exit(main())
