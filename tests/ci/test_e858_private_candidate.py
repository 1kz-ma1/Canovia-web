"""New private boundary only. All files, CA, TTY input and identities synthetic."""
import contextlib
import copy
from datetime import datetime, timedelta, timezone
import hashlib
import io
import json
import os
from pathlib import Path
import ssl
import subprocess
import sys
import tempfile
import time
import unittest
from unittest.mock import patch

from cryptography import x509
from cryptography.hazmat.primitives import hashes, serialization
from cryptography.hazmat.primitives.asymmetric import rsa
from cryptography.x509.oid import NameOID

sys.path.insert(0,str(Path(__file__).resolve().parents[2]/'scripts/ci'))
from e858_phase1 import private_binding as b, private_candidate as c, private_distribution as d
from e858_phase1 import r1_driver_fixtures as tty_fixture
import e858_private_archive as cli

HEAD='a'*40
CANARY='SYNTHETIC_PRIVATE_DO_NOT_OUTPUT'
Q0=b'SELECT DATABASE(), CURRENT_USER(), VERSION(), @@server_uuid;\n'
Q1=b'SHOW GRANTS FOR CURRENT_USER();\n'
GRANTS="GRANT REFERENCES ON `synthetic_db`.* TO `inspector`@`10.%`\nGRANT SELECT ON `synthetic_db`.`migrations` TO `inspector`@`10.%`\n"
UUID='12345678-1234-1234-1234-123456789012'


class PrivateCandidate(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        key=rsa.generate_private_key(public_exponent=65537,key_size=2048)
        name=x509.Name([x509.NameAttribute(NameOID.COMMON_NAME,'Synthetic boundary CA')])
        now=datetime.now(timezone.utc)
        cert=(x509.CertificateBuilder().subject_name(name).issuer_name(name).public_key(key.public_key())
              .serial_number(1).not_valid_before(now-timedelta(days=1)).not_valid_after(now+timedelta(days=1))
              .add_extension(x509.BasicConstraints(ca=True,path_length=None),critical=True).sign(key,hashes.SHA256()))
        cls.ca=cert.public_bytes(serialization.Encoding.PEM)

    def setUp(self):
        self.temp=tempfile.TemporaryDirectory(prefix='canovia-synthetic-binding-')
        self.addCleanup(self.temp.cleanup)
        self.root=Path(self.temp.name);self.root.chmod(0o700)
        self.now=int(time.time())
        self.record=dict(kind='E858_PHASE1_PRIVATE_BINDING_V1',provenance='OWNER_PRIVATE_2026_10_21',
            source_sha=b.SOURCE,release_ref=b.RELEASE,tooling_sha=HEAD,sql_sha256=b.SQL,
            q0_sha256=b.Q0_SHA,q1_sha256=b.Q1_SHA,starts_at=self.now-5,expires_at=self.now+3600,
            target=dict(service=CANARY,database='synthetic_db',host='private-target.synthetic.invalid',port=12345,principal='inspector'),
            tls=dict(mode='VERIFY_IDENTITY',minimum='TLS1.2',san='private-target.synthetic.invalid',ca_sha256=b.sha(self.ca)))
        self.write()
        self.fd=os.open(self.root,os.O_RDONLY|os.O_DIRECTORY)
        self.addCleanup(os.close,self.fd)

    def write(self):
        (self.root/'target.json').write_text(json.dumps(self.record));(self.root/'target.json').chmod(0o600)
        (self.root/'ca.pem').write_bytes(self.ca);(self.root/'ca.pem').chmod(0o600)
        self.seal=b.sha((self.root/'target.json').read_bytes())

    def target(self):return b.read_binding(self.fd,self.seal,HEAD,self.now)

    def candidate(self):return c.Candidate(self.target(),Q0,Q1)

    def handoff(self):
        value=self.candidate();value.used=True;value.captured._used=True
        value.captured._password.extend(c.SYNTHETIC_PASSWORD)
        return value

    def identity(self,control=True,uuid=UUID,account='inspector@10.%',cid=91):
        fields=['synthetic_db',account,'8.4.7',uuid]
        if control:fields.append(str(cid))
        return '\t'.join(fields)+'\n'

    def test_binding_parses_only_owned_private_files_and_never_resolves_dns(self):
        with patch('socket.getaddrinfo',side_effect=AssertionError('network')):
            t=self.target();ctx=t.tls_context()
        self.assertEqual(t.host,'private-target.synthetic.invalid');self.assertEqual(t.port,12345)
        self.assertTrue(ctx.check_hostname);self.assertEqual(ctx.verify_mode,ssl.CERT_REQUIRED)
        self.assertGreaterEqual(ctx.minimum_version,ssl.TLSVersion.TLSv1_2)
        self.assertNotIn(CANARY,repr(t));self.assertFalse(os.get_inheritable(self.fd))
        self.assertTrue(os.fstat(self.fd))  # caller FD remains owned by caller

    def test_target_hash_tooling_sql_tls_unknown_fields_and_expiry_reject(self):
        changes=[('source_sha','b'*40),('release_ref','other'),('tooling_sha','b'*40),('sql_sha256','b'*64),
                 ('q0_sha256','b'*64),('q1_sha256','b'*64),('provenance','UNREVIEWED'),
                 ('expires_at',self.now),('starts_at',self.now+1),('starts_at',True),('unknown',CANARY)]
        original=copy.deepcopy(self.record)
        for key,value in changes:
            with self.subTest(key=key):
                self.record=copy.deepcopy(original);self.record[key]=value;self.write()
                with self.assertRaises(b.Block):self.target()
        self.record=original;self.write()
        for seal in ('0'*64,'not-a-hash'):
            with self.assertRaises(b.Block):b.read_binding(self.fd,seal,HEAD,self.now)

    def test_binding_rejects_override_weak_tls_ip_and_multiple_targets(self):
        original=copy.deepcopy(self.record)
        for section,key,value in [('target','host','127.0.0.1'),('target','host','*.synthetic.invalid'),
                 ('target','host','UPPER.synthetic.invalid'),('target','principal','root@%'),
                 ('target','port',True),('target','port',0),('target','database','x;SELECT'),
                 ('target','extra',CANARY),('tls','mode','VERIFY_CA'),('tls','minimum','TLS1.1'),
                 ('tls','san','other.synthetic.invalid')]:
            with self.subTest(section=section,key=key,value=value):
                self.record=copy.deepcopy(original);self.record[section][key]=value;self.write()
                with self.assertRaises(b.Block):self.target()
        self.record=copy.deepcopy(original);self.record['target']=[self.record['target']];self.write()
        with self.assertRaises(b.Block):self.target()

    def test_binding_ignores_environment_and_redacts_all_errors(self):
        with patch.dict(os.environ,{'MYSQL_HOST':CANARY,'MYSQL_PWD':CANARY,'SSL_CERT_FILE':CANARY,'APPROVED':'true'}):
            self.assertEqual(self.target().host,self.record['target']['host'])
        out,err=io.StringIO(),io.StringIO()
        with contextlib.redirect_stdout(out),contextlib.redirect_stderr(err),patch.object(b,'_read',side_effect=OSError(CANARY)):
            try:self.target()
            except b.Block as error:self.assertNotIn(CANARY,str(error)+repr(error))
            else:self.fail('must refuse')
        self.assertEqual(out.getvalue()+err.getvalue(),'')

    def test_permissions_symlink_hardlink_fifo_and_oversize_reject(self):
        for name in ('target.json','ca.pem'):
            file=self.root/name;original=file.read_bytes()
            for mode in (0o644,0o660,0o400):
                file.chmod(mode)
                with self.assertRaises(b.Block):self.target()
            file.chmod(0o600);file.rename(self.root/'hidden')
            file.symlink_to(self.root/'hidden')
            with self.assertRaises(b.Block):self.target()
            file.unlink();os.link(self.root/'hidden',file)
            with self.assertRaises(b.Block):self.target()
            file.unlink();os.mkfifo(file,0o600)
            with self.assertRaises(b.Block):self.target()
            file.unlink();file.write_bytes(b'x'*65537);file.chmod(0o600)
            with self.assertRaises(b.Block):self.target()
            file.write_bytes(original);(self.root/'hidden').unlink()
        self.root.chmod(0o755)
        with self.assertRaises(b.Block):self.target()

    def test_ca_tamper_invalid_pem_and_record_duplicate_reject(self):
        (self.root/'ca.pem').write_bytes(self.ca+b'changed')
        with self.assertRaises(b.Block):self.target()
        self.ca=b'not a certificate';self.record['tls']['ca_sha256']=b.sha(self.ca);self.write()
        with self.assertRaises(b.Block):self.target()
        raw=b'{"kind":1,"kind":2}';(self.root/'target.json').write_bytes(raw);self.seal=b.sha(raw)
        with self.assertRaises(b.Block):self.target()

    def test_reader_detects_mid_read_change_and_wrong_fd_ownership(self):
        old_read=os.read;done=False
        def read(fd,count):
            nonlocal done
            value=old_read(fd,count)
            if not done:
                done=True
                with (self.root/'target.json').open('ab') as f:f.write(b' ')
            return value
        with patch.object(b.os,'read',side_effect=read):
            with self.assertRaises(b.Block):self.target()
        for fd in (-1,0,1,2,True):
            with self.assertRaises(b.Block):b.read_binding(fd,self.seal,HEAD,self.now)

    def test_real_constructor_handoff_is_alias_no_network_and_cleared(self):
        candidate=self.handoff();secret=candidate.captured._password
        with patch('socket.create_connection',side_effect=AssertionError('network')),patch('socket.getaddrinfo',side_effect=AssertionError('DNS')):
            wires=candidate.prepare_pair()
            self.assertEqual(len(wires),2)
            for wire in wires:
                self.assertIs(wire.password,secret);self.assertEqual(wire.user,'inspector')
                self.assertEqual(wire.host,self.record['target']['host']);self.assertEqual(wire.port,12345)
                self.assertEqual(wire.connect_count,0)
            for wire in wires:
                for action in (wire.connect,lambda:wire.query(Q0),lambda:wire._request_authentication(),wire.graceful_close):
                    with self.assertRaises(b.Block):action()
                    self.assertTrue(candidate.closed)
            candidate.close()
        self.assertFalse(secret);self.assertIsNone(candidate.target)
        self.assertTrue(all(w.password==b'' for w in wires))
        self.assertEqual(candidate.report()['remote_termination'],'REMOTE_TERMINATION_UNVERIFIED')
        self.assertFalse(candidate.report()['execution_allowed'])

    def test_capture_requires_action_and_fresh_child_before_terminal_open(self):
        for action,parent in ((None,os.getppid()),(c.Action.SYNTHETIC_OWNER_GESTURE,-1)):
            candidate=self.candidate()
            with patch.object(c.os,'open') as opened:
                with self.assertRaises(b.Block):candidate.capture_synthetic_ctty(action,parent)
                opened.assert_not_called()
            with self.assertRaises(b.Block):candidate.capture_synthetic_ctty(c.Action.SYNTHETIC_OWNER_GESTURE,os.getppid())
            self.assertFalse(candidate.captured._password)

    def test_fresh_synthetic_ctty_to_new_private_pair_combined(self):
        target=self.target()
        def child(fault,parent):
            with c.Candidate(target,Q0,Q1) as candidate:
                candidate.capture_synthetic_ctty(c.Action.SYNTHETIC_OWNER_GESTURE,parent)
                refs=candidate.prepare_pair();secret=candidate.captured._password
                self.assertTrue(all(w.password is secret and w.connect_count==0 for w in refs))
            self.assertFalse(secret);self.assertTrue(all(w.password==b'' for w in refs))
            return dict(result='PRIVATE_CANDIDATE_PTY_VERIFIED',traces=[],execution_allowed=False)
        with patch.object(tty_fixture,'child_trial',child):
            result=tty_fixture.trial('TTY_ONLY')
        self.assertEqual(result['result'],'PRIVATE_CANDIDATE_PTY_VERIFIED')
        self.assertFalse(result['execution_allowed'])

    def test_partial_constructor_interrupt_and_reentry_discard_secret(self):
        for fault in (RuntimeError(CANARY),KeyboardInterrupt(CANARY)):
            candidate=self.handoff();secret=candidate.captured._password;created=[]
            original=c.DisabledWire.__init__
            def initialize(wire,*args):
                created.append(wire)
                if len(created)==2:raise fault
                original(wire,*args)
            with patch.object(c.DisabledWire,'__init__',initialize):
                with self.assertRaises(b.Block) as error:candidate.prepare_pair()
            self.assertNotIn(CANARY,str(error.exception));self.assertFalse(secret)
            self.assertTrue(all(w.password==b'' for w in created))
            with self.assertRaises(b.Block):candidate.prepare_pair()
        candidate=self.handoff();secret=candidate.captured._password;wires=candidate.prepare_pair()
        with self.assertRaises(b.Block):candidate.prepare_pair()
        self.assertFalse(secret);self.assertTrue(all(w.password==b'' for w in wires))

    def test_deadline_dependency_seals_and_context_interrupt(self):
        for q0,q1 in ((Q0+b' ',Q1),(Q0,Q1+b' ')):
            with self.assertRaises(b.Block):c.Candidate(self.target(),q0,q1)
        with patch.object(c,'version',return_value='2.0'):
            with self.assertRaises(b.Block):self.candidate()
        candidate=self.handoff();secret=candidate.captured._password;candidate.budget.until=0
        with self.assertRaises(b.Block):candidate.prepare_pair()
        self.assertFalse(secret)
        candidate=self.handoff();secret=candidate.captured._password
        with self.assertRaises(KeyboardInterrupt):
            with candidate:candidate.prepare_pair();raise KeyboardInterrupt()
        self.assertFalse(secret)

    def test_unknown_control_identity_then_data_and_current_grants_latch(self):
        with self.candidate() as candidate:
            candidate.observe_identity('control',self.identity(),91,GRANTS)
            candidate.observe_identity('data',self.identity(False),92,GRANTS)
            candidate.observe_identity('control',self.identity(),91,GRANTS)
            candidate.observe_identity('data',self.identity(False),92,GRANTS)
            self.assertEqual(candidate.identity[1],'inspector@10.%')  # no guessed @%
            self.assertNotIn(UUID,json.dumps(candidate.report()))

    def test_identity_role_cid_failover_account_and_permissions_stop(self):
        for role,raw,cid,grants in (
            ('data',self.identity(False),92,GRANTS),('control',self.identity(cid=90),91,GRANTS),
            ('control',self.identity(account='root@10.%'),91,GRANTS),
            ('control',self.identity(),91,'GRANT ALL PRIVILEGES ON *.* TO `inspector`@`10.%`\n'),
            ('control',self.identity(),91,''),('control',self.identity(),91,GRANTS.replace('inspector','other')),('control',self.identity(),True,GRANTS)):
            candidate=self.candidate()
            with self.assertRaises(b.Block):candidate.observe_identity(role,raw,cid,grants)
            self.assertTrue(candidate.closed)
        for role,raw,cid in [('control',self.identity(uuid='f'*36),91),('control',self.identity(cid=90),90),
                             ('data',self.identity(False,account='inspector@%'),92),('data',self.identity(False),91)]:
            candidate=self.candidate();candidate.observe_identity('control',self.identity(),91,GRANTS)
            with self.assertRaises(b.Block):candidate.observe_identity(role,raw,cid,GRANTS)
            self.assertEqual(candidate.report()['remote_termination'],'REMOTE_TERMINATION_UNVERIFIED')

    def test_sql_gate_bytes_and_caps_are_inherited_unchanged(self):
        with self.handoff() as candidate:
            control,data=candidate.prepare_pair()
            self.assertEqual(data.gate.q0,Q0);self.assertEqual(control.gate.q1,Q1)
            self.assertIs(data.gate.budget,control.gate.budget)
            self.assertEqual(data.gate.deadline,candidate.budget.until)
            self.assertEqual(data.gate.claim(3,b'SET NAMES ascii'),b'SET NAMES ascii')
            with self.assertRaises(Exception):data.gate.claim(3,b'SELECT 1')
        self.assertEqual(c.Gate.__module__,'e858_phase1.protocol')

    def test_cleanup_uncertain_is_block_even_with_refs_removed(self):
        candidate=self.handoff();secret=candidate.captured._password
        with self.assertRaises(b.Block):
            with candidate:
                wires=candidate.prepare_pair()
                wires[0].close=lambda: (_ for _ in ()).throw(OSError(CANARY))
        self.assertFalse(secret);self.assertTrue(all(w.password==b'' for w in wires))
        self.assertEqual(candidate.report()['remote_termination'],'REMOTE_TERMINATION_UNVERIFIED')

    def test_data_cid_replacement_expiry_and_clock_window_stop(self):
        candidate=self.candidate();candidate.observe_identity('control',self.identity(),91,GRANTS)
        candidate.observe_identity('data',self.identity(False),92,GRANTS)
        with self.assertRaises(b.Block):candidate.observe_identity('data',self.identity(False),93,GRANTS)
        candidate=self.candidate();candidate.budget.until=0
        with self.assertRaises(b.Block):candidate.observe_identity('control',self.identity(),91,GRANTS)
        self.assertTrue(candidate.closed)


class Distribution(unittest.TestCase):
    def inputs(self):
        self.pins={'source_sha':b.SOURCE,'release_ref':b.RELEASE,'tooling_sha':HEAD,'collector_sha':'b'*40}
        self.wheel=b'public synthetic wheel bytes'
        return {p:('public source '+p).encode() for p in d.FILES}|{'queries/identity.sql':Q0,'queries/grants.sql':Q1}

    def built(self):
        files=self.inputs()
        def git(root,op,spec):
            self.assertEqual(op,'show');path=spec.split(':',1)[1]
            if '/e858_audit/' in path:path=path.split('/e858_audit/',1)[1]
            return files[path]
        with patch.object(d,'WHEEL_SHA',d._sha(self.wheel)),patch.object(d,'integrity',return_value=self.pins),patch.object(d,'git',side_effect=git):
            raw,pins=d.build(Path('/unused'),HEAD,self.wheel)
            self.assertEqual(raw,d.build(Path('/unused'),HEAD,self.wheel)[0])
            report=d.verify(raw,d._sha(raw),pins)
        return raw,pins,report

    def test_deterministic_complete_archive_not_source_index_or_adoption(self):
        raw,pins,report=self.built()
        self.assertEqual(report['file_count'],len(d.FILES)+4)
        self.assertFalse(report['execution_allowed']);self.assertFalse(report['runtime_complete'])
        self.assertEqual(report['tooling_sha'],HEAD)

    def test_wheel_tamper_or_git_integrity_failure_refuses_build(self):
        self.inputs()
        with patch.object(d,'integrity') as gate:
            with self.assertRaises(b.Block):d.build(Path('/unused'),HEAD,b'tampered')
            gate.assert_not_called()
        with patch.object(d,'WHEEL_SHA',d._sha(self.wheel)),patch.object(d,'integrity',side_effect=RuntimeError(CANARY)):
            with self.assertRaises(b.Block):d.build(Path('/unused'),HEAD,self.wheel)

    def test_archive_source_query_pins_member_and_mode_tamper_refuse(self):
        raw,pins,_=self.built()
        import zipfile
        with zipfile.ZipFile(io.BytesIO(raw)) as z:files={n:z.read(n) for n in z.namelist()}
        for name in ('queries/identity.sql',d.FILES[0],'manifest.json','../escape'):
            values=dict(files);values[name]=b'tampered'
            altered=d.pack(values)
            with patch.object(d,'WHEEL_SHA',d._sha(self.wheel)):
                with self.assertRaises(b.Block):d.verify(altered,d._sha(altered),pins)
        with patch.object(d,'WHEEL_SHA',d._sha(self.wheel)):
            with self.assertRaises(b.Block):d.verify(raw,'0'*64,pins)
            with self.assertRaises(b.Block):d.verify(raw,d._sha(raw),dict(pins,tooling_sha='f'*40))
            with self.assertRaises(b.Block):d.verify(b'x'*(d.CAP+1),'0'*64,pins)

    def test_runtime_observes_current_machine_and_mismatch_not_faked_mac_lock(self):
        actual=d.runtime_components()
        self.assertEqual(actual['linkage_closure'],'UNVERIFIED')
        self.assertFalse(d.verify_runtime(actual)['runtime_complete'])
        for key in actual:
            other=dict(actual);other[key]='mismatch'
            with self.subTest(key=key),self.assertRaises(b.Block):d.verify_runtime(other)
        with self.assertRaises(b.Block):d.verify_runtime(None)
        self.assertNotIn(str(Path(sys.executable).resolve()),json.dumps(actual))

    def test_archive_cli_rejects_target_password_mode_without_leaking(self):
        for args in ([],['--endpoint',CANARY],['--password',CANARY],['--connect'],['--help']):
            out,err=io.StringIO(),io.StringIO()
            with contextlib.redirect_stdout(out),contextlib.redirect_stderr(err),patch.object(cli,'build') as build:
                self.assertEqual(cli.main(args),2);build.assert_not_called()
            self.assertNotIn(CANARY,out.getvalue()+err.getvalue());self.assertEqual(err.getvalue(),'')

    def test_links_duplicates_and_nonstored_archive_members_reject(self):
        import zipfile
        raw,pins,_=self.built()
        with zipfile.ZipFile(io.BytesIO(raw)) as z:files={n:z.read(n) for n in z.namelist()}
        for mode in ('link','compressed','duplicate'):
            out=io.BytesIO()
            with zipfile.ZipFile(out,'w') as z:
                for i,(name,data) in enumerate(sorted(files.items())):
                    info=zipfile.ZipInfo(name,(1980,1,1,0,0,0));info.create_system=3
                    info.external_attr=(0o100644)<<16
                    if i==0 and mode=='link':info.external_attr=0o120777<<16
                    if i==0 and mode=='compressed':info.compress_type=zipfile.ZIP_DEFLATED
                    z.writestr(info,data)
                if mode=='duplicate':
                    import warnings
                    with warnings.catch_warnings():
                        warnings.simplefilter('ignore',UserWarning);z.writestr(info,data)
            altered=out.getvalue()
            with patch.object(d,'WHEEL_SHA',d._sha(self.wheel)):
                with self.assertRaises(b.Block):d.verify(altered,d._sha(altered),pins)


if __name__=='__main__':unittest.main()
