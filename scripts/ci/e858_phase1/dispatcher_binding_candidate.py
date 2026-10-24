"""Inert Runtime/Entry/trial/Producer coupling, NOT a live dispatcher.

No DNS, TCP, credential capture, callback, factory or execution API. Only the
excluded owned executor advances real I/O. B's human trust is an external
operational assumption, not something this binding authenticates or signs.
"""
import os
import time

from .private_binding import Block
from .runtime_file_lock_candidate import RuntimeFileLockCandidate
from .runtime_placement_candidate import RuntimePlacementCandidate
from .b_procedural_attempt import BProceduralAttempt
from .producer_candidate import ProducerBinding


class DispatcherBindingCandidate:
    """Transfer file-lock/placement ownership; borrow the existing trial.

    Failure invalidates this binding and both runtime observations; the caller's
    existing Bridge/finally still owns secret, socket, worker and trial cleanup.
    No reservation is removed, renewed or interpreted as permission to retry.
    """
    def __init__(self, runtime_lock, placement, procedure):
        self.lock=self.placement=self.procedure=None
        self.producers=[];self.state='STOPPED'
        try:
            if (type(runtime_lock) is not RuntimeFileLockCandidate
                    or type(placement) is not RuntimePlacementCandidate
                    or type(procedure) is not BProceduralAttempt):raise Block()
            # Type-check before taking ownership. Never invoke an injected object's
            # check/close method as a pretend authority or connector callback.
            self.lock,self.placement,self.procedure=runtime_lock,placement,procedure
            if (procedure.state!='PREFLIGHTED' or procedure.entry.secret is not None
                    or getattr(procedure,'_runtime_dispatch_binding',None) is not None):raise Block()
            procedure._runtime_dispatch_binding=self  # sticky even after failure/close
            self.entry=procedure.entry;self.program=self.entry.review.program
            self.seal=procedure._seal;self.target=self.entry.target
            self.attempt_id=self.entry.review.value['attempt_id']
            self.runtime=self.program.runtime;self.pid=os.getpid()
            self.until=min(procedure.until,placement.until)
            self.state='BOUND_NOT_AUTHORIZED';self.check()
        except BaseException:
            self.close();raise Block() from None

    def check(self):
        try:
            if self.state=='STOPPED' or os.getpid()!=self.pid or time.monotonic()>=self.until:raise Block()
            p=self.procedure
            if p.r1_started_at is None:
                self.lock.check()  # read-only Git may spawn ONLY before R1
            else:
                self.lock.check_sources()  # never fork/exec a password-bearing process
            self.placement.check();p.check()  # existing Entry/Program checks retained
            value=self.entry.review.check()
            if (p.entry is not self.entry or p._runtime_dispatch_binding is not self
                    or p._seal!=self.seal or p.until<self.until
                    or self.entry.target!=self.target or value['attempt_id']!=self.attempt_id
                    or self.entry.review.program is not self.program or self.lock._program is not self.program
                    or self.program.runtime!=self.runtime or self.program.sha!=self.placement.archive_sha
                    or self.program.pins['tooling_sha']!=self.placement.tooling_sha
                    or self.lock._sha!=self.placement.archive_sha
                    or self.lock._head!=self.placement.tooling_sha):raise Block()
            for producer in self.producers:
                if (producer.procedure is not p or producer.entry is not self.entry
                        or producer.state=='STOPPED' or producer._runtime_dispatch_binding is not self
                        or producer.wire.gate is not producer.gate or producer.wire.plan is not producer.plan):raise Block()
                producer.wire.check_binding()
            return dict(result='RUNTIME_TRIAL_PRODUCER_BOUND_NOT_AUTHORIZED',
                state=self.state,roles_bound=len(self.producers),execution_allowed=False,
                runtime_installation='SNAPSHOT_STAGED_CHECKOUT_OBSERVED_NOT_LIVE_BOOTSTRAP',
                approval_authenticity='UNIMPLEMENTED_EXTERNAL_BOUNDARY',
                real_aiven_connected=False,production_go=False)
        except BaseException:
            self.close();raise Block() from None

    def bind_producer(self,producer):
        try:
            self.check()
            index=len(self.producers)
            if type(producer) is not ProducerBinding or index>=2:raise Block()
            producer.check()
            if (producer.procedure is not self.procedure or producer.entry is not self.entry
                    or producer.role!=('control','data')[index]
                    or producer.state!='BOUND_NOT_AUTHORIZED'
                    or producer.gate is not producer.wire.gate
                    or any(producer is old or producer.worker is old.worker for old in self.producers)):
                raise Block()
            if getattr(producer,'_runtime_dispatch_binding',None) is not None:raise Block()
            producer._runtime_dispatch_binding=self
            self.producers.append(producer)
            self.state=('CONTROL_BOUND_NOT_AUTHORIZED','DATA_BOUND_NOT_AUTHORIZED')[index]
            return self.check()
        except BaseException:
            self.close();raise Block() from None

    def close(self):
        self.state='STOPPED';okay=True
        lock,placement=self.lock,self.placement
        self.lock=self.placement=self.procedure=None
        self.entry=self.program=self.target=None
        self.producers.clear()
        for value in (lock,placement):
            if value is not None:
                try:value.close()
                except BaseException:okay=False
        if not okay:raise Block() from None

    def __repr__(self):return '<inert runtime/trial/producer binding; no GO or live dispatcher>'
