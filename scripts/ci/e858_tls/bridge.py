"""Frozen #795 pure comparator bridged to #806 synthetic-only TLS Session."""
import hashlib
import json
from pathlib import Path

from transport import Block, Session
from e858_audit.core import audit, hashed, CANDIDATE, SEAL
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
            or facts['migration_seal']!=SEAL or hashed(contract)!=reference['pending_contract_sha256']
            or preflight['kind']!='SYNTHETIC_B1_ONLY' or preflight['owner_approval_verified'] is not False
            or preflight['execution_allowed'] is not False):
        raise Block('BRIDGE_ASSET_OR_APPROVAL_REJECTED')
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
        try:
            data=session.collect()
            proof=session.finish()
            if not proof['verified']: raise Block('REMOTE_TERMINATION_UNVERIFIED')
            result=assess(data,self.inputs)
            return result,dict(statements=session.executions,tls_session_count=session.conn.connect_count,
                               query_ended=proof['query_ended'],connection_ended=proof['connection_ended'])
        except Exception:
            session.abort()
            raise
