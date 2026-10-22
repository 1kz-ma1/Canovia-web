"""Deterministic INERT library archive, not an adopted executable distribution.

No target/CA/credential/TTY CLI. Builder uses fixed public Git blobs and one
hash-pinned wheel; verifier never extracts or executes archive members.
"""
import hashlib
import io
from importlib import metadata
import json
import os
from pathlib import Path
import platform
import ssl
import stat
import sys
import zipfile

from e858_b_offline import canonical, digest, decode, integrity, git, SOURCE, RELEASE
from .private_binding import Block, Q0_SHA, Q1_SHA

WHEEL = 'pymysql-1.1.2-py3-none-any.whl'
WHEEL_SHA = 'e6b1d89711dd51f8f74b1631fe08f039e7d76cf67a42a323d3178f0f25762ed9'
FILES = tuple('scripts/ci/' + p for p in (
    'e858_phase1/private_acl.py','e858_phase1/private_binding.py','e858_phase1/private_candidate.py',
    'e858_phase1/private_distribution.py','e858_phase1/private_acceptance.py',
    'e858_phase1/private_native_runtime.py',
    'e858_phase1/private_operator.py','e858_phase1/private_entry.py',
    'e858_phase1/private_root.py','e858_phase1/r1_tty.py',
    'e858_phase1/r1_adapter.py','e858_phase1/runtime.py','e858_phase1/protocol.py',
    'e858_tls/transport.py','e858_tls/control_contract.py','e858_b_offline.py',
    'p0_mysql_metadata_grants_input.py','p0_mysql_metadata_grants_guard.py',
    'release488_runtime_ddl_restore_gate.py'))
QUERIES = {'queries/identity.sql':Q0_SHA,'queries/grants.sql':Q1_SHA}
WHEEL_PATH = 'dependencies/' + WHEEL
CAP = 4 * 1024 * 1024


def _sha(raw): return hashlib.sha256(raw).hexdigest()


def pack(files):
    """Public byte inputs only; deterministic uncompressed ZIP, no extraction."""
    out = io.BytesIO()
    with zipfile.ZipFile(out,'w',compression=zipfile.ZIP_STORED) as archive:
        for path in sorted(files):
            info = zipfile.ZipInfo(path,(1980,1,1,0,0,0))
            info.create_system = 3
            info.external_attr = (stat.S_IFREG | 0o644) << 16
            archive.writestr(info,files[path])
    return out.getvalue()


def build(root, head, wheel):
    try:
        if type(wheel) is not bytes or _sha(wheel) != WHEEL_SHA: raise Block()
        pins = integrity(root,head)
        files = {p:git(root,'show',head+':'+p) for p in FILES}
        for path, expected in QUERIES.items():
            files[path] = git(root,'show',pins['collector_sha']+':scripts/ci/e858_audit/'+path)
            if _sha(files[path]) != expected: raise Block()
        files[WHEEL_PATH] = wheel
        manifest = dict(kind='E858_INERT_LIBRARY_V1',pins=pins,files={p:_sha(v) for p,v in files.items()},
            wheel_sha256=WHEEL_SHA,scope='OFFLINE_ONLY',execution_allowed=False,
            live_tooling_adopted=False,runtime_lock='OWNER_COMPONENT_LOCK_AND_LINKAGE_REVIEW_REQUIRED')
        files['manifest.json'] = canonical(manifest)
        raw = pack(files)
        if len(raw) > CAP: raise Block()
        verify(raw,_sha(raw),pins)
        return raw, pins
    except BaseException:
        raise Block() from None


def verify(raw, expected_archive_sha, expected_pins):
    try:
        if type(raw) is not bytes or len(raw)>CAP or _sha(raw)!=expected_archive_sha: raise Block()
        with zipfile.ZipFile(io.BytesIO(raw)) as archive:
            names = set(FILES) | set(QUERIES) | {WHEEL_PATH,'manifest.json'}
            infos = archive.infolist()
            if (len(infos)!=len(names) or {i.filename for i in infos}!=names
                    or any(i.compress_type!=zipfile.ZIP_STORED or i.file_size>CAP or i.flag_bits
                           or i.external_attr!=(stat.S_IFREG|0o644)<<16 for i in infos)
                    or sum(i.file_size for i in infos)>CAP): raise Block()
            data = {name:archive.read(name) for name in names}
        manifest = decode(data.pop('manifest.json'))
        expected = dict(kind='E858_INERT_LIBRARY_V1',pins=expected_pins,
            files={p:_sha(v) for p,v in data.items()},wheel_sha256=WHEEL_SHA,scope='OFFLINE_ONLY',
            execution_allowed=False,live_tooling_adopted=False,
            runtime_lock='OWNER_COMPONENT_LOCK_AND_LINKAGE_REVIEW_REQUIRED')
        if (canonical(manifest)!=canonical(expected) or expected_pins['source_sha']!=SOURCE
                or expected_pins['release_ref']!=RELEASE or _sha(data[WHEEL_PATH])!=WHEEL_SHA
                or any(_sha(data[p])!=v for p,v in QUERIES.items())): raise Block()
        return dict(result='INERT_ARCHIVE_VERIFIED',archive_sha256=expected_archive_sha,
            tooling_sha=expected_pins['tooling_sha'],file_count=len(data)+1,
            execution_allowed=False,live_tooling_adopted=False,runtime_complete=False)
    except BaseException:
        raise Block() from None


def _public_file(path, cap, allow_empty=False):
    fd = None
    try:
        fd = os.open(path,os.O_RDONLY|os.O_NONBLOCK|os.O_NOFOLLOW|os.O_CLOEXEC)
        st = os.fstat(fd)
        if not stat.S_ISREG(st.st_mode) or not (0 if allow_empty else 1)<=st.st_size<=cap: raise Block()
        out = bytearray()
        while len(out)<=cap:
            chunk=os.read(fd,min(65536,cap+1-len(out)))
            if not chunk: break
            out.extend(chunk)
        after=os.fstat(fd)
        if len(out)!=st.st_size or (st.st_size,st.st_mtime_ns,st.st_ctime_ns)!=(after.st_size,after.st_mtime_ns,after.st_ctime_ns): raise Block()
        return bytes(out)
    finally:
        if fd is not None: os.close(fd)


def runtime_components():
    """Observe THIS machine only. Not full OpenSSL/dyld/system-library closure."""
    try:
        import _ssl
        dist=metadata.distribution('PyMySQL')
        if dist.version!='1.1.2': raise Block()
        source={str(p):_sha(_public_file(dist.locate_file(p),131072,allow_empty=True)) for p in dist.files
                if str(p).startswith('pymysql/') and str(p).endswith('.py')}
        if not source: raise Block()
        binary_sha=_sha(_public_file(Path(sys.executable).resolve(),64*1024*1024))
        if getattr(_ssl.__spec__,'origin',None)=='built-in':
            ssl_kind,ssl_sha='BUILTIN_IN_PYTHON_BINARY',binary_sha
        elif getattr(_ssl,'__file__',None):
            ssl_kind,ssl_sha='EXTENSION',_sha(_public_file(Path(_ssl.__file__).resolve(),64*1024*1024))
        else: raise Block()
        return dict(kind='OBSERVED_COMPONENTS_NOT_COMPLETE_RUNTIME',python_version=platform.python_version(),
            os=platform.system(),machine=platform.machine(),openssl=ssl.OPENSSL_VERSION,
            python_binary_sha256=binary_sha,ssl_component_kind=ssl_kind,ssl_component_sha256=ssl_sha,
            pymysql_version=dist.version,pymysql_sources_sha256=digest(source),
            linkage_closure='UNVERIFIED',execution_allowed=False)
    except BaseException:
        raise Block() from None


def verify_runtime(expected):
    # Expected components must be independently reviewed, not self-approval.
    try:
        actual=runtime_components()
        if type(expected) is not dict or canonical(expected)!=canonical(actual): raise Block()
        return dict(components_match=True,runtime_complete=False,execution_allowed=False)
    except BaseException:
        raise Block() from None


def verify_pymysql_wheel_installation(wheel):
    """Compare installed RECORD-listed .py bytes to the pinned PyMySQL wheel.

    This does not attest the OS loader, extra untracked files, Python import
    hooks, active native libraries, parent-directory trust or live adoption.
    No caller-supplied file paths, command execution, network or secrets.
    """
    try:
        if type(wheel) is not bytes or not 0 < len(wheel) <= 131072 or _sha(wheel) != WHEEL_SHA:
            raise Block()
        with zipfile.ZipFile(io.BytesIO(wheel)) as archive:
            expected = {}
            names = archive.infolist()
            if len(names) > 128 or len({i.filename for i in names}) != len(names):
                raise Block()
            for item in names:
                name = item.filename
                if not name.startswith('pymysql/') or not name.endswith('.py'):
                    continue
                if (any(segment in ('', '.', '..') for segment in name.split('/'))
                        or '\\' in name or not 0 <= item.file_size <= 131072):
                    raise Block()
                expected[name] = _sha(archive.read(item))
        if not 1 <= len(expected) <= 64 or 'pymysql/__init__.py' not in expected:
            raise Block()
        distribution = metadata.distribution('PyMySQL')
        if distribution.version != '1.1.2' or distribution.files is None:
            raise Block()
        names = [str(file) for file in distribution.files
                 if str(file).startswith('pymysql/') and str(file).endswith('.py')]
        if len(names) != len(expected) or set(names) != set(expected):
            raise Block()
        observed = {}
        for name in names:
            raw = _public_file(distribution.locate_file(name), 131072, allow_empty=True)
            observed[name] = _sha(raw)
            if observed[name] != expected[name]:
                raise Block()
        # Installed metadata alone is insufficient if a different package
        # is actually loaded due to sys.path/import redirection.
        import pymysql
        if (not getattr(pymysql, '__file__', None)
                or not os.path.samefile(pymysql.__file__,
                                        distribution.locate_file('pymysql/__init__.py'))):
            raise Block()
        return dict(
            result='PINNED_WHEEL_RECORD_SOURCES_MATCH_OBSERVED',
            wheel_sha256=WHEEL_SHA,
            source_count=len(observed),
            installed_sources_sha256=digest(observed),
            untracked_files='UNVERIFIED',
            runtime_linkage_closure='UNVERIFIED',
            runtime_complete=False,
            live_tooling_adopted=False,
            execution_allowed=False,
        )
    except BaseException:
        raise Block() from None
