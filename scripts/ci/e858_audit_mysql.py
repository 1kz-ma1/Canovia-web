#!/usr/bin/env python3
"""Generate/verify new references ONLY in owned loopback Docker MySQL 8.0/8.4."""
import argparse
from contextlib import redirect_stderr
import io
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile

sys.dont_write_bytecode=True
from e858_audit.core import (ROOT,BASE,CANDIDATE,Block,boundary,git,model,absence,audit,
                            reference_binding,hashed,queries,load_reference)
from e858_audit.transport import Budget,bounded_query
from integrated_release20_audit import snapshot,parse_full_ledger
from integrated_release20_semantic_fixtures import structural_cases
from p0_mysql_metadata_grants_guard import verify_grants


def rehearse(series,facts,blobs,generator):
    name,port={'8.0':('canovia-integration-80','13380'),'8.4':('canovia-integration-84','13384')}[series]
    with tempfile.TemporaryDirectory(prefix='e858-disposable-private-') as directory:
        os.chmod(directory,0o700)
        tmp=Path(directory)
        with (tmp/'errors').open('w+b') as errors:
            def run(argv,*,data=None,env=None,deny=False):
                p=subprocess.run(argv,input=data,env=env,cwd=ROOT,stdout=subprocess.PIPE,stderr=errors,timeout=180)
                if (p.returncode==0)==deny:raise Block('DISPOSABLE_COMMAND_REJECTED')
                return p.stdout.decode('utf-8')
            state=json.loads(run(['docker','inspect',name]))[0]
            if (state['Config']['Labels'].get('canovia.integration')!='2026-10-16'
                    or state['NetworkSettings']['Ports'].get('3306/tcp')!=[{'HostIp':'127.0.0.1','HostPort':port}]
                    or not state['State']['Running']):raise Block('DISPOSABLE_TARGET_REJECTED')
            prefix=['docker','exec','-i','-e','MYSQL_PWD=ci-only-root-password',name,'mysql','-uroot',
                    '--batch','--raw','--skip-column-names','canovia_p0_ci']
            def ddl(text,deny=False):return run(prefix,data=text.encode(),deny=deny)
            budget=Budget()
            def query(key,narrow=False):
                command=prefix if not narrow else ['docker','exec','-i','-e','MYSQL_PWD=ci-only-e858-inspector',name,
                    'mysql','-ue858_inspector','--batch','--raw','--skip-column-names','canovia_p0_ci']
                # Fresh budget per capture; no retries, all IO through the bounded reader.
                return bounded_query(command,key,budget)
            version=ddl('SELECT VERSION();').strip()
            if not version.startswith(series+'.'):raise Block('DISPOSABLE_ENGINE_REJECTED')
            env={k:os.environ[k] for k in ('PATH','PHPRC','PHP_INI_SCAN_DIR','LD_LIBRARY_PATH','LANG') if k in os.environ}
            env.update(APP_ENV='testing',APP_KEY='base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
                DB_CONNECTION='mysql',DB_HOST='127.0.0.1',DB_PORT=port,DB_DATABASE='canovia_p0_ci',
                DB_USERNAME='canovia_ci',DB_PASSWORD='ci-only-ephemeral-password',DB_URL='',DATABASE_URL='',
                CACHE_STORE='array',SESSION_DRIVER='database',QUEUE_CONNECTION='sync',MAIL_MAILER='array',
                CANOVIA_PUBLIC_RELEASE_LEVEL='0',CANOVIA_NATIVE_AI_DRIVER='disabled',FEATURE_CANOVIA_AI='false',
                CI='true',CANOVIA_P0_DISPOSABLE_MYSQL_CI='1')
            def php(*args):return run(['php',*args],env=env)
            old_dir=tmp/'old';all_dir=tmp/'all';old_dir.mkdir();all_dir.mkdir()
            for migration in facts['candidate']:
                path='database/migrations/'+migration+'.php'
                raw=subprocess.check_output(['git','show',CANDIDATE+':'+path],cwd=ROOT,stderr=errors)
                (all_dir/(migration+'.php')).write_bytes(raw)
                if migration in facts['baseline']:(old_dir/(migration+'.php')).write_bytes(raw)
            def capture(narrow=False):
                nonlocal budget
                budget=Budget()
                raw=''.join(query(k,narrow) for k in ('engine','table','column','index','foreign_key','check','trigger'))
                supplemental=''.join(query(k,narrow) for k in ('semantic_version','semantic_index','semantic_foreign_key'))
                extra=query('column_extra',narrow)
                return raw,supplemental,extra
            builds=[];original=json.loads((ROOT/f'scripts/ci/integrated_release20_reference/{series}.json').read_text())
            for build in range(2):
                ddl('DROP DATABASE canovia_p0_ci; CREATE DATABASE canovia_p0_ci CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;')
                php('artisan','migrate','--realpath','--path='+str(old_dir),'--force','--no-interaction')
                php('scripts/ci/integration_upgrade_fixture.php','seed')
                seal=str(tmp/f'old-rows-{build}.json')
                php('scripts/ci/integration_upgrade_fixture.php','snapshot',seal)
                baseline=capture();old_ledger=parse_full_ledger(query('ledger'))
                if set(old_ledger)!=set(facts['baseline']):raise Block('BASELINE_LEDGER_REJECTED')
                php('artisan','migrate','--realpath','--path='+str(all_dir),'--force','--no-interaction')
                full=capture();new_ledger=parse_full_ledger(query('ledger'))
                if set(new_ledger)!=set(facts['candidate']):raise Block('CANDIDATE_LEDGER_REJECTED')
                ddl("INSERT INTO learning_context_source_epochs (plan_id,epoch,last_mutation_kind) SELECT id,7,'synthetic_recovery_seal' FROM plans WHERE category='資格学習';")
                php('scripts/ci/integration_upgrade_fixture.php','verify',seal)
                php('artisan','migrate','--realpath','--path='+str(all_dir),'--force','--no-interaction')
                if capture()!=full or parse_full_ledger(query('ledger'))!=new_ledger:raise Block('REPLAY_SHAPE_CHANGED')
                php('scripts/ci/integration_upgrade_fixture.php','verify',seal)
                contract=absence(baseline[0],full[0])
                source_contract=json.loads((ROOT/'resources/release20_pending_schema_contract.json').read_text())
                if contract!={k:source_contract[k] for k in contract}:raise Block('PENDING_CONTRACT_REJECTED')
                ref=reference_binding(facts,blobs,generator)
                ref.update(engine=series,independent_build_count=2,pending_contract_sha256=hashed(contract),
                           baseline94=model(*baseline)[0],candidate114=model(*full)[0])
                for stage in ('baseline94','candidate114'):
                    if ref[stage]['physical']!=original[stage]:raise Block('ORIGINAL_PHYSICAL_REGRESSION')
                builds.append(ref)
            if builds[0]!=builds[1]:raise Block('DOUBLE_BUILD_NOT_REPRODUCIBLE')
            ref=builds[0];cases={}
            def check(label,ledger,payload,strict):
                result=audit(facts,ledger,ledger,*payload,ref,contract)
                if result['strict_result']!=strict or result['release_authorized'] or result['strict_block_waived']:
                    raise Block('SYNTHETIC_VERDICT_REJECTED')
                cases[label]=dict(strict_result=strict,real_gates='UNVERIFIED')
                return result
            check('normal94_pre_migration',old_ledger,baseline,'REVIEW_REQUIRED')
            check('normal114_target',new_ledger,full,'REVIEW_REQUIRED')
            ddl('ALTER TABLE intelligence_decision_traces RENAME INDEX intelligence_decision_traces_domain_index TO e858_synthetic_alias;')
            out=check('index_name_only',new_ledger,capture(),'BLOCK')
            if out['semantic_sections'][0]['classification']!='NAME_ONLY_DIFFERENCE':raise Block('ALIAS_CLASSIFICATION_REJECTED')
            fk=next(r.split('\t') for r in full[0].splitlines() if r.startswith('foreign_key\tintelligence_decision_traces\tintelligence_decision_traces_user_id_foreign\t'))
            ddl(f'ALTER TABLE intelligence_decision_traces DROP FOREIGN KEY intelligence_decision_traces_user_id_foreign, ADD CONSTRAINT e858_synthetic_fk FOREIGN KEY (user_id) REFERENCES users(id) ON UPDATE {fk[7]} ON DELETE {fk[8]};')
            check('index_and_fk_name_only',new_ledger,capture(),'BLOCK')
            ddl('ALTER TABLE intelligence_decision_traces RENAME INDEX e858_synthetic_alias TO intelligence_decision_traces_domain_index;')
            out=check('fk_name_only',new_ledger,capture(),'BLOCK')
            if out['semantic_sections'][1]['classification']!='NAME_ONLY_DIFFERENCE':raise Block('ALIAS_CLASSIFICATION_REJECTED')
            ddl(f'ALTER TABLE intelligence_decision_traces DROP FOREIGN KEY e858_synthetic_fk, ADD CONSTRAINT intelligence_decision_traces_user_id_foreign FOREIGN KEY (user_id) REFERENCES users(id) ON UPDATE {fk[7]} ON DELETE {fk[8]};')
            # Existing immutable semantic structural negatives use only this disposable DB.
            structural=structural_cases(ddl,(ROOT/'scripts/sql/integrated_release20_metadata.sql').read_text(),
                                       (ROOT/'scripts/sql/integrated_release20_semantic_metadata.sql').read_text())
            cases.update({label:dict(result='SEMANTIC_NEGATIVE_VERIFIED') for label in structural})
            ddl('ALTER TABLE learning_context_source_epochs ADD UNIQUE INDEX e858_extra_unique (last_mutation_kind);')
            check('extra_unique',new_ledger,capture(),'BLOCK')
            ddl('ALTER TABLE learning_context_source_epochs DROP INDEX e858_extra_unique;')
            ddl('CREATE TABLE e858_synthetic_partial (id BIGINT PRIMARY KEY);')
            check('partial_ddl',new_ledger,capture(),'BLOCK')
            ddl('DROP TABLE e858_synthetic_partial;')
            # Narrow identity has no business-row or TRIGGER privilege. Never widen it.
            ddl("DROP USER IF EXISTS 'e858_inspector'@'%'; CREATE USER 'e858_inspector'@'%' IDENTIFIED BY 'ci-only-e858-inspector'; GRANT REFERENCES ON canovia_p0_ci.* TO 'e858_inspector'@'%'; GRANT SELECT ON canovia_p0_ci.migrations TO 'e858_inspector'@'%';")
            try:
                budget=Budget()
                if not verify_grants(query('grants',True),'canovia_p0_ci'):raise Block('NARROW_GRANTS_REJECTED')
                narrow=capture(True)
                out=check('trigger_invisible',new_ledger,narrow,'BLOCK')
                if out['trigger_visibility']!='UNVERIFIED':raise Block('VISIBILITY_ESCALATED')
                for row in [r.split('\t') for r in full[0].splitlines() if r.startswith('trigger\t')]:
                    if not row[2].replace('_','').isalnum():raise Block('FIXTURE_IDENTIFIER_REJECTED')
                    ddl('DROP TRIGGER `'+row[2]+'`;')
                absent=capture()
                if snapshot(absent[0])!=snapshot(narrow[0]):raise Block('TRIGGER_INVISIBILITY_NOT_REPRODUCED')
                check('trigger_absent',new_ledger,absent,'BLOCK')
            finally:ddl("DROP USER IF EXISTS 'e858_inspector'@'%';")
            return ref,dict(engine=series,version=version,independent_build_count=2,cases=cases,
                            source_sha=CANDIDATE,tooling_sha=git(ROOT,'rev-parse','HEAD'),
                            result='SYNTHETIC_DB_VERIFIED',old_rows_retained=True,
                            original_physical_hashes_unchanged=True,real_aiven_connected=False,
                            production_modified=False,strict_block_waived=False,release_authorized=False)


def main():
    try:
        parser=argparse.ArgumentParser(exit_on_error=False,add_help=False)
        parser.add_argument('--series',choices=['8.0','8.4'])
        parser.add_argument('--record',action='store_true')
        with redirect_stderr(io.StringIO()):args=parser.parse_args()
        if args.record and (args.series or os.environ.get('GITHUB_ACTIONS')=='true'):
            raise Block('REFERENCE_RECORDING_REJECTED')
        tooling=git(ROOT,'rev-parse','HEAD')
        facts,blobs=boundary(ROOT,CANDIDATE,tooling)
        refs={};reports=[]
        for series in ([args.series] if args.series else ['8.0','8.4']):
            generator=tooling if args.record else load_reference(ROOT,series,facts,blobs,tooling)['generator_tooling_sha']
            ref,report=rehearse(series,facts,blobs,generator)
            if not args.record and ref!=load_reference(ROOT,series,facts,blobs,tooling):
                raise Block('REFERENCE_REBUILD_REJECTED')
            refs[series]=ref;reports.append(report)
        if args.record:
            for series,ref in refs.items():
                (ROOT/f'scripts/ci/e858_audit_reference/{series}.json').write_text(json.dumps(ref,sort_keys=True,indent=2)+'\n')
        print(json.dumps(dict(result='SYNTHETIC_DB_VERIFIED',reports=reports,reference_recorded=args.record,
                              real_aiven_connected=False,release_authorized=False),sort_keys=True));return 0
    except Block as error:
        print(json.dumps(dict(result='BLOCK',code=error.code,real_aiven_connected=False,release_authorized=False)));return 2
    except (Exception,SystemExit,KeyboardInterrupt):
        print(json.dumps(dict(result='BLOCK',code='DISPOSABLE_REHEARSAL_REJECTED',real_aiven_connected=False,release_authorized=False)));return 2


if __name__=='__main__':sys.exit(main())
