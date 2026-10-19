"""CI-only Git tamper and private receipt file tests; not owner packet fixtures."""
import copy
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest
from unittest.mock import patch

ROOT=Path(__file__).resolve().parents[2]
sys.path.insert(0,str(ROOT/'scripts/ci'))
from e858_approval_offline import guard as g
from e858_approval_offline.contracts import Block


class SourceTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.tmp=tempfile.TemporaryDirectory(prefix='approval-source-')
        cls.root=Path(cls.tmp.name).resolve()/'repo'
        subprocess.run(['git','clone','--quiet','--shared',str(ROOT),str(cls.root)],check=True,
                       stdout=subprocess.PIPE,stderr=subprocess.PIPE)
        cls.head=g.git(ROOT,'rev-parse','HEAD')
        for ref,sha in g.PINS.items():
            g.git(cls.root,'update-ref','refs/remotes/origin/'+ref,sha)

    @classmethod
    def tearDownClass(cls):cls.tmp.cleanup()

    def test_valid_descendant_original_seven_and_source_seal(self):
        facts=g.boundary(self.root,self.head)
        self.assertEqual((94,114),(len(facts['baseline']),len(facts['candidate'])))
        self.assertEqual('a3494c1d1cf60546a86312f249f7d44b2ac52e44669752a65e1c70011ec9f91b',facts['migration_seal'])

    def test_head_source_tooling_transport_release_and_main_drift(self):
        with self.assertRaisesRegex(Block,'VERIFIER_HEAD_REJECTED'):g.boundary(self.root,'b'*40)
        for ref,sha in g.PINS.items():
            name='refs/remotes/origin/'+ref
            try:
                g.git(self.root,'update-ref',name,self.head)
                with self.assertRaisesRegex(Block,'FIXED_REF_DRIFT'):g.boundary(self.root,self.head)
            finally:g.git(self.root,'update-ref',name,sha)

    def test_dirty_and_shallow(self):
        file=self.root/'synthetic-untracked';file.write_text('synthetic')
        try:
            with self.assertRaisesRegex(Block,'CLEAN_CHECKOUT_REQUIRED'):g.boundary(self.root,self.head)
        finally:file.unlink()
        shallow=Path(self.tmp.name).resolve()/'shallow'
        subprocess.run(['git','clone','--quiet','--depth=1',ROOT.as_uri(),str(shallow)],check=True,
                       stdout=subprocess.PIPE,stderr=subprocess.PIPE)
        with self.assertRaisesRegex(Block,'FULL_HISTORY_REQUIRED'):g.boundary(shallow,self.head)

    def test_assume_unchanged_and_skip_worktree_flags(self):
        file='scripts/ci/e858_audit/queries/engine.sql'
        for yes,no in [('--assume-unchanged','--no-assume-unchanged'),('--skip-worktree','--no-skip-worktree')]:
            try:
                g.git(self.root,'update-index',yes,file)
                with self.assertRaisesRegex(Block,'INDEX_FLAGS_REJECTED'):g.boundary(self.root,self.head)
            finally:g.git(self.root,'update-index',no,file)

    def test_symlink_sql_reference_and_new_verifier_tamper_even_if_status_lies(self):
        real_git=g.git
        def ignore_status(root,*args):
            return '' if args and args[0]=='status' else real_git(root,*args)
        for relative in ('scripts/ci/e858_audit/queries/engine.sql',
                         'scripts/ci/e858_audit_reference/8.0.json',g.FILES[0]):
            file=self.root/relative;raw=file.read_bytes()
            try:
                file.write_bytes(b'SYNTHETIC_PRIVATE')
                with patch.object(g,'git',side_effect=ignore_status):
                    with self.assertRaisesRegex(Block,'TRACKED_FILE_REJECTED'):g.boundary(self.root,self.head)
                file.unlink();file.symlink_to(ROOT/relative)
                with patch.object(g,'git',side_effect=ignore_status):
                    with self.assertRaisesRegex(Block,'TRACKED_FILE_REJECTED'):g.boundary(self.root,self.head)
            finally:file.unlink();file.write_bytes(raw)

    def test_extra_file_or_inherited_modification_scope_blocks(self):
        real_git=g.git
        for base,extra in [(g.TRANSPORT,'M\tscripts/ci/e858_audit/core.py'),
                           (g.TOOLING,'A\tsynthetic-extra')]:
            def changed(root,*args):
                data=real_git(root,*args)
                if len(args)>=4 and args[:2]==('diff','--name-status') and args[2]==base:
                    return data+'\n'+extra
                return data
            with patch.object(g,'git',side_effect=changed):
                with self.assertRaises(Block):g.boundary(self.root,self.head)

    def test_credentials_and_enable_environment_cannot_unlock(self):
        for key in ('MYSQL_PWD','MYSQL_HOME','MYSQL_TEST_LOGIN_FILE','DATABASE_URL',
                    'DB_HOST','CANOVIA_TRANSPORT_ENABLE','CANOVIA_P0_LOGIN_PATH'):
            with patch.dict(os.environ,{key:'SYNTHETIC_PRIVATE'},clear=True),patch('subprocess.Popen') as process:
                with self.assertRaisesRegex(Block,'ENVIRONMENT_OVERRIDE_REJECTED'):g.boundary(self.root,self.head)
                process.assert_not_called()


class ReceiptFileTests(unittest.TestCase):
    def fixture(self,path):
        for name in ('tooling-ci.json','transport-ci.json','gate-ci.json'):
            file=path/name;file.write_text('{}');file.chmod(0o600)

    def test_only_three_private_ci_files_read(self):
        with tempfile.TemporaryDirectory() as tmp:
            path=Path(tmp).resolve();self.fixture(path)
            self.assertEqual(3,len(g.private_receipts(path)))
            (path/'not-a-ci-file').write_text('synthetic')
            with patch('os.open') as opened:
                with self.assertRaisesRegex(Block,'CI_FILES_REJECTED'):g.private_receipts(path)
                opened.assert_not_called()

    def test_mode_symlink_fifo_duplicate_keys_and_size_block(self):
        with tempfile.TemporaryDirectory() as tmp:
            path=Path(tmp).resolve();self.fixture(path);file=path/'gate-ci.json'
            file.chmod(0o644)
            with self.assertRaisesRegex(Block,'CI_FILES_REJECTED'):g.private_receipts(path)
            file.chmod(0o600);file.write_text('{"x":1,"x":2}')
            with self.assertRaisesRegex(Block,'CI_RECEIPT_REJECTED'):g.private_receipts(path)
            file.write_text('{')
            with self.assertRaisesRegex(Block,'CI_RECEIPT_REJECTED'):g.private_receipts(path)
            file.unlink();file.symlink_to(path/'tooling-ci.json')
            with self.assertRaisesRegex(Block,'CI_FILES_REJECTED'):g.private_receipts(path)
            file.unlink();os.mkfifo(file,0o600)
            with self.assertRaisesRegex(Block,'CI_FILES_REJECTED'):g.private_receipts(path)
            file.unlink();file.write_bytes(b'x'*(1024*1024+1));file.chmod(0o600)
            with self.assertRaisesRegex(Block,'CI_FILES_REJECTED'):g.private_receipts(path)


if __name__=='__main__':unittest.main()
