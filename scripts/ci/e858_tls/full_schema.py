"""Full frozen schema fixture; runs only on owned internal Docker network."""
import json
import os
from pathlib import Path
import socket
import threading
import time

from transport import Block, Session, Observer, connection, rows as client_rows, CELL, ROW, SECTION, TOTAL
from fixtures import Oracle, rows, require, wait_running, close_client, swallow, private_error
from bridge import Collector, assess, assets


def setup(admin):
    rows(admin.conn,"CREATE USER 'e858_tls_inspector'@'%' IDENTIFIED BY 'ci-only-tls-inspector' REQUIRE SSL")
    rows(admin.conn,"CREATE USER 'e858_tls_other'@'%' IDENTIFIED BY 'ci-only-other' REQUIRE SSL")
    rows(admin.conn,"GRANT REFERENCES ON canovia_p0_ci.* TO 'e858_tls_inspector'@'%'")
    rows(admin.conn,"GRANT SELECT ON canovia_p0_ci.migrations TO 'e858_tls_inspector'@'%'")
    rows(admin.conn,"GRANT REFERENCES ON canovia_p0_ci.* TO 'e858_tls_other'@'%'")


def root_reference(admin):
    # Privileged fixture oracle evidence is separate from the narrow collector.
    # Fixed SQL only, no application-row reads or inspector privilege widening.
    manifest=json.loads(Path('/synthetic/manifest.json').read_text()); data={}; total=0
    begin=time.monotonic()
    for key in manifest['caps']:
        if key in ('identity','grants'): continue
        values=rows(admin.conn,(Path('/synthetic/queries')/(key+'.sql')).read_text())
        require(len(values)<=manifest['caps'][key])
        lines=[]
        for value in values:
            cells=[('NULL' if c is None else str(c)).encode('ascii') for c in value]
            require(all(len(c)<=CELL for c in cells))
            line=b'\t'.join(cells)+b'\n';require(len(line)<=ROW);lines.append(line)
        raw=b''.join(lines);total+=len(raw)
        require(len(raw)<=SECTION and total<=TOTAL and time.monotonic()-begin<120)
        data[key]=raw.decode('ascii')
    return assess(data,assets())


def capture(admin, own, expected_stage, strict):
    collector=Collector(own)
    result,proof=collector.capture()
    require(result['stage']==expected_stage and result['strict_result']==strict,dict(stage=result['stage'],strict=result['strict_result'],code=result['strict_code'],sections=result['sections']))
    require(proof==dict(statements=17,tls_session_count=1,query_ended=True,connection_ended=True))
    require(not result['release_authorized'] and not result['strict_block_waived'])
    allowed={(Path('/synthetic/queries')/(k+'.sql')).read_text().strip() for k in json.loads(Path('/synthetic/manifest.json').read_text())['caps']}
    allowed.update(('SET NAMES ascii','SELECT CONNECTION_ID()','SET SESSION max_execution_time=10000'))
    log=rows(admin.conn,"SELECT argument FROM mysql.general_log WHERE thread_id=%s AND command_type='Query'",(collector._data_cid,))
    logged=[r[0].decode('ascii') if isinstance(r[0],bytes) else r[0] for r in log]
    require(len(log)>17 and all(text.strip() in allowed for text in logged),
            dict(statement_count=len(log),unknown_statement_count=sum(text.strip() not in allowed for text in logged)))
    proof.update(data_sql_allowlist_observed=True,application_select_count=0)
    try: collector.capture()
    except Block as error: require(str(error)=='NEW_OWNER_GO_REQUIRED')
    else: raise Block('SECOND_CAPTURE_ACCEPTED')
    return result,proof


def negative(own, kind):
    s=Session(own)
    try:
        if kind in ('sql','export'):
            s.read('identity', b'SELECT * FROM users;' if kind=='sql' else b'SELECT 1 INTO OUTFILE "/tmp/forbidden";')
        elif kind=='target':
            client_rows(s.conn,'USE information_schema');s.read('identity')
        elif kind=='swap':
            s.read('identity'); replacement=connection();s.conn=replacement
            try:s.read('grants')
            finally:
                if replacement.open:replacement.close()
                if s.original.open:s.original.close()
        else:raise Block('FIXTURE_KIND_REJECTED')
    except Exception as error:
        expected={'sql':'SQL_NOT_ALLOWLISTED','export':'SQL_NOT_ALLOWLISTED','target':'IDENTITY_CHANGED','swap':'SESSION_CHANGED'}
        require(isinstance(error,Block) and str(error)==expected[kind])
        require(s.abort()['verified'])
        try:s.read('identity')
        except Block:return dict(blocked=True,no_retry=True,remote_connection_ended=True)
    finally:
        s.abort()
    raise Block('NEGATIVE_ACCEPTED')


def run():
    stage=os.environ.get('E858_SYNTHETIC_STAGE')
    require(stage in ('old94','all114'))
    admin=Oracle();own=None;reports={};current='BOOTSTRAP'
    def case(name,func):
        nonlocal current
        current=name;reports[name]=func()
    try:
        version=rows(admin.conn,'SELECT VERSION()')[0][0]
        if stage=='old94':setup(admin)
        own=Oracle(role='inspector')
        expected='baseline94' if stage=='old94' else 'candidate114'
        case('STRICT_ROOT_REFERENCE',lambda:summary(root_reference(admin),expected,'REVIEW_REQUIRED'))
        # Log only in the disposable server; inspect privately per data CID.
        rows(admin.conn,"SET GLOBAL log_output='TABLE'");rows(admin.conn,'SET GLOBAL general_log=ON')
        def narrow():
            strict='REVIEW_REQUIRED' if stage=='old94' else 'BLOCK'
            result,proof=capture(admin,own,expected,strict)
            if stage=='all114':
                require(result['strict_code']=='TRIGGER_ABSENT_OR_NOT_VISIBLE')
                require(all(r['classification']=='MATCH' for r in result['sections'] if r['section']!='trigger'))
            return dict(summary(result,expected,strict),**proof)
        case('FULL_NARROW_SINGLE_TLS',narrow)
        if stage=='old94':
            for kind in ('sql','export','target','swap'):case('DENY_'+kind.upper(),lambda k=kind:negative(own,k))
            def alias():
                rows(admin.conn,'ALTER TABLE intelligence_decision_traces RENAME INDEX intelligence_decision_traces_domain_index TO e858_synthetic_full_alias')
                try:
                    result,_=capture(admin,own,'baseline94','BLOCK')
                    require(result['semantic_sections'][0]['classification']=='NAME_ONLY_DIFFERENCE')
                    return dict(strict_result='BLOCK',classification='NAME_ONLY_DIFFERENCE')
                finally:rows(admin.conn,'ALTER TABLE intelligence_decision_traces RENAME INDEX e858_synthetic_full_alias TO intelligence_decision_traces_domain_index')
            case('STRICT_NAME_ONLY_BLOCK',alias)
            for kind in ('excess','missing'):
                def grants(k=kind):
                    if k=='excess':rows(admin.conn,"GRANT SELECT ON canovia_p0_ci.* TO 'e858_tls_inspector'@'%'")
                    else:rows(admin.conn,"REVOKE SELECT ON canovia_p0_ci.migrations FROM 'e858_tls_inspector'@'%'")
                    try:
                        try:Collector(own).capture()
                        except Block as error:require(str(error)=='MINIMAL_GRANTS_BLOCK');return dict(blocked_before_schema=True)
                        raise Block('GRANTS_ACCEPTED')
                    finally:
                        if k=='excess':rows(admin.conn,"REVOKE SELECT ON canovia_p0_ci.* FROM 'e858_tls_inspector'@'%'")
                        else:rows(admin.conn,"GRANT SELECT ON canovia_p0_ci.migrations TO 'e858_tls_inspector'@'%'")
                case('GRANTS_'+kind.upper(),grants)
            def timeout():
                s=Session(own,query_seconds=.2)
                try:s._execute('SELECT SLEEP(30)',1)
                except Block as error:
                    require(str(error)=='QUERY_TIMEOUT')
                    running=admin.state(s.cid)['running'];proof=s.abort()
                    require(proof['verified'] and admin.gone(s.cid)['verified'])
                    return dict(remote_running_after_local_stop=running,remote_termination_verified=True)
                finally:s.abort()
                raise Block('TIMEOUT_ACCEPTED')
            case('SAME_PRINCIPAL_REMOTE_TERMINATION',timeout)
            def invisible():
                control=Oracle(role='inspector',fault='unobservable')
                try:
                    try:Collector(control).capture()
                    except Block as error:
                        require(str(error)=='REMOTE_VISIBILITY_UNPROVEN')
                        return dict(blocked_before_metadata=True,remote_status='REMOTE_TERMINATION_UNVERIFIED')
                    raise Block('INVISIBLE_CONTROL_ACCEPTED')
                finally:control.close()
            case('OBSERVER_INVISIBLE',invisible)
            for fault in ('kill_query','kill_connection'):
                def failed_stop(f=fault):
                    control=Oracle(role='inspector',fault=f)
                    c=connection();cid=c.thread_id()
                    thread=threading.Thread(target=lambda:swallow(lambda:client_rows(c,'SELECT SLEEP(30)')))
                    thread.start();wait_running(admin,cid)
                    try:
                        proof=control.terminate(cid)
                        require(proof['query_request_failed' if f=='kill_query' else 'connection_request_failed'])
                        if f=='kill_connection':require(not proof['verified'] and proof['status']=='REMOTE_TERMINATION_UNVERIFIED')
                        return dict(audit_result='BLOCK',original_control_proof=proof,cleanup_is_not_a_waiver=True)
                    finally:
                        close_client(c,thread);require(admin.terminate(cid)['verified']);control.close()
                case('REMOTE_'+fault.upper(),failed_stop)
        # Return only fixed classifier/counts, never raw values or error text.
        require(not any(r.get('release_authorized',False) for r in reports.values()))
        return dict(result='FULL_SCHEMA_STAGE_VERIFIED',fixture_stage=stage,version=version,cases=reports,
            source_sha='e858b9c855970fbe73f37a2d60c9db6525b132d6',real_aiven_connected=False,
            production_modified=False,release_authorized=False,owner_approval_verified=False,
            trigger_visibility='UNVERIFIED',inspector_privileges_added=False,synthetic_b1_only=True)
    except BaseException as error:
        private_error(error)
        return dict(result='BLOCK',case=current,real_aiven_connected=False,release_authorized=False)
    finally:
        if own:own.close()
        admin.close()


def summary(result,stage,strict):
    require(result['stage']==stage and result['strict_result']==strict,dict(stage=result['stage'],strict=result['strict_result'],code=result['strict_code'],sections=result['sections']))
    require(result['result']=='BLOCK_REAL_GATES_UNVERIFIED')
    return dict(stage=stage,applied_count=result['applied_count'],strict_result=strict,
        strict_code=result['strict_code'],overall_result=result['result'],trigger_visibility=result['trigger_visibility'],
        sections=result['sections'])


if __name__=='__main__':
    try:value=run()
    except BaseException as error:
        private_error(error);value=dict(result='BLOCK',case='CLEANUP_OR_BOOTSTRAP',real_aiven_connected=False)
    print(json.dumps(value,sort_keys=True))
    raise SystemExit(0 if value['result']=='FULL_SCHEMA_STAGE_VERIFIED' else 2)
