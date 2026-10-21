"""INERT foreground terminal observation. No real credential or owner authority.

An already-owned FD is provided by a caller. We never open /dev/tty,
read, write, alter termios, or return the FD. Foreground-process membership
is NOT proof that a human is present or is the authorized owner. This
library is deliberately independent of any live connector or CLI.
"""
import os
import stat
import sys
import termios
import threading

from .private_binding import Block


def _identity(value):
    return (value.st_dev, value.st_ino, value.st_rdev, value.st_uid,
            value.st_gid, value.st_mode, value.st_ctime_ns)


def observe_foreground_terminal(terminal_fd):
    """Return a redacted observation, never an authentication verdict."""
    try:
        if (sys.platform != 'darwin' or type(terminal_fd) is not int
                or terminal_fd <= 2
                or threading.current_thread() is not threading.main_thread()):
            raise Block()
        before = os.fstat(terminal_fd)
        if (not stat.S_ISCHR(before.st_mode)
                or before.st_uid != os.geteuid()
                or os.get_inheritable(terminal_fd)
                or not os.isatty(terminal_fd)):
            raise Block()
        group = os.getpgrp()
        session = os.getsid(0)
        if (type(group) is not int or group <= 0
                or type(session) is not int or session <= 0
                or os.tcgetpgrp(terminal_fd) != group):
            raise Block()
        mode = termios.tcgetattr(terminal_fd)
        if (len(mode) != 7
                or _identity(before) != _identity(os.fstat(terminal_fd))
                or os.getpgrp() != group or os.getsid(0) != session
                or os.tcgetpgrp(terminal_fd) != group
                or termios.tcgetattr(terminal_fd) != mode):
            raise Block()
        return {
            'kind': 'INERT_FOREGROUND_TERMINAL_OBSERVATION',
            'foreground_group': 'OBSERVED_NOT_AUTHENTICATED',
            'terminal_owner': 'EUID_MATCH_OBSERVED',
            'session_stability': 'OBSERVED',
            'explicit_owner_gesture': 'UNVERIFIED',
            'operator_identity': 'UNVERIFIED',
            'terminal_restoration': 'NOT_APPLICABLE_NO_MUTATION',
            'ancestor_trust': 'UNVERIFIED',
            'execution_allowed': False,
            'real_credential_read': False,
            'live_tooling_adopted': False,
        }
    except BaseException:
        raise Block() from None
