#!/usr/bin/env python3
"""Full94 B1 integration, internal Docker only. No real target arguments."""
import hashlib
import json
import os
from pathlib import Path
import sys
import time
sys.path.insert(0,str(Path(__file__).resolve().parent))
import e858_tls_host as h
from e858_phase1_host import mysql, SETUP
from e858_b_offline import template, DECLARATIONS


def exercise(work,stem,token,network,image,owned,head,series):
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
    migrate('old94')
    server=stem+'-server'
    server_uuid=mysql(server,'\n'.join(SETUP[1:])).decode().strip()
    client=work/'client';pins=json.loads((client/'b_preflight.json').read_text())['pins']
    packet=template(pins,int(time.time()));packet.update(declarations=DECLARATIONS,engine=series,attempt_id='a'*32)
    manifest=json.loads((client/'manifest.json').read_text())
    digests={p.stem:hashlib.sha256(p.read_bytes()).hexdigest() for p in (client/'queries').iterdir()}
    ca=hashlib.sha256((client/'ca.pem').read_bytes()).hexdigest()
    config=dict(kind='SYNTHETIC_PHASE1_ONLY',series=series,pins=pins,packet=packet,caps=manifest['caps'],
        execution_allowed=False,owner_approval_verified=False,sql_digests=digests,
        expected=dict(uuid=server_uuid,binding=dict(target=hashlib.sha256(b'mysql.synthetic.invalid:3306/canovia_p0_ci').hexdigest(),
            server_uuid=server_uuid,principal='e858_tls_inspector@%',ca=ca,san=hashlib.sha256(b'mysql.synthetic.invalid').hexdigest())),
        r1=dict(kind='OWNED_DOCKER_R1_ONLY',host='mysql.synthetic.invalid',port=3306,database='canovia_p0_ci',
            principal='e858_tls_inspector',san='mysql.synthetic.invalid',source_sha=h.SOURCE,release_ref=pins['release_ref'],
            tooling_sha=head,sql_sha256=pins['sql_sha256'],ca_sha256=ca,
            ipv4=json.loads(h.run(['docker','inspect',server]))[0]['NetworkSettings']['Networks'][network]['IPAddress']))
    (client/'phase1.json').write_text(json.dumps(config))
    name=stem+'-b1';owned.append(name)
    args=['docker','run','--name',name,'--label',h.LABEL+'='+token,'--network',network,'--read-only',
        '--tmpfs','/tmp:rw,nosuid,size=64m','--user',str(os.getuid())+':'+str(os.getgid()),
        '-v',str(h.ROOT/'scripts/ci')+':/code:ro','-v',str(client)+':/synthetic:ro',
        '-v',str(work/'out')+':/b1:rw','-e','PYTHONPATH=/synthetic/legacy:/code/e858_tls:/code',
        image,'python','-B','-m','e858_phase1.b1_fixtures']
    try:result=json.loads(h.run(args,timeout=240))
    except Exception:
        if os.environ.get('GITHUB_ACTIONS')!='true' and (work/'out/failure.json').exists():
            dest=Path('/tmp/canovia-b1-failure.json');dest.write_bytes((work/'out/failure.json').read_bytes());dest.chmod(0o600)
        raise
    if result.get('result')!='B1_INTEGRATION_VERIFIED':raise h.Block('ISOLATED_B1_WORKER_REJECTED')
    traces=json.loads((work/'out/traces.json').read_text())
    actual={int(x) for x in mysql(server,"SELECT DISTINCT thread_id FROM mysql.general_log WHERE command_type='Query' AND argument='SET NAMES ascii';").decode().splitlines()}
    if actual!={t['cid'] for t in traces}:raise h.Block('ISOLATED_UNLISTED_CONNECTION')
    for t in traces:
        if type(t['cid']) is not int or not 0<t['cid']<2**32:raise h.Block('ISOLATED_TRACE_REJECTED')
        logged=[bytes.fromhex(x).decode('ascii').strip().removesuffix(';') for x in mysql(server,
            "SELECT HEX(argument) FROM mysql.general_log WHERE thread_id="+str(t['cid'])+" AND command_type='Query' ORDER BY event_time;").decode().splitlines()]
        if logged!=[x.strip().removesuffix(';') for x in t['sql']]:raise h.Block('ISOLATED_UNLISTED_SQL')
        # Independent root fixture oracle, not a privilege requested for real audit.
        if mysql(server,'SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE ID='+str(t['cid'])+';').strip()!=b'0':
            raise h.Block('ISOLATED_REMOTE_REMAINS')
    result.update(tooling_sha=head,source_sha=h.SOURCE,series=series,wire_inventory_verified=True,
                  synthetic_oracle_connections_absent=True,production_modified=False)
    return result


if __name__=='__main__':
    # Reuse the unchanged, accepted owned network/Full94 lifecycle. Only the
    # synthetic exercise is replaced; there is no live factory selection.
    from unittest.mock import patch
    import e858_full94_tls
    with patch.object(e858_full94_tls,'exercise',exercise):
        raise SystemExit(h.main(profile='full94'))
