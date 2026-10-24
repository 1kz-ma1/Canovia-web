"""Sealed Phase1 driver composition; inherited connect() ALWAYS BLOCKS.

No resolver, dialer, TLS opener, credential input or factory. Only the excluded
owned fixture supplies actual I/O. Shared code binds the existing R1 reference,
Entry/target/Gate and deadline to PyMySQL and its pre-auth packet boundary.
"""
import time

from . import practical as p
from .private_binding import Block
from .protocol import Gate
from .opener_candidate import OpenerCandidate
from .connection_handoff_candidate import AuthenticationBoundary


class Phase1DriverCandidate(p.PrivateWire):
    """No override of PrivateWire.connect; no alternative execution method."""
    def bind_entry(self,entry):
        try:
            if getattr(self,'_binding_started',False):raise Block()
            self._binding_started=True  # consumed on failure; never a retry permit
            if (getattr(self,'entry',None) is not None or type(entry) is not p.ReviewedEntry
                    or self.connect_count or self.authenticated
                    or type(self.gate) is not Gate):raise Block()
            entry.check()
            if (entry.review.check()['scope']!='phase1' or self.target!=entry.target
                    or entry.secret is None or self.password is not entry.secret._password
                    or not self.password):raise Block()
            self.entry=entry;self._gate=self.gate;self._target=self.target
            self.plan=OpenerCandidate(entry,self.gate,self.ctx)
            self.auth_boundary=AuthenticationBoundary(self.plan)
            self.check_binding()
        except BaseException:
            self.close();raise Block() from None

    def check_binding(self):
        try:
            entry=self.entry;entry.check();target=entry.target
            if (self.connect_count not in (0,1) or self.target!=self._target or target!=self._target or self.gate is not self._gate
                    or self.plan.entry is not entry or self.plan.gate is not self.gate
                    or (self.host,self.port)!=(target.host,target.port)
                    or self.db not in (target.database,target.database.encode('ascii'))
                    or self.user not in (target.principal,target.principal.encode('ascii'))
                    or self.gate.role!=self.plan.role or entry.review.check()['scope']!='phase1'
                    or self.gate.q0!=entry.review.program.queries['identity']
                    or self.gate.q1!=entry.review.program.queries['grants']
                    or not time.monotonic()<self.gate.deadline<=entry.until):raise Block()
            # PyMySQL changes self.user AND self.db to bytes during auth.
        except BaseException:
            self.close();raise Block() from None

    def _read_bytes(self,count):
        previous=self.gate.deadline
        try:
            self.check_binding()
            if not self.authenticated:
                self.gate.deadline=min(previous,self.plan.until);self.plan.remaining()
            return super()._read_bytes(count)
        finally:self.gate.deadline=previous

    def _write_bytes(self,data):
        previous=self.gate.deadline
        try:
            self.check_binding()
            if not self.authenticated:
                self.gate.deadline=min(previous,self.plan.until);self.plan.remaining()
            return super()._write_bytes(data)
        finally:self.gate.deadline=previous

    def write_packet(self,data):
        self.check_binding()
        if not self.authenticated:self.auth_boundary.packet(self._sock,data)
        return super().write_packet(data)

    def close(self):
        try:return super().close()
        finally:
            if getattr(self,'auth_boundary',None) is not None:self.auth_boundary.close()
            if getattr(self,'plan',None) is not None:self.plan.close()
            self.entry=self._gate=self._target=None

    def __repr__(self):return '<sealed Phase1 driver; connect unconditionally blocked>'
