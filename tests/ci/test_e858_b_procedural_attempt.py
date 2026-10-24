"""B local procedure on real synthetic controlling PTYs; no owner or live GO."""
import contextlib
import io
import os
from pathlib import Path
import time
import types
import unittest
from unittest.mock import patch

import test_e858_practical as fixture
from e858_phase1 import b_procedural_attempt as b
from e858_phase1 import b1_attempt_reservation as reservation
from e858_phase1 import b1_package

p=fixture.p
Block=fixture.binding.Block


class Procedure(unittest.TestCase):
    setUpClass=classmethod(fixture.Practical.setUpClass.__func__)
    write=fixture.Practical.write
    load=fixture.Practical.load

    def setUp(self):
        fixture.Practical.setUp(self)
        self.stores=self.base/'attempt-stores';self.stores.mkdir(mode=0o700);self.sequence=0

    def trial(self, mode='NORMAL', response='MATCH'):
        self.write();self.sequence+=1
        self.store=self.stores/str(self.sequence);self.store.mkdir(mode=0o700)
        if mode=='DUPLICATE':
            with b.Root(str(self.store)) as root:
                reservation.reserve(root.check(),self.review['attempt_id'],self.head)
        def child(_,parent):
            if os.getppid()!=parent:raise Block()
            entry=p.ReviewedEntry();review=None;procedure=None;root=None;secret=None
            out,err=io.StringIO(),io.StringIO()
            try:
                with contextlib.redirect_stdout(out),contextlib.redirect_stderr(err), \
                        patch('socket.socket') as sock,patch('socket.getaddrinfo') as dns:
                    review=self.load();entry.begin(str(self.private),human_supervised=True);entry.bind_review(review)
                    initial=entry.until
                    launcher=b.LauncherCandidate(entry,self.head,self.program.sha)
                    root=b.Root(str(self.private if mode=='ALIASED_ROOT' else self.store))
                    if mode=='ALIASED_ROOT':
                        with self.assertRaises(Block):b.BProceduralAttempt(launcher,root)
                    else:
                        procedure=b.BProceduralAttempt(launcher,root)
                        self.assertLessEqual(procedure.until,initial)
                        self.assertEqual(procedure.state,'PREFLIGHTED')
                        if mode in ('BEFORE_CONFIRM','FAKE_GO','CANCEL','TARGET','CA','RUNTIME'):
                            if mode=='BEFORE_CONFIRM':
                                with self.assertRaises(Block):procedure.reserve_attempt()
                            elif mode=='FAKE_GO':
                                with self.assertRaises(Block):procedure.confirm_on_tty(approved=True)
                            elif mode=='CANCEL':
                                procedure.cancel()
                            else:
                                if mode=='TARGET':(self.private/'target.json').write_bytes(b'{}')
                                if mode=='CA':(self.private/'ca.pem').write_bytes(b'not-a-ca')
                                if mode=='RUNTIME':
                                    with patch.object(self.program,'runtime','0'*64):
                                        with self.assertRaises(Block):procedure.check()
                                else:
                                    with self.assertRaises(Block):procedure.check()
                        elif mode=='WRONG_CONFIRM':
                            with self.assertRaises(Block):procedure.confirm_on_tty()
                            self.assertEqual(list(self.store.iterdir()),[])
                        else:
                            procedure.confirm_on_tty()
                            self.assertEqual(procedure.state,'CONFIRMED_NOT_AUTHORITY')
                            if mode in ('CONFIRM_TWICE','EXPIRED','CLOCK_ROLLBACK'):
                                if mode=='CONFIRM_TWICE':
                                    with self.assertRaises(Block):procedure.confirm_on_tty()
                                elif mode=='EXPIRED':
                                    with patch.object(b.time,'time',return_value=self.review['expires_at']):
                                        with self.assertRaises(Block):procedure.reserve_attempt()
                                else:
                                    with patch.object(b.time,'time',return_value=procedure.last_wall-.01):
                                        with self.assertRaises(Block):procedure.check()
                            elif mode in ('DUPLICATE','FSYNC_FAILURE'):
                                if mode=='FSYNC_FAILURE':
                                    with patch.object(reservation.os,'fsync',side_effect=OSError(fixture.CANARY)):
                                        with self.assertRaises(Block):procedure.reserve_attempt()
                                else:
                                    with self.assertRaises(Block):procedure.reserve_attempt()
                                self.assertEqual(len(list(self.store.iterdir())),1)
                            else:
                                procedure.reserve_attempt()
                                self.assertEqual(procedure.state,'RESERVED_NOT_AUTHORITY')
                                self.assertEqual(len(list(self.store.iterdir())),1)
                                if mode=='AFTER_RESERVED_CANCEL':procedure.cancel()
                                else:
                                    procedure.before_input();entry.capture();secret=entry.secret._password
                                    gate=lambda role:p.r.Gate(role,self.program.queries['identity'],self.program.queries['grants'],entry.until)
                                    if mode=='WRONG_ORDER':
                                        with self.assertRaises(Block):procedure.before_connection(gate('data'))
                                    elif mode=='FAKE_GATE':
                                        with self.assertRaises(Block):procedure.before_connection(types.SimpleNamespace(role='control'))
                                    elif mode=='SQL':
                                        q=gate('control');q.q0=b'SELECT 1;'
                                        with self.assertRaises(Block):procedure.before_connection(q)
                                    else:
                                        procedure.before_connection(gate('control'));procedure.before_connection(gate('data'))
                                        self.assertEqual(procedure.state,'DATA_STARTED')
                                        if mode=='THIRD':
                                            with self.assertRaises(Block):procedure.before_connection(gate('data'))
                                        elif mode=='UNKNOWN_REMOTE':
                                            with self.assertRaises(Block):procedure.finish()
                                        else:procedure.close()
                        self.assertEqual(procedure.state,'STOPPED')
                        self.assertIsNone(procedure.entry)
                        self.assertIsNone(launcher.entry)
                        with self.assertRaises(Block):procedure.before_input()
                        report=procedure.report()
                        for key in ('owner_identity_verified','fresh_b2_go_granted','execution_allowed',
                                    'real_aiven_connected','durable_rollback_resistance','production_go'):
                            self.assertIs(report[key],False)
                        self.assertEqual(report['remote_termination'],'REMOTE_TERMINATION_UNVERIFIED')
                        self.assertNotIn(fixture.CANARY,str(report)+repr(procedure))
                    if secret is not None:self.assertEqual(secret,bytearray())
                    self.assertTrue(entry.closed)
                    sock.assert_not_called();dns.assert_not_called()
                self.assertEqual(out.getvalue()+err.getvalue(),'')
                return dict(result='B_PROCEDURE_SYNTHETIC_VERIFIED',traces=[],execution_allowed=False,
                            real_aiven_connected=False,production_go=False)
            finally:
                if procedure is not None:procedure.close()
                if root is not None:root.close()
                entry.close()
                if review is not None:review.close()
        with patch.object(fixture.tty,'child_trial',side_effect=child):
            result=fixture.tty.trial('NORMAL',procedural_review=response)
        self.assertEqual(result['result'],'B_PROCEDURE_SYNTHETIC_VERIFIED')
        self.assertFalse(result['execution_allowed'])
        return result

    def test_actual_private_tty_confirmation_reservation_r1_two_ordered_roles_and_cleanup(self):
        self.assertTrue(self.trial()['procedural_review_response_sent'])

    def test_wrong_stale_or_cancelled_tty_response_is_terminal_without_reservation(self):
        for response in ('WRONG','STALE','CANCEL'):
            with self.subTest(response=response):self.trial('WRONG_CONFIRM',response)

    def test_unconfirmed_fake_go_and_cancellation_cannot_reach_input(self):
        for mode in ('BEFORE_CONFIRM','FAKE_GO','CANCEL'):
            with self.subTest(mode=mode):self.trial(mode)

    def test_target_ca_runtime_and_store_alias_are_rejected(self):
        for mode in ('TARGET','CA','RUNTIME','ALIASED_ROOT'):
            with self.subTest(mode=mode):self.trial(mode)

    def test_confirmation_reuse_expiry_and_backward_clock_stop(self):
        for mode in ('CONFIRM_TWICE','EXPIRED','CLOCK_ROLLBACK'):
            with self.subTest(mode=mode):self.trial(mode)

    def test_consumed_attempt_duplicate_fsync_failure_and_cancellation_do_not_retry(self):
        for mode in ('DUPLICATE','FSYNC_FAILURE','AFTER_RESERVED_CANCEL'):
            with self.subTest(mode=mode):self.trial(mode)

    def test_wrong_order_sql_fake_third_role_and_unknown_remote_stop_after_reservation(self):
        for mode in ('WRONG_ORDER','FAKE_GATE','SQL','THIRD','UNKNOWN_REMOTE'):
            with self.subTest(mode=mode):self.trial(mode)

    def test_review_archive_contains_contract_without_live_activation(self):
        self.assertIn('scripts/ci/e858_phase1/b_procedural_attempt.py',b1_package.FILES)
        self.assertIn('scripts/ci/e858_phase1/b1_attempt_reservation.py',b1_package.FILES)
        self.assertFalse(hasattr(b.BProceduralAttempt,'run'))
        with patch('socket.socket') as sock,patch('socket.getaddrinfo') as dns,patch.dict(os.environ,{'B2_GO':'true'}):
            for cls,method in ((p.Adapter,'prepare'),(p.PrivateWire,'connect'),
                               (b.LauncherCandidate,'run')):
                with self.assertRaises(Block):getattr(object.__new__(cls),method)()
            from e858_phase1.opener_candidate import OpenerCandidate
            with self.assertRaises(Block):object.__new__(OpenerCandidate).open()
            with self.assertRaises(Block):b.BProceduralAttempt({'approved':True},None)
            sock.assert_not_called();dns.assert_not_called()


if __name__=='__main__':unittest.main()
