"""Synthetic files only. No extraction, application server, secrets or external I/O."""
import contextlib
import io
import json
import os
from pathlib import Path
import shutil
import stat
import subprocess
import sys
import tempfile
import unittest
import zipfile
import preparation_static_build as b

class Distribution(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.tmp=tempfile.TemporaryDirectory(prefix='preparation-security-')
        cls.base=Path(cls.tmp.name)
        for profile in ('B','C'):
            b.build(cls.base/profile,profile)
    @classmethod
    def tearDownClass(cls):cls.tmp.cleanup()
    def setUp(self):
        self.temp=tempfile.TemporaryDirectory(dir=self.base)
        self.root=Path(self.temp.name)/'candidate';shutil.copytree(self.base/'B',self.root)
    def tearDown(self):self.temp.cleanup()
    def rejected(self):
        with self.assertRaises((ValueError,OSError,KeyError)):b.verify_directory(self.root)
    def test_profiles_reproducible_and_source(self):
        for profile in ('B','C'):
            original=self.base/profile
            self.assertEqual(b.verify_archive(str(original)+'.zip')['profile'],profile)
            files=b.verify_directory(original)
            self.assertNotIn('sw.js',files)
            self.assertFalse(any(n.endswith('.php') or n.startswith(('.','tests/','admin/')) for n in files))
            other=Path(self.temp.name)/profile;b.build(other,profile)
            self.assertEqual(original.with_suffix('.zip').read_bytes(),other.with_suffix('.zip').read_bytes())
            self.assertIn("connect-src 'none'",files['_headers'].decode())
            # Module imports resolve only inside this frozen public closure.
            import re
            for name,raw in files.items():
                if name.endswith('.mjs'):
                    for ref in re.findall(r"(?:from\s+|import\s*)['\"]([^'\"]+)['\"]",raw.decode()):
                        self.assertTrue(ref.startswith('./'),ref)
                        self.assertIn(str(Path(name).parent/ref),files)
    def test_tamper_even_with_recomputed_manifest(self):
        p=self.root/'src/app.mjs';p.write_bytes(p.read_bytes()+b'\n// synthetic alteration')
        m=self.root/'manifest.json';v=json.loads(m.read_text());v['files']['src/app.mjs']=b.digest(p.read_bytes());m.write_bytes(b.canonical_manifest(v));self.rejected()
    def test_sha_tree_flags_and_manifest_traversal(self):
        m=self.root/'manifest.json';original=m.read_bytes()
        for key,value in [('source_sha','0'*40),('source_tree','0'*40),('release_approved',True),('adopted_origin','http://invalid.test'),('files',{'../../canary':'0'*64})]:
            with self.subTest(key=key):
                v=json.loads(original);v[key]=value;m.write_bytes(b.canonical_manifest(v));self.rejected()
        m.write_bytes(original)
    def test_extra_missing_and_private_files(self):
        for name in ('.env','secret.php','admin.js','sw.js','raw.json'):
            with self.subTest(name=name):
                p=self.root/name;p.write_text('SYNTHETIC_ONLY');self.rejected();p.unlink()
        (self.root/'index.html').unlink();self.rejected()
    def test_symlink_file_parent_manifest_and_root(self):
        for name in ('index.html','src','manifest.json'):
            with self.subTest(name=name):
                target=self.root/name;keep=Path(self.temp.name)/('saved-'+name)
                target.rename(keep);target.symlink_to(keep,target_is_directory=keep.is_dir());self.rejected();target.unlink();keep.rename(target)
        alias=Path(self.temp.name)/'alias';alias.symlink_to(self.root,target_is_directory=True)
        with self.assertRaises(ValueError):b.verify_directory(alias)
    def test_fifo_hardlink_and_oversize(self):
        p=self.root/'index.html';p.unlink();os.mkfifo(p);self.rejected();p.unlink()
        os.link(self.base/'B/index.html',p);self.rejected();p.unlink()
        p.write_bytes(b'x'*(b.MAX_MEMBER+1));self.rejected()
    def test_public_path_boundaries(self):
        for name in ('../index.html','/index.html','src/../../index.html'):
            with self.subTest(name=name),self.assertRaises(ValueError):b.public_file(self.root,name,b.MAX_MEMBER)
    def test_zip_rejects_traversal_duplicate_symlink_and_tamper(self):
        source=self.base/'B.zip'
        for mutation in ('traversal','duplicate','symlink','tamper','compressed'):
            p=Path(self.temp.name)/(mutation+'.zip')
            with zipfile.ZipFile(source) as old,zipfile.ZipFile(p,'w') as z:
                for info in old.infolist():
                    raw=old.read(info.filename)
                    if info.filename=='index.html':
                        if mutation=='traversal':info.filename='../index.html'
                        if mutation=='symlink':info.external_attr=(stat.S_IFLNK|0o777)<<16
                        if mutation=='tamper':raw+=b'not approved'
                        if mutation=='compressed':info.compress_type=zipfile.ZIP_DEFLATED
                    z.writestr(info,raw)
                if mutation=='duplicate':
                    import warnings
                    with warnings.catch_warnings():
                        warnings.simplefilter('ignore');z.writestr('index.html',b'duplicate')
            with self.subTest(mutation=mutation),self.assertRaises((ValueError,KeyError)):b.verify_archive(p)
        self.assertFalse((Path(self.temp.name)/'index.html').exists(),'Verifier never extracts')
    def test_cli_fixed_error_no_argument_leak(self):
        for script,marker in [('preparation_static_build.py','PREPARATION_BUILD_BLOCK'),('preparation_static_serve.py','PREPARATION_LAB_BLOCK')]:
            p=subprocess.run([sys.executable,'-B',str(b.ROOT/'scripts/ci'/script),'--secret=SYNTHETIC_CANARY'],capture_output=True,text=True)
            self.assertEqual(p.returncode,2);self.assertEqual(p.stdout,'');self.assertEqual(p.stderr.strip(),marker)

if __name__=='__main__':unittest.main()
