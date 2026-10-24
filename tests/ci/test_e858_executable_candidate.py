"""Executable offline package / preserved bounded-kernel delta, no DB access."""
import ast
import io
import json
import os
from pathlib import Path
import subprocess
import sys
import unittest
import zipfile

sys.path.insert(0,str(Path(__file__).resolve().parents[2]/'scripts/ci'))
from e858_phase1 import executable_package as package,private_distribution as dist
import e858_b_offline as b


class Executable(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.directory=Path(os.environ['B1_BUILD_DIR'])
        cls.head=b.git(b.ROOT,'rev-parse','HEAD').decode().strip()
        cls.wheel=(cls.directory/dist.WHEEL).read_bytes()
        cls.raw,cls.meta=package.build(b.ROOT,cls.head,cls.wheel)
        cls.path=cls.directory/'phase1-offline-candidate.pyz';cls.path.write_bytes(cls.raw)

    def execute(self,path=None,args=('--verify',),extra=None):
        env=b.local_env();env.update(extra or {})
        return subprocess.run([sys.executable,'-I','-S','-B',str(path or self.path),*args],stdin=subprocess.DEVNULL,
            capture_output=True,timeout=15,env=env)

    def test_deterministic_fixture_free_distribution_and_frozen_pins(self):
        self.assertEqual(self.raw,package.build(b.ROOT,self.head,self.wheel)[0])
        with zipfile.ZipFile(io.BytesIO(self.raw)) as z:
            for name in z.namelist():
                self.assertNotIn('fixture',name);self.assertNotIn('transport.py',name)
                self.assertNotIn('r1_adapter.py',name);self.assertNotIn('control_contract.py',name)
                self.assertNotIn(b'ci-only-tls-',z.read(name))
        self.assertEqual(self.meta['pins']['source_sha'],b.SOURCE)
        self.assertFalse(self.meta['execution_allowed'])
        print('PHASE1_EXECUTABLE_SHA256='+dist._sha(self.raw))

    def test_self_contained_snapshot_import_without_site_packages_or_network(self):
        child=self.execute();self.assertEqual(child.returncode,0,child.stdout.decode())
        self.assertEqual(child.stderr,b'');v=json.loads(child.stdout)
        self.assertEqual(v['artifact_sha256'],dist._sha(self.raw))
        self.assertEqual(v['tooling_sha'],self.head)
        self.assertEqual(v['status'],'TECHNICAL_WORK_REMAINING')
        for key in ('execution_allowed','owner_approval_verified','real_aiven_connected','production_go','live_tooling_adopted'):
            self.assertIs(v[key],False)

    def test_no_live_mode_credentials_arguments_or_environment_override(self):
        canary='PRIVATE_VALUE_NEVER_RENDER'
        for args in ((),('--phase1',),('--go',),('--verify','--target',canary),('--verify','--password',canary)):
            result=self.execute(args=args,extra={'B2_GO':'true','LIVE_APPROVED':'1'})
            self.assertEqual(result.returncode,2);self.assertEqual(result.stderr,b'')
            self.assertNotIn(canary.encode(),result.stdout);self.assertEqual(json.loads(result.stdout)['result'],'BLOCK')
        result=self.execute(extra={'B2_GO':'true'})
        self.assertEqual(result.returncode,0);self.assertFalse(json.loads(result.stdout)['execution_allowed'])

    def test_modified_payload_unknown_member_and_symlink_refused(self):
        with zipfile.ZipFile(io.BytesIO(self.raw)) as z:files={n:z.read(n) for n in z.namelist()}
        for name in ('review.zip','UNKNOWN'):
            changed=dict(files);changed[name]=changed.get(name,b'')+b'bad'
            path=self.directory/('tamper-'+name.replace('.','-')+'.pyz');path.write_bytes(dist.pack(changed))
            value=self.execute(path);self.assertEqual(value.returncode,2);self.assertEqual(value.stderr,b'')
        link=self.directory/'candidate-link.pyz'
        if link.is_symlink():link.unlink()
        link.symlink_to(self.path)
        value=self.execute(link);self.assertEqual(value.returncode,2)


class KernelPreservation(unittest.TestCase):
    base='6a93a5ff3b19b31f34f04b4f9bb9a5f372188b6f'
    def node(self,path,name,parent=None,old=False):
        raw=b.git(b.ROOT,'show',self.base+':'+path) if old else (b.ROOT/path).read_bytes()
        tree=ast.parse(raw);nodes=tree.body
        if parent:nodes=next(n for n in nodes if isinstance(n,ast.ClassDef) and n.name==parent).body
        node=next(n for n in nodes if getattr(n,'name',None)==name)
        return ast.dump(ast.Module(body=node.body,type_ignores=[]))

    def test_auth_stream_caps_and_identity_logic_moved_without_relaxation(self):
        for method in ('__init__','_read_bytes','_request_authentication'):
            self.assertEqual(self.node('scripts/ci/e858_tls/transport.py',method,'StrictConnection',True),
                self.node('scripts/ci/e858_tls/stream_core.py',method,'StrictConnection'))
        self.assertEqual(self.node('scripts/ci/e858_tls/transport.py','_execute','Session',True),
            self.node('scripts/ci/e858_tls/stream_core.py','execute'))
        for method in ('_read_bytes','_write_bytes','_execute_command','graceful_close','close'):
            self.assertEqual(self.node('scripts/ci/e858_phase1/runtime.py',method,'Wire',True),
                self.node('scripts/ci/e858_phase1/wire_core.py',method,'Wire'))
        for method in ('_active','_fields','observe_identity'):
            self.assertEqual(self.node('scripts/ci/e858_phase1/private_candidate.py',method,'Candidate',True),
                self.node('scripts/ci/e858_phase1/identity_core.py',method,'IdentityCandidate'))


if __name__=='__main__':unittest.main()
