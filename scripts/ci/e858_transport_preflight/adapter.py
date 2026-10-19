"""Closed synthetic adapters. No hostname, password, SQL or command arguments."""
import base64
import json
import os
import subprocess
import sys
from pathlib import Path
import zlib

from e858_audit import core as original
from e858_audit.transport import Budget, bounded_query
from e858_transport_preflight.core import Block, environment_guard, plan
from p0_mysql_metadata_grants_guard import verify_grants

# Constant fake program; user-provided Python/shell/argv is never evaluated.
FAKE = "import os,sys,base64,zlib;sys.stdin.buffer.read();sys.stdout.buffer.write(zlib.decompress(base64.b64decode(os.environ['SYNTHETIC_PAYLOAD'])))"
# Trusted OS installation paths only; never search an ambient PATH.
DOCKER = next((p for p in ('/usr/bin/docker', '/usr/local/bin/docker') if Path(p).is_file()), '/usr/bin/docker')
TARGETS = {'8.0': ('canovia-integration-80', '13380'),
           '8.4': ('canovia-integration-84', '13384')}


def child_env():
    return {'PATH': '/usr/bin:/bin', 'HOME': '/nonexistent', 'LANG': 'C', 'LC_ALL': 'C'}


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
        name, _ = TARGETS[self.series]
        return [DOCKER, 'exec', '-i', '-e', 'MYSQL_PWD=ci-only-e858-inspector',
                '-e', 'HOME=/nonexistent', name, 'mysql', '--no-defaults', '--no-login-paths',
                '--protocol=TCP', '--host=127.0.0.1', '--port=3306', '--user=e858_inspector',
                '--database=canovia_p0_ci', '--batch', '--raw', '--skip-column-names',
                '--skip-auto-rehash', '--connect-timeout=5'], child_env()


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
