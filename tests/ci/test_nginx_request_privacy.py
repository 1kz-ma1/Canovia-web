"""Real owned Nginx HTTP probe; synthetic-only, network-none, no app/DB startup."""
from pathlib import Path
import re
import subprocess
import tempfile
import time
import unittest
import uuid

ROOT = Path(__file__).resolve().parents[2]
IMAGE = 'nginx:1.28-bookworm'


def docker(*args):
    return subprocess.run(['docker', *args], stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                          text=True, timeout=60)


class NginxRequestPrivacyTest(unittest.TestCase):
    def test_auth_urls_headers_bodies_and_error_paths_do_not_enter_nginx_logs(self):
        # A fixed source config, no credential/environment/endpoint arguments.
        with tempfile.TemporaryDirectory(prefix='canovia-nginx-synthetic-') as directory:
            config = Path(directory) / 'nginx.conf'
            config.write_bytes((ROOT / 'docker/nginx.conf').read_bytes())
            name = 'canovia-nginx-privacy-' + uuid.uuid4().hex
            created = False
            try:
                result = docker('create', '--name', name, '--network', 'none',
                                '--label', 'canovia.fixture=nginx-request-privacy',
                                '--read-only', '--tmpfs', '/tmp', '--tmpfs', '/var/cache/nginx',
                                '--tmpfs', '/var/run', '--mount', 'type=bind,source=' + str(config) + ',target=/fixture/nginx.conf,readonly',
                                '--entrypoint', 'nginx', IMAGE, '-c', '/fixture/nginx.conf', '-g', 'daemon off;')
                self.assertEqual(result.returncode, 0, 'OWNED_NGINX_START_FAILED')
                created = True
                self.assertEqual(docker('start', name).returncode, 0, 'OWNED_NGINX_START_FAILED')
                ready = False
                for _ in range(40):
                    if docker('exec', name, 'curl', '--silent', '--fail', '--max-time', '2', '--output', '/dev/null', 'http://127.0.0.1:10000/health').returncode == 0:
                        ready = True
                        break
                    time.sleep(0.1)
                self.assertTrue(ready, 'OWNED_NGINX_UNREADY')
                secret = 'SYNTHETIC_NEVER_LOG_7e534d'
                # Missing local FPM intentionally produces 502/error paths; no
                # connection can leave the container's network-none loopback.
                for path in ['/reset-password/' + secret + '?email=' + secret,
                             '/.env?code=' + secret, '/unavailable?state=' + secret]:
                    docker('exec', name, 'curl', '--silent', '--max-time', '2', '--output', '/dev/null',
                           '--header', 'Referer: https://synthetic.invalid/' + secret,
                           '--header', 'Authorization: Bearer ' + secret,
                           '--header', 'Cookie: synthetic=' + secret,
                           'http://127.0.0.1:10000' + path)
                docker('exec', name, 'curl', '--silent', '--max-time', '2', '--output', '/dev/null',
                       '--data', 'password=' + secret,
                       'http://127.0.0.1:10000/reset-password')
                logs = docker('logs', name)
                self.assertEqual(logs.returncode, 0, 'OWNED_LOG_OBSERVATION_FAILED')
                output = logs.stdout + logs.stderr
                self.assertTrue(secret not in output, 'SYNTHETIC_SECRET_ENTERED_NGINX_LOG')
                self.assertTrue('/reset-password' not in output, 'AUTH_PATH_ENTERED_NGINX_LOG')
                self.assertTrue(re.search(r'GET 502 [0-9]+ rt=[0-9.]+', output), 'STATUS_LATENCY_OBSERVATION_MISSING')
                self.assertTrue(re.search(r'POST 502 [0-9]+ rt=[0-9.]+', output), 'POST_OBSERVATION_MISSING')
                self.assertTrue(re.search(r'GET 403 [0-9]+ rt=[0-9.]+', output), 'DENIAL_OBSERVATION_MISSING')
            finally:
                if created:
                    cleanup = docker('rm', '--force', name)
                    self.assertEqual(cleanup.returncode, 0, 'OWNED_NGINX_CLEANUP_UNVERIFIED')


if __name__ == '__main__':
    unittest.main()
