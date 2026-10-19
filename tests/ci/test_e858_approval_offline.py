"""Pure synthetic tests used by owner packet: no files, children, or networking."""
import copy
from concurrent.futures import ThreadPoolExecutor
import importlib.util
import io
import json
from pathlib import Path
import sys
import threading
import unittest
from unittest.mock import patch

ROOT=Path(__file__).resolve().parents[2]
sys.path.insert(0,str(ROOT/'scripts/ci'))
from e858_approval_offline import contracts as c
from e858_approval_offline import guard


def load_packet():
    spec=importlib.util.spec_from_file_location('approval_packet',ROOT/'scripts/ci/e858_approval_offline/packet.py')
    value=importlib.util.module_from_spec(spec);spec.loader.exec_module(value)
    return value


class ContractTests(unittest.TestCase):
    def setUp(self):
        self.head='a'*40;self.value=c.payload(self.head);self.tls=c.tls_evidence()
        self.authority=c.SyntheticAuthority();self.clock=c.FakeClock();self.store=c.SyntheticOneShot()
        self.proofs=self.authority.endorsements(c.canonical(self.value))

    def review(self):
        return c.review(self.value,self.proofs,self.authority,self.clock,self.head,self.tls)

    def consume(self,record):
        return self.store.consume(record,self.value,self.proofs,self.authority,self.clock,self.head,self.tls)

    def test_positive_synthetic_contract_is_never_real_authority(self):
        out=self.consume(self.review())
        self.assertEqual('SYNTHETIC_VALIDATED',out['state'])
        self.assertEqual('READY_FOR_OWNER_REVIEW',out['next_state'])
        self.assertEqual('MISSING_REAL_AUTHORITY',out['authority'])
        self.assertEqual(list(c.REAL_GAPS),out['real_gaps'])
        for field in ('real_operator_authenticated','release_authorized','real_aiven_connected',
                      'production_modified','strict_block_waived'):
            self.assertIs(False,out[field])

    def test_both_engine_profiles(self):
        for engine in ('8.0','8.4'):
            v=c.payload(self.head,engine);p=self.authority.endorsements(c.canonical(v))
            record=c.review(v,p,self.authority,c.FakeClock(),self.head,c.tls_evidence(engine))
            self.assertIsInstance(record,c.Review)

    def test_canonical_order_types_and_duplicate_keys(self):
        raw=c.canonical(self.value)
        self.assertEqual(raw,c.canonical(dict(reversed(list(self.value.items())))))
        self.assertEqual(self.value,c.decode(raw))
        for data in (b'{"kind":1,"kind":2}',b'{"target":{"host":1,"host":2}}'):
            with self.assertRaisesRegex(c.Block,'JSON_DUPLICATE_KEY'):c.decode(data)
        for raw in (b'{',b'x'*16385,b'{"x":NaN}',b'{"x":1.1}',b'{"x":"\\u0000"}',
                    b'{"x":"\\u65e5"}',b'{"x":"\\u007f"}',b'\xff',b'['*30+b'0'+b']'*30):
            with self.assertRaises(c.Block):c.decode(raw)

    def test_unknown_mutable_and_real_approval_fields_rejected(self):
        for key,value in [('approved',True),('signature','self-issued'),('public_key','synthetic'),
                          ('credential','synthetic'),('unknown',{}),('grant',True)]:
            self.value[key]=value
            with self.assertRaisesRegex(c.Block,'APPROVAL_FIELDS_REJECTED'):self.review()
            del self.value[key]
        self.value['kind']='REAL'
        with self.assertRaisesRegex(c.Block,'MISSING_REAL_AUTHORITY'):self.review()

    def test_unsigned_hash_receipt_or_comment_cannot_endorse(self):
        for proofs in ({},{'approved':True},{'comment':6270282307},
                       {'sha256':'a'*64},{'owner':self.proofs['owner']}):
            with self.assertRaises(c.Block):
                c.review(self.value,proofs,self.authority,self.clock,self.head,self.tls)
        for real in ({},self.value,{'approved':True,'ci':'success'}):
            with self.assertRaisesRegex(c.Block,'MISSING_REAL_AUTHORITY'):c.real_endorsement(real)

    def test_self_issued_key_and_public_key_swap_cannot_pass(self):
        other=c.SyntheticAuthority()
        with self.assertRaisesRegex(c.Block,'SYNTHETIC_MAC_MISMATCH'):
            c.review(self.value,other.endorsements(c.canonical(self.value)),self.authority,
                     self.clock,self.head,self.tls)
        self.proofs['owner']['public_key']='SYNTHETIC_ONLY'
        with self.assertRaisesRegex(c.Block,'SYNTHETIC_SIGNER_REJECTED'):self.review()

    def test_wrong_signer_roles_and_missing_proof(self):
        for role in ('owner','operator'):
            for key,value in [('role','operator' if role=='owner' else 'owner'),
                              ('signer','synthetic_unknown'),('generation',0)]:
                proofs=copy.deepcopy(self.proofs);proofs[role][key]=value
                with self.assertRaises(c.Block):
                    c.review(self.value,proofs,self.authority,self.clock,self.head,self.tls)

    def test_tampered_payload_and_mac_rejected(self):
        self.value['nonce']='b'*32
        with self.assertRaisesRegex(c.Block,'SYNTHETIC_MAC_MISMATCH'):self.review()
        self.value['nonce']='a'*32;self.proofs['owner']['mac']='0'*64
        with self.assertRaisesRegex(c.Block,'SYNTHETIC_MAC_MISMATCH'):self.review()

    def test_every_fixed_pin_scope_order_and_cap_is_bound(self):
        for key in c.FIELDS-{'nonce','not_before','expires_at','target'}:
            value=copy.deepcopy(self.value);value[key]='SYNTHETIC_CHANGED'
            with self.assertRaises(c.Block):
                c.validate_payload(value,self.head)
        for key in self.value['limits']:
            value=copy.deepcopy(self.value);value['limits'][key]+=1
            with self.assertRaisesRegex(c.Block,'SCOPE_OR_PIN_CHANGED'):c.validate_payload(value,self.head)
        for key in self.value['caps']:
            value=copy.deepcopy(self.value);value['caps'][key]+=1
            with self.assertRaisesRegex(c.Block,'SCOPE_OR_PIN_CHANGED'):c.validate_payload(value,self.head)
        for order in (list(reversed(c.ORDER)),list(c.ORDER)+['DROP'],['SELECT_USER_ROWS']):
            value=copy.deepcopy(self.value);value['query_order']=order
            with self.assertRaises(c.Block):c.validate_payload(value,self.head)

    def test_booleans_and_local_timezone_strings_are_not_integer_time_or_caps(self):
        for key in ('version','max_successes','not_before','expires_at'):
            v=copy.deepcopy(self.value);v[key]=True
            with self.assertRaises(c.Block):c.validate_payload(v,self.head)
        for value in ('2026-10-20T12:00:00+09:00','2026-10-20T03:00:00Z',1.0,None):
            v=copy.deepcopy(self.value);v['not_before']=value
            with self.assertRaises(c.Block):c.validate_payload(v,self.head)

    def test_window_start_inclusive_end_exclusive_no_grace(self):
        self.review()
        self.clock.advance(119);self.review()
        self.clock.advance(1)
        with self.assertRaisesRegex(c.Block,'EXPIRED'):self.review()
        self.clock=c.FakeClock(1699999999)
        with self.assertRaisesRegex(c.Block,'NOT_YET_VALID'):self.review()

    def test_window_bounds_nonce_and_weak_algorithm(self):
        for key,value in [('expires_at',1700000121),('expires_at',1700000000),
                          ('nonce','short'),('nonce','G'*32),('algorithm','SHA1')]:
            v=copy.deepcopy(self.value);v[key]=value
            with self.assertRaises(c.Block):c.validate_payload(v,self.head)

    def test_revoked_and_rotated_keys(self):
        self.authority.revoked.add('owner')
        with self.assertRaisesRegex(c.Block,'SIGNER_REVOKED'):self.review()
        self.authority.revoked.clear();self.authority.rotate()
        with self.assertRaisesRegex(c.Block,'STALE_SIGNER'):self.review()

    def test_verification_exception_is_fixed_code(self):
        with patch.object(self.authority,'verify',side_effect=RuntimeError('SYNTHETIC_PRIVATE')):
            with self.assertRaisesRegex(c.Block,'VERIFICATION_FAILED') as result:self.review()
            self.assertNotIn('SYNTHETIC_PRIVATE',str(result.exception))

    def test_no_external_authority_or_clock_object_is_trusted(self):
        with self.assertRaisesRegex(c.Block,'MISSING_REAL_AUTHORITY'):
            c.review(self.value,self.proofs,object(),self.clock,self.head,self.tls)

    def test_destination_each_field_must_be_exact_synthetic(self):
        for key in self.value['target']:
            value=copy.deepcopy(self.value);value['target'][key]='SYNTHETIC_CHANGED'
            with patch('builtins.open') as opened,patch('socket.socket') as socket,patch('subprocess.Popen') as process:
                with self.assertRaises(c.Block):c.validate_payload(value,self.head)
                opened.assert_not_called();socket.assert_not_called();process.assert_not_called()

    def test_tls_downgrade_wrong_chain_san_ca_or_session_block(self):
        for key,values in {'mode':['REQUIRED','VERIFY_CA','DISABLED'],
                'chain_valid':[False,1],'san':['other.invalid'],'ca_sha256':['0'*64],
                'tls_version':['1.0'],'session':['other-session'],
                'q0_session':['other-session'],'q1_session':['other-session']}.items():
            for value in values:
                changed=copy.deepcopy(self.tls);changed[key]=value
                with self.assertRaisesRegex(c.Block,'TLS_UNVERIFIED'):
                    c.validate_tls(changed,self.value['target'])

    def test_session_identity_and_expanded_privileges_block(self):
        for key in self.value['target']:
            changed=copy.deepcopy(self.tls);changed['q0'][key]='SYNTHETIC_CHANGED'
            with self.assertRaisesRegex(c.Block,'SESSION_IDENTITY_CHANGED'):
                c.validate_tls(changed,self.value['target'])
        for extra in ('SELECT_ALL','ROLE','GRANT_OPTION','TRIGGER','PROCESS','DDL'):
            changed=copy.deepcopy(self.tls);changed['grants'].append(extra)
            with self.assertRaisesRegex(c.Block,'METADATA_PRIVILEGE_BLOCK'):
                c.validate_tls(changed,self.value['target'])
        self.tls['grants'].remove('USAGE');self.review()

    def test_ledger_before_after_drift_and_partial_not_full_snapshot(self):
        self.tls['ledger_after']='2'*64
        with self.assertRaisesRegex(c.Block,'LEDGER_CHANGED'):self.review()
        self.tls['ledger_after']='1'*64
        for n in (0,93,95,113,115,True):
            self.tls['ledger_count']=n
            with self.assertRaisesRegex(c.Block,'SNAPSHOT_UNVERIFIED'):self.review()
        self.tls['ledger_count']=114;self.review()

    def test_semantic_names_equal_never_waive_strict_block_or_trigger_visibility(self):
        self.tls['strict_result']='BLOCK';self.tls['semantic_names_only']=True
        with self.assertRaisesRegex(c.Block,'STRICT_SCHEMA_BLOCK'):self.review()
        self.tls['strict_result']='REVIEW_REQUIRED'
        with self.assertRaisesRegex(c.Block,'STRICT_SCHEMA_BLOCK'):self.review()
        self.tls['semantic_names_only']=False;self.tls['trigger_visibility']='COMPLETE'
        with self.assertRaisesRegex(c.Block,'SNAPSHOT_UNVERIFIED'):self.review()

    def test_nonce_replay_revocation_and_memory_reset_limit(self):
        record=self.review();self.consume(record)
        with self.assertRaisesRegex(c.Block,'REPLAY_REJECTED'):self.consume(record)
        # Explicit limitation: a new memory store forgets consumption. Never real exactly-once.
        other=c.SyntheticOneShot()
        out=other.consume(record,self.value,self.proofs,self.authority,self.clock,self.head,self.tls)
        self.assertIn('ONE_SHOT_REAL_CONSUMPTION_UNVERIFIED',out['real_gaps'])
        self.store=c.SyntheticOneShot();self.store.revoked.add(self.value['nonce'])
        with self.assertRaisesRegex(c.Block,'APPROVAL_REVOKED'):self.consume(record)

    def test_simultaneous_consumers_have_one_synthetic_success(self):
        record=self.review();barrier=threading.Barrier(2)
        def attempt():
            barrier.wait(timeout=2)
            try:return self.consume(record)['state']
            except c.Block as error:return error.code
        with ThreadPoolExecutor(max_workers=2) as pool:
            values=list(pool.map(lambda _v:attempt(),range(2)))
        self.assertCountEqual(['SYNTHETIC_VALIDATED','REPLAY_REJECTED'],values)

    def test_clock_rollback_drift_and_jit_expiry(self):
        self.review();self.clock.utc-=1
        with self.assertRaisesRegex(c.Block,'CLOCK_ROLLBACK'):self.review()
        self.clock=c.FakeClock();record=self.review();self.clock.monotonic+=1
        with self.assertRaisesRegex(c.Block,'CLOCK_DRIFT'):self.consume(record)
        self.clock=c.FakeClock();record=self.review();self.clock.advance(120)
        with self.assertRaisesRegex(c.Block,'EXPIRED'):self.consume(record)

    def test_jit_binding_signer_writer_ledger_or_payload_change_stops(self):
        record=self.review();self.value['nonce']='b'*32
        self.proofs=self.authority.endorsements(c.canonical(self.value))
        with self.assertRaisesRegex(c.Block,'TARGET_OR_BINDING_CHANGED'):self.consume(record)
        self.value['nonce']='a'*32;self.proofs=self.authority.endorsements(c.canonical(self.value))
        self.authority.rotate()
        with self.assertRaisesRegex(c.Block,'STALE_SIGNER'):self.consume(record)
        self.proofs=self.authority.endorsements(c.canonical(self.value))
        with self.assertRaisesRegex(c.Block,'TARGET_OR_BINDING_CHANGED'):self.consume(record)

    def test_jit_changed_observation_or_revocation_and_mono_rollback(self):
        record=self.review()
        self.tls['ledger_count']=114
        with self.assertRaisesRegex(c.Block,'TARGET_OR_BINDING_CHANGED'):self.consume(record)
        self.tls['ledger_count']=94;self.tls['writer_epoch']='synthetic-writer-2'
        with self.assertRaisesRegex(c.Block,'SNAPSHOT_UNVERIFIED'):self.consume(record)
        self.tls['writer_epoch']='synthetic-writer-1';self.authority.revoked.add('operator')
        with self.assertRaisesRegex(c.Block,'SIGNER_REVOKED'):self.consume(record)
        self.authority.revoked.clear();self.clock.monotonic=-1
        with self.assertRaisesRegex(c.Block,'CLOCK_UNVERIFIED'):self.consume(record)
        self.clock=c.FakeClock(monotonic=2);self.clock.observe();self.clock.monotonic=1
        with self.assertRaisesRegex(c.Block,'CLOCK_ROLLBACK'):self.clock.observe()

    def test_descriptor_has_no_connector_command_or_live_state(self):
        out=c.plan_descriptor();self.assertEqual('NOT_EXECUTABLE',out['kind'])
        self.assertEqual('UNREVIEWED',out['state']);self.assertEqual(14,out['unique_sql_count'])
        self.assertEqual(17,out['execution_count'])
        for forbidden in ('password','host','argv','READY_TO_CONNECT','APPROVED'):
            self.assertNotIn(forbidden,json.dumps(out))
        self.assertEqual('EXPIRED',c.state_for('EXPIRED'))
        self.assertEqual('REPLAY_REJECTED',c.state_for('REPLAY_REJECTED'))
        self.assertEqual('TLS_UNVERIFIED',c.state_for('TLS_UNVERIFIED'))
        self.assertEqual('TARGET_CHANGED',c.state_for('TARGET_CHANGED'))


class ReceiptAndCliTests(unittest.TestCase):
    def valid_receipts(self):
        common=['migration-regression / upgrade (8.0)','migration-regression / upgrade (8.4)',
            'migration-regression / startup-policy','fixed-diagnostic-regression / Schema diagnosis (MySQL 8.0)',
            'fixed-diagnostic-regression / Schema diagnosis (MySQL 8.4)']
        out={}
        for file,head,name,run,jobs in [
            ('tooling-ci.json',c.TOOLING,'E858 Source-bound Audit',39211597992,
             ['E858 offline safety','E858 reference (MySQL 8.0)','E858 reference (MySQL 8.4)']+common),
            ('transport-ci.json',c.TRANSPORT,'E858 Transport Offline Preflight',39271348988,
             ['Transport offline safety','Transport disposable (MySQL 8.0)','Transport disposable (MySQL 8.4)']+common),
            ('gate-ci.json','a'*40,'E858 Approval Offline Gate',1,
             ['Approval offline (Python 3.9)','Approval offline (Python 3.12)','Pinned dependency regressions'])]:
            out[file]=dict(headSha=head,workflowName=name,status='completed',conclusion='success',
                url='https://github.com/1kz-ma1/Canovia-web/actions/runs/'+str(run),
                jobs=[dict(name=j,status='completed',conclusion='success') for j in jobs])
        return out

    def test_independent_receipts_match_exact_jobs_heads_and_runs(self):
        values=self.valid_receipts();self.assertEqual([39211597992,39271348988,1],guard.receipts(values,'a'*40))
        for file in values:
            for field in ('headSha','workflowName','status','conclusion','url'):
                data=copy.deepcopy(values);data[file][field]='SYNTHETIC_CHANGED'
                with self.assertRaises(c.Block):guard.receipts(data,'a'*40)
            data=copy.deepcopy(values);data.pop(file)
            with self.assertRaises(c.Block):guard.receipts(data,'a'*40)

    def test_skipped_neutral_cancelled_missing_duplicate_jobs_block(self):
        values=self.valid_receipts()
        for file in values:
            for status in ('skipped','neutral','cancelled','failure',''):
                data=copy.deepcopy(values);data[file]['jobs'][0]['conclusion']=status
                with self.assertRaises(c.Block):guard.receipts(data,'a'*40)
            for jobs in ([],values[file]['jobs'][:-1],values[file]['jobs']+values[file]['jobs'][:1]):
                data=copy.deepcopy(values);data[file]['jobs']=jobs
                with self.assertRaises(c.Block):guard.receipts(data,'a'*40)

    def test_real_args_collect_or_target_never_access_files_socket_process(self):
        packet=load_packet()
        for args in (['--collect'],['--host','synthetic.invalid'],['--credential','synthetic'],
                     ['--approval-json','synthetic'],['--ca-file','synthetic'],['--enable-real']):
            with patch.object(sys,'argv',['offline',*args]),patch('builtins.open') as opened,\
                    patch('socket.socket') as socket,patch('subprocess.Popen') as process,\
                    patch('sys.stdout',new_callable=io.StringIO) as out:
                self.assertEqual(2,packet.main());value=json.loads(out.getvalue())
                self.assertIs(False,value['real_aiven_connected'])
                opened.assert_not_called();socket.assert_not_called();process.assert_not_called()

    def test_errors_and_interrupts_redacted(self):
        packet=load_packet()
        for error in (RuntimeError('SYNTHETIC_PRIVATE'),KeyboardInterrupt()):
            with patch.object(sys,'argv',['offline','--gate-sha','a'*40,'--receipts-dir','unused']),\
                    patch.object(packet,'packet',side_effect=error),patch('sys.stdout',new_callable=io.StringIO) as out:
                self.assertEqual(2,packet.main());self.assertNotIn('SYNTHETIC_PRIVATE',out.getvalue())


if __name__=='__main__':unittest.main()
