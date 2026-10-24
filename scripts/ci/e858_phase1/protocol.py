"""Closed synthetic Phase1 SQL inventory. No caller SQL or execution permit."""
import time

from e858_tls.stream_core import Block

INIT = b'SET NAMES ascii'
CID = b'SELECT CONNECTION_ID();'
CONTROL_ID = b'SELECT DATABASE(), CURRENT_USER(), VERSION(), @@server_uuid, CONNECTION_ID();'
PROCESS = b'SELECT ID, USER, DB, COMMAND FROM information_schema.PROCESSLIST WHERE ID = '
MAX_POLLS = 10


class Gate:
    """Wire-level allowlist and exact ordering, including driver initialization."""
    def __init__(self, role, q0, q1, deadline):
        if role not in ('data', 'control'):
            raise Block('PHASE1_ROLE_REJECTED')
        self.role, self.q0, self.q1, self.deadline = role, q0, q1, deadline
        self.used = 0
        self.sent = []  # Private synthetic trace, never printed.
        self.owner = None

    def __repr__(self):
        return '<private Phase1 SQL gate>'

    def expected(self):
        if self.role == 'data':
            order = (INIT, self.q0, CID, self.q1, CID, self.q0)
            if self.used >= len(order):
                raise Block('PHASE1_SQL_EXHAUSTED')
            return order[self.used]
        if self.used == 0:
            return INIT
        if self.used == 1:
            return CONTROL_ID
        if self.used == 2:
            return self.q1
        offset = self.used - 3
        if offset >= 3 * (1 + MAX_POLLS):
            raise Block('PHASE1_OBSERVATION_CAP')
        step = offset % 3
        if step == 0:
            return CONTROL_ID
        if step == 1:
            return self.q1
        if self.owner is None:
            raise Block('PHASE1_UNKNOWN_CID')
        self.owner.check_owned()
        return PROCESS + str(self.owner.cid).encode('ascii') + b';'

    def claim(self, command, sql):
        if time.monotonic() >= self.deadline:
            raise Block('STREAM_TIMEOUT')
        if command != 3:  # COM_QUERY only; no PING/INIT_DB/reset/KILL command.
            raise Block('PHASE1_PROTOCOL_REJECTED')
        if type(sql) is str:
            try: sql = sql.encode('ascii')
            except UnicodeError: raise Block('PHASE1_SQL_REJECTED') from None
        if type(sql) is not bytes or sql != self.expected():
            raise Block('PHASE1_SQL_REJECTED')
        self.used += 1
        return sql
