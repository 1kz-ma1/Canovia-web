"""B owner-entry synthetic offline rehearsal: no private files or credentials."""
import os
from pathlib import Path
import pty
import sys
import tempfile
import unittest
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[2] / 'scripts/ci'))
from e858_phase1 import private_entry as entry
from e858_phase1 import private_root
from e858_phase1.private_binding import Block

CANARY = 'SYNTHETIC_CREDENTIAL_MUST_NOT_LEAK'


def acl_result(result='ACL_RECORD_ENOENT_UNVERIFIED'):
    return dict(kind='INERT_NATIVE_ACL_OBSERVATION', result=result,
                ancestor_trust='UNVERIFIED', operator_identity='UNVERIFIED',
                execution_allowed=False, live_tooling_adopted=False)


def tty_result():
    return dict(kind='INERT_FOREGROUND_TERMINAL_OBSERVATION',
                foreground_group='OBSERVED_NOT_AUTHENTICATED',
                operator_identity='UNVERIFIED', explicit_owner_gesture='UNVERIFIED',
                real_credential_read=False, execution_allowed=False)


class SupervisedBEntryRehearsal(unittest.TestCase):
    def setUp(self):
        temp = tempfile.TemporaryDirectory(prefix='e858-b-synthetic-entry-')
        self.addCleanup(temp.cleanup)
        self.anchor = Path(temp.name)
        self.anchor.chmod(0o700)
        self.child = self.anchor / 'phase1-private'
        self.child.mkdir(mode=0o700)
        self.parent_fd = os.open(self.anchor, os.O_RDONLY | os.O_DIRECTORY | os.O_CLOEXEC)
        self.addCleanup(os.close, self.parent_fd)
        self.master, self.slave = pty.openpty()
        self.addCleanup(os.close, self.slave)
        self.addCleanup(os.close, self.master)

    def run_synthetic(self, acl=None, tty=None):
        with patch.object(entry.sys, 'platform', 'darwin'), \
             patch.object(entry.private_acl, 'inspect_native_acl',
                          side_effect=acl if acl else lambda fd: acl_result()), \
             patch.object(entry.private_operator, 'observe_foreground_terminal',
                          side_effect=tty if tty else lambda fd: tty_result()):
            return entry.review_b_operator_entry(self.parent_fd, self.slave)

    def test_combined_observation_is_always_b1_pending_not_go(self):
        report = self.run_synthetic()
        self.assertEqual(report['kind'], 'E858_B_INERT_OPERATOR_ENTRY_REHEARSAL')
        self.assertEqual(report['current_design'],
                         'B_OWNER_SUPERVISED_SINGLE_METADATA_AUDIT_ADOPTED')
        self.assertEqual(report['fixed_child_parent'], 'ONE_LEVEL_FD_RELATION_OBSERVED')
        self.assertEqual(report['anchor_ancestry'], 'UNVERIFIED')
        self.assertEqual(report['anchor_acl'], 'ACL_RECORD_ENOENT_UNVERIFIED')
        self.assertEqual(report['child_acl'], 'ACL_RECORD_ENOENT_UNVERIFIED')
        self.assertEqual(report['b1_private_acceptance'], 'PENDING')
        self.assertEqual(report['b2_fresh_owner_go'], 'NOT_GRANTED')
        for key in ('execution_allowed', 'credential_read',
                    'target_ca_read', 'network_connected', 'real_aiven_connected'):
            self.assertIs(report[key], False)
        self.assertNotIn(str(self.anchor), str(report))
        self.assertTrue(os.fstat(self.parent_fd))
        self.assertTrue(os.fstat(self.slave))

    def test_both_native_acl_categories_remain_untrusted(self):
        reports = iter((acl_result('ZERO_ACL_ENTRIES_OBSERVED'),
                        acl_result('ACL_RECORD_ENOENT_UNVERIFIED')))
        r = self.run_synthetic(acl=lambda fd: next(reports))
        self.assertEqual(r['anchor_acl'], 'ZERO_ACL_ENTRIES_OBSERVED')
        self.assertEqual(r['child_acl'], 'ACL_RECORD_ENOENT_UNVERIFIED')
        self.assertEqual(r['anchor_ancestry'], 'UNVERIFIED')
        self.assertFalse(r['execution_allowed'])

    def test_actual_darwin_acl_on_owned_synthetic_fds(self):
        if sys.platform != 'darwin':
            self.skipTest('Darwin ACL FD read-only probe is only valid on macOS')
        with patch.object(entry.private_operator, 'observe_foreground_terminal',
                          return_value=tty_result()):
            r = entry.review_b_operator_entry(self.parent_fd, self.slave)
        self.assertIn(r['anchor_acl'], entry._ALLOWED_ACL)
        self.assertIn(r['child_acl'], entry._ALLOWED_ACL)
        self.assertFalse(r['execution_allowed'])

    def test_linux_invalid_fd_inherited_or_non_directory_is_blocked(self):
        with self.assertRaises(Block):
            entry.review_b_operator_entry(self.parent_fd, self.slave)
        with patch.object(entry.sys, 'platform', 'darwin'):
            for values in ((None, self.slave), (True, self.slave), (1, self.slave),
                           (self.parent_fd, self.parent_fd), (self.slave, self.parent_fd)):
                with self.subTest(values=values):
                    with self.assertRaises(Block):
                        entry.review_b_operator_entry(*values)
            with patch.object(entry.os, 'get_inheritable', return_value=True):
                with self.assertRaises(Block):
                    entry.review_b_operator_entry(self.parent_fd, self.slave)

    def test_symlink_and_weak_mode_child_block_without_read(self):
        self.child.chmod(0o755)
        with self.assertRaises(Block):
            self.run_synthetic()
        self.child.chmod(0o700)
        self.child.rmdir()
        self.child.symlink_to(self.anchor)
        with self.assertRaises(Block):
            self.run_synthetic()

    def test_native_acl_or_foreground_anomaly_block(self):
        for bogus in ({'kind': 'INERT_NATIVE_ACL_OBSERVATION',
                       'result': 'ZERO_ACL_ENTRIES_OBSERVED', 'execution_allowed': True},
                      acl_result('REAL_ACL_TRUSTED'), None):
            with self.subTest(bogus=bogus):
                with self.assertRaises(Block):
                    self.run_synthetic(acl=lambda fd: bogus)
        for bogus in (None, {**tty_result(), 'operator_identity': 'OWNER_VERIFIED'},
                      {**tty_result(), 'execution_allowed': True}):
            with self.subTest(tty=bogus):
                with self.assertRaises(Block):
                    self.run_synthetic(tty=lambda fd: bogus)

    def test_rehearsal_releases_only_its_child_fd_even_on_failure(self):
        opened = []
        actual = private_root.open_private_root
        def wrapped(parent):
            handle = actual(parent)
            opened.append(handle)
            return handle
        with patch.object(entry.private_root, 'open_private_root', side_effect=wrapped):
            self.run_synthetic()
        self.assertEqual(len(opened), 1)
        with self.assertRaises(Block):
            opened[0].descriptor()
        with patch.object(entry.private_root, 'open_private_root', side_effect=wrapped):
            with self.assertRaises(Block):
                self.run_synthetic(tty=lambda fd: {**tty_result(), 'execution_allowed': True})
        with self.assertRaises(Block):
            opened[-1].descriptor()
        self.assertTrue(os.fstat(self.parent_fd))
        self.assertTrue(os.fstat(self.slave))

    def test_no_secret_io_or_spurious_approval(self):
        with patch.object(entry.os, 'read', side_effect=AssertionError(CANARY)), \
             patch.object(entry.os, 'write', side_effect=AssertionError(CANARY)):
            result = self.run_synthetic()
        self.assertNotIn(CANARY, str(result))
        self.assertNotIn('B2_FRESH_OWNER_GO_GRANTED', str(result))
        with patch.object(entry.private_acl, 'inspect_native_acl',
                          side_effect=RuntimeError(CANARY)), \
             patch.object(entry.sys, 'platform', 'darwin'):
            with self.assertRaises(Block) as caught:
                entry.review_b_operator_entry(self.parent_fd, self.slave)
        self.assertNotIn(CANARY, str(caught.exception))


if __name__ == '__main__':
    unittest.main()
