"""R1 OFFLINE fixture boundary. Only internally owned PTYs and a fixed mock.

No endpoint/config/CA path/password/driver/descriptor CLI or input loader exists.
The synthetic gesture is NOT owner authentication or permission to connect.
"""
from dataclasses import dataclass
from enum import Enum
import hashlib
import os
import pty
import stat
import threading
import time

from e858_b_offline import SOURCE, RELEASE, SHA
from e858_tls.transport import HOST, DB, USER, PASSWORD, SQL_SHA, CELL, ROW, SECTION, TOTAL
from .r1_tty import PrivateTTYAttempt
from .protocol import Gate

# Marker bytes exercise CA sealing only; NOT a certificate or TLS evidence.
CA_MARKER = b'SYNTHETIC_CA_SEAL_ONLY_NOT_X509'
SYNTHETIC_PASSWORD = PASSWORD.encode('ascii')
Q0_SHA = '092b3b57f2cc33abe4857da34d50dbefc316e61c9b3073bb575233b7569e7a89'
Q1_SHA = '0923d34edf5c4d241e7ba853045e7443943ebeee81153a6c49ed8c5b080ebc24'


class Block(Exception):
    """Fixed error only; no original exception/secret/identity interpolation."""


class InputAction(Enum):
    SYNTHETIC_OPERATOR_GESTURE = 'OFFLINE_ONLY_NOT_OWNER_AUTHENTICATION'


@dataclass(frozen=True, repr=False)
class Contract:
    tooling_sha: str
    service: str = 'owned-synthetic-fixture'
    database: str = DB
    host: str = HOST
    port: int = 3306
    principal: str = USER
    ca_bytes: bytes = CA_MARKER
    ca_sha256: str = hashlib.sha256(CA_MARKER).hexdigest()
    san: str = HOST
    tls: str = 'VERIFY_IDENTITY'
    verify_certificate: bool = True
    verify_hostname: bool = True
    minimum_tls: str = 'TLSv1.2'
    source_sha: str = SOURCE
    release_ref: str = RELEASE
    sql_sha256: str = SQL_SHA
    q0_sha256: str = Q0_SHA
    q1_sha256: str = Q1_SHA
    limits: tuple = (2, 15, 42, 10, 120, CELL, ROW, SECTION, TOTAL)

    def __repr__(self):
        return '<redacted synthetic R1 contract; not adopted>'


def _fd_identity(fd):
    if type(fd) is not int or fd <= 2:
        raise Block('R1_ADAPTER_BLOCKED')
    value = os.fstat(fd)
    return value.st_dev, value.st_ino, value.st_rdev, value.st_mode


class OwnedSyntheticTTY:
    """No injected descriptor or path. Actual managed-Mac /dev/tty stays absent."""
    def __init__(self):
        self._owned, self._created = {}, []
        self.master, self.slave = None, None
        self._cleanup = True

    def acquire(self):
        if self._created:
            raise Block('R1_ADAPTER_BLOCKED')
        try:
            self.master, self.slave = pty.openpty()
            self._created = [self.master, self.slave]
            for fd in (self.master, self.slave):
                self._owned[fd] = _fd_identity(fd)
                os.set_inheritable(fd, False)
            self.check()
            if self.master == self.slave:
                raise Block('R1_ADAPTER_BLOCKED')
        except BaseException:
            self.close()
            raise Block('R1_ADAPTER_BLOCKED') from None

    def check(self):
        if set(self._owned) != {self.master, self.slave}:
            raise Block('R1_ADAPTER_BLOCKED')
        for fd, expected in self._owned.items():
            if (_fd_identity(fd) != expected or not os.isatty(fd)
                    or not stat.S_ISCHR(expected[3]) or os.get_inheritable(fd)):
                raise Block('R1_ADAPTER_BLOCKED')

    def close(self):
        verified = self._cleanup and len(self._created) == len(self._owned)
        for fd, expected in self._owned.items():
            try:
                # Never close an unknown/reused descriptor as a fallback.
                if _fd_identity(fd) != expected:
                    verified = False
                    continue
                os.close(fd)
            except BaseException:
                verified = False
        self._owned.clear()
        self._created.clear()
        self._cleanup = verified
        return verified

    def __repr__(self):
        return '<owned synthetic TTY; identifiers redacted>'


class SyntheticDriver:
    """Fixed no-I/O mock only. No user-supplied driver or connect method."""
    def __init__(self, role, lease, q0, q1, until, owner):
        if not lease.readonly or lease != SYNTHETIC_PASSWORD:
            raise Block('R1_ADAPTER_BLOCKED')
        self.role, self._lease, self._owner = role, lease, owner
        self._q0, self._q1, self._until = q0, q1, until
        self.gate = Gate(role, q0, q1, until)
        self.connect_count, self.quit_written, self.closed = 1, False, False

    def claim_mock(self, sql):
        owner = self._owner
        if owner is None:
            raise Block('R1_ADAPTER_BLOCKED')
        try:
            if (self.closed or owner._closed or not owner._sealed()
                    or type(self.gate) is not Gate or self.gate.role != self.role
                    or self.gate.q0 != self._q0 or self.gate.q1 != self._q1
                    or self.gate.deadline > self._until):
                owner._block()
            return self.gate.claim(3, sql)  # validate only; never execute SQL
        except BaseException:
            owner._block()  # refusal is terminal for both channels and input

    def close(self):
        if self._lease is not None:
            self._lease.release()
            self._lease = None
        self.closed = True

    def __repr__(self):
        return '<synthetic driver; credentials redacted; no I/O>'


class SyntheticAdapter:
    """Capture once, lend one shared buffer to control then data, dispose once.

    reviewed_tooling_sha is a caller's offline review pin, NOT proof of authority
    or of checkout integrity; reuse the existing source-bound preflight for that.
    """
    def __init__(self, reviewed_tooling_sha, q0, q1, candidate=None):
        self._reviewed_tooling_sha = reviewed_tooling_sha
        self.candidate = Contract(reviewed_tooling_sha) if candidate is None else candidate
        self._q0, self._q1 = q0, q1
        self._entered = False
        self._input = PrivateTTYAttempt()
        self._tty, self._drivers, self._leases = None, [], []
        self._used, self._closed, self._ready = False, False, False
        self._cleanup, self._status = True, 'NOT_STARTED'

    def __repr__(self):
        return '<offline R1 adapter; no execution authority>'

    def _block(self):
        self._status = 'R1_ADAPTER_BLOCKED' if self._cleanup else 'R1_CLEANUP_UNVERIFIED'
        self.close()
        raise Block('R1_ADAPTER_BLOCKED') from None

    def _sealed(self):
        from dataclasses import fields
        if type(self._reviewed_tooling_sha) is not str or not SHA.fullmatch(self._reviewed_tooling_sha):
            return False
        expected = Contract(self._reviewed_tooling_sha)
        if type(self.candidate) is not Contract:
            return False
        for field in fields(expected):
            value, fixed = getattr(self.candidate, field.name), getattr(expected, field.name)
            if type(value) is not type(fixed):
                return False
            if type(fixed) is tuple and any(type(a) is not type(b) for a, b in zip(value, fixed)):
                return False
            if value != fixed:
                return False
        return (type(self._reviewed_tooling_sha) is str and SHA.fullmatch(self._reviewed_tooling_sha)
                and type(self._q0) is bytes and hashlib.sha256(self._q0).hexdigest() == Q0_SHA
                and type(self._q1) is bytes and hashlib.sha256(self._q1).hexdigest() == Q1_SHA)

    def capture(self, action):
        if self._used or self._closed or not self._entered:
            self._block()
        self._used = True  # failure consumes this in-process attempt too
        if action is not InputAction.SYNTHETIC_OPERATOR_GESTURE or not self._sealed():
            self._block()
        stop = threading.Event()
        writer = None
        try:
            if threading.current_thread() is not threading.main_thread():
                self._block()
            self._tty = OwnedSyntheticTTY()
            self._tty.acquire()
            self._tty.check()
            # Input is always this fixed synthetic fixture, never a real prompt.
            def send_fixture():
                import termios
                try:
                    while not stop.wait(.001):
                        self._tty.check()
                        if not termios.tcgetattr(self._tty.slave)[3] & (termios.ECHO | termios.ECHONL):
                            os.write(self._tty.master, SYNTHETIC_PASSWORD + b'\n')
                            return
                except BaseException:
                    # Unblock only a still-owned master. Never close a reused
                    # descriptor after concurrent/error cleanup.
                    try:
                        fd = self._tty.master
                        expected = self._tty._owned.get(fd)
                        if expected is not None and _fd_identity(fd) == expected:
                            os.close(fd)
                            del self._tty._owned[fd]
                            self._tty._created.remove(fd)
                    except BaseException:
                        self._tty._cleanup = False
            writer = threading.Thread(target=send_fixture, daemon=True)
            writer.start()
            self._status = self._input.capture(self._tty.slave)
            stop.set(); writer.join(1)
            if writer.is_alive() or self._status != 'R1_CAPTURED_OFFLINE':
                self._block()
            self._tty.check()
            self._until = time.monotonic() + 120
            self._ready = True
            return self.report()
        except BaseException:
            self._block()
        finally:
            stop.set()
            if writer is not None:
                writer.join(1)

    def open_mock(self, role):
        if (not self._ready or self._closed or type(role) is not str
                or len(self._drivers) >= 2 or any(d.closed for d in self._drivers)
                or role != ('control', 'data')[len(self._drivers)]
                or not self._sealed()):
            self._block()
        try:
            self._tty.check()
            lease = memoryview(self._input._password).toreadonly()
            self._leases.append(lease)  # owned even when driver creation fails
            driver = SyntheticDriver(role, lease, self._q0, self._q1, self._until, self)
            self._drivers.append(driver)
            return driver
        except BaseException:
            self._block()

    def close(self):
        if self._closed:
            return self.report()
        self._closed, self._ready, self._entered = True, False, False
        for driver in reversed(self._drivers):
            try: driver.close()
            except BaseException: self._cleanup = False
            finally:
                # Also remove references if the mock close itself failed.
                driver._lease = None
                driver._owner = None
        self._drivers.clear()
        for lease in self._leases:
            try: lease.release()
            except BaseException: self._cleanup = False
        self._leases.clear()
        try: self._input.discard()
        except BaseException: self._cleanup = False
        if self._tty is not None:
            self._cleanup = self._tty.close() and self._cleanup
        if not self._cleanup:
            self._status = 'R1_CLEANUP_UNVERIFIED'
        return self.report()

    def report(self):
        return dict(status=self._status, cleanup_verified=self._closed and self._cleanup,
                    secret_released=self._closed and not self._input._password,
                    execution_allowed=False, owner_approval_verified=False,
                    real_aiven_connected=False, release_authorized=False,
                    new_owner_go_required=True)

    def __enter__(self):
        if self._used or self._closed or self._entered:
            self._block()
        self._entered = True
        return self

    def __exit__(self, kind, value, traceback):
        if kind is not None:
            self._status = 'R1_ADAPTER_BLOCKED' if self._cleanup else 'R1_CLEANUP_UNVERIFIED'
        self.close()
        # Never render foreign exception text; Python context/debug inspection
        # is not a confidentiality boundary (see the existing memory caveat).
        if kind is not None:
            raise Block('R1_ADAPTER_BLOCKED') from None
