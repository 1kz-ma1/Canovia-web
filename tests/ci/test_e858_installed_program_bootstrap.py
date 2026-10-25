"""Actual staged Program/wheel imports, offline fresh interpreter only."""
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest

sys.path.insert(0,str(Path(__file__).resolve().parents[2]/'scripts/ci'))
import e858_b_offline as offline
from e858_phase1 import private_distribution as distribution

# This EXCLUDED test seed loads public code only. It is not a live launcher.
CHILD=r'''
import importlib,importlib.abc,importlib.machinery,importlib.util,json,os,sys,time
from pathlib import Path
root,anchor,archive_path,head,sha,mode=sys.argv[1:]
def no_network(event,args):
    if event.startswith('socket.'):raise RuntimeError('NETWORK_FORBIDDEN')
sys.addaudithook(no_network)
sys.path.insert(0,str(Path(root)/'scripts/ci'))
from e858_phase1.runtime_placement_candidate import RuntimePlacementCandidate,BASENAME
from e858_phase1 import b1_package,private_distribution
import e858_b_offline
pins=e858_b_offline.integrity(Path(root),head)
archive=Path(archive_path).read_bytes()
fd=os.open(anchor,os.O_RDONLY|os.O_DIRECTORY|os.O_CLOEXEC)
try:candidate=RuntimePlacementCandidate(fd,archive,sha,pins,time.monotonic()+120)
finally:os.close(fd)
staged=Path(anchor)/BASENAME
candidate.bind_public_origin(str(staged))
source_names=set()
for relative in candidate._files:
    prefix='scripts/ci/' if relative.startswith('scripts/ci/') else 'dependency/' if relative.startswith('dependency/') else None
    if prefix and relative.endswith('.py'):
        name=relative[len(prefix):-3].replace('/','.')
        if name.endswith('.__init__'):name=name[:-9]
        source_names.add(name)
namespaces={'e858_phase1','e858_tls','e858_audit'}
class SnapshotFinder(importlib.abc.MetaPathFinder):
    def find_spec(self,fullname,path=None,target=None):
        if fullname in namespaces:
            spec=importlib.machinery.ModuleSpec(fullname,None,is_package=True)
            spec.submodule_search_locations=[str(staged/'scripts/ci'/fullname)]
            return spec
        if fullname in source_names:return candidate.spec(fullname)
        return None
# Drop the public seed modules; the Program MUST come from staged snapshots.
for name in list(sys.modules):
    if name in source_names or name in namespaces:del sys.modules[name]
sys.path=[str(staged/'scripts/ci'),str(staged/'dependency')]+[x for x in sys.path if x!=str(Path(root)/'scripts/ci')]
sys.meta_path.insert(0,SnapshotFinder())
for name in sorted(source_names):importlib.import_module(name)
from e858_phase1.practical import Program,Adapter,PrivateWire
from e858_phase1.runtime_file_lock_candidate import RuntimeFileLockCandidate
from e858_phase1.opener_candidate import OpenerCandidate
from e858_phase1.launcher_candidate import LauncherCandidate
program=Program(archive,sha,pins)
lock=RuntimeFileLockCandidate(program,root,archive,sha,head,source_root=str(staged))
try:
    assert lock.check()['file_placement']=='STAGED_PROGRAM_ORIGINS_OBSERVED_NOT_ADOPTED'
    import pymysql
    assert Path(pymysql.__file__).is_relative_to(staged)
    assert Path(sys.modules[Program.__module__].__file__).is_relative_to(staged)
    if mode=='replace':
        path=staged/'scripts/ci/e858_phase1/sql_pins.py'
        raw=path.read_bytes();path.unlink();path.write_bytes(raw);path.chmod(0o400)
        try:lock.check_sources()
        except BaseException:pass
        else:raise AssertionError('REPLACEMENT_ACCEPTED')
        try:candidate.check()
        except BaseException:pass
        else:raise AssertionError('PLACEMENT_ACCEPTED_REPLACEMENT')
    elif mode=='origin':
        sys.modules['e858_phase1.sql_pins'].__spec__.origin='/SYNTHETIC_OTHER_ORIGIN'
        try:lock.check_sources()
        except BaseException:pass
        else:raise AssertionError('ORIGIN_ACCEPTED')
    elif mode!='normal':raise AssertionError('FIXTURE_MODE')
    for cls,method in ((Adapter,'prepare'),(PrivateWire,'connect'),(OpenerCandidate,'open'),(LauncherCandidate,'run')):
        try:getattr(cls,method)(object.__new__(cls))
        except BaseException:pass
        else:raise AssertionError('PUBLIC_EXECUTION')
    print(json.dumps(dict(result='STAGED_PROGRAM_WHEEL_ORIGINS_VERIFIED_OFFLINE',
        execution_allowed=False,real_aiven_connected=False,production_go=False)))
finally:lock.close();candidate.close()
'''


class InstalledProgram(unittest.TestCase):
    def trial(self,mode):
        root=offline.ROOT
        head=offline.git(root,'rev-parse','HEAD').decode().strip()
        archive=Path(os.environ['B1_BUILD_DIR'])/'b1-candidate.zip'
        with tempfile.TemporaryDirectory() as temporary:
            anchor=Path(temporary).resolve()
            result=subprocess.run([sys.executable,'-I','-S','-B','-c',CHILD,str(root),str(anchor),str(archive),head,
                                   distribution._sha(archive.read_bytes()),mode],capture_output=True,timeout=120)
        self.assertEqual(result.returncode,0,'STAGED_PROGRAM_BOOTSTRAP_BLOCKED')
        self.assertEqual(result.stderr,b'')
        report=json.loads(result.stdout)
        self.assertEqual(report['result'],'STAGED_PROGRAM_WHEEL_ORIGINS_VERIFIED_OFFLINE')
        self.assertFalse(report['execution_allowed']);self.assertFalse(report['real_aiven_connected'])

    def test_complete_program_and_embedded_wheel_load_from_verified_staged_sources(self):self.trial('normal')
    def test_identical_bytes_replacement_after_import_is_rejected(self):self.trial('replace')
    def test_loaded_origin_change_after_import_is_rejected(self):self.trial('origin')


if __name__=='__main__':unittest.main()
