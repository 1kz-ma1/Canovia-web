#!/usr/bin/env python3
"""Entire frozen 94/114 schema, owned synthetic TLS network ONLY. No live mode."""
import io
import json
import os
from pathlib import Path
import shutil
import sys
import tarfile
import tempfile
import time

sys.path.insert(0, str(Path(__file__).resolve().parent))
import e858_tls_host as h
from e858_b_offline import template, validate, DECLARATIONS


def prepare(work, head, series, pins, old, new, reference):
    # Reuse B's unchanged checker, including all source/SQL/reference pins.
    packet = template(pins, int(time.time()))
    packet.update(declarations=DECLARATIONS, engine=series, attempt_id='a' * 32)
    receipt = validate(packet, pins, int(time.time()))
    client = work / 'client'
    for name, value in [('facts', dict(baseline=old, candidate=new, migration_seal=reference['migration_seal'])),
                        ('b_preflight', dict(kind='SYNTHETIC_B1_ONLY', receipt=receipt, packet=packet, pins=pins,
                            owner_approval_verified=False, execution_allowed=False))]:
        (client / (name + '.json')).write_text(json.dumps(value))
    (client / 'reference.json').write_bytes(h.git(h.ROOT,'show',h.COLLECTOR+':scripts/ci/e858_audit_reference/'+series+'.json'))
    manifest = json.loads((client / 'manifest.json').read_text())
    manifest.update(reference_sha256=pins['reference_sha256'][series], source_sha=h.SOURCE, tooling_sha=head)
    (client / 'manifest.json').write_text(json.dumps(manifest))
    contract = json.loads(h.git(h.ROOT, 'show', h.SOURCE + ':resources/release20_pending_schema_contract.json'))
    (client / 'contract.json').write_text(json.dumps({k:v for k,v in contract.items() if k.startswith('absent_')}))
    legacy = client / 'legacy'; (legacy / 'e858_audit').mkdir(parents=True)
    for name in ('e858_b_offline.py','p0_mysql_metadata_grants_input.py','p0_mysql_metadata_grants_guard.py','release488_runtime_ddl_restore_gate.py'):
        (legacy / name).write_bytes(h.git(h.ROOT,'show',head+':scripts/ci/'+name))
    files = ['integrated_release20_audit.py','integrated_release20_diagnose.py','integrated_release20_semantics.py',
             'p0_mysql_full_ledger_offline_check.py']
    for name in files:
        (legacy / name).write_bytes(h.git(h.ROOT, 'show', h.COLLECTOR + ':scripts/ci/' + name))
    (legacy / 'e858_audit/core.py').write_bytes(h.git(h.ROOT, 'show', h.COLLECTOR + ':scripts/ci/e858_audit/core.py'))
    # Same reviewed Laravel migrate --realpath --path harness; every migration
    # and application dependency comes from e858, not moving main.
    paths = ['artisan','app','bootstrap','config','database','routes','storage','composer.json','composer.lock',
             'resources/release20_pending_schema_contract.json']
    raw = h.git(h.ROOT,'archive',h.SOURCE,*paths)
    app = work / 'migration-app'; app.mkdir()
    with tarfile.open(fileobj=io.BytesIO(raw)) as archive:
        for member in archive.getmembers():
            if member.name.startswith('/') or '..' in Path(member.name).parts or not (member.isfile() or member.isdir()):
                raise h.Block('ISOLATED_SOURCE_ARCHIVE_REJECTED')
        archive.extractall(app)
    for path in ('composer.json','composer.lock'):
        if h.git(h.ROOT,'show',head+':'+path) != h.git(h.ROOT,'show',h.SOURCE+':'+path):
            raise h.Block('ISOLATED_VENDOR_SOURCE_MISMATCH')
    if not (h.ROOT / 'vendor/autoload.php').is_file(): raise h.Block('ISOLATED_VENDOR_REQUIRED')
    shutil.copytree(h.ROOT / 'vendor', app / 'vendor', symlinks=True)
    (app / '.env').write_text('# Owned synthetic migration fixture only\n')
    for stage, names in [('old94',old), ('all114',new)]:
        directory = app / stage; directory.mkdir()
        for name in names:
            shutil.copyfile(app / 'database/migrations' / (name+'.php'), directory / (name+'.php'))


def exercise(work, stem, token, network, image, owned, head, series):
    build = work / 'php-build'; build.mkdir()
    (build / 'Dockerfile').write_text('FROM php:8.4-cli-bookworm\nRUN docker-php-ext-install -j2 pdo_mysql\n')
    php_image = stem + '-php'
    h.run(['docker','build','--network=none','--label',h.LABEL+'='+token,'-t',php_image,str(build)],timeout=300)
    def migrate(stage):
        if stage not in ('old94','all114'): raise h.Block('ISOLATED_STAGE_REJECTED')
        name = stem + '-migrate-' + stage; owned.append(name)
        env = dict(APP_ENV='testing', APP_KEY='base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
            DB_CONNECTION='mysql', DB_HOST='mysql.synthetic.invalid', DB_PORT='3306', DB_DATABASE='canovia_p0_ci',
            DB_USERNAME='root', DB_PASSWORD=h.ROOT_PASSWORD, DB_URL='', DATABASE_URL='', MYSQL_ATTR_SSL_CA='/synthetic/ca.pem',
            CACHE_STORE='array', SESSION_DRIVER='database', QUEUE_CONNECTION='sync', MAIL_MAILER='array',
            CANOVIA_PUBLIC_RELEASE_LEVEL='0', CANOVIA_NATIVE_AI_DRIVER='disabled', FEATURE_CANOVIA_AI='false',
            CI='true', CANOVIA_P0_DISPOSABLE_MYSQL_CI='1')
        args=['docker','run','--name',name,'--label',h.LABEL+'='+token,'--network',network,
            '--user',str(os.getuid())+':'+str(os.getgid()),'-v',str(work/'migration-app')+':/app:rw',
            '-v',str(work/'client')+':/synthetic:ro','-w','/app']
        for key,value in env.items(): args += ['-e',key+'='+value]
        h.run(args+[php_image,'php','artisan','migrate','--realpath','--path=/app/'+stage,'--force','--no-interaction'])
    def capture(stage):
        preflight=json.loads((work/'client/b_preflight.json').read_text())
        packet=template(preflight['pins'],int(time.time()))
        packet.update(declarations=DECLARATIONS,engine=series,attempt_id='a'*32)
        preflight.update(packet=packet,receipt=validate(packet,preflight['pins'],int(time.time())))
        (work/'client/b_preflight.json').write_text(json.dumps(preflight))
        name=stem+'-full-'+stage; owned.append(name)
        args=['docker','run','--name',name,'--label',h.LABEL+'='+token,'--network',network,
            '--read-only','--tmpfs','/tmp:rw,nosuid,size=64m','--user',str(os.getuid())+':'+str(os.getgid()),
            '-v',str(h.ROOT/'scripts/ci/e858_tls')+':/code:ro','-v',str(work/'client')+':/synthetic:ro',
            '-v',str(work/'out')+':/out:rw','-e','PYTHONPATH=/synthetic/legacy:/synthetic:/code',
            '-e','E858_SYNTHETIC_STAGE='+stage,image,'python','-B','/code/full_schema.py']
        try: value=json.loads(h.run(args))
        except Exception:
            debug=work/'out/private-error.json'
            if debug.exists() and os.environ.get('GITHUB_ACTIONS')!='true':
                dest=Path(tempfile.gettempdir())/('canovia-e858-full94-debug-'+str(os.getuid())+'.json')
                dest.write_bytes(debug.read_bytes());dest.chmod(0o600)
            raise h.Block('ISOLATED_FULL_SCHEMA_FAILED_'+stage.upper()) from None
        if value.get('result')!='FULL_SCHEMA_STAGE_VERIFIED': raise h.Block('ISOLATED_FULL_REPORT_REJECTED')
        return value
    migrate('old94'); baseline=capture('old94')
    migrate('all114'); target=capture('all114')
    return dict(result='FULL94_SINGLE_TLS_BRIDGE_VERIFIED',source_sha=h.SOURCE,tooling_sha=head,series=series,
        stages=[baseline,target],fixed_sql_reference_unchanged=True,real_aiven_connected=False,production_modified=False,
        release_authorized=False,execution_allowed=False,owner_approval_verified=False,strict_block_waived=False,
        evidence=['STATIC_REVIEWED','SYNTHETIC_TESTED','ISOLATED_FULL_SCHEMA_TLS_TESTED','REAL_AIVEN_UNVERIFIED'])


if __name__=='__main__': raise SystemExit(h.main(profile='full94'))
