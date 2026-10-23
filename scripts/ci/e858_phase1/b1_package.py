"""Deterministic B1 offline-review bundle; deliberately no live launcher."""
import hashlib
import io
import json
import zipfile
from . import private_distribution as d
from .private_binding import Block
from e858_b_offline import canonical, decode, integrity, git, COLLECTOR

FILES=tuple('scripts/ci/'+p for p in ('e858_phase1/b1_operator.py','e858_phase1/b1_bridge.py',
    'e858_phase1/b1_integrity.py','e858_phase1/b1_package.py','e858_phase1/practical.py',
    'e858_phase1/phase1_application.py','e858_phase1/opener_candidate.py',
    'e858_phase1/r1_driver.py','e858_tls/bridge.py'))
KEYS=('engine','identity','grants','ledger','table','column','index','foreign_key','check','trigger',
      'semantic_version','semantic_index','semantic_foreign_key','column_extra')
FROZEN=tuple('scripts/ci/'+p for p in ('e858_audit/core.py','integrated_release20_audit.py',
    'integrated_release20_diagnose.py','integrated_release20_semantics.py','p0_mysql_full_ledger_offline_check.py'))
FROZEN+=tuple('scripts/ci/e858_audit/queries/'+k+'.sql' for k in KEYS)
FROZEN+=tuple('scripts/ci/e858_audit_reference/'+s+'.json' for s in ('8.0','8.4'))


DERIVED=('assets/facts.json','assets/contract.json')

def build(root,head,wheel):
    try:
        library,pins=d.build(root,head,wheel)
        files={'library.zip':library}
        files.update({p:git(root,'show',head+':'+p) for p in FILES})
        files.update({p:git(root,'show',COLLECTOR+':'+p) for p in FROZEN})
        from integrated_release20_audit import BASE
        def migrations(sha):
            return {p.rsplit('/',1)[-1][:-4]:meta.split()[2] for row in git(root,'ls-tree','-r',sha,'database/migrations').decode().splitlines()
                    for meta,p in [row.split('\t')] if p.endswith('.php')}
        reference=json.loads(files['scripts/ci/e858_audit_reference/8.0.json'])
        files[DERIVED[0]]=canonical(dict(baseline=migrations(BASE),candidate=migrations(pins['source_sha']),migration_seal=reference['migration_seal']))
        contract=json.loads(git(root,'show',pins['source_sha']+':resources/release20_pending_schema_contract.json'))
        files[DERIVED[1]]=canonical({k:v for k,v in contract.items() if k.startswith('absent_')})
        manifest=dict(kind='E858_B1_OFFLINE_REVIEW_BUNDLE_V1',pins=pins,
            files={p:d._sha(v) for p,v in files.items()},execution_allowed=False,production_go=False,live_backend='DISABLED_NO_LAUNCHER',
            owner_machine='UNVERIFIED',private_target='UNVERIFIED',b2_owner_go='NOT_GRANTED')
        files['b1-manifest.json']=canonical(manifest)
        raw=d.pack(files)
        verify(raw,d._sha(raw),pins)
        return raw,pins
    except BaseException:raise Block() from None


def verify(raw,expected_sha,pins):
    try:
        if type(raw) is not bytes or not 0<len(raw)<=d.CAP or d._sha(raw)!=expected_sha:raise Block()
        with zipfile.ZipFile(io.BytesIO(raw)) as archive:
            names=set(FILES)|set(FROZEN)|set(DERIVED)|{'library.zip','b1-manifest.json'}
            infos=archive.infolist()
            if (len(infos)!=len(names) or {i.filename for i in infos}!=names
                    or sum(i.file_size for i in infos)>d.CAP
                    or any(i.compress_type or i.flag_bits or i.external_attr!=(0o100644<<16) for i in infos)):raise Block()
            files={n:archive.read(n) for n in names}
        manifest=decode(files.pop('b1-manifest.json'))
        expected=dict(kind='E858_B1_OFFLINE_REVIEW_BUNDLE_V1',pins=pins,
            files={p:d._sha(v) for p,v in files.items()},execution_allowed=False,production_go=False,live_backend='DISABLED_NO_LAUNCHER',
            owner_machine='UNVERIFIED',private_target='UNVERIFIED',b2_owner_go='NOT_GRANTED')
        if canonical(manifest)!=canonical(expected):raise Block()
        d.verify(files['library.zip'],d._sha(files['library.zip']),pins)
        return dict(result='B1_OFFLINE_BUNDLE_VERIFIED',tooling_sha=pins['tooling_sha'],
            bundle_sha256=expected_sha,member_count=len(files)+1,execution_allowed=False,
            live_tooling_adopted=False,real_aiven_connected=False,production_go=False)
    except BaseException:raise Block() from None
