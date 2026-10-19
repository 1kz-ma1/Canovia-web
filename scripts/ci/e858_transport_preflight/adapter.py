"""Closed synthetic adapters. No hostname, password, SQL or command arguments."""
import base64
import json
import os
import selectors
import signal
import subprocess
import sys
import time
from pathlib import Path
import zlib

from e858_audit import core as original
from e858_audit.transport import Budget
from e858_transport_preflight.core import Block, environment_guard, plan
from p0_mysql_metadata_grants_guard import verify_grants

# Constant fake program; user-provided Python/shell/argv is never evaluated.
FAKE = "import os,sys,base64,zlib;sys.stdin.buffer.read();sys.stdout.buffer.write(zlib.decompress(base64.b64decode(os.environ['SYNTHETIC_PAYLOAD'])))"
# Trusted OS installation paths only; never search an ambient PATH.
DOCKER = next((p for p in ('/usr/bin/docker', '/usr/local/bin/docker') if Path(p).is_file()), '/usr/bin/docker')
TARGETS = {'8.0': ('canovia-integration-80', '13380'),
           '8.4': ('canovia-integration-84', '13384')}
# Closed, childless fault fixtures. No arbitrary source receives this certificate.
FAULT_PROGRAMS = ('import time;time.sleep(2)',
                  'import sys;sys.stderr.write("SYNTHETIC_SECRET")',
                  'import os,signal;os.kill(os.getpid(),signal.SIGTERM)')


def _contract(command, env):
    if (command == [sys.executable, '-I', '-B', '-c', FAKE]
            and isinstance(env, dict) and isinstance(env.get('SYNTHETIC_PAYLOAD'), str)
            and env == dict(child_env(), SYNTHETIC_PAYLOAD=env['SYNTHETIC_PAYLOAD'])
            and len(env['SYNTHETIC_PAYLOAD']) <= 65536):
        return 'CHILDLESS_FAKE'
    if (any(command == [sys.executable, '-I', '-c', source] for source in FAULT_PROGRAMS)
            and env == child_env()):
        return 'CHILDLESS_FAKE'
    if env == child_env() and any(command == _disposable_command(s) for s in TARGETS):
        return 'DISPOSABLE_DOCKER'
    return 'UNPROVEN_PROCESS_TREE'


def _cleanup(process, contract, completed):
    """Return a fixed failure code, never an exception/raw error. Bounded grace.

    Only sealed childless Python can prove its whole tree by direct-child wait.
    Docker exec success proves remote exit; killing its local client does not.
    """
    permission_denied = False
    try:
        if process.poll() is None:
            try:
                os.killpg(process.pid, signal.SIGKILL)
            except ProcessLookupError:
                pass
            except PermissionError:
                permission_denied = True
                # Best effort even for unproven trees; this never certifies them.
                process.kill()
        if type(process.wait(timeout=0.5)) is not int:
            return 'PROCESS_TERMINATION_UNVERIFIED'
    except (Exception, KeyboardInterrupt):
        return 'PROCESS_TERMINATION_UNVERIFIED'
    if contract == 'CHILDLESS_FAKE':
        # poll/wait confirmed exit, and exact -I program+env cannot spawn children.
        return None
    if permission_denied:
        return 'PROCESS_GROUP_TERMINATION_UNVERIFIED'
    remaining = False
    until = time.monotonic() + 0.25
    try:
        while True:
            try:
                os.killpg(process.pid, 0)
            except ProcessLookupError:
                break
            remaining = True
            if time.monotonic() >= until:
                return 'PROCESS_GROUP_TERMINATION_UNVERIFIED'
            os.killpg(process.pid, signal.SIGKILL)
            time.sleep(0.01)
    except (Exception, KeyboardInterrupt):
        return 'PROCESS_GROUP_TERMINATION_UNVERIFIED'
    if contract == 'DISPOSABLE_DOCKER' and not completed:
        return 'DISPOSABLE_REMOTE_TERMINATION_UNVERIFIED'
    if remaining:
        return 'UNEXPECTED_DESCENDANT_DETECTED'
    return None


def bounded_query(command, query_id, budget, *, seconds=10, max_bytes=8*1024*1024,
                  row_bytes=256*1024, cell_bytes=128*1024, sql=None, env=None):
    """#796 reader, preserving #795's SQL/stream caps without editing its code."""
    bundle = original.queries()
    if query_id not in bundle or (sql is not None and sql != bundle[query_id]):
        raise Block('SQL_NOT_ALLOWLISTED')
    if (seconds > 10 or max_bytes > 8*1024*1024 or row_bytes > 256*1024
            or cell_bytes > 128*1024 or budget.maximum > 16*1024*1024):
        raise Block('STREAM_LIMIT_OVERRIDE_REJECTED')
    if time.monotonic() >= budget.until:
        raise Block('QUERY_TIMEOUT')
    contract = _contract(command, env)
    process = None; problem = None; cleanup = None; result = None; completed = False
    selector = selectors.DefaultSelector()
    raw = bytearray(); line = bytearray(); rows = 0
    try:
        process = subprocess.Popen(command, stdin=subprocess.PIPE, stdout=subprocess.PIPE,
            stderr=subprocess.PIPE, start_new_session=True, env=env)
        process.stdin.write(bundle[query_id]); process.stdin.close()
        for pipe, tag in [(process.stdout, 'out'), (process.stderr, 'error')]:
            os.set_blocking(pipe.fileno(), False)
            selector.register(pipe, selectors.EVENT_READ, tag)
        until = min(time.monotonic()+seconds, budget.until)
        while selector.get_map():
            remaining = until-time.monotonic()
            if remaining <= 0:
                raise Block('QUERY_TIMEOUT')
            for key, _ in selector.select(min(remaining, 0.05)):
                chunk = os.read(key.fd, 4096)
                if not chunk:
                    selector.unregister(key.fileobj); continue
                budget.used += len(chunk)
                if key.data == 'error':
                    raise Block('PRIVATE_CLIENT_FAILURE')
                if budget.used > budget.maximum or len(raw)+len(chunk) > max_bytes:
                    raise Block('QUERY_BYTES_EXCEEDED')
                if not chunk.isascii() or any(c < 32 and c not in (9, 10) or c == 127 for c in chunk):
                    raise Block('PRIVATE_OUTPUT_REJECTED')
                raw.extend(chunk)
                for part in chunk.splitlines(keepends=True):
                    line.extend(part)
                    if len(line) > row_bytes or any(len(c) > cell_bytes for c in line.split(b'\t')):
                        raise Block('QUERY_FIELD_EXCEEDED')
                    if line.endswith(b'\n'):
                        rows += 1
                        if rows > original.CAPS[query_id]:
                            raise Block('QUERY_ROWS_EXCEEDED')
                        line.clear()
        if process.wait(timeout=max(0.01, until-time.monotonic())) != 0:
            raise Block('PRIVATE_CLIENT_FAILURE')
        if line or (not raw and query_id not in ('check', 'trigger', 'foreign_key', 'semantic_foreign_key')):
            raise Block('PRIVATE_OUTPUT_REJECTED')
        result = raw.decode('ascii'); completed = True
    except Block as error:
        problem = error
    except subprocess.TimeoutExpired:
        problem = Block('QUERY_TIMEOUT')
    except (Exception, KeyboardInterrupt):
        problem = Block('PRIVATE_CLIENT_FAILURE')
    finally:
        try:
            selector.close()
        except (Exception, KeyboardInterrupt):
            cleanup = 'STREAM_CLEANUP_UNVERIFIED'
        if process is not None:
            cleanup = _cleanup(process, contract, completed) or cleanup
            for pipe in (process.stdin, process.stdout, process.stderr):
                try:
                    if pipe is not None and not pipe.closed:
                        pipe.close()
                except (Exception, KeyboardInterrupt):
                    cleanup = cleanup or 'STREAM_CLEANUP_UNVERIFIED'
    if cleanup:
        error = Block(cleanup)
        error.primary_code = problem.code if problem else 'NONE'
        raise error from None
    if problem:
        raise problem from None
    return result


def child_env():
    return {'PATH': '/usr/bin:/bin', 'HOME': '/nonexistent', 'LANG': 'C', 'LC_ALL': 'C'}


def mysql_options(series):
    # 8.0 has no --no-login-paths. Its login-file path is instead fixed to an
    # absent synthetic path in Docker env. This is an engine contract, no retry.
    return ['mysql', '--no-defaults'] + (['--no-login-paths'] if series == '8.4' else [])


def docker_client_prefix(name, password):
    # Only fixture constants constructed below/inside disposable.py use this.
    if name not in {v[0] for v in TARGETS.values()} or password not in (
            'ci-only-root-password', 'ci-only-e858-inspector'):
        raise Block('DISPOSABLE_TARGET_REJECTED')
    return [DOCKER, 'exec', '-i', '-e', 'MYSQL_PWD=' + password, '-e', 'HOME=/nonexistent',
            '-e', 'MYSQL_TEST_LOGIN_FILE=/nonexistent/client-login', name]


def _disposable_command(series):
    name, _ = TARGETS[series]
    return docker_client_prefix(name, 'ci-only-e858-inspector') + mysql_options(series) + [
        '--protocol=TCP', '--host=127.0.0.1', '--port=3306', '--user=e858_inspector',
        '--database=canovia_p0_ci', '--batch', '--raw', '--skip-column-names',
        '--skip-auto-rehash', '--connect-timeout=5']


class FakeAdapter:
    def __init__(self, payloads):
        if not isinstance(payloads, dict) or any(k not in original.CAPS or not isinstance(v, bytes)
                                                for k, v in payloads.items()):
            raise Block('SYNTHETIC_FIXTURE_REJECTED')
        self.payloads = dict(payloads)

    def _command(self, key):
        env = child_env()
        env['SYNTHETIC_PAYLOAD'] = base64.b64encode(zlib.compress(self.payloads[key])).decode('ascii')
        if len(env['SYNTHETIC_PAYLOAD']) > 65536:
            raise Block('SYNTHETIC_FIXTURE_REJECTED')
        return [sys.executable, '-I', '-B', '-c', FAKE], env


class DisposableAdapter:
    def __init__(self, series):
        environment_guard()
        if series not in TARGETS:
            raise Block('DISPOSABLE_TARGET_REJECTED')
        self.series = series
        self._verify()

    def _verify(self):
        name, port = TARGETS[self.series]
        try:
            p = subprocess.run([DOCKER, 'inspect', name], env=child_env(),
                               stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=5, check=True)
            if len(p.stdout) > 1024 * 1024:
                raise ValueError()
            state = json.loads(p.stdout)[0]
            if (state['Name'] != '/' + name or not state['State']['Running']
                    or state['Config']['Image'] != 'mysql:' + self.series
                    or state['Config']['Labels'].get('canovia.integration') != '2026-10-16'
                    or state['NetworkSettings']['Ports'].get('3306/tcp') != [
                        {'HostIp': '127.0.0.1', 'HostPort': port}]):
                raise ValueError()
        except (Exception, KeyboardInterrupt):
            raise Block('DISPOSABLE_TARGET_REJECTED') from None

    def _command(self, _key):
        # Recheck ownership immediately before EACH query. Docker is local only;
        # client connects to its own container's 127.0.0.1, never an external host.
        self._verify()
        return _disposable_command(self.series), child_env()


def capture(adapter, *, root=original.ROOT, supplied_plan=None):
    environment_guard()
    fixed = plan(root, supplied_plan)  # Validate ENTIRE order/SQL before any process.
    if type(adapter) not in (FakeAdapter, DisposableAdapter):
        raise Block('ADAPTER_NOT_ALLOWLISTED')
    budget = Budget()  # No caller override can increase the inherited limits.
    out = {}
    for key, sql in fixed:
        command, env = adapter._command(key)
        value = bounded_query(command, key, budget, sql=sql, env=env,
                              max_bytes=16384 if key in ('identity', 'grants') else 8 * 1024 * 1024)
        if type(adapter) is DisposableAdapter:
            if key == 'identity':
                cells = value.rstrip('\n').split('\t')
                if (len(cells) != 4 or cells[0] != 'canovia_p0_ci' or cells[1] != 'e858_inspector@%'
                        or not cells[2].startswith(adapter.series + '.')):
                    raise Block('DISPOSABLE_IDENTITY_REJECTED')
            if key == 'grants' and not verify_grants(value, 'canovia_p0_ci'):
                raise Block('METADATA_GRANTS_REJECTED')
            if key == 'engine' and value != 'engine\t' + adapter.series + '\n':
                raise Block('DISPOSABLE_ENGINE_REJECTED')
        if key in out and out[key] != value:
            raise Block('LEDGER_CHANGED' if key == 'ledger' else 'PRIVATE_BINDING_CHANGED')
        out[key] = value
    # Private memory only; callers must never print/serialize this raw dictionary.
    return out
