"""Pure policy + fixed non-DNS child faults. No external network or secrets."""
import contextlib
import dataclasses
import io
import os
import socket
import ssl
import subprocess
import time
import unittest
from unittest.mock import Mock,patch

import test_e858_practical as fixture
from e858_phase1 import opener_candidate as c, opener_fixture as owned

p=fixture.p
Block=fixture.binding.Block
CANARY=fixture.CANARY


def answer(ip='198.51.100.7',port=9876,family=socket.AF_INET):
    return (family,socket.SOCK_STREAM,socket.IPPROTO_TCP,'',
            (ip,port) if family==socket.AF_INET else (ip,port,0,0))


class OpenerPolicy(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        fixture.Practical.setUpClass.__func__(cls)
        fixture.binding_fixture.PrivateCandidate.setUpClass()
        cls.other_ca=fixture.binding_fixture.PrivateCandidate.ca
    setUp=fixture.Practical.setUp
    write=fixture.Practical.write
    load=fixture.Practical.load

    def test_generic_dns_ipv4_ipv6_order_without_static_fixture_ip(self):
        first=answer();second=answer('2001:db8::7',family=socket.AF_INET6)
        self.assertEqual(c.select_peer([first,second],9876).address,first[4])
        self.assertEqual(c.select_peer([second,first],9876).address,second[4])
        with self.assertRaises(dataclasses.FrozenInstanceError):c.select_peer([first],9876).family=9
        self.assertNotIn(first[4][0],repr(c.select_peer([first],9876)))

    def test_reject_all_bad_answers_not_only_selected_address(self):
        bad=[[],[answer()]*33,[answer(port=9877)],[answer(),answer('127.0.0.1')],
             [answer('0.0.0.0')],[answer('224.0.0.1')],[answer('169.254.0.1')],
             [answer('fe80::1',family=socket.AF_INET6)],
             [(socket.AF_INET,socket.SOCK_DGRAM,17,'',('198.51.100.7',9876))],
             [(socket.AF_INET6,socket.SOCK_STREAM,6,'',('2001:db8::7',9876,0,3))]]
        for rows in bad:
            with self.subTest(rows=repr(rows)):
                with self.assertRaises(Block):c.select_peer(rows,9876)

    def test_open_guard_and_false_approval_never_resolve_or_dial(self):
        with patch('socket.getaddrinfo') as dns,patch('socket.socket') as dial,\
                patch.dict(os.environ,{'B2_GO':'true','E858_LIVE':'1'}):
            with self.assertRaises(Block):object.__new__(c.OpenerCandidate).open()
            with self.assertRaises(Block):c.OpenerCandidate({'approved':True},None,None)
            dns.assert_not_called();dial.assert_not_called()

    def _entry_case(self,fault):
        def child(_,parent):
            if os.getppid()!=parent:raise Block()
            review=self.load();entry=p.ReviewedEntry();plan=None;boundary=None
            try:
                entry.begin(str(self.private),human_supervised=True);entry.bind_review(review)
                with patch('socket.getaddrinfo') as dns,patch('socket.socket') as dial:
                    gate=p.r.Gate('control',self.program.queries['identity'],self.program.queries['grants'],entry.until)
                    ctx=entry.target.tls_context();ctx.hostname_checks_common_name=False
                    plan=c.OpenerCandidate(entry,gate,ctx)
                    if fault=='NORMAL':
                        selected=plan.resolution([answer()])
                        self.assertEqual(selected.address,('198.51.100.7',9876))
                        self.assertLessEqual(plan.remaining(),2)
                        with self.assertRaises(Block):plan.resolution([answer()])
                    elif fault=='WEAK':
                        ctx.check_hostname=False;ctx.verify_mode=ssl.CERT_NONE
                        with self.assertRaises(Block):plan.remaining()
                    elif fault=='CA':
                        ctx.load_verify_locations(cadata=self.other_ca.decode('ascii'))
                        with self.assertRaises(Block):plan.remaining()
                    elif fault=='TIME':
                        plan.until=time.monotonic()-1
                        with self.assertRaises(Block):plan.remaining()
                    elif fault=='ROLE':
                        gate.role='third'
                        with self.assertRaises(Block):plan.remaining()
                    elif fault=='SNI':
                        plan.resolution([answer()]);raw=Mock();raw.getpeername.return_value=('198.51.100.7',9876)
                        with self.assertRaises(Block):plan.before_tls(raw,'unapproved.synthetic.invalid')
                    elif fault=='FAKE_TLS':
                        plan.resolution([answer()]);raw=Mock();raw.getpeername.return_value=('198.51.100.7',9876)
                        plan.before_tls(raw,entry.target.host)
                        with self.assertRaises(Block):plan.after_tls({'tls_verified':True})
                    elif fault=='THIRD':
                        boundary=p.PrivateBoundary(entry);secret=bytearray(b'SYNTHETIC_HANDOFF');boundary.secret=secret
                        for role in ('control','data'):
                            boundary.factory(p.r.Gate(role,gate.q0,gate.q1,entry.until))
                        with self.assertRaises(Block):boundary.factory(p.r.Gate('data',gate.q0,gate.q1,entry.until))
                        self.assertFalse(secret);self.assertTrue(boundary.closed)
                    dns.assert_not_called();dial.assert_not_called()
                return dict(result='SYNTHETIC_OPENER_POLICY_VERIFIED',traces=[],execution_allowed=False)
            finally:
                if plan is not None:plan.close()
                if boundary is not None:boundary.close()
                entry.close();review.close()
        with patch.object(fixture.tty,'child_trial',side_effect=child):
            self.assertEqual(fixture.tty.trial('NORMAL')['result'],'SYNTHETIC_OPENER_POLICY_VERIFIED')

    def test_resolution_is_single_use_and_bounded(self):self._entry_case('NORMAL')
    def test_tls_weakening_and_ca_store_change_block(self):
        self._entry_case('WEAK');self._entry_case('CA')
    def test_expiry_and_role_change_block(self):
        self._entry_case('TIME');self._entry_case('ROLE')
    def test_sni_switch_and_self_declared_tls_are_not_proof(self):
        self._entry_case('SNI');self._entry_case('FAKE_TLS')
    def test_third_connection_fails_before_any_network(self):self._entry_case('THIRD')

    def test_owned_dns_rejects_real_target_before_child_or_dns(self):
        with patch.object(owned.subprocess,'Popen') as child,patch('socket.getaddrinfo') as dns:
            with self.assertRaises(Block):owned.owned_resolve('unapproved.example.invalid',time.monotonic()+2)
            child.assert_not_called();dns.assert_not_called()

    def test_actual_fixed_child_timeout_and_bad_output_are_reaped(self):
        start=owned.subprocess.Popen;children=[];out,err=io.StringIO(),io.StringIO()
        def launch(*args,**kwargs):
            value=start(*args,**kwargs);children.append(value);return value
        with contextlib.redirect_stdout(out),contextlib.redirect_stderr(err),\
                patch.object(owned,'namespace',return_value={'owned_ips':['198.51.100.7']}),\
                patch.object(owned.subprocess,'Popen',side_effect=launch):
            # These fixed modes exit/sleep before any DNS syscall in the child.
            for mode in ('timeout','malformed','oversize'):
                with self.assertRaises(Block) as caught:owned.owned_resolve(owned.HOSTS[0],time.monotonic()+.6,mode)
                self.assertTrue(caught.exception.dns_reaped)
                self.assertEqual(caught.exception.dns_status,'DNS_TIMEOUT' if mode=='timeout' else 'DNS_RESPONSE_INVALID')
        self.assertEqual(len(children),3);self.assertTrue(all(v.poll() is not None for v in children))
        self.assertEqual(out.getvalue()+err.getvalue(),'')

    def test_child_kill_permission_error_does_not_claim_reaped(self):
        child=Mock();child.poll.return_value=None
        child.communicate.side_effect=subprocess.TimeoutExpired('fixed-child',.1)
        child.kill.side_effect=PermissionError(CANARY)
        out,err=io.StringIO(),io.StringIO()
        with contextlib.redirect_stdout(out),contextlib.redirect_stderr(err),\
                patch.object(owned,'namespace',return_value={'owned_ips':['198.51.100.7']}),\
                patch.object(owned.subprocess,'Popen',return_value=child):
            with self.assertRaises(Block) as caught:owned.owned_resolve(owned.HOSTS[0],time.monotonic()+2,'timeout')
        self.assertFalse(caught.exception.dns_reaped)
        self.assertEqual(caught.exception.dns_status,'DNS_CHILD_CLEANUP_UNVERIFIED')
        self.assertEqual(str(caught.exception),'PRIVATE_CANDIDATE_BLOCK');self.assertEqual(out.getvalue()+err.getvalue(),'')


if __name__=='__main__':unittest.main()
