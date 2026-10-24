"""Only the new review boundary; no TTY, driver, network or historical audit replay."""
import contextlib
import hashlib
import io
import json
from pathlib import Path
import sys
import unittest
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[2] / 'scripts/ci'))
import e858_phase1_review as review


class ReviewInventory(unittest.TestCase):
    head = 'a' * 40
    canary = 'PRIVATE_CANARY_DO_NOT_LOG'

    def blobs(self, root, op, spec):
        self.assertEqual(op, 'show')
        sha, path = spec.split(':', 1)
        self.assertIn(sha, (self.head, review.EVIDENCE))
        self.assertIn(path, review.RUNTIME + review.REVIEW)
        if path in review.RUNTIME_DELTAS:
            return (Path(__file__).resolve().parents[2]/path).read_bytes()
        return ('public source ' + path).encode()

    def test_public_inventory_is_deterministic_and_never_authorizes(self):
        with patch.object(review.b, 'integrity', return_value={'tooling_sha': self.head}) as integrity, patch.object(review.b, 'git', side_effect=self.blobs):
            result = review.inventory(Path('/unused'), self.head)
            self.assertEqual(result, review.inventory(Path('/unused'), self.head))
            integrity.assert_called_with(Path('/unused'), self.head)
        self.assertEqual(len(result['public_source_sha256']), len(review.RUNTIME + review.REVIEW))
        for path, digest in result['public_source_sha256'].items():
            self.assertEqual(digest, hashlib.sha256(self.blobs(Path('/unused'),'show',self.head+':'+path)).hexdigest())
        for flag in ('execution_allowed', 'owner_approval_verified', 'real_aiven_connected', 'strict_block_waived', 'release_authorized'):
            self.assertIs(result[flag], False)
        self.assertEqual(result['live_distribution'], 'NOT_BUILT')
        self.assertEqual(result['runtime_artifact_lock'], 'UNVERIFIED')

    def test_changed_runtime_has_its_own_pin_and_no_old_device_acceptance(self):
        with patch.object(review.b, 'integrity', return_value={}), patch.object(review.b, 'git', side_effect=self.blobs):
            result=review.inventory(Path('/unused'),self.head)
            self.assertEqual(result['changed_runtime_source'],sorted(review.RUNTIME_DELTAS))
            self.assertEqual(result['reused_evidence_scope'],'UNCHANGED_RUNTIME_FILES_ONLY')
            self.assertEqual(result['changed_runtime_owner_acceptance'],'NOT_CLAIMED')
            self.assertFalse(result['execution_allowed'])
            with patch.dict(review.RUNTIME_DELTAS,{key:'0'*64 for key in review.RUNTIME_DELTAS}):
                with self.assertRaises(review.b.Block):review.inventory(Path('/unused'),self.head)

    def test_any_runtime_change_refuses_historical_evidence_reuse(self):
        for changed in review.RUNTIME:
            with self.subTest(path=changed):
                def blobs(root, op, spec):
                    raw = self.blobs(root, op, spec)
                    return raw + b'changed' if spec == self.head + ':' + changed else raw
                with patch.object(review.b, 'integrity', return_value={}), patch.object(review.b, 'git', side_effect=blobs):
                    with self.assertRaises(review.b.Block):
                        review.inventory(Path('/unused'), self.head)

    def test_integrity_failure_cannot_fall_back_to_unchecked_inventory(self):
        with patch.object(review.b, 'integrity', side_effect=review.b.Block(self.canary)), patch.object(review.b, 'git') as git:
            with self.assertRaises(review.b.Block):
                review.inventory(Path('/unused'), self.head)
            git.assert_not_called()

    def test_unknown_private_arguments_are_not_echoed_or_read(self):
        for args in ([], ['--endpoint', self.canary], ['--tooling-sha', self.head, '--password', self.canary], ['--connect'], ['--help']):
            with self.subTest(args=args), patch.object(review, 'inventory') as inventory:
                out, err = io.StringIO(), io.StringIO()
                with contextlib.redirect_stdout(out), contextlib.redirect_stderr(err):
                    self.assertEqual(review.main(args), 2)
                inventory.assert_not_called()
                self.assertNotIn(self.canary, out.getvalue() + err.getvalue())
                self.assertEqual(err.getvalue(), '')
                self.assertEqual(json.loads(out.getvalue())['code'], 'REVIEW_INVENTORY_REJECTED')

    def test_failure_interrupt_and_exception_details_never_escape(self):
        for failure in (RuntimeError(self.canary), KeyboardInterrupt(self.canary)):
            with patch.object(review, 'inventory', side_effect=failure):
                out, err = io.StringIO(), io.StringIO()
                with contextlib.redirect_stdout(out), contextlib.redirect_stderr(err):
                    self.assertEqual(review.main(['--tooling-sha', self.head]), 2)
                self.assertNotIn(self.canary, out.getvalue() + err.getvalue())
                self.assertFalse(json.loads(out.getvalue())['execution_allowed'])
                self.assertEqual(err.getvalue(), '')

    def test_review_delta_changes_index_without_disclosing_blob_contents(self):
        with patch.object(review.b, 'integrity', return_value={}), patch.object(review.b, 'git', side_effect=self.blobs):
            previous = review.inventory(Path('/unused'), self.head)
        def blobs(root, op, spec):
            raw = self.blobs(root, op, spec)
            return raw + self.canary.encode() if spec.endswith(':' + review.REVIEW[-1]) else raw
        with patch.object(review.b, 'integrity', return_value={}), patch.object(review.b, 'git', side_effect=blobs):
            current = review.inventory(Path('/unused'), self.head)
        self.assertNotEqual(previous['public_source_index_sha256'], current['public_source_index_sha256'])
        self.assertEqual(previous['reused_evidence_head'], current['reused_evidence_head'])
        self.assertNotIn(self.canary, json.dumps(current))
        self.assertEqual(len(set(review.RUNTIME + review.REVIEW)), len(review.RUNTIME + review.REVIEW))


if __name__ == '__main__':
    unittest.main()
