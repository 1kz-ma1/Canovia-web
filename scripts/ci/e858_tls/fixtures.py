"""Runs ONLY inside the owned internal Docker network; all SQL below is synthetic."""
import contextlib
import json
import multiprocessing
import os
from pathlib import Path
import socket
import ssl
import threading
import time
import traceback

from transport import (Block, Observer, Session, connection, context, rows as client_rows,
                       DB, USER, PASSWORD, ROOT_PASSWORD, CELL)


def oracle_process(pipe):
    """Separate process: data-client SSL faults cannot close its TLS objects."""
    observer = None
    try:
        observer = Observer()
        pipe.send((True, (observer.uuid, observer.conn._sock.getsockname())))
        while True:
            operation, args = pipe.recv()
            if operation == 'close':
                observer.close(); pipe.send((True, None)); break
            try:
                if operation == 'rows': result = client_rows(observer.conn, *args)
                elif operation in ('state', 'gone', 'terminate'): result = getattr(observer, operation)(*args)
                else: raise Block('ORACLE_OPERATION_REJECTED')
                pipe.send((True, result))
            except Exception as error:
                pipe.send((False, dict(type=type(error).__name__, mysql_code=
                    error.args[0] if error.args and type(error.args[0]) is int else None)))
    finally:
        if observer: observer.close()
        pipe.close()


class Oracle:
    """Private fixture RPC, not a network service or production control channel."""
    def __init__(self):
        ctx = multiprocessing.get_context('spawn')
        self.pipe, child = ctx.Pipe()
        self.process = ctx.Process(target=oracle_process, args=(child,), daemon=True)
        self.process.start(); child.close()
        self.uuid, self.local = self.receive()
        self.conn = self

    def receive(self):
        if not self.pipe.poll(5): raise Block('ORACLE_OBSERVATION_UNVERIFIED')
        ok, result = self.pipe.recv()
        if not ok: raise Block('ORACLE_CONTROL_FAILED', result)
        return result

    def call(self, operation, *args):
        self.pipe.send((operation, args)); return self.receive()

    def state(self, cid): return self.call('state', cid)
    def gone(self, cid): return self.call('gone', cid)
    def terminate(self, cid): return self.call('terminate', cid)

    def close(self):
        try: self.call('close')
        finally:
            self.process.join(3)
            if self.process.is_alive(): self.process.kill(); self.process.join(2)
            self.pipe.close()


def rows(conn, sql, args=None):
    return conn.call('rows', sql, args) if isinstance(conn, Oracle) else client_rows(conn, sql, args)


def require(value, facts=None):
    if not value: raise Block('ISOLATED_ASSERTION_FAILED', facts)


def wait_running(observer, cid):
    until = time.monotonic() + 3
    while time.monotonic() < until:
        if observer.state(cid)['running']: return True
        time.sleep(.01)
    raise Block('REMOTE_QUERY_NOT_OBSERVED')


def long_client(pipe, role='inspector'):
    c = connection(role)
    pipe.send(c.thread_id())
    try: rows(c, 'SELECT SLEEP(30)')
    except Exception: pass
    finally: c.close(); pipe.close()


class Relay:
    """Raw TCP fault fixture; cannot decrypt TLS, fixed upstream only."""
    def __init__(self, blackhole=False, strip_ssl=False):
        self.listener = socket.socket()
        self.listener.bind(('127.0.0.1', 0)); self.listener.listen(1)
        self.port = self.listener.getsockname()[1]
        self.blackhole = blackhole; self.strip_ssl = strip_ssl
        self.client_payload = 0; self.server_cid = None
        self.client = None; self.server = None
        self.stop = threading.Event()
        self.thread = threading.Thread(target=self.run, daemon=True); self.thread.start()

    def run(self):
        try:
            self.client, _ = self.listener.accept()
            if self.blackhole:
                self.stop.wait(2); return
            self.server = socket.create_connection(('mysql.synthetic.invalid', 3306), timeout=2)
            if self.strip_ssl:
                def exact(count):
                    data = b''
                    while len(data) < count:
                        part = self.server.recv(count - len(data))
                        if not part: raise OSError()
                        data += part
                    return data
                header = exact(4); size = int.from_bytes(header[:3], 'little')
                if size > 4096: raise OSError()
                data = bytearray(exact(size)); end = data.index(0, 1)
                self.server_cid = int.from_bytes(data[end+1:end+5], 'little')
                caps = end + 1 + 4 + 8 + 1
                data[caps + 1] &= ~8  # CLIENT.SSL bit11 in plaintext greeting.
                self.client.sendall(header + data)
            import select
            while not self.stop.is_set():
                ready, _, _ = select.select([self.client, self.server], [], [], .05)
                for sock in ready:
                    data = sock.recv(65536)
                    if not data: return  # Keep upstream fd open for parent observation.
                    if sock is self.client: self.client_payload += len(data)
                    (self.server if sock is self.client else self.client).sendall(data)
        except (OSError, ValueError): pass

    def close(self):
        self.stop.set()
        for sock in (self.client, self.server, self.listener):
            if sock:
                try: sock.shutdown(socket.SHUT_RDWR)
                except OSError: pass
                sock.close()
        self.thread.join(1)


def run():
    manifest = json.loads(Path('/synthetic/manifest.json').read_text())
    admin = Oracle()
    version = rows(admin.conn, 'SELECT VERSION()')[0][0]
    require(version.startswith(manifest['series'] + '.'))
    # Minimal structure fixture, NOT a 94-migration deployment/rehearsal.
    rows(admin.conn, 'CREATE TABLE migrations(id BIGINT PRIMARY KEY AUTO_INCREMENT, migration VARCHAR(255), batch INT)')
    for name in manifest['baseline_names']:
        rows(admin.conn, 'INSERT INTO migrations(migration,batch) VALUES(%s,1)', (name,))
    rows(admin.conn, 'CREATE TABLE tls_fixture_parent(id BIGINT PRIMARY KEY)')
    rows(admin.conn, 'CREATE TABLE tls_fixture_child(id BIGINT PRIMARY KEY, parent_id BIGINT, FOREIGN KEY(parent_id) REFERENCES tls_fixture_parent(id))')
    rows(admin.conn, 'CREATE TRIGGER tls_fixture_trigger BEFORE UPDATE ON tls_fixture_parent FOR EACH ROW SET NEW.id=OLD.id')
    rows(admin.conn, "CREATE USER 'e858_tls_inspector'@'%' IDENTIFIED BY 'ci-only-tls-inspector' REQUIRE SSL")
    rows(admin.conn, "CREATE USER 'e858_tls_other'@'%' IDENTIFIED BY 'ci-only-other' REQUIRE SSL")
    rows(admin.conn, "GRANT REFERENCES ON canovia_p0_ci.* TO 'e858_tls_other'@'%'")
    rows(admin.conn, "GRANT REFERENCES ON canovia_p0_ci.* TO 'e858_tls_inspector'@'%'")
    rows(admin.conn, "GRANT SELECT ON canovia_p0_ci.migrations TO 'e858_tls_inspector'@'%'")
    reports = {}
    current = ['BOOTSTRAP']
    def case(label, func):
        current[0] = label
        require(rows(admin.conn, 'SELECT @@server_uuid')[0][0] == admin.uuid)
        value = func()
        require(rows(admin.conn, 'SELECT @@server_uuid')[0][0] == admin.uuid)
        reports[label] = value or dict(verified=True)

    def normal():
        s = Session(admin)
        data = s.collect()
        require(s.executions == 17 and s.conn.connect_count == 1)
        require(s.finish()['verified'])
        Path('/out/snapshot.json').write_text(json.dumps(data))
        os.chmod('/out/snapshot.json', 0o600)
        return dict(verified=True, statements=17, tls_session_count=1, remote_connection_ended=True)

    def tcp_shutdown_isolation():
        c = connection(); cid = c.thread_id()
        require(c._sock.getsockname() != admin.local)
        c._sock.shutdown(socket.SHUT_RDWR)
        c.close()
        require(rows(admin.conn, 'SELECT @@server_uuid')[0][0] == admin.uuid)
        require(admin.gone(cid)['verified'])
        return dict(verified=True, unrelated_tls_connection_preserved=True)

    def tls_negative(fault):
        try: c = connection(fault=fault)
        except Exception as error:
            original = getattr(error, 'original_exception', error)
            require(isinstance(original, ssl.SSLCertVerificationError))
            expected = {'wrong_ca': (18, 19, 20, 21), 'wrong_host': (62,), 'expired': (10,)}[fault]
            require(original.verify_code in expected)
            return dict(blocked=True, certificate_validation_failed=True)
        c.close(); raise Block('TLS_NEGATIVE_DID_NOT_BLOCK')

    def policy_negative(kind):
        s = Session(admin)
        try:
            if kind == 'sql': s.read('identity', b'SELECT SLEEP(30)')
            elif kind == 'order': s.read('table')
            elif kind == 'swap':
                s.read('identity'); replacement = connection(); s.conn = replacement
                try: s.read('grants')
                finally:
                    if replacement.open: replacement.close()
                    if s.original.open: s.original.close()
            elif kind == 'postflight':
                for key in s.order[:15]: s.read(key)
                rows(s.conn, 'USE information_schema'); s.read('identity')
            elif kind == 'disconnect':
                s.read('identity'); s.cancel_socket.shutdown(socket.SHUT_RDWR); s.read('grants')
            elif kind == 'reconnect':
                s.conn.close(); s.conn.ping(reconnect=True)
            elif kind == 'concurrent':
                s.lock.acquire()
                try: s.read('identity')
                finally: s.lock.release()
            elif kind == 'cell': s._execute('SELECT REPEAT(\'x\',131073)', 1)
            elif kind == 'row': s._execute('SELECT REPEAT(\'x\',131072),REPEAT(\'x\',131072)', 1)
        except Exception as error:
            expected = dict(sql='SQL_NOT_ALLOWLISTED', order='QUERY_ORDER_REJECTED',
                swap='SESSION_CHANGED', postflight='IDENTITY_CHANGED', reconnect='RECONNECT_REJECTED',
                concurrent='CONCURRENT_COMMAND_REJECTED', cell='CELL_CAP')
            if kind in expected: require(type(error) is Block and str(error) == expected[kind])
            elif kind == 'row': require(type(error) is Block and str(error) in ('WIRE_ROW_CAP','ROW_CAP'))
            elif kind == 'disconnect': require(error.args and error.args[0] in (0,2006,2013))
            proof = s.abort(); require(proof['verified'], proof)
            try: s.read('identity')
            except Block: return dict(blocked=True, remote_connection_ended=True)
        finally:
            s.abort()
        raise Block('POLICY_NEGATIVE_DID_NOT_BLOCK')

    def privilege(kind):
        if kind == 'excess': rows(admin.conn, "GRANT SELECT ON canovia_p0_ci.* TO 'e858_tls_inspector'@'%'")
        else: rows(admin.conn, "REVOKE SELECT ON canovia_p0_ci.migrations FROM 'e858_tls_inspector'@'%'")
        try:
            s = Session(admin)
            try:
                s.read('identity'); s.read('grants')
            except Block as error:
                require(str(error) == 'MINIMAL_GRANTS_BLOCK')
                require(s.executions == 1 and s.abort()['verified'])
                return dict(blocked_before_metadata=True)
            finally: s.abort()
            raise Block('PRIVILEGE_NEGATIVE_DID_NOT_BLOCK')
        finally:
            if kind == 'excess': rows(admin.conn, "REVOKE SELECT ON canovia_p0_ci.* FROM 'e858_tls_inspector'@'%'")
            else: rows(admin.conn, "GRANT SELECT ON canovia_p0_ci.migrations TO 'e858_tls_inspector'@'%'")

    def timeout(kind):
        s = Session(admin, query_seconds=.25)
        if kind == 'stream': s.until = min(s.until, time.monotonic() + .12)
        try: s._execute('SELECT SLEEP(30)', 1)
        except Block as error:
            require(str(error) == ('STREAM_TIMEOUT' if kind == 'stream' else 'QUERY_TIMEOUT'))
            state = admin.state(s.cid)
            proof = s.abort(); require(proof['verified'])
            return dict(blocked=True, remote_running_after_local_timeout=state['running'], **proof)
        finally: s.abort()
        raise Block('TIMEOUT_DID_NOT_BLOCK')

    def process_kill():
        mp = multiprocessing.get_context('spawn')  # Never inherit another TLS socket.
        parent, child = mp.Pipe()
        process = mp.Process(target=long_client, args=(child,))
        process.start(); child.close()
        require(parent.poll(5)); cid = parent.recv(); wait_running(admin, cid)
        process.kill(); process.join(2); require(not process.is_alive())
        before = admin.state(cid)
        proof = admin.terminate(cid); require(proof['verified'])
        parent.close()
        return dict(client_exit_confirmed=True, remote_running_after_sigkill=before['running'], **proof)

    def relay_disconnect():
        relay = Relay(); sock = socket.create_connection(('127.0.0.1', relay.port), timeout=2)
        c = connection(sock=sock); cid = c.thread_id()
        thread = threading.Thread(target=lambda: swallow(lambda: rows(c, 'SELECT SLEEP(30)')))
        thread.start(); wait_running(admin, cid)
        relay.client.shutdown(socket.SHUT_RDWR); relay.client.close()
        thread.join(2); require(not thread.is_alive())
        before = admin.state(cid); proof = admin.terminate(cid); require(proof['verified'])
        relay.close(); c.close()
        return dict(client_link_lost=True, remote_running_after_disconnect=before['running'], **proof)

    def handshake_timeout():
        before = rows(admin.conn, "SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE USER='e858_tls_inspector'")[0][0]
        relay = Relay(blackhole=True)
        sock = socket.create_connection(('127.0.0.1', relay.port), timeout=1)
        from transport import StrictConnection
        c = StrictConnection(host='mysql.synthetic.invalid', user=USER, password=PASSWORD,
                             database=DB, ssl=context(), read_timeout=.2, connect_timeout=1)
        begin = time.monotonic()
        try: c.connect(sock=sock)
        except Exception:
            require(time.monotonic() - begin < 2)
            require(rows(admin.conn, "SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE USER='e858_tls_inspector'")[0][0] == before)
            return dict(blocked=True, phase='MYSQL_GREETING_TIMEOUT', remote_mysql_session_created=False)
        finally: c.close(); relay.close()
        raise Block('HANDSHAKE_TIMEOUT_DID_NOT_BLOCK')

    def no_ssl_fallback():
        relay = Relay(strip_ssl=True)
        sock = socket.create_connection(('127.0.0.1', relay.port), timeout=1)
        try:
            try: c = connection(sock=sock)
            except Block as error:
                require(str(error) == 'TLS_REQUIRED_NO_FALLBACK')
                require(relay.client_payload == 0)
                cid = relay.server_cid
                relay.close(); require(cid is not None and admin.gone(cid)['verified'])
                return dict(blocked_before_password=True, remote_connection_ended=True)
            c.close(); raise Block('TLS_FALLBACK_ACCEPTED')
        finally: relay.close()

    def failed_cleanup(kind):
        observer = Observer(fault=kind)
        c = connection(); cid = c.thread_id()
        thread = threading.Thread(target=lambda: swallow(lambda: rows(c, 'SELECT SLEEP(30)')))
        thread.start(); wait_running(admin, cid)
        if kind == 'observer_disconnect': observer.conn._sock.shutdown(socket.SHUT_RDWR)
        proof = observer.terminate(cid)
        if kind == 'kill_query': require(proof['query_request_failed'] and proof['verified'])
        else: require(not proof['verified'])
        # Unobservable / still-live connections remain UNVERIFIED even though
        # the harness subsequently performs independent emergency cleanup.
        close_client(c, thread)
        emergency = admin.terminate(cid); require(emergency['verified'])
        observer.close()
        return dict(expected_block=True, transport_proof=proof,
                    emergency_fixture_cleanup_verified=True)

    def permission_denial():
        c = connection('root'); cid = c.thread_id()
        other = connection('other'); failures = []
        thread = threading.Thread(target=lambda: swallow(lambda: rows(c, 'SELECT SLEEP(30)')))
        thread.start(); wait_running(admin, cid)
        try:
            for command in ('KILL QUERY %s', 'KILL CONNECTION %s'):
                try: rows(other, command, (cid,))
                except Exception as error: failures.append(error.args[0] == 1095)
            require(failures == [True, True] and admin.state(cid)['running'])
            invisible = Observer(role='other')
            require(not invisible.gone(cid, .1)['verified']); invisible.close()
            return dict(query_denied=True, connection_denied=True, still_running_observed=True,
                        invisible_observer_rejected=True)
        finally:
            close_client(c, thread)
            require(admin.terminate(cid)['verified']); other.close()

    def same_principal_cleanup():
        own = Observer(role='inspector')
        for operation in ('QUERY', 'CONNECTION'):
            c = connection(); cid = c.thread_id()
            def active():
                try: rows(c, 'SELECT SLEEP(30)')
                except Exception: pass
                finally: c.close()
            thread = threading.Thread(target=active)
            thread.start(); wait_running(admin, cid); require(own.state(cid)['running'])
            rows(own.conn, 'KILL ' + operation + ' %s', (cid,))
            thread.join(2); require(not thread.is_alive())
            require(own.gone(cid)['verified'] and admin.gone(cid)['verified'])
        own.close()
        return dict(no_privileges_added=True, own_session_observed=True,
                    own_query_kill_verified=True, own_connection_kill_verified=True)

    def server_timeout():
        c = connection(); cid = c.thread_id()
        rows(c, 'SET SESSION max_execution_time=150')
        begin = time.monotonic(); error_code = None
        try:
            rows(c, 'SELECT SUM(LENGTH(a.COLUMN_NAME)+LENGTH(b.COLUMN_NAME)+LENGTH(d.COLUMN_NAME)) FROM information_schema.columns a CROSS JOIN information_schema.columns b CROSS JOIN information_schema.columns d')
        except Exception as error: error_code = error.args[0]
        elapsed = time.monotonic() - begin
        state = admin.state(cid)
        require(error_code == 3024 and elapsed < 3 and not state['running'])
        c.close(); require(admin.gone(cid)['verified'])
        return dict(server_error_3024=True, remote_query_ended=True, connection_required_separate_close=True)

    try:
        case('TCP_SHUTDOWN_ISOLATION', tcp_shutdown_isolation)
        case('TLS_VALID_SAME_SESSION', normal)
        for fault in ('wrong_ca', 'wrong_host', 'expired'): case('TLS_' + fault.upper(), lambda f=fault: tls_negative(f))
        def weak():
            try: context(mode='REQUIRED')
            except Block: return dict(blocked_before_connect=True)
            raise Block('WEAK_TLS_ACCEPTED')
        case('TLS_WEAKENING', weak)
        case('TLS_NO_ADVERTISEMENT_FALLBACK', no_ssl_fallback)
        for fault in ('sql', 'order', 'swap', 'postflight', 'disconnect', 'reconnect', 'concurrent', 'cell', 'row'):
            case('POLICY_' + fault.upper(), lambda f=fault: policy_negative(f))
        for kind in ('excess', 'missing'): case('GRANTS_' + kind.upper(), lambda k=kind: privilege(k))
        case('QUERY_TIMEOUT', lambda: timeout('query'))
        case('STREAM_TIMEOUT', lambda: timeout('stream'))
        case('CLIENT_SIGKILL', process_kill)
        case('COMMUNICATION_LOSS', relay_disconnect)
        case('CONNECT_GREETING_TIMEOUT', handshake_timeout)
        for kind in ('kill_query', 'kill_connection', 'unobservable', 'observer_disconnect'):
            case('REMOTE_' + kind.upper(), lambda k=kind: failed_cleanup(k))
        case('REMOTE_PERMISSION_DENIED', permission_denial)
        case('SAME_PRINCIPAL_TERMINATION', same_principal_cleanup)
        case('SERVER_SELECT_TIMEOUT', server_timeout)
        remaining = rows(admin.conn, "SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE USER IN ('e858_tls_inspector','e858_tls_other')")[0][0]
        require(remaining == 0)
        return dict(result='ISOLATED_SYNTHETIC_VERIFIED', version=version, cases=reports,
                    synthetic_sessions_remaining=0, real_aiven_connected=False,
                    release_authorized=False, strict_block_waived=False)
    except BaseException as error:
        private_error(error)
        return dict(result='BLOCK', code='ISOLATED_CASE_FAILED', case=current[0],
                    real_aiven_connected=False, release_authorized=False)
    finally:
        admin.close()


def swallow(func):
    try: func()
    except Exception: pass


def close_client(c, thread):
    try:
        if c._sock: c._sock.shutdown(socket.SHUT_RDWR)
    except OSError: pass
    thread.join(2); require(not thread.is_alive())
    if c.open: c.close()


def private_error(error):
    # No error text / SQL / addresses / credentials, even in private diagnostics.
    Path('/out/private-error.json').write_text(json.dumps(dict(
        type=type(error).__name__, mysql_code=error.args[0] if error.args and type(error.args[0]) is int else None,
        details=error.args[1] if len(error.args) > 1 and type(error.args[1]) is dict else None,
        frames=[(Path(f.filename).name, f.lineno) for f in traceback.extract_tb(error.__traceback__)])))


if __name__ == '__main__':
    try: report = run()
    except BaseException as error:
        private_error(error)
        report = dict(result='BLOCK', code='ISOLATED_BOOTSTRAP_FAILED', real_aiven_connected=False)
    print(json.dumps(report, sort_keys=True))
    raise SystemExit(0 if report['result'] == 'ISOLATED_SYNTHETIC_VERIFIED' else 2)
