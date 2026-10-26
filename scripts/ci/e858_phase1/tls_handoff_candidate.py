"""Reviewed peer -> real TLS handshake handoff for an already-owned socket.

This component is NOT an opener or live adapter. It cannot resolve, dial,
capture credentials, issue SQL, authenticate the operator, approve B2 or retry.
It only accepts a preconnected socket whose actual peer, CA-bound SSLContext,
hostname, SNI and shared deadline are checked by OpenerCandidate before the
first TLS byte and again after the handshake. Used in owned MySQL CI today.

EXCLUDED from the closed B1 review bundle. No Aiven live activation exists.
"""
import socket
import ssl

from .opener_candidate import OpenerCandidate
from .private_binding import Block


class VerifiedTLSHandoff:
    """At most one TLS handshake on the precise already-selected peer."""

    def __init__(self, plan, raw, server_hostname):
        self._plan=None;self._raw=None;self._hostname=None;self._started=False
        self._owned_socket=None;self._binding=None
        try:
            if (type(plan) is not OpenerCandidate or type(raw) is not socket.socket
                    or type(server_hostname) is not str):raise Block()
            # Before acceptance the caller owns raw; rejected inputs are not
            # cleanup callbacks. Thereafter retain the original owner reference.
            self._owned_socket=raw
            context=plan.context
            if type(context) is not ssl.SSLContext or context.sslsocket_class is not ssl.SSLSocket:raise Block()
            # The plan checks target, expected SNI, peer and 2-second deadline.
            plan.before_tls(raw,server_hostname)
            raw.settimeout(plan.remaining())
            self._plan=plan;self._raw=raw;self._hostname=server_hostname
            self._binding=(plan,raw,server_hostname,context)
        except BaseException:
            self.close()
            raise Block() from None

    def handshake(self):
        if self._started:raise Block()
        self._started=True
        try:
            if self._binding is None:raise Block()
            plan,raw,hostname,context=self._binding
            if (self._plan is not plan or self._raw is not raw or self._owned_socket is not raw
                    or self._hostname is not hostname or plan.context is not context
                    or context.sslsocket_class is not ssl.SSLSocket):raise Block()
            plan.remaining()  # Includes elapsed DNS/TCP time, no new budget.
            # Native wrapping detaches raw's FD. Take ownership of the native
            # TLS socket BEFORE handshake, so failures have a definite owner.
            wrapped=ssl.SSLContext.wrap_socket(context,raw,server_hostname=hostname,
                do_handshake_on_connect=False)
            if type(wrapped) is not ssl.SSLSocket:raise Block()
            self._owned_socket=wrapped;self._raw=None
            wrapped.settimeout(plan.remaining())
            wrapped.do_handshake()
            plan.after_tls(wrapped)  # Actual certificate, TLS, peer and deadline.
            self._owned_socket=None  # Successful return transfers TLS ownership.
            self._plan=None;self._hostname=None;self._binding=None
            return wrapped
        except BaseException:
            self.close()  # Cleanup failure is BLOCK, never suppressed success.
            # The existing owned driver classifies the original TLS cause and
            # converts it to a fixed private failure code. Never log it here.
            raise

    def close(self):
        self._started=True;self._plan=None;self._raw=None;self._hostname=None;self._binding=None
        owned=self._owned_socket
        if owned is not None:
            if type(owned) not in (socket.socket,ssl.SSLSocket):raise Block()
            try:
                # A failed native wrap may already have detached/closed raw.
                if owned.fileno()!=-1:owned.close()
                if owned.fileno()!=-1:raise Block()
            except BaseException:
                # Retain the resource for caller cleanup only; handshake stays
                # terminal even if a later close succeeds.
                raise Block() from None
            self._owned_socket=None

    def __repr__(self):
        return '<single-use TLS handoff; no connection authority>'
