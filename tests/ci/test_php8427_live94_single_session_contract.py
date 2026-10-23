"""Owned synthetic Live94 contract tests. No DB client, network or real input."""
from dataclasses import replace
from pathlib import Path
import json
import sys
import unittest

sys.path.insert(0, str(Path(__file__).resolve().parents[2]))

from scripts.ci.php8427_live94_single_session_contract import (
    Block, Live94Contract, PHASES, SCOPE, SyntheticWitness,
)
from scripts.ci.php8427_live94_offline_source_gate import LIVE_SHA, LIVE_MIGRATION_TREE


class Clock:
    def __init__(self):
        self.t = 10.0

    def __call__(self):
        return self.t

    def step(self, n=0.5):
        self.t += n


def witness(phase: str) -> SyntheticWitness:
    b = dict(phase=phase, session="1" * 64, server="2" * 64,
             principal="3" * 64, tls_identity_verified=True)
    if phase in ("identity", "identity_recheck"):
        b.update(byte_count=120, row_count=1)
    if phase == "grants":
        b.update(byte_count=800, row_count=3, effective_scope=SCOPE,
                 active_and_mandatory_roles_absent=True)
    if phase == "inventory":
        b.update(byte_count=2048, row_count=60, inventory_complete=True)
    if phase == "ledger":
        b.update(byte_count=4096, row_count=94)
    if phase == "local_cleanup":
        b.update(local_cleanup_verified=True)
    return SyntheticWitness(**b)


def contract():
    clock = Clock()
    return Live94Contract(source_sha=LIVE_SHA, migration_tree=LIVE_MIGRATION_TREE,
                          clock=clock), clock


def advance(c, t, phase, **changes):
    t.step()
    c.observe_synthetic(replace(witness(phase), **changes))


class Live94SyntheticContractTest(unittest.TestCase):
    def test_complete_single_session_is_still_hold_remote_unknown(self):
        c, t = contract()
        for phase in PHASES:
            advance(c, t, phase)
        r = c.report()
        self.assertEqual(r["code"], "OFFLINE_SEQUENCE_VALID_REMOTE_TERMINATION_UNVERIFIED")
        self.assertEqual(r["verdict"], "HOLD")
        self.assertEqual(r["steps_observed"], 7)
        for k in ("real_aiven_connected", "real_target_authenticated",
                  "remote_termination_verified", "schema_94_verified",
                  "effective_privileges_observed", "owner_go", "production_go"):
            self.assertIs(r[k], False)
        self.assertNotIn("1" * 64, json.dumps(r))
        self.assertNotIn("2" * 64, json.dumps(r))
        self.assertNotIn("3" * 64, json.dumps(r))
        with self.assertRaises(Block):
            advance(c, t, "identity")

    def test_wrong_frozen_source_never_builds_contract(self):
        for sha, tree in (("0" * 40, LIVE_MIGRATION_TREE),
                          (LIVE_SHA, "f" * 40)):
            with self.assertRaises(Block):
                Live94Contract(source_sha=sha, migration_tree=tree)

    def test_no_runtime_activation_or_sql_is_possible(self):
        for method in ("connect", "execute"):
            c, _ = contract()
            with self.assertRaises(Block):
                getattr(c, method)("CANARY_SECRET", sql="DROP TABLE users")
            self.assertEqual(c.report()["verdict"], "HOLD")
            self.assertEqual(c.report()["steps_observed"], 0)
            self.assertNotIn("CANARY_SECRET", json.dumps(c.report()))

    def test_out_of_order_and_replay_stop_fails_closed(self):
        for first in ("grants", "ledger", "local_quit", "local_cleanup"):
            c, t = contract()
            with self.assertRaises(Block):
                advance(c, t, first)
            with self.assertRaises(Block):
                advance(c, t, "identity")
            self.assertEqual(c.report()["code"], "SYNTHETIC_SESSION_INCOMPLETE_OR_BLOCKED")
        c, t = contract()
        advance(c, t, "identity")
        with self.assertRaises(Block):
            advance(c, t, "identity")
        with self.assertRaises(Block):
            advance(c, t, "grants")

    def test_identity_continuity_and_tls_negative_cases(self):
        for diff in ({"server": "a" * 64}, {"principal": "b" * 64},
                     {"session": "c" * 64}, {"tls_identity_verified": False},
                     {"server": "not_a_hash"}, {"principal": ""}):
            c, t = contract()
            advance(c, t, "identity")
            with self.subTest(change=diff), self.assertRaises(Block):
                advance(c, t, "grants", **diff)
            self.assertIs(c.report()["production_go"], False)

    def test_roles_and_excess_privileges_are_rejected(self):
        for diff in ({"effective_scope": "ADMIN"},
                     {"effective_scope": ""},
                     {"active_and_mandatory_roles_absent": False},
                     {"active_and_mandatory_roles_absent": 1}):
            c, t = contract()
            advance(c, t, "identity")
            with self.subTest(change=diff), self.assertRaises(Block):
                advance(c, t, "grants", **diff)

    def test_incomplete_metadata_and_not_exactly_94_ledger_fail(self):
        for diff in ({"inventory_complete": False},
                     {"byte_count": 32769}, {"row_count": 0},
                     {"row_count": 513}, {"byte_count": True}):
            c, t = contract()
            advance(c, t, "identity")
            advance(c, t, "grants")
            with self.subTest(change=diff), self.assertRaises(Block):
                advance(c, t, "inventory", **diff)
        for applied in (0, 93, 95, 4001, True):
            c, t = contract()
            for phase in PHASES[:3]:
                advance(c, t, phase)
            with self.subTest(applied=applied), self.assertRaises(Block):
                advance(c, t, "ledger", row_count=applied)

    def test_mismatch_at_last_identity_and_unknown_cleanup_stop(self):
        c, t = contract()
        for phase in PHASES[:4]:
            advance(c, t, phase)
        with self.assertRaises(Block):
            advance(c, t, "identity_recheck", server="e" * 64)
        c, t = contract()
        for phase in PHASES[:-1]:
            advance(c, t, phase)
        with self.assertRaises(Block):
            advance(c, t, "local_cleanup", local_cleanup_verified=False)
        self.assertFalse(c.report()["remote_termination_verified"])

    def test_deadline_and_non_monotonic_mock_clock_stop(self):
        c, t = contract()
        t.step(10.0)
        with self.assertRaises(Block):
            c.observe_synthetic(witness("identity"))
        self.assertEqual(c.report()["steps_observed"], 0)
        c, t = contract()
        advance(c, t, "identity")
        t.step(-5)
        with self.assertRaises(Block):
            c.observe_synthetic(witness("grants"))

    def test_bad_mock_inputs_and_nonfinite_clock_fail(self):
        c, t = contract()
        t.step()
        with self.assertRaises(Block):
            c.observe_synthetic({"phase": "identity"})
        c, t = contract()
        t.t = float("nan")
        with self.assertRaises(Block):
            c.observe_synthetic(witness("identity"))
        c, t = contract()
        t.step()
        with self.assertRaises(Block):
            c.observe_synthetic(replace(witness("identity"), tls_identity_verified=1))
        self.assertEqual(c.report()["verdict"], "HOLD")


if __name__ == "__main__":
    unittest.main()
