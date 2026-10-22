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
    if name == 'b1_offline':
        value['synthetic_case'] = 'NOT_RUN'
    return value


class OwnerReadinessHold(unittest.TestCase):
    def test_missing_everything_is_hold(self):
        r = acceptance.review_inert_receipts({})
        self.assertEqual(r['result'], 'OWNER_ADOPTION_HOLD')
        self.assertEqual(set(r['pending_gates']), set(acceptance.GATES))
        self.assertEqual(r['current_design'],
                         'B_OWNER_SUPERVISED_SINGLE_METADATA_AUDIT_ADOPTED')
        self.assertEqual(r['future_design'],
                         'A_INDEPENDENT_SIGNING_AND_DURABLE_NONCE_DEFERRED')
        self.assertNotIn('required_design_decision', r)
        self.assertEqual(r['b_stages']['B0'], 'DESIGN_BASELINE_ADOPTED_2026_10_20')
        self.assertEqual(r['b_stages']['B2'], 'FRESH_OWNER_GO_NOT_GRANTED')
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
        self.assertEqual(observed['owner_authority'], 'B2_FRESH_GO_UNVERIFIED')
        self.assertEqual(observed['durable_one_shot'], 'FUTURE_A_ONLY_NOT_B_PREREQUISITE')
        self.assertEqual(observed['retry_control'], 'HUMAN_PROCEDURAL_NEW_GO_REQUIRED')

    def test_b1_synthetic_receipt_never_becomes_b2_owner_go(self):
        for simulated in acceptance.B1_CASES:
            item = fake('b1_offline')
            item['synthetic_case'] = simulated
            result = acceptance.review_inert_receipts({'b1_offline': item})
            self.assertEqual(result['observations']['b1_offline'],
                             'UNTRUSTED_RECEIPT_SHAPE_ONLY')
            self.assertEqual(result['b_stages']['B1'],
                             'OFFLINE_PACKET_IMPLEMENTED_PRIVATE_ACCEPTANCE_PENDING')
            self.assertEqual(result['b_stages']['B3'], 'REAL_AIVEN_AUDIT_NOT_RUN')
            self.assertFalse(result['execution_allowed'])
        for forged in ('REAL_OWNER_APPROVED', None, True):
            item = fake('b1_offline')
            item['synthetic_case'] = forged
            with self.assertRaises(Block):
                acceptance.review_inert_receipts({'b1_offline': item})

    def test_a_authority_is_future_not_a_b1_or_b2_gate(self):
        result = acceptance.review_inert_receipts({})
        joined = ' '.join(result['pending_gates'])
        self.assertNotIn('INDEPENDENT_APPROVAL_AUTHORITY', joined)
        self.assertNotIn('DURABLE_ONE_SHOT_AND_REVOCATION', joined)
        self.assertEqual(result['future_design'],
                         'A_INDEPENDENT_SIGNING_AND_DURABLE_NONCE_DEFERRED')
        self.assertIn('B_PROCEDURAL_SINGLE_ATTEMPT_AND_NEW_GO_FOR_RETRY',
                      result['pending_gates'])
        self.assertIn('B2_FRESH_OWNER_SINGLE_ATTEMPT_GO',
                      result['pending_gates'])

    def test_acl_enoent_and_zero_entries_both_remain_hold(self):
        for value in acceptance.ACL_RESULTS:
            with self.subTest(value=value):
                item = fake('acl')
                item['result'] = value
                result = acceptance.review_inert_receipts({'acl': item})
                self.assertEqual(result['result'], 'OWNER_ADOPTION_HOLD')
                self.assertIn('NATIVE_ACL_OWNER_ACCEPTANCE', result['pending_gates'])

    def test_forged_go_or_identity_true_is_rejected(self):
        for key in ('root', 'acl', 'tty', 'native_runtime', 'wheel', 'archive', 'b1_offline'):
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
