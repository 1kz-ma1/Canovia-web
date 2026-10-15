#!/usr/bin/env python3
"""Rehearse the frozen 94->114 source on identified disposable MySQL only."""
import argparse
import json
import os
from pathlib import Path
import subprocess
import tempfile

ROOT = Path(__file__).resolve().parents[2]
BASE = '15abdc9de6f24b5250fb255c579d1aa55facaf1a'


def run(series, artifacts):
    name, port = {'8.0': ('canovia-integration-80', '13380'),
                  '8.4': ('canovia-integration-84', '13384')}[series]
    target = json.loads(subprocess.check_output(['docker', 'inspect', name], text=True))[0]
    if (target['Config']['Labels'].get('canovia.integration') != '2026-10-16'
            or target['NetworkSettings']['Ports']['3306/tcp'] != [{'HostIp': '127.0.0.1', 'HostPort': port}]):
        raise RuntimeError('Disposable container identity/loopback boundary mismatch')
    subprocess.run(['git', 'diff', '--quiet', 'HEAD'], cwd=ROOT, check=True)
    candidate = subprocess.check_output(['git', 'rev-parse', 'HEAD'], cwd=ROOT, text=True).strip()
    env = {k: os.environ[k] for k in ['PATH', 'PHPRC', 'PHP_INI_SCAN_DIR', 'LD_LIBRARY_PATH', 'LANG'] if k in os.environ}
    env.update(APP_ENV='testing', APP_KEY='base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
               DB_CONNECTION='mysql', DB_HOST='127.0.0.1', DB_PORT=port, DB_DATABASE='canovia_p0_ci',
               DB_USERNAME='canovia_ci', DB_PASSWORD='ci-only-ephemeral-password', DB_URL='',
               CACHE_STORE='array', SESSION_DRIVER='database', QUEUE_CONNECTION='sync', MAIL_MAILER='array',
               CANOVIA_PUBLIC_RELEASE_LEVEL='1', CI='true', CANOVIA_P0_DISPOSABLE_MYSQL_CI='1',
               CANOVIA_NATIVE_AI_DRIVER='disabled', FEATURE_CANOVIA_AI='false')
    artifacts.mkdir(parents=True, exist_ok=True)
    with tempfile.TemporaryDirectory(prefix='canovia-old-migrations-') as directory, (artifacts / f'mysql-{series}.log').open('w') as log:
        def command(args):
            return subprocess.run(args, cwd=ROOT, env=env, stdout=log, stderr=log, check=True, timeout=180)
        version = subprocess.check_output(['docker', 'exec', '-e', 'MYSQL_PWD=ci-only-ephemeral-password', name,
            'mysql', '-u', 'canovia_ci', '-Nse', 'SELECT VERSION()'], text=True).strip()
        if not version.startswith(series + '.'):
            raise RuntimeError('Wrong disposable MySQL series')
        # Reset only this tool's named, labelled and loopback-bound container DB.
        command(['docker', 'exec', '-e', 'MYSQL_PWD=ci-only-root-password', name,
                 'mysql', '-uroot', '-e', 'DROP DATABASE canovia_p0_ci; CREATE DATABASE canovia_p0_ci CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;'])
        paths = subprocess.check_output(['git', 'ls-tree', '-r', '--name-only', BASE, 'database/migrations'], cwd=ROOT, text=True).splitlines()
        paths = [p for p in paths if p.endswith('.php')]
        if len(paths) != 94:
            raise RuntimeError('Historical source does not contain exactly 94 migrations')
        for path in paths:
            (Path(directory) / Path(path).name).write_bytes(subprocess.check_output(['git', 'show', BASE + ':' + path], cwd=ROOT))
        command(['php', 'artisan', 'migrate', '--realpath', '--path=' + directory, '--force', '--no-interaction'])
        command(['php', 'scripts/ci/prelaunch_pinned_mysql19_fixture.php', 'seed'])
        seal = str(Path(directory) / 'row-seal.json')
        command(['php', 'scripts/ci/integration_upgrade_fixture.php', 'snapshot', seal])
        command(['php', 'artisan', 'migrate', '--force', '--no-interaction'])
        command(['php', 'scripts/ci/integration_upgrade_fixture.php', 'verify', seal])
        command(['php', 'artisan', 'migrate', '--force', '--no-interaction'])
        command(['php', 'scripts/ci/integration_upgrade_fixture.php', 'verify', seal])
        command(['php', 'artisan', 'canovia:release-schema-ready'])
        command(['php', 'scripts/ci/integration_upgrade_fixture.php', 'fences'])
    image = target['Image']
    result = dict(series=series, version=version, image_id=image, base_sha=BASE, candidate_sha=candidate,
                  baseline_migrations=94, candidate_migrations=114, result='passed',
                  real_aiven_verified=False, physical_device_verified=False, release_authorized=False)
    (artifacts / f'mysql-{series}.json').write_text(json.dumps(result, indent=2) + '\n')
    print(json.dumps(result))


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--series', choices=['8.0', '8.4'], required=True)
    parser.add_argument('--artifacts', type=Path, required=True)
    args = parser.parse_args()
    run(args.series, args.artifacts)
