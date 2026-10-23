"""DNS/TLS opener policy candidate; actual opening is unconditionally closed.

No resolver/dialer, callable injection or execution CLI. Only the separately
owned fixture backend applies these checks to real DNS/TCP/TLS. Peer/IP facts
are private, not authority, and do not supply an Aiven static-IP policy.
"""
from dataclasses import dataclass
import hashlib
import ipaddress
import socket
import ssl
import time

from .private_binding import Block, Target
from .practical import ReviewedEntry

OPEN_SECONDS=2  # existing connect limit, shared across DNS/TCP/TLS; never reset


@dataclass(frozen=True,repr=False)
class Peer:
    family:int
    address:tuple
    def __repr__(self):return '<private DNS selection; no execution authority>'


def select_peer(answers,port):
    """Validate EVERY answer, choose exactly the first; no address fallback."""
    try:
        if type(port) is not int or not 1<=port<=65535 or type(answers) not in (tuple,list) or not 1<=len(answers)<=32:raise Block()
        result=[]
        for row in answers:
            if type(row) not in (tuple,list) or len(row)!=5:raise Block()
            family,kind,protocol,canonical,address=row
            if (family not in (socket.AF_INET,socket.AF_INET6) or type(kind) is bool or kind!=socket.SOCK_STREAM
                    or protocol!=socket.IPPROTO_TCP or canonical!='' or type(address) not in (tuple,list)
                    or len(address)!=(2 if family==socket.AF_INET else 4)
                    or type(address[0]) is not str or len(address[0])>45
                    or type(address[1]) is not int or address[1]!=port
                    or family==socket.AF_INET6 and (tuple(address[2:])!=(0,0) or any(type(v) is not int for v in address[2:]))):raise Block()
            ip=ipaddress.ip_address(address[0])
            if (ip.version!=(4 if family==socket.AF_INET else 6) or '%' in address[0]
                    or ip.is_unspecified or ip.is_multicast or ip.is_loopback or ip.is_link_local):raise Block()
            result.append(Peer(int(family),(ip.compressed,port)+tuple(address[2:])))
        return result[0]
    except BaseException:raise Block() from None


def _ca(context):
    return tuple(sorted(hashlib.sha256(raw).hexdigest() for raw in context.get_ca_certs(binary_form=True)))


class OpenerCandidate:
    """One role/connection policy; does not own a socket or authenticate owner.

    Public open() stays BLOCK even with valid records. The reviewed Entry still
    owns input and total120s; the common boundary enforces Control/Data/max2.
    """
    def __init__(self,entry,gate,context):
        self.entry=None;self.peer=None;self.before=False;self.verified=False
        try:
            if type(entry) is not ReviewedEntry or type(entry.target) is not Target:raise Block()
            self.entry=entry;self.target=entry.target;self.gate=gate;self.context=context
            self.role=gate.role;self.until=min(entry.until,gate.deadline,time.monotonic()+OPEN_SECONDS)
            self.ca=_ca(self.target.tls_context())
            if not self.ca:raise Block()
            self.remaining()
        except BaseException:
            self.close();raise Block() from None

    def open(self):raise Block()  # no live resolver/dialer/activation exists

    def remaining(self):
        try:
            if self.entry is None:raise Block()
            self.entry.check()
            program=self.entry.review.program
            if (self.entry.review.value['scope']!='phase1' or self.entry.target!=self.target
                    or self.role not in ('control','data') or self.gate.role!=self.role
                    or self.gate.q0!=program.queries['identity'] or self.gate.q1!=program.queries['grants']
                    or self.gate.deadline>self.entry.until or self.context.verify_mode!=ssl.CERT_REQUIRED
                    or not self.context.check_hostname or self.context.hostname_checks_common_name
                    or self.context.minimum_version<ssl.TLSVersion.TLSv1_2 or self.context.keylog_filename is not None
                    or _ca(self.context)!=self.ca):raise Block()
            value=min(self.until,self.gate.deadline,self.entry.until)-time.monotonic()
            if value<=0:raise Block()
            return value
        except BaseException:
            self.close();raise Block() from None

    def resolution(self,answers):
        try:
            self.remaining()
            if self.peer is not None:raise Block()
            self.peer=select_peer(answers,self.target.port)
            return self.peer
        except BaseException:
            self.close();raise Block() from None

    def before_tls(self,raw,hostname):
        try:
            self.remaining()
            if (self.before or self.verified or self.peer is None or hostname!=self.target.host
                    or raw.getpeername()!=self.peer.address):raise Block()
            self.before=True
        except BaseException:
            self.close();raise Block() from None

    def after_tls(self,wrapped):
        try:
            self.remaining()
            if (not self.before or self.verified or not isinstance(wrapped,ssl.SSLSocket)
                    or wrapped.context is not self.context or wrapped.server_hostname!=self.target.host
                    or wrapped.getpeername()!=self.peer.address or not wrapped.cipher()
                    or wrapped.version() not in ('TLSv1.2','TLSv1.3')
                    or not wrapped.getpeercert().get('subjectAltName')):raise Block()
            self.verified=True
        except BaseException:
            self.close();raise Block() from None

    def close(self):
        self.entry=None;self.target=None;self.context=None;self.peer=None

    def __repr__(self):return '<closed live opener candidate; no execution authority>'
