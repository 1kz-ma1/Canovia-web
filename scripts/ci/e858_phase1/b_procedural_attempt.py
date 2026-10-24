"""B's local procedural trial, NOT identity verification or a live permit.

Only the owned fixture composes this with I/O. No CLI, path/credential loader,
backend callback, approval JSON or live connection method. The human/operator
and managed Mac are external B assumptions, not properties proved here.
Included as an inert review library; no adopted live distribution exists.
"""
import hashlib
import os
import secrets
import select
import time

from e858_b_offline import canonical
from . import practical as p
from .b1_operator import Root, identity
from .b1_attempt_reservation import reserve
from .launcher_candidate import LauncherCandidate
from .private_binding import Block
from .private_candidate import WindowedTTY
from .protocol import Gate


class BProceduralAttempt:
    """Own one existing launcher/Entry plus a separate trusted reservation Root.

    A matching fresh TTY response establishes only this local procedural step.
    It never sets execution_allowed, authenticates a person, issues B2, or makes
    any of the existing four BLOCK methods usable. All terminal paths retain
    the reservation. Snapshot/deletion/other-device resistance is not claimed.
    """
    def __init__(self, launcher, reservation_root):
        self.launcher=None;self.entry=None;self.store=None;self.engine=None
        self.state='STOPPED';self.outcome='NOT_STARTED';self.reserved=False
        self.confirmed=False;self._seal=None;self._store_identity=None
        try:
            if type(launcher) is not LauncherCandidate or type(reservation_root) is not Root:raise Block()
            self.launcher=launcher;self.entry=launcher.entry;self.store=reservation_root
            if (type(self.entry) is not p.ReviewedEntry or launcher.state!='PRE_INPUT'
                    or launcher.roles or self.entry.secret is not None):raise Block()
            self.launcher._check();self.entry.check();value=self.entry.review.check()
            if value['scope']!='phase1':raise Block()
            fd=self.store.check();self._store_identity=identity(os.fstat(fd))
            # Do not mutate the sealed target/review roots or any of their ancestors.
            forbidden=self.entry.root.stats+self.entry.review.root.stats
            if self._store_identity[:2] in [row[:2] for row in forbidden]:raise Block()
            now=time.time();mono=time.monotonic()
            if not value['starts_at']<=now<value['expires_at']:raise Block()
            # Never reset/extend Entry's 120s; clamp it to the existing UTC window.
            self.entry.until=min(self.entry.until,mono+value['expires_at']-now)
            self.launcher.until=self.entry.until;self.until=self.entry.until;self.last_wall=now
            self._seal=self._context()
            self.state='PREFLIGHTED';self.outcome='PENDING_PROCEDURAL_REVIEW'
        except BaseException:
            self.close();raise Block() from None

    def _context(self):
        value=self.entry.review.check()
        return hashlib.sha256(canonical(dict(review=value,
            target_sha256=value['binding_sha256'],store=list(self._store_identity),
            until=self.until))).hexdigest()

    def check(self):
        try:
            if self.state in ('STOPPED','NORMAL_FINISHED') or self.entry is None:raise Block()
            self.entry.check()
            now=time.time()
            if (not self.last_wall<=now<self.entry.review.value['expires_at']
                    or self.entry.until!=self.until or not time.monotonic()<self.until
                    or self._context()!=self._seal):raise Block()
            self.last_wall=now
            if self.store is not None and identity(os.fstat(self.store.check()))!=self._store_identity:raise Block()
        except BaseException:
            self.close();raise Block() from None

    def _write_private(self, raw):
        """Bounded owned TTY output, never stdout/stderr or an arbitrary FD."""
        duplicate=None;blocking=None
        try:
            if type(raw) is not bytes or not 0<len(raw)<=8192:raise Block()
            fd=self.entry.terminal.check();duplicate=os.dup(fd)
            blocking=os.get_blocking(duplicate);os.set_blocking(duplicate,False)
            sent=0
            while sent<len(raw):
                self.check();remaining=self.until-time.monotonic()
                if remaining<=0:raise Block()
                _,ready,_=select.select([], [duplicate], [], min(.1,remaining))
                if not ready:continue
                try:count=os.write(duplicate,raw[sent:])
                except BlockingIOError:continue
                if count<=0:raise Block()
                sent+=count
        finally:
            if duplicate is not None:
                try:
                    if blocking is not None:os.set_blocking(duplicate,blocking)
                finally:os.close(duplicate)

    def confirm_on_tty(self, *args, **kwargs):
        capture=None
        try:
            if args or kwargs:raise Block()
            self.check()
            if self.state!='PREFLIGHTED':raise Block()
            self.state='CONFIRMING'  # Failure/interrupt cannot restart this dialogue.
            value=self.entry.review.value;target=self.entry.target
            private=dict(kind='B_PROCEDURAL_REVIEW_NOT_GO',source=value['source_sha'],
                tooling=value['tooling_sha'],scope='phase1',sql=value['sql_sha256'],
                reference=value['reference_sha256'],bundle=value['bundle_sha256'],
                runtime=value['runtime_sha256'],binding=value['binding_sha256'],
                host=target.host,port=target.port,database=target.database,principal=target.principal,
                ca_sha256=hashlib.sha256(target.ca).hexdigest(),attempt_id=value['attempt_id'],
                starts_at=value['starts_at'],expires_at=value['expires_at'])
            token=hashlib.sha256(canonical(private)+secrets.token_bytes(32)).hexdigest().encode('ascii')
            self._write_private(b'B_PRIVATE_REVIEW_NOT_GO\n'+canonical(private)+b'\nB_CONFIRM_RESPONSE='+token+b'\n')
            capture=WindowedTTY(self.until)
            if capture.capture(self.entry.terminal.check())!='R1_CAPTURED_OFFLINE':raise Block()
            self.check()
            if capture._password!=token:raise Block()
            self.confirmed=True;self.state='CONFIRMED_NOT_AUTHORITY'
            self._write_private(b'B_CONFIRMATION_FINISHED_NOT_GO\n')
            return self.report()
        except BaseException:
            self.close();raise Block() from None
        finally:
            if capture is not None:capture.discard()

    def reserve_attempt(self):
        try:
            self.check()
            if self.state!='CONFIRMED_NOT_AUTHORITY' or not self.confirmed or self.reserved:raise Block()
            self.state='RESERVING'
            value=self.entry.review.value
            result=reserve(self.store.check(),value['attempt_id'],value['tooling_sha'])
            if result['execution_allowed'] is not False or result['fresh_b2_go_granted'] is not False:raise Block()
            self.reserved=True
            # Our own marker changes directory ctime. Do not weaken Root.check:
            # close this now-consumed separate Root rather than refreshing its seal.
            store=self.store;self.store=None;store.close()
            self.state='RESERVED_NOT_AUTHORITY';self.check()
        except BaseException:
            self.close();raise Block() from None

    def bind_engine(self, engine):
        try:
            self.check()
            if (self.engine is not None or self.state!='PREFLIGHTED' or not isinstance(engine,p.Adapter)
                    or engine.entry is not self.entry or engine.scope!='phase1'):raise Block()
            self.engine=engine
        except BaseException:
            self.close();raise Block() from None

    def before_input(self):
        try:
            self.check()
            if self.state!='RESERVED_NOT_AUTHORITY' or not self.reserved:raise Block()
            self.launcher._before_input();self.state='INPUT_STARTED'
        except BaseException:
            self.close();raise Block() from None

    def before_connection(self, gate):
        try:
            self.check()
            if type(gate) is not Gate or self.state not in ('INPUT_STARTED','CONTROL_STARTED') or not self.reserved:raise Block()
            self.launcher._connection(gate)
            self.state=self.launcher.state
        except BaseException:
            self.close();raise Block() from None

    def finish(self):
        try:
            self.check();engine=self.engine
            if (self.state!='DATA_STARTED' or engine is None or engine.entry is not self.entry
                    or engine.proof is not True or engine.quit is not True
                    or len(engine.channels)!=2 or engine.channels[-1].closed is not True):raise Block()
            self.state='NORMAL_FINISHED';self.outcome='NORMAL_DATA_CLOSE_OBSERVED_NOT_AUTHORITY'
            return self.report()
        except BaseException:
            self.close();raise Block() from None

    def cancel(self):
        self.close()

    def close(self):
        self.state='STOPPED';okay=True
        if self.outcome!='NORMAL_DATA_CLOSE_OBSERVED_NOT_AUTHORITY':self.outcome='STOPPED_REMOTE_UNVERIFIED'
        launcher,entry,store=self.launcher,self.entry,self.store
        self.launcher=self.entry=self.store=self.engine=None
        for obj in (launcher,entry,store):
            if obj is not None:
                try:obj.close()
                except BaseException:okay=False
        if not okay:raise Block() from None

    def report(self):
        return dict(state=self.state,outcome=self.outcome,local_confirmation_observed=self.confirmed,
            local_attempt_reserved=self.reserved,owner_identity_verified=False,
            fresh_b2_go_granted=False,execution_allowed=False,real_aiven_connected=False,
            remote_termination='REMOTE_TERMINATION_UNVERIFIED',durable_rollback_resistance=False,
            production_go=False)

    def __repr__(self):return '<B procedural trial; no live authority>'
