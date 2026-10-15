#!/usr/bin/env python3
"""Synthetic old/new HTTP session compatibility on the owned MySQL fixture."""
import argparse
import hashlib
from html.parser import HTMLParser
import http.cookiejar
import json
import os
from pathlib import Path
import signal
import subprocess
import tempfile
import time
from urllib.error import HTTPError
from urllib.parse import urlencode, urlparse
from urllib.request import build_opener, ProxyHandler, HTTPCookieProcessor, Request

parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--repo', type=Path, default=Path.cwd())
parser.add_argument('--old-root', type=Path, required=True)
parser.add_argument('--artifacts', type=Path, required=True)
args = parser.parse_args()
ROOT = args.repo.resolve(strict=True)
OLD = args.old_root.resolve(strict=True)
BASE = '15abdc9de6f24b5250fb255c579d1aa55facaf1a'
ARTIFACTS = args.artifacts.resolve()
ARTIFACTS.mkdir(parents=True, exist_ok=True)


class Tokens(HTMLParser):
    token = None
    def handle_starttag(self, tag, attrs):
        attrs = dict(attrs)
        if tag == 'input' and attrs.get('name') == '_token':
            self.token = attrs.get('value')


def request(opener, url, data=None):
    body = urlencode(data).encode() if data is not None else None
    try:
        with opener.open(Request(url, data=body, headers={'User-Agent': 'Mozilla/5.0 CanoviaNative/iOS/1.0'}), timeout=20) as response:
            return response.status, response.read().decode(), response.url
    except HTTPError as error:
        return error.code, error.read().decode(), error.url


def token(page):
    parser = Tokens(); parser.feed(page)
    assert parser.token, 'Real HTTP CSRF token missing'
    return parser.token


servers = []
logs = []
try:
    # Verify the old authentication/session source against immutable Git blobs.
    for source in ['composer.lock', 'bootstrap/app.php', 'app/Models/User.php',
                   'app/Http/Controllers/AuthController.php', 'config/session.php', 'routes/web.php']:
        data = (OLD / source).read_bytes()
        actual = hashlib.sha1(b'blob ' + str(len(data)).encode() + b'\0' + data).hexdigest()
        expected = subprocess.check_output(['git', 'rev-parse', BASE + ':' + source], cwd=ROOT, text=True).strip()
        assert actual == expected, source
    target = json.loads(subprocess.check_output(['docker', 'inspect', 'canovia-integration-84'], text=True))[0]
    assert target['Config']['Labels'].get('canovia.integration') == '2026-10-16'
    assert target['NetworkSettings']['Ports']['3306/tcp'] == [{'HostIp': '127.0.0.1', 'HostPort': '13384'}]
    with tempfile.TemporaryDirectory(prefix='canovia-session-') as directory:
        for label, root, port in [('old', OLD, 18084), ('new', ROOT, 18085)]:
            private = Path(directory) / label; private.mkdir()
            views = private / 'views'; views.mkdir()
            env = {k: os.environ[k] for k in ['PATH', 'PHPRC', 'PHP_INI_SCAN_DIR', 'LD_LIBRARY_PATH', 'LANG'] if k in os.environ}
            env.update(APP_ENV='local', APP_DEBUG='false', APP_URL=f'http://127.0.0.1:{port}',
                       APP_KEY='base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
                       DB_CONNECTION='mysql', DB_HOST='127.0.0.1', DB_PORT='13384',
                       DB_DATABASE='canovia_p0_ci', DB_USERNAME='canovia_ci', DB_PASSWORD='ci-only-ephemeral-password', DB_URL='',
                       SESSION_DRIVER='database', SESSION_COOKIE='integration_compat', SESSION_SECURE_COOKIE='false',
                       CACHE_STORE='array', QUEUE_CONNECTION='sync', MAIL_MAILER='array',
                       CANOVIA_PUBLIC_RELEASE_LEVEL='1', CANOVIA_NATIVE_AI_DRIVER='disabled', FEATURE_CANOVIA_AI='false',
                       APP_CONFIG_CACHE=str(private/'config.php'), APP_ROUTES_CACHE=str(private/'routes.php'),
                       VIEW_COMPILED_PATH=str(views))
            log = (ARTIFACTS/f'session-{label}.log').open('w'); logs.append(log)
            server = subprocess.Popen(['php', 'artisan', 'serve', '--no-reload', '--host=127.0.0.1', f'--port={port}'],
                cwd=root, env=env, stdout=log, stderr=log, start_new_session=True)
            servers.append(server)
            probe = build_opener(ProxyHandler({}))
            for _ in range(100):
                if server.poll() is not None: raise RuntimeError(label+' server exited')
                try:
                    if request(probe, f'http://127.0.0.1:{port}/login')[0] == 200: break
                except OSError: pass
                time.sleep(0.2)
            else: raise RuntimeError(label+' readiness timeout')
        opener = build_opener(ProxyHandler({}), HTTPCookieProcessor(http.cookiejar.CookieJar()))
        old, new = 'http://127.0.0.1:18084', 'http://127.0.0.1:18085'
        status, page, _ = request(opener, old+'/login'); assert status == 200
        status, _, _ = request(opener, old+'/login', {'_token': token(page), 'email': 'pinned-upgrade-owner@example.invalid', 'password': 'password'})
        assert status == 200, ('old_login', status)
        for base in [old, new]:
            status, page, url = request(opener, base+'/account')
            assert status == 200 and urlparse(url).path == '/account' and 'pinned-upgrade-owner@example.invalid' in page
        status, _, _ = request(opener, new+'/welcome/start', {'_token': 'invalid', 'entry': 'browse'})
        assert status == 419, ('real_csrf', status)
        status, page, _ = request(opener, new+'/account'); assert status == 200
        status, _, _ = request(opener, new+'/logout', {'_token': token(page)})
        assert status == 200
        status, _, url = request(opener, old+'/account')
        assert status == 200 and urlparse(url).path == '/login', ('cross_version_logout', status, urlparse(url).path)
        result = dict(result='passed', old_sha=BASE, candidate_sha=subprocess.check_output(['git','rev-parse','HEAD'],cwd=ROOT,text=True).strip(),
            mysql='8.4_disposable', old_login_new_session=True, real_csrf_419=True, new_logout_old_session_revoked=True,
            native_user_agent_only=True, physical_wkwebview_verified=False, release_authorized=False)
        (ARTIFACTS/'cross-version-session.json').write_text(json.dumps(result,indent=2)+'\n')
        print(json.dumps(result))
finally:
    for server in servers:
        if server.poll() is None:
            os.killpg(server.pid,signal.SIGTERM)
            try: server.wait(timeout=10)
            except subprocess.TimeoutExpired:
                os.killpg(server.pid,signal.SIGKILL);server.wait()
    for log in logs: log.close()
