"""Entry-to-driver binding delta. Synthetic records/R1 PTY, no DNS/IP I/O."""
import contextlib
import io
import os
import time
import unittest
from unittest.mock import patch

import test_e858_practical as fixture
from e858_phase1.phase1_driver_candidate import Phase1DriverCandidate
from e858_phase1 import b1_package

p=fixture.p
Block=fixture.binding.Block


class DriverBinding(unittest.TestCase):
    setUpClass=classmethod(fixture.Practical.setUpClass.__func__)
    setUp=fixture.Practical.setUp
    write=fixture.Practical.write
    load=fixture.Practical.load

    def trial(self,mode):
        self.write()
        def child(_,parent):
            if os.getppid()!=parent:raise Block()
            entry=p.ReviewedEntry();review=None;wire=None;secret=None
            out,err=io.StringIO(),io.StringIO()
            try:
                with contextlib.redirect_stdout(out),contextlib.redirect_stderr(err),patch('socket.getaddrinfo') as dns,patch('socket.socket') as sock:
                    review=self.load();entry.begin(str(self.private),human_supervised=True);entry.bind_review(review)
                    entry.capture();secret=entry.secret._password
                    gate=p.r.Gate('control',self.program.queries['identity'],self.program.queries['grants'],entry.until)
                    wire=object.__new__(Phase1DriverCandidate);wire.initialize(gate,secret,entry.target)
                    if mode=='COPIED_SECRET':wire.password=bytearray(secret)
                    if mode=='COPIED_SECRET':
                        with self.assertRaises(Block):wire.bind_entry(entry)
                    else:
                        wire.bind_entry(entry)
                        self.assertIs(wire.plan.entry,entry);self.assertIs(wire.gate,gate)
                        self.assertIs(wire.password,secret)
                        if mode=='NORMAL':
                            self.assertIs(Phase1DriverCandidate.connect,p.PrivateWire.connect)
                            with self.assertRaises(Block):wire.connect()
                            wire.check_binding()  # no SQL or driver initialization on BLOCK
                        elif mode in ('USER_BYTES','DB_BYTES'):
                            if mode=='USER_BYTES':wire.user=entry.target.principal.encode('ascii')
                            else:wire.db=entry.target.database.encode('ascii')
                            wire.check_binding()
                        elif mode=='REBIND':
                            with self.assertRaises(Block):wire.bind_entry(entry)
                        else:
                            if mode=='HOST':wire.host='unapproved.synthetic.invalid'
                            elif mode=='PORT':wire.port+=1
                            elif mode=='USER':wire.user='unknown'
                            elif mode=='DB':wire.db='other'
                            elif mode=='GATE':wire.gate=p.r.Gate('control',gate.q0,gate.q1,gate.deadline)
                            elif mode=='ROLE':gate.role='data'
                            elif mode=='SQL':gate.q0=b'SELECT 1;'
                            elif mode=='DEADLINE':gate.deadline=time.monotonic()-1
                            elif mode=='SCOPE':review.value['scope']='metadata'
                            elif mode=='PRIVATE':(self.private/'target.json').write_bytes(b'{}')
                            elif mode=='RECONNECT':wire.connect_count=2
                            with self.assertRaises(Block):wire.check_binding()
                    if mode not in ('NORMAL','USER_BYTES','DB_BYTES'):
                        self.assertEqual(wire.password,b'');self.assertIsNone(wire.entry)
                        with self.assertRaises(Block):wire.bind_entry(entry)
                    self.assertNotIn(fixture.CANARY,repr(wire))
                    dns.assert_not_called();sock.assert_not_called()
                self.assertEqual(out.getvalue()+err.getvalue(),'')
                return dict(result='PHASE1_DRIVER_BINDING_VERIFIED',traces=[],execution_allowed=False)
            finally:
                if wire is not None:wire.close()
                entry.close()
                if review is not None:review.close()
                if secret is not None:self.assertEqual(secret,bytearray())
        with patch.object(fixture.tty,'child_trial',side_effect=child):
            self.assertEqual(fixture.tty.trial('NORMAL')['result'],'PHASE1_DRIVER_BINDING_VERIFIED')

    def test_existing_r1_alias_entry_and_inherited_connection_block(self):self.trial('NORMAL')
    def test_copy_and_rebind_rejected_without_recapture(self):
        self.trial('COPIED_SECRET');self.trial('REBIND')
    def test_driver_target_fields_cannot_override_review(self):
        for mode in ('HOST','PORT','USER','DB'):self.trial(mode)
    def test_gate_identity_role_sql_and_deadline_are_bound(self):
        for mode in ('GATE','ROLE','SQL','DEADLINE','RECONNECT'):self.trial(mode)
    def test_private_or_scope_drift_stops_and_pymysql_auth_bytes_are_supported(self):
        self.trial('PRIVATE');self.trial('SCOPE');self.trial('USER_BYTES');self.trial('DB_BYTES')
    def test_closed_review_archive_contains_inert_driver_not_dialer(self):
        for name in ('phase1_driver_candidate.py','connection_handoff_candidate.py'):
            self.assertIn('scripts/ci/e858_phase1/'+name,b1_package.FILES)
        for name in ('opener_fixture.py','owned_activation_fixture.py','tls_handoff_candidate.py'):
            self.assertNotIn('scripts/ci/e858_phase1/'+name,b1_package.FILES)


if __name__=='__main__':unittest.main()
