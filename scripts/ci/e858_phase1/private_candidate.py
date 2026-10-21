"""Inert practical-adapter candidate. NO CLI and NO working connect method.

Only fresh synthetic controlling PTYs are exercised. B human supervision and
future runtime lock/GO remain external; no enum/record is real authentication.
"""
from enum import Enum
import hashlib
from importlib.metadata import version
import os
import re
import select
import stat
import termios
import time

import pymysql
from pymysql import connections, _auth
from .private_binding import Target, Block
from .r1_tty import PrivateTTYAttempt, MAX_PASSWORD_BYTES, INPUT_TIMEOUT_SECONDS
from .r1_adapter import SYNTHETIC_PASSWORD, Q0_SHA, Q1_SHA
from .runtime import Wire, StrictConnection, SharedBudget
from .protocol import Gate
from p0_mysql_metadata_grants_guard import verify_grants, GRANT_LINE


class Action(Enum):
    SYNTHETIC_OWNER_GESTURE = 'SYNTHETIC_ONLY_NOT_REAL_APPROVAL'


class WindowedTTY(PrivateTTYAttempt):
    """Reuse capture/echo/restore unchanged; bound waiting by the same budget.

    The immutable #815 reader has its own 120s clock. This small read hook
    clamps that clock so an earlier attempt start cannot extend the window.
    """
    def __init__(self, until):
        super().__init__()
        self.until = until

    def _read_hidden(self, descriptor):
        deadline = min(self.until,time.monotonic()+INPUT_TIMEOUT_SECONDS)
        while True:
            remaining = deadline-time.monotonic()
            if remaining<=0 or not select.select([descriptor],[],[],remaining)[0]: return 'R1_INPUT_TIMEOUT'
            value=os.read(descriptor,1)
            if not value or value==b'\x04': return 'R1_EOF'
            if value==b'\n': return 'R1_CAPTURED_OFFLINE' if self._password else 'R1_EMPTY'
            if value[0]<32 or value[0]==127: return 'R1_INPUT_BLOCKED'
            if len(self._password)>=MAX_PASSWORD_BYTES: return 'R1_OVERSIZE'
            self._password.append(value[0])


class DisabledWire(Wire):
    """Actual PyMySQL constructor/secret alias; all network/SQL entry is closed."""
    def __init__(self, gate, target, secret, owner):
        self.gate, self.quit_written, self.password = gate, False, b''
        self._owner = owner
        StrictConnection.__init__(self,host=target.host,port=target.port,user=target.principal,
            database=target.database,password=secret,ssl=target.tls_context(),charset='ascii',
            autocommit=None,sql_mode=None,connect_timeout=2,read_timeout=10,write_timeout=10,
            cursorclass=pymysql.cursors.SSCursor)
        if self.password is not secret: raise Block()

    def _force_close(self):
        try: return super()._force_close()
        finally: self.password = b''

    def _deny(self):
        self._owner.close()
        raise Block()

    def connect(self, sock=None): self._deny()
    def _request_authentication(self): self._deny()
    def _execute_command(self, command, sql): self._deny()
    def graceful_close(self): self._deny()
    def __repr__(self): return '<disabled private PyMySQL candidate>'


class Candidate:
    """One inert attempt; no factory injection, target override or retry."""
    def __init__(self, target, q0, q1):
        self.target = target
        self.wires = []
        self.used = False
        self.closed = False
        self.budget = SharedBudget()  # The same 120s budget includes input.
        self.captured = WindowedTTY(self.budget.until)
        self.q0, self.q1 = q0, q1
        self.identity = None
        self.data_cid = None
        self.control_cid = None
        try:
            if (type(target) is not Target or not target.valid(int(time.time()))
                    or type(q0) is not bytes or type(q1) is not bytes
                    or hashlib.sha256(q0).hexdigest() != Q0_SHA
                    or hashlib.sha256(q1).hexdigest() != Q1_SHA
                    or version('PyMySQL') != '1.1.2' or connections.DEBUG or _auth.DEBUG): raise Block()
        except BaseException:
            self.close(); raise Block() from None

    def __enter__(self):
        self._active(); return self

    def __exit__(self, *args):
        if not self.close(): raise Block()

    def _active(self):
        if (self.closed or time.monotonic() >= self.budget.until
                or not self.target.valid(int(time.time()))): raise Block()

    def capture_synthetic_ctty(self, action, parent_pid):
        # NOT a live entry. Fresh pty.fork child/session leader is mandatory;
        # ordinary operator terminal input is not enabled by a gesture value.
        fd = None
        try:
            if self.used: raise Block()
            self.used = True
            self._active()
            if (action is not Action.SYNTHETIC_OWNER_GESTURE or type(parent_pid) is not int
                    or os.getppid() != parent_pid or os.getsid(0) != os.getpid()): raise Block()
            fd = os.open('/dev/tty',os.O_RDWR | os.O_NOCTTY | os.O_NOFOLLOW | os.O_CLOEXEC)
            st, inherited = os.fstat(fd), os.fstat(0)
            if (fd <= 2 or os.get_inheritable(fd) or not stat.S_ISCHR(st.st_mode)
                    or not os.isatty(fd) or not os.isatty(0) or inherited.st_uid != os.geteuid()
                    or os.tcgetpgrp(fd) != os.getpgrp() or os.tcgetpgrp(0) != os.getpgrp()
                    or termios.tcgetattr(fd) != termios.tcgetattr(0)): raise Block()
            original = termios.tcgetattr(fd)
            if (self.captured.capture(fd) != 'R1_CAPTURED_OFFLINE'
                    or termios.tcgetattr(fd) != original or self.captured._password != SYNTHETIC_PASSWORD): raise Block()
            self._active()
        except BaseException:
            self.close(); raise Block() from None
        finally:
            if fd is not None:
                try: os.close(fd)
                except BaseException:
                    self.close(); raise Block() from None

    def prepare_pair(self):
        try:
            self._active()
            if not self.used or self.wires or self.captured._password != SYNTHETIC_PASSWORD: raise Block()
            for role in ('control','data'):
                gate = Gate(role,self.q0,self.q1,self.budget.until);gate.budget = self.budget
                wire = object.__new__(DisabledWire)
                self.wires.append(wire)  # Own partially initialized objects, too.
                DisabledWire.__init__(wire,gate,self.target,self.captured._password,self)
            return tuple(self.wires)
        except BaseException:
            self.close(); raise Block() from None

    def _fields(self, raw, cid, control):
        self._active()
        if type(raw) is not str or not raw.isascii() or len(raw) > 4096 or not raw.endswith('\n') or raw.endswith('\n\n'): raise Block()
        fields = raw[:-1].split('\t')
        if (len(fields) != (5 if control else 4) or type(cid) is not int or not 0 < cid < 2**32
                or fields[0] != self.target.database or fields[1].count('@') != 1
                or fields[1].split('@')[0] != self.target.principal
                or not re.fullmatch('[A-Za-z0-9_.%:/-]{1,255}', fields[1].split('@')[1])
                or not re.fullmatch(r'8\.(?:0|4)\.[0-9]+[A-Za-z0-9_.+-]*',fields[2])
                or not re.fullmatch('[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}',fields[3])
                or (control and fields[4] != str(cid))): raise Block()
        return tuple(fields)

    def observe_identity(self, role, raw, cid, grants):
        # Offline input seam, never a TLS/current-grants proof or receipt data.
        # UUID/account host are first observed here, NOT from pre-GO probes.
        try:
            self._active()
            if type(grants) is not str or len(grants) > 16384 or not verify_grants(grants,self.target.database): raise Block()
            fields = self._fields(raw,cid,role=='control')
            account = GRANT_LINE.fullmatch(grants.splitlines()[0]).group(3)
            user, host = (part[1:-1] for part in account.split('@'))
            if user + '@' + host != fields[1]: raise Block()
            if role == 'control':
                if self.identity is None:
                    self.identity, self.control_cid = fields, cid
                elif fields != self.identity or cid != self.control_cid: raise Block()
            elif role == 'data':
                if self.identity is None or fields != self.identity[:4] or cid == self.control_cid: raise Block()
                if self.data_cid is not None and cid != self.data_cid: raise Block()
                self.data_cid = cid
            else: raise Block()
            return 'SYNTHETIC_IDENTITY_MATCH_NOT_TLS_PROOF'
        except BaseException:
            self.close(); raise Block() from None

    def close(self):
        okay = True
        for wire in reversed(self.wires):
            try: wire.close()
            except BaseException: okay = False
            finally: wire.password = b''
        self.wires.clear()
        self.captured.discard()
        self.identity = self.data_cid = self.control_cid = None
        self.target = None
        self.closed = True
        return okay

    def report(self):
        return dict(kind='INERT_PRIVATE_ADAPTER_CANDIDATE',execution_allowed=False,
            owner_approval_verified=False,live_tooling_adopted=False,real_aiven_connected=False,
            sql_sent=0,tls_connections=0,remote_termination='REMOTE_TERMINATION_UNVERIFIED',
            fresh_owner_go_required=True)

    def __repr__(self): return '<inert private candidate; no live authority>'
