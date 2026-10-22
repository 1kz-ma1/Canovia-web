"""Application composition only; fresh synthetic roots/PTY, zero networking."""
import contextlib
import io
import os
import unittest
from unittest.mock import patch

import test_e858_practical as fixture
from e858_phase1.phase1_application import Phase1ApplicationReview

p=fixture.p
Block=fixture.binding.Block
CANARY=fixture.CANARY


class ApplicationReview(unittest.TestCase):
    @classmethod
    def setUpClass(cls):fixture.Practical.setUpClass.__func__(cls)
    setUp=fixture.Practical.setUp
    write=fixture.Practical.write
    load=fixture.Practical.load

    def _trial(self,fault='NORMAL'):
        self.write()  # reset synthetic files between deliberately destructive trials
        def child(_,parent):
            if os.getppid()!=parent:raise Block()
            review=self.load();entry=p.ReviewedEntry();application=None
            out,err=io.StringIO(),io.StringIO()
            try:
                entry.begin(str(self.private),human_supervised=True);entry.bind_review(review)
                terminal_fd=entry.terminal.fd
                original=p.Adapter.prepare_reviewed
                def prepare(adapter):
                    if fault=='INTERRUPT':raise KeyboardInterrupt(CANARY)
                    config,bundle=original(adapter)
                    if fault in ('Q0','Q1'):config[fault.lower()]=b'SELECT * FROM users;'
                    if fault=='EXPORT':bundle={'unapproved_export':b'SELECT 1 INTO OUTFILE "x";'}
                    return config,bundle
                close=entry.close
                def failed_close():
                    close();raise PermissionError(CANARY)
                with contextlib.ExitStack() as stack:
                    stack.enter_context(contextlib.redirect_stdout(out));stack.enter_context(contextlib.redirect_stderr(err))
                    stack.enter_context(patch('socket.getaddrinfo',side_effect=AssertionError('NO_DNS')))
                    stack.enter_context(patch('socket.socket',side_effect=AssertionError('NO_SOCKET')))
                    capture=stack.enter_context(patch.object(p.Entry,'capture',side_effect=AssertionError('NO_INPUT')))
                    driver=stack.enter_context(patch.object(p.PrivateBoundary,'factory',side_effect=AssertionError('NO_DRIVER')))
                    identity=stack.enter_context(patch.object(p.Candidate,'observe_identity',side_effect=AssertionError('NO_LIVE_IDENTITY')))
                    setup=stack.enter_context(patch.object(p.Adapter,'prepare_reviewed',side_effect=prepare,autospec=True))
                    if fault=='TARGET':(self.private/'target.json').write_bytes(b'{}')
                    if fault=='CA':(self.private/'ca.pem').write_bytes(b'SYNTHETIC_INVALID_CA')
                    if fault=='ACL':stack.enter_context(patch.object(p,'native_acl',side_effect=PermissionError(CANARY)))
                    if fault=='TTY':stack.enter_context(patch.object(entry.terminal,'check',side_effect=PermissionError(CANARY)))
                    if fault=='RUNTIME':stack.enter_context(patch.object(p,'stdlib_identity',return_value={'changed':'SYNTHETIC'}))
                    if fault=='EXPIRED':entry.until=0
                    if fault=='CLEANUP':stack.enter_context(patch.object(entry,'close',side_effect=failed_close))
                    application=Phase1ApplicationReview(entry,'f'*40 if fault=='TOOLING' else self.head,
                        '0'*64 if fault=='BUNDLE' else self.program.sha)
                    if fault=='NORMAL':
                        result=application.review()
                        self.assertEqual(result['result'],'PHASE1_APPLICATION_REVIEWED_OFFLINE')
                        for key in ('execution_allowed','credentials_read','owner_approval_verified','live_tooling_adopted',
                                    'b2_owner_go','real_aiven_connected','production_go'):self.assertIs(result[key],False)
                        self.assertEqual(result['sql_sent'],0);self.assertEqual(result['tls_connections'],0)
                        self.assertEqual(result['effective_principal'],'REAL_SESSION_UNVERIFIED')
                        self.assertEqual(result['remote_termination'],'REMOTE_TERMINATION_UNVERIFIED')
                        self.assertNotIn(CANARY,str(result)+repr(application))
                    else:
                        with self.assertRaises(Block) as caught:application.review()
                        self.assertEqual(str(caught.exception),'PRIVATE_CANDIDATE_BLOCK')
                    if fault in ('TOOLING','BUNDLE','TARGET','CA','ACL','TTY','RUNTIME','EXPIRED','METADATA'):setup.assert_not_called()
                    capture.assert_not_called();driver.assert_not_called();identity.assert_not_called()
                self.assertEqual(out.getvalue()+err.getvalue(),'')
                self.assertIsNone(application.entry);self.assertTrue(entry.closed);self.assertTrue(review.closed)
                self.assertIsNone(entry.target);self.assertIsNone(entry.secret)
                with self.assertRaises(OSError):os.fstat(terminal_fd)
                with self.assertRaises(Block):application.review()
                # A fresh wrapper cannot revive this consumed entry. This does
                # not claim detection of a new process/private-file rollback.
                with self.assertRaises(Block):Phase1ApplicationReview(entry,self.head,self.program.sha).review()
                return dict(result='SYNTHETIC_APPLICATION_REVIEW_VERIFIED',traces=[],execution_allowed=False)
            finally:
                if application is not None:application.close()
                entry.close();review.close()
        with patch.object(fixture.tty,'child_trial',side_effect=child):
            self.assertEqual(fixture.tty.trial('NORMAL')['result'],'SYNTHETIC_APPLICATION_REVIEW_VERIFIED')

    def test_actual_composition_without_input_driver_or_network(self):self._trial()

    def test_false_approval_objects_are_not_an_entry(self):
        out,err=io.StringIO(),io.StringIO()
        with contextlib.redirect_stdout(out),contextlib.redirect_stderr(err),\
                patch('socket.getaddrinfo',side_effect=AssertionError('NO_DNS')):
            for fake in ({'approved':True,'password':CANARY},object(),None):
                with self.assertRaises(Block) as caught:Phase1ApplicationReview(fake,self.head,self.program.sha)
                self.assertNotIn(CANARY,str(caught.exception))
        self.assertEqual(out.getvalue()+err.getvalue(),'')

    def test_independent_public_pin_mismatch_precedes_setup(self):
        for fault in ('TOOLING','BUNDLE'):
            with self.subTest(fault=fault):self._trial(fault)

    def test_metadata_review_is_not_phase1(self):
        self.review['scope']='metadata';self.write();self._trial('METADATA')

    def test_changed_private_runtime_or_tty_stops_before_setup(self):
        for fault in ('TARGET','CA','ACL','TTY','RUNTIME','EXPIRED'):
            with self.subTest(fault=fault):self._trial(fault)

    def test_interrupt_and_cleanup_error_are_private_terminal(self):
        for fault in ('INTERRUPT','CLEANUP'):
            with self.subTest(fault=fault):self._trial(fault)

    def test_changed_sql_or_export_is_never_execution(self):
        for fault in ('Q0','Q1','EXPORT'):
            with self.subTest(fault=fault):self._trial(fault)

    def test_fresh_process_can_only_review_pending_records_not_issue_go(self):
        # Each trial creates a fresh child/session. Procedural B one-shot is
        # deliberately NOT a cryptographic, durable restart/replay guarantee.
        self._trial();self._trial()
        for method in ('run','connect','activate','capture','factory'):
            self.assertFalse(hasattr(Phase1ApplicationReview,method))


if __name__=='__main__':unittest.main()
