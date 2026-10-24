"""Synthetic-only Q0/Q1 and read-only control. No KILL/full17/live mode."""
import hashlib
import json
import os
from pathlib import Path
import secrets
import socket
import ssl
import struct
import time
import traceback

import pymysql
from e858_tls.transport import (Block, StrictConnection, Session, context,
    HOST, DB, USER, PASSWORD, SQL_SHA, TOTAL)
from e858_tls.control_contract import Attempt, Binding, ExpectedTarget, Observation, Block as ContractBlock
from e858_b_offline import validate, outcome, canonical, SOURCE, RELEASE, COLLECTOR, TRANSPORT, CONTRACT
from p0_mysql_metadata_grants_guard import verify_grants
from .protocol import Gate, INIT, CID, CONTROL_ID, MAX_POLLS
from .wire_core import Wire as CoreWire,Channel as CoreChannel,SharedBudget


class Wire(CoreWire):
    def __init__(self, gate, fault=None):
            if fault not in (None, 'wrong_ca', 'wrong_host', 'expired'):
                raise Block('PHASE1_FIXTURE_REJECTED')
            self.gate = gate
            host = {'wrong_host':'mismatch.synthetic.invalid','expired':'expired.synthetic.invalid'}.get(fault,HOST)
            ca = '/synthetic/wrong-ca.pem' if fault=='wrong_ca' else '/synthetic/ca.pem'
            super().__init__(host=host, port=3306, user=USER, password=PASSWORD, database=DB,
                ssl=context(ca), charset='ascii', autocommit=None, sql_mode=None,
                connect_timeout=2, read_timeout=10, write_timeout=10,
                cursorclass=pymysql.cursors.SSCursor)
            self.quit_written = False

    def connect(self, sock=None):
            if sock is not None:
                raise Block('PHASE1_SOCKET_OVERRIDE_REJECTED')
            result = StrictConnection.connect(self)
            if (not isinstance(self._sock,ssl.SSLSocket) or not self._sock.getpeercert()
                    or not self._sock.cipher()):
                self._force_close();raise Block('PHASE1_TLS_UNVERIFIED')
            return result





class Channel(CoreChannel):
    def __init__(self, role, config, budget, fault=None):
            self.budget = budget
            self.until = budget.until
            self.query_seconds = 10
            self.gate = Gate(role, config['q0'], config['q1'], self.until)
            self.gate.budget = budget
            self.conn = Wire(self.gate, fault)
            try: self.conn.connect()
            except BaseException:
                self.conn.close();raise
            self.original, self.tls = self.conn, self.conn._sock
            self.cid = self.conn.thread_id()
            self.cancel_socket = socket.socket(fileno=os.dup(self.tls.fileno()))
            self.closed = False



def load():
    value=json.loads(Path('/synthetic/phase1.json').read_text())
    validate(value['packet'],value['pins'],int(time.time()))
    expected={'source_sha':SOURCE,'release_ref':RELEASE,'collector_sha':COLLECTOR,'transport_sha':TRANSPORT,'contract_sha':CONTRACT,'sql_sha256':SQL_SHA}
    if (any(value['pins'].get(k)!=v for k,v in expected.items())
            or hashlib.sha256(canonical(value['sql_digests'])).hexdigest()!=SQL_SHA):
        raise Block('PHASE1_PREFLIGHT_REJECTED')
    if (value['series']!=value['packet']['engine'] or value['kind']!='SYNTHETIC_PHASE1_ONLY' or value['execution_allowed'] is not False
            or value['owner_approval_verified'] is not False):
        raise Block('PHASE1_PREFLIGHT_REJECTED')
    for key in ('identity','grants'):
        raw=(Path('/synthetic/queries')/(key+'.sql')).read_bytes()
        if hashlib.sha256(raw).hexdigest()!=value['sql_digests'][key]:
            raise Block('PHASE1_SQL_REJECTED')
        value['q0' if key=='identity' else 'q1']=raw
    return value


class Bootstrap:
    """One synthetic attempt. API accepts neither target/credentials nor SQL."""
    def __init__(self):
        self.config=load()
        self.started=False
        self.channels=[]
        self.policy=None
        self.data_close=False
        self.absence=False
        self.query_end=False
        self.control_close=False

    def _identity(self, raw, control=False):
        fields=raw.rstrip('\n').split('\t')
        expected=self.config['expected']
        if (len(fields)!=(5 if control else 4) or fields[:2]!=[DB,USER+'@%']
                or not fields[2].startswith(self.config['series']+'.') or fields[3]!=expected['uuid']):
            raise Block('PHASE1_IDENTITY_REJECTED')
        return fields

    def run(self):
        if self.started: raise Block('NEW_OWNER_GO_REQUIRED')
        self.started=True
        budget=SharedBudget()
        try:
            control=Channel('control',self.config,budget);self.channels.append(control)
            identity=self._identity(control.read(CONTROL_ID),True)
            if identity[4]!=str(control.cid):raise Block('PHASE1_CONTROL_CHANGED')
            if not verify_grants(control.read(self.config['q1'],self.config['caps']['grants']),DB):raise Block('MINIMAL_GRANTS_BLOCK')
            data=Channel('data',self.config,budget);self.channels.append(data)
            if self._identity(data.read(self.config['q0']))!=identity[:4]:raise Block('PHASE1_IDENTITY_REJECTED')
            if data.read(CID).strip()!=str(data.cid):raise Block('PHASE1_CID_CONTINUITY_UNVERIFIED')
            if not verify_grants(data.read(self.config['q1'],self.config['caps']['grants']),DB):raise Block('MINIMAL_GRANTS_BLOCK')
            control.gate.owner=data
            if data.cid==control.cid:raise Block('PHASE1_CONTROL_CHANGED')
            expected=ExpectedTarget(**self.config['expected']['binding'])
            dbind=Binding(expected.target,identity[3],identity[1],data.cid,secrets.token_hex(32),expected.ca,expected.san)
            cbind=Binding(expected.target,identity[3],identity[1],control.cid,secrets.token_hex(32),expected.ca,expected.san)
            self.policy=Attempt(self.config['packet'],self.config['pins'],self.config['pins'],
                self.config['sql_digests'],int(time.time()),expected)
            sequence=0
            def observe():
                nonlocal sequence
                if self._identity(control.read(CONTROL_ID),True)!=identity:
                    raise Block('PHASE1_CONTROL_CHANGED')
                if not verify_grants(control.read(self.config['q1'],self.config['caps']['grants']),DB):raise Block('MINIMAL_GRANTS_BLOCK')
                raw=control.read(control.gate.expected())
                fields=raw.rstrip('\n').split('\t') if raw else []
                if fields and (len(fields)!=4 or fields[:3]!=[str(data.cid),USER,DB]):
                    raise Block('PHASE1_CID_CONTINUITY_UNVERIFIED')
                if not fields and not data.closed:raise Block('PHASE1_VISIBILITY_UNVERIFIED')
                sequence+=1
                return Observation(dbind,cbind,sequence,int(time.time()),True,bool(fields),
                                   bool(fields and fields[3]!='Sleep'),True)
            first=observe()
            self.policy.bind(dbind,cbind,first,first.observed_at,scope=('OBSERVE',))
            if data.read(CID).strip()!=str(data.cid):raise Block('PHASE1_CID_CONTINUITY_UNVERIFIED')
            if self._identity(data.read(self.config['q0']))!=identity[:4]:raise Block('PHASE1_IDENTITY_REJECTED')
            data.normal_close();self.data_close=data.conn.quit_written
            if not self.data_close:raise Block('PHASE1_CLOSE_UNVERIFIED')
            for _ in range(MAX_POLLS):
                observation=observe()
                if not observation.exists:
                    proof=self.policy.finish(observation,observation.observed_at)
                    self.absence=proof['connection_ended'];self.query_end=proof['query_ended']
                    break
                self.policy.observe(observation,observation.observed_at)
                time.sleep(.02)
            if not self.absence:raise Block('REMOTE_TERMINATION_UNVERIFIED')
            control.normal_close();self.control_close=True
            return self.report(True)
        except BaseException as error:
            self.stop_code=str(error) if type(error) is Block and str(error) in ('QUERY_TIMEOUT','STREAM_TIMEOUT','PHASE1_CLOSE_UNVERIFIED','MINIMAL_GRANTS_BLOCK') else 'PHASE1_REJECTED'
            original=getattr(error,'original_exception',error)
            self.certificate_failure=None
            if isinstance(original,ssl.SSLCertVerificationError):
                self.certificate_failure={10:'EXPIRED',62:'HOSTNAME',18:'CHAIN',19:'CHAIN',20:'CHAIN',21:'CHAIN'}.get(original.verify_code,'UNKNOWN')
            self.private_frames=[(Path(f.filename).name,f.lineno) for f in traceback.extract_tb(error.__traceback__)]
            self.absence=False;self.query_end=False
            if self.policy:
                try:self.policy.interrupt()
                except ContractBlock:pass
            return self.report(False)
        finally:
            for channel in reversed(self.channels):channel.drop()

    def report(self, success):
        return dict(stop_code=getattr(self,'stop_code',None),phase1='ISOLATED_PHASE1_VERIFIED' if success else 'BLOCK',
            data_close_written=self.data_close, control_close_written=self.control_close,
            query_ended=self.query_end, data_connection_absent=self.absence,
            remote_termination='SYNTHETIC_NORMAL_CLOSE_OBSERVED' if success else 'REMOTE_TERMINATION_UNVERIFIED',
            certificate_failure=getattr(self,'certificate_failure',None),
            kill_capability_verified=False,cid_reuse_race_verified=False,
            **outcome('PHASE1_SYNTHETIC_ONLY','SYNTHETIC_REVIEW_ONLY'))

    def private_trace(self):
        return [dict(role=c.gate.role,cid=c.cid,sql=c.gate.sent,quit_written=c.conn.quit_written)
                for c in self.channels]
