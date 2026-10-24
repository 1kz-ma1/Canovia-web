"""Entry-to-driver binding delta. Synthetic records/R1 PTY, no DNS/IP I/O."""
import contextlib
import io
import os
import json
import subprocess
import sys
import time
import unittest
from unittest.mock import patch

import test_e858_practical as fixture
from e858_phase1.phase1_driver_candidate import Phase1DriverCandidate
from e858_phase1 import b1_package
from e858_phase1 import backend_candidate as backend
from e858_phase1.b_procedural_attempt import BProceduralAttempt,LauncherCandidate,Root
from e858_phase1.producer_candidate import ProducerBinding
from e858_phase1.dispatcher_binding_candidate import DispatcherBindingCandidate
from e858_phase1.runtime_placement_candidate import RuntimePlacementCandidate
from e858_phase1.runtime_file_lock_candidate import RuntimeFileLockCandidate
import e858_b_offline as offline

p=fixture.p
Block=fixture.binding.Block


class DriverBinding(unittest.TestCase):
    setUpClass=classmethod(fixture.Practical.setUpClass.__func__)
    setUp=fixture.Practical.setUp
    write=fixture.Practical.write
    load=fixture.Practical.load

    def trial(self,mode):
        self.write()
        dispatch_mode=mode.startswith('DISPATCH_')
        producer_mode=mode.startswith('PRODUCER_') or dispatch_mode
        store=self.base/('store-'+mode)
        if producer_mode:store.mkdir(mode=0o700)
        def child(_,parent):
            if os.getppid()!=parent:raise Block()
            entry=p.ReviewedEntry();review=None;wire=None;secret=None;procedure=None;worker=None;process=None;dispatch=None;placement=None;runtime_lock=None
            out,err=io.StringIO(),io.StringIO()
            try:
                with contextlib.redirect_stdout(out),contextlib.redirect_stderr(err),patch('socket.getaddrinfo') as dns,patch('socket.socket') as sock:
                    review=self.load();entry.begin(str(self.private),human_supervised=True);entry.bind_review(review)
                    if producer_mode:
                        launcher=LauncherCandidate(entry,self.head,self.program.sha)
                        procedure=BProceduralAttempt(launcher,Root(str(store)))
                        engine=p.Adapter(entry,'phase1');engine.boundary=p.PrivateBoundary(entry)
                        procedure.bind_engine(engine)
                        if dispatch_mode:
                            anchor=self.base/'runtime-anchor';anchor.mkdir(mode=0o700)
                            fd=os.open(anchor,os.O_RDONLY|os.O_DIRECTORY|os.O_CLOEXEC)
                            try:placement=RuntimePlacementCandidate(fd,self.archive,self.program.sha,self.pins,time.monotonic()+120)
                            finally:os.close(fd)
                            runtime_lock=RuntimeFileLockCandidate(self.program,str(offline.ROOT),self.archive,self.program.sha,self.head)
                            dispatch=DispatcherBindingCandidate(runtime_lock,placement,procedure)
                            self.assertFalse(dispatch.check()['execution_allowed'])
                        code="import sys;sys.stdout.buffer.write(b'E858_DNS_READY\\n');sys.stdout.flush();sys.stdin.buffer.read();sys.stdout.write('[[2,1,6,\"\",[\"198.51.100.7\",9876]]]')"
                        process=subprocess.Popen([sys.executable,'-I','-B','-c',code],stdin=subprocess.PIPE,stdout=subprocess.PIPE,stderr=subprocess.PIPE,close_fds=True,env={})
                        worker=backend.PreparedChild(process,time.monotonic()+2)
                        procedure.confirm_on_tty();procedure.reserve_attempt();procedure.before_input()
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
                        if producer_mode:
                            engine.boundary.wires.append(wire);procedure.before_connection(gate)
                            if mode=='PRODUCER_LATE':worker.ready_at=time.monotonic()
                            if mode=='PRODUCER_LATE':
                                with self.assertRaises(Block):ProducerBinding(wire,procedure,worker)
                            else:
                                producer=ProducerBinding(wire,procedure,worker)
                                if dispatch_mode:
                                    if mode=='DISPATCH_RUNTIME_EXPIRED':placement.until=time.monotonic()-1
                                    elif mode=='DISPATCH_PROGRAM_CHANGED':dispatch.runtime='different'
                                    elif mode=='DISPATCH_GATE_CHANGED':wire.gate=p.r.Gate('control',gate.q0,gate.q1,gate.deadline)
                                    if mode!='DISPATCH_NORMAL':
                                        with self.assertRaises(Block):dispatch.bind_producer(producer)
                                        self.assertEqual(dispatch.state,'STOPPED')
                                    else:
                                        report=dispatch.bind_producer(producer)
                                        self.assertEqual(report['roles_bound'],1)
                                        self.assertFalse(report['execution_allowed'])
                                        self.assertIs(dispatch.program,self.program)
                                        # Neither runtime files nor a reserved B trial can authorize DNS.
                                        for name in ('run','connect','resolve','activate','capture','grant_go'):
                                            self.assertFalse(hasattr(dispatch,name))
                                        with self.assertRaises(Block):dispatch.bind_producer(producer)
                                    producer.close();wire.close()
                                    return dict(result='PHASE1_DRIVER_BINDING_VERIFIED',traces=[],execution_allowed=False)
                                if mode=='PRODUCER_DUPLICATE':
                                    with self.assertRaises(Block):ProducerBinding(wire,procedure,worker)
                                elif mode=='PRODUCER_SCOPE':
                                    engine.boundary.entry=None
                                    with self.assertRaises(Block):producer.dns_request()
                                else:
                                    request=producer.dns_request()
                                    self.assertEqual(json.loads(request),dict(host=entry.target.host,port=entry.target.port))
                                    if mode=='PRODUCER_RETRY':
                                        with self.assertRaises(Block):producer.dns_request()
                                    else:
                                        answer=json.loads(worker.exchange(request,wire.plan.until))
                                        peer=producer.resolved(answer)
                                        self.assertEqual(peer.address,('198.51.100.7',9876))
                                        with self.assertRaises(Block):producer.resolved(answer)
                                producer.close();self.assertEqual(producer.state,'STOPPED')
                            wire.close()
                        elif mode=='NORMAL':
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
                if dispatch is not None:dispatch.close()
                if runtime_lock is not None:runtime_lock.close()
                if placement is not None:placement.close()
                if wire is not None:wire.close()
                if procedure is not None:procedure.close()
                if process is not None:self.assertTrue(backend.reap(process,time.monotonic()+.25))
                entry.close()
                if review is not None:review.close()
                if secret is not None:self.assertEqual(secret,bytearray())
        with patch.object(fixture.tty,'child_trial',side_effect=child):
            self.assertEqual(fixture.tty.trial('NORMAL',procedural_review='MATCH' if producer_mode else None)['result'],'PHASE1_DRIVER_BINDING_VERIFIED')

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

    def test_runtime_trial_producer_is_one_inert_context_and_cannot_repeat(self):self.trial('DISPATCH_NORMAL')
    def test_runtime_expiry_or_program_drift_blocks_before_dns(self):
        self.trial('DISPATCH_RUNTIME_EXPIRED');self.trial('DISPATCH_PROGRAM_CHANGED')
    def test_runtime_binding_rejects_replaced_gate_without_authority(self):self.trial('DISPATCH_GATE_CHANGED')

    def test_producer_binds_preinput_worker_and_same_entry_role_to_dns_result(self):self.trial('PRODUCER_NORMAL')
    def test_producer_rejects_worker_after_r1_and_cross_scope(self):
        self.trial('PRODUCER_LATE');self.trial('PRODUCER_SCOPE')
    def test_producer_worker_binding_and_dns_request_cannot_be_reused(self):
        self.trial('PRODUCER_DUPLICATE');self.trial('PRODUCER_RETRY')


if __name__=='__main__':unittest.main()
