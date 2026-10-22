"""Synthetic private trees and fresh controlling PTYs; never owner input."""
import contextlib
import io
import os
from pathlib import Path
import struct
import subprocess
import sys
import tempfile
import termios
import unittest
from unittest.mock import patch

sys.path.insert(0,str(Path(__file__).resolve().parents[2]/'scripts/ci'))
from e858_phase1 import b1_operator as b
from e858_phase1 import r1_driver_fixtures as f
from e858_phase1.r1_adapter import SYNTHETIC_PASSWORD


class EntryTests(unittest.TestCase):
    def setUp(self):
        self.temp=tempfile.TemporaryDirectory(prefix='.b1-synthetic-',dir=Path(os.environ.get('B1_SYNTHETIC_PARENT',str(Path.cwd().resolve()))))
        self.addCleanup(self.temp.cleanup)
        self.path=self.temp.name
        os.chmod(self.path,0o700)

    def test_complete_ancestor_chain(self):
        with b.Root(self.path) as root:
            self.assertGreater(len(root.fds),2)
            self.assertEqual(root.check(),root.fds[-1])
        self.assertEqual(root.fds,[])

    def test_symlink(self):
        path=self.path+'/link';os.symlink(self.path,path)
        with self.assertRaises(b.Block): b.Root(path)

    def test_weak_leaf(self):
        os.chmod(self.path,0o750)
        with self.assertRaises(b.Block): b.Root(self.path)

    def test_weak_ancestor(self):
        child=self.path+'/child';os.mkdir(child,0o700);os.chmod(self.path,0o777)
        with self.assertRaises(b.Block): b.Root(child)

    def test_parent_rename(self):
        child=self.path+'/child';os.mkdir(child,0o700)
        root=b.Root(child)
        try:
            os.rename(child,self.path+'/moved');os.mkdir(child,0o700)
            with self.assertRaises(b.Block): root.check()
        finally: root.close()

    def test_replaced_fd_never_closes_unrelated_fd(self):
        root=b.Root(self.path);fd=root.fds[-1];os.close(fd)
        replacement=os.open('/dev/null',os.O_RDONLY)
        self.assertEqual(fd,replacement)
        try:
            with self.assertRaises(b.Block): root.check()
            with self.assertRaises(b.Block): root.close()
            os.fstat(replacement)
        finally: os.close(replacement)

    def test_native_error_is_block(self):
        with patch.object(b,'native_acl',side_effect=PermissionError('synthetic private value')):
            with self.assertRaises(b.Block) as caught: b.Root(self.path)
        self.assertEqual(str(caught.exception),'PRIVATE_CANDIDATE_BLOCK')

    def test_explicit_invocation_not_approval(self):
        entry=b.Entry()
        with self.assertRaises(b.Block): entry.begin(self.path)
        with self.assertRaises(b.Block): entry.begin(self.path,human_supervised=True)
        self.assertTrue(entry.closed)

    def test_native_empty_supported_is_not_enoent(self):
        raw=struct.pack('<IiI',12,8,0)
        self.assertEqual(b.decode_security(raw,1),'SUPPORTED_FS_EMPTY_SECURITY_OBSERVED')
        for cap in (0,-1,True):
            with self.assertRaises(b.Block): b.decode_security(raw,cap)

    def test_native_acl_allow_and_unknown_flags(self):
        blob=struct.pack('<I',0x012cc16d)+bytes(32)+struct.pack('<II',1,0)+bytes(16)+struct.pack('<II',1,1)
        raw=struct.pack('<IiI',12+len(blob),8,len(blob))+blob
        with self.assertRaises(b.Block): b.decode_security(raw,1)
        blob=blob[:-8]+struct.pack('<II',2,1)
        self.assertEqual(b.decode_security(struct.pack('<IiI',12+len(blob),8,len(blob))+blob,1),'NATIVE_DENY_ONLY_OR_EMPTY_SECURITY_OBSERVED')
        with self.assertRaises(b.Block): b.decode_security(raw[:-1],1)

    def test_real_platform_acl_allows_no_silent_downgrade(self):
        fd=os.open(self.path,os.O_RDONLY|os.O_DIRECTORY)
        try:
            self.assertTrue(b.native_acl(fd))
            if sys.platform=='darwin':
                subprocess.run(['/bin/chmod','+a','everyone allow read',self.path],check=True,capture_output=True)
                try:
                    with self.assertRaises(b.Block): b.native_acl(fd)
                finally: subprocess.run(['/bin/chmod','-N',self.path],check=True,capture_output=True)
        finally: os.close(fd)

    def _pty(self,fault):
        path=self.path
        def child(_,parent):
            if os.getppid()!=parent: raise b.Block()
            entry=b.Entry();captured=None;original=termios.tcgetattr(0)
            try:
                entry.begin(path,human_supervised=True)
                if fault=='EXPIRED':
                    entry.until=0
                    with self.assertRaises(b.Block): entry.capture()
                elif fault=='INTERRUPT':
                    with patch.object(b.WindowedTTY,'_read_hidden',side_effect=KeyboardInterrupt):
                        with self.assertRaises(b.Block): entry.capture()
                else:
                    captured=entry.capture()
                    self.assertEqual(captured._password,SYNTHETIC_PASSWORD)
                    self.assertEqual(termios.tcgetattr(0),original)
                    if fault=='SECOND':
                        with self.assertRaises(b.Block): entry.capture()
                    elif fault=='TTY_CHANGE':
                        mode=termios.tcgetattr(0);mode[3]^=termios.ECHO
                        termios.tcsetattr(0,termios.TCSANOW,mode)
                        with self.assertRaises(b.Block): entry.check()
                        termios.tcsetattr(0,termios.TCSANOW,original)
                entry.close()
                self.assertEqual(termios.tcgetattr(0),original)
                self.assertTrue(captured is None or not captured._password)
                with self.assertRaises(b.Block): entry.begin(path,human_supervised=True)
                return dict(result='SYNTHETIC_ENTRY_VERIFIED',traces=[],execution_allowed=False)
            finally: entry.close()
        with patch.object(f,'child_trial',side_effect=child):
            value=f.trial('NORMAL')
        self.assertEqual(value['result'],'SYNTHETIC_ENTRY_VERIFIED')

    def test_pty_success_non_echo_and_destruction(self): self._pty('NORMAL')
    def test_pty_repeat_consumes_attempt(self): self._pty('SECOND')
    def test_pty_expired_no_input(self): self._pty('EXPIRED')
    def test_pty_interrupt_restores_mode(self): self._pty('INTERRUPT')
    def test_pty_changed_terminal_blocks(self): self._pty('TTY_CHANGE')


if __name__=='__main__': unittest.main()
