#!/usr/bin/env python3
"""Run isolated browser journeys on separate disposable SQLite databases."""
import argparse
import json
import os
from pathlib import Path
import signal
import subprocess
import tempfile
import time
from urllib.request import build_opener, ProxyHandler

ROOT = Path(__file__).resolve().parents[2]
JOURNEYS = {
    'welcome': (8796, 'prelaunch_ia2d_browser_smoke.mjs', 'CANOVIA_IA2_BROWSER'),
    'development': (8791, 'development_v69_browser_smoke.mjs', 'CANOVIA_V69'),
    'study': (8799, 'learning_v621_draft_browser_smoke.mjs', 'CANOVIA_LEARNING_V621'),
    'future': (8797, 'future_carousel_browser_smoke.mjs', 'CANOVIA_FUTURE'),
}


def run(name, artifacts, executable=None, engine='chromium'):
    port, script, prefix = JOURNEYS[name]
    base = f'http://127.0.0.1:{port}'
    # Retain tool activation, not provider credentials or production DB values.
    env = {k: os.environ[k] for k in ['PATH', 'PHPRC', 'PHP_INI_SCAN_DIR', 'LD_LIBRARY_PATH',
           'LANG', 'NODE_EXTRA_CA_CERTS'] if k in os.environ}
    with tempfile.TemporaryDirectory(prefix='canovia-integration-') as private:
        database = Path(private) / ('learning-v621-browser.sqlite' if name == 'study' else 'synthetic.sqlite')
        database.touch(mode=0o600)
        env.update(APP_ENV='testing', APP_DEBUG='false', APP_URL=base,
                   APP_KEY='base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
                   DB_CONNECTION='sqlite', DB_DATABASE=str(database), DB_URL='',
                   SESSION_DRIVER='file', SESSION_COOKIE=f'integration_{name}',
                   CACHE_STORE='array', QUEUE_CONNECTION='sync', MAIL_MAILER='array',
                   NATIVE_AI_DRIVER='disabled', CANOVIA_NATIVE_AI_DRIVER='disabled',
                   CANOVIA_PUBLIC_RELEASE_LEVEL='1', CI='true', RUNNER_TEMP=private,
                   CANOVIA_MCP_TOOLS_ENABLED='false', FEATURE_CANOVIA_AI='false')
        if name == 'welcome':
            env.update(CANOVIA_IA2_BROWSER_BASE_URL=base, CANOVIA_IA2_BROWSER_FIXTURE='1')
        else:
            env[prefix + '_BASE_URL'] = base
            env[prefix + ('_BROWSER_FIXTURE' if name == 'development' else '_FIXTURE')] = '1'
        if name == 'future':
            env.update(CANOVIA_PUBLIC_RELEASE_LEVEL='0', CANOVIA_FUTURE_ENGINE=engine)
            env['CANOVIA_FUTURE_SCREENSHOT'] = str((artifacts / 'future-desktop.png').resolve())
        if executable:
            env['CANOVIA_BROWSER_EXECUTABLE_PATH'] = str(Path(executable).resolve(strict=True))
        artifacts.mkdir(parents=True, exist_ok=True)
        with (artifacts / f'{name}.log').open('w') as log:
            subprocess.run(['php', 'artisan', 'migrate', '--force', '--no-interaction'],
                           cwd=ROOT, env=env, stdout=log, stderr=log, check=True)
            server = subprocess.Popen(['php', 'artisan', 'serve', '--no-reload',
                                       '--host=127.0.0.1', f'--port={port}'],
                                      cwd=ROOT, env=env, stdout=log, stderr=log, start_new_session=True)
            try:
                opener = build_opener(ProxyHandler({}))
                for _ in range(100):
                    if server.poll() is not None:
                        raise RuntimeError(f'{name}: server exited, inspect log')
                    try:
                        with opener.open(base + '/health', timeout=1) as response:
                            if response.status == 204:
                                break
                    except OSError:
                        pass
                    time.sleep(0.2)
                else:
                    raise RuntimeError(f'{name}: readiness timeout')
                subprocess.run(['node', 'scripts/ci/' + script], cwd=ROOT, env=env,
                               stdout=log, stderr=log, check=True, timeout=300)
            finally:
                if server.poll() is None:
                    os.killpg(server.pid, signal.SIGTERM)
                    try:
                        server.wait(timeout=10)
                    except subprocess.TimeoutExpired:
                        os.killpg(server.pid, signal.SIGKILL)
                        server.wait()
    print(json.dumps(dict(journey=name, result='passed', database='disposable_sqlite',
                         production_verified=False, native_ios_verified=False)))


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--journey', choices=[*JOURNEYS, 'all'], default='all')
    parser.add_argument('--artifacts', type=Path, required=True)
    parser.add_argument('--browser-executable')
    parser.add_argument('--browser-engine', choices=['chromium', 'webkit'], default='chromium')
    args = parser.parse_args()
    # A Vite hot marker would connect these fixtures to an unrelated live dev server.
    if (ROOT / 'public/hot').exists():
        raise SystemExit('Stop the owned Vite session and remove its stale hot marker before isolated E2E')
    for name in JOURNEYS if args.journey == 'all' else [args.journey]:
        try:
            run(name, args.artifacts, args.browser_executable, args.browser_engine)
        except subprocess.CalledProcessError as error:
            raise SystemExit(error.returncode)
