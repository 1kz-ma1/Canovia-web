"""Frozen #795 pure comparator bridged to #806 synthetic-only TLS Session."""
import hashlib
import json
from pathlib import Path
import time
from e858_b_offline import validate, Block as PreflightBlock

from transport import Block, Session
from e858_audit.core import audit, hashed, CANDIDATE, SEAL, RELEASE
from p0_mysql_full_ledger_offline_check import parse_full_ledger


def assets(directory=Path('/synthetic')):
    manifest=json.loads((directory/'manifest.json').read_text())
    raw=(directory/'reference.json').read_bytes()
    reference=json.loads(raw)
    facts=json.loads((directory/'facts.json').read_text())
    contract=json.loads((directory/'contract.json').read_text())
    preflight=json.loads((directory/'b_preflight.json').read_text())
    if (manifest['source_sha']!=CANDIDATE or hashlib.sha256(raw).hexdigest()!=manifest['reference_sha256']
            or reference['candidate_sha']!=CANDIDATE or reference['migration_seal']!=SEAL
            or facts['migration_seal']!=SEAL
            or hashed(facts['candidate'])!=reference['source_manifest_sha256']
            or hashed({k:sorted(facts[k]) for k in ('baseline','candidate')})!=reference['expected_sets_sha256']
            or hashed(contract)!=reference['pending_contract_sha256']
            or preflight['kind']!='SYNTHETIC_B1_ONLY' or preflight['owner_approval_verified'] is not False
            or preflight['execution_allowed'] is not False):
        raise Block('BRIDGE_ASSET_OR_APPROVAL_REJECTED')
    try: receipt=validate(preflight['packet'],preflight['pins'],int(time.time()))
    except PreflightBlock: raise Block('BRIDGE_PREFLIGHT_REJECTED') from None
    if (receipt!=preflight['receipt']
            or preflight['pins']['source_sha']!=CANDIDATE
            or preflight['pins']['release_ref']!=RELEASE
            or preflight['pins']['tooling_sha']!=manifest['tooling_sha']
            or preflight['pins']['sql_sha256']!=manifest['sql_sha256']
            or preflight['pins']['reference_sha256'][manifest['series']]!=manifest['reference_sha256']):
        raise Block('BRIDGE_PREFLIGHT_REJECTED')
    return facts,reference,contract


def assess(data, inputs):
    facts,reference,contract=inputs
    raw=data['engine']+''.join(data[k] for k in ('table','column','index','foreign_key','check','trigger'))
    supplemental=''.join(data[k] for k in ('semantic_version','semantic_index','semantic_foreign_key'))
    ledger=parse_full_ledger(data['ledger'])
    return audit(facts,ledger,ledger,raw,supplemental,data['column_extra'],reference,contract)


class Collector:
    """One synthetic attempt; no endpoint, credentials, retry or GO interface."""
    def __init__(self, observer):
        self.inputs=assets()
        self.observer=observer
        self.used=False

    def capture(self):
        if self.used: raise Block('NEW_OWNER_GO_REQUIRED')
        self.used=True
        session=Session(self.observer)
        self._data_cid=session.cid
        try:
            data=session.collect()
            proof=session.finish()
            if not proof['verified'] or proof.get('query_request_failed') or proof.get('connection_request_failed'):
                raise Block('REMOTE_TERMINATION_UNVERIFIED')
            result=assess(data,self.inputs)
            return result,dict(statements=session.executions,tls_session_count=session.conn.connect_count,
                               query_ended=proof['query_ended'],connection_ended=proof['connection_ended'])
        except Exception:
            session.abort()
            raise
