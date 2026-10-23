"""Single-attempt launcher lifecycle candidate. Public run ALWAYS BLOCKS.

This composes existing entry/program/time checks, does not acquire a TTY or
capture a password, and cannot launch processes, resolve, connect or issue SQL.
Only the archive-excluded owned fixture exercises these internal transitions.
No receipt/JSON/flag is an execution permit; activation remains absent.
"""
import time

from .phase1_application import _phase1_program
from .private_binding import Block


class LauncherCandidate:
    def __init__(self,entry,tooling_sha,bundle_sha256):
        self.entry=None;self.state='STOPPED';self.roles=0
        try:
            _phase1_program(entry,tooling_sha,bundle_sha256)
            self.entry=entry;self.until=entry.until;self.state='PRE_INPUT'
        except BaseException:raise Block() from None

    def run(self):
        self.close();raise Block()  # no activation, CLI, injection or input path

    def _check(self):
        if self.entry is None or self.state=='STOPPED':raise Block()
        self.entry.check()
        if self.entry.until!=self.until or not time.monotonic()<self.until:raise Block()

    def _before_input(self):
        try:
            self._check()
            if self.state!='PRE_INPUT' or self.entry.secret is not None:raise Block()
            self.state='INPUT_STARTED'  # consumed even on failure/interrupt
        except BaseException:self.close();raise Block() from None

    def _connection(self,gate):
        try:
            self._check()
            if (self.state not in ('INPUT_STARTED','CONTROL_STARTED') or self.roles>=2
                    or gate.role!=('control','data')[self.roles]
                    or self.entry.secret is None or self.entry.secret._password is None
                    or not self.entry.secret._password
                    or gate.q0!=self.entry.review.program.queries['identity']
                    or gate.q1!=self.entry.review.program.queries['grants']
                    or not time.monotonic()<gate.deadline<=self.until):raise Block()
            self.roles+=1;self.state='CONTROL_STARTED' if self.roles==1 else 'DATA_STARTED'
        except BaseException:self.close();raise Block() from None

    def close(self):
        # Resources/secret are still owned by Entry/Boundary, not duplicated.
        self.state='STOPPED';self.entry=None

    def __repr__(self):return '<dormant Phase1 launcher; no execution authority>'
