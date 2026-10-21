"""INERT private-root FD preflight, not trusted operator authorization.

This library starts from a caller-owned anchor FD; it does NOT establish the
trustworthiness of that anchor's ancestors, macOS native ACLs, or the operator.
No CLI, credential input, target reading, network access, or execution path.
"""
import os
import stat

from .private_binding import Block

BASENAME = "phase1-private"


def _identity(value):
    return (value.st_dev, value.st_ino, value.st_uid, value.st_gid,
            value.st_mode, value.st_nlink, value.st_mtime_ns, value.st_ctime_ns)


def _private_directory(value):
    return (stat.S_ISDIR(value.st_mode)
            and stat.S_IMODE(value.st_mode) == 0o700
            and value.st_uid == os.geteuid())


class PreflightRoot:
    """Owns only a preflighted child FD; never attests a live trust chain."""
    def __init__(self, fd, identity):
        self._fd = fd
        self._identity = identity

    def __repr__(self):
        return "<inert private root; owner and ACL unverified>"

    def descriptor(self):
        try:
            if self._fd is None or _identity(os.fstat(self._fd)) != self._identity:
                raise Block()
            return self._fd
        except BaseException:
            raise Block() from None

    def assessment(self):
        return dict(kind="INERT_PRIVATE_ROOT_PREFLIGHT",
                    directory_open=self._fd is not None,
                    ancestor_trust="UNVERIFIED",
                    native_acl="UNVERIFIED",
                    operator_identity="UNVERIFIED",
                    execution_allowed=False,
                    real_private_record_read=False)

    def close(self):
        if self._fd is None:
            return
        fd, expected = self._fd, self._identity
        self._fd = None
        try:
            # Never close an unrelated/reused descriptor after owner loses FD.
            if _identity(os.fstat(fd)) != expected:
                raise Block()
            os.close(fd)
        except BaseException:
            raise Block() from None

    def __enter__(self):
        self.descriptor()
        return self

    def __exit__(self, *_):
        self.close()


def open_private_root(anchor_fd):
    """Open exactly one fixed child under an already supplied private anchor.

    Both directory modes/UIDs, no-follow/open flags, file identities and a
    same-device relation are checked. The caller still must independently
    attest the original anchor path and native ACLs in a future separate gate.
    This check must never be interpreted as owner authentication or a live GO.
    """
    child_fd = None
    try:
        needed = ("O_NOFOLLOW", "O_DIRECTORY", "O_CLOEXEC", "O_NONBLOCK")
        if (type(anchor_fd) is not int or anchor_fd <= 2
                or any(not isinstance(getattr(os, name, None), int)
                       or getattr(os, name) == 0 for name in needed)
                or os.get_inheritable(anchor_fd)):
            raise Block()
        before = os.fstat(anchor_fd)
        if not _private_directory(before):
            raise Block()
        flags = (os.O_RDONLY | os.O_NOFOLLOW | os.O_DIRECTORY |
                 os.O_CLOEXEC | os.O_NONBLOCK)
        child_fd = os.open(BASENAME, flags, dir_fd=anchor_fd)
        after = os.fstat(child_fd)
        if (child_fd <= 2 or os.get_inheritable(child_fd)
                or not _private_directory(after)
                or after.st_dev != before.st_dev
                or _identity(before) != _identity(os.fstat(anchor_fd))
                or _identity(after) != _identity(os.fstat(child_fd))):
            raise Block()
        owned = PreflightRoot(child_fd, _identity(after))
        child_fd = None
        return owned
    except BaseException:
        raise Block() from None
    finally:
        if child_fd is not None:
            try:
                os.close(child_fd)
            except BaseException:
                raise Block() from None
