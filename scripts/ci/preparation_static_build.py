#!/usr/bin/env python3
"""Allowlisted public assets only; build candidates, never deploy or approve."""
import argparse
import hashlib
import json
from pathlib import Path
import subprocess
import zipfile

ROOT = Path(__file__).resolve().parents[2]
HEADERS = {
    'Content-Security-Policy': "default-src 'none'; script-src 'self'; style-src 'self'; img-src 'self'; connect-src 'none'; worker-src 'none'; object-src 'none'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'",
    'Referrer-Policy': 'no-referrer', 'X-Content-Type-Options': 'nosniff',
    'Permissions-Policy': 'camera=(), microphone=(), geolocation=(), payment=()',
    'Cache-Control': 'no-store',
}
MODULES = ('app', 'data', 'scoring', 'model', 'backup', 'backup-codec', 'backup-ui', 'plan-ui')
def repository_bytes():
    raw=(ROOT/'local-preview/src/repository.mjs').read_text()
    marker="export const DB_NAME = 'canovia-local-preview-v1';"
    if raw.count(marker)!=1:raise ValueError('REPOSITORY_SOURCE_CHANGED')
    return raw.replace(marker,"export const DB_NAME = 'canovia-preparation-v1';").encode()
def digest(raw): return hashlib.sha256(raw).hexdigest()

def build(output, profile='B'):
    if profile not in ('B','C'): raise ValueError('PROFILE_NOT_IMPLEMENTED')
    if subprocess.check_output(['git', 'status', '--porcelain'], cwd=ROOT).strip():
        raise ValueError('CLEAN_SOURCE_REQUIRED')
    source = subprocess.check_output(['git', 'rev-parse', 'HEAD'], cwd=ROOT, text=True).strip()
    files = {'index.html': (ROOT/'preparation-local/index.html').read_bytes(),
        'style.css': (ROOT/'preparation-local/style.css').read_bytes(),
        'preparation.css': (ROOT/'resources/css/preparation.css').read_bytes()}
    for module in MODULES: files['src/'+module+'.mjs']=(ROOT/f'preparation-local/src/{module}.mjs').read_bytes()
    files['src/repository.mjs']=repository_bytes() if profile=='B' else (ROOT/'preparation-local/src/memory-repository.mjs').read_bytes()
    files['src/settings.mjs']=(f"export const PROFILE='{profile}';\nexport const ADOPTED_ORIGIN=null;\n").encode()
    for category in ('st','dv','cr','wk','wb','fn','ct','lf'):
        files[f'assets/{category}.webp']=(ROOT/f'public/images/preparation/{category}.webp').read_bytes()
    files['assets/canovia-wordmark.png']=(ROOT/'public/brand/canovia-wordmark.png').read_bytes()
    files['_headers']=('/*\n'+''.join(f'  {k}: {v}\n' for k,v in HEADERS.items())).encode()
    manifest=dict(kind='PREPARATION_STATIC_CANDIDATE_V1',profile=profile,source_sha=source,
        files={n:digest(v) for n,v in sorted(files.items())},adopted_origin=None,release_approved=False,
        service_worker=False,canonical_connected=False)
    files['manifest.json']=(json.dumps(manifest,sort_keys=True,ensure_ascii=False,separators=(',',':'))+'\n').encode()
    output=Path(output);output.mkdir(mode=0o700,parents=False,exist_ok=False)
    for name,raw in files.items():
        p=output/name;p.parent.mkdir(exist_ok=True);p.write_bytes(raw)
    archive=output.with_suffix('.zip')
    with archive.open('xb') as stream,zipfile.ZipFile(stream,'w',compression=zipfile.ZIP_STORED) as z:
        for name,raw in sorted(files.items()):
            info=zipfile.ZipInfo(name,(1980,1,1,0,0,0));info.create_system=3;info.external_attr=0o100644<<16;z.writestr(info,raw)
    return dict(source_sha=source,profile=profile,sha256=digest(archive.read_bytes()),files=len(files),release_approved=False)

if __name__ == '__main__':
    p=argparse.ArgumentParser();p.add_argument('--out',required=True);p.add_argument('--profile',choices=['B','C'],default='B')
    a=p.parse_args();print(json.dumps(build(a.out,a.profile)))
