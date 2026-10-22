"""Integrated synthetic private files/PTY/real driver; owned network only."""
import hashlib
import json
import os
from pathlib import Path
import tempfile
import time
from unittest.mock import patch
from . import runtime as r
from . import b1_bridge as b
from . import private_binding as p
from .b1_operator import Entry
from .r1_driver_fixtures import trial
from . import r1_driver_fixtures as tty

CASES=('PHASE1','METADATA','STRICT_TAMPER','SQL','POSTFLIGHT_IDENTITY','CONTROL_LOST','INTERRUPT','QUERY_TIMEOUT','QUIT_FAILURE')


def child_run(fault,parent):
    if os.getppid()!=parent:raise p.Block()
    entry=Entry();bridge=None
    with tempfile.TemporaryDirectory(dir='/b1',prefix='private-') as private:
        os.chmod(private,0o700);root=Path(private)
        config=r.load();now=int(time.time());ca=Path('/synthetic/ca.pem').read_bytes()
        record=dict(kind='E858_PHASE1_PRIVATE_BINDING_V1',provenance='OWNER_PRIVATE_2026_10_21',
            source_sha=r.SOURCE,release_ref=r.RELEASE,tooling_sha=config['pins']['tooling_sha'],sql_sha256=p.SQL,
            q0_sha256=p.Q0_SHA,q1_sha256=p.Q1_SHA,starts_at=now-1,expires_at=now+120,
            target=dict(service='OWNED_DOCKER_ONLY',database=r.DB,host=r.HOST,port=3306,principal=r.USER),
            tls=dict(mode='VERIFY_IDENTITY',minimum='TLS1.2',san=r.HOST,ca_sha256=hashlib.sha256(ca).hexdigest()))
        raw=json.dumps(record).encode('ascii')
        for name,data in (('target.json',raw),('ca.pem',ca)):
            (root/name).write_bytes(data);(root/name).chmod(0o600)
        entry.begin(private,human_supervised=True)
        entry.bind(hashlib.sha256(raw).hexdigest(),config['pins']['tooling_sha'])
        original=b.Channel.read;write=r.Wire._write_bytes
        def read(channel,sql,cap=1):
            role=channel.gate.role;used=channel.gate.used
            if role=='data' and used==4:
                if fault=='SQL':return original(channel,b'SELECT * FROM users;',cap)
                if fault=='INTERRUPT':raise KeyboardInterrupt()
                if fault=='QUERY_TIMEOUT':channel.query_seconds=0
            if fault=='CONTROL_LOST' and role=='control' and used==3:channel.conn.close()
            result=original(channel,sql,cap)
            if fault=='POSTFLIGHT_IDENTITY' and role=='data' and used==5:return result.replace(r.DB,'synthetic_other')
            if fault=='STRICT_TAMPER' and sql==Path('/synthetic/queries/index.sql').read_bytes():
                lines=result.splitlines();fields=lines[0].split('\t');fields[2]='SYNTHETIC_DIFFERENT_INDEX'
                lines[0]='\t'.join(fields);result='\n'.join(lines)+'\n'
            return result
        def send(wire,data):
            if fault=='QUIT_FAILURE' and wire.gate.role=='data' and data==b'\x01\x00\x00\x00\x01':raise OSError()
            return write(wire,data)
        try:
            bridge=b.Bridge(entry,'metadata' if fault in ('METADATA','STRICT_TAMPER') else 'phase1')
            with patch.object(b.Channel,'read',read),patch.object(r.Wire,'_write_bytes',send):result=bridge.run()
            traces=[dict(role=w.gate.role,cid=w.thread_id(),sql=w.gate.sent,quit_written=w.quit_written)
                    for w in bridge.boundary.wires if w.gate.sent]
            if fault in ('PHASE1','METADATA','STRICT_TAMPER'):
                if (result['result']!='ISOLATED_B1_BRIDGE_VERIFIED' or not result['connection_ended']
                        or result['tls_connections']!=2 or result['sql_count']!=(30 if fault in ('METADATA','STRICT_TAMPER') else 15)):
                    raise p.Block()
                if fault=='METADATA' and result['strict_result']!='REVIEW_REQUIRED':raise p.Block()
                if fault=='STRICT_TAMPER' and result['strict_result']!='BLOCK':raise p.Block()
            elif result['result']!='BLOCK' or result['remote_termination']!='REMOTE_TERMINATION_UNVERIFIED':raise p.Block()
            if any(w.password or w.connect_count!=1 for w in bridge.boundary.wires):raise p.Block()
            try:bridge.run()
            except p.Block:pass
            else:raise p.Block()
            if entry.secret is not None or bridge.boundary.secret is not None:raise p.Block()
            result['transport_result']=result['result'];result['result']='B1_CASE_VERIFIED'
            result['traces']=traces
            return result
        finally:entry.close()


def child(fault,parent):
    try:return child_run(fault,parent)
    except BaseException as error:
        import traceback
        Path('/b1/failure.json').write_text(json.dumps(dict(case=fault,type=type(error).__name__,
            frames=[(Path(f.filename).name,f.lineno) for f in traceback.extract_tb(error.__traceback__)])))
        os.chmod('/b1/failure.json',0o600)
        raise


def run():
    reports={};traces=[]
    for fault in CASES:
        with patch.object(tty,'child_trial',side_effect=lambda _,parent:child(fault,parent)):
            value=trial('NORMAL')
        traces.extend(value.pop('traces'));reports[fault]=value
    Path('/b1/traces.json').write_text(json.dumps(traces));os.chmod('/b1/traces.json',0o600)
    return dict(result='B1_INTEGRATION_VERIFIED',cases=reports,real_aiven_connected=False,
        owner_approval_verified=False,execution_allowed=False,
        evidence=['SYNTHETIC_TESTED','ISOLATED_MYSQL_TLS_TESTED','REAL_AIVEN_UNVERIFIED'])


if __name__=='__main__':
    try:value=run();code=0
    except BaseException as error:
        if not Path('/b1/failure.json').exists():
            import traceback
            Path('/b1/failure.json').write_text(json.dumps(dict(type=type(error).__name__,context_code=(str(error.__context__) if type(error.__context__) is r.Block else 'NONE'),frames=[(Path(f.filename).name,f.lineno) for f in traceback.extract_tb(error.__traceback__)])))
        value=dict(result='BLOCK',code='ISOLATED_B1_FIXTURE_BLOCK',real_aiven_connected=False);code=2
    print(json.dumps(value,sort_keys=True));raise SystemExit(code)
