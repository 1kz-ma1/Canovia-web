"""Reviewed peer -> real TLS handshake handoff for an already-owned socket.

This component is NOT an opener or live adapter. It cannot resolve, dial,
capture credentials, issue SQL, authenticate the operator, approve B2 or retry.
It only accepts a preconnected socket whose actual peer, CA-bound SSLContext,
hostname, SNI and shared deadline are checked by OpenerCandidate before the
first TLS byte and again after the handshake. Used in owned MySQL CI today.

EXCLUDED from the closed B1 review bundle. No Aiven live activation exists.
"""
import socket

from .opener_candidate import OpenerCandidate
from .private_binding import Block


class VerifiedTLSHandoff:
    """At most one TLS handshake on the precise already-selected peer."""

    def __init__(self, plan, raw, server_hostname):
        self._plan=None;self._raw=None;self._hostname=None;self._started=False
        try:
            if (type(plan) is not OpenerCandidate or not isinstance(raw,socket.socket)
                    or type(server_hostname) is not str):raise Block()
            # The plan checks target, expected SNI, peer and 2-second deadline.
            plan.before_tls(raw,server_hostname)
            raw.settimeout(plan.remaining())
            self._plan=plan;self._raw=raw;self._hostname=server_hostname
        except BaseException:
            try:raw.close()
            except BaseException:pass
            raise Block() from None

    def handshake(self):
        if self._started or self._plan is None or self._raw is None:raise Block()
        self._started=True
        wrapped=None;plan=self._plan;raw=self._raw
        try:
            plan.remaining()  # Includes elapsed DNS/TCP time, no new budget.
            wrapped=plan.context.wrap_socket(raw,server_hostname=self._hostname)
            plan.after_tls(wrapped)  # Actual certificate, TLS, peer and deadline.
            self._plan=None;self._raw=None;self._hostname=None
            return wrapped
        except BaseException:
            if wrapped is not None:
                try:wrapped.close()
                except BaseException:pass
            try:raw.close()
            except BaseException:pass
            self._plan=None;self._raw=None;self._hostname=None
            # The existing owned driver classifies the original TLS cause and
            # converts it to a fixed private failure code. Never log it here.
            raise

    def __repr__(self):
        return '<single-use TLS handoff; no connection authority>'
