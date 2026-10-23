#!/usr/bin/env python3
"""Foreground, disposable original-Web lab. No existing .env/database is used."""
import argparse
import base64
import contextlib
import json
import os
from pathlib import Path
import re
import secrets
import signal
import shutil
import socket
import subprocess
import sys
import tempfile
import time
from urllib.request import build_opener, ProxyHandler

sys.dont_write_bytecode = True

from integration_browser_regression import ROOT, synthetic_environment
from prelaunch_ia2i_local_lab import SafetyError, check_no_symlink_components, git_value

PORTS = {'preparation': 18803, 'original': 18804}


def check_checkout(root, sha):
    check_no_symlink_components(root)
    if not re.fullmatch('[0-9a-f]{40}', sha) or git_value(root, 'rev-parse', 'HEAD') != sha:
        raise SafetyError('LAB_SOURCE_MISMATCH')
    if git_value(root, 'status', '--porcelain', '--untracked-files=normal'):
        raise SafetyError('LAB_CHECKOUT_NOT_CLEAN')
    if list(root.glob('.env')) or any(p.name != '.env.example' for p in root.glob('.env.*')):
        raise SafetyError('LAB_ENV_FILE_FORBIDDEN')
    if (root / 'public/hot').exists() or (root / 'bootstrap/cache/config.php').exists():
        raise SafetyError('LAB_CACHED_CONFIG_OR_HOT_FORBIDDEN')
    if list((root / 'bootstrap/cache').glob('routes*.php')):
        raise SafetyError('LAB_ROUTE_CACHE_FORBIDDEN')
    # Composer's baseDir must resolve to THIS checkout, not a symlinked vendor.
    vendor = root / 'vendor'
    if vendor.resolve() != vendor or not (vendor / 'autoload.php').is_file():
        raise SafetyError('LAB_OWN_VENDOR_REQUIRED')
    if not (root / 'public/build/manifest.json').is_file() or (root / 'public/storage').exists():
        raise SafetyError('LAB_ASSETS_OR_PUBLIC_STORAGE_UNSAFE')


def lab_env(private, profile):
    port = PORTS[profile]
    env = synthetic_environment(f'http://127.0.0.1:{port}', private, 'mac_lab', public_level=0, app_env='local')
    # Do not import PHP activation, proxy, HOME, DB or provider values from the user shell.
    env = {k: v for k, v in env.items() if k not in
           ('PHPRC', 'PHP_INI_SCAN_DIR', 'LD_LIBRARY_PATH', 'NODE_EXTRA_CA_CERTS')}
    env.update(APP_KEY='base64:' + base64.b64encode(secrets.token_bytes(32)).decode(),
               LARAVEL_STORAGE_PATH=str(private / 'storage'),
               VIEW_COMPILED_PATH=str(private / 'storage/framework/views'),
               APP_CONFIG_CACHE=str(private / 'config.php'), APP_ROUTES_CACHE=str(private / 'routes.php'),
               APP_EVENTS_CACHE=str(private / 'events.php'),
               APP_SERVICES_CACHE=str(private / 'services.php'), APP_PACKAGES_CACHE=str(private / 'packages.php'),
               SESSION_COOKIE='canovia_mac_lab_' + secrets.token_hex(8), SESSION_DOMAIN='null',
               SESSION_SECURE_COOKIE='false', LOG_CHANNEL='null',
               CANOVIA_SUPER_ADMIN_USER_ID='3', CANOVIA_ADMIN_EMAIL='', PACEKEEPER_ADMIN_EMAIL='',
               CANOVIA_MCP_DISCOVERY_ENABLED='false', CANOVIA_MCP_PLAN_CONSENT_ENABLED='false',
               CANOVIA_MCP_ACCOUNT_LINK_ENABLED='false', CANOVIA_MCP_DELEGATED_POLICY_ENABLED='false',
               CANOVIA_MCP_TOKEN_INTROSPECTION_ENABLED='false',
               CANOVIA_MAC_LAB_FIXTURE='1')
    return env


def stop_server(server):
    # Direct php -S: one owned process, no artisan supervisor or daemon PID file.
    if server.poll() is None:
        server.terminate()
        try:
            server.wait(timeout=5)
        except subprocess.TimeoutExpired:
            server.kill()
            server.wait(timeout=5)
    if server.poll() is None:
        raise SafetyError('LAB_CLEANUP_UNVERIFIED')


@contextlib.contextmanager
def serve(profile, sha):
    check_checkout(ROOT, sha)
    port = PORTS[profile]
    with socket.socket() as probe:
        try:
            probe.bind(('127.0.0.1', port))
        except OSError:
            raise SafetyError('LAB_PORT_OCCUPIED_DO_NOT_STOP_OTHER_SERVICE') from None
    private = Path(tempfile.mkdtemp(prefix='canovia-preparation-mac-'))
    server = None
    try:
        private.chmod(0o700)
        for name in ('framework/sessions', 'framework/views', 'framework/cache/data', 'logs', 'app/private'):
            (private / 'storage' / name).mkdir(parents=True, mode=0o700)
        (private / 'synthetic.sqlite').touch(mode=0o600)
        env = lab_env(private, profile)
        with (private / 'private.log').open('w') as log:
            os.chmod(log.name, 0o600)
            # Disposable schema only; restrictive runtime profile is enabled AFTER setup.
            for command in (['php', 'artisan', 'migrate', '--force', '--no-interaction'],
                            ['php', 'scripts/ci/preparation_mac_fixture.php']):
                subprocess.run(command, cwd=ROOT, env=env, stdout=log, stderr=log, check=True, timeout=120)
            env['CANOVIA_PREPARATION_ONLY'] = 'true' if profile == 'preparation' else 'false'
            token = secrets.token_hex(24)
            env['CANOVIA_MAC_LAB_READY'] = token
            server = subprocess.Popen(['php', '-S', f'127.0.0.1:{port}', '-t', 'public',
                                       'scripts/ci/preparation_mac_router.php'],
                                      cwd=ROOT, env=env, stdout=log, stderr=log)
            try:
                opener = build_opener(ProxyHandler({}))
                for _ in range(100):
                    if server.poll() is not None:
                        raise SafetyError('LAB_SERVER_EXITED')
                    try:
                        with opener.open(env['APP_URL'] + '/__preparation_lab_ready', timeout=1) as response:
                            if response.read(64).decode() == token:
                                break
                    except OSError:
                        pass
                    time.sleep(0.1)
                else:
                    raise SafetyError('LAB_READINESS_FAILED')
                yield env, server
            finally:
                stop_server(server)
    finally:
        # Keep files if termination cannot be verified; never claim cleanup succeeded.
        if server is None or server.poll() is not None:
            shutil.rmtree(private)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--profile', choices=PORTS, default='preparation')
    parser.add_argument('--source-sha', required=True)
    parser.add_argument('--smoke', action='store_true', help='Run the existing synthetic preparation journey then stop')
    parser.add_argument('--browser-executable')
    args = parser.parse_args()
    if args.smoke and args.profile != 'preparation':
        raise SafetyError('LAB_SMOKE_PROFILE_MISMATCH')
    # Terminal termination follows exactly the same finally/cleanup path as Ctrl-C.
    def interrupted(signum, frame):
        raise KeyboardInterrupt
    signal.signal(signal.SIGTERM, interrupted)
    with serve(args.profile, args.source_sha) as (env, server):
        print(json.dumps({'status': 'SYNTHETIC_LOCAL_LAB_READY', 'url': env['APP_URL'],
                          'profile': args.profile, 'public_level': 0, 'tester_level': 1,
                          'source': args.source_sha, 'device_acceptance': 'NOT_RUN'}), flush=True)
        print('Synthetic logins: tester@example.test (L1), normal@example.test (L0), admin@example.test (L0).', flush=True)
        print('All use PUBLIC FAKE password Synthetic-Preparation-Only-2026; never enter real data. Ctrl-C stops and deletes this lab.', flush=True)
        if args.smoke:
            env.update(CANOVIA_PREPARATION_SURFACE_BASE_URL=env['APP_URL'], CANOVIA_PREPARATION_SURFACE_FIXTURE='1')
            if args.browser_executable:
                env['CANOVIA_BROWSER_EXECUTABLE_PATH'] = str(Path(args.browser_executable).resolve(strict=True))
            subprocess.run(['node', 'scripts/ci/preparation_surface_browser_smoke.mjs'],
                           cwd=ROOT, env=env, check=True, timeout=300)
        else:
            try:
                while server.poll() is None:
                    time.sleep(1)
                raise SafetyError('LAB_SERVER_EXITED')
            except KeyboardInterrupt:
                pass  # Success is printed only AFTER the context confirms cleanup.
    print('LAB_STOPPED_DISPOSABLE_DATA_DELETED')


if __name__ == '__main__':
    try:
        main()
    except KeyboardInterrupt:
        print('LAB_STOP_UNVERIFIED: confirm owned listener termination before restarting.', file=sys.stderr)
        sys.exit(2)
    except SafetyError as error:
        code = str(error) if re.fullmatch(r'LAB_[A-Z_]+', str(error)) else 'LAB_UNSAFE_CHECKOUT'
        print(code, file=sys.stderr)
        sys.exit(2)
    except (subprocess.SubprocessError, OSError):
        # Never print inherited environment, app errors, raw SQL or private log paths.
        print('LAB_BLOCKED: check clean dedicated checkout, dependencies and unused fixed port.', file=sys.stderr)
        sys.exit(2)
