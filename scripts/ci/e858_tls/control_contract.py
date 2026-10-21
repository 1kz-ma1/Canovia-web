"""Offline B1 model, never an execution interface or real approval authority.

Private synthetic assertions enter; only fixed, non-executable verdicts leave.
No sockets, credentials, SQL execution, arbitrary CID action, or live CLI exists.
A generation token is a fixture assertion, NOT MySQL anti-CID-reuse proof.
"""
from dataclasses import dataclass
import hashlib
import re
from types import MappingProxyType

from e858_b_offline import validate, canonical, outcome, Block as PacketBlock


class Block(Exception):
    """Fixed classifier only. Never include an input or underlying exception."""


@dataclass(frozen=True, repr=False)
class Binding:
    target: str
    server_uuid: str
    principal: str
    cid: int
    generation: str
    ca: str
    san: str
    tls: str = 'VERIFY_IDENTITY'
    tls_verified: bool = True
    grants: str = 'EXACT_MINIMAL'

    def __repr__(self):
        return '<private synthetic binding>'


@dataclass(frozen=True, repr=False)
class ExpectedTarget:
    target: str
    server_uuid: str
    principal: str
    ca: str
    san: str

    def __repr__(self):
        return '<private synthetic target>'


@dataclass(frozen=True, repr=False)
class Observation:
    data: Binding
    control: Binding
    sequence: int
    observed_at: int
    visible: bool
    exists: bool
    running: bool
    continuity: bool
    observer_connected: bool = True

    def __repr__(self):
        return '<private synthetic observation>'


class Attempt:
    """Single in-memory synthetic trial; fresh instances are NOT anti-replay.

    Callers supply expected pins from the existing integrity checker and SQL
    hashes from its frozen bundle, not a packet-authorized replacement bundle.
    This object never invokes Observer.terminate or emits executable permits.
    """
    def __repr__(self):
        return '<offline synthetic attempt>'

    def __init__(self, packet, pins, expected_pins, sql_digests, now, expected_target):
        self.state = 'STOPPED'
        self.remote = 'REMOTE_TERMINATION_UNVERIFIED'
        self.query_ended = False
        self.connection_ended = False
        self.last_sequence = 0
        self.last_time = now
        self.seen = False
        try:
            if type(now) is not int or type(expected_target) is not ExpectedTarget:
                raise Block('PREFLIGHT_REJECTED')
            self.expected = expected_target
            if canonical(pins) != canonical(expected_pins):
                raise Block('INTEGRITY_REJECTED')
            validate(packet, expected_pins, now)
            raw = canonical(sql_digests)
            if (type(sql_digests) is not dict or len(sql_digests) != 14
                    or any(type(k) is not str or type(v) is not str or not re.fullmatch('[0-9a-f]{64}', v)
                           for k, v in sql_digests.items())
                    or hashlib.sha256(raw).hexdigest() != expected_pins['sql_sha256']):
                raise Block('INTEGRITY_REJECTED')
            self.sql = MappingProxyType(dict(sql_digests))
            self.expires = packet['ends_at']
        except (PacketBlock, KeyError, TypeError, ValueError, OverflowError, RecursionError):
            raise Block('PREFLIGHT_REJECTED') from None
        self.state = 'NEW'

    def _stop(self, code):
        self.state = 'STOPPED'
        # Never promote an earlier partial observation after a failed check.
        self.remote = 'REMOTE_TERMINATION_UNVERIFIED'
        self.query_ended = False
        self.connection_ended = False
        raise Block(code) from None

    def _active(self, now):
        if self.state != 'ACTIVE':
            self._stop('NEW_OWNER_GO_REQUIRED')
        if type(now) is not int or now < self.last_time or now >= self.expires:
            self._stop('WINDOW_REJECTED')

    def bind(self, data, control, initial, now, scope=('OBSERVE',), metadata_scope=('identity', 'grants')):
        if self.state != 'NEW':
            self._stop('NEW_OWNER_GO_REQUIRED')
        self.state = 'STOPPED'  # Consume the attempt before validating bindings.
        if type(now) is not int or now < self.last_time or now >= self.expires:
            self._stop('WINDOW_REJECTED')
        for value in (data, control):
            if (type(value) is not Binding or type(value.cid) is not int or not 0 < value.cid < 2**32
                    or any(type(s) is not str or not re.fullmatch('[0-9a-f]{64}', s)
                           for s in (value.target, value.generation, value.ca, value.san))
                    or type(value.principal) is not str or not 0 < len(value.principal) <= 256
                    or type(value.server_uuid) is not str
                    or not re.fullmatch('[0-9a-f]{8}(-[0-9a-f]{4}){3}-[0-9a-f]{12}', value.server_uuid)):
                self._stop('BINDING_REJECTED')
            if value.tls != 'VERIFY_IDENTITY' or value.tls_verified is not True:
                self._stop('TLS_WEAKENING_REJECTED')
            if value.grants != 'EXACT_MINIMAL':
                self._stop('MINIMAL_GRANTS_BLOCK')
        if any(getattr(data, k) != getattr(self.expected, k) for k in ('target', 'server_uuid', 'principal', 'ca', 'san')):
            self._stop('TARGET_REJECTED')
        if (any(getattr(data, k) != getattr(control, k) for k in ('target', 'server_uuid', 'principal', 'ca', 'san'))
                or data.cid == control.cid or data.generation == control.generation):
            self._stop('CONTROL_SCOPE_UNVERIFIED')
        if (type(scope) is not tuple or not scope or any(type(k) is not str for k in scope) or len(scope) != len(set(scope))
                or 'OBSERVE' not in scope or any(k not in ('OBSERVE', 'KILL_QUERY', 'KILL_CONNECTION') for k in scope)):
            self._stop('CONTROL_SCOPE_UNVERIFIED')
        if (type(metadata_scope) is not tuple or any(type(k) is not str for k in metadata_scope)
                or len(metadata_scope) != len(set(metadata_scope)) or any(k not in self.sql for k in metadata_scope)):
            self._stop('DATA_SCOPE_REJECTED')
        self.data_scope = metadata_scope
        self.data, self.control, self.scope = data, control, scope
        self.state = 'ACTIVE'
        self.observe(initial, now)
        if not self.seen:
            self._stop('CONTROL_SCOPE_UNVERIFIED')
        return self.report()

    def observe(self, value, now):
        self._active(now)
        if (type(value) is not Observation or value.data != self.data or value.control != self.control
                or value.continuity is not True or value.visible is not True or value.observer_connected is not True
                or type(value.sequence) is not int or value.sequence != self.last_sequence + 1
                or type(value.observed_at) is not int or value.observed_at != now
                or type(value.exists) is not bool or type(value.running) is not bool
                or (not value.exists and value.running)):
            self._stop('CONTROL_SCOPE_UNVERIFIED')
        if self.connection_ended and value.exists:
            self._stop('CONTROL_SCOPE_UNVERIFIED')
        if not value.exists and not self.seen:
            self._stop('REMOTE_TERMINATION_UNVERIFIED')
        self.last_sequence, self.last_time = value.sequence, now
        self.seen = self.seen or value.exists
        self.query_ended = not value.running
        self.connection_ended = not value.exists
        return self.report()

    def check_control(self, operation, fresh_observation, now):
        self._active(now)
        if operation not in self.scope:
            self._stop('CONTROL_SCOPE_REJECTED')
        # Every decision requires a fresh matching observation. No arbitrary
        # cid/SQL/target argument is accepted, and no KILL callback is exposed.
        self.observe(fresh_observation, now)
        if operation != 'OBSERVE' and self.connection_ended:
            self._stop('CONTROL_SCOPE_UNVERIFIED')
        return self.report()

    def check_metadata(self, key, digest, now, *, export=False):
        self._active(now)
        if self.connection_ended:
            self._stop('CONTROL_SCOPE_UNVERIFIED')
        if (export is not False or type(key) is not str or key not in self.sql or key not in self.data_scope
                or type(digest) is not str or digest != self.sql[key]):
            self._stop('SQL_OR_EXPORT_REJECTED')
        self.last_time = now
        return self.report()

    def finish(self, final_observation, now, *, query_request_failed=False, connection_request_failed=False):
        self.observe(final_observation, now)
        if (query_request_failed is not False or connection_request_failed is not False
                or not self.query_ended or not self.connection_ended):
            self._stop('REMOTE_TERMINATION_UNVERIFIED')
        self.state = 'COMPLETED'
        self.remote = 'SYNTHETIC_REMOTE_TERMINATION_OBSERVED'
        return self.report()

    def interrupt(self):
        self._stop('REMOTE_TERMINATION_UNVERIFIED')

    def report(self):
        # Deliberately excludes hashes of private identities, CID, raw state,
        # SQL, error text and operator data; never accepts arbitrary log fields.
        return dict(state=self.state, remote=self.remote, query_ended=self.query_ended,
                    connection_ended=self.connection_ended, evidence='SYNTHETIC_TESTED',
                    **outcome('B1_OFFLINE_CONTRACT_ONLY', 'B1_SYNTHETIC_REVIEW_ONLY'))
