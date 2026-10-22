"""Private-record/caller/credential delta. Fresh synthetic files and PTYs only."""
import contextlib
import copy
import io
import json
import os
from pathlib import Path
import ssl
import socket
import sys
import tempfile
import time
import unittest
from unittest.mock import patch

sys.path.insert(0,str(Path(__file__).resolve().parents[2]/'scripts/ci'))
from e858_phase1 import practical as p, b1_package, private_distribution as d, private_binding as binding
from e858_phase1 import r1_driver_fixtures as tty
import test_e858_private_candidate as binding_fixture
import e858_b_offline as old

CANARY='SYNTHETIC_PRIVATE_NEVER_RENDER'


class Practical(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        binding_fixture.PrivateCandidate.setUpClass();cls.ca=binding_fixture.PrivateCandidate.ca
        root=Path(os.environ['B1_BUILD_DIR']);cls.archive=(root/'b1-candidate.zip').read_bytes()
        cls.head=old.git(old.ROOT,'rev-parse','HEAD').decode().strip();cls.pins=old.integrity(old.ROOT,cls.head)
        cls.program=p.Program(cls.archive,d._sha(cls.archive),cls.pins)

    def setUp(self):
        self.temp=tempfile.TemporaryDirectory(prefix='.practical-',dir=os.environ.get('B1_SYNTHETIC_PARENT',str(Path.cwd())))
        self.addCleanup(self.temp.cleanup)
        self.base=Path(self.temp.name).resolve();self.private=self.base/'private';self.independent=self.base/'owner'
        for path in (self.private,self.independent):path.mkdir(mode=0o700)
        now=int(time.time())
        self.record=dict(kind='E858_PHASE1_PRIVATE_BINDING_V1',provenance='OWNER_PRIVATE_2026_10_21',source_sha=p.SOURCE,
            release_ref=p.RELEASE,tooling_sha=self.head,sql_sha256=binding.SQL,q0_sha256=binding.Q0_SHA,q1_sha256=binding.Q1_SHA,
            starts_at=now-1,expires_at=now+3600,target=dict(service=CANARY,database='private_fixture',host='dynamic.synthetic.invalid',port=9876,principal='private_inspector'),
            tls=dict(mode='VERIFY_IDENTITY',minimum='TLS1.2',san='dynamic.synthetic.invalid',ca_sha256=d._sha(self.ca)))
        self.review=dict(kind='E858_B1_PRIVATE_REVIEW_V1',state='OWNER_REVIEW_PENDING_NOT_GO',source_sha=p.SOURCE,
            release_ref=p.RELEASE,tooling_sha=self.head,bundle_sha256=self.program.sha,sql_sha256=binding.SQL,
            reference_sha256=self.pins['reference_sha256']['8.0'],wheel_sha256=d.WHEEL_SHA,runtime_sha256=self.program.runtime,
            binding_sha256=d._sha(old.canonical(self.record)),scope='phase1',engine='8.0',attempt_id='a'*32,starts_at=now-1,expires_at=now+3600)
        self.write()

    def write(self):
        for root,name,data in ((self.private,'target.json',old.canonical(self.record)),(self.private,'ca.pem',self.ca),
                               (self.independent,'review.json',old.canonical(self.review))):
            path=root/name;path.write_bytes(data);path.chmod(0o600)
        self.review_hash=d._sha((self.independent/'review.json').read_bytes())
    def load(self,hash=None):return p.PrivateReview(str(self.independent),hash or self.review_hash,self.program)

    def test_separate_private_record_is_not_owner_authentication_or_go(self):
        review=self.load()
        try:
            self.assertEqual(review.check()['state'],'OWNER_REVIEW_PENDING_NOT_GO')
            self.assertNotIn(CANARY,repr(review)+repr(self.program))
        finally:review.close()

    def test_review_hash_is_independent_of_record_self_claims(self):
        with self.assertRaises(binding.Block):self.load('0'*64)
        self.review['approved']=True;self.write()
        with self.assertRaises(binding.Block):self.load()

    def test_review_seals_scope_time_tooling_runtime_wheel_refs(self):
        original=copy.deepcopy(self.review)
        for key,value in [('source_sha','f'*40),('release_ref',CANARY),('tooling_sha','f'*40),('bundle_sha256','0'*64),
                ('runtime_sha256','0'*64),('wheel_sha256','0'*64),('reference_sha256','0'*64),('sql_sha256','0'*64),
                ('scope','full17-approved'),('engine','9.0'),('attempt_id',CANARY),('starts_at',True),('expires_at',0),('state','APPROVED')]:
            with self.subTest(key=key):
                self.review=copy.deepcopy(original);self.review[key]=value;self.write()
                with self.assertRaises(binding.Block):self.load()

    def test_review_rejects_weak_mode_symlink_hardlink_fifo_oversize(self):
        file=self.independent/'review.json';raw=file.read_bytes()
        file.chmod(0o644)
        with self.assertRaises(binding.Block):self.load()
        file.chmod(0o600);os.link(file,self.independent/'other')
        with self.assertRaises(binding.Block):self.load()
        (self.independent/'other').unlink();file.unlink();file.symlink_to(self.private/'target.json')
        with self.assertRaises(binding.Block):self.load()
        file.unlink();os.mkfifo(file,0o600)
        with self.assertRaises(binding.Block):self.load()
        file.unlink();file.write_bytes(b'x'*16385);file.chmod(0o600)
        with self.assertRaises(binding.Block):self.load()

    def test_unknown_acl_and_private_error_redaction(self):
        out,err=io.StringIO(),io.StringIO()
        with contextlib.redirect_stdout(out),contextlib.redirect_stderr(err),patch.object(p,'native_acl',side_effect=PermissionError(CANARY)):
            with self.assertRaises(binding.Block) as error:self.load()
        self.assertNotIn(CANARY,str(error.exception));self.assertEqual(out.getvalue()+err.getvalue(),'')

    def test_parent_acl_or_link_change_is_terminal(self):
        review=self.load();moved=self.base/'moved'
        try:
            os.rename(self.independent,moved);self.independent.mkdir(mode=0o700)
            with self.assertRaises(binding.Block):review.check()
        finally:review.close()

    def test_stale_record_and_runtime_change_reject(self):
        review=self.load()
        try:
            (self.independent/'review.json').write_bytes(b'{}')
            with self.assertRaises(binding.Block):review.check()
        finally:review.close()
        self.write()
        with patch.object(d,'runtime_components',return_value={'kind':'changed'}):
            with self.assertRaises(binding.Block):self.load()

    def test_program_tamper_wrong_tooling_and_frozen_import_origin(self):
        with self.assertRaises(binding.Block):p.Program(self.archive+b'x',self.program.sha,self.pins)
        with self.assertRaises(binding.Block):p.Program(self.archive,self.program.sha,dict(self.pins,tooling_sha='f'*40))
        module=unittest.mock.Mock(__file__=str(self.private/'target.json'))
        with patch.dict(sys.modules,{'e858_audit.core':module}):
            with self.assertRaises(binding.Block):self.program.check()

    def test_program_fixed_14_sql_and_inputs(self):
        self.assertEqual(len(self.program.queries),14)
        self.assertEqual(p.digest({k:d._sha(v) for k,v in self.program.queries.items()}),binding.SQL)
        for engine in ('8.0','8.4'):
            facts,reference,contract=self.program.inputs(engine)
            self.assertEqual(len(facts['baseline']),94);self.assertEqual(len(facts['candidate']),114)
            self.assertEqual(reference['migration_seal'],facts['migration_seal'])
            self.assertIn('absent_tables',contract)

    def target(self):
        fd=os.open(self.private,os.O_RDONLY|os.O_DIRECTORY)
        try:return binding.read_binding(fd,self.review['binding_sha256'],self.head,int(time.time()),inspect_acl=p.native_acl)
        finally:os.close(fd)

    def test_deferred_generic_wire_no_dns_and_secret_alias(self):
        target=self.target();secret=bytearray(b'SYNTHETIC-DIFFERENT-PASSWORD');wire=object.__new__(p.PrivateWire)
        with patch('socket.getaddrinfo',side_effect=AssertionError('NETWORK_FORBIDDEN')):
            try:
                wire.initialize(None,secret,target)
                self.assertIs(wire.password,secret)
                self.assertFalse(wire.ctx.hostname_checks_common_name)
                self.assertTrue(wire.ctx.check_hostname);self.assertEqual(wire.ctx.verify_mode,ssl.CERT_REQUIRED)
                with self.assertRaises(binding.Block):wire.connect()
                self.assertEqual(wire.connect_count,0)
                self.assertNotIn(CANARY,repr(wire))
            finally:wire.close()
        self.assertEqual(wire.password,b'');secret.clear()

    def test_dynamic_dns_can_change_within_owned_fixture_not_record_ip(self):
        from e858_phase1.practical_fixture import resolve_owned
        one=(socket.AF_INET,socket.SOCK_STREAM,6,'',('172.20.0.2',3306))
        two=(socket.AF_INET,socket.SOCK_STREAM,6,'',('172.20.0.3',3306))
        with patch('socket.getaddrinfo',side_effect=[[one],[two]]) as lookup:
            self.assertEqual(resolve_owned('mysql.synthetic.invalid',3306,{'172.20.0.2','172.20.0.3'}),[one])
            self.assertEqual(resolve_owned('mysql.synthetic.invalid',3306,{'172.20.0.2','172.20.0.3'}),[two])
            self.assertEqual(lookup.call_count,2)
        with patch('socket.getaddrinfo',return_value=[two]):
            with self.assertRaises(binding.Block):resolve_owned('mysql.synthetic.invalid',3306,{'172.20.0.2'})
        with patch('socket.getaddrinfo',side_effect=AssertionError('NO_REAL_DNS')):
            with self.assertRaises(binding.Block):resolve_owned('private.example.invalid',3306,{'172.20.0.2'})

    def test_default_adapter_blocks_before_private_read_input_and_network(self):
        entry=unittest.mock.Mock()
        with patch('socket.getaddrinfo',side_effect=AssertionError('NETWORK_FORBIDDEN')):
            result=p.Adapter(entry).run()
        entry.check.assert_not_called();entry.capture.assert_not_called()
        self.assertEqual(result['result'],'BLOCK')
        for key in ('execution_allowed','real_aiven_connected','production_go','live_tooling_adopted','b2_owner_go'):self.assertIs(result[key],False)

    def test_target_replacement_during_read_is_rejected(self):
        original=os.read;done=False;file=self.private/'target.json'
        def read(fd,count):
            nonlocal done
            value=original(fd,count)
            if not done:
                done=True;other=self.private/'replacement';other.write_bytes(file.read_bytes());other.chmod(0o600);os.replace(other,file)
            return value
        fd=os.open(self.private,os.O_RDONLY|os.O_DIRECTORY)
        try:
            with patch.object(binding.os,'read',side_effect=read):
                with self.assertRaises(binding.Block):binding.read_binding(fd,self.review['binding_sha256'],self.head,int(time.time()),inspect_acl=p.native_acl)
        finally:os.close(fd)

    def _pty_bind(self,fault):
        def child(_,parent):
            if os.getppid()!=parent:raise binding.Block()
            entry=p.ReviewedEntry();review=None
            try:
                if fault=='same_root':
                    path=self.private/'review.json';path.write_bytes(old.canonical(self.review));path.chmod(0o600)
                    review=p.PrivateReview(str(self.private),self.review_hash,self.program)
                else:review=self.load()
                entry.begin(str(self.private),human_supervised=True)
                if fault=='same_root':
                    # The owner record has valid schema but its origin is the
                    # target leaf; that cannot establish an independent review.
                    with self.assertRaises(binding.Block):entry.bind_review(review)
                else:
                    entry.bind_review(review)
                    self.assertEqual(entry.target.principal,'private_inspector')
                    if fault=='changed':
                        (self.private/'target.json').write_bytes(b'{}')
                        with self.assertRaises(binding.Block):entry.check()
                entry.close()
                return dict(result='PRACTICAL_TTY_BIND_VERIFIED',traces=[],execution_allowed=False)
            finally:
                entry.close()
                if review is not None:review.close()
        with patch.object(tty,'child_trial',side_effect=child):
            self.assertEqual(tty.trial('NORMAL')['result'],'PRACTICAL_TTY_BIND_VERIFIED')
    def test_reviewed_foreground_entry_binds_without_input(self):self._pty_bind('normal')
    def test_reviewed_entry_rejects_same_root(self):self._pty_bind('same_root')
    def test_reviewed_entry_rechecks_target_without_input(self):self._pty_bind('changed')


if __name__=='__main__':unittest.main()
