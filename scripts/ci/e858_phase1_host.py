#!/usr/bin/env python3
"""Owned Docker Phase1 only. No real endpoint/credential/login-path switches."""
import argparse
import hashlib
import json
import os
from pathlib import Path
import shutil
import sys
import tempfile
import time
import uuid

sys.path.insert(0,str(Path(__file__).resolve().parent))
import e858_tls_host as h
from e858_b_offline import template, DECLARATIONS, validate

SETUP = (
    'CREATE TABLE migrations (id BIGINT PRIMARY KEY);',
    "CREATE USER 'e858_tls_inspector'@'%' IDENTIFIED BY 'ci-only-tls-inspector' REQUIRE SSL;",
    "GRANT REFERENCES ON canovia_p0_ci.* TO 'e858_tls_inspector'@'%';",
    "GRANT SELECT ON canovia_p0_ci.migrations TO 'e858_tls_inspector'@'%';",
    "SET GLOBAL log_output='TABLE';",
    'SET GLOBAL general_log=ON;',
    'SELECT @@server_uuid;',
)


def rehearse(series, head, profile='phase1'):
    if profile not in ('phase1', 'r1-driver'): raise h.Block('ISOLATED_PROFILE_REJECTED')
    token=uuid.uuid4().hex;stem='canovia-phase1-'+token[:12]
    network=stem+'-network';image=stem+'-client';owned=[];created=False
    with tempfile.TemporaryDirectory(prefix='canovia-phase1-') as temp:
        work=Path(temp);work.chmod(0o700)
        h.certificate_material(work)
        pins,_,_,_=h.assets(work/'client',head,series)
        digests={p.stem:hashlib.sha256(p.read_bytes()).hexdigest() for p in (work/'client/queries').iterdir()}
        for p in (work/'client/queries').iterdir():
            if p.stem not in ('identity','grants'):p.unlink()
        legacy=work/'client/legacy';legacy.mkdir()
        shutil.move(str(work/'client/p0_mysql_metadata_grants_guard.py'),str(legacy/'p0_mysql_metadata_grants_guard.py'))
        for name in ('e858_b_offline.py','p0_mysql_metadata_grants_input.py','release488_runtime_ddl_restore_gate.py'):
            (legacy/name).write_bytes(h.git(h.ROOT,'show',head+':scripts/ci/'+name))
        output=work/'out';output.mkdir(mode=0o700)
        build=work/'build';build.mkdir()
        try:
            h.run(['docker','network','create','--internal','--label',h.LABEL+'='+token,network]);created=True
            h.run([sys.executable,'-m','pip','download','--no-deps','--only-binary=:all:','--dest',str(build),'PyMySQL==1.1.2'])
            wheel=build/'pymysql-1.1.2-py3-none-any.whl'
            if hashlib.sha256(wheel.read_bytes()).hexdigest()!='e6b1d89711dd51f8f74b1631fe08f039e7d76cf67a42a323d3178f0f25762ed9':raise h.Block('DEPENDENCY_REJECTED')
            (build/'Dockerfile').write_text('FROM python:3.12-slim\nCOPY *.whl /tmp/\nRUN pip install --no-index /tmp/*.whl && rm /tmp/*.whl\n')
            h.run(['docker','build','--network=none','--label',h.LABEL+'='+token,'-t',image,str(build)],timeout=300)
            for kind,host in [('server','mysql.synthetic.invalid'),('expired','expired.synthetic.invalid')]:
                name=stem+'-'+kind;owned.append(name)
                args=['docker','run','-d','--name',name,'--label',h.LABEL+'='+token,'--network',network,'--network-alias',host,
                    '--tmpfs','/var/lib/mysql:rw,nosuid,size=512m','-v',str(work/kind)+':/certs:ro',
                    '-e','MYSQL_ROOT_PASSWORD='+h.ROOT_PASSWORD,'-e','MYSQL_ROOT_HOST=%','-e','MYSQL_DATABASE=canovia_p0_ci',
                    'mysql:'+series,'--ssl-ca=/certs/ca.pem','--ssl-cert=/certs/server.pem','--ssl-key=/certs/server-key.pem',
                    '--require-secure-transport=ON','--innodb-buffer-pool-size=32M','--innodb-redo-log-capacity=64M']
                if kind=='server':args[args.index('--tmpfs'):args.index('--tmpfs')]=['--network-alias','mismatch.synthetic.invalid']
                h.run(args)
                until=time.monotonic()+90
                while True:
                    try:
                        if mysql(name,'SELECT @@skip_networking;').strip()==b'0':break
                    except h.Block:pass
                    if time.monotonic()>until:raise h.Block('SERVER_NOT_READY')
                    time.sleep(.25)
                state=json.loads(h.run(['docker','inspect',name]))[0]
                net=json.loads(h.run(['docker','network','inspect',network]))[0]
                if (state['Config']['Labels'].get(h.LABEL)!=token or state['HostConfig']['PortBindings']
                        or set(state['NetworkSettings']['Networks'])!={network} or not net['Internal']
                        or net['Labels'].get(h.LABEL)!=token):raise h.Block('ISOLATION_REJECTED')
            server=stem+'-server'
            server_uuid=mysql(server,'\n'.join(SETUP)).decode().strip()
            packet=template(pins,int(time.time()));packet.update(declarations=DECLARATIONS,engine=series,attempt_id='a'*32)
            validate(packet,pins,int(time.time()))
            config=dict(kind='SYNTHETIC_PHASE1_ONLY',caps=json.loads((work/'client/manifest.json').read_text())['caps'],series=series,pins=pins,packet=packet,sql_digests=digests,
                execution_allowed=False,owner_approval_verified=False,
                expected=dict(uuid=server_uuid,binding=dict(target=hashlib.sha256(b'mysql.synthetic.invalid:3306/canovia_p0_ci').hexdigest(),
                    server_uuid=server_uuid,principal='e858_tls_inspector@%',
                    ca=hashlib.sha256((work/'client/ca.pem').read_bytes()).hexdigest(),
                    san=hashlib.sha256(b'mysql.synthetic.invalid').hexdigest())))
            if profile == 'r1-driver':
                config['r1'] = dict(kind='OWNED_DOCKER_R1_ONLY', host='mysql.synthetic.invalid', port=3306,
                    database='canovia_p0_ci', principal='e858_tls_inspector', san='mysql.synthetic.invalid',
                    source_sha=h.SOURCE, release_ref=pins['release_ref'], tooling_sha=head, sql_sha256=pins['sql_sha256'],
                    ca_sha256=config['expected']['binding']['ca'],
                    ipv4=json.loads(h.run(['docker','inspect',server]))[0]['NetworkSettings']['Networks'][network]['IPAddress'])
            (work/'client/phase1.json').write_text(json.dumps(config))
            name=stem+'-worker';owned.append(name)
            args=['docker','run','--name',name,'--label',h.LABEL+'='+token,'--network',network,'--read-only',
                '--tmpfs','/tmp:rw,nosuid,size=64m','--user',str(os.getuid())+':'+str(os.getgid()),
                '-v',str(h.ROOT/'scripts/ci')+':/code:ro','-v',str(work/'client')+':/synthetic:ro',
                '-v',str(output)+':/out:rw','-e','PYTHONPATH=/synthetic/legacy:/code',image,'python','-B','-m',('e858_phase1.r1_driver_fixtures' if profile == 'r1-driver' else 'e858_phase1.fixtures')]
            try:raw=h.run(args)
            except Exception:
                debug=output/'error.json'
                if debug.exists() and os.environ.get('GITHUB_ACTIONS')!='true':
                    dest=Path(tempfile.gettempdir())/('canovia-phase1-debug-'+str(os.getuid())+'.json')
                    dest.write_bytes(debug.read_bytes());dest.chmod(0o600)
                raise
            result=json.loads(raw)
            if result['result']!='ISOLATED_PHASE1_ACCEPTED':raise h.Block('PHASE1_FAILED')
            traces=json.loads((output/'traces.json').read_text())
            actual_ids={int(x) for x in mysql(server,"SELECT DISTINCT thread_id FROM mysql.general_log WHERE command_type='Query' AND argument='SET NAMES ascii';").decode().splitlines()}
            if actual_ids!={t['cid'] for t in traces}:raise h.Block('UNLISTED_CONNECTION')
            for trace in traces:
                cid=trace['cid']
                if type(cid) is not int or not 0<cid<2**32:raise h.Block('TRACE_REJECTED')
                raw=mysql(server,"SELECT HEX(argument) FROM mysql.general_log WHERE thread_id="+str(cid)+" AND command_type='Query' ORDER BY event_time;")
                logged=[bytes.fromhex(line).decode('ascii') for line in raw.decode().splitlines()]
                # Observation-only delimiter normalization, not execution policy.
                normalize=lambda s:s.strip().removesuffix(';')
                if list(map(normalize,logged))!=list(map(normalize,trace['sql'])):raise h.Block('UNLISTED_WIRE_SQL')
            result.update(tooling_sha=head,source_sha=h.SOURCE,series=series,wire_inventory_verified=True)
        finally:
            for name in reversed(owned):
                try:state=json.loads(h.run(['docker','inspect',name]))[0]
                except Exception:continue
                if state['Config']['Labels'].get(h.LABEL)!=token:raise h.Block('OWNERSHIP_REJECTED')
                h.run(['docker','rm','-f',name])
            if created:h.run(['docker','network','rm',network])
            for value in set(h.run(['docker','image','ls','-q','--filter','label='+h.LABEL+'='+token]).split()):
                h.run(['docker','image','rm',value.decode()])
            if h.run(['docker','ps','-aq','--filter','label='+h.LABEL+'='+token]).strip():raise h.Block('CLEANUP_UNVERIFIED')
    result['owned_fixture_cleanup_verified']=True
    return result


def mysql(server, sql):
    # Owned synthetic setup / independent wire oracle only; never a live API.
    return h.run(['docker','exec','-e','MYSQL_PWD='+h.ROOT_PASSWORD,server,'mysql','--protocol=socket','-uroot',
                  '--database=canovia_p0_ci','--batch','--raw','--skip-column-names','-e',sql])


def main(profile='phase1'):
    class Parser(argparse.ArgumentParser):
        def error(self,message):raise h.Block('ARGUMENTS_REJECTED')
    try:
        p=Parser(add_help=False,allow_abbrev=False)
        p.add_argument('--series',choices=['8.0','8.4'],required=True,action='append')
        p.add_argument('--tooling-sha',required=True,action='append')
        a=p.parse_args()
        if len(a.series)!=1 or len(a.tooling_sha)!=1:raise h.Block('ARGUMENTS_REJECTED')
        value=rehearse(a.series[0],a.tooling_sha[0],profile);code=0
    except BaseException as error:
        if os.environ.get('GITHUB_ACTIONS')!='true':
            import traceback
            p=Path(tempfile.gettempdir())/('canovia-phase1-host-'+str(os.getuid())+'.json')
            p.write_text(json.dumps(dict(type=type(error).__name__,frames=[(Path(f.filename).name,f.lineno) for f in traceback.extract_tb(error.__traceback__)])));p.chmod(0o600)
        value=dict(result='BLOCK',code='ISOLATED_PHASE1_BLOCK',real_aiven_connected=False,execution_allowed=False);code=2
    print(json.dumps(value,sort_keys=True));return code


if __name__=='__main__':raise SystemExit(main())
