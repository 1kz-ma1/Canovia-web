"""No-GO synthesis of forged/offline receipts; no live environment required."""
import ast
from pathlib import Path
import sys
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / 'scripts/ci'))
from e858_phase1 import private_acceptance as acceptance
from e858_phase1.private_binding import Block

CANARY = 'SYNTHETIC_PRIVATE_CANARY_MUST_NOT_APPEAR'


def fake(name):
    value = dict(acceptance.REQUIRED[name])
    if name == 'acl':
        value['result'] = 'ACL_RECORD_ENOENT_UNVERIFIED'
    return value


class OwnerReadinessHold(unittest.TestCase):
    def test_missing_everything_is_hold(self):
        r = acceptance.review_inert_receipts({})
        self.assertEqual(r['result'], 'OWNER_ADOPTION_HOLD')
        self.assertEqual(set(r['pending_gates']), set(acceptance.GATES))
        self.assertEqual(r['required_design_decision'],
                         'A_OR_B_OWNER_SELECTION_PENDING')
        self.assertTrue(all(x == 'NOT_SUPPLIED' for x in r['observations'].values()))
        self.assertFalse(r['execution_allowed'])
        self.assertFalse(r['production_go'])
        self.assertFalse(r['real_aiven_connected'])

    def test_all_synthetic_shapes_never_clear_any_real_gate(self):
        supplied = {k: fake(k) for k in acceptance.REQUIRED}
        observed = acceptance.review_inert_receipts(supplied)
        self.assertEqual(len(observed['pending_gates']), 10)
        self.assertTrue(all(x == 'UNTRUSTED_RECEIPT_SHAPE_ONLY'
                            for x in observed['observations'].values()))
        self.assertFalse(observed['runtime_complete'])
        self.assertFalse(observed['live_tooling_adopted'])
        self.assertEqual(observed['owner_authority'], 'UNVERIFIED')
        self.assertEqual(observed['durable_one_shot'], 'NOT_IMPLEMENTED')

    def test_acl_enoent_and_zero_entries_both_remain_hold(self):
        for value in acceptance.ACL_RESULTS:
            with self.subTest(value=value):
                item = fake('acl')
                item['result'] = value
                result = acceptance.review_inert_receipts({'acl': item})
                self.assertEqual(result['result'], 'OWNER_ADOPTION_HOLD')
                self.assertIn('NATIVE_ACL_OWNER_ACCEPTANCE', result['pending_gates'])

    def test_forged_go_or_identity_true_is_rejected(self):
        for key in ('root', 'acl', 'tty', 'native_runtime', 'wheel', 'archive'):
            v = fake(key)
            v['execution_allowed'] = True
            with self.subTest(receipt=key):
                with self.assertRaises(Block):
                    acceptance.review_inert_receipts({key: v})
        tty = fake('tty')
        tty['operator_identity'] = 'VERIFIED'
        with self.assertRaises(Block):
            acceptance.review_inert_receipts({'tty': tty})
        wheel = fake('wheel')
        wheel['runtime_complete'] = 1  # int 1 is not a boolean False
        with self.assertRaises(Block):
            acceptance.review_inert_receipts({'wheel': wheel})

    def test_unknown_or_malformed_top_level_input_is_blocked(self):
        for bad in (None, True, (), [], '', {CANARY: {}},
                    {key: fake(key) for key in acceptance.REQUIRED} | {CANARY: {}}):
            with self.subTest(typ=type(bad).__name__):
                with self.assertRaises(Block):
                    acceptance.review_inert_receipts(bad)

    def test_arbitrary_private_values_never_reach_output(self):
        item = fake('native_runtime')
        item['secret'] = CANARY
        item['path'] = '/synthetic/' + CANARY
        report = acceptance.review_inert_receipts({'native_runtime': item})
        self.assertNotIn(CANARY, str(report))
        self.assertNotIn('/synthetic/', str(report))
        self.assertEqual(report['receipt_authenticity'], 'UNVERIFIED')

    def test_unexpected_acl_claims_and_missing_fixed_keys_are_rejected(self):
        item = fake('acl')
        item['result'] = 'REAL_ACL_TRUSTED'
        with self.assertRaises(Block):
            acceptance.review_inert_receipts({'acl': item})
        item = fake('root')
        item.pop('native_acl')
        with self.assertRaises(Block):
            acceptance.review_inert_receipts({'root': item})

    def test_no_live_or_authorization_capabilities_are_imported(self):
        path = ROOT / 'scripts/ci/e858_phase1/private_acceptance.py'
        tree = ast.parse(path.read_text())
        imports = [node.module for node in ast.walk(tree)
                   if isinstance(node, ast.ImportFrom)]
        self.assertEqual(imports, ['private_binding'])
        banned = ('socket', 'subprocess', 'os', 'ssl', 'sqlite3',
                  'requests', 'pymysql', 'termios', 'getpass')
        self.assertFalse(any(isinstance(n, ast.Import)
                             and any(a.name in banned for a in n.names)
                             for n in ast.walk(tree)))
        for name in ('connect', 'authorize', 'issue_token',
                     'consume_nonce', 'owner_go', 'run', 'main'):
            self.assertFalse(hasattr(acceptance, name))


if __name__ == '__main__':
    unittest.main()
