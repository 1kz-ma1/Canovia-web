"""B integration using the real driver, locked to owned synthetic Docker only.

There is deliberately no live backend, endpoint argument, GO switch or CLI.
Phase1 and full metadata are separate scopes. The latter adds exactly 15 SQL
(the remaining fixed ORDER after initial Q0/Q1), never silently authorizes it.
"""
import hashlib
import json
import os
from pathlib import Path
import socket
import time

from . import runtime as r
from .protocol import Gate, INIT, CID, CONTROL_ID, MAX_POLLS
from .private_binding import Target, Block
from .private_candidate import Candidate
from .r1_driver import IsolatedAttempt
from .r1_adapter import SYNTHETIC_PASSWORD


ORDER=('identity','grants','ledger','engine','table','column','index','foreign_key',
       'check','trigger','semantic_version','semantic_index','semantic_foreign_key',
       'column_extra','ledger','identity','grants')


class BridgeGate(Gate):
    def __init__(self,role,config,budget,bundle):
        super().__init__(role,config['q0'],config['q1'],budget.until)
        self.budget=budget
        self.bundle=bundle
    def expected(self):
        if self.role=='control':return super().expected()
        order=(INIT,self.q0,CID,self.q1)+tuple(self.bundle[k] for k in ORDER[2:])+(CID,self.q0)
        if self.used>=len(order):raise r.Block('PHASE1_SQL_EXHAUSTED')
        return order[self.used]


class Channel(r.Channel):
    def __init__(self,role,config,budget,boundary,entry,bundle):
        self.entry=entry;self.budget=budget;self.until=budget.until;self.query_seconds=10
        self.gate=BridgeGate(role,config,budget,bundle) if bundle else Gate(role,config['q0'],config['q1'],budget.until)
        self.gate.budget=budget
        self.conn=boundary.factory(self.gate)
        try:self.conn.connect()
        except BaseException:self.conn.close();raise
        self.original,self.tls=self.conn,self.conn._sock
        self.cid=self.conn.thread_id();self.closed=False
        self.cancel_socket=socket.socket(fileno=os.dup(self.tls.fileno()))
    def check_owned(self):
        self.entry.check()
        super().check_owned()


class Bridge:
    """One B supervised attempt. No production connector/factory injection."""
    def __init__(self,entry,scope='phase1'):
        self.entry=entry;self.scope=scope;self.started=False;self.channels=[]
        self.boundary=None;self.validator=None;self.data={};self.proof=False;self.quit=False

    def run(self):
        if self.started:raise Block()
        self.started=True
        try:
            if self.scope not in ('phase1','metadata'):raise Block()
            self.entry.check()
            config=r.load()
            self.boundary=IsolatedAttempt(config,None)
            self.boundary.until=self.entry.until
            self.boundary.seal()  # fixed Docker IP/CA, not an Aiven dynamic-IP policy
            target=self.entry.target
            if (target is None or (target.host,target.port,target.database,target.principal)!=(r.HOST,3306,r.DB,r.USER)
                    or hashlib.sha256(target.ca).hexdigest()!=config['r1']['ca_sha256']
                    or target.tooling_sha!=config['pins']['tooling_sha']):raise Block()
            self.validator=Candidate(target,config['q0'],config['q1'])
            bundle={}
            if self.scope=='metadata':
                manifest=json.loads(Path('/synthetic/manifest.json').read_text())
                if tuple(manifest['order'])!=ORDER:raise Block()
                bundle={k:(Path('/synthetic/queries')/(k+'.sql')).read_bytes() for k in config['sql_digests']}
                if any(hashlib.sha256(raw).hexdigest()!=config['sql_digests'][k] for k,raw in bundle.items()):raise Block()
                # Comparator assets verify the fixed Reference, source seal and SQL pins.
                from e858_tls.bridge import assets
                self.inputs=assets()
            secret=self.entry.capture()
            if secret._password!=SYNTHETIC_PASSWORD:raise Block()
            self.boundary.secret=secret._password
            budget=r.SharedBudget();budget.until=self.entry.until
            control=Channel('control',config,budget,self.boundary,self.entry,bundle);self.channels.append(control)
            ci=control.read(CONTROL_ID);cg=control.read(config['q1'],config['caps']['grants'])
            self.validator.observe_identity('control',ci,control.cid,cg)
            data=Channel('data',config,budget,self.boundary,self.entry,bundle);self.channels.append(data)
            di=data.read(config['q0'])
            if data.read(CID).strip()!=str(data.cid):raise Block()
            dg=data.read(config['q1'],config['caps']['grants'])
            self.validator.observe_identity('data',di,data.cid,dg)
            control.gate.owner=data
            def observe():
                ci=control.read(CONTROL_ID);cg=control.read(config['q1'],config['caps']['grants'])
                self.validator.observe_identity('control',ci,control.cid,cg)
                raw=control.read(control.gate.expected())
                fields=raw.rstrip('\n').split('\t') if raw else []
                if fields and (len(fields)!=4 or fields[:3]!=[str(data.cid),r.USER,r.DB]):raise Block()
                if not fields and not data.closed:raise Block()
                return fields
            if observe()[3]!='Sleep':raise Block()
            if bundle:
                self.data={'identity':di,'grants':dg}
                for key in ORDER[2:]:
                    value=data.read(bundle[key],config['caps'][key])
                    if key in self.data and self.data[key]!=value:raise Block()
                    if key=='identity':self.validator.observe_identity('data',value,data.cid,self.data['grants'])
                    if key=='grants':self.validator.observe_identity('data',self.data['identity'],data.cid,value)
                    self.data[key]=value
            if data.read(CID).strip()!=str(data.cid):raise Block()
            self.validator.observe_identity('data',data.read(config['q0']),data.cid,dg)
            data.normal_close();self.quit=data.conn.quit_written
            for _ in range(MAX_POLLS):
                if not observe():self.proof=True;break
                time.sleep(.02)
            if not self.proof:raise Block()
            control.normal_close()
            if bundle:
                from e858_tls.bridge import assess
                self.assessment=assess(self.data,self.inputs)  # original strict BLOCK unchanged
            return self.report(True)
        except BaseException:
            self.proof=False
            return self.report(False)
        finally:
            okay=True
            for channel in reversed(self.channels):
                try:channel.drop()
                except BaseException:okay=False
            if self.boundary is not None:okay=self.boundary.close() and okay
            if self.validator is not None:okay=self.validator.close() and okay
            try:self.entry.close()
            except BaseException:okay=False
            self.data.clear()
            if not okay:raise Block() from None

    def report(self,success):
        return dict(result='ISOLATED_B1_BRIDGE_VERIFIED' if success else 'BLOCK',scope=self.scope,
            sql_count=sum(len(c.gate.sent) for c in self.channels),tls_connections=len(self.channels),
            query_ended=self.proof,connection_ended=self.proof,quit_written=self.quit,
            remote_termination='SYNTHETIC_NORMAL_CLOSE_OBSERVED' if self.proof else 'REMOTE_TERMINATION_UNVERIFIED',
            strict_result=getattr(self,'assessment',{}).get('strict_result','NOT_RUN'),
            execution_allowed=False,owner_approval_verified=False,real_aiven_connected=False)

    def private_trace(self):
        return [dict(role=c.gate.role,cid=c.cid,sql=c.gate.sent,quit_written=c.conn.quit_written) for c in self.channels]
