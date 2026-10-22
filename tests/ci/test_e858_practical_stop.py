"""Post-start integrity and terminal STOP delta; synthetic files/PTYs only."""
import contextlib
import io
import os
from pathlib import Path
import sys
import unittest
from unittest.mock import patch

import test_e858_practical as fixture

p=fixture.p
Block=fixture.binding.Block
CANARY=fixture.CANARY


class StopBoundary(unittest.TestCase):
    @classmethod
    def setUpClass(cls):fixture.Practical.setUpClass.__func__(cls)
    setUp=fixture.Practical.setUp
    write=fixture.Practical.write
    load=fixture.Practical.load

    def test_extra_installed_driver_file_after_start_blocks(self):
        # Exercise the real installed tree, not a mocked version/result. Always
        # restore it before another test/interpreter can use that installation.
        extra=Path(p.pymysql.__file__).parent/'__b1_unreviewed_synthetic__.py'
        self.assertFalse(extra.exists())
        try:
            extra.write_bytes(b'# synthetic unreviewed dependency\n')
            with self.assertRaises(Block):self.program.check()
        finally:extra.unlink()
        self.program.check()

    def test_loaded_driver_origin_after_start_blocks(self):
        with patch.object(p.connections,'__file__',str(self.private/'unreviewed.py')):
            with self.assertRaises(Block):self.program.check()

    def test_loaded_public_module_origin_after_start_blocks(self):
        with patch.object(p.r,'__file__',str(self.private/'unreviewed.py')):
            with self.assertRaises(Block):self.program.check()

    def test_loaded_public_spec_origin_after_start_blocks(self):
        with patch.object(p.r.__spec__,'origin',str(self.private/'unreviewed.py')):
            with self.assertRaises(Block):self.program.check()
        # Full94 legitimately loads byte-identical helpers from its separate
        # frozen-assets mount. Origin equality alone must not admit changed code.
        name='p0_mysql_metadata_grants_guard'
        module=sys.modules[name];copy=self.private/'copied_helper.py'
        copy.write_bytes(self.program.files['scripts/ci/'+name+'.py'])
        with patch.object(module,'__file__',str(copy)),patch.object(module.__spec__,'origin',str(copy)):
            self.program.check()
            copy.write_bytes(b'# synthetic unreviewed replacement\n')
            with self.assertRaises(Block):self.program.check()

    def test_review_acl_failure_cannot_resume_after_repair(self):
        review=self.load()
        self.addCleanup(review.close)
        with patch.object(p,'native_acl',side_effect=PermissionError(CANARY)):
            with self.assertRaises(Block):review.check()
        with self.assertRaises(Block):review.check()
        self.assertTrue(review.closed);self.assertIsNone(review.root)
        review.close()  # terminal cleanup is idempotent

    def test_review_expiry_cannot_resume_after_clock_restoration(self):
        review=self.load()
        self.addCleanup(review.close)
        with patch.object(p.time,'time',return_value=self.review['expires_at']):
            with self.assertRaises(Block):review.check()
        with self.assertRaises(Block):review.check()
        self.assertTrue(review.closed)

    def test_review_cleanup_error_is_fixed_and_terminal(self):
        review=self.load();close=review.root.close
        self.addCleanup(review.close)
        def fail_close():
            close()
            raise OSError(CANARY)
        out,err=io.StringIO(),io.StringIO()
        with contextlib.redirect_stdout(out),contextlib.redirect_stderr(err),\
                patch.object(p,'native_acl',side_effect=PermissionError(CANARY)),\
                patch.object(review.root,'close',side_effect=fail_close):
            with self.assertRaises(Block) as caught:review.check()
        self.assertEqual(str(caught.exception),'PRIVATE_CANDIDATE_BLOCK')
        self.assertEqual(out.getvalue()+err.getvalue(),'')
        self.assertTrue(review.closed);self.assertIsNone(review.root)
        with self.assertRaises(Block):review.check()

    def _secret_stop(self,fault):
        def child(_,parent):
            if os.getppid()!=parent:raise Block()
            review=self.load();entry=p.ReviewedEntry()
            try:
                entry.begin(str(self.private),human_supervised=True)
                entry.bind_review(review)
                captured=entry.capture();secret=captured._password
                self.assertTrue(secret)
                terminal_fd=entry.terminal.fd
                out,err=io.StringIO(),io.StringIO()
                with contextlib.redirect_stdout(out),contextlib.redirect_stderr(err):
                    if fault=='TARGET':
                        raw=(self.private/'target.json').read_bytes()
                        (self.private/'target.json').write_bytes(b'{}')
                        with self.assertRaises(Block):entry.check()
                        (self.private/'target.json').write_bytes(raw)
                    else:
                        with patch.object(entry.terminal,'check',side_effect=KeyboardInterrupt(CANARY)):
                            with self.assertRaises(Block):entry.check()
                self.assertEqual(out.getvalue()+err.getvalue(),'')
                self.assertFalse(secret);self.assertTrue(entry.closed)
                self.assertIsNone(entry.secret);self.assertIsNone(entry.review)
                self.assertTrue(review.closed)
                with self.assertRaises(OSError):os.fstat(terminal_fd)
                for action in (entry.check,entry.capture):
                    with self.assertRaises(Block):action()
                with self.assertRaises(Block):entry.begin(str(self.private),human_supervised=True)
                return dict(result='SYNTHETIC_TERMINAL_STOP_VERIFIED',traces=[],execution_allowed=False)
            finally:entry.close();review.close()
        with patch.object(fixture.tty,'child_trial',side_effect=child):
            self.assertEqual(fixture.tty.trial('NORMAL')['result'],'SYNTHETIC_TERMINAL_STOP_VERIFIED')

    def test_target_check_failure_erases_secret_and_cannot_resume(self):self._secret_stop('TARGET')
    def test_terminal_interrupt_erases_secret_and_cannot_resume(self):self._secret_stop('TTY')


if __name__=='__main__':unittest.main()
