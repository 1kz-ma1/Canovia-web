"""Rejected objects are never cleanup callbacks. UNIX socket pairs only."""
from pathlib import Path
import socket
import sys
import unittest

sys.path.insert(0, str(Path(__file__).resolve().parents[2] / 'scripts/ci'))
from e858_phase1.connection_handoff_candidate import ConnectedPeer
from e858_phase1.opener_candidate import OpenerCandidate
from e858_phase1.producer_candidate import ProducerBinding
from e858_phase1.private_binding import Block


class Rejected:
    def __init__(self): self.calls = 0
    def close(self): self.calls += 1
    def fileno(self): self.calls += 1; return -1


class SocketOwnership(unittest.TestCase):
    def stopped_producer(self):
        value = object.__new__(ProducerBinding)
        value.state = 'STOPPED'; value.procedure = None
        return value

    def test_constructor_rejects_unknown_object_without_calling_close(self):
        rejected = Rejected()
        with self.assertRaises(Block): ConnectedPeer(None, rejected)
        self.assertEqual(rejected.calls, 0)

    def test_invalid_plan_does_not_take_callers_socket(self):
        raw, other = socket.socketpair()
        with raw, other:
            with self.assertRaises(Block): ConnectedPeer(None, raw)
            self.assertGreaterEqual(raw.fileno(), 0)

    def test_accepted_types_cleanup_owned_socket_after_initialization_failure(self):
        raw, other = socket.socketpair()
        try:
            with self.assertRaises(Block): ConnectedPeer(object.__new__(OpenerCandidate), raw)
            self.assertEqual(raw.fileno(), -1)
            self.assertGreaterEqual(other.fileno(), 0)
        finally: raw.close(); other.close()

    def test_stopped_producer_never_calls_rejected_input(self):
        rejected = Rejected()
        with self.assertRaises(Block): self.stopped_producer().take_socket(rejected)
        self.assertEqual(rejected.calls, 0)

    def test_producer_keeps_caller_ownership_until_handoff_accepts(self):
        raw, other = socket.socketpair()
        try:
            with self.assertRaises(Block): self.stopped_producer().take_socket(raw)
            self.assertGreaterEqual(raw.fileno(), 0)
            self.assertGreaterEqual(other.fileno(), 0)
        finally: raw.close(); other.close()

    def test_cleanup_uses_original_socket_not_replaced_attribute(self):
        raw, other = socket.socketpair(); rejected = Rejected()
        peer = object.__new__(ConnectedPeer)
        peer.raw = rejected; peer._owned_raw = raw; peer.plan = None; peer.used = False
        try:
            with self.assertRaises(Block): peer.take()
            peer.close(); peer.close()
            self.assertEqual(rejected.calls, 0)
            self.assertEqual(raw.fileno(), -1)
            self.assertGreaterEqual(other.fileno(), 0)
        finally: raw.close(); other.close()


if __name__ == '__main__': unittest.main()
