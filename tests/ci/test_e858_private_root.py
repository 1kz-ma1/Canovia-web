"""Synthetic-only FD boundary tests. No operator Mac, CA, secret or network."""
import os
from pathlib import Path
import stat
import sys
import tempfile
import unittest
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[2] / "scripts/ci"))
from e858_phase1 import private_root as root
from e858_phase1.private_binding import Block

CANARY = "SYNTHETIC_SECRET_MUST_NOT_LEAK"


class RootPreflight(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix="e858-inert-root-")
        self.addCleanup(self.temp.cleanup)
        self.parent = Path(self.temp.name)
        self.parent.chmod(0o700)
        self.child = self.parent / root.BASENAME
        self.child.mkdir(mode=0o700)
        self.anchor = os.open(self.parent, os.O_RDONLY | os.O_DIRECTORY)
        self.addCleanup(os.close, self.anchor)

    def test_acquires_fixed_child_without_trust_or_live_authority(self):
        with root.open_private_root(self.anchor) as entry:
            self.assertTrue(stat.S_ISDIR(os.fstat(entry.descriptor()).st_mode))
            self.assertFalse(os.get_inheritable(entry.descriptor()))
            state = entry.assessment()
            self.assertEqual(state["native_acl"], "UNVERIFIED")
            self.assertEqual(state["ancestor_trust"], "UNVERIFIED")
            self.assertEqual(state["operator_identity"], "UNVERIFIED")
            self.assertFalse(state["execution_allowed"])
            self.assertFalse(state["real_private_record_read"])
            self.assertNotIn(str(self.parent), repr(entry))
        self.assertFalse(entry.assessment()["directory_open"])
        with self.assertRaises(Block):
            entry.descriptor()
        entry.close()  # Already-closed owner is idempotent.

    def test_anchor_fd_validation_rejects_untrusted_type_and_mode(self):
        for bad in (-1, 0, 1, 2, True, "3", None):
            with self.subTest(fd=bad):
                with self.assertRaises(Block):
                    root.open_private_root(bad)
        self.parent.chmod(0o755)
        with self.assertRaises(Block):
            root.open_private_root(self.anchor)
        self.parent.chmod(0o700)
        with patch.object(root.os, "geteuid", return_value=os.geteuid() + 1):
            with self.assertRaises(Block):
                root.open_private_root(self.anchor)
        with patch.object(root.os, "get_inheritable", return_value=True):
            with self.assertRaises(Block):
                root.open_private_root(self.anchor)

    def test_child_mode_file_symlink_and_missing_fail_closed(self):
        self.child.chmod(0o755)
        with self.assertRaises(Block):
            root.open_private_root(self.anchor)
        self.child.chmod(0o700)
        self.child.rmdir()
        self.child.write_text("synthetic")
        with self.assertRaises(Block):
            root.open_private_root(self.anchor)
        self.child.unlink()
        elsewhere = self.parent / "elsewhere"
        elsewhere.mkdir(mode=0o700)
        self.child.symlink_to(elsewhere)
        with self.assertRaises(Block):
            root.open_private_root(self.anchor)
        self.child.unlink()
        with self.assertRaises(Block):
            root.open_private_root(self.anchor)

    def test_no_inherited_descriptor_or_unknown_platform_flag_fallback(self):
        with patch.object(root.os, "get_inheritable", side_effect=[False, True]):
            with self.assertRaises(Block):
                root.open_private_root(self.anchor)
        with patch.object(root.os, "O_NOFOLLOW", 0):
            with self.assertRaises(Block):
                root.open_private_root(self.anchor)
        with patch.object(root.os, "open", side_effect=OSError(CANARY)):
            with self.assertRaises(Block) as cm:
                root.open_private_root(self.anchor)
        self.assertNotIn(CANARY, str(cm.exception))

    def test_post_open_parent_identity_change_is_block(self):
        original = root._identity
        calls = [0]
        def changed(value):
            calls[0] += 1
            result = original(value)
            if calls[0] == 2:
                return result[:-1] + (result[-1] + 1,)
            return result
        with patch.object(root, "_identity", side_effect=changed):
            with self.assertRaises(Block):
                root.open_private_root(self.anchor)

    def test_reused_owned_descriptor_is_not_closed(self):
        owned = root.open_private_root(self.anchor)
        fd = owned.descriptor()
        replacement = os.open(os.devnull, os.O_RDONLY)
        try:
            os.dup2(replacement, fd)
            with self.assertRaises(Block):
                owned.close()
            os.fstat(fd)  # The unrelated descriptor remains open.
        finally:
            os.close(fd)
            if replacement != fd:
                os.close(replacement)

    def test_reverse_link_from_child_matches_original_parent(self):
        with root.open_private_root(self.anchor) as entry:
            self.assertTrue(stat.S_ISDIR(os.fstat(entry.descriptor()).st_mode))
            self.assertEqual(entry.assessment()["ancestor_trust"], "UNVERIFIED")
            self.assertFalse(entry.assessment()["execution_allowed"])

    def test_reverse_link_to_unrelated_owned_parent_is_blocked(self):
        alternate = self.parent / "unrelated"
        alternate.mkdir(mode=0o700)
        alt_fd = os.open(alternate, os.O_RDONLY | os.O_DIRECTORY)
        self.addCleanup(os.close, alt_fd)
        original_open = os.open
        def open_checked(name, flags, *args, **kwargs):
            if name == "..":
                return os.dup(alt_fd)
            return original_open(name, flags, *args, **kwargs)
        with patch.object(root.os, "open", side_effect=open_checked):
            with self.assertRaises(Block):
                root.open_private_root(self.anchor)
        self.assertTrue(os.fstat(self.anchor))

    def test_reverse_link_failure_closes_temporary_child(self):
        original_open = os.open
        child = []
        def open_checked(name, flags, *args, **kwargs):
            if name == "..":
                raise OSError(CANARY)
            fd = original_open(name, flags, *args, **kwargs)
            child.append(fd)
            return fd
        with patch.object(root.os, "open", side_effect=open_checked):
            with self.assertRaises(Block) as raised:
                root.open_private_root(self.anchor)
        self.assertNotIn(CANARY, str(raised.exception))
        self.assertEqual(len(child), 1)
        with self.assertRaises(OSError):
            os.fstat(child[0])
        self.assertTrue(os.fstat(self.anchor))

    def test_no_password_tty_sql_or_network_capability(self):
        self.assertFalse(hasattr(root, "connect"))
        self.assertFalse(hasattr(root, "capture_password"))
        self.assertFalse(hasattr(root, "execute_sql"))
        with root.open_private_root(self.anchor) as owned:
            self.assertEqual(owned.assessment()["kind"], "INERT_PRIVATE_ROOT_PREFLIGHT")


if __name__ == "__main__":
    unittest.main()
