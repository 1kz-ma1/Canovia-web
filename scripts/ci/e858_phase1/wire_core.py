"""Fixture-free Phase1 wire/budget/channel kernel. No live constructor."""
import time
import struct
from e858_tls.stream_core import Block,StrictConnection,execute,TOTAL
from .protocol import Gate

class Wire(StrictConnection):
    """No connector; pinned protocol/read/close methods only."""


    def _read_bytes(self,count):
        remaining=self.gate.deadline-time.monotonic()
        if remaining<=0:raise Block('STREAM_TIMEOUT')
        self.gate.budget.wire_bytes+=count
        if self.gate.budget.wire_bytes>TOTAL:raise Block('WIRE_TOTAL_CAP')
        self._read_timeout=min(10,remaining)
        return super()._read_bytes(count)

    def _write_bytes(self,data):
        remaining=self.gate.deadline-time.monotonic()
        if remaining<=0:raise Block('STREAM_TIMEOUT')
        self._write_timeout=min(10,remaining)
        return super()._write_bytes(data)

    def _execute_command(self, command, sql):
        sql = self.gate.claim(command, sql)
        result = super()._execute_command(command, sql)
        self.gate.sent.append(sql.decode('ascii'))
        return result

    def graceful_close(self):
        # PyMySQL close() suppresses COM_QUIT write failure. Record successful
        # write explicitly; neither this nor local close proves remote absence.
        if self._closed or self._sock is None or self.quit_written:
            raise Block('PHASE1_CLOSE_UNVERIFIED')
        self._closed = True
        try:
            self._write_bytes(struct.pack('<iB',1,1))  # COM_QUIT, not SQL.
            self.quit_written = True
        except Exception:
            raise Block('PHASE1_CLOSE_UNVERIFIED') from None
        finally:
            self._force_close()

    def close(self):
        # Error cleanup is local-only, never emits a hidden QUIT or KILL.
        self._force_close()

class SharedBudget:
    def __init__(self):
        self.until = time.monotonic()+120
        self.used = 0
        self.wire_bytes = 0

class Channel:
    """Reuse #806 bounded stream reader without its observer/abort interface."""

    @property
    def used(self): return self.budget.used

    @used.setter
    def used(self,value): self.budget.used = value

    def check_owned(self):
        if (self.conn is not self.original or self.conn.thread_id()!=self.cid
                or self.conn.connect_count!=1 or type(self.cid) is not int or not 0<self.cid<2**32
                or (not self.closed and (self.conn._sock is not self.tls or not self.conn.open))):
            raise Block('PHASE1_CID_CONTINUITY_UNVERIFIED')

    def read(self, sql, cap=1):
        if self.closed:raise Block('PHASE1_CHANNEL_CLOSED')
        self.check_owned()
        return execute(self,sql.decode('ascii'),cap)

    def normal_close(self):
        self.check_owned()
        self.conn.graceful_close()
        self.closed = True
        self.cancel_socket.close()

    def drop(self):
        self.conn.close()
        self.cancel_socket.close()
        self.closed = True
