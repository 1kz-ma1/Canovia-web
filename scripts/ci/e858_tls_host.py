#!/usr/bin/env python3
"""Creates owned synthetic TLS fixtures only; accepts no endpoint/credential input."""
import argparse
import ast
import datetime
import hashlib
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile
import time
import uuid

from cryptography import x509
from cryptography.hazmat.primitives import hashes, serialization
from cryptography.hazmat.primitives.asymmetric import rsa
from cryptography.x509.oid import NameOID, ExtendedKeyUsageOID

sys.path.insert(0, str(Path(__file__).resolve().parent))
from e858_b_offline import integrity, git, ROOT, SOURCE, COLLECTOR, CONTRACT
from integrated_release20_audit import snapshot, compare, BASE
from p0_mysql_full_ledger_offline_check import parse_full_ledger

LABEL = 'canovia.e858.isolated_tls'
ROOT_PASSWORD = 'ci-only-tls-root'


class Block(Exception): pass


def run(argv, data=None, timeout=180):
    env = dict(os.environ, DOCKER_BUILDKIT='0') if argv[:2] == ['docker', 'build'] else None
    proc = subprocess.run(argv, input=data, capture_output=True, timeout=timeout, env=env)
    if proc.returncode:
        if os.environ.get('GITHUB_ACTIONS') != 'true':
            debug = Path('/tmp') / ('canovia-e858-tls-command-' + str(os.getuid()) + '.log')
            debug.write_bytes(proc.stdout + proc.stderr); debug.chmod(0o600)
        stage = {'build': 'BUILD', 'run': 'RUN', 'network': 'NETWORK', 'inspect': 'INSPECT', 'rm': 'CLEANUP'}.get(argv[1], 'OTHER')
        raise Block('ISOLATED_COMMAND_FAILED_' + stage)
    return proc.stdout


def certificate_material(root):
    now = datetime.datetime.now(datetime.timezone.utc)
    def authority(name):
        key = rsa.generate_private_key(public_exponent=65537, key_size=2048)
        subject = x509.Name([x509.NameAttribute(NameOID.COMMON_NAME, name)])
        cert = (x509.CertificateBuilder().subject_name(subject).issuer_name(subject)
            .public_key(key.public_key()).serial_number(x509.random_serial_number())
            .not_valid_before(now - datetime.timedelta(days=7)).not_valid_after(now + datetime.timedelta(days=1))
            .add_extension(x509.BasicConstraints(ca=True, path_length=0), critical=True)
            .sign(key, hashes.SHA256()))
        return key, cert
    ca_key, ca = authority('SYNTHETIC TLS CA')
    _, wrong = authority('SYNTHETIC WRONG CA')
    client = root / 'client'; client.mkdir()
    (client / 'ca.pem').write_bytes(ca.public_bytes(serialization.Encoding.PEM))
    (client / 'wrong-ca.pem').write_bytes(wrong.public_bytes(serialization.Encoding.PEM))
    for name, host, expired in [('server', 'mysql.synthetic.invalid', False),
                                 ('expired', 'expired.synthetic.invalid', True)]:
        directory = root / name; directory.mkdir(mode=0o755); directory.chmod(0o755)
        key = rsa.generate_private_key(public_exponent=65537, key_size=2048)
        cert = (x509.CertificateBuilder()
            .subject_name(x509.Name([x509.NameAttribute(NameOID.COMMON_NAME, host)]))
            .issuer_name(ca.subject).public_key(key.public_key()).serial_number(x509.random_serial_number())
            .not_valid_before(now - datetime.timedelta(days=3))
            .not_valid_after(now - datetime.timedelta(days=1) if expired else now + datetime.timedelta(hours=1))
            .add_extension(x509.SubjectAlternativeName([x509.DNSName(host)]), critical=False)
            .add_extension(x509.ExtendedKeyUsage([ExtendedKeyUsageOID.SERVER_AUTH]), critical=False)
            .sign(ca_key, hashes.SHA256()))
        (directory / 'server.pem').write_bytes(cert.public_bytes(serialization.Encoding.PEM))
        (directory / 'ca.pem').write_bytes(ca.public_bytes(serialization.Encoding.PEM))
        (directory / 'server-key.pem').write_bytes(key.private_bytes(serialization.Encoding.PEM,
            serialization.PrivateFormat.TraditionalOpenSSL, serialization.NoEncryption()))
        # Readable only within the server's mounted subtree; host parent is0700.
        for file in directory.iterdir(): file.chmod(0o444)


def assets(directory, head, series):
    facts = integrity(ROOT, head)
    sql = directory / 'queries'; sql.mkdir()
    tree = ast.parse(git(ROOT, 'show', COLLECTOR + ':scripts/ci/e858_audit/core.py'))
    caps = None
    for n in tree.body:
        if isinstance(n, ast.Assign) and any(isinstance(t, ast.Name) and t.id == 'CAPS' for t in n.targets):
            caps = {k.arg: ast.literal_eval(k.value) for k in n.value.keywords}
    constants = ast.parse(git(ROOT, 'show', CONTRACT + ':scripts/ci/e858_approval_offline/contracts.py'))
    order = next(ast.literal_eval(n.value) for n in constants.body if isinstance(n, ast.Assign)
                 and any(isinstance(t, ast.Name) and t.id == 'ORDER' for t in n.targets))
    for key in caps:
        (sql / (key + '.sql')).write_bytes(git(ROOT, 'show', COLLECTOR + ':scripts/ci/e858_audit/queries/' + key + '.sql'))
    def migrations(sha):
        result = {}
        for row in git(ROOT, 'ls-tree', '-r', sha, 'database/migrations').decode().splitlines():
            meta, path = row.split('\t')
            if path.endswith('.php'): result[Path(path).stem] = meta.split()[2]
        return result
    old, new = migrations(BASE), migrations(SOURCE)
    (directory / 'manifest.json').write_text(json.dumps(dict(series=series, order=order, caps=caps,
        sql_sha256=facts['sql_sha256'], baseline_names=sorted(old))))
    (directory / 'p0_mysql_metadata_grants_guard.py').write_bytes(git(ROOT, 'show', head + ':scripts/ci/p0_mysql_metadata_grants_guard.py'))
    reference = json.loads(git(ROOT, 'show', COLLECTOR + ':scripts/ci/e858_audit_reference/' + series + '.json'))
    return facts, old, new, reference


def rehearse(series, head):
    token = uuid.uuid4().hex
    stem = 'canovia-e858-tls-' + token[:12]
    network, image = stem + '-network', stem + '-client'
    owned = []
    network_created = False
    result = None
    cleanup = True
    with tempfile.TemporaryDirectory(prefix='canovia-e858-isolated-') as temp:
        work = Path(temp).resolve(); work.chmod(0o700)
        certificate_material(work)
        facts, old, new, reference = assets(work / 'client', head, series)
        output = work / 'out'; output.mkdir(mode=0o700)
        try:
            run(['docker', 'network', 'create', '--internal', '--label', LABEL + '=' + token, network])
            network_created = True
            build = work / 'build'; build.mkdir()
            run([sys.executable, '-m', 'pip', 'download', '--no-deps', '--only-binary=:all:',
                 '--dest', str(build), 'PyMySQL==1.1.2'])
            wheel = build / 'pymysql-1.1.2-py3-none-any.whl'
            if hashlib.sha256(wheel.read_bytes()).hexdigest() != 'e6b1d89711dd51f8f74b1631fe08f039e7d76cf67a42a323d3178f0f25762ed9':
                raise Block('ISOLATED_DEPENDENCY_HASH_REJECTED')
            (build / 'Dockerfile').write_text('FROM python:3.12-slim\nCOPY *.whl /tmp/\nRUN pip install --no-index /tmp/*.whl && rm /tmp/*.whl\n')
            run(['docker', 'build', '--network=none', '--label', LABEL + '=' + token,
                 '-t', image, str(build)], timeout=300)
            for suffix, hostname in [('server', 'mysql.synthetic.invalid'), ('expired', 'expired.synthetic.invalid')]:
                name = stem + '-' + suffix
                argv = ['docker', 'run', '-d', '--name', name, '--label', LABEL + '=' + token,
                    '--network', network, '--network-alias', hostname,
                    '--tmpfs', '/var/lib/mysql:rw,nosuid,size=512m',
                    '-v', str(work / suffix) + ':/certs:ro',
                    '-e', 'MYSQL_ROOT_PASSWORD=' + ROOT_PASSWORD, '-e', 'MYSQL_ROOT_HOST=%',
                    '-e', 'MYSQL_DATABASE=canovia_p0_ci', 'mysql:' + series,
                    '--ssl-ca=/certs/ca.pem', '--ssl-cert=/certs/server.pem', '--ssl-key=/certs/server-key.pem',
                    '--require-secure-transport=ON', '--innodb-buffer-pool-size=32M',
                    '--innodb-redo-log-capacity=64M']
                if suffix == 'server': argv[argv.index('--tmpfs'):argv.index('--tmpfs')] = ['--network-alias', 'mismatch.synthetic.invalid']
                run(argv); owned.append(name)
                until = time.monotonic() + 90
                while True:
                    ready = subprocess.run(['docker', 'exec', '-e', 'MYSQL_PWD=' + ROOT_PASSWORD, name,
                        'mysql', '--protocol=socket', '-uroot', '-Nse', 'SELECT @@skip_networking'], capture_output=True)
                    if ready.returncode == 0 and ready.stdout.strip() == b'0': break
                    if time.monotonic() >= until: raise Block('ISOLATED_SERVER_NOT_READY')
                    time.sleep(.25)
                ssl_state = run(['docker', 'exec', '-e', 'MYSQL_PWD=' + ROOT_PASSWORD, name,
                    'mysql', '--protocol=socket', '-uroot', '-Nse', "SHOW VARIABLES LIKE 'have_ssl'"])
                if b'YES' not in ssl_state:
                    logs = run(['docker', 'logs', name])
                    if os.environ.get('GITHUB_ACTIONS') != 'true':
                        info = dict(cert_permission_error=b'Permission denied' in logs,
                            certificate_error=b'certificate' in logs.lower(),
                            key_error=b'private key' in logs.lower())
                        Path('/tmp/canovia-e858-server-tls-debug.json').write_text(json.dumps(info))
                    raise Block('ISOLATED_SERVER_TLS_NOT_ENABLED')
                state = json.loads(run(['docker', 'inspect', name]))[0]
                net = json.loads(run(['docker', 'network', 'inspect', network]))[0]
                if (state['Config']['Labels'].get(LABEL) != token or state['HostConfig']['PortBindings']
                        or not net['Internal'] or net['Labels'].get(LABEL) != token
                        or set(state['NetworkSettings']['Networks']) != {network}):
                    raise Block('ISOLATION_OWNERSHIP_REJECTED')
            name = stem + '-worker'
            owned.append(name)
            proc = subprocess.run(['docker', 'run', '--name', name, '--label', LABEL + '=' + token,
                '--network', network, '--read-only', '--tmpfs', '/tmp:rw,nosuid,size=64m',
                '--user', str(os.getuid()) + ':' + str(os.getgid()),
                '-v', str(ROOT / 'scripts/ci/e858_tls') + ':/code:ro',
                '-v', str(work / 'client') + ':/synthetic:ro', '-v', str(output) + ':/out:rw',
                '-e', 'PYTHONPATH=/synthetic:/code', image, 'python', '-B', '/code/fixtures.py'],
                capture_output=True, timeout=180)
            if proc.returncode:
                # Private, fixed diagnostic frames only, never SQL/error strings.
                debug = output / 'private-error.json'
                if debug.exists() and os.environ.get('GITHUB_ACTIONS') != 'true':
                    dest = Path('/tmp') / ('canovia-e858-tls-debug-' + str(os.getuid()) + '.json')
                    dest.write_bytes(debug.read_bytes()); dest.chmod(0o600)
                try: failure = json.loads(proc.stdout)
                except Exception: failure = {}
                raise Block('ISOLATED_WORKER_FAILED_' + failure.get('case', 'BOOTSTRAP'))
            result = json.loads(proc.stdout)
            if result['result'] != 'ISOLATED_SYNTHETIC_VERIFIED' or result['real_aiven_connected']:
                raise Block('ISOLATED_REPORT_REJECTED')
            data = json.loads((output / 'snapshot.json').read_text())
            raw = data['engine'] + ''.join(data[k] for k in ('table','column','index','foreign_key','check','trigger'))
            legacy = dict(kind='DISPOSABLE_SOURCE_REFERENCE_ONLY', baseline_sha=BASE,
                migration_seal=reference['migration_seal'], engine=series,
                baseline94=reference['baseline94']['physical'], candidate114=reference['candidate114']['physical'])
            strict = compare(dict(baseline=old, candidate=new, migration_seal=reference['migration_seal']),
                             parse_full_ledger(data['ledger']), snapshot(raw), legacy)
            if strict['result'] != 'BLOCK': raise Block('MINIMAL_FIXTURE_MUST_STRICT_BLOCK')
            result.update(tooling_sha=head, source_sha=SOURCE, series=series,
                strict_schema_result='BLOCK_EXPECTED_MINIMAL_STRUCTURE_FIXTURE',
                trigger_visibility='UNVERIFIED', ledger_fixture_only=True,
                evidence=['STATIC_REVIEWED','SYNTHETIC_TESTED','ISOLATED_MYSQL_TLS_TESTED',
                          'ISOLATED_REMOTE_TERMINATION_TESTED','REAL_AIVEN_UNVERIFIED'])
        finally:
            for name in reversed(owned):
                state = subprocess.run(['docker', 'inspect', name], capture_output=True)
                if state.returncode: continue
                try:
                    if json.loads(state.stdout)[0]['Config']['Labels'].get(LABEL) != token:
                        cleanup = False; continue
                    run(['docker', 'rm', '-f', name])
                except Exception: cleanup = False
            if network_created:
                try: run(['docker', 'network', 'rm', network])
                except Exception: cleanup = False
            subprocess.run(['docker', 'image', 'rm', image], capture_output=True)
            if not cleanup: raise Block('ISOLATED_CLEANUP_UNVERIFIED')
    result['owned_containers_network_certificates_removed'] = True
    return result


def main():
    class PrivateParser(argparse.ArgumentParser):
        def error(self, message): raise Block('ARGUMENTS_REJECTED')
    try:
        parser = PrivateParser(add_help=False, allow_abbrev=False)
        parser.add_argument('--series', choices=['8.0','8.4'], required=True, action='append')
        parser.add_argument('--tooling-sha', required=True, action='append')
        args = parser.parse_args()
        if len(args.series) != 1 or len(args.tooling_sha) != 1: raise Block('ARGUMENTS_REJECTED')
        result = rehearse(args.series[0], args.tooling_sha[0])
        code = 0
    except (Exception, SystemExit, KeyboardInterrupt) as error:
        # Public failure identifies a fixed fixture stage only.
        code = 2
        value = str(error) if type(error) is Block and str(error).startswith(('ISOLATED_', 'MINIMAL_', 'ARGUMENTS_')) else 'ISOLATED_HARNESS_REJECTED'
        result = dict(result='BLOCK', code=value, real_aiven_connected=False,
                      release_authorized=False, production_modified=False)
    print(json.dumps(result, sort_keys=True))
    return code


if __name__ == '__main__': sys.exit(main())
