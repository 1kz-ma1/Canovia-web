"""Local B procedural no-retry attempt reservation; NEVER an audit permit.

This is a *single* optional building block for a later separately reviewed
owner-operated entry. All tests use disposable privately owned directories.
The caller must already own and trust the directory FD; this code cannot
establish ancestry, backup/sync exclusion, operator identity or fresh B2 GO.

An attempted reservation leaves its file behind even after failure. It cannot
prove that remote SQL ran or stopped, and rollback/copy/alternate directories
can bypass the local marker. No actual Aiven path imports or calls this file.
"""
import hashlib
import json
import os
import stat

from e858_b_offline import SOURCE, RELEASE

class Block(Exception):
    """Deliberately contains no private filesystem or input diagnostics."""


def reserve(directory_fd, attempt_id, tooling_sha):
    """Write-once marker BEFORE a possible future attempt; NEVER approve it.

    The caller supplies a pre-owned, non-inheritable 0700 directory FD.
    This does not open a path, read an identity, touch credentials, or connect.
    A write/fsync failure is terminal; any partial file is left as consumed.
    """
    owned = None
    try:
        if (type(directory_fd) is not int or directory_fd <= 2
                or type(attempt_id) is not str or len(attempt_id) != 32
                or any(c not in '0123456789abcdef' for c in attempt_id)
                or type(tooling_sha) is not str or len(tooling_sha) != 40
                or any(c not in '0123456789abcdef' for c in tooling_sha)
                or os.get_inheritable(directory_fd)):
            raise Block()
        before = os.fstat(directory_fd)
        if (not stat.S_ISDIR(before.st_mode) or before.st_uid != os.geteuid()
                or stat.S_IMODE(before.st_mode) != 0o700):
            raise Block()
        # A hash in the private basename prevents raw attempt-ID disclosure.
        # Still only a local filename: never a trusted replay authority.
        fingerprint = hashlib.sha256(attempt_id.encode('ascii')).hexdigest()
        name = 'attempt-' + fingerprint + '.reserved'
        data = json.dumps({
            'kind': 'E858_B_LOCAL_ATTEMPT_RESERVATION_NOT_AUTHORITY_V0',
            'attempt_fingerprint': fingerprint,
            'source_sha': SOURCE, 'release_ref': RELEASE,
            'tooling_sha': tooling_sha,
            'scope': 'phase1',
            'state': 'CONSUMED_ON_RESERVATION_NOT_EXECUTION',
            'owner_approval_verified': False,
            'execution_allowed': False,
        },sort_keys=True,separators=(',',':'),ensure_ascii=True).encode('ascii') + b'\n'
        # O_EXCL rejects existing file / symlink, including interrupted runs.
        owned = os.open(name, os.O_CREAT | os.O_EXCL | os.O_WRONLY |
                        os.O_NOFOLLOW | os.O_CLOEXEC, 0o600, dir_fd=directory_fd)
        initial = os.fstat(owned)
        if (not stat.S_ISREG(initial.st_mode) or initial.st_uid != os.geteuid()
                or stat.S_IMODE(initial.st_mode) != 0o600 or initial.st_nlink != 1
                or os.get_inheritable(owned)):
            raise Block()
        offset = 0
        while offset < len(data):
            count = os.write(owned, data[offset:])
            if count <= 0:
                raise Block()
            offset += count
        os.fsync(owned)
        final = os.fstat(owned)
        parent = os.fstat(directory_fd)
        if (final.st_dev != initial.st_dev or final.st_ino != initial.st_ino
                or final.st_nlink != 1 or final.st_size != len(data)
                or final.st_uid != initial.st_uid or stat.S_IMODE(final.st_mode) != 0o600
                or parent.st_dev != before.st_dev or parent.st_ino != before.st_ino
                or parent.st_uid != before.st_uid or stat.S_IMODE(parent.st_mode) != 0o700):
            raise Block()
        os.fsync(directory_fd)
        # Never echo attempt, hash, private path, or other binding data.
        return {
            'result': 'LOCAL_ATTEMPT_RESERVED_NOT_AUTHORIZED',
            'retry_same_id_in_same_directory': 'REJECTED',
            'remote_state': 'UNVERIFIED',
            'durable_rollback_resistance': False,
            'owner_identity_verified': False,
            'fresh_b2_go_granted': False,
            'execution_allowed': False,
            'real_aiven_connected': False,
            'production_go': False,
        }
    except BaseException:
        raise Block() from None
    finally:
        if owned is not None:
            try:
                os.close(owned)
            except BaseException:
                # A local close error cannot issue GO or safely erase a marker.
                raise Block() from None


def authorize(*_args, **_kwargs):
    raise Block()
