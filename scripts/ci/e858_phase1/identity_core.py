"""Target identity and bounded TTY primitives; no fixture credentials or connector."""
import hashlib
from importlib.metadata import version
import os
import re
import select
import time
from pymysql import connections,_auth
from .private_binding import Target,Block,Q0_SHA,Q1_SHA
from .r1_tty import PrivateTTYAttempt,MAX_PASSWORD_BYTES,INPUT_TIMEOUT_SECONDS
from .wire_core import SharedBudget
from p0_mysql_metadata_grants_guard import verify_grants,GRANT_LINE

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

class IdentityCandidate:
    def __init__(self,target,q0,q1):
        self.target=target;self.closed=False;self.budget=SharedBudget()
        self.identity=self.data_cid=self.control_cid=None
        try:
            if (type(target) is not Target or not target.valid(int(time.time()))
                    or type(q0) is not bytes or type(q1) is not bytes
                    or hashlib.sha256(q0).hexdigest()!=Q0_SHA or hashlib.sha256(q1).hexdigest()!=Q1_SHA
                    or version('PyMySQL')!='1.1.2' or connections.DEBUG or _auth.DEBUG):raise Block()
        except BaseException:self.close();raise Block() from None
    def close(self):
        self.target=self.identity=self.data_cid=self.control_cid=None
        self.closed=True
        return True

    def _active(self):
            if (self.closed or time.monotonic() >= self.budget.until
                    or not self.target.valid(int(time.time()))): raise Block()

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
