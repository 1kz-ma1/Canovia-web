"""B2 local SQLite experiment: synthetic-only consumption, never authentic GO."""
import copy
import multiprocessing
import os
from pathlib import Path
import shutil
import socket
import sqlite3
import sys
import tempfile
import unittest
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[2] / 'scripts/ci'))
from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PrivateKey
from cryptography.hazmat.primitives.serialization import Encoding, PublicFormat
from e858_b_offline import SOURCE, RELEASE, canonical
from e858_phase1 import b1_package
from e858_phase1 import b2_signature_fixture as b2
from e858_phase1 import b2_synthetic_consumption as consumption
from e858_phase1.private_binding import Block


def _racer(records, binding, public_key, when, filename, queue):
    try:
        consumption.consume_synthetic_once(records, binding, public_key, when, filename)
        queue.put('COMMITTED')
    except Block:
        queue.put('BLOCKED')


def _commit_then_exit(records, binding, public_key, when, filename):
    consumption.consume_synthetic_once(records, binding, public_key, when, filename)
    os._exit(0)


class SyntheticSQLiteConsumption(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory(prefix='b2-synthetic-only-')
        self.addCleanup(self.tmp.cleanup)
        self.path = str(Path(self.tmp.name) / 'consumed.sqlite')
        self.key = Ed25519PrivateKey.generate()
        self.public = self.key.public_key().public_bytes(Encoding.Raw, PublicFormat.Raw)
        self.now = 1800000000
        self.binding = dict(
            source_sha=SOURCE, release_ref=RELEASE, tooling_sha='a'*40,
            bundle_sha256='b'*64, sql_sha256='c'*64,
            reference_sha256='d'*64, binding_sha256='e'*64,
            runtime_sha256='f'*64, target_ca_sha256='1'*64,
            scope='phase1', engine='8.4', attempt_id='2'*32,
        )

    def records(self, binding=None, changes=None):
        records = []
        for step in b2.STEPS:
            claim = dict(kind=b2.KIND, step=step,
                         binding=copy.deepcopy(binding or self.binding),
                         issued_at=self.now-60, expires_at=self.now+60,
                         issuer=b2.ISSUER, operator=b2.OPERATOR)
            if changes:
                claim.update(changes)
            raw = canonical(claim)
            records.append((raw, self.key.sign(raw)))
        return records

    def consume(self, records=None, binding=None, path=None, now=None):
        return consumption.consume_synthetic_once(
            self.records() if records is None else records,
            self.binding if binding is None else binding,
            self.public, self.now if now is None else now,
            self.path if path is None else path)

    def test_commit_persists_across_reopen_but_returns_only_not_go(self):
        result = self.consume()
        self.assertTrue(result['local_sqlite_commit_observed'])
        for field in ('rollback_resistance_verified', 'trusted_store_verified',
                      'independent_owner_authority_verified', 'operator_authentication_verified',
                      'durable_one_shot_verified', 'execution_allowed',
                      'real_aiven_connected', 'production_go'):
            self.assertIs(result[field], False)
        with self.assertRaises(Block): self.consume()
        with sqlite3.connect(self.path) as db:
            self.assertEqual(db.execute(
                'SELECT COUNT(*) FROM synthetic_consumed_attempts').fetchone()[0], 1)
        with self.assertRaises(Block): consumption.authorize(result)
        self.assertNotIn('scripts/ci/e858_phase1/b2_synthetic_consumption.py',
                         b1_package.FILES)

    def test_atomic_competition_across_distinct_spawned_processes(self):
        ctx = multiprocessing.get_context('spawn')
        queue = ctx.Queue()
        records = self.records()
        children = [ctx.Process(target=_racer, args=(
            records, self.binding, self.public, self.now, self.path, queue))
            for _ in range(4)]
        for child in children: child.start()
        for child in children:
            child.join(20)
            self.assertEqual(child.exitcode, 0)
        outcomes = [queue.get(timeout=3) for _ in children]
        self.assertEqual(outcomes.count('COMMITTED'), 1)
        self.assertEqual(outcomes.count('BLOCKED'), 3)

    def test_crash_after_commit_cannot_reconsume_same_ledger(self):
        ctx = multiprocessing.get_context('spawn')
        child = ctx.Process(target=_commit_then_exit, args=(
            self.records(), self.binding, self.public, self.now, self.path))
        child.start()
        child.join(20)
        self.assertEqual(child.exitcode, 0)
        with self.assertRaises(Block): self.consume()

    def test_invalid_signatures_expiry_scope_and_path_do_not_create_ledger(self):
        invalid = [
            self.records(changes={'b2_go': True}),
            self.records(changes={'expires_at': self.now}),
            self.records()[:-1],
        ]
        for records in invalid:
            with self.assertRaises(Block): self.consume(records=records)
            self.assertFalse(os.path.exists(self.path))
        other = dict(self.binding, attempt_id='3'*32)
        with self.assertRaises(Block): self.consume(records=self.records(),
                                                   binding=other)
        with self.assertRaises(Block): self.consume(path='relative.sqlite')
        self.assertFalse(os.path.exists(self.path))
        # A symlink to some other file cannot be passed as the ledger pathname.
        symlink = str(Path(self.tmp.name) / 'alias')
        os.symlink(self.path, symlink)
        with self.assertRaises(Block): self.consume(path=symlink)
        self.assertFalse(os.path.exists(self.path))

    def test_attempt_id_is_unique_even_with_freshly_signed_payload(self):
        self.consume()
        # A new signature window is not a second permission for an old attempt ID.
        with self.assertRaises(Block):
            self.consume(records=self.records(changes={
                'issued_at': self.now-40, 'expires_at': self.now+40}))
        new = dict(self.binding, attempt_id='4'*32)
        second = self.consume(records=self.records(binding=new), binding=new)
        self.assertTrue(second['local_sqlite_commit_observed'])

    def test_rollback_restore_proves_local_store_is_not_trust_anchor(self):
        self.consume()
        snapshot = str(Path(self.tmp.name) / 'snapshot.sqlite')
        shutil.copyfile(self.path, snapshot)
        new = dict(self.binding, attempt_id='5'*32)
        changed = self.records(binding=new)
        self.consume(records=changed, binding=new)
        with self.assertRaises(Block): self.consume(records=changed, binding=new)
        # A privileged restorer can erase the consumption of '5'. This is the
        # exact limitation: SQLite atomicity is NOT rollback-resistant one-shot.
        shutil.copyfile(snapshot, self.path)
        self.assertTrue(self.consume(records=changed, binding=new)[
            'local_sqlite_commit_observed'])

    def test_no_actual_entrypoint_or_network_from_fixture(self):
        with patch('socket.getaddrinfo') as dns, patch('socket.socket') as sock:
            self.consume()
            with self.assertRaises(Block): consumption.authorize(self.records())
            with self.assertRaises(Block): b2.authorize(self.records(), self.public)
            dns.assert_not_called()
            sock.assert_not_called()


if __name__ == '__main__':
    unittest.main()
