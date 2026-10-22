"""Clean HEAD, reproducible public bundle, actual import and wheel tree."""
import hashlib
import io
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest
import zipfile
sys.path.insert(0,str(Path(__file__).resolve().parents[2]/'scripts/ci'))
from e858_phase1 import b1_package as b, b1_integrity as r, private_distribution as d
from e858_phase1.private_binding import Block
import e858_b_offline as old

class Bundle(unittest.TestCase):
    def test_full_bundle_and_checked_checkout(self):
        directory=Path(os.environ['B1_BUILD_DIR'])
        wheel=(directory/d.WHEEL).read_bytes()
        head=old.git(old.ROOT,'rev-parse','HEAD').decode().strip()
        raw,pins=b.build(old.ROOT,head,wheel)
        self.assertEqual(raw,b.build(old.ROOT,head,wheel)[0])
        digest=d._sha(raw)
        self.assertFalse(b.verify(raw,digest,pins)['execution_allowed'])
        self.assertFalse(r.checkout(old.ROOT,head,raw,digest)['execution_allowed'])
        r.installed_wheel(wheel)
        directory.joinpath('b1-candidate.zip').write_bytes(raw)
        print('B1_BUNDLE_SHA256='+digest)
        for changed in (raw+b'x',raw[:-1]):
            with self.assertRaises(Block):b.verify(changed,digest,pins)
        wrong=dict(pins,tooling_sha='f'*40)
        with self.assertRaises(Block):b.verify(raw,digest,wrong)
        with tempfile.TemporaryDirectory() as temp:
            root=Path(temp)
            with zipfile.ZipFile(io.BytesIO(raw)) as outer:
                for name in outer.namelist():
                    path=root/name;path.parent.mkdir(parents=True,exist_ok=True);path.write_bytes(outer.read(name))
            with zipfile.ZipFile(root/'library.zip') as inner:
                for name in inner.namelist():
                    path=root/name;path.parent.mkdir(parents=True,exist_ok=True);path.write_bytes(inner.read(name))
            code='''import sys
sys.path[:0]=[sys.argv[1],sys.argv[1]+'/e858_tls']
def deny(event,args):
    if event.startswith('socket.'):raise RuntimeError('NETWORK_FORBIDDEN')
sys.addaudithook(deny)
from e858_phase1.b1_operator import Entry
from e858_phase1.b1_bridge import Bridge
from e858_phase1.practical import Adapter, PrivateReview, PrivateWire, Program, ReviewedEntry
from e858_phase1.phase1_application import Phase1ApplicationReview
from e858_phase1.b1_integrity import installed_wheel
from e858_tls.bridge import assets,assess
print('B1_IMPORT_CLOSURE_VERIFIED')
'''
            result=subprocess.run([sys.executable,'-I','-B','-c',code,str(root/'scripts/ci')],capture_output=True,timeout=10)
            self.assertEqual(result.returncode,0,'B1_IMPORT_CLOSURE_BLOCKED')
            self.assertEqual(result.stdout,b'B1_IMPORT_CLOSURE_VERIFIED\n');self.assertEqual(result.stderr,b'')

if __name__=='__main__':unittest.main()
