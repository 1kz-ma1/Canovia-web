"""Synthetic-only package/drift negatives. No private inputs or network."""
import io
import os
from pathlib import Path
import sys
import tempfile
import ssl
import time
import unittest
from unittest.mock import patch
import zipfile
sys.path.insert(0,str(Path(__file__).resolve().parents[2]/'scripts/ci'))
from e858_phase1 import b1_integrity as b, b1_bridge as bridge
from e858_phase1 import private_distribution as d
from e858_phase1.private_binding import Block,Target
from e858_phase1.runtime import SharedBudget
from e858_phase1.protocol import INIT,CID


class Integrity(unittest.TestCase):
    def setUp(self):
        self.temp=tempfile.TemporaryDirectory();self.addCleanup(self.temp.cleanup)
        self.root=Path(self.temp.name)/'pymysql';self.root.mkdir()
        self.files={'pymysql/__init__.py':b'\n','pymysql/connections.py':b'# synthetic\n'}
        data=io.BytesIO()
        with zipfile.ZipFile(data,'w') as z:
            for k,v in self.files.items():z.writestr(k,v)
        self.wheel=data.getvalue()
        self.pin=patch.object(d,'WHEEL_SHA',d._sha(self.wheel));self.pin.start();self.addCleanup(self.pin.stop)
        for name,raw in self.files.items():(self.root/Path(name).name).write_bytes(raw)

    def test_exact_tree(self):
        self.assertEqual(b.wheel_tree(self.wheel,self.root)['result'],'EXACT_PINNED_WHEEL_TREE')
    def test_extra_file(self):
        for name in ('extra.py','connections.so','extra.pyc'):
            with self.subTest(name=name):
                file=self.root/name;file.write_bytes(b'private canary')
                with self.assertRaises(Block) as caught:b.wheel_tree(self.wheel,self.root)
                self.assertNotIn('canary',str(caught.exception));file.unlink()
    def test_symlink(self):
        file=self.root/'connections.py';file.unlink();file.symlink_to(self.root/'__init__.py')
        with self.assertRaises(Block):b.wheel_tree(self.wheel,self.root)
    def test_changed_bytes(self):
        (self.root/'connections.py').write_bytes(b'changed')
        with self.assertRaises(Block):b.wheel_tree(self.wheel,self.root)
    def test_corrupt_wheel(self):
        with self.assertRaises(Block):b.wheel_tree(self.wheel+b'x',self.root)
    def test_native_not_fake_cross_platform_pass(self):
        if sys.platform=='darwin':
            try:result=b.native_graph()
            except Block as error:self.fail('FIXED_NATIVE_STAGE='+getattr(error,'boundary','UNKNOWN'))
            self.assertFalse(result['runtime_complete']);self.assertFalse(result['execution_allowed'])
            self.assertGreater(result['node_count'],0)
        else:
            with self.assertRaises(Block):b.native_graph()
    def test_bridge_no_live_endpoint_api_or_cli(self):
        import inspect
        self.assertEqual(list(inspect.signature(bridge.Bridge).parameters),['entry','scope'])
        entry=unittest.mock.Mock();entry.check.side_effect=Block()
        with patch('socket.getaddrinfo',side_effect=AssertionError('NETWORK_FORBIDDEN')):
            result=bridge.Bridge(entry).run()
        self.assertEqual(result['result'],'BLOCK');self.assertFalse(result['real_aiven_connected'])
    def test_bound_wire_never_accepts_private_target(self):
        wire=object.__new__(bridge.BoundWire)
        target=Target('synthetic','private_db','private.synthetic.invalid',3306,'inspector',b'fake CA','0'*64,'a'*40,0,2**31)
        with patch('socket.getaddrinfo',side_effect=AssertionError('NO_NETWORK')):
            with self.assertRaises(Block):wire.initialize_bound(None,bytearray(bridge.SYNTHETIC_PASSWORD),{},target)
    def test_bound_wire_rejects_ca_change_and_untrusted_target_class(self):
        target=Target('synthetic',bridge.r.DB,bridge.r.HOST,3306,bridge.r.USER,b'fake CA','0'*64,'a'*40,0,2**31)
        pin=dict(ca_sha256='0'*64,tooling_sha='a'*40)
        for value in (target,unittest.mock.Mock()):
            with self.assertRaises(Block):
                object.__new__(bridge.BoundWire).initialize_bound(None,bytearray(bridge.SYNTHETIC_PASSWORD),pin,value)
    def test_bound_wire_no_filename_ca_reread_and_no_weak_context(self):
        ca=b'synthetic bytes for mock only'
        target=Target('synthetic',bridge.r.DB,bridge.r.HOST,3306,bridge.r.USER,ca,'0'*64,'a'*40,0,2**31)
        pin=dict(ca_sha256=d._sha(ca),tooling_sha='a'*40)
        context=ssl.SSLContext(ssl.PROTOCOL_TLS_CLIENT)
        context.check_hostname=False;context.verify_mode=ssl.CERT_NONE
        with patch.object(Target,'tls_context',return_value=context),patch.object(bridge.Path,'read_bytes',side_effect=AssertionError('NO_PATH_READ')):
            with self.assertRaises(Block):object.__new__(bridge.BoundWire).initialize_bound(None,bytearray(bridge.SYNTHETIC_PASSWORD),pin,target)
    def test_gate_metadata_exact_bytes_and_scope(self):
        cfg=dict(q0=b'fixed q0',q1=b'fixed q1');bundle={k:('fixed '+k).encode() for k in set(bridge.ORDER)}
        gate=bridge.BridgeGate('data',cfg,SharedBudget(),bundle)
        expected=[INIT,cfg['q0'],CID,cfg['q1']]+[bundle[k] for k in bridge.ORDER[2:]]+[CID,cfg['q0']]
        for raw in expected:self.assertEqual(gate.claim(3,raw),raw)
        with self.assertRaises(Exception):gate.claim(3,b'SELECT * FROM users;')
        self.assertEqual(gate.used,21)
    def test_gate_sql_export_protocol_weakening(self):
        cfg=dict(q0=b'q0',q1=b'q1');bundle={k:k.encode() for k in set(bridge.ORDER)}
        for command,sql in ((3,b'SELECT * FROM users;'),(3,b'SELECT 1 INTO OUTFILE "x";'),(14,INIT),(3,INIT+b' ')):
            gate=bridge.BridgeGate('data',cfg,SharedBudget(),bundle)
            with self.assertRaises(Exception):gate.claim(command,sql)
            self.assertEqual(gate.used,0)

if __name__=='__main__':unittest.main()
