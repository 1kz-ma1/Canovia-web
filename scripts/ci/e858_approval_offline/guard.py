"""Read-only local Git/source and private GitHub CI receipt checks. No network."""
import hashlib
import json
import os
from pathlib import Path
import re
import stat
import subprocess

from e858_approval_offline.contracts import Block,CANDIDATE,TOOLING,TRANSPORT,SQL,REFS,ORDER,CAPS
from e858_audit import core as source
from e858_transport_preflight import core as transport

ROOT=Path(__file__).resolve().parents[3]
FILES=(
    'scripts/ci/e858_approval_offline/contracts.py','scripts/ci/e858_approval_offline/guard.py',
    'scripts/ci/e858_approval_offline/packet.py','tests/ci/test_e858_approval_offline.py',
    'tests/ci/test_e858_approval_source.py','.github/workflows/e858-approval-offline.yml',
    'docs/development/integration/2026-10-18-b/e858-approval-offline-2026-10-20.md')
PINS={'main':CANDIDATE,'production':'15abdc9de6f24b5250fb255c579d1aa55facaf1a',
      'release/2026-10-19-e858b9c8':CANDIDATE,
      'release/2026-10-19-c09a8fc7':'c09a8fc718c338f98a4045a4b5f2260c7571fdaa',
      'docs/e858-production-readiness':'02143e26c70288a1f2b3551513015ac6be72e236',
      'audit/e858-source-bound-readiness':TOOLING,'audit/e858-transport-offline-preflight':TRANSPORT}


def git(root,*args):
    try:
        return subprocess.check_output(['git','-c','core.fsmonitor=false','-c','core.hooksPath=/nonexistent',
            '-C',str(root),*args],text=True,stderr=subprocess.DEVNULL,timeout=20).strip()
    except (Exception,KeyboardInterrupt):
        raise Block('LOCAL_GIT_REJECTED') from None


def tree(root,head):
    return {line.split('\t',1)[1]:line.split('\t',1)[0].split()
            for line in git(root,'ls-tree','-r',head).splitlines()}


def boundary(root,head):
    try:
        transport.environment_guard()
    except Exception:
        raise Block('ENVIRONMENT_OVERRIDE_REJECTED') from None
    if not re.fullmatch('[a-f0-9]{40}',head) or git(root,'rev-parse','HEAD')!=head:
        raise Block('VERIFIER_HEAD_REJECTED')
    if git(root,'rev-parse','--is-shallow-repository')!='false':
        raise Block('FULL_HISTORY_REQUIRED')
    if git(root,'status','--porcelain','--untracked-files=all'):
        raise Block('CLEAN_CHECKOUT_REQUIRED')
    if any(line[0]!='H' for line in git(root,'ls-files','-v').splitlines()):
        raise Block('INDEX_FLAGS_REJECTED')
    for name,sha in PINS.items():
        if git(root,'rev-parse','refs/remotes/origin/'+name)!=sha:
            raise Block('FIXED_REF_DRIFT')
    if git(root,'rev-parse',CANDIDATE+'^{tree}')!='f6990e8bcf7904be2fdc2706d6a525a29ccef9ee':
        raise Block('CANDIDATE_TREE_REJECTED')
    git(root,'merge-base','--is-ancestor',TRANSPORT,head)
    if set(git(root,'diff','--name-status',TOOLING,TRANSPORT).splitlines())!={'A\t'+p for p in transport.FILES}:
        raise Block('DEPENDENCY_SEVEN_FILE_CONTRACT_REJECTED')
    if set(git(root,'diff','--name-status',TRANSPORT,head).splitlines())!={'A\t'+p for p in FILES}:
        raise Block('NEW_SCOPE_REJECTED')
    inherited,current=tree(root,TRANSPORT),tree(root,head)
    if any(current.get(k)!=v for k,v in inherited.items()):
        raise Block('INHERITED_BLOB_CHANGED')
    for path,(mode,kind,blob) in current.items():
        file=root/path
        if (mode not in ('100644','100755') or kind!='blob' or file.is_symlink()
                or any(p.is_symlink() for p in file.parents) or not file.is_file()
                or file.stat().st_size>8*1024*1024):
            raise Block('TRACKED_FILE_REJECTED')
        with file.open('rb') as stream:
            raw=stream.read(8*1024*1024+1)
        if len(raw)>8*1024*1024 or hashlib.sha1(b'blob '+str(len(raw)).encode()+b'\0'+raw).hexdigest()!=blob:
            raise Block('TRACKED_FILE_REJECTED')
    facts=source.source_facts(root,CANDIDATE)
    if SQL!=transport.SQL_HASH or REFS!=transport.REFS or ORDER!=transport.ORDER or CAPS!=source.CAPS:
        raise Block('FROZEN_POLICY_CHANGED')
    if source.hashed(source.query_hashes(root))!=SQL:
        raise Block('SQL_BUNDLE_REJECTED')
    inputs=source.LEGACY+source.IMPLEMENTATION+source.SOURCE_FILES
    inputs += [p for p in tree(root,CANDIDATE) if p.startswith(source.PREFIXES)]
    blobs={p:current[p][2] for p in sorted(set(inputs))}
    for engine,expected in REFS.items():
        path=root/f'scripts/ci/e858_audit_reference/{engine}.json'
        if hashlib.sha256(path.read_bytes()).hexdigest()!=expected:
            raise Block('REFERENCE_HASH_REJECTED')
        source.load_reference(root,engine,facts,blobs,TOOLING)
    return facts


def private_receipts(directory):
    # This is exclusively a CI-evidence reader, not an approval/target/key loader.
    path=Path(directory).absolute()
    if path.is_symlink() or any(p.is_symlink() for p in path.parents):
        raise Block('CI_FILES_REJECTED')
    path=path.resolve(strict=True)
    if path.is_relative_to(ROOT) or not path.is_dir():
        raise Block('CI_FILES_REJECTED')
    info=path.stat()
    names={'tooling-ci.json','transport-ci.json','gate-ci.json'}
    if info.st_uid!=os.getuid() or info.st_mode & 0o077 or {p.name for p in path.iterdir()}!=names:
        raise Block('CI_FILES_REJECTED')
    out={}
    for name in sorted(names):
        file=path/name
        if file.is_symlink():
            raise Block('CI_FILES_REJECTED')
        fd=os.open(file,os.O_RDONLY|getattr(os,'O_NOFOLLOW',0)|getattr(os,'O_NONBLOCK',0))
        try:
            info=os.fstat(fd)
            if (not stat.S_ISREG(info.st_mode) or info.st_uid!=os.getuid() or info.st_mode & 0o077
                    or info.st_size>1024*1024):
                raise Block('CI_FILES_REJECTED')
            with os.fdopen(fd,'rb',closefd=False) as stream:
                raw=stream.read(1024*1024+1)
            if len(raw)>1024*1024:
                raise Block('CI_FILES_REJECTED')
            def unique(pairs):
                value={}
                for k,v in pairs:
                    if k in value:raise Block('CI_RECEIPT_REJECTED')
                    value[k]=v
                return value
            out[name]=json.loads(raw.decode('ascii'),object_pairs_hook=unique)
        finally:
            os.close(fd)
    return out


def receipts(values,head):
    # Keep dependency/new evidence separate. Receipts are NOT attestations.
    common=('migration-regression / upgrade (8.0)','migration-regression / upgrade (8.4)',
            'migration-regression / startup-policy',
            'fixed-diagnostic-regression / Schema diagnosis (MySQL 8.0)',
            'fixed-diagnostic-regression / Schema diagnosis (MySQL 8.4)')
    required={
        'tooling-ci.json':(TOOLING,'E858 Source-bound Audit',39211597992,
            ('E858 offline safety','E858 reference (MySQL 8.0)','E858 reference (MySQL 8.4)')+common),
        'transport-ci.json':(TRANSPORT,'E858 Transport Offline Preflight',39271348988,
            ('Transport offline safety','Transport disposable (MySQL 8.0)','Transport disposable (MySQL 8.4)')+common),
        'gate-ci.json':(head,'E858 Approval Offline Gate',None,
            ('Approval offline (Python 3.9)','Approval offline (Python 3.12)','Pinned dependency regressions'))}
    if set(values)!=set(required):
        raise Block('CI_RECEIPT_REJECTED')
    ids=[]
    for name,(sha,workflow,run,jobs) in required.items():
        try:
            number=transport.receipt(values[name],sha,workflow,jobs)
        except Exception:
            raise Block('CI_RECEIPT_REJECTED') from None
        if run is not None and number!=run:
            raise Block('CI_RECEIPT_REJECTED')
        ids.append(number)
    if len(set(ids))!=3:
        raise Block('CI_RECEIPT_REJECTED')
    return ids
