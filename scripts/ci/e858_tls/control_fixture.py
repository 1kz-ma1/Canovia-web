"""Owned full94 fixture evidence for the offline B1 model; never live tooling.

Generation continuity below comes from owned fixture objects. It does not
solve the observe-to-KILL race, failover or CID reuse in a real database.
"""
from dataclasses import replace
import hashlib
import json
from pathlib import Path
import secrets
import time

from control_contract import Attempt, Binding, ExpectedTarget, Observation, Block as ContractBlock
from fixtures import require, rows
from transport import Session, HOST, DB, USER


def run(admin, control):
    preflight=json.loads(Path('/synthetic/b_preflight.json').read_text())
    manifest=json.loads(Path('/synthetic/manifest.json').read_text())
    # Independently verified by bridge.assets before this fixture. No caller-
    # supplied bundle: immutable blobs already sealed by the existing Session.
    hashes={k:hashlib.sha256((Path('/synthetic/queries')/(k+'.sql')).read_bytes()).hexdigest()
            for k in manifest['caps']}
    target=hashlib.sha256((HOST+':3306/'+DB).encode()).hexdigest()
    ca=hashlib.sha256(Path('/synthetic/ca.pem').read_bytes()).hexdigest()
    san=hashlib.sha256(HOST.encode()).hexdigest()
    reports={}

    def begin(channel=control):
        session=Session(channel)
        try:
            session.read('identity');session.read('grants')
            require(admin.state(session.cid)['exists'])
            identity=rows(channel.conn,'SELECT @@server_uuid, CURRENT_USER(), CONNECTION_ID()')[0]
            require(identity[0]==channel.uuid and identity[1]==USER+'@%')
            expected=ExpectedTarget(target,identity[0],identity[1],ca,san)
            data=Binding(target,identity[0],identity[1],session.cid,secrets.token_hex(32),ca,san)
            observer=replace(data,cid=int(identity[2]),generation=secrets.token_hex(32))
            value=Attempt(preflight['packet'],preflight['pins'],preflight['pins'],hashes,int(time.time()),expected)
            seq=[0]
            def observation():
                state=channel.state(session.cid)
                if state['exists']:
                    found=rows(channel.conn,'SELECT USER, DB FROM information_schema.PROCESSLIST WHERE ID=%s',(session.cid,))
                    require(found==((USER,DB),))
                seq[0]+=1
                return Observation(data,observer,seq[0],int(time.time()),True,
                                   state['exists'],state['running'],True)
            initial=observation()
            value.bind(data,observer,initial,initial.observed_at,('OBSERVE','KILL_QUERY','KILL_CONNECTION'))
            return session,value,observation
        except BaseException:
            session.abort();raise

    # Actual own-principal idle -> absence feeds the contract. No root
    # observer is used as an authorization fallback; admin only cross-checks.
    s,value,observe=begin()
    try:
        idle=observe();result=value.observe(idle,idle.observed_at)
        require(result['query_ended'] and not result['connection_ended'])
        require(s.finish()['verified'])
        final=observe();result=value.finish(final,final.observed_at)
        require(result['connection_ended'] and result['query_ended'] and not result['execution_allowed'])
        require(admin.gone(s.cid)['verified'])
        reports['IDLE_THEN_ABSENCE']=dict(synthetic_contract_completed=True,real_execution_allowed=False)
    finally:
        s.abort()

    # A forged/reused CID or observer loss never produces an action. The
    # original data connection is still present and unchanged after rejection.
    for fault in ('CID_SUBSTITUTION','GENERATION_CHANGE','OBSERVER_DISCONNECT','SERVER_SWITCH'):
        from fixtures import Oracle
        channel=Oracle(role='inspector') if fault=='OBSERVER_DISCONNECT' else control
        s,value,observe=begin(channel)
        try:
            evidence=observe()
            if fault=='CID_SUBSTITUTION':evidence=replace(evidence,data=replace(evidence.data,cid=evidence.control.cid))
            elif fault=='GENERATION_CHANGE':evidence=replace(evidence,data=replace(evidence.data,generation=secrets.token_hex(32)))
            elif fault=='SERVER_SWITCH':evidence=replace(evidence,control=replace(evidence.control,server_uuid='22222222-2222-2222-2222-222222222222'))
            else:
                # Actual observer process disconnect, not just a false flag.
                channel.close()
                try:channel.state(s.cid)
                except (OSError,EOFError):pass
                else:raise ContractBlock('OBSERVER_DISCONNECT_NOT_REPRODUCED')
                evidence=replace(evidence,observer_connected=False)
            try:value.check_control('KILL_CONNECTION',evidence,evidence.observed_at)
            except ContractBlock:
                require(value.report()['remote']=='REMOTE_TERMINATION_UNVERIFIED')
                require(control.state(s.cid)['exists'])
                # Original TLS data connection remains unchanged: no KILL
                # has been generated, sent, or delegated by the contract.
                require(rows(s.conn,'SELECT CONNECTION_ID()')[0][0]==s.cid)
                require(not value.report()['execution_allowed'])
                reports[fault]=dict(blocked=True,original_session_preserved=True,control_action_sent=False)
            else:raise ContractBlock('CONTRACT_NEGATIVE_ACCEPTED')
        finally:
            if fault=='OBSERVER_DISCONNECT':
                # Fixture-only resource cleanup, not the lost channel's proof.
                s.conn.close();s.cancel_socket.close()
                require(admin.gone(s.cid)['verified'])
                require(value.report()['remote']=='REMOTE_TERMINATION_UNVERIFIED')
            else:
                require(s.finish()['verified'])
    return dict(result='B1_ISOLATED_CONTRACT_VERIFIED',cases=reports,
                cid_reuse_race_proven=False,real_execution_allowed=False)
