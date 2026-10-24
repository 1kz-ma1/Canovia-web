"""Build a deterministic OFFLINE_VERIFY_ONLY zipapp; never live adoption.

Only fixed public Git blobs, frozen audit assets and the pinned wheel enter.
No target/CA/password input, unpacker, approval parser, live launcher or network.
"""
import io
import json
import zipfile

from e858_b_offline import canonical,git
from . import b1_package as b, private_distribution as d
from .private_binding import Block

ENTRY='scripts/ci/e858_phase1/executable_verify_entry.py'


def build(root,head,wheel):
    try:
        review,pins=b.build(root,head,wheel)
        sources={};files={'review.zip':review,'dependency.whl':wheel}
        def add(name,raw):
            if not name.startswith('scripts/ci/') or not name.endswith('.py'):return
            name=name[len('scripts/ci/'):]
            if name in sources and sources[name]!=raw:raise Block()
            sources[name]=raw
        with zipfile.ZipFile(io.BytesIO(review)) as outer:
            for name in b.FILES+b.FROZEN:add(name,outer.read(name))
            with zipfile.ZipFile(io.BytesIO(outer.read('library.zip'))) as inner:
                for name in d.FILES:add(name,inner.read(name))
        forbidden=('transport.py','runtime.py','r1_adapter.py','r1_driver.py','control_contract.py','private_candidate.py','b1_bridge.py')
        if any('fixture' in name or name.rsplit('/',1)[-1] in forbidden for name in sources):raise Block()
        with zipfile.ZipFile(io.BytesIO(wheel)) as dep:
            for name in dep.namelist():
                if name.startswith('pymysql/') and name.endswith('.py'):sources[name]=dep.read(name)
        for package in ('e858_phase1','e858_tls','e858_audit'):
            sources[package+'/__init__.py']=b''
        if any(b'ci-only-tls-' in raw or b'SYNTHETIC_PASSWORD =' in raw for raw in sources.values()):raise Block()
        files.update({'sources/'+k:v for k,v in sources.items()})
        files['__main__.py']=git(root,'show',head+':'+ENTRY)
        manifest=dict(kind='E858_PHASE1_EXECUTABLE_OFFLINE_CANDIDATE_V1',profile='OFFLINE_VERIFY_ONLY',
            pins=pins,review_sha256=d._sha(review),wheel_sha256=d.WHEEL_SHA,
            files={k:d._sha(v) for k,v in files.items()},execution_allowed=False,
            live_entry='NOT_IMPLEMENTED',live_tooling_adopted=False,production_go=False)
        files['candidate-manifest.json']=canonical(manifest)
        return d.pack(files),manifest
    except BaseException:raise Block() from None
