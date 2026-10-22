"""CI integration only, after building a verified archive from this clean HEAD.

No network/TTY/target file. Extract only our verified fixed public members into
an owned temp directory to check import closure with socket audit hooks denied.
"""
import hashlib
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest
import zipfile

sys.path.insert(0,str(Path(__file__).resolve().parents[2]/'scripts/ci'))
from e858_phase1.private_distribution import verify
import e858_b_offline as b


class ArchiveClosure(unittest.TestCase):
    def test_verified_archive_contains_all_internal_import_dependencies(self):
        # Required fixture, not a skip: the preceding CI build must produce it.
        raw=(Path(os.environ['BUILD_DIR'])/'candidate.zip').read_bytes()
        head=b.git(b.ROOT,'rev-parse','HEAD').decode().strip()
        pins=b.integrity(b.ROOT,head)
        result=verify(raw,hashlib.sha256(raw).hexdigest(),pins)
        self.assertFalse(result['execution_allowed'])
        with tempfile.TemporaryDirectory(prefix='canovia-verified-public-import-') as temp:
            root=Path(temp)
            with zipfile.ZipFile(__import__('io').BytesIO(raw)) as archive:
                # verify already enforces exact names, regular mode, size/no links.
                for name in archive.namelist():
                    file=root/name;file.parent.mkdir(parents=True,exist_ok=True);file.write_bytes(archive.read(name))
            code='''import sys
sys.path.insert(0,sys.argv[1])
def deny(event,args):
    if event.startswith('socket.'): raise RuntimeError('NETWORK_DISABLED')
sys.addaudithook(deny)
from e858_phase1.private_candidate import Candidate, DisabledWire
from e858_phase1.private_binding import Target
from e858_phase1.private_root import open_private_root
from e858_phase1.private_operator import observe_foreground_terminal\nfrom e858_phase1.private_native_runtime import observe_native_runtime
from e858_phase1.private_acl import inspect_native_acl
from e858_phase1.private_distribution import runtime_components
print('INERT_IMPORT_CLOSURE_VERIFIED')
'''
            child=subprocess.run([sys.executable,'-I','-B','-c',code,str(root/'scripts/ci')],
                capture_output=True,timeout=10,env=b.local_env())
            self.assertEqual(child.returncode,0,'PUBLIC_IMPORT_CLOSURE_FAILED')
            self.assertEqual(child.stdout,b'INERT_IMPORT_CLOSURE_VERIFIED\n');self.assertEqual(child.stderr,b'')


if __name__=='__main__':unittest.main()
