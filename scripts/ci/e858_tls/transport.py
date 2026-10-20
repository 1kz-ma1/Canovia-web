"""Disposable-only transport. Fixed synthetic endpoints; no live target API."""
import hashlib
import json
from pathlib import Path
import socket
import ssl
import threading
import time

import pymysql
from pymysql.constants import CLIENT

HOST = 'mysql.synthetic.invalid'
DB = 'canovia_p0_ci'
USER = 'e858_tls_inspector'
PASSWORD = 'ci-only-tls-inspector'
ROOT_PASSWORD = 'ci-only-tls-root'
CELL = 128 * 1024
ROW = 256 * 1024
SECTION = 8 * 1024 * 1024
TOTAL = 16 * 1024 * 1024
SQL_SHA = '8383bc16fc8b35b8413a2a6a0b0c0784c9fa646ebab119017b97c2bd8d4333d1'


class Block(Exception):
    """Only constant codes may cross the harness boundary."""


def context(ca='/synthetic/ca.pem', mode='VERIFY_IDENTITY'):
    if mode != 'VERIFY_IDENTITY':
        raise Block('TLS_WEAKENING_REJECTED')
    value = ssl.create_default_context(cafile=ca)
    value.minimum_version = ssl.TLSVersion.TLSv1_2
    value.verify_mode = ssl.CERT_REQUIRED
    value.check_hostname = True
    return value


class StrictConnection(pymysql.connections.Connection):
    """No SSL-less authentication; bounded protocol reads, not buffered results."""
    def __init__(self, **kwargs):
        self.wire_bytes = 0
        self.connect_count = 0
        super().__init__(defer_connect=True, **kwargs)

    def connect(self, sock=None):
        if self.connect_count:
            raise Block('RECONNECT_REJECTED')
        self.connect_count += 1
        return super().connect(sock=sock)

    def _request_authentication(self):
        if not self.server_capabilities & CLIENT.SSL:
            raise Block('TLS_REQUIRED_NO_FALLBACK')
        return super()._request_authentication()

    def _read_bytes(self, count):
        if count > ROW:
            raise Block('WIRE_ROW_CAP')
        self.wire_bytes += count
        if self.wire_bytes > TOTAL:
            raise Block('WIRE_TOTAL_CAP')
        return super()._read_bytes(count)


def connection(role='inspector', fault=None, sock=None):
    """Closed test fixtures only; never takes host, port, password or CA input."""
    if role not in ('inspector', 'root', 'other') or fault not in (None, 'wrong_ca', 'wrong_host', 'expired'):
        raise Block('SYNTHETIC_SCOPE_REJECTED')
    host = {'wrong_host': 'mismatch.synthetic.invalid', 'expired': 'expired.synthetic.invalid'}.get(fault, HOST)
    ca = '/synthetic/wrong-ca.pem' if fault == 'wrong_ca' else '/synthetic/ca.pem'
    user, password = {'inspector': (USER, PASSWORD), 'root': ('root', ROOT_PASSWORD),
                      'other': ('e858_tls_other', 'ci-only-other')}[role]
    value = StrictConnection(host=host, port=3306, user=user, password=password,
        database=DB, ssl=context(ca), connect_timeout=2, read_timeout=10, write_timeout=10,
        charset='ascii', autocommit=True, cursorclass=pymysql.cursors.SSCursor)
    try:
        value.connect(sock=sock)
        if not isinstance(value._sock, ssl.SSLSocket) or not value._sock.cipher() or not value._sock.getpeercert():
            raise Block('TLS_NOT_VERIFIED')
        return value
    except BaseException:
        value.close()
        raise


def rows(conn, sql, args=None):
    """Private test observer/bootstrap only, never metadata ingress."""
    with conn.cursor() as cursor:
        cursor.execute(sql, args)
        return cursor.fetchall()


class Observer:
    """Independent synthetic observation channel. NOT a production role policy."""
    def __init__(self, role='root', fault=None):
        self.conn = connection(role)
        self.uuid = rows(self.conn, 'SELECT @@server_uuid')[0][0]
        self.fault = fault
        self.role = role

    def state(self, cid):
        if self.fault == 'unobservable' or self.role == 'other':
            raise Block('REMOTE_TERMINATION_UNVERIFIED')
        if rows(self.conn, 'SELECT @@server_uuid')[0][0] != self.uuid:
            raise Block('OBSERVER_SERVER_CHANGED')
        found = rows(self.conn, 'SELECT COMMAND FROM information_schema.PROCESSLIST WHERE ID=%s', (int(cid),))
        return dict(exists=bool(found), running=bool(found and found[0][0] != 'Sleep'))

    def gone(self, cid, seconds=2):
        until = time.monotonic() + seconds
        last = None
        error_code = None
        try:
            while time.monotonic() < until:
                value = self.state(cid); last = value
                if not value['exists']:
                    return dict(query_ended=True, connection_ended=True, verified=True, status='VERIFIED')
                time.sleep(.02)
        except Exception as error:
            error_code = error.args[0] if error.args and type(error.args[0]) is int else None
        return dict(query_ended=False, connection_ended=False, verified=False,
                    status='REMOTE_TERMINATION_UNVERIFIED', last_observation=last, observer_error_code=error_code)

    def terminate(self, cid):
        result = dict(query_request_failed=False, connection_request_failed=False)
        for operation, key in [('QUERY', 'query_request_failed'), ('CONNECTION', 'connection_request_failed')]:
            try:
                if self.fault == 'kill_query' and operation == 'QUERY': raise Block('TEST_REQUEST_FAILED')
                if self.fault == 'kill_connection' and operation == 'CONNECTION': raise Block('TEST_REQUEST_FAILED')
                rows(self.conn, 'KILL ' + operation + ' %s', (int(cid),))
            except pymysql.MySQLError as error:
                # 1094: independently verify disappearance; never assume success.
                if error.args[0] != 1094: result[key] = True
            except Exception:
                result[key] = True
        return dict(result, **self.gone(cid))

    def close(self):
        self.conn.close()


class Session:
    def __init__(self, observer, query_seconds=10, stream_seconds=120):
        if not 0 < query_seconds <= 10 or not 0 < stream_seconds <= 120:
            raise Block('LIMIT_INCREASE_REJECTED')
        self.observer = observer
        self.conn = connection()
        self.original = self.conn
        self.socket = self.conn._sock
        self.cid = self.conn.thread_id()
        self.query_seconds = query_seconds
        self.until = time.monotonic() + stream_seconds
        self.lock = threading.Lock()
        self.closed = False
        self.failed = False
        self.used = 0
        self.executions = 0
        self.identity = None
        self.ledger = None
        manifest = json.loads(Path('/synthetic/manifest.json').read_text())
        self.order, self.caps = manifest['order'], manifest['caps']
        self.bundle = {k: (Path('/synthetic/queries') / (k + '.sql')).read_bytes() for k in self.caps}
        actual = {k: hashlib.sha256(v).hexdigest() for k, v in self.bundle.items()}
        seal = hashlib.sha256(json.dumps(actual, sort_keys=True, separators=(',', ':')).encode()).hexdigest()
        if seal != SQL_SHA or manifest['sql_sha256'] != SQL_SHA:
            self.abort(); raise Block('SQL_BUNDLE_CHANGED')
        # Fixed test envelope; NOT inserted into immutable metadata SQL bundle.
        self._execute('SET SESSION max_execution_time=10000', 0)

    def _execute(self, sql, cap):
        remaining = self.until - time.monotonic()
        if remaining <= 0: raise Block('STREAM_TIMEOUT')
        limit = min(self.query_seconds, remaining)
        timeout_code = 'STREAM_TIMEOUT' if remaining <= self.query_seconds else 'QUERY_TIMEOUT'
        fired = threading.Event()
        def interrupt():
            fired.set()
            try: self.socket.shutdown(socket.SHUT_RDWR)
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
                try: self.socket.shutdown(socket.SHUT_RDWR)
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

    def read(self, key, sql=None):
        if self.closed or self.failed: raise Block('NEW_OWNER_GO_REQUIRED')
        if not self.lock.acquire(blocking=False):
            self.failed = True; raise Block('CONCURRENT_COMMAND_REJECTED')
        try:
            if key not in self.bundle or (sql is not None and sql != self.bundle[key]):
                raise Block('SQL_NOT_ALLOWLISTED')
            if self.executions >= len(self.order) or key != self.order[self.executions]:
                raise Block('QUERY_ORDER_REJECTED')
            if self.conn is not self.original or self.conn._sock is not self.socket or self.conn.thread_id() != self.cid:
                raise Block('SESSION_CHANGED')
            if self._execute('SELECT CONNECTION_ID()', 1).strip() != str(self.cid):
                raise Block('SESSION_CHANGED')
            value = self._execute(self.bundle[key].decode('ascii'), self.caps[key])
            if self._execute('SELECT CONNECTION_ID()', 1).strip() != str(self.cid):
                raise Block('SESSION_CHANGED')
            if key == 'identity':
                fields = value.rstrip('\n').split('\t')
                if len(fields) != 4 or fields[0] != DB or fields[1] != USER + '@%' or fields[3] != self.observer.uuid:
                    raise Block('IDENTITY_CHANGED')
                if self.identity is not None and value != self.identity: raise Block('IDENTITY_CHANGED')
                self.identity = value
            if key == 'grants':
                from p0_mysql_metadata_grants_guard import verify_grants
                if not verify_grants(value, DB): raise Block('MINIMAL_GRANTS_BLOCK')
            if key == 'ledger':
                if self.ledger is not None and value != self.ledger: raise Block('LEDGER_CHANGED')
                self.ledger = value
            self.executions += 1
            return value
        except Exception:
            self.failed = True
            raise
        finally:
            self.lock.release()

    def collect(self):
        values = {}
        for key in self.order: values[key] = self.read(key)
        return values

    def finish(self):
        self.closed = True
        self.conn.close()
        return self.observer.gone(self.cid)

    def abort(self):
        self.closed = True; self.failed = True
        try: self.conn.close()
        except Exception: pass
        return self.observer.terminate(self.cid)
