"""Shared bounded driver/stream kernel. No fixture, target or connection entry."""
import socket
import threading
import time
import pymysql
from pymysql.constants import CLIENT

CELL=128*1024
ROW=256*1024
SECTION=8*1024*1024
TOTAL=16*1024*1024
PREAMBLE=16*1024
SQL_SHA='8383bc16fc8b35b8413a2a6a0b0c0784c9fa646ebab119017b97c2bd8d4333d1'

class Block(Exception):
    """Only constant codes may cross the harness boundary."""

class StrictConnection(pymysql.connections.Connection):
    """No SSL-less authentication; bounded protocol reads, not buffered results."""
    def __init__(self, **kwargs):
        self.wire_bytes = 0
        self.connect_count = 0
        self.authenticated = False
        self.preamble_bytes = 0
        super().__init__(defer_connect=True, **kwargs)

    def connect(self, sock=None):
        raise Block('LIVE_CONNECTION_DISABLED')

    def _request_authentication(self):
        if not self.server_capabilities & CLIENT.SSL:
            raise Block('TLS_REQUIRED_NO_FALLBACK')
        value = super()._request_authentication()
        self.authenticated = True
        return value

    def _read_bytes(self, count):
        if not self.authenticated:
            self.preamble_bytes += count
            if self.preamble_bytes > PREAMBLE: raise Block('AUTH_PREAMBLE_CAP')
        if count > ROW:
            raise Block('WIRE_ROW_CAP')
        self.wire_bytes += count
        if self.wire_bytes > TOTAL:
            raise Block('WIRE_TOTAL_CAP')
        return super()._read_bytes(count)

def execute(self, sql, cap):
        remaining = self.until - time.monotonic()
        if remaining <= 0: raise Block('STREAM_TIMEOUT')
        limit = min(self.query_seconds, remaining)
        timeout_code = 'STREAM_TIMEOUT' if remaining <= self.query_seconds else 'QUERY_TIMEOUT'
        fired = threading.Event()
        def interrupt():
            fired.set()
            try: self.cancel_socket.shutdown(socket.SHUT_RDWR)
            except OSError: pass
        timer = threading.Timer(limit, interrupt)
        timer.start()
        raw = bytearray()
        cursor = self.conn.cursor()
        try:
            try:
                cursor.execute(sql)
                for number, row in enumerate(cursor):
                    if number >= cap: raise Block('ROW_COUNT_CAP')
                    cells = [('NULL' if c is None else str(c)).encode('ascii') for c in row]
                    if any(len(c) > CELL for c in cells): raise Block('CELL_CAP')
                    line = b'\t'.join(cells) + b'\n'
                    if len(line) > ROW: raise Block('ROW_CAP')
                    if any(c < 32 and c not in (9, 10) or c == 127 for c in line): raise Block('OUTPUT_REJECTED')
                    self.used += len(line)
                    if len(raw) + len(line) > SECTION or self.used > TOTAL: raise Block('BYTE_CAP')
                    raw.extend(line)
            except Exception:
                # Do not let SSCursor.close drain an unbounded rejected result.
                try: self.cancel_socket.shutdown(socket.SHUT_RDWR)
                except OSError: pass
                raise
            if fired.is_set(): raise Block(timeout_code)
            return raw.decode('ascii')
        except Exception:
            if fired.is_set(): raise Block(timeout_code) from None
            raise
        finally:
            timer.cancel(); timer.join(.5)
            try: cursor.close()
            except Exception: pass
