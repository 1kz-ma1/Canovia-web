"""Owned Docker R1 fixture only. No endpoint, password or CA-path input API."""
import hashlib
import ipaddress
from pathlib import Path
import socket
import ssl
import time
from importlib.metadata import version

import pymysql
from pymysql import _auth, connections
from e858_b_offline import SHA
from . import runtime as r
from .r1_adapter import SYNTHETIC_PASSWORD, Q0_SHA, Q1_SHA


class IsolatedAttempt:
    def __init__(self, config, captured):
        self.config, self.secret = config, captured
        self.wires = []
        self.used = False
        self.closed = False
        self.until = time.monotonic() + 120

    def seal(self):
        try: return self._seal()
        except BaseException: raise r.Block('R1_SEAL_BLOCK') from None

    def _seal(self):
        cfg = self.config
        pin = cfg['r1']
        fixed = dict(kind='OWNED_DOCKER_R1_ONLY', host=r.HOST, port=3306, database=r.DB,
                     principal=r.USER, san=r.HOST, source_sha=r.SOURCE, release_ref=r.RELEASE,
                     tooling_sha=cfg['pins']['tooling_sha'], sql_sha256=r.SQL_SHA,
                     ca_sha256=cfg['expected']['binding']['ca'], ipv4=pin['ipv4'])
        address = ipaddress.ip_address(pin['ipv4'])
        ca = Path('/synthetic/ca.pem')
        if (pin != fixed or type(pin['tooling_sha']) is not str or not SHA.fullmatch(pin['tooling_sha'])
                or not isinstance(address, ipaddress.IPv4Address) or not address.is_private
                or version('PyMySQL') != '1.1.2' or _auth.DEBUG or connections.DEBUG
                or ca.is_symlink() or not ca.is_file() or ca.stat().st_size > 65536
                or hashlib.sha256(ca.read_bytes()).hexdigest() != pin['ca_sha256']
                or hashlib.sha256(cfg['q0']).hexdigest() != Q0_SHA
                or hashlib.sha256(cfg['q1']).hexdigest() != Q1_SHA):
            raise r.Block('R1_SEAL_BLOCK')
        return pin

    def factory(self, gate, fault=None):
        try: return self._factory(gate, fault)
        except BaseException:
            self.close()
            raise r.Block('R1_DRIVER_BLOCK') from None

    def _factory(self, gate, fault=None):
        if (self.closed or fault is not None or len(self.wires) >= 2
                or gate.role != ('control', 'data')[len(self.wires)]
                or gate.q0 != self.config['q0'] or gate.q1 != self.config['q1']
                or time.monotonic() >= self.until):
            raise r.Block('R1_DRIVER_BLOCK')
        pin = self.seal()
        gate.deadline = min(gate.deadline, self.until)
        wire = object.__new__(R1Wire)
        self.wires.append(wire)  # own even a partly constructed driver
        wire.initialize(gate, self.secret, pin)
        return wire

    def close(self):
        verified = True
        for wire in reversed(self.wires):
            try: wire.close()
            except BaseException: verified = False
            finally: wire.password = b''
        if self.secret is not None:
            for i in range(len(self.secret)): self.secret[i] = 0
            self.secret.clear()
            self.secret = None
        self.closed = True
        return verified

    def __repr__(self):
        return '<isolated R1 attempt; secrets redacted; not live tooling>'


class R1Wire(r.Wire):
    """Inherited SQL/TLS/budget/QUIT enforcement; fixed synthetic target only."""
    def initialize(self, gate, secret, pin):
        # Never accepts a real password: only the two fixed synthetic fixtures.
        if type(secret) is not bytearray or secret not in (SYNTHETIC_PASSWORD, SYNTHETIC_PASSWORD[:-1]+b'x'):
            raise r.Block('R1_SYNTHETIC_SECRET_REQUIRED')
        self.gate, self.pin = gate, dict(pin)
        self.password = b''
        self.alias_verified, self.full_auth_packet_seen = False, False
        self.quit_written = False
        ctx = r.context('/synthetic/ca.pem')
        if (ctx.verify_mode != ssl.CERT_REQUIRED or not ctx.check_hostname
                or ctx.minimum_version < ssl.TLSVersion.TLSv1_2
                or hashlib.sha256(Path('/synthetic/ca.pem').read_bytes()).hexdigest() != pin['ca_sha256']):
            raise r.Block('R1_TLS_BLOCK')
        r.StrictConnection.__init__(self, host=r.HOST, port=3306, user=r.USER,
            password=secret, database=r.DB, ssl=ctx, charset='ascii', autocommit=None, sql_mode=None,
            connect_timeout=2, read_timeout=10, write_timeout=10, cursorclass=pymysql.cursors.SSCursor)
        self.alias_verified = self.password is secret
        if not self.alias_verified: raise r.Block('R1_DRIVER_COPY_CHANGED')

    def connect(self, sock=None):
        # DNS and actual TCP peer must both stay inside this owned fixture.
        if sock is not None: raise r.Block('R1_SOCKET_OVERRIDE_BLOCK')
        addresses = {entry[4][0] for entry in socket.getaddrinfo(r.HOST, 3306, type=socket.SOCK_STREAM)}
        if addresses != {self.pin['ipv4']}: raise r.Block('R1_TARGET_BLOCK')
        result = super().connect()
        if set(self._sock.getpeercert().get('subjectAltName', ())) != {('DNS', r.HOST)}:
            self.close(); raise r.Block('R1_SAN_BLOCK')
        self.tls_verified = True
        return result

    def _request_authentication(self):
        try:
            if self._sock.getpeername()[:2] != (self.pin['ipv4'], 3306):
                raise r.Block('R1_PEER_BLOCK')
            return super()._request_authentication()
        finally:
            # PyMySQL normally keeps password for reconnection. Reconnect is
            # forbidden here; remove that reference immediately after auth.
            self.password = b''

    def write_packet(self, payload):
        # Observe full caching_sha2 TLS auth without copying/logging plaintext.
        if (self.password and len(payload) == len(self.password)+1 and payload[-1] == 0
                and memoryview(payload)[:-1] == memoryview(self.password)):
            if not isinstance(self._sock, ssl.SSLSocket): raise r.Block('R1_TLS_BLOCK')
            self.full_auth_packet_seen = True
        return super().write_packet(payload)

    def _force_close(self):
        try: return super()._force_close()
        finally: self.password = b''

    def close(self):
        try:
            if hasattr(self, '_sock'): super().close()
        finally: self.password = b''
