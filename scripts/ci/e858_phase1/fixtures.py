"""Fixed synthetic fault cases only; no KILL or long-running query fixtures."""
import json
import os
from pathlib import Path
import struct
import time
from unittest.mock import patch

from . import runtime as r
from .protocol import CONTROL_ID, PROCESS


def require(value):
    if not value:raise AssertionError('PHASE1_FIXTURE_ASSERTION')


def run():
    reports={};traces=[]
    original_wire=r.Wire
    original_read=r.Channel.read
    original_owned=r.Channel.check_owned
    original_write=r.Wire._write_bytes
    original_bytes=r.Wire._read_bytes
    current='NORMAL';last=None
    for fault in ('NORMAL','FREE_SQL','KILL_SQL','FULL_METADATA','TARGET_SWITCH','RECONNECT',
                  'CONTROL_DISCONNECT','UNOBSERVABLE','SERVER_SWITCH','CID_SUBSTITUTION',
                  'QUIT_WRITE_FAILURE','QUERY_TIMEOUT','STREAM_TIMEOUT','REMOTE_ABSENCE_UNVERIFIED',
                  'WRONG_CA','WRONG_HOST','EXPIRED_CERT','INTERRUPTED'):
        current=fault;wires=[];last=None;saved=[None]
        def factory(gate,fixture_fault=None):
            if fault in ('WRONG_CA','WRONG_HOST','EXPIRED_CERT') and gate.role=='data':
                fixture_fault={'WRONG_CA':'wrong_ca','WRONG_HOST':'wrong_host','EXPIRED_CERT':'expired'}[fault]
            value=original_wire(gate,fixture_fault);wires.append(value);return value
        def read(channel,sql,cap=1):
            role,used=channel.gate.role,channel.gate.used
            if fault in ('FREE_SQL','KILL_SQL','FULL_METADATA') and role=='control' and used==1:
                sql={'FREE_SQL':b'SELECT * FROM users;', 'KILL_SQL':b'KILL CONNECTION 1;',
                     'FULL_METADATA':b'SELECT migration FROM migrations;'}[fault]
            if fault=='TARGET_SWITCH' and role=='control' and used==1:channel.conn.select_db('information_schema')
            if fault=='RECONNECT' and role=='control' and used==1:channel.conn.connect()
            if fault=='INTERRUPTED' and role=='control' and used==3:raise KeyboardInterrupt()
            if fault=='STREAM_TIMEOUT' and role=='control' and used==1:channel.until=time.monotonic()-1
            if fault=='QUERY_TIMEOUT':channel.query_seconds=.02
            if fault=='CONTROL_DISCONNECT' and role=='control' and used==6:channel.drop()
            raw=original_read(channel,sql,cap)
            if role=='control' and sql.startswith(PROCESS):
                if raw:saved[0]=raw
                if fault=='UNOBSERVABLE':return ''
                if fault=='REMOTE_ABSENCE_UNVERIFIED' and not raw:return saved[0]
            if fault=='SERVER_SWITCH' and role=='control' and used==3:
                fields=raw.rstrip('\n').split('\t');fields[3]='22222222-2222-2222-2222-222222222222'
                return '\t'.join(fields)+'\n'
            return raw
        def owned(channel):
            if fault=='CID_SUBSTITUTION' and channel.gate.role=='data' and channel.gate.used==4:
                channel.cid+=10000
            return original_owned(channel)
        def write(wire,data):
            if fault=='QUIT_WRITE_FAILURE' and wire.gate.role=='data' and data==struct.pack('<iB',1,1):
                raise OSError('SYNTHETIC_QUIT_FAILURE')
            return original_write(wire,data)
        def read_bytes(wire,count):
            if fault=='QUERY_TIMEOUT' and wire.authenticated and wire.gate.used==2:time.sleep(.05)
            return original_bytes(wire,count)
        with patch.object(r,'Wire',side_effect=factory),patch.object(r.Channel,'read',read),\
             patch.object(r.Channel,'check_owned',owned),patch.object(original_wire,'_write_bytes',write),\
             patch.object(original_wire,'_read_bytes',read_bytes):
            last=r.Bootstrap();result=last.run()
            if fault=='NORMAL':
                if result['phase1']!='ISOLATED_PHASE1_VERIFIED':
                    Path('/out/error.json').write_text(json.dumps(dict(case=fault,frames=getattr(last,'private_frames',[]))))
                require(result['phase1']=='ISOLATED_PHASE1_VERIFIED')
                require(result['data_close_written'] and result['data_connection_absent'] and result['query_ended'])
                require(len(last.channels)==2 and all(c.conn.connect_count==1 for c in last.channels))
            else:
                require(result['phase1']=='BLOCK' and result['remote_termination']=='REMOTE_TERMINATION_UNVERIFIED')
                require(not result['data_connection_absent'] and not result['query_ended'])
            if fault in ('WRONG_CA','WRONG_HOST','EXPIRED_CERT'):
                require(result['certificate_failure']=={'WRONG_CA':'CHAIN','WRONG_HOST':'HOSTNAME','EXPIRED_CERT':'EXPIRED'}[fault])
            try:last.run()
            except r.Block:pass
            else:raise AssertionError('PHASE1_RETRY_ACCEPTED')
            require(not result['execution_allowed'] and not result['owner_approval_verified'])
            reports[fault]=result
            for wire in wires:
                if wire.gate.sent:
                    traces.append(dict(role=wire.gate.role,cid=int(wire.thread_id()),sql=wire.gate.sent,
                                       quit_written=wire.quit_written))
    (Path('/out')/'traces.json').write_text(json.dumps(traces));os.chmod('/out/traces.json',0o600)
    return dict(result='ISOLATED_PHASE1_ACCEPTED',cases=reports,real_aiven_connected=False,
                full17_executed=False,kill_executed=False,execution_allowed=False)


if __name__=='__main__':
    try:value=run();code=0
    except BaseException as error:
        import traceback
        debug=Path('/out/error.json')
        if not debug.exists():debug.write_text(json.dumps(dict(type=type(error).__name__,
            frames=[(Path(f.filename).name,f.lineno) for f in traceback.extract_tb(error.__traceback__)])))
        value=dict(result='BLOCK',code='PHASE1_FIXED_FIXTURE_FAILURE',real_aiven_connected=False);code=2
    print(json.dumps(value,sort_keys=True));raise SystemExit(code)
