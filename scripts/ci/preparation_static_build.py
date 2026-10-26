#!/usr/bin/env python3
"""Allowlisted public assets only; build candidates, never deploy or approve."""
import argparse
import hashlib
import json
import os
from pathlib import Path
import stat
import subprocess
import sys
import zipfile

ROOT = Path(__file__).resolve().parents[2]
HEADERS = {
    'Content-Security-Policy': "default-src 'none'; script-src 'self'; style-src 'self'; img-src 'self'; connect-src 'none'; worker-src 'none'; object-src 'none'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'",
    'Referrer-Policy': 'no-referrer', 'X-Content-Type-Options': 'nosniff',
    'Permissions-Policy': 'camera=(), microphone=(), geolocation=(), payment=()',
    'Cache-Control': 'no-store',
}
MODULES = ('app', 'data', 'scoring', 'model', 'backup', 'backup-codec', 'backup-ui', 'plan-ui')
MAX_MEMBER=2*1024*1024
def clean_source():
    if subprocess.check_output(['git','status','--porcelain'],cwd=ROOT).strip():raise ValueError('CLEAN_SOURCE_REQUIRED')
    return subprocess.check_output(['git','rev-parse','HEAD'],cwd=ROOT,text=True).strip()
def source_file(source,path,inputs):
    entry=subprocess.check_output(['git','ls-tree',source,'--',path],cwd=ROOT).decode().strip().split()
    if len(entry)!=4 or entry[0]!='100644' or entry[1]!='blob' or entry[3]!=path:raise ValueError('SOURCE_FILE_REJECTED')
    inputs[path]=entry[2]
    return subprocess.check_output(['git','cat-file','blob',entry[2]],cwd=ROOT)
def repository_bytes(raw):
    raw=raw.decode()
    marker="export const DB_NAME = 'canovia-local-preview-v1';"
    if raw.count(marker)!=1:raise ValueError('REPOSITORY_SOURCE_CHANGED')
    return raw.replace(marker,"export const DB_NAME = 'canovia-preparation-v1';").encode()
def digest(raw): return hashlib.sha256(raw).hexdigest()

def candidate_files(source,profile):
    if profile not in ('B','C'): raise ValueError('PROFILE_NOT_IMPLEMENTED')
    inputs={}
    read=lambda p:source_file(source,p,inputs)
    files={'index.html':read('preparation-local/index.html'),'style.css':read('preparation-local/style.css'),
        'preparation.css':read('resources/css/preparation.css')}
    for module in MODULES:files['src/'+module+'.mjs']=read(f'preparation-local/src/{module}.mjs')
    files['src/repository.mjs']=repository_bytes(read('local-preview/src/repository.mjs')) if profile=='B' else read('preparation-local/src/memory-repository.mjs')
    files['src/settings.mjs']=(f"export const PROFILE='{profile}';\nexport const ADOPTED_ORIGIN=null;\n").encode()
    codec=read('local-preview/src/backup.mjs').decode()
    expected="// Shared canonical/checksum primitive from local-preview; generated, no authority.\nimport {requireValue} from './model.mjs';\n"+codec[codec.index('export function canonical'):codec.index('export async function exportJSON')].replace('1000000','100000')
    if files['src/backup-codec.mjs']!=expected.encode():raise ValueError('CODEC_SOURCE_CHANGED')
    for p in ('config/prelaunch_interest_diagnostic.php','config/canovia_public_roadmap.php','app/Services/PrelaunchInterestScoringService.php'):read(p)
    for category in ('st','dv','cr','wk','wb','fn','ct','lf'):
        files[f'assets/{category}.webp']=read(f'public/images/preparation/{category}.webp')
    files['assets/canovia-wordmark.png']=read('public/brand/canovia-wordmark.png')
    files['_headers']=('/*\n'+''.join(f'  {k}: {v}\n' for k,v in HEADERS.items())).encode()
    if any(len(v)>MAX_MEMBER for v in files.values()) or sum(map(len,files.values()))>8*1024*1024:raise ValueError('ASSET_CAP')
    return files,inputs
def manifest_for(source,profile,files,inputs):
    return dict(kind='PREPARATION_STATIC_CANDIDATE_V1',profile=profile,source_sha=source,source_tree=subprocess.check_output(['git','rev-parse',source+'^{tree}'],cwd=ROOT,text=True).strip(),source_inputs=inputs,
        files={n:digest(v) for n,v in sorted(files.items())},adopted_origin=None,release_approved=False,
        service_worker=False,canonical_connected=False)
def canonical_manifest(value):return (json.dumps(value,sort_keys=True,ensure_ascii=False,separators=(',',':'))+'\n').encode()
def public_file(root,name,cap):
    parts=Path(name).parts
    if not parts or Path(name).is_absolute() or any(p in ('.','..') for p in parts):raise ValueError('ASSET_PATH')
    fds=[]
    try:
        fd=os.open(root,os.O_RDONLY|os.O_DIRECTORY|os.O_NOFOLLOW|os.O_CLOEXEC);fds.append(fd)
        for part in parts[:-1]:fd=os.open(part,os.O_RDONLY|os.O_DIRECTORY|os.O_NOFOLLOW|os.O_CLOEXEC,dir_fd=fd);fds.append(fd)
        fd=os.open(parts[-1],os.O_RDONLY|os.O_NONBLOCK|os.O_NOFOLLOW|os.O_CLOEXEC,dir_fd=fd);fds.append(fd)
        before=os.fstat(fd)
        if not stat.S_ISREG(before.st_mode) or before.st_nlink!=1 or not 0<before.st_size<=cap:raise ValueError('ASSET_TYPE_CAP')
        raw=bytearray()
        while len(raw)<=cap:
            b=os.read(fd,min(65536,cap+1-len(raw)))
            if not b:break
            raw.extend(b)
        after=os.fstat(fd);seal=lambda s:(s.st_dev,s.st_ino,s.st_size,s.st_mtime_ns,s.st_ctime_ns)
        if len(raw)!=before.st_size or seal(before)!=seal(after):raise ValueError('ASSET_CHANGED')
        return bytes(raw)
    finally:
        for fd in reversed(fds):os.close(fd)
def verify_directory(directory):
    root=Path(directory)
    if root.is_symlink() or not root.is_dir():raise ValueError('BAD_DIRECTORY')
    raw=public_file(root,'manifest.json',65536);meta=json.loads(raw)
    source=clean_source()
    if not isinstance(meta,dict) or meta.get('source_sha')!=source:raise ValueError('SOURCE_MISMATCH')
    files,inputs=candidate_files(source,meta.get('profile'))
    if raw!=canonical_manifest(manifest_for(source,meta['profile'],files,inputs)):raise ValueError('MANIFEST_MISMATCH')
    expected=set(files)|{'manifest.json'};actual=set()
    for count,path in enumerate(root.rglob('*'),1):
        if count>32:raise ValueError('ASSET_COUNT_CAP')
        name=path.relative_to(root).as_posix()
        if path.is_symlink():raise ValueError('ASSET_LINK')
        if path.is_dir():
            if name not in ('src','assets'):raise ValueError('EXTRA_DIRECTORY')
        else:actual.add(name)
    if actual!=expected:raise ValueError('EXTRA_OR_MISSING_ASSET')
    for name,value in files.items():
        if public_file(root,name,MAX_MEMBER)!=value:raise ValueError('ASSET_BYTES_MISMATCH')
    return files
def verify_archive(archive):
    """No extraction: compare every ZIP entry with frozen Git bytes before use."""
    source=clean_source()
    raw=public_file(Path(archive).parent,Path(archive).name,10*1024*1024)
    import io
    with zipfile.ZipFile(io.BytesIO(raw)) as z:
        names=z.namelist()
        if len(names)>32 or len(names)!=len(set(names)):raise ValueError('ZIP_INVENTORY')
        for info in z.infolist():
            parts=info.filename.split('/')
            if (info.filename.startswith('/') or any(p in ('','.','..') for p in parts) or
                '\\' in info.filename or info.flag_bits&1 or info.compress_type!=zipfile.ZIP_STORED or
                info.file_size>MAX_MEMBER or info.compress_size!=info.file_size or
                stat.S_IFMT(info.external_attr>>16)!=stat.S_IFREG):raise ValueError('ZIP_ENTRY')
        meta=json.loads(z.read('manifest.json'))
        if not isinstance(meta,dict):raise ValueError('ZIP_MANIFEST')
        files,inputs=candidate_files(source,meta.get('profile'))
        files['manifest.json']=canonical_manifest(manifest_for(source,meta['profile'],files,inputs))
        if set(names)!=set(files) or any(z.read(n)!=b for n,b in files.items()):raise ValueError('ZIP_BYTES')
    return dict(source_sha=source,profile=meta['profile'],sha256=digest(raw),release_approved=False)

def build(output, profile='B'):
    source=clean_source();files,inputs=candidate_files(source,profile)
    files['manifest.json']=canonical_manifest(manifest_for(source,profile,files,inputs))
    output=Path(output);output.mkdir(mode=0o700,parents=False,exist_ok=False)
    for name,raw in files.items():
        p=output/name;p.parent.mkdir(exist_ok=True);p.write_bytes(raw)
    archive=output.with_suffix('.zip')
    with archive.open('xb') as stream,zipfile.ZipFile(stream,'w',compression=zipfile.ZIP_STORED) as z:
        for name,raw in sorted(files.items()):
            info=zipfile.ZipInfo(name,(1980,1,1,0,0,0));info.create_system=3;info.external_attr=0o100644<<16;z.writestr(info,raw)
    return dict(source_sha=source,profile=profile,sha256=digest(archive.read_bytes()),files=len(files),release_approved=False)

if __name__ == '__main__':
    class Parser(argparse.ArgumentParser):
        def error(self,message):raise ValueError('ARGUMENTS_REJECTED')
    try:
        p=Parser();g=p.add_mutually_exclusive_group(required=True);g.add_argument('--out');g.add_argument('--verify-zip');p.add_argument('--profile',choices=['B','C'],default='B')
        a=p.parse_args();print(json.dumps(verify_archive(a.verify_zip) if a.verify_zip else build(a.out,a.profile)))
    except (OSError,ValueError,KeyError,RecursionError,zipfile.BadZipFile,subprocess.SubprocessError):
        print('PREPARATION_BUILD_BLOCK',file=sys.stderr);sys.exit(2)
