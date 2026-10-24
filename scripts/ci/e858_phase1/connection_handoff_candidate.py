"""Inert TCP ownership / MySQL pre-auth packet boundary; no dialing or driver.

Only the archive-excluded owned fixture supplies connected sockets. This module
cannot spawn, resolve, connect, capture a secret, issue SQL or activate B2.
"""
import os
import socket
import ssl
import struct

from .opener_candidate import OpenerCandidate
from .private_binding import Block


class ConnectedPeer:
    """Consume one preconnected TCP socket; no callback/factory injection."""
    def __init__(self,plan,raw):
        self.plan=None;self.raw=raw;self.used=False
        try:
            if type(plan) is not OpenerCandidate or type(raw) is not socket.socket:raise Block()
            self.plan=plan;self.fd=raw.fileno();self._check()
        except BaseException:
            self.close();raise Block() from None

    def _check(self):
        plan=self.plan;raw=self.raw
        if (self.used or plan is None or raw is None or plan.peer is None
                or plan.before or plan.verified or raw.fileno()!=self.fd or self.fd<0
                or os.get_inheritable(self.fd) or raw.family!=plan.peer.family
                or raw.getsockopt(socket.SOL_SOCKET,socket.SO_TYPE)!=socket.SOCK_STREAM
                or raw.getpeername()!=plan.peer.address):raise Block()
        raw.settimeout(plan.remaining())  # elapsed DNS/TCP remains in same 2s

    def take(self):
        try:
            self._check();raw=self.raw;self.raw=None;self.plan=None;self.used=True
            return raw
        except BaseException:
            self.close();raise Block() from None

    def close(self):
        self.used=True;raw=self.raw;self.raw=None;self.plan=None
        if raw is not None:
            try:raw.close()
            except BaseException:raise Block() from None

    def __repr__(self):return '<single-use connected peer; no authority>'


class AuthenticationBoundary:
    """Only a 32-byte SSLRequest before TLS; auth only on verified socket.

    No password retained. PyMySQL and the existing SQL gate still own protocol,
    secret lifetime, auth completion and all post-auth query limits.
    """
    def __init__(self,plan):
        if type(plan) is not OpenerCandidate:raise Block()
        self.plan=plan;self.ssl_request=False;self.stopped=False

    def packet(self,raw,payload):
        try:
            plan=self.plan
            if self.stopped or plan is None or type(payload) not in (bytes,bytearray):raise Block()
            plan.remaining()
            if not plan.verified:
                # SSLRequest is capability/max_packet/charset + 23 zero bytes.
                # It contains no principal/password. A second plaintext packet
                # or TLS wrapping that silently returned raw TCP is refused.
                if (self.ssl_request or type(raw) is not socket.socket or plan.peer is None
                        or raw.getpeername()!=plan.peer.address or len(payload)!=32
                        or payload[9:]!=b'\0'*23
                        or not struct.unpack('<I',payload[:4])[0] & 0x800):raise Block()
                self.ssl_request=True
            elif (not self.ssl_request or not isinstance(raw,ssl.SSLSocket)
                    or raw.context is not plan.context or raw.server_hostname!=plan.target.host
                    or raw.getpeername()!=plan.peer.address or not raw.cipher()
                    or raw.version() not in ('TLSv1.2','TLSv1.3')
                    or not raw.getpeercert().get('subjectAltName')):raise Block()
        except BaseException:
            self.close();raise Block() from None

    def close(self):self.stopped=True;self.plan=None
    def __repr__(self):return '<pre-auth boundary; no secret or authority>'
