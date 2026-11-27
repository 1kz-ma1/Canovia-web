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
import uuid

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
# Gate S is *one fixed synthetic fixture*, never a general CSS or public build.
FIXED_CSS_SHA256 = '843d0307076b6fd7e3c5606f4d98b86cfd5af4614635066e5938f142e298c3c4'
FIXED_PUBLIC_SHA256 = {
    '404.html': '0a4f74100e937075b8d83de32c10edffa0d53351fddb995db021520ee6d3fb3f',
    'legal/legal.css': FIXED_CSS_SHA256,
    'legal/privacy.html': '43407e6d2bec05dfbff313d8032c42b0efda7c24a5a1b1f07a6a9f58fd8d480f',
    'legal/support.html': 'a1c6a19b9e54eb77d09b78bddad8dbf4e8aafaaf13aad10e2e023a14fea08b80',
}
FIXED_PUBLIC_BYTES = {'404.html': 509, 'legal/legal.css': 259,
                      'legal/privacy.html': 605, 'legal/support.html': 635}
ATTEMPT_RE = re.compile(r'[0-9a-f]{32}\Z')
EXPECTATION_KEYS = frozenset({
    'kind', 'source_sha', 'source_tree', 'inputs_sha256', 'files_sha256',
    'files_bytes', 'run_id', 'attempt_id', 'isolation_root', 'out_root',
    'b25_inventory_sha256', 'synthetic_only', 'not_deployable', 'release_approved',
})


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
        # Exact bytes, not a regex parser: reject escape obfuscation, image-set
        # string URLs and *every* unapproved modification, including comments.
        if sha(raw) != FIXED_CSS_SHA256 or len(raw) != FIXED_PUBLIC_BYTES[name]:
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


def source_snapshot(repo, source_sha, source_tree, expected_inputs=None):
    if not isinstance(source_sha, str) or not HEX40.fullmatch(source_sha) or not isinstance(source_tree, str) or not HEX40.fullmatch(source_tree):
        deny('FULL_SOURCE_IDENTITY_REQUIRED')
    if git(repo, 'rev-parse', 'HEAD').decode().strip() != source_sha:
        deny('SOURCE_HEAD_MOVED')
    if git(repo, 'rev-parse', 'HEAD^{tree}').decode().strip() != source_tree:
        deny('SOURCE_TREE_MOVED')
    if git(repo, 'status', '--porcelain', '--untracked-files=all').strip():
        deny('SOURCE_NOT_CLEAN')
    if expected_inputs is not None and (not isinstance(expected_inputs, dict) or set(expected_inputs) != set(INPUTS)):
        deny('EXPECTED_INPUT_INVENTORY_INVALID')
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
        if expected_inputs is not None and (not isinstance(expected_inputs[path], str) or sha(blob) != expected_inputs[path]):
            deny('INDEPENDENT_INPUT_HASH_MISMATCH')
        result[path] = blob
    return result


def _is_hex_sha(value, length):
    return type(value) is str and re.fullmatch(r'[a-f0-9]{%d}' % length, value) is not None


def _canonical_absolute(path):
    if type(path) is not str or not path.startswith('/') or '\x00' in path or '\\' in path or '//' in path:
        deny('NONCANONICAL_ROOT')
    parts = path.split('/')
    if any(item in ('.', '..') for item in parts):
        deny('NONCANONICAL_ROOT')
    return Path(path)


def _ancestors_no_symlinks(path):
    current = Path('/')
    for component in path.parts[1:]:
        current = current / component
        st = current.lstat()
        if not stat.S_ISDIR(st.st_mode):
            deny('ISOLATION_ANCESTOR_NOT_DIRECTORY')
    return path


def isolation_boundary(repo, isolation_root, out_root, *, must_be_new):
    """Externally selected private root; reject *every* symlinked ancestor.

    Assumption: the calling OS user and the private directory are not controlled
    by a concurrent malicious writer. This is not an OS-level concurrency proof.
    """
    root = _canonical_absolute(isolation_root)
    out = _canonical_absolute(out_root)
    if out.parent != root or out.name in ('', '.', '..') or not re.fullmatch(r'[A-Za-z0-9][A-Za-z0-9_-]{0,63}', out.name):
        deny('OUTPUT_OUTSIDE_ISOLATION_ROOT')
    repo_real = repo.resolve(strict=True)
    _ancestors_no_symlinks(root)
    if root.resolve(strict=True) != root or root == repo_real or root in repo_real.parents or repo_real in root.parents:
        deny('ISOLATION_REPOSITORY_OVERLAP')
    st = root.lstat()
    if st.st_uid != os.getuid() or (stat.S_IMODE(st.st_mode) & 0o077) != 0:
        deny('ISOLATION_ROOT_NOT_PRIVATE')
    if must_be_new:
        if out.exists() or out.is_symlink():
            deny('NEW_ISOLATED_OUTPUT_REQUIRED')
        return root, out, st
    st_out = out.lstat()
    if not stat.S_ISDIR(st_out.st_mode) or st_out.st_uid != os.getuid() or (stat.S_IMODE(st_out.st_mode) & 0o077):
        deny('OUTPUT_DIRECTORY_IDENTITY')
    return root, out, st_out


def validate_expected(expected):
    """External input is a review condition, not proof of owner authorization."""
    if type(expected) is not dict or set(expected) != EXPECTATION_KEYS:
        deny('EXPECTED_SCHEMA_INVALID')
    if expected['kind'] != 'CANOVIA_GATE_S_EXTERNAL_EXPECTED_V1':
        deny('EXPECTED_KIND_INVALID')
    if not _is_hex_sha(expected['source_sha'], 40) or not _is_hex_sha(expected['source_tree'], 40):
        deny('EXPECTED_SOURCE_INVALID')
    if type(expected['run_id']) is not str or not RUN_RE.fullmatch(expected['run_id']):
        deny('EXPECTED_RUN_INVALID')
    if type(expected['attempt_id']) is not str or not ATTEMPT_RE.fullmatch(expected['attempt_id']):
        deny('EXPECTED_ATTEMPT_INVALID')
    if expected['synthetic_only'] is not True or expected['not_deployable'] is not True or expected['release_approved'] is not False:
        deny('EXPECTED_GATE_INVALID')
    if expected['b25_inventory_sha256'] != B_INVENTORY_SHA256:
        deny('EXPECTED_B25_INVALID')
    for field, names in (('inputs_sha256', INPUTS), ('files_sha256', PUBLIC)):
        x = expected[field]
        if type(x) is not dict or set(x) != set(names) or not all(_is_hex_sha(v, 64) for v in x.values()):
            deny('EXPECTED_HASH_SET_INVALID')
    if expected['files_sha256'] != FIXED_PUBLIC_SHA256:
        deny('EXPECTED_FIXTURE_HASH_INVALID')
    if type(expected['files_bytes']) is not dict or expected['files_bytes'] != FIXED_PUBLIC_BYTES or any(type(v) is not int for v in expected['files_bytes'].values()):
        deny('EXPECTED_FIXTURE_SIZE_INVALID')
    _canonical_absolute(expected['isolation_root'])
    _canonical_absolute(expected['out_root'])
    return expected


def _identity(path):
    st = path.lstat()
    if not stat.S_ISDIR(st.st_mode) or stat.S_IMODE(st.st_mode) & 0o077 or st.st_uid != os.getuid():
        deny('OUTPUT_DIRECTORY_IDENTITY')
    return {'device': st.st_dev, 'inode': st.st_ino}


def _as_exact_identity(value):
    if type(value) is not dict or set(value) != {'device', 'inode'}:
        deny('IDENTITY_SCHEMA_INVALID')
    if any(type(v) is not int or v <= 0 for v in value.values()):
        deny('IDENTITY_VALUE_INVALID')
    return value


def _check_actual_output(repo, expected, output_identity):
    _, out, _ = isolation_boundary(repo, expected['isolation_root'], expected['out_root'], must_be_new=False)
    if _identity(out) != _as_exact_identity(output_identity):
        deny('CURRENT_OUTPUT_IDENTITY_MISMATCH')
    if set(x.name for x in out.iterdir()) != {'.render-legal-only', 'evidence'}:
        deny('OUTPUT_EXTRA_OR_MISSING')
    publish = out / '.render-legal-only'
    real = check_regular_tree(publish)
    if {n: sha(b) for n, b in real.items()} != expected['files_sha256'] or {n: len(b) for n, b in real.items()} != expected['files_bytes']:
        deny('CURRENT_OUTPUT_BYTES_MISMATCH')
    evidence = out / 'evidence'
    if evidence.is_symlink() or not evidence.is_dir() or set(x.name for x in evidence.iterdir()) != {'manifest.json', 'receipt.json'}:
        deny('OUTPUT_EVIDENCE_SET_INVALID')
    for name in ('manifest.json', 'receipt.json'):
        st = (evidence / name).lstat()
        if not stat.S_ISREG(st.st_mode) or st.st_nlink != 1:
            deny('EVIDENCE_SPECIAL_FILE')
    return real


def verify_receipt(manifest, receipt, expected, observed_exit_code=None, process_exited=None, *, observed_attempt=None, repo=REPO):
    """Post-process verifier. The external supervisor MUST capture actual exit.

    Never derive expected source, attempt or output from receipt/current checkout.
    Passing an untrusted observed_exit_code is not an independent guarantee.
    """
    expected = validate_expected(expected)
    # The observation is supplied by an *external* process supervisor after wait().
    # Receipt metadata cannot substitute for exit/wait observation.
    if type(observed_attempt) is not dict or set(observed_attempt) != {
            'attempt_id', 'run_id', 'source_sha', 'source_tree', 'out_root',
            'exit_code', 'process_exited'}:
        deny('EXTERNAL_EXECUTION_OBSERVATION_REQUIRED')
    for key in ('attempt_id', 'run_id', 'source_sha', 'source_tree', 'out_root'):
        if type(observed_attempt[key]) is not str or observed_attempt[key] != expected[key]:
            deny('EXTERNAL_ATTEMPT_IDENTITY_MISMATCH')
    if (type(observed_exit_code) is not int or observed_exit_code != 0 or process_exited is not True or
            type(observed_attempt['exit_code']) is not int or observed_attempt['exit_code'] != observed_exit_code or
            observed_attempt['process_exited'] is not True):
        deny('CURRENT_ATTEMPT_NOT_SUCCESSFULLY_EXITED')
    if type(manifest) is not dict or type(receipt) is not dict:
        deny('INVALID_EVIDENCE_TYPES')
    if set(manifest) != MANIFEST_KEYS or set(receipt) != RECEIPT_KEYS:
        deny('EVIDENCE_SCHEMA_MISMATCH')
    if manifest['kind'] != 'CANOVIA_LEGAL_GATE_S_SYNTHETIC_V1':
        deny('UNAPPROVED_EVIDENCE_KIND')
    for field in ('run_id', 'attempt_id', 'source_sha', 'source_tree', 'isolation_root', 'out_root'):
        if type(manifest[field]) is not str or manifest[field] != expected[field] or receipt[field] != expected[field]:
            deny('CURRENT_ATTEMPT_SOURCE_OR_LOCATION_MISMATCH')
    if manifest['inputs_sha256'] != expected['inputs_sha256'] or manifest['files_sha256'] != expected['files_sha256'] or manifest['files_bytes'] != expected['files_bytes']:
        deny('EVIDENCE_EXPECTED_HASH_MISMATCH')
    if receipt['inputs_sha256'] != expected['inputs_sha256'] or receipt['files_sha256'] != expected['files_sha256'] or receipt['files_bytes'] != expected['files_bytes']:
        deny('RECEIPT_EXPECTED_HASH_MISMATCH')
    if any(manifest[k] is not True for k in ('synthetic_only', 'not_deployable', 'b24_other_paths_excluded', 'legal404_independently_sourced')):
        deny('MANIFEST_SAFETY_FLAG_INVALID')
    if any(receipt[k] is not True for k in ('synthetic_only', 'not_deployable')):
        deny('RECEIPT_SAFETY_FLAG_INVALID')
    if manifest['release_approved'] is not False or manifest['render_connected'] is not False or receipt['release_approved'] is not False:
        deny('RELEASE_SAFETY_INVALID')
    if manifest['allowed_modes'] != ['synthetic'] or manifest['publish_directory'] != '.render-legal-only' or manifest['public_path_count'] != 4:
        deny('MANIFEST_PUBLIC_BOUNDARY_INVALID')
    if manifest['b25_inventory_sha256'] != B_INVENTORY_SHA256 or manifest['b25_count'] != 25 or manifest['b25_path_overlap'] != ['404.html']:
        deny('B25_RECORD_INVALID')
    if manifest['b25_source_sha'] != B_SOURCE_SHA or manifest['b25_tree_sha'] != B_TREE_SHA or manifest['b404_sha256'] != B_404_SHA256:
        deny('B25_REFERENCE_INVALID')
    if manifest['legal404_source'] != PUBLIC['404.html'] or manifest['legal404_sha256'] != expected['files_sha256']['404.html']:
        deny('LEGAL_404_SOURCE_INVALID')
    if manifest['baseline_source_sha'] != BASELINE_SHA or manifest['live_headers_routes_cookie_sw_cache'] != 'NOT_VERIFIED':
        deny('UNSUPPORTED_LIVE_CLAIM')
    if receipt['status'] != 'SYNTHETIC_PASS_NOT_DEPLOYABLE' or receipt['render_deploy'] != 'NOT_RUN' or receipt['policy_publication'] != 'HOLD' or receipt['publish_path_count'] != 4:
        deny('RECEIPT_GATE_INVALID')
    if type(receipt['manifest_sha256']) is not str or receipt['manifest_sha256'] != sha(canonical(manifest)):
        deny('RECEIPT_MANIFEST_HASH_MISMATCH')
    for k in ('output_identity', 'publish_identity'):
        if _as_exact_identity(manifest[k]) != _as_exact_identity(receipt[k]):
            deny('RECEIPT_OUTPUT_IDENTITY_MISMATCH')
    # Independently re-read *current* checkout and current output; no stale fallback.
    sources = source_snapshot(repo, expected['source_sha'], expected['source_tree'], expected['inputs_sha256'])
    if {k: sha(v) for k, v in sources.items()} != manifest['inputs_sha256']:
        deny('CURRENT_INPUTS_MISMATCH')
    raw = _check_actual_output(repo, expected, manifest['output_identity'])
    # The provided candidate must be exactly what is on disk for this output identity.
    evidence_dir = Path(expected['out_root']) / 'evidence'
    if (evidence_dir / 'manifest.json').read_bytes() != canonical(manifest) or (evidence_dir / 'receipt.json').read_bytes() != canonical(receipt):
        deny('CURRENT_EVIDENCE_BYTES_MISMATCH')
    if _identity(Path(expected['out_root']) / '.render-legal-only') != manifest['publish_identity']:
        deny('CURRENT_PUBLISH_IDENTITY_MISMATCH')
    if {k: sha(v) for k, v in raw.items()} != manifest['files_sha256']:
        deny('CURRENT_PUBLISH_HASH_MISMATCH')
    return True


MANIFEST_KEYS = frozenset({
    'kind', 'run_id', 'attempt_id', 'source_sha', 'source_tree', 'baseline_source_sha',
    'synthetic_only', 'not_deployable', 'release_approved', 'render_connected',
    'allowed_modes', 'publish_directory', 'b25_source_sha', 'b25_tree_sha',
    'b25_inventory_sha256', 'b25_count', 'b25_path_overlap', 'b24_other_paths_excluded',
    'b404_sha256', 'legal404_source', 'legal404_sha256', 'legal404_independently_sourced',
    'inputs_sha256', 'files_sha256', 'files_bytes', 'public_path_count',
    'live_headers_routes_cookie_sw_cache', 'isolation_root', 'out_root',
    'output_identity', 'publish_identity',
})
RECEIPT_KEYS = frozenset({
    'status', 'synthetic_only', 'not_deployable', 'release_approved', 'run_id',
    'attempt_id', 'source_sha', 'source_tree', 'manifest_sha256',
    'publish_path_count', 'render_deploy', 'policy_publication',
    'isolation_root', 'out_root', 'output_identity', 'publish_identity',
    'inputs_sha256', 'files_sha256', 'files_bytes',
})


def build(repo, source_sha, source_tree, inventory_sha, run_id, out_root, mode='synthetic', *, expected=None, isolation_root=None, attempt_id=None):
    if mode != 'synthetic':
        deny('PUBLICATION_MODE_FORBIDDEN')
    if expected is None:
        deny('INDEPENDENT_EXPECTED_REQUIRED')
    expected = validate_expected(expected)
    if not RUN_RE.fullmatch(run_id) or run_id != expected['run_id']:
        deny('RUN_ID_INVALID')
    if attempt_id is None or attempt_id != expected['attempt_id']:
        deny('ATTEMPT_ID_MISMATCH')
    if inventory_sha != B_INVENTORY_SHA256 or inventory_sha != expected['b25_inventory_sha256']:
        deny('B25_INVENTORY_INPUT_MISMATCH')
    if source_sha != expected['source_sha'] or source_tree != expected['source_tree']:
        deny('INDEPENDENT_SOURCE_IDENTITY_MISMATCH')
    if isolation_root != expected['isolation_root'] or str(out_root) != expected['out_root']:
        deny('INDEPENDENT_OUTPUT_LOCATION_MISMATCH')
    inventory_identity()
    repo = Path(repo).resolve(strict=True)
    # Reject everything before creating the output, including symlinked parent.
    root, out, root_stat = isolation_boundary(repo, isolation_root, str(out_root), must_be_new=True)
    sources = source_snapshot(repo, source_sha, source_tree, expected['inputs_sha256'])
    payload = {p: sources[src] for p, src in PUBLIC.items()}
    check_payload(payload)
    if {k: sha(v) for k, v in payload.items()} != expected['files_sha256'] or {k: len(v) for k, v in payload.items()} != expected['files_bytes']:
        deny('INDEPENDENT_FIXTURE_HASH_MISMATCH')
    # Exclusive creation relative to the verified private parent fd.
    flags = os.O_RDONLY | os.O_DIRECTORY | getattr(os, 'O_NOFOLLOW', 0)
    fd = os.open(str(root), flags)
    try:
        if (os.fstat(fd).st_dev, os.fstat(fd).st_ino) != (root_stat.st_dev, root_stat.st_ino):
            deny('ISOLATION_ROOT_CHANGED')
        os.mkdir(out.name, 0o700, dir_fd=fd)
    finally:
        os.close(fd)
    publish = out / '.render-legal-only'
    publish.mkdir(mode=0o700)
    (publish / 'legal').mkdir(mode=0o700)
    for p, raw in payload.items():
        with (publish / p).open('xb') as f:
            f.write(raw)
    actual = check_regular_tree(publish)
    if actual != payload:
        deny('PUBLISH_BYTES_MISMATCH')
    out_identity, publish_identity = _identity(out), _identity(publish)
    manifest = {
        'kind': 'CANOVIA_LEGAL_GATE_S_SYNTHETIC_V1',
        'run_id': run_id, 'attempt_id': attempt_id, 'source_sha': source_sha, 'source_tree': source_tree,
        'baseline_source_sha': BASELINE_SHA, 'isolation_root': str(root), 'out_root': str(out),
        'output_identity': out_identity, 'publish_identity': publish_identity,
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
        'public_path_count': 4, 'live_headers_routes_cookie_sw_cache': 'NOT_VERIFIED',
    }
    evidence = out / 'evidence'
    evidence.mkdir(mode=0o700)
    receipt = {
        'status': 'SYNTHETIC_PASS_NOT_DEPLOYABLE', 'synthetic_only': True,
        'not_deployable': True, 'release_approved': False, 'run_id': run_id,
        'attempt_id': attempt_id, 'source_sha': source_sha, 'source_tree': source_tree,
        'isolation_root': str(root), 'out_root': str(out),
        'output_identity': out_identity, 'publish_identity': publish_identity,
        'inputs_sha256': manifest['inputs_sha256'],
        'files_sha256': manifest['files_sha256'], 'files_bytes': manifest['files_bytes'],
        'manifest_sha256': sha(canonical(manifest)), 'publish_path_count': 4,
        'render_deploy': 'NOT_RUN', 'policy_publication': 'HOLD',
    }
    # Builder receipt is *only a candidate*: process has not exited yet.
    (evidence / 'manifest.json').write_bytes(canonical(manifest))
    (evidence / 'receipt.json').write_bytes(canonical(receipt))
    return manifest, receipt


def _no_duplicate_json(pairs):
    data = {}
    for k, v in pairs:
        if k in data:
            deny('DUPLICATE_EXPECTED_KEY')
        data[k] = v
    return data


def main():
    parser = argparse.ArgumentParser(description='Non-deployable Gate S synthetic builder')
    parser.add_argument('--mode', required=True, choices=['synthetic'])
    parser.add_argument('--source-sha', required=True)
    parser.add_argument('--source-tree', required=True)
    parser.add_argument('--b-inventory-sha256', required=True)
    parser.add_argument('--run-id', required=True)
    parser.add_argument('--attempt-id', required=True)
    parser.add_argument('--isolation-root', required=True)
    parser.add_argument('--out-root', required=True)
    parser.add_argument('--expected-file', required=True,
                        help='Caller-pinned independent expectations; NEVER derive from current receipt')
    args = parser.parse_args()
    try:
        exp_path = _canonical_absolute(args.expected_file)
        if exp_path.is_symlink() or not exp_path.is_file() or exp_path.stat().st_nlink != 1 or exp_path.stat().st_size > 16384:
            deny('INVALID_EXTERNAL_EXPECTATION_FILE')
        if REPO.resolve(strict=True) in exp_path.resolve(strict=True).parents:
            deny('EXPECTATION_MUST_BE_OUTSIDE_SOURCE')
        expected = json.loads(exp_path.read_text(encoding='utf-8'), object_pairs_hook=_no_duplicate_json)
        manifest, receipt = build(REPO, args.source_sha, args.source_tree,
                                  args.b_inventory_sha256, args.run_id,
                                  args.out_root, args.mode, expected=expected,
                                  isolation_root=args.isolation_root, attempt_id=args.attempt_id)
        print(canonical({'manifest_sha256': receipt['manifest_sha256'],
                         'run_id': receipt['run_id'], 'attempt_id': receipt['attempt_id'],
                         'status': 'CANDIDATE_REQUIRES_INDEPENDENT_EXIT_AND_OUTPUT_VERIFICATION',
                         'synthetic_only': True, 'not_deployable': True}).decode(), end='')
    except (OSError, ValueError, subprocess.CalledProcessError) as exc:
        print('LEGAL_GATE_S_BLOCK:' + type(exc).__name__ + ':' + str(exc), file=sys.stderr)
        return 2
    return 0


if __name__ == '__main__':
    sys.exit(main())
