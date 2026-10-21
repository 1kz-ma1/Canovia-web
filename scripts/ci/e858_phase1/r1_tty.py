"""Offline-only R1 input boundary; deliberately not imported by any runtime/CLI.

Only an explicitly supplied, private terminal descriptor is accepted. No stdin,
login-path, environment, password file, network or adapter handoff exists here.
Python/OS copies, debugger access and uncatchable process death remain outside
this boundary; discard() is best effort, never a secure-erasure guarantee.
"""
import copy
import os
import select
import termios
import threading
import time

MAX_PASSWORD_BYTES = 1024
INPUT_TIMEOUT_SECONDS = 120


class PrivateTTYAttempt:
    """One in-process attempt, including failure. Not durable authorization.

    capture returns a fixed classification only. The private mutable buffer is
    retained solely for offline boundary tests; no public secret-export method
    or live adapter is implemented. repr/str never render its contents.
    """

    def __init__(self):
        self._used = False
        self._lock = threading.Lock()
        self._password = bytearray()

    def __repr__(self):
        return '<PrivateTTYAttempt redacted; no execution authority>'

    def discard(self):
        with self._lock:
            self._erase()

    def _erase(self):
        for position in range(len(self._password)):
            self._password[position] = 0
        self._password.clear()

    def capture(self, terminal_fd):
        # No blocking retry, including simultaneous calls on this same attempt.
        if not self._lock.acquire(blocking=False):
            return 'R1_RETRY_REFUSED'
        try:
            if self._used:
                return 'R1_RETRY_REFUSED'
            self._used = True
            # Python delivers SIGINT to the main thread, not an input worker.
            if threading.current_thread() is not threading.main_thread():
                return 'R1_INPUT_BLOCKED'
            return self._capture_once(terminal_fd)
        finally:
            self._lock.release()

    def _capture_once(self, terminal_fd):
        descriptor = None
        original = None
        status = 'R1_INPUT_BLOCKED'
        if type(terminal_fd) is not int or terminal_fd in (0, 1, 2):
            return status
        try:
            # Own a stable duplicate; never close or fall back to caller stdin.
            descriptor = os.dup(terminal_fd)
            if not os.isatty(descriptor):
                status = 'R1_NON_TTY'
            else:
                original = termios.tcgetattr(descriptor)
                hidden = copy.deepcopy(original)
                hidden[0] |= termios.ICRNL
                hidden[0] &= ~(termios.INLCR | termios.IGNCR | termios.ISTRIP |
                               termios.IXON | termios.IXOFF | termios.PARMRK | termios.INPCK)
                hidden[3] |= termios.ISIG
                hidden[3] &= ~(termios.ICANON | termios.IEXTEN | termios.ECHO | termios.ECHONL)
                hidden[6][termios.VMIN] = 1
                hidden[6][termios.VTIME] = 0
                # Flush pending input before disabling echo; never read it first.
                termios.tcsetattr(descriptor, termios.TCSAFLUSH, hidden)
                if termios.tcgetattr(descriptor) != hidden:
                    status = 'R1_TERMINAL_SETUP_BLOCKED'
                else:
                    status = self._read_hidden(descriptor)
        except KeyboardInterrupt:
            status = 'R1_INTERRUPTED'
        except BaseException:
            # Do not retain, interpolate, chain, log or return exception objects.
            status = 'R1_INPUT_BLOCKED'
        finally:
            if original is not None:
                try:
                    # Discard queued secret suffixes BEFORE re-enabling echo.
                    termios.tcsetattr(descriptor, termios.TCSAFLUSH, original)
                    if termios.tcgetattr(descriptor) != original:
                        status = 'R1_TERMINAL_RESTORE_UNVERIFIED'
                except BaseException:
                    status = 'R1_TERMINAL_RESTORE_UNVERIFIED'
            if descriptor is not None:
                try:
                    os.close(descriptor)
                except BaseException:
                    status = 'R1_TERMINAL_CLEANUP_UNVERIFIED'
            if status != 'R1_CAPTURED_OFFLINE':
                self._erase()
        return status

    def _read_hidden(self, descriptor):
        # Noncanonical, one byte at a time: no platform canonical-line truncation
        # can turn an overlong password into an accepted shorter password.
        deadline = time.monotonic() + INPUT_TIMEOUT_SECONDS
        while True:
            remaining = deadline - time.monotonic()
            if remaining <= 0 or not select.select([descriptor], [], [], remaining)[0]:
                return 'R1_INPUT_TIMEOUT'
            value = os.read(descriptor, 1)
            if not value or value == b'\x04':
                return 'R1_EOF'
            if value == b'\n':
                return 'R1_CAPTURED_OFFLINE' if self._password else 'R1_EMPTY'
            if value[0] < 32 or value[0] == 127:
                return 'R1_INPUT_BLOCKED'
            if len(self._password) >= MAX_PASSWORD_BYTES:
                return 'R1_OVERSIZE'
            self._password.append(value[0])
