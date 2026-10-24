"""B2 operator-facing OFFLINE rehearsal: self-reported reading is never GO."""
import copy
from pathlib import Path
import socket
import sys
import unittest
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[2] / 'scripts/ci'))
from e858_b_offline import SOURCE, RELEASE
from e858_phase1 import b1_package
from e858_phase1 import b2_owner_briefing as brief
from e858_phase1 import launcher_candidate, opener_candidate, practical
from e858_phase1.private_binding import Block


class B2OwnerBriefingTest(unittest.TestCase):
    def test_complete_eight_step_public_briefing_always_pending(self):
        packet = brief.template()
        self.assertEqual(packet['source_sha'], SOURCE)
        self.assertEqual(packet['release_ref'], RELEASE)
        self.assertEqual([i['step'] for i in packet['items']], list(brief.STEPS))
        self.assertEqual([i['state'] for i in packet['items']], ['PENDING'] * 8)
        status = brief.assess(packet)
        self.assertEqual(status['pending_steps'], list(brief.STEPS))
        self.assertEqual(status['declined_steps'], [])
        self.assertFalse(status['execution_allowed'])
        self.assertFalse(status['authentic_owner_approval_verified'])
        with self.assertRaises(Block):
            brief.authorize(packet)
        output = brief.render_read_only()
        self.assertIn('PRODUCTION_NO_GO_HOLD', output)
        self.assertIn('REAL_AIVEN_NOT_RUN', output)
        for step in brief.STEPS:
            self.assertIn('## ' + step + ' — ', output)

    def test_even_all_self_reported_read_is_not_approval(self):
        packet = brief.template()
        for item in packet['items']:
            item['state'] = 'SELF_REPORTED_READ_NOT_VERIFIED'
        result = brief.assess(packet)
        self.assertEqual(result['self_reported_read_steps'], list(brief.STEPS))
        self.assertFalse(result['authentic_owner_approval_verified'])
        self.assertFalse(result['operator_authentication_verified'])
        self.assertFalse(result['fresh_owner_go_granted'])
        self.assertFalse(result['independent_key_authority_verified'])
        self.assertFalse(result['durable_one_shot_verified'])
        self.assertFalse(result['execution_allowed'])
        with self.assertRaises(Block): brief.authorize(result)

    def test_decline_never_becomes_retry_or_fresh_go(self):
        packet = brief.template()
        packet['items'][1]['state'] = 'DECLINED'
        packet['items'][7]['state'] = 'SELF_REPORTED_READ_NOT_VERIFIED'
        result = brief.assess(packet)
        self.assertEqual(result['declined_steps'], ['R1'])
        self.assertEqual(result['pending_steps'], ['P1'] + list(brief.STEPS[2:7]))
        # The exact PENDING ordering is always P1,R1,...,B2.
        self.assertFalse(result['execution_allowed'])

    def test_spoofed_approval_tamper_and_unrecognized_fields_block(self):
        original = brief.template()
        variants = []
        for field, value in [('b2_fresh_owner_go','GRANTED'),
                             ('execution_allowed',True),
                             ('mode','A_AUTOMATIC'),
                             ('scope','full17'),
                             ('source_sha','0'*40),
                             ('release_ref','main')]:
            obj = copy.deepcopy(original)
            obj[field] = value
            variants.append(obj)
        obj = copy.deepcopy(original)
        obj['owner_authenticated'] = True
        variants.append(obj)
        obj = copy.deepcopy(original)
        obj['items'][0]['state'] = 'APPROVED'
        variants.append(obj)
        obj = copy.deepcopy(original)
        obj['items'][1]['title'] = 'Not a reviewed risk'
        variants.append(obj)
        obj = copy.deepcopy(original)
        obj['items'][2]['meaning'] += ' signed'
        variants.append(obj)
        obj = copy.deepcopy(original)
        obj['items'][3]['step'] = 'B2'
        variants.append(obj)
        obj = copy.deepcopy(original)
        obj['items'][4]['unknown'] = 1
        variants.append(obj)
        obj = copy.deepcopy(original)
        obj['items'] = list(reversed(obj['items']))
        variants.append(obj)
        obj = copy.deepcopy(original)
        obj['items'] = obj['items'][:-1]
        variants.append(obj)
        obj = copy.deepcopy(original)
        obj['items'] = obj['items'] + [obj['items'][0]]
        variants.append(obj)
        obj = copy.deepcopy(original)
        obj['items'][5]['state'] = True
        variants.append(obj)
        for i, packet in enumerate(variants):
            with self.subTest(case=i):
                with self.assertRaises(Block): brief.assess(packet)
        with self.assertRaises(Block): brief.assess(None)

    def test_cli_rejects_other_modes_without_printing_private_values(self):
        self.assertEqual(brief.main(['--print-public-briefing']), 0)
        for args in ([], ['--connect'], ['--print-public-briefing', '--approve'],
                     ['--print-public-briefing', '--target', 'private.example.test']):
            with self.subTest(args=args), patch('builtins.print') as printed:
                self.assertEqual(brief.main(args), 2)
                printed.assert_not_called()

    def test_no_network_and_all_live_entrypoints_still_block(self):
        self.assertNotIn('scripts/ci/e858_phase1/b2_owner_briefing.py',
                         b1_package.FILES)
        with patch('socket.getaddrinfo') as dns, patch('socket.socket') as sock:
            brief.assess(brief.template())
            brief.render_read_only()
            with self.assertRaises(Block): brief.authorize(brief.template())
            with self.assertRaises(Block):
                object.__new__(launcher_candidate.LauncherCandidate).run()
            with self.assertRaises(Block):
                object.__new__(opener_candidate.OpenerCandidate).open()
            with self.assertRaises(Block):
                object.__new__(practical.Adapter).prepare()
            with self.assertRaises(Block):
                object.__new__(practical.PrivateWire).connect()
            dns.assert_not_called()
            sock.assert_not_called()


if __name__ == '__main__':
    unittest.main()
