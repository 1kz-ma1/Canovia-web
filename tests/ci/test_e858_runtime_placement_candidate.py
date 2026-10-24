"""Fresh synthetic private anchors/public archives only; no live bootstrap."""
import importlib.util
import os
from pathlib import Path
import sys
import tempfile
import time
import unittest
from unittest.mock import patch

sys.path.insert(0,str(Path(__file__).resolve().parents[2]/'scripts/ci'))
from e858_phase1 import runtime_placement_candidate as runtime
from e858_phase1 import private_distribution as dist
from e858_phase1.private_binding import Block
import e858_b_offline as offline


class Placement(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.head=offline.git(offline.ROOT,'rev-parse','HEAD').decode().strip()
        cls.pins=offline.integrity(offline.ROOT,cls.head)
        cls.archive=(Path(os.environ['B1_BUILD_DIR'])/'b1-candidate.zip').read_bytes()
        cls.sha=dist._sha(cls.archive)

    def setUp(self):
        self.temp=tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root=Path(self.temp.name)
        self.fd=os.open(self.root,os.O_RDONLY|os.O_DIRECTORY|os.O_CLOEXEC)
        self.addCleanup(os.close,self.fd)

    def new(self,**kw):
        candidate=runtime.RuntimePlacementCandidate(self.fd,kw.get('archive',self.archive),
            kw.get('sha',self.sha),self.pins,kw.get('until',time.monotonic()+30))
        self.addCleanup(candidate.close)
        return candidate

    def test_placement_snapshot_loader_and_explicit_no_authority(self):
        candidate=self.new()
        result=candidate.check()
        self.assertFalse(result['execution_allowed'])
        self.assertNotIn(str(self.root),repr(result)+repr(candidate))
        spec=candidate.spec('e858_phase1.sql_pins')
        module=importlib.util.module_from_spec(spec)
        spec.loader.exec_module(module)
        from e858_phase1.sql_pins import Q0_SHA,Q1_SHA
        self.assertEqual((module.Q0_SHA,module.Q1_SHA),(Q0_SHA,Q1_SHA))
        self.assertEqual(candidate.source('scripts/ci/e858_phase1/sql_pins.py'),
                         (self.root/runtime.BASENAME/'scripts/ci/e858_phase1/sql_pins.py').read_bytes())
        candidate.close()
        with self.assertRaises(Block):spec.loader.exec_module(module)

    def test_no_overwrite_symlink_existing_and_untrusted_anchor(self):
        (self.root/runtime.BASENAME).symlink_to(self.root)
        with self.assertRaises(Block):self.new()
        (self.root/runtime.BASENAME).unlink()
        (self.root/runtime.BASENAME).mkdir(mode=0o700)
        with self.assertRaises(Block):self.new()
        self.root.chmod(0o755)
        with self.assertRaises(Block):self.new()
        self.root.chmod(0o700)

    def test_tampered_archive_and_expiry_refuse_before_placement(self):
        for values in ({'sha':'0'*64},{'archive':self.archive+b'x'},
                       {'until':time.monotonic()-1},{'until':time.monotonic()+121}):
            with self.assertRaises(Block):self.new(**values)
            self.assertFalse((self.root/runtime.BASENAME).exists())

    def test_replacement_hardlink_and_extra_file_are_terminal(self):
        for fault in ('replace','hardlink','extra','symlink'):
            with self.subTest(fault=fault),tempfile.TemporaryDirectory() as temp:
                fd=os.open(temp,os.O_RDONLY|os.O_DIRECTORY|os.O_CLOEXEC)
                try:candidate=runtime.RuntimePlacementCandidate(fd,self.archive,self.sha,self.pins,time.monotonic()+30)
                finally:os.close(fd)
                root=Path(temp)/runtime.BASENAME
                target=root/'scripts/ci/e858_phase1/sql_pins.py'
                if fault=='replace':
                    raw=target.read_bytes();target.unlink();target.write_bytes(raw);target.chmod(0o400)
                elif fault=='hardlink':os.link(target,root/'linked')
                elif fault=='extra':(root/'extra').write_bytes(b'not-public')
                else:target.unlink();target.symlink_to(root/'dependency.whl')
                with self.assertRaises(Block):candidate.check()
                with self.assertRaises(Block):candidate.spec('e858_phase1.sql_pins')
                self.assertEqual(candidate._fds,[])

    def test_import_identity_expiry_and_incomplete_writes(self):
        candidate=self.new()
        spec=candidate.spec('e858_phase1.sql_pins')
        module=importlib.util.module_from_spec(spec);module.__spec__.origin='different'
        with self.assertRaises(Block):spec.loader.exec_module(module)
        self.assertEqual(candidate._fds,[])
        # A failed root stays quarantined; never turn cleanup into a retry permit.
        with self.assertRaises(Block):self.new()

    def test_unknown_module_and_loader_after_file_change_reject(self):
        candidate=self.new();spec=candidate.spec('e858_phase1.sql_pins')
        module=importlib.util.module_from_spec(spec)
        path=self.root/runtime.BASENAME/'scripts/ci/e858_phase1/sql_pins.py'
        path.chmod(0o600);path.write_bytes(b'raise RuntimeError("SYNTHETIC_SECRET")')
        with self.assertRaises(Block) as caught:spec.loader.exec_module(module)
        self.assertNotIn('SYNTHETIC_SECRET',str(caught.exception))

    def test_write_failure_cleanup_and_closed_fd_count(self):
        baseline=len(os.listdir('/dev/fd'))
        with patch.object(runtime.os,'write',side_effect=OSError('SYNTHETIC_SECRET')):
            with self.assertRaises(Block) as caught:self.new()
        self.assertNotIn('SYNTHETIC_SECRET',str(caught.exception))
        self.assertEqual(len(os.listdir('/dev/fd')),baseline)
        self.assertTrue((self.root/runtime.BASENAME).exists())

    def test_expired_snapshot_and_unknown_dependency_do_not_load(self):
        candidate=self.new();candidate.until=time.monotonic()-1
        with self.assertRaises(Block):candidate.check()
        self.assertEqual(candidate._fds,[])
        for name in ('run','connect','activate','capture','grant_go'):
            self.assertFalse(hasattr(runtime.RuntimePlacementCandidate,name))


if __name__=='__main__':unittest.main()
