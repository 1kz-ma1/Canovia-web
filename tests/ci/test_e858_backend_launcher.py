"""New dormant-launcher / portable-pipe delta; synthetic children, never DNS."""
import contextlib
import io
import os
import signal
import subprocess
import sys
import time
import unittest
from unittest.mock import patch

import test_e858_practical as fixture
from e858_phase1 import backend_candidate as b, launcher_candidate as l

Block=fixture.binding.Block
CANARY=fixture.CANARY


class ChildSupervisor(unittest.TestCase):
    def launch(self,code):
        process=subprocess.Popen([sys.executable,'-I','-B','-c',code],stdin=subprocess.PIPE,
            stdout=subprocess.PIPE,stderr=subprocess.PIPE,close_fds=True,env={})
        self.addCleanup(lambda:b.reap(process,time.monotonic()+.25))
        return process

    def failure(self,code,status,seconds=.6):
        process=self.launch(code);out,err=io.StringIO(),io.StringIO();start=time.monotonic()
        with contextlib.redirect_stdout(out),contextlib.redirect_stderr(err):
            with self.assertRaises(Block) as error:b.exchange(process,b'{}',start+seconds)
        self.assertEqual(error.exception.dns_status,status)
        self.assertTrue(error.exception.dns_reaped);self.assertIsNotNone(process.poll())
        self.assertEqual(str(error.exception),'PRIVATE_CANDIDATE_BLOCK')
        self.assertEqual(out.getvalue()+err.getvalue(),'')
        self.assertLess(time.monotonic()-start,seconds+.4)

    def test_success_and_eof_on_all_pipes(self):
        process=self.launch("import sys;v=sys.stdin.buffer.read();sys.stdout.buffer.write(v)")
        self.assertEqual(b.exchange(process,b'{"synthetic":true}',time.monotonic()+2),b'{"synthetic":true}')
        self.assertEqual(process.poll(),0)
        self.assertTrue(all(v.closed for v in (process.stdin,process.stdout,process.stderr)))

    def test_running_output_flood_stops_without_unbounded_buffer(self):
        self.failure("import sys\nwhile True:sys.stdout.buffer.write(b'x'*8192);sys.stdout.flush()",'DNS_RESPONSE_INVALID')

    def test_any_stderr_nonzero_empty_and_signal_are_distinct(self):
        self.failure("import sys;sys.stderr.write('"+CANARY+"')",'DNS_CHILD_ERROR')
        self.failure('raise SystemExit(2)','DNS_CHILD_ERROR')
        self.failure('pass','DNS_RESPONSE_INVALID')
        self.failure('import os,signal;os.kill(os.getpid(),signal.SIGTERM)','DNS_CHILD_SIGNAL')

    def test_idle_open_pipes_timeout_and_cleanup_within_same_budget(self):
        self.failure('import time;time.sleep(60)','DNS_TIMEOUT')

    def test_expired_invalid_deadline_and_oversize_request_are_terminal(self):
        for request,until in ((b'{}',time.monotonic()-1),(b'{}',float('nan')),(b'x'*257,time.monotonic()+2)):
            process=self.launch('import time;time.sleep(60)')
            with self.assertRaises(Block):b.exchange(process,request,until)
            process.wait(timeout=2)  # local cleanup only; not a successful receipt

    def test_interrupt_reaps_and_does_not_leak_exception(self):
        process=self.launch('import time;time.sleep(60)')
        with patch.object(b.selectors.DefaultSelector,'select',side_effect=KeyboardInterrupt(CANARY)):
            with self.assertRaises(Block) as error:b.exchange(process,b'{}',time.monotonic()+2)
        self.assertTrue(error.exception.dns_reaped);self.assertNotIn(CANARY,str(error.exception))

    def test_real_kill_permission_error_is_not_cleanup_success(self):
        process=self.launch('import time;time.sleep(60)');kill=process.kill
        try:
            with patch.object(process,'kill',side_effect=PermissionError(CANARY)):
                with self.assertRaises(Block) as error:b.exchange(process,b'{}',time.monotonic()+.4)
            self.assertEqual(error.exception.dns_status,'DNS_CHILD_CLEANUP_UNVERIFIED')
            self.assertFalse(error.exception.dns_reaped);self.assertIsNone(process.poll())
            self.assertNotIn(CANARY,str(error.exception))
        finally:kill();process.wait(timeout=2)

    def test_unknown_cleanup_overrides_even_successful_output(self):
        process=self.launch("import sys;sys.stdout.write('{}')")
        with patch.object(b,'reap',return_value=False):
            with self.assertRaises(Block) as error:b.exchange(process,b'{}',time.monotonic()+2)
        self.assertEqual(error.exception.dns_status,'DNS_CHILD_CLEANUP_UNVERIFIED')
        self.assertFalse(error.exception.dns_reaped)


class LauncherLifecycle(unittest.TestCase):
    setUpClass=classmethod(fixture.Practical.setUpClass.__func__)
    setUp=fixture.Practical.setUp
    write=fixture.Practical.write
    load=fixture.Practical.load

    def case(self,mode):
        p=fixture.p
        def child(_,parent):
            if os.getppid()!=parent:raise Block()
            review=self.load();entry=p.ReviewedEntry();launcher=None
            try:
                entry.begin(str(self.private),human_supervised=True);entry.bind_review(review)
                with patch('socket.socket') as dial,patch('socket.getaddrinfo') as dns,\
                        patch.dict(os.environ,{'LIVE_APPROVED':'true'}):
                    if mode in ('TOOLING','BUNDLE','PRIVATE'):
                        if mode=='PRIVATE':(self.private/'target.json').write_bytes(b'{}')
                        with self.assertRaises(Block):l.LauncherCandidate(entry,
                            'f'*40 if mode=='TOOLING' else self.head,'0'*64 if mode=='BUNDLE' else self.program.sha)
                    else:
                        launcher=l.LauncherCandidate(entry,self.head,self.program.sha)
                        if mode=='BEFORE_EXPIRED':entry.until=time.monotonic()-1
                        if mode=='BEFORE_EXPIRED':
                            with self.assertRaises(Block):launcher._before_input()
                        else:
                            launcher._before_input()
                            if mode=='REINPUT':
                                with self.assertRaises(Block):launcher._before_input()
                            elif mode=='GUARD':
                                with patch.dict(os.environ,{'B2_GO':'true','E858_LIVE':'1'}):
                                    with self.assertRaises(Block):launcher.run()
                            elif mode=='STOP':
                                launcher.close()
                                with self.assertRaises(Block):launcher._before_input()
                            else:
                                # Real R1 synthetic PTY capture; launcher never sees/copies secret.
                                entry.capture()
                                gate=lambda role:p.r.Gate(role,self.program.queries['identity'],self.program.queries['grants'],entry.until)
                                if mode=='AFTER_EXPIRED':
                                    entry.until=time.monotonic()-1
                                    with self.assertRaises(Block):launcher._connection(gate('control'))
                                elif mode=='ROLE':
                                    with self.assertRaises(Block):launcher._connection(gate('data'))
                                elif mode=='SQL':
                                    value=gate('control');value.q0=b'SELECT 1'
                                    with self.assertRaises(Block):launcher._connection(value)
                                else:
                                    launcher._connection(gate('control'));launcher._connection(gate('data'))
                                    self.assertEqual(launcher.roles,2)
                                    with self.assertRaises(Block):launcher._connection(gate('data'))
                    dns.assert_not_called();dial.assert_not_called()
                return dict(result='SYNTHETIC_LAUNCHER_DELTA_VERIFIED',traces=[],execution_allowed=False)
            finally:
                if launcher is not None:
                    launcher.close();self.assertIsNone(launcher.entry);self.assertEqual(launcher.state,'STOPPED')
                    self.assertNotIn(CANARY,repr(launcher))
                entry.close();review.close()
        with patch.object(fixture.tty,'child_trial',side_effect=child):
            self.assertEqual(fixture.tty.trial('NORMAL')['result'],'SYNTHETIC_LAUNCHER_DELTA_VERIFIED')

    def test_actual_r1_handoff_two_roles_and_no_third_connection(self):self.case('NORMAL')
    def test_before_after_input_deadline_not_reset(self):self.case('BEFORE_EXPIRED');self.case('AFTER_EXPIRED')
    def test_unknown_or_out_of_order_role_and_sql_rejected(self):self.case('ROLE');self.case('SQL')
    def test_private_tooling_and_artifact_mismatch_before_input(self):
        for mode in ('TOOLING','BUNDLE','PRIVATE'):self.case(mode)
    def test_input_stop_and_retry_are_terminal(self):self.case('REINPUT');self.case('STOP')
    def test_default_activation_block_no_factory_or_env_bypass(self):self.case('GUARD')


if __name__=='__main__':unittest.main()
