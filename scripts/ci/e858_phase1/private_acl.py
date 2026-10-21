"""macOS native ACL observation of an already-open synthetic directory FD.

Observation only. It cannot authenticate an operator, validate ancestor paths,
or authorize private record access / Phase1 connection. Unsupported/error/ACL
present => fail closed. No path/secret/network/TTY input, mutation or CLI.
"""
import ctypes
import errno
import os
import stat
import sys

from .private_binding import Block

ACL_TYPE_EXTENDED = 0x00000100
ACL_FIRST_ENTRY = 0


def _identity(value):
    return (value.st_dev, value.st_ino, value.st_uid,
            value.st_mode, value.st_mtime_ns, value.st_ctime_ns)


def _acl_observation(fd):
    """Read Darwin ACL on FD; distinguish absent record from empty ACL.

    On a synthetic macOS-15 temp directory, acl_get_fd_np returned
    NULL/ENOENT (observed in CI), but this is NOT a proof of zero ACL
    entries, nor an authorization. Other NULL/errno values fail closed.
    A valid ACL with zero entries is separately classified.
    """
    lib = ctypes.CDLL(None, use_errno=True)
    get = lib.acl_get_fd_np
    get.argtypes = [ctypes.c_int, ctypes.c_int]
    get.restype = ctypes.c_void_p
    valid = lib.acl_valid
    valid.argtypes = [ctypes.c_void_p]
    valid.restype = ctypes.c_int
    entry = lib.acl_get_entry
    entry.argtypes = [ctypes.c_void_p, ctypes.c_int, ctypes.POINTER(ctypes.c_void_p)]
    entry.restype = ctypes.c_int
    free = lib.acl_free
    free.argtypes = [ctypes.c_void_p]
    free.restype = ctypes.c_int
    acl = None
    observation = None
    try:
        ctypes.set_errno(0)
        acl = get(fd, ACL_TYPE_EXTENDED)
        if not acl:
            if ctypes.get_errno() == errno.ENOENT:
                return "ACL_RECORD_ENOENT_UNVERIFIED"
            raise Block()
        if valid(acl) != 0:
            raise Block()
        first = ctypes.c_void_p()
        ctypes.set_errno(0)
        result = entry(acl, ACL_FIRST_ENTRY, ctypes.byref(first))
        if result == -1 and ctypes.get_errno() == errno.EINVAL:
            observation = "ZERO_ACL_ENTRIES_OBSERVED"
        else:
            raise Block()
    finally:
        if acl:
            if free(acl) != 0:
                raise Block()
    return observation


def inspect_native_acl(fd):
    """Fail-closed native ACL preflight; never return an execution permit."""
    try:
        if sys.platform != "darwin" or type(fd) is not int or fd <= 2:
            raise Block()
        before = os.fstat(fd)
        if (not stat.S_ISDIR(before.st_mode) or before.st_uid != os.geteuid()
                or stat.S_IMODE(before.st_mode) != 0o700
                or os.get_inheritable(fd)):
            raise Block()
        observed = _acl_observation(fd)
        if observed not in ("ACL_RECORD_ENOENT_UNVERIFIED", "ZERO_ACL_ENTRIES_OBSERVED"):
            raise Block()
        if _identity(before) != _identity(os.fstat(fd)):
            raise Block()
        return dict(kind="INERT_NATIVE_ACL_OBSERVATION",
                    result=observed,
                    ancestor_trust="UNVERIFIED",
                    operator_identity="UNVERIFIED",
                    execution_allowed=False,
                    real_target_read=False,
                    live_tooling_adopted=False)
    except BaseException:
        raise Block() from None
