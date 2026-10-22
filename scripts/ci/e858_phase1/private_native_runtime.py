"""Read-only Darwin native runtime inventory, never an operator attestation.

Runs only fixed Apple /usr/bin tools against THIS Python and its loaded _ssl
extension. No user paths, private records, TTY, secrets, SQL, or network calls.
A tool reporting success never establishes a trusted execution environment.
"""
import hashlib
import os
from pathlib import Path
import platform
import stat
import subprocess
import sys

from .private_binding import Block
from .private_distribution import _public_file, _sha

_TOOL_ENV = {'PATH': '/usr/bin:/bin', 'LC_ALL': 'C'}
_LIMIT = 32768


def _target(path):
    # Canonicalize only the observed process components, never caller input.
    target = Path(path).resolve(strict=True)
    if not target.is_file():
        raise Block()
    return target


def _invoke(tool, arguments):
    """Only fixed local Apple tools; never expose paths or raw tool messages."""
    if tool not in ('/usr/bin/otool', '/usr/bin/codesign'):
        raise Block()
    try:
        completed = subprocess.run(
            [tool] + list(arguments), stdin=subprocess.DEVNULL,
            stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=8,
            check=False, env=_TOOL_ENV)
        if (len(completed.stdout) > _LIMIT or
                len(completed.stderr) > _LIMIT):
            raise Block()
        return completed.returncode, completed.stdout
    except BaseException:
        raise Block() from None


def _observe_component(path):
    target = _target(path)
    before = os.stat(target, follow_symlinks=False)
    if not stat.S_ISREG(before.st_mode) or not 0 < before.st_size <= 64 * 1024 * 1024:
        raise Block()
    digest = _sha(_public_file(target, 64 * 1024 * 1024))
    link_code, link_data = _invoke('/usr/bin/otool', ('-L', str(target)))
    if link_code == 0:
        # The first line is the binary's own path and is never returned.
        lines = link_data.splitlines()
        deps = [v.strip() for v in lines[1:] if v.strip()]
        if (not 1 <= len(deps) <= 128 or
                not all(b' (' in v and b')' in v for v in deps)):
            raise Block()
        link_status = 'DIRECT_LINKS_OBSERVED_NOT_CLOSED'
        links_digest = hashlib.sha256(b'\n'.join(deps)).hexdigest()
        link_count = len(deps)
    else:
        link_status, links_digest, link_count = 'UNVERIFIED', None, 0
    sign_code, _ = _invoke('/usr/bin/codesign',
                           ('--verify', '--strict', str(target)))
    signature_status = ('LOCAL_CODESIGN_EXIT_ZERO_NOT_OWNER_TRUST'
                        if sign_code == 0 else 'UNVERIFIED')
    after = os.stat(target, follow_symlinks=False)
    if ((before.st_dev, before.st_ino, before.st_size, before.st_mtime_ns,
         before.st_ctime_ns) !=
        (after.st_dev, after.st_ino, after.st_size, after.st_mtime_ns,
         after.st_ctime_ns)) or _sha(_public_file(target, 64 * 1024 * 1024)) != digest:
        raise Block()
    return dict(binary_sha256=digest, direct_links=link_status,
                direct_link_count=link_count, direct_links_sha256=links_digest,
                codesign=signature_status)


def observe_native_runtime():
    """Fixed, redacted observation only; never yields a live execution permit."""
    try:
        if sys.platform != 'darwin':
            raise Block()
        import _ssl
        python = _observe_component(sys.executable)
        ssl_location = getattr(_ssl, '__file__', None)
        if ssl_location:
            tls = _observe_component(ssl_location)
            ssl_kind = 'DYNAMIC_EXTENSION_OBSERVED'
        elif getattr(_ssl.__spec__, 'origin', None) == 'built-in':
            ssl_kind = 'BUILTIN_SSL_NATIVE_LINKS_UNVERIFIED'
            tls = None
        else:
            raise Block()
        return dict(
            kind='INERT_DARWIN_NATIVE_RUNTIME_OBSERVATION',
            os='darwin', architecture=platform.machine(),
            python=python, ssl_extension=tls, ssl_kind=ssl_kind,
            executable_origin='UNVERIFIED',
            os_integrity='UNVERIFIED',
            dyld_shared_cache='UNVERIFIED',
            transitive_linkage='UNVERIFIED',
            notarization='UNVERIFIED',
            operator_identity='UNVERIFIED',
            runtime_complete=False, live_tooling_adopted=False,
            execution_allowed=False, real_aiven_connected=False)
    except BaseException:
        raise Block() from None
