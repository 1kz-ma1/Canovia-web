import sqlite3
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / 'scripts/ci'))
sys.path.insert(0, str(ROOT / 'scripts/ops'))
from platform_release_preflight import cutover_order, CUTOVER_ORDER
import preparation_cutover_rehearsal as rehearsal
from preparation_mac_lab import stop_server, SafetyError


class CutoverRehearsalTest(unittest.TestCase):
    def test_complete_order_is_only_a_source_plan_never_execution_permission(self):
        result = cutover_order(list(CUTOVER_ORDER))
        self.assertEqual(result['state'], 'VERIFIED_SOURCE')
        self.assertFalse(result['release_authorized'])
        self.assertIn('NOT_OBSERVATION', result['scope'])

    def test_missing_reordered_or_repeated_stage_cannot_pass(self):
        for i in range(len(CUTOVER_ORDER)):
            self.assertNotEqual(cutover_order(list(CUTOVER_ORDER[:i] + CUTOVER_ORDER[i+1:]))['state'], 'VERIFIED_SOURCE')
        self.assertEqual(cutover_order(['RECOVERY_PRECONDITIONS', 'SOURCE_AND_CONFIG'])['state'], 'BLOCKING')
        self.assertEqual(cutover_order(list(CUTOVER_ORDER[:8]) + ['MIGRATION_ONCE'])['state'], 'BLOCKING')

    def test_stop_unknown_remote_and_retry_are_terminal(self):
        for i in range(len(CUTOVER_ORDER)):
            trace = list(CUTOVER_ORDER[:i]) + ['STOP', CUTOVER_ORDER[i]]
            self.assertEqual(cutover_order(trace)['state'], 'BLOCKING')
        self.assertEqual(cutover_order(['SOURCE_AND_CONFIG', 'UNKNOWN_REMOTE'])['state'], 'BLOCKING')

    def test_approval_shaped_values_never_supply_observations(self):
        for value in [True, {'approved': True}, ['SOURCE_AND_CONFIG', {'approved': True}], ['SOURCE_AND_CONFIG'] * 40]:
            self.assertEqual(cutover_order(value)['state'], 'BLOCKING')

    def test_owned_transaction_is_observably_busy_then_released_without_row_loss(self):
        with tempfile.TemporaryDirectory(prefix='canovia-preparation-mac-') as directory:
            database = Path(directory) / 'synthetic.sqlite'
            with sqlite3.connect(database) as db:
                db.execute('CREATE TABLE plans (id INTEGER PRIMARY KEY, description TEXT)')
                db.execute("INSERT INTO plans VALUES (1,'synthetic-preserve')")
            processes = []
            child = rehearsal.actor(database, 'inflight', processes)
            try:
                self.assertFalse(rehearsal.lock_free(database))
                rehearsal.finish(child)
                self.assertTrue(rehearsal.lock_free(database))
                with sqlite3.connect(database) as db:
                    self.assertEqual(db.execute('SELECT description FROM plans').fetchone()[0], 'synthetic-preserve')
                self.assertEqual(child.returncode, 0)
            finally:
                stop_server(child)

    def test_unknown_observation_and_invalid_actor_fail_before_launch(self):
        with self.assertRaises(SafetyError):
            rehearsal.require(None, 'CUTOVER_UNOBSERVABLE')
        processes = []
        with self.assertRaises(SafetyError):
            rehearsal.actor(Path('/not-an-accepted-target'), 'real', processes)
        self.assertEqual(processes, [])


if __name__ == '__main__':
    unittest.main()
