import copy
import hashlib
import importlib.util
import io
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest
from unittest.mock import patch

ROOT=Path(__file__).resolve().parents[2]
sys.path.insert(0,str(ROOT/'scripts/ci'))
from e858_audit import core
from e858_audit.transport import Budget,bounded_query
from integrated_release20_audit import InvalidLedger
spec=importlib.util.spec_from_file_location('e858_packet',ROOT/'scripts/ops/e858_audit_packet.py')
packet=importlib.util.module_from_spec(spec);spec.loader.exec_module(packet)


def fixture():
    table,index=core.DEPENDENCY_KEY
    def line(*parts):return '\t'.join(parts)+'\n'
    raw=line('engine','8.0')+line('table',table,'BASE TABLE','InnoDB','utf8mb4_unicode_ci')
    raw+=line('column',table,'1','identity_fingerprint','char(64)','NO','NULL','','utf8mb4_unicode_ci')
    raw+=line('index',table,index,'0','1','identity_fingerprint','0','BTREE','YES','')
    sem=line('semantic_version','1')+line('semantic_index',table,index,'0','1','identity_fingerprint','0','BTREE','YES','','A','')
    extra=line('column_extra',table,'1','identity_fingerprint','utf8mb4','')
    model=core.model(raw,sem,extra)[0]
    reference=dict(engine='8.0',baseline94=model,candidate114=model)
    contract=dict(absent_tables=[],absent_columns={},absent_index_names={},absent_foreign_key_names=[],absent_trigger_names=[])
    facts={'baseline':{'old':'blob'},'candidate':{'old':'blob','new':'blob'},'migration_seal':core.SEAL}
    return facts,{'old':1},raw,sem,extra,reference,contract


class MetadataTests(unittest.TestCase):
    def evaluate(self,modify=None):
        facts,ledger,raw,sem,extra,ref,contract=fixture()
        if modify:raw,sem,extra=modify(raw,sem,extra)
        return core.audit(facts,ledger,ledger,raw,sem,extra,ref,contract)

    def test_exact94_is_not_114_or_real_gate_pass(self):
        out=self.evaluate()
        self.assertEqual('baseline94',out['stage']);self.assertEqual('REVIEW_REQUIRED',out['strict_result'])
        self.assertEqual('BLOCK_REAL_GATES_UNVERIFIED',out['result'])
        for key in ['release_authorized','real_aiven_connected','production_modified','strict_block_waived']:
            self.assertIs(out[key],False)
        self.assertEqual('UNVERIFIED',out['trigger_visibility'])
        self.assertEqual('UNVERIFIED_METADATA_ONLY',out['identity_data_collision'])

    def test_114_model_is_distinct(self):
        f,l,r,s,e,ref,c=fixture();l['new']=2
        out=core.audit(f,l,l,r,s,e,ref,c);self.assertEqual('candidate114',out['stage'])

    def test_unknown_missing_and_partial_ledger_block(self):
        f,l,r,s,e,ref,c=fixture()
        for ledger in ({},{'unknown':1},{'new':2}):
            out=core.audit(f,ledger,ledger,r,s,e,ref,c);self.assertEqual('BLOCK',out['strict_result'])

    def test_changed_ledger_rejected(self):
        f,l,r,s,e,ref,c=fixture()
        with self.assertRaisesRegex(core.Block,'LEDGER_CHANGED'):core.audit(f,l,{'old':2},r,s,e,ref,c)

    def test_name_only_cannot_waive_and_m08_alias_is_refused(self):
        name=core.DEPENDENCY_KEY[1]
        out=self.evaluate(lambda r,s,e:(r.replace(name,'synthetic_alias'),s.replace(name,'synthetic_alias'),e))
        self.assertEqual('NAME_ONLY_DIFFERENCE',out['semantic_sections'][0]['classification'])
        self.assertEqual('BLOCK',out['strict_result']);self.assertEqual('BLOCK',out['original_strict_result'])
        self.assertEqual('MISSING_INCOMPATIBLE_OR_AMBIGUOUS_BLOCK',out['m08_name_dependency'])

    def test_same_count_unique_difference_rejected(self):
        out=self.evaluate(lambda r,s,e:(r.replace('\t0\t1\t','\t1\t1\t'),s.replace('\t0\t1\t','\t1\t1\t'),e))
        self.assertEqual('SEMANTIC_DEFINITION_DIFFERENCE',out['semantic_sections'][0]['classification'])
        self.assertEqual('BLOCK',out['strict_result'])

    def test_generated_expression_supplement_tightens_original_match(self):
        out=self.evaluate(lambda r,s,e:(r,s,e.rstrip('\n')+'31\n'))
        self.assertEqual('REVIEW_REQUIRED',out['original_strict_result']);self.assertEqual('BLOCK',out['strict_result'])
        self.assertEqual('EXTENDED_METADATA_OR_DEPENDENCY_BLOCK',out['strict_code'])

    def test_trigger_order_is_not_normalized_away(self):
        f,l,r,s,e,ref,c=fixture()
        r+='trigger\tsynthetic\tfirst\tUPDATE\tBEFORE\t1\t53455420313D31\n'
        ref['baseline94']=core.model(r,s,e)[0]
        changed=r.replace('BEFORE\t1\t','BEFORE\t2\t')
        self.assertEqual('BLOCK',core.audit(f,l,l,changed,s,e,ref,c)['strict_result'])

    def test_malformed_missing_and_repeated_supplement_refused(self):
        f,l,r,s,e,ref,c=fixture()
        for sem,extra in [(s+'semantic_version\t1\n',e),(s,''),(s,e+e)]:
            with self.assertRaises((core.Block,InvalidLedger)):core.audit(f,l,l,r,sem,extra,ref,c)

    def test_source_contract_collision_is_separate(self):
        f,l,r,s,e,ref,c=fixture();c['absent_tables']=[core.DEPENDENCY_KEY[0]]
        out=core.audit(f,l,l,r,s,e,ref,c);self.assertEqual(1,out['pending_collision_count']);self.assertEqual('BLOCK',out['strict_result'])

    def test_no_private_identifiers_in_diagnostic_output(self):
        out=json.dumps(self.evaluate())
        for secret in core.DEPENDENCY_KEY:self.assertNotIn(secret,out)


class TransportTests(unittest.TestCase):
    def client(self,source):return [sys.executable,'-c',source]

    def test_fixed_statement_is_only_stdin(self):
        command=self.client("import sys; q=sys.stdin.buffer.read(); assert q==b'SHOW GRANTS FOR CURRENT_USER();\\n'; print('synthetic')")
        self.assertEqual('synthetic\n',bounded_query(command,'grants',Budget()))

    def test_empty_trigger_is_observation_only_not_visibility_proof(self):
        self.assertEqual('',bounded_query(self.client('pass'),'trigger',Budget()))
        self.assertEqual('UNVERIFIED',MetadataTests().evaluate()['trigger_visibility'])

    def test_unknown_sql_ddl_multi_statement_never_starts_a_client(self):
        for key,sql in [('not-a-query',None),('engine',b'DROP TABLE synthetic;'),('engine',b'SELECT 1; SELECT 2;')]:
            with patch('subprocess.Popen') as start:
                with self.assertRaisesRegex(core.Block,'SQL_NOT_ALLOWLISTED'):
                    bounded_query([],key,Budget(),sql=sql)
                start.assert_not_called()

    def test_row_cap_plus_one_never_truncates_to_success(self):
        with self.assertRaisesRegex(core.Block,'QUERY_ROWS_EXCEEDED'):
            bounded_query(self.client("print('engine\\t8.0\\nengine\\t8.0')"),'engine',Budget())

    def test_query_and_cumulative_stream_byte_caps(self):
        command=self.client("print('x'*10000)")
        for budget,maximum in [(Budget(),32),(Budget(maximum=32),100000)]:
            with self.assertRaisesRegex(core.Block,'QUERY_BYTES_EXCEEDED'):
                bounded_query(command,'grants',budget,max_bytes=maximum)

    def test_cell_and_row_limit(self):
        for kwargs in [dict(cell_bytes=10),dict(row_bytes=10)]:
            with self.assertRaisesRegex(core.Block,'QUERY_FIELD_EXCEEDED'):
                bounded_query(self.client("print('x'*100)"),'grants',Budget(),**kwargs)

    def test_timeout_and_expired_overall_budget(self):
        with self.assertRaisesRegex(core.Block,'QUERY_TIMEOUT'):
            bounded_query(self.client('import time;time.sleep(2)'),'engine',Budget(),seconds=.05)
        with patch('subprocess.Popen') as start:
            with self.assertRaisesRegex(core.Block,'QUERY_TIMEOUT'):
                bounded_query([],'engine',Budget(seconds=-1))
            start.assert_not_called()

    def test_nonzero_stderr_non_ascii_partial_and_empty_are_private_blocks(self):
        cases=["import sys;sys.stderr.write('SYNTHETIC_SECRET');sys.exit(2)",
               "import sys;sys.stdout.buffer.write(b'\\xff\\n')", "print('partial',end='')",'pass',
               "print('unsafe\\x1b')",'raise RuntimeError("SYNTHETIC_SECRET")']
        for source in cases:
            with self.assertRaises(core.Block) as caught:bounded_query(self.client(source),'grants',Budget())
            self.assertNotIn('SYNTHETIC_SECRET',str(caught.exception))

    def test_keyboard_interrupt_kills_without_leaking(self):
        with patch('e858_audit.transport.selectors.DefaultSelector') as selector:
            selector.return_value.select.side_effect=KeyboardInterrupt()
            with self.assertRaises(core.Block):
                bounded_query(self.client('import time;time.sleep(1)'),'engine',Budget(),seconds=.1)


class SourceTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.tmp=tempfile.TemporaryDirectory(prefix='e858-source-test-')
        cls.clone=Path(cls.tmp.name).resolve()/'repo'
        subprocess.run(['git','clone','--quiet','--shared',str(ROOT),str(cls.clone)],check=True,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
        cls.head=core.git(ROOT,'rev-parse','HEAD')
        subprocess.run(['git','-C',str(cls.clone),'checkout','--quiet','--detach',cls.head],check=True,stderr=subprocess.DEVNULL)
        for ref in [core.RELEASE,'main']:
            subprocess.run(['git','-C',str(cls.clone),'update-ref','refs/remotes/origin/'+ref,core.CANDIDATE],check=True)

    @classmethod
    def tearDownClass(cls):cls.tmp.cleanup()

    def test_frozen_source_manifest_and_clean_tooling(self):
        facts,blobs=core.boundary(self.clone,core.CANDIDATE,self.head)
        self.assertEqual(core.SEAL,facts['migration_seal']);self.assertEqual(114,len(facts['candidate']))
        self.assertEqual(94,len(facts['baseline']));self.assertIn('scripts/ops/e858_audit_packet.py',blobs)

    def test_wrong_candidate_tooling_and_release_pin(self):
        for candidate,tooling in [(core.OLD,self.head),(core.CANDIDATE,'a'*40)]:
            with self.assertRaises(core.Block):core.boundary(self.clone,candidate,tooling)
        ref='refs/remotes/origin/'+core.RELEASE
        try:
            subprocess.run(['git','-C',str(self.clone),'update-ref',ref,core.OLD],check=True)
            with self.assertRaisesRegex(core.Block,'RELEASE_PIN_REJECTED'):core.boundary(self.clone,core.CANDIDATE,self.head)
        finally:subprocess.run(['git','-C',str(self.clone),'update-ref',ref,core.CANDIDATE],check=True)

    def test_dirty_and_shallow_are_blocks(self):
        file=self.clone/'synthetic-dirty';file.write_text('synthetic')
        try:
            with self.assertRaisesRegex(core.Block,'CLEAN_CHECKOUT_REQUIRED'):core.boundary(self.clone,core.CANDIDATE,self.head)
        finally:file.unlink()
        shallow=Path(self.tmp.name).resolve()/'shallow'
        subprocess.run(['git','clone','--quiet','--depth=1',ROOT.as_uri(),str(shallow)],check=True,
                       stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
        with self.assertRaisesRegex(core.Block,'FULL_HISTORY_REQUIRED'):core.boundary(shallow,core.CANDIDATE,self.head)

    def test_ignored_assume_unchanged_and_symlink_cannot_replace_sql(self):
        relative='scripts/ci/e858_audit/queries/engine.sql';file=self.clone/relative;data=file.read_bytes()
        subprocess.run(['git','-C',str(self.clone),'update-index','--assume-unchanged',relative],check=True)
        try:
            file.write_text('SELECT 1;\n')
            with self.assertRaisesRegex(core.Block,'SOURCE_FILE_REJECTED'):core.boundary(self.clone,core.CANDIDATE,self.head)
            file.unlink();file.symlink_to(ROOT/relative)
            with self.assertRaisesRegex(core.Block,'SOURCE_FILE_REJECTED'):core.boundary(self.clone,core.CANDIDATE,self.head)
        finally:
            file.unlink();file.write_bytes(data)
            subprocess.run(['git','-C',str(self.clone),'update-index','--no-assume-unchanged',relative],check=True)

    def test_reference_candidate_generator_hash_and_old_proposed_rejected(self):
        facts,blobs=core.boundary(self.clone,core.CANDIDATE,self.head)
        for series in ('8.0','8.4'):
            ref=core.load_reference(self.clone,series,facts,blobs,self.head)
            self.assertEqual(2,ref['independent_build_count'])
            path=self.clone/f'scripts/ci/e858_audit_reference/{series}.json';raw=path.read_bytes()
            try:
                path.write_bytes((ROOT/f'scripts/ci/release20_proposed_reference/{series}.json').read_bytes())
                with self.assertRaises(core.Block):core.load_reference(self.clone,series,facts,blobs,self.head)
            finally:path.write_bytes(raw)


class OperatorTests(unittest.TestCase):
    def private_fixture(self,directory):
        f,l,r,s,e,ref,c=fixture()
        migration='2026_01_01_000000_synthetic'
        f['baseline']={migration:'blob'};f['candidate']={migration:'blob','2026_01_02_000000_synthetic':'blob'}
        identity='synthetic_ci\tci@localhost\t8.0.46\tsynthetic-uuid\n'
        data=dict(identity_before=identity,identity_after=identity,
                  approved_identity_sha256=hashlib.sha256(identity.encode()).hexdigest(),
                  grants='GRANT REFERENCES ON `synthetic_ci`.* TO `ci`@`localhost`\nGRANT SELECT ON `synthetic_ci`.`migrations` TO `ci`@`localhost`\n',
                  ledger_before='migration_ledger\t'+migration+'\t1\n',
                  ledger_after='migration_ledger\t'+migration+'\t1\n',metadata=r,semantic=s,column_extra=e)
        path=Path(directory).resolve()/'bundle.json';path.write_text(json.dumps(data));path.chmod(0o600)
        return path,data,f,{'8.0':ref},c

    def test_private_bundle_is_offline_and_never_proves_real_visibility(self):
        with tempfile.TemporaryDirectory() as tmp:
            path,data,f,refs,c=self.private_fixture(tmp)
            with patch.object(packet.subprocess,'run') as run:
                result=packet.metadata_input(path,f,refs,c);run.assert_not_called()
            self.assertEqual('REVIEW_REQUIRED',result['strict_result'])
            self.assertEqual('UNVERIFIED',result['trigger_visibility'])
            self.assertNotIn('synthetic_ci',json.dumps(result))

    def test_overprivilege_target_switch_and_ledger_change_are_fixed_blocks(self):
        with tempfile.TemporaryDirectory() as tmp:
            path,data,f,refs,c=self.private_fixture(tmp)
            for field,value,code in [
                    ('grants','GRANT ALL PRIVILEGES ON *.* TO `ci`@`localhost`\n','METADATA_GRANTS_REJECTED'),
                    ('identity_after','another-target','PRIVATE_TARGET_BINDING_REJECTED'),
                    ('ledger_after',data['ledger_after'].replace('\t1\n','\t2\n'),'LEDGER_CHANGED')]:
                changed=dict(data);changed[field]=value;path.write_text(json.dumps(changed))
                with self.assertRaisesRegex(core.Block,code):packet.metadata_input(path,f,refs,c)

    def test_fifo_and_symlink_private_input_rejected_without_reading(self):
        with tempfile.TemporaryDirectory() as tmp:
            directory=Path(tmp).resolve();fifo=directory/'fifo';os.mkfifo(fifo,0o600)
            with self.assertRaisesRegex(core.Block,'PRIVATE_INPUT_REJECTED'):packet.private_json(fifo,1024)
            target=directory/'target';target.write_text('{}');target.chmod(0o600)
            link=directory/'link';link.symlink_to(target)
            with self.assertRaisesRegex(core.Block,'PRIVATE_INPUT_REJECTED'):packet.private_json(link,1024)

    def test_real_mode_and_unknown_arguments_never_start_any_subprocess(self):
        for argv in [['--collect','--candidate-sha',core.CANDIDATE,'--tooling-sha','a'*40],['SYNTHETIC_SECRET']]:
            with patch.object(sys,'argv',['e858',*argv]),patch.object(packet.subprocess,'run') as run,patch('sys.stdout',new_callable=io.StringIO) as out:
                self.assertEqual(2,packet.main());run.assert_not_called();self.assertNotIn('SYNTHETIC_SECRET',out.getvalue())

    def test_host_secret_and_ci_cannot_enable_real_mode(self):
        with patch.dict(os.environ,{'CI':'true','DB_HOST':'synthetic.invalid','MYSQL_PWD':'SYNTHETIC_SECRET'},clear=True):
            with self.assertRaisesRegex(core.Block,'ENVIRONMENT_OVERRIDE_REJECTED'):core.environment_guard()

    def test_error_redaction_and_interrupt(self):
        for error in [RuntimeError('SYNTHETIC_SECRET'),KeyboardInterrupt()]:
            with patch.object(sys,'argv',['e858','--candidate-sha',core.CANDIDATE,'--tooling-sha','a'*40]),patch.object(packet,'packet',side_effect=error),patch('sys.stdout',new_callable=io.StringIO) as out:
                self.assertEqual(2,packet.main());self.assertNotIn('SYNTHETIC_SECRET',out.getvalue())

    def test_private_input_modes_duplicate_keys_and_ci_receipt_mismatch(self):
        with tempfile.TemporaryDirectory() as tmp:
            f=Path(tmp)/'input.json';f.write_text('{"x":1,"x":2}');f.chmod(0o600)
            with self.assertRaises(core.Block):packet.private_json(f,4096)
            f.write_text('[]');f.chmod(0o644)
            with self.assertRaises(core.Block):packet.private_json(f,4096)
            f.chmod(0o600);f.write_text('[{"headSha":"wrong","conclusion":"success","status":"completed"}]')
            with self.assertRaises(core.Block):packet.receipt_check(f,'a'*40)


if __name__=='__main__':unittest.main()
