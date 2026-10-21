"""Only new bridge boundaries; existing31 TLS/B20 negatives are reused."""
import contextlib
import io
import json
from pathlib import Path
import subprocess
import sys
import unittest
from unittest.mock import patch

ROOT=Path(__file__).resolve().parents[2]
sys.path.insert(0,str(ROOT/'scripts/ci'))
import e858_full94_tls as full
import e858_tls_host as host

CANARY='SYNTHETIC_PRIVATE_INPUT_DO_NOT_ECHO'


class FullBridgeBoundaryTests(unittest.TestCase):
    def test_unknown_runtime_profile_never_builds_or_connects(self):
        with patch.object(host,'certificate_material') as cert, patch.object(host,'run') as run:
            with self.assertRaises(host.Block):host.rehearse('8.0','a'*40,'real_aiven')
            cert.assert_not_called();run.assert_not_called()

    def test_new_entrypoint_rejects_live_credentials_export_and_free_sql(self):
        for args in (['--host',CANARY],['--password',CANARY],['--login-path',CANARY],
                     ['--ca',CANARY],['--collect'],['--execute'],['--sql',CANARY],['--dump'],
                     ['--series','8.0','--tooling-sha','a'*40,'--live']):
            proc=subprocess.run([sys.executable,'-I','-B',str(ROOT/'scripts/ci/e858_full94_tls.py'),*args],capture_output=True)
            self.assertEqual(2,proc.returncode)
            self.assertNotIn(CANARY.encode(),proc.stdout+proc.stderr)
            result=json.loads(proc.stdout)
            self.assertEqual('BLOCK',result['result']);self.assertFalse(result['real_aiven_connected'])

    def test_source_mismatch_cannot_enter_docker(self):
        with patch.object(host,'assets',side_effect=ValueError(CANARY)),patch.object(host,'run') as run:
            with self.assertRaises(ValueError):host.rehearse('8.0','a'*40,'full94')
            run.assert_not_called()



# The pure comparator is loaded from frozen #795 blobs, not reimplemented here.
import importlib
import tempfile
import time
import copy
import hashlib
from integrated_release20_audit import source


class FrozenBridgeInputTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.temp=tempfile.TemporaryDirectory();cls.root=Path(cls.temp.name)
        legacy=cls.root/'legacy';(legacy/'e858_audit').mkdir(parents=True)
        for name in ('integrated_release20_audit.py','integrated_release20_diagnose.py',
                     'integrated_release20_semantics.py','p0_mysql_full_ledger_offline_check.py'):
            (legacy/name).write_bytes(host.git(ROOT,'show',host.COLLECTOR+':scripts/ci/'+name))
        (legacy/'e858_audit/core.py').write_bytes(host.git(ROOT,'show',host.COLLECTOR+':scripts/ci/e858_audit/core.py'))
        sys.path.insert(0,str(legacy));sys.path.insert(0,str(ROOT/'scripts/ci/e858_tls'))
        cls.bridge=importlib.import_module('bridge')

    @classmethod
    def tearDownClass(cls):cls.temp.cleanup()

    def setUp(self):
        self.directory=Path(tempfile.mkdtemp(dir=self.root))
        raw=host.git(ROOT,'show',host.COLLECTOR+':scripts/ci/e858_audit_reference/8.0.json')
        reference=json.loads(raw)
        self.pins=dict(source_sha=host.SOURCE,release_ref='release/2026-10-19-e858b9c8',tooling_sha='a'*40,sql_sha256=reference['sql_bundle_sha256'],reference_sha256={'8.0':hashlib.sha256(raw).hexdigest()})
        self.packet=full.template(self.pins,int(time.time()));self.packet.update(
            engine='8.0',declarations=full.DECLARATIONS,attempt_id='a'*32)
        self.files={'manifest':dict(source_sha=host.SOURCE,series='8.0',tooling_sha='a'*40,sql_sha256=reference['sql_bundle_sha256'],reference_sha256=self.pins['reference_sha256']['8.0']),
            'facts':source(ROOT,host.SOURCE),
            'contract':{k:v for k,v in json.loads(host.git(ROOT,'show',host.SOURCE+':resources/release20_pending_schema_contract.json')).items() if k.startswith('absent_')},
            'b_preflight':dict(kind='SYNTHETIC_B1_ONLY',receipt=full.validate(self.packet,self.pins,int(time.time())),
                packet=self.packet,pins=self.pins,owner_approval_verified=False,execution_allowed=False)}
        (self.directory/'reference.json').write_bytes(raw)
        self.write()

    def write(self):
        for name,data in self.files.items():(self.directory/(name+'.json')).write_text(json.dumps(data))

    def test_fixed_assets_are_synthetic_and_cannot_authorize_live_execution(self):
        facts,reference,contract=self.bridge.assets(self.directory)
        self.assertEqual('8.0',reference['engine'])
        self.assertEqual(self.bridge.SEAL,facts['migration_seal'])
        self.assertFalse(self.files['b_preflight']['execution_allowed'])

    def test_reference_mutation_is_rejected_before_any_connection(self):
        p=self.directory/'reference.json';p.write_bytes(p.read_bytes()+b' ')
        with patch.object(self.bridge,'Session') as connection:
            with self.assertRaises(self.bridge.Block):self.bridge.assets(self.directory)
            connection.assert_not_called()

    def test_wrong_source_or_contract_and_forged_approval_are_rejected(self):
        for name,field,value in [('manifest','source_sha','f'*40),('facts','migration_seal','f'*64),
                                  ('contract','absent_tables',[]),('b_preflight','execution_allowed',True),
                                  ('b_preflight','owner_approval_verified',True),('manifest','tooling_sha','f'*40),('manifest','sql_sha256','f'*64)]:
            original=copy.deepcopy(self.files)
            self.files[name][field]=value;self.write()
            with self.assertRaises(self.bridge.Block):self.bridge.assets(self.directory)
            self.files=original;self.write()

    def test_expired_packet_and_retry_cannot_cross_bridge(self):
        for change in ('expiry','retry'):
            packet=self.files['b_preflight']['packet']
            if change=='expiry':packet['starts_at']=1;packet['ends_at']=2
            else:packet.update(starts_at=int(time.time()),ends_at=int(time.time())+120,prior_attempt='INTERRUPTED')
            self.write()
            with self.assertRaises(self.bridge.Block):self.bridge.assets(self.directory)

    def test_collector_latches_before_attempt_and_never_retries(self):
        with patch.object(self.bridge,'assets',return_value=object()):collector=self.bridge.Collector(object())
        with patch.object(self.bridge,'Session',side_effect=self.bridge.Block('REMOTE_VISIBILITY_UNPROVEN')) as opened:
            with self.assertRaises(self.bridge.Block):collector.capture()
            with self.assertRaisesRegex(self.bridge.Block,'NEW_OWNER_GO_REQUIRED'):collector.capture()
            self.assertEqual(1,opened.call_count)
