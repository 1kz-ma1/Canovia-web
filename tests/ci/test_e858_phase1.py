"""Phase1-only SQL, privacy, no-execution and normal-close boundaries."""
import ast
import contextlib
import io
import json
from pathlib import Path
import struct
import subprocess
import sys
import time
import unittest
from unittest.mock import patch

ROOT=Path(__file__).resolve().parents[2]
sys.path.insert(0,str(ROOT/'scripts/ci'))
from e858_phase1.protocol import Gate, INIT, CID, CONTROL_ID, PROCESS, MAX_POLLS
from e858_phase1 import runtime as r
import e858_phase1_host as host

CANARY='SYNTHETIC_PRIVATE_INPUT_NOT_FOR_LOGS'
Q0=host.h.git(ROOT,'show',host.h.COLLECTOR+':scripts/ci/e858_audit/queries/identity.sql')
Q1=host.h.git(ROOT,'show',host.h.COLLECTOR+':scripts/ci/e858_audit/queries/grants.sql')


class SqlInventoryTests(unittest.TestCase):
    def gate(self,role='data'):return Gate(role,Q0,Q1,time.monotonic()+120)

    def test_fixed_q0_q1_bytes_are_unchanged_git_blobs(self):
        self.assertEqual(b'SELECT DATABASE(), CURRENT_USER(), VERSION(), @@server_uuid;\n',Q0)
        self.assertEqual(b'SHOW GRANTS FOR CURRENT_USER();\n',Q1)

    def test_data_sequence_including_driver_sql_is_closed(self):
        gate=self.gate()
        for sql in (INIT,Q0,CID,Q1,CID,Q0):self.assertEqual(sql,gate.claim(3,sql))
        with self.assertRaises(r.Block):gate.claim(3,Q1)

    def test_free_kill_full_metadata_export_sql_never_reaches_driver(self):
        for sql in (b'SELECT * FROM users;',b'KILL QUERY 1;',b'KILL CONNECTION 1;',
                    b'SELECT migration FROM migrations;',b'SELECT 1 INTO OUTFILE "/tmp/forbidden";',
                    Q0.strip(),Q0+b'SELECT 1;',CANARY.encode()):
            gate=self.gate();gate.claim(3,INIT)
            with patch.object(r.StrictConnection,'_execute_command') as sent:
                wire=object.__new__(r.Wire);wire.gate=gate
                with self.assertRaises(r.Block):wire._execute_command(3,sql)
                sent.assert_not_called()

    def test_ping_initdb_reset_and_binary_commands_rejected(self):
        for command in (1,2,12,14,17,31):
            with self.assertRaises(r.Block):self.gate().claim(command,INIT)

    def test_unknown_cid_is_never_a_processlist_query(self):
        gate=self.gate('control')
        for sql in (INIT,CONTROL_ID,Q1,CONTROL_ID,Q1):gate.claim(3,sql)
        with self.assertRaises(r.Block):gate.claim(3,PROCESS+b'1;')

    def test_owned_cid_and_observation_loop_are_bounded(self):
        class Owned:
            cid=123
            def check_owned(self):pass
        gate=self.gate('control');gate.owner=Owned()
        for sql in (INIT,CONTROL_ID,Q1):gate.claim(3,sql)
        for _ in range(MAX_POLLS+1):
            for sql in (CONTROL_ID,Q1,PROCESS+b'123;'):gate.claim(3,sql)
        with self.assertRaises(r.Block):gate.claim(3,CONTROL_ID)

    def test_different_cid_or_role_rejected(self):
        with self.assertRaises(r.Block):self.gate('root')
        gate=self.gate('control')
        class Owned:
            cid=123
            def check_owned(self):pass
        gate.owner=Owned()
        for sql in (INIT,CONTROL_ID,Q1,CONTROL_ID,Q1):gate.claim(3,sql)
        with self.assertRaises(r.Block):gate.claim(3,PROCESS+b'124;')

    def test_global_deadline_stops_even_driver_initialization(self):
        gate=Gate('data',Q0,Q1,time.monotonic()-1)
        with self.assertRaisesRegex(r.Block,'STREAM_TIMEOUT'):gate.claim(3,INIT)


class NormalCloseTests(unittest.TestCase):
    def wire(self):
        wire=object.__new__(r.Wire);wire._closed=False;wire._sock=object();wire.quit_written=False
        return wire

    def test_quit_write_failure_is_not_swallowed(self):
        wire=self.wire()
        with patch.object(wire,'_write_bytes',side_effect=OSError(CANARY)),patch.object(wire,'_force_close') as close:
            with self.assertRaises(r.Block) as error:wire.graceful_close()
            self.assertNotIn(CANARY,str(error.exception));close.assert_called_once()
            self.assertFalse(wire.quit_written)

    def test_successful_quit_write_is_separate_from_remote_proof(self):
        wire=self.wire()
        with patch.object(wire,'_write_bytes') as write,patch.object(wire,'_force_close'):
            wire.graceful_close();write.assert_called_once_with(struct.pack('<iB',1,1))
            self.assertTrue(wire.quit_written)
        self.assertFalse(hasattr(wire,'remote_termination_verified'))
        with self.assertRaises(r.Block):wire.graceful_close()

    def test_error_close_has_no_hidden_quit_or_kill(self):
        wire=self.wire()
        with patch.object(wire,'_write_bytes') as sent,patch.object(wire,'_force_close') as close:
            wire.close();sent.assert_not_called();close.assert_called_once()

    def test_reconnect_socket_override_or_weak_tls_cannot_connect(self):
        gate=Gate('data',Q0,Q1,time.monotonic()+120)
        with patch.object(r,'context',return_value=None):wire=r.Wire(gate)
        with self.assertRaises(r.Block):wire.connect(sock=object())
        wire.connect_count=1
        with self.assertRaises(r.Block):wire.connect()
        with patch.object(r,'context') as ca:
            with self.assertRaises(r.Block):r.Wire(gate,'real_endpoint')
            ca.assert_not_called()

    def test_wire_byte_budget_is_shared_between_both_channels(self):
        budget=r.SharedBudget();budget.wire_bytes=r.TOTAL
        wire=self.wire();wire.gate=self.gate_for_budget(budget)
        with patch.object(r.StrictConnection,'_read_bytes') as read:
            with self.assertRaisesRegex(r.Block,'WIRE_TOTAL_CAP'):wire._read_bytes(1)
            read.assert_not_called()

    def gate_for_budget(self,budget):
        gate=Gate('data',Q0,Q1,budget.until);gate.budget=budget;return gate

    def test_unknown_real_cli_modes_never_echo_inputs(self):
        for args in (['--host',CANARY],['--credential',CANARY],['--login-path',CANARY],['--ca',CANARY],
                     ['--sql',CANARY],['--collect'],['--execute'],['--kill'],['--full17']):
            proc=subprocess.run([sys.executable,'-I','-B',str(ROOT/'scripts/ci/e858_phase1_host.py'),*args],capture_output=True)
            self.assertEqual(2,proc.returncode)
            self.assertNotIn(CANARY.encode(),proc.stdout+proc.stderr)
            self.assertFalse(json.loads(proc.stdout)['real_aiven_connected'])

    def test_bootstrap_api_has_no_sql_cid_target_or_termination_input(self):
        self.assertFalse(hasattr(r.Bootstrap,'terminate'))
        self.assertFalse(hasattr(r.Bootstrap,'collect'))
        self.assertFalse(hasattr(r.Bootstrap,'kill'))
        for name in ('runtime.py','protocol.py'):
            tree=ast.parse((ROOT/'scripts/ci/e858_phase1'/name).read_text())
            attrs=[n.attr for n in ast.walk(tree) if isinstance(n,ast.Attribute)]
            self.assertNotIn('terminate',attrs)
            self.assertNotIn('kill',attrs)
        with patch.object(r,'load',return_value={}),patch.object(r,'Channel') as opened:
            value=r.Bootstrap();value.started=True
            with self.assertRaises(r.Block):value.run()
            opened.assert_not_called()


if __name__=='__main__':unittest.main()
