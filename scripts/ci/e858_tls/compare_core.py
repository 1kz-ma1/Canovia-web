"""Pure frozen comparator adapter; no collector/observer/connection."""
from e858_audit.core import audit
from p0_mysql_full_ledger_offline_check import parse_full_ledger

def assess(data, inputs):
    facts,reference,contract=inputs
    raw=data['engine']+''.join(data[k] for k in ('table','column','index','foreign_key','check','trigger'))
    supplemental=''.join(data[k] for k in ('semantic_version','semantic_index','semantic_foreign_key'))
    ledger=parse_full_ledger(data['ledger'])
    return audit(facts,ledger,ledger,raw,supplemental,data['column_extra'],reference,contract)
