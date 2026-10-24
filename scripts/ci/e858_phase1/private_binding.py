"""Private target format/reader candidate. Library only; no paths/CLI/network.

Expected record hash comes from a separate private owner review, NOT this JSON.
Current tests use only newly created synthetic files. A hash is not approval.
"""
from dataclasses import dataclass, field
import hashlib
import os
import re
import ssl
import stat

from e858_b_offline import decode, SOURCE, RELEASE, SHA
from .sql_pins import Q0_SHA,Q1_SHA

SQL = '8383bc16fc8b35b8413a2a6a0b0c0784c9fa646ebab119017b97c2bd8d4333d1'
HEX = re.compile('[0-9a-f]{64}')


class Block(Exception):
    def __init__(self): super().__init__('PRIVATE_CANDIDATE_BLOCK')


def sha(raw): return hashlib.sha256(raw).hexdigest()


@dataclass(frozen=True, repr=False)
class Target:
    service: str
    database: str
    host: str
    port: int
    principal: str
    ca: bytes = field(repr=False)
    record_hash: str
    tooling_sha: str
    starts_at: int
    expires_at: int

    def __repr__(self): return '<private target; not an execution permit>'

    def valid(self, now):
        return type(now) is int and self.starts_at <= now < self.expires_at

    def tls_context(self):
        # No CA path/default store/hostname override. Actual SAN is unverified
        # until a future reviewed connection uses this very SSLContext.
        ctx = ssl.SSLContext(ssl.PROTOCOL_TLS_CLIENT)
        ctx.minimum_version = ssl.TLSVersion.TLSv1_2
        ctx.verify_mode = ssl.CERT_REQUIRED
        ctx.check_hostname = True
        ctx.load_verify_locations(cadata=self.ca.decode('ascii'))
        return ctx


def _identity(st):
    return (st.st_dev, st.st_ino, st.st_uid, st.st_mode, st.st_nlink,
            st.st_size, st.st_mtime_ns, st.st_ctime_ns)


def _read(directory, name, cap, inspect_acl=None):
    fd = None
    try:
        fd = os.open(name, os.O_RDONLY | os.O_NONBLOCK | os.O_NOFOLLOW | os.O_CLOEXEC, dir_fd=directory)
        before = os.fstat(fd)
        if (not stat.S_ISREG(before.st_mode) or stat.S_IMODE(before.st_mode) != 0o600
                or before.st_uid != os.geteuid() or before.st_nlink != 1 or not 0 < before.st_size <= cap):
            raise Block()
        if inspect_acl is not None: inspect_acl(fd)
        raw = bytearray()
        while len(raw) <= cap:
            chunk = os.read(fd, min(4096, cap + 1 - len(raw)))
            if not chunk: break
            raw.extend(chunk)
        if len(raw) != before.st_size or len(raw) > cap or _identity(before) != _identity(os.fstat(fd)):
            raise Block()
        if inspect_acl is not None: inspect_acl(fd)
        if _identity(before)!=_identity(os.stat(name,dir_fd=directory,follow_symlinks=False)): raise Block()
        return bytes(raw)
    finally:
        if fd is not None: os.close(fd)


def read_binding(directory_fd, expected_record_hash, tooling_sha, now, *, inspect_acl=None):
    """Own a dup of an already privately acquired directory FD, fixed basenames.

    Parent path acquisition/owner authenticity are not proved by fstat. No path,
    private record hash or target value may be published in receipts/errors.
    """
    owned = None
    try:
        if (type(directory_fd) is not int or directory_fd <= 2
                or type(expected_record_hash) is not str or not HEX.fullmatch(expected_record_hash)
                or type(tooling_sha) is not str or not SHA.fullmatch(tooling_sha)):
            raise Block()
        owned = os.dup(directory_fd)
        os.set_inheritable(owned, False)
        ds = os.fstat(owned)
        if (not stat.S_ISDIR(ds.st_mode) or ds.st_uid != os.geteuid()
                or stat.S_IMODE(ds.st_mode) != 0o700): raise Block()
        raw = _read(owned, 'target.json', 16384) if inspect_acl is None else _read(owned, 'target.json', 16384, inspect_acl)
        if sha(raw) != expected_record_hash: raise Block()
        value = decode(raw)  # Existing size/duplicate/depth/type-safe JSON boundary.
        required = {'kind','provenance','source_sha','release_ref','tooling_sha','sql_sha256',
                    'q0_sha256','q1_sha256','starts_at','expires_at','target','tls'}
        if type(value) is not dict or set(value) != required: raise Block()
        expected = dict(kind='E858_PHASE1_PRIVATE_BINDING_V1', provenance='OWNER_PRIVATE_2026_10_21',
            source_sha=SOURCE, release_ref=RELEASE, tooling_sha=tooling_sha,
            sql_sha256=SQL, q0_sha256=Q0_SHA, q1_sha256=Q1_SHA)
        if any(value.get(k) != v for k,v in expected.items()): raise Block()
        tls, t = value['tls'], value['target']
        if (type(tls) is not dict or set(tls) != {'mode','minimum','san','ca_sha256'}
                or tls['mode'] != 'VERIFY_IDENTITY' or tls['minimum'] != 'TLS1.2'
                or type(t) is not dict or set(t) != {'service','database','host','port','principal'}):
            raise Block()
        if any(type(t[k]) is not str or not t[k] or len(t[k]) > 253
               or not t[k].isascii() or any(ord(c) < 33 or ord(c) > 126 for c in t[k])
               for k in ('service','database','host','principal')): raise Block()
        if (not re.fullmatch('[A-Za-z0-9_-]{1,64}',t['database'])
                or not re.fullmatch('[A-Za-z0-9_.-]{1,32}',t['principal'])
                or t['host'] != t['host'].lower() or len(t['host'].split('.')) < 2
                or any(not re.fullmatch('[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?',p) for p in t['host'].split('.'))
                or all(p.isdigit() for p in t['host'].split('.')) or tls['san'] != t['host']
                or type(t['port']) is not int or not 1 <= t['port'] <= 65535
                or type(tls['ca_sha256']) is not str or not HEX.fullmatch(tls['ca_sha256'])): raise Block()
        if (type(value['starts_at']) is not int or type(value['expires_at']) is not int
                or value['expires_at'] <= value['starts_at']): raise Block()
        ca = _read(owned, 'ca.pem', 65536) if inspect_acl is None else _read(owned, 'ca.pem', 65536, inspect_acl)
        if sha(ca) != tls['ca_sha256'] or b'PRIVATE KEY' in ca: raise Block()
        if _identity(ds) != _identity(os.fstat(owned)): raise Block()
        target = Target(**t, ca=ca, record_hash=expected_record_hash, tooling_sha=tooling_sha,
                        starts_at=value['starts_at'], expires_at=value['expires_at'])
        if not target.valid(now): raise Block()
        target.tls_context()  # Parse only the synthetic CA in tests; no handshake.
        return target
    except BaseException:
        raise Block() from None
    finally:
        if owned is not None:
            try: os.close(owned)
            except BaseException: raise Block() from None
