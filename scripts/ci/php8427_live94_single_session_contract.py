#!/usr/bin/env python3
"""Inert Live94 single-session audit contract. Synthetic witness data ONLY.

There is deliberately NO connector, DNS, socket, SQL executor, credential
reader, process launcher, permission check against real Aiven, or GO switch.
A passing simulated trace never authenticates a target, principal or owner.
"""
from __future__ import annotations

from dataclasses import dataclass
import math
import re
import time

from scripts.ci.php8427_live94_offline_source_gate import (
    LIVE_SHA, LIVE_MIGRATION_TREE, LIVE_MIGRATION_COUNT,
)

TOTAL_SECONDS = 120.0
STEP_SECONDS = 10.0
MAX_EVENTS = 7
PHASES = (
    "identity", "grants", "inventory", "ledger",
    "identity_recheck", "local_quit", "local_cleanup",
)
BYTES_CAP = dict(identity=2048, grants=16384, inventory=32768,
                 ledger=131072, identity_recheck=2048,
                 local_quit=0, local_cleanup=0)
FINGERPRINT = re.compile(r"[0-9a-f]{64}\Z")
SCOPE = "SCHEMA_REFERENCES_AND_MIGRATIONS_SELECT"


class Block(Exception):
    """An offline STOP must not contain observations or exception details."""


@dataclass(frozen=True)
class SyntheticWitness:
    phase: str
    session: str
    server: str
    principal: str
    tls_identity_verified: bool
    byte_count: int = 0
    row_count: int = 0
    effective_scope: str = ""
    active_and_mandatory_roles_absent: bool = False
    inventory_complete: bool = False
    local_cleanup_verified: bool = False


class Live94Contract:
    """Simulated single-session sequencing with terminal failure/no retry.

    Only accepts intentionally synthetic opaque fingerprints. It does not
    validate real TLS, real SQL, SQL output authenticity or server termination.
    """
    def __init__(self, *, source_sha: str, migration_tree: str, clock=time.monotonic):
        if source_sha != LIVE_SHA or migration_tree != LIVE_MIGRATION_TREE:
            raise Block() from None
        self._clock = clock
        self._start = self._tick()
        self._last = self._start
        self._step = 0
        self._state = "ACTIVE_SIMULATION"
        self._session = None
        self._server = None
        self._principal = None
        self._consumed = False

    def _tick(self) -> float:
        try:
            value = self._clock()
        except BaseException:
            raise Block() from None
        if type(value) not in (int, float) or not math.isfinite(value):
            raise Block()
        return float(value)

    def _stop(self) -> None:
        self._state = "STOPPED"
        self._consumed = True
        self._session = self._server = self._principal = None

    def connect(self, *args, **kwargs):
        self._stop()
        raise Block()  # Must remain unconditional; no real activation path.

    def execute(self, *args, **kwargs):
        self._stop()
        raise Block()  # No MySQL/SQL or external side effects.

    def observe_synthetic(self, witness: SyntheticWitness) -> None:
        """Process a mock witness; never issue or trigger a real query."""
        if self._state != "ACTIVE_SIMULATION" or self._consumed:
            raise Block()
        try:
            now = self._tick()
            if (now < self._last
                    or now - self._start >= TOTAL_SECONDS
                    or now - self._last >= STEP_SECONDS):
                raise Block()
            if type(witness) is not SyntheticWitness:
                raise Block()
            if self._step >= MAX_EVENTS or witness.phase != PHASES[self._step]:
                raise Block()
            if (not all(
                type(v) is str and FINGERPRINT.fullmatch(v)
                for v in (witness.session, witness.server, witness.principal)
            ) or witness.tls_identity_verified is not True):
                raise Block()
            if self._step == 0:
                self._session = witness.session
                self._server = witness.server
                self._principal = witness.principal
            elif (witness.session, witness.server, witness.principal) != (
                self._session, self._server, self._principal
            ):
                raise Block()
            if (type(witness.byte_count) is not int or witness.byte_count < 0
                    or witness.byte_count > BYTES_CAP[witness.phase]
                    or type(witness.row_count) is not int or witness.row_count < 0
                    or witness.row_count > 4000):
                raise Block()

            phase = witness.phase
            if phase == "identity":
                if not 0 < witness.byte_count <= BYTES_CAP[phase] or witness.row_count != 1:
                    raise Block()
            elif phase == "grants":
                if (not 0 < witness.byte_count <= BYTES_CAP[phase]
                        or witness.row_count < 1 or witness.row_count > 8
                        or witness.effective_scope != SCOPE
                        or witness.active_and_mandatory_roles_absent is not True):
                    raise Block()
            elif phase == "inventory":
                if (not 0 < witness.byte_count <= BYTES_CAP[phase]
                        or not 1 <= witness.row_count <= 512
                        or witness.inventory_complete is not True):
                    raise Block()
            elif phase == "ledger":
                if (not 0 < witness.byte_count <= BYTES_CAP[phase]
                        or witness.row_count != LIVE_MIGRATION_COUNT):
                    raise Block()
            elif phase == "identity_recheck":
                if not 0 < witness.byte_count <= BYTES_CAP[phase] or witness.row_count != 1:
                    raise Block()
            elif phase in ("local_quit", "local_cleanup"):
                if witness.byte_count != 0 or witness.row_count != 0:
                    raise Block()
                if phase == "local_cleanup" and witness.local_cleanup_verified is not True:
                    raise Block()

            self._step += 1
            self._last = now
            if self._step == MAX_EVENTS:
                self._state = "SIMULATED_LOCAL_END_REMOTE_UNKNOWN"
                self._consumed = True
        except BaseException:
            self._stop()
            raise Block() from None

    def report(self) -> dict[str, object]:
        """Never reveal opaque witness tokens, real receipt or execution GO."""
        return {
            "verdict": "HOLD",
            "code": ("OFFLINE_SEQUENCE_VALID_REMOTE_TERMINATION_UNVERIFIED"
                     if self._state == "SIMULATED_LOCAL_END_REMOTE_UNKNOWN"
                     else "SYNTHETIC_SESSION_INCOMPLETE_OR_BLOCKED"),
            "scope": "OLD_LIVE_94_ONLY",
            "source_sha": LIVE_SHA,
            "migration_tree": LIVE_MIGRATION_TREE,
            "steps_observed": self._step,
            "remote_termination_verified": False,
            "real_aiven_connected": False,
            "real_target_authenticated": False,
            "effective_privileges_observed": False,
            "schema_94_verified": False,
            "owner_go": False,
            "production_go": False,
        }
