"""Single-target producer binding, NOT a DNS/TCP executor or authority.

All I/O remains in the excluded fixed owned executor. No callable/factory,
endpoint override, resolver, socket creation, credential input or activation.
"""
import os
import ssl

from e858_b_offline import canonical
from .private_binding import Block
from .backend_candidate import PreparedChild
from .b_procedural_attempt import BProceduralAttempt
from .phase1_driver_candidate import Phase1DriverCandidate
from .connection_handoff_candidate import ConnectedPeer


class ProducerBinding:
    def __init__(self,wire,procedure,worker):
        self.wire=self.worker=None;self.state='STOPPED'
        self.procedure=procedure if type(procedure) is BProceduralAttempt else None
        try:
            if (not isinstance(wire,Phase1DriverCandidate) or type(procedure) is not BProceduralAttempt
                    or type(worker) is not PreparedChild):raise Block()
            self.wire=wire;self.procedure=procedure;self.worker=worker
            self.entry=wire.entry;self.gate=wire.gate;self.plan=wire.plan
            self.seal=procedure._seal;self.role=wire.gate.role
            if procedure._workers is not None and procedure._workers[('control','data').index(self.role)] is not worker:raise Block()
            if (worker._producer_binding is not None or procedure.r1_started_at is None
                    or not worker.ready_at<=procedure.r1_started_at or worker.parent!=os.getpid()):raise Block()
            worker._producer_binding=self  # consumed even if a later check fails
            worker.check();self.worker_identity=worker.identity
            self.state='BOUND_NOT_AUTHORIZED';self.check()
        except BaseException:
            self._abort();raise Block() from None

    def check(self):
        try:
            if self.state=='STOPPED':raise Block()
            p=self.procedure;w=self.wire
            if type(p) is not BProceduralAttempt or not isinstance(w,Phase1DriverCandidate):raise Block()
            p.check();w.check_binding()
            boundary=p.engine.boundary
            index=('control','data').index(self.role)
            if (p._seal!=self.seal or p.entry is not self.entry or w.entry is not self.entry
                    or w.gate is not self.gate or w.plan is not self.plan
                    or p.state!=('CONTROL_STARTED','DATA_STARTED')[index]
                    or boundary.entry is not self.entry or not 0<len(boundary.wires)<=2
                    or boundary.wires[index] is not w or self.worker.identity!=self.worker_identity
                    or self.worker._producer_binding is not self):raise Block()
            self.plan.remaining()
        except BaseException:
            self._abort();raise Block() from None

    def dns_request(self):
        try:
            self.check()
            if self.state!='BOUND_NOT_AUTHORIZED':raise Block()
            self.worker.check();self.state='DNS_REQUESTED_NOT_AUTHORIZED'
            return canonical(dict(host=self.entry.target.host,port=self.entry.target.port))
        except BaseException:
            self._abort();raise Block() from None

    def resolved(self,answers):
        try:
            self.check()
            if (self.state!='DNS_REQUESTED_NOT_AUTHORIZED' or not self.worker.used
                    or self.worker.process.poll()!=0
                    or not all(s.closed for s in (self.worker.process.stdin,self.worker.process.stdout,self.worker.process.stderr))):raise Block()
            peer=self.plan.resolution(answers);self.state='TCP_PENDING_NOT_AUTHORIZED'
            return peer
        except BaseException:
            self._abort();raise Block() from None

    def take_socket(self,raw):
        handoff=None
        try:
            self.check()
            if self.state!='TCP_PENDING_NOT_AUTHORIZED':raise Block()
            handoff=ConnectedPeer(self.plan,raw);value=handoff.take()
            self.state='SOCKET_TAKEN_NOT_AUTHORIZED';return value
        except BaseException:
            try:
                if handoff is not None:handoff.close()
                # Until ConnectedPeer accepts the concrete types the caller
                # still owns raw. Never invoke methods of rejected inputs.
            except BaseException:
                pass  # always BLOCK below; cleanup failure never preserves a trial
            self._abort();raise Block() from None

    def authenticated(self):
        try:
            self.check();w=self.wire;raw=w._sock
            if (self.state!='SOCKET_TAKEN_NOT_AUTHORIZED' or not w.authenticated
                    or w.password or w.connect_count!=1 or self.gate.used!=1
                    or not self.plan.verified or not isinstance(raw,ssl.SSLSocket)
                    or raw.context is not self.plan.context or raw.getpeername()!=self.plan.peer.address
                    or raw.server_hostname!=self.entry.target.host):raise Block()
            self.state='IDENTITY_CHECK_REQUIRED_NOT_AUTHORIZED'
        except BaseException:
            self._abort();raise Block() from None

    def _abort(self):
        # A failed producer cannot leave its B trial usable for another DNS or
        # connection attempt. Entry/secret/marker cleanup stays with the trial.
        procedure=self.procedure
        self.close()
        if type(procedure) is BProceduralAttempt:procedure.cancel()

    def close(self):
        self.state='STOPPED'
        self.wire=self.procedure=self.worker=self.entry=self.gate=self.plan=None

    def __repr__(self):return '<single-target producer binding; no execution authority>'
