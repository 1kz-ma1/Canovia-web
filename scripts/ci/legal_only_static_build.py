#!/usr/bin/env python3
"""Gate S: synthetic-only four-file assembler. No public build, Render, or network API."""
import argparse
import hashlib
from html.parser import HTMLParser
import json
import os
from pathlib import Path, PurePosixPath
import re
import stat
import subprocess
import sys

SYNTHETIC_MARKER = 'GATE_S_SYNTHETIC_FIXTURE_NOT_FOR_PUBLICATION'
BASELINE_SHA = 'a84dda3a91d07ca32e7ffe0e1c9a4cac77733c5f'
B_SOURCE_SHA = 'ed6a18a63dac1efbba81999fa0a9611f1c3be104'
B_TREE_SHA = '1274a581f3a8284131531dc162b9c729fdf06990'
B_INVENTORY_SHA256 = 'ec1df87bdb520e04149418bb935f6bffeea3e18632c8bddad18cf176d279ad27'
B_404_SHA256 = 'f9bc02f4fa2e5660ca44822b3f283eae7fea5e7433aa22079456cc5cc0058288'
B_INVENTORY = {
 '404.html': B_404_SHA256,
 '_headers': 'c07f847e927d9ed2f052f510bf7a4d693e811b6b31d026f07e6c99a16fe7c028',
 'assets/canovia-wordmark.png': 'a977cbaffe89ab76e7b9b8cce0517bb44b73315196f97fc4df9882fe092aa2b9',
 'assets/cr.webp': '9cabd52d5760d00862ded48e3b5f7ef4132f33f9d0ae0869c3f7bc5e577237ec',
 'assets/ct.webp': 'c1ef5392c60e8a97ec10ff34f838ad0930309cfe0676ff3f200d11812dc8b650',
 'assets/dv.webp': 'b733025eaa7b6923239b69ec210095c3b07f52c540b3e9004e68e710bf725266',
 'assets/fn.webp': '3e9b5ec47868462bb9c47a65fab0ff8287fb7a46e7d6f63c0ff5129940ee8db9',
 'assets/lf.webp': '04dc8b5f0f01abcf7e1f169e5f1908f3b13520d0e9779dc6a533e4b3bb686301',
 'assets/st.webp': '6c0dcb6b3f2c2b0a629e0cafe112689a7456818a0c7959302eb32d850090b191',
 'assets/wb.webp': 'd7e26fb64b9bc98c5b18219ffb1827883320faa61e6f1268524ef77f80980ca9',
 'assets/wk.webp': 'ff1e3f8716cb7fe84912ce7ddfb6009fcc7535c2c9b9d502ed365e2287485599',
 'index.html': '87bcc2ebe9cd17c49b7eeec3b5f6ddd8e8e46be91ea4f316f246cabd1cba4236',
 'manifest.json': '95dbdf45dc75549f4c3aadd95a3fa69367d50aa02e23f3b28fe2be3c21603669',
 'preparation.css': 'cb4459c74f9bc452292879e7c53b8949e9d7098b1086e491c6b5e19b796ecb52',
 'src/app.mjs': '76fc72727961379cb0f996d1eec47beada5af0574e7ff35dce7b97616b98ae94',
 'src/backup-codec.mjs': 'e52aa0bcdecb9d7a7d32783d61a2c631cf32f12a84cd04ff022494798263b0ae',
 'src/backup-ui.mjs': 'a551fe2beddf38705aa69ee4b68521a49b162aeffd928f9d8e4c2fa9414c35dc',
 'src/backup.mjs': '21af43d0fb1d234f0d5513abd32374ebc0ef18810ac64180de67c06ac907f7f4',
 'src/data.mjs': '330d04727d4ebce7ff2b7df05b6ba3f27a780096f38ace73df23bc8b18881edc',
 'src/model.mjs': '8165bc518d2e1bc21c95e4ff8dbce3f2b7e8f0fbcd21d363ec32c61d90b64ba0',
 'src/plan-ui.mjs': '79114cc8d33618a687367951a213da1193105a39c61bacef73b227fdac691054',
 'src/repository.mjs': '37d1125042253925ce40af580e98de73694b258f4f4b305b28720d299f8a61d8',
 'src/scoring.mjs': '50171bb4c7524722bf7a03a3a924c0aeefbac55296f9f1b2993ef63e1c446dbe',
 'src/settings.mjs': '9e40e94a7d257a2d44e00451fef704595b03ffbf637ffec11240b1ffbbf25d77',
 'style.css': '52698024981d5a0075ebbe2e892e06f4e78a2922700c976e5a8da38cb5185fb3',
}
PUBLIC = {
 'legal/privacy.html': 'legal-only/public/legal/privacy.html',
 'legal/support.html': 'legal-only/public/legal/support.html',
 'legal/legal.css': 'legal-only/public/legal/legal.css',
 '404.html': 'legal-only/public/404.html',
}
INPUTS = tuple(PUBLIC.values()) + ('scripts/ci/legal_only_static_build.py', 'tests/legal-only/test_publication_contract.py')
REPO = Path(__file__).resolve().parents[2]
RUN_RE = re.compile(r'[A-Za-z0-9][A-Za-z0-9_-]{3,63}\Z')
HEX40 = re.compile(r'[0-9a-f]{40}\Z')
MAX_BYTES = 64 * 1024


def deny(message):
    raise ValueError(message)


def sha(raw):
    return hashlib.sha256(raw).hexdigest()


def canonical(value):
    return (json.dumps(value, ensure_ascii=False, sort_keys=True, separators=(',', ':')) + '\n').encode('utf-8')


def inventory_identity():
    if len(B_INVENTORY) != 25 or len(set(B_INVENTORY)) != 25:
        deny('B25_INVENTORY_COUNT')
    if sha(canonical(B_INVENTORY)) != B_INVENTORY_SHA256:
        deny('B25_INVENTORY_DIGEST')
    if set(PUBLIC) & set(B_INVENTORY) != {'404.html'}:
        deny('B25_INTERSECTION')
    if B_INVENTORY['404.html'] != B_404_SHA256:
        deny('B_404_IDENTITY')


def canonical_path(path):
    if not isinstance(path, str) or not path or '\\' in path or '%' in path or '\x00' in path:
        deny('NONCANONICAL_PATH')
    p = PurePosixPath(path)
    if p.is_absolute() or str(p) != path or any(z in ('', '.', '..') for z in path.split('/')):
        deny('NONCANONICAL_PATH')
    return path


def check_paths(paths):
    items = list(paths)
    for p in items:
        canonical_path(p)
    if len(items) != len(set(items)):
        deny('DUPLICATE_PATH')
    if set(items) != set(PUBLIC):
        deny('EXTRA_OR_MISSING_ASSET')


class StaticHTML(HTMLParser):
    ALLOWED = {'html', 'head', 'meta', 'title', 'link', 'body', 'main', 'h1', 'h2', 'p', 'a', 'small', 'strong'}
    def handle_starttag(self, tag, attrs):
        if tag not in self.ALLOWED:
            deny('UNAPPROVED_HTML_TAG')
        names = [k for k, _ in attrs]
        if len(names) != len(set(names)) or any(k.startswith('on') or k in ('src', 'srcset', 'style', 'target', 'download') for k in names):
            deny('UNAPPROVED_HTML_ATTRIBUTE')
        a = dict(attrs)
        if tag == 'a':
            if a.keys() != {'href'} or a['href'] not in ('/privacy', '/support'):
                deny('UNAPPROVED_HTML_LINK')
        elif tag == 'link':
            if a != {'rel': 'stylesheet', 'href': '/legal/legal.css'}:
                deny('UNAPPROVED_HTML_LINK')
        elif tag == 'meta':
            if not (a.get('charset') == 'utf-8' and len(a) == 1 or
                    a == {'name': 'robots', 'content': 'noindex,nofollow'} or
                    a == {'http-equiv': 'Content-Security-Policy', 'content': "default-src 'none'; style-src 'self'; base-uri 'none'; form-action 'none'"}):
                deny('UNAPPROVED_HTML_META')
        elif attrs:
            deny('UNAPPROVED_HTML_ATTRIBUTE')


def check_asset(name, raw):
    if not isinstance(raw, bytes) or not 0 < len(raw) <= MAX_BYTES:
        deny('ASSET_SIZE')
    try:
        s = raw.decode('utf-8')
    except UnicodeDecodeError:
        deny('NON_UTF8_ASSET')
    if SYNTHETIC_MARKER not in s:
        deny('NON_SYNTHETIC_ASSET')
    if '\x00' in s or '\r' in s:
        deny('INVALID_TEXT')
    if name.endswith('.html'):
        if not s.startswith('<!doctype html>\n') or s.count(SYNTHETIC_MARKER) != 1:
            deny('INVALID_SYNTHETIC_HTML')
        p = StaticHTML(); p.feed(s); p.close()
        if s.lower().count('<html') != 1 or s.lower().count('</html>') != 1:
            deny('INVALID_HTML_STRUCTURE')
    elif name.endswith('.css'):
        if not s.startswith('/* ' + SYNTHETIC_MARKER + ' */'):
            deny('INVALID_SYNTHETIC_CSS')
        if re.search(r'@import|url\s*\(|expression\s*\(|javascript:|behavior\s*:', s, re.I):
            deny('UNAPPROVED_CSS_RESOURCE')
    else:
        deny('UNAPPROVED_ASSET_TYPE')
    if name == '404.html' and sha(raw) == B_404_SHA256:
        deny('B_404_REUSED')


def check_payload(payload):
    inventory_identity()
    check_paths(payload.keys())
    for name, raw in payload.items():
        check_asset(name, raw)
    if payload['404.html'] == payload.get('legal/privacy.html'):
        deny('404_SOURCE_NOT_INDEPENDENT')


def synthetic_route(method, host, path, query=''):
    """Offline contract oracle only; never claims Render routing or header behavior."""
    if method not in ('GET', 'HEAD'):
        return (405, None)
    if host != 'start.rpf-canovia.com':
        return (421, None)
    if query or not isinstance(path, str):
        return (404, '404.html')
    if path == '/privacy':
        return (200, 'legal/privacy.html')
    if path == '/support':
        return (200, 'legal/support.html')
    if path in ('/legal/privacy.html', '/legal/support.html', '/legal/legal.css', '/404.html'):
        return (200, path.lstrip('/'))
    return (404, '404.html')


def check_regular_tree(root):
    """Verify exact file set, mode and no symlink/hardlink, including subdirs."""
    root = Path(root)
    if not root.is_dir() or root.is_symlink():
        deny('PUBLISH_ROOT_NOT_REGULAR')
    seen = []
    for base, dirs, files in os.walk(root, followlinks=False):
        for name in dirs:
            path = Path(base) / name
            relative = path.relative_to(root).as_posix()
            if relative != 'legal' or not stat.S_ISDIR(path.lstat().st_mode) or path.is_symlink():
                deny('EXTRA_OR_SPECIAL_DIRECTORY')
        for name in files:
            path = Path(base) / name
            st = path.lstat()
            if not stat.S_ISREG(st.st_mode) or st.st_nlink != 1:
                deny('LINK_OR_SPECIAL_FILE')
            rel = path.relative_to(root).as_posix()
            canonical_path(rel)
            seen.append(rel)
    check_paths(seen)
    result = {}
    for p in seen:
        raw = (root / p).read_bytes()
        check_asset(p, raw)
        result[p] = raw
    check_payload(result)
    return result


def git(repo, *args):
    return subprocess.check_output(['git', *args], cwd=repo, stderr=subprocess.DEVNULL)


def source_snapshot(repo, source_sha, source_tree):
    if not HEX40.fullmatch(source_sha) or not HEX40.fullmatch(source_tree):
        deny('FULL_SOURCE_IDENTITY_REQUIRED')
    if git(repo, 'rev-parse', 'HEAD').decode().strip() != source_sha:
        deny('SOURCE_HEAD_MOVED')
    if git(repo, 'rev-parse', 'HEAD^{tree}').decode().strip() != source_tree:
        deny('SOURCE_TREE_MOVED')
    if git(repo, 'status', '--porcelain', '--untracked-files=all').strip():
        deny('SOURCE_NOT_CLEAN')
    result = {}
    for path in INPUTS:
        entry = git(repo, 'ls-tree', 'HEAD', '--', path).decode().strip().split()
        if len(entry) != 4 or entry[0] != '100644' or entry[1] != 'blob' or entry[3] != path:
            deny('UNAPPROVED_GIT_INPUT')
        target = repo / path
        st = target.lstat()
        if not stat.S_ISREG(st.st_mode) or st.st_nlink != 1:
            deny('LINK_OR_SPECIAL_SOURCE')
        blob = git(repo, 'cat-file', 'blob', entry[2])
        if target.read_bytes() != blob:
            deny('SOURCE_BYTES_MISMATCH')
        result[path] = blob
    return result


def verify_receipt(manifest, receipt, current_run):
    if manifest.get('run_id') != current_run or receipt.get('run_id') != current_run:
        deny('RUN_ID_MISMATCH')
    if manifest.get('synthetic_only') is not True or manifest.get('not_deployable') is not True:
        deny('SYNTHETIC_GATE_REMOVED')
    if receipt.get('synthetic_only') is not True or receipt.get('not_deployable') is not True:
        deny('SYNTHETIC_RECEIPT_REMOVED')
    if receipt.get('manifest_sha256') != sha(canonical(manifest)) or receipt.get('status') != 'SYNTHETIC_PASS_NOT_DEPLOYABLE':
        deny('RECEIPT_MISMATCH')


def build(repo, source_sha, source_tree, inventory_sha, run_id, out_root, mode='synthetic'):
    if mode != 'synthetic':
        deny('PUBLICATION_MODE_FORBIDDEN')
    if not RUN_RE.fullmatch(run_id):
        deny('RUN_ID_INVALID')
    if inventory_sha != B_INVENTORY_SHA256:
        deny('B25_INVENTORY_INPUT_MISMATCH')
    inventory_identity()
    # Validation before any output creation.
    sources = source_snapshot(repo, source_sha, source_tree)
    payload = {p: sources[src] for p, src in PUBLIC.items()}
    check_payload(payload)
    out_root = Path(out_root).absolute()
    if out_root.exists() or out_root.is_symlink() or (repo.resolve() in out_root.parents) or not out_root.parent.is_dir():
        deny('NEW_ISOLATED_OUTPUT_REQUIRED')
    out_root.mkdir(mode=0o700, parents=False, exist_ok=False)
    try:
        publish = out_root / '.render-legal-only'
        publish.mkdir(mode=0o700)
        (publish / 'legal').mkdir(mode=0o700)
        for p, raw in payload.items():
            with (publish / p).open('xb') as f:
                f.write(raw)
        actual = check_regular_tree(publish)
        if actual != payload:
            deny('PUBLISH_BYTES_MISMATCH')
        manifest = {
            'kind': 'CANOVIA_LEGAL_GATE_S_SYNTHETIC_V1',
            'run_id': run_id, 'source_sha': source_sha, 'source_tree': source_tree,
            'baseline_source_sha': BASELINE_SHA,
            'synthetic_only': True, 'not_deployable': True, 'release_approved': False,
            'render_connected': False, 'allowed_modes': ['synthetic'],
            'publish_directory': '.render-legal-only',
            'b25_source_sha': B_SOURCE_SHA, 'b25_tree_sha': B_TREE_SHA,
            'b25_inventory_sha256': B_INVENTORY_SHA256, 'b25_count': len(B_INVENTORY),
            'b25_path_overlap': ['404.html'], 'b24_other_paths_excluded': True,
            'b404_sha256': B_404_SHA256, 'legal404_source': PUBLIC['404.html'],
            'legal404_sha256': sha(actual['404.html']), 'legal404_independently_sourced': True,
            'inputs_sha256': {p: sha(data) for p, data in sorted(sources.items())},
            'files_sha256': {p: sha(data) for p, data in sorted(actual.items())},
            'files_bytes': {p: len(data) for p, data in sorted(actual.items())},
            'public_path_count': 4,
            'live_headers_routes_cookie_sw_cache': 'NOT_VERIFIED',
        }
        evidence = out_root / 'evidence'
        evidence.mkdir(mode=0o700)
        (evidence / 'manifest.json').write_bytes(canonical(manifest))
        receipt = {
            'status': 'SYNTHETIC_PASS_NOT_DEPLOYABLE', 'synthetic_only': True,
            'not_deployable': True, 'release_approved': False, 'run_id': run_id,
            'source_sha': source_sha, 'source_tree': source_tree,
            'manifest_sha256': sha(canonical(manifest)), 'publish_path_count': 4,
            'render_deploy': 'NOT_RUN', 'policy_publication': 'HOLD',
        }
        verify_receipt(manifest, receipt, run_id)
        (evidence / 'receipt.json').write_bytes(canonical(receipt))
        return manifest, receipt
    except BaseException:
        # Preserve any partial output for forensic diagnosis, never write a valid receipt.
        raise


def main():
    parser = argparse.ArgumentParser(description='Non-deployable Gate S synthetic builder')
    parser.add_argument('--mode', required=True, choices=['synthetic'])
    parser.add_argument('--source-sha', required=True)
    parser.add_argument('--source-tree', required=True)
    parser.add_argument('--b-inventory-sha256', required=True)
    parser.add_argument('--run-id', required=True)
    parser.add_argument('--out-root', required=True)
    args = parser.parse_args()
    try:
        manifest, receipt = build(REPO, args.source_sha, args.source_tree,
                                  args.b_inventory_sha256, args.run_id,
                                  args.out_root, args.mode)
        print(canonical({'manifest_sha256': receipt['manifest_sha256'],
                         'run_id': receipt['run_id'], 'status': receipt['status'],
                         'synthetic_only': True, 'not_deployable': True}).decode(), end='')
    except (OSError, ValueError, subprocess.CalledProcessError) as exc:
        print('LEGAL_GATE_S_BLOCK:' + type(exc).__name__ + ':' + str(exc), file=sys.stderr)
        return 2
    return 0


if __name__ == '__main__':
    sys.exit(main())
