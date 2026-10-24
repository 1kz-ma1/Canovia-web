"""Actual admission checks for owned Phase1 seam; no real target or network."""
import contextlib
import io
import os
import types
import unittest
import time
from unittest.mock import patch

import test_e858_practical as fixture
from e858_phase1.owned_activation_fixture import OwnedPhase1Activation

p = fixture.p
Block = fixture.binding.Block
CANARY = fixture.CANARY


class OwnedActivationContract(unittest.TestCase):
    setUpClass = classmethod(fixture.Practical.setUpClass.__func__)
    setUp = fixture.Practical.setUp
    write = fixture.Practical.write
    load = fixture.Practical.load

    def trial(self, fault='NORMAL'):
        # The generic Practical fixture intentionally uses a non-owned
        # private target. This admission suite uses ONLY the explicitly
        # allowed owned Docker name and preserves its independent binding.
        self.record['target'].update(
            service='ONE_SYNTHETIC_SERVICE', database='canovia_p0_ci',
            host='mysql.synthetic.invalid', port=3306,
            principal='e858_review_inspector')
        self.record['tls']['san'] = 'mysql.synthetic.invalid'
        self.review['binding_sha256'] = p.dist._sha(fixture.old.canonical(self.record))
        self.write()
        if fault == 'METADATA':
            self.review['scope'] = 'metadata'
            self.write()

        def child(_, parent):
            if os.getppid() != parent:
                raise Block()
            review = self.load()
            entry = p.ReviewedEntry()
            activation = None
            output, errors = io.StringIO(), io.StringIO()
            try:
                entry.begin(str(self.private), human_supervised=True)
                entry.bind_review(review)
                props = dict(
                    kind='OWNED_INTERNAL_DOCKER_ONLY',
                    owned_ips=['192.0.2.1'],
                    pins={'tooling_sha': self.head},
                    bundle_sha256=self.program.sha,
                )
                if fault == 'NAMESPACE':
                    props['kind'] = 'ACTUAL_AIVEN'
                if fault == 'EMPTY_IP':
                    props['owned_ips'] = []
                if fault == 'WRONG_TOOLING':
                    props['pins'] = {'tooling_sha': 'f' * 40}
                if fault == 'WRONG_BUNDLE':
                    props['bundle_sha256'] = '0' * 64

                with contextlib.redirect_stdout(output), contextlib.redirect_stderr(errors), \
                        patch('socket.socket') as sock, patch('socket.getaddrinfo') as dns, \
                        patch.dict(os.environ, {'E858_LIVE': '1', 'B2_GO': 'true'}):
                    if fault in ('NAMESPACE', 'EMPTY_IP', 'WRONG_TOOLING', 'WRONG_BUNDLE', 'METADATA'):
                        with self.assertRaises(Block):
                            OwnedPhase1Activation(entry, props)
                    else:
                        activation = OwnedPhase1Activation(entry, props)
                        self.assertEqual(activation.state, 'PRE_INPUT')
                        if fault == 'EXPIRED':
                            entry.until = time.monotonic() - 1
                            with self.assertRaises(Block):
                                activation.before_input()
                        else:
                            activation.before_input()
                            if fault == 'REINPUT':
                                with self.assertRaises(Block):
                                    activation.before_input()
                            elif fault == 'FAKE_GATE':
                                entry.capture()
                                fake = types.SimpleNamespace(
                                    role='control', q0=self.program.queries['identity'],
                                    q1=self.program.queries['grants'], deadline=entry.until)
                                with self.assertRaises(Block):
                                    activation.before_connection(fake)
                            elif fault == 'WRONG_ORDER':
                                entry.capture()
                                with self.assertRaises(Block):
                                    activation.before_connection(
                                        p.r.Gate('data', self.program.queries['identity'],
                                                 self.program.queries['grants'], entry.until))
                            else:
                                entry.capture()
                                gate = lambda role: p.r.Gate(
                                    role, self.program.queries['identity'],
                                    self.program.queries['grants'], entry.until)
                                activation.before_connection(gate('control'))
                                activation.before_connection(gate('data'))
                                self.assertEqual(activation.state, 'DATA_STARTED')
                                if fault == 'THIRD':
                                    with self.assertRaises(Block):
                                        activation.before_connection(gate('data'))
                                else:
                                    with self.assertRaises(Block):
                                        activation.run()
                    sock.assert_not_called()
                    dns.assert_not_called()

                if activation is not None:
                    self.assertEqual(activation.state, 'STOPPED')
                    self.assertIsNone(activation.entry)
                    self.assertNotIn(CANARY, repr(activation))
                self.assertEqual(output.getvalue() + errors.getvalue(), '')
                return dict(result='OWNED_ACTIVATION_OFFLINE_ONLY', traces=[],
                            execution_allowed=False, real_aiven_connected=False)
            finally:
                if activation is not None:
                    activation.close()
                entry.close()
                review.close()

        with patch.object(fixture.tty, 'child_trial', side_effect=child):
            out = fixture.tty.trial('NORMAL')
        self.assertEqual(out['result'], 'OWNED_ACTIVATION_OFFLINE_ONLY')
        self.assertFalse(out['execution_allowed'])
        self.assertFalse(out['real_aiven_connected'])

    def test_fixed_synthetic_scope_two_ordered_roles_and_live_flags_cannot_enable_run(self):
        self.trial('NORMAL')

    def test_scope_wrong_artifact_and_namespace_reject_before_input(self):
        for fault in ('NAMESPACE', 'EMPTY_IP', 'WRONG_TOOLING', 'WRONG_BUNDLE', 'METADATA'):
            with self.subTest(fault=fault):
                self.trial(fault)

    def test_reinput_expiration_unknown_gate_out_of_order_and_third_role_are_terminal(self):
        for fault in ('EXPIRED', 'REINPUT', 'FAKE_GATE', 'WRONG_ORDER', 'THIRD'):
            with self.subTest(fault=fault):
                self.trial(fault)


if __name__ == '__main__':
    unittest.main()
