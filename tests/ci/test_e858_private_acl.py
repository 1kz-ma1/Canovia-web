"""Native ACL read-only boundary; synthetic directories, no private data."""
import os
from pathlib import Path
import sys
import tempfile
import unittest
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[2] / "scripts/ci"))
from e858_phase1 import private_acl as acl
from e858_phase1.private_binding import Block


class NativeACLObservation(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix="e858-synthetic-acl-")
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.root.chmod(0o700)
        self.fd = os.open(self.root, os.O_RDONLY | os.O_DIRECTORY)
        self.addCleanup(os.close, self.fd)

    def test_real_macos_empty_synthetic_acl_or_nonmac_block(self):
        if sys.platform != "darwin":
            with self.assertRaises(Block):
                acl.inspect_native_acl(self.fd)
            return
        try:
            result = acl.inspect_native_acl(self.fd)
        except Block:
            # Diagnostic strictly on this test-created temporary directory FD.
            import ctypes
            libc = ctypes.CDLL(None, use_errno=True)
            get = libc.acl_get_fd_np
            get.argtypes = [ctypes.c_int, ctypes.c_int]
            get.restype = ctypes.c_void_p
            ctypes.set_errno(0)
            ptr = get(self.fd, acl.ACL_TYPE_EXTENDED)
            if not ptr:
                raise AssertionError("SYNTHETIC_MAC_ACL_GET_NULL_ERRNO_" + str(ctypes.get_errno())) from None
            try:
                valid = libc.acl_valid
                valid.argtypes = [ctypes.c_void_p]
                valid.restype = ctypes.c_int
                is_valid = valid(ptr)
                entry = libc.acl_get_entry
                entry.argtypes = [ctypes.c_void_p, ctypes.c_int, ctypes.POINTER(ctypes.c_void_p)]
                entry.restype = ctypes.c_int
                slot = ctypes.c_void_p()
                ctypes.set_errno(0)
                found = entry(ptr, acl.ACL_FIRST_ENTRY, ctypes.byref(slot))
                raise AssertionError("SYNTHETIC_MAC_ACL_VALID_" + str(is_valid) +
                    "_FIRST_" + str(found) + "_ERRNO_" + str(ctypes.get_errno())) from None
            finally:
                free = libc.acl_free
                free.argtypes = [ctypes.c_void_p]
                free.restype = ctypes.c_int
                free(ptr)
        self.assertEqual(result["result"], "ZERO_EXTENDED_ACL_ENTRIES_OBSERVED")
        self.assertFalse(result["execution_allowed"])
        self.assertFalse(result["real_target_read"])
        self.assertEqual(result["operator_identity"], "UNVERIFIED")

    def test_untrusted_fd_values_and_mode_rejected(self):
        for fd in (-1, 0, 1, 2, True, "3", None):
            with self.subTest(fd=fd):
                with self.assertRaises(Block):
                    acl.inspect_native_acl(fd)
        with patch.object(acl.sys, "platform", "darwin"), patch.object(acl, "_empty_native_acl", return_value=True):
            self.root.chmod(0o755)
            with self.assertRaises(Block):
                acl.inspect_native_acl(self.fd)
            self.root.chmod(0o700)
            with patch.object(acl.os, "get_inheritable", return_value=True):
                with self.assertRaises(Block):
                    acl.inspect_native_acl(self.fd)
            with patch.object(acl.os, "geteuid", return_value=os.geteuid()+1):
                with self.assertRaises(Block):
                    acl.inspect_native_acl(self.fd)

    def test_acl_present_unavailable_or_error_blocks_even_if_owner_matches(self):
        with patch.object(acl.sys, "platform", "darwin"):
            for outcome in (False, None):
                with patch.object(acl, "_empty_native_acl", return_value=outcome):
                    with self.assertRaises(Block):
                        acl.inspect_native_acl(self.fd)
            with patch.object(acl, "_empty_native_acl", side_effect=OSError("SYNTHETIC_SECRET")):
                with self.assertRaises(Block) as caught:
                    acl.inspect_native_acl(self.fd)
                self.assertNotIn("SYNTHETIC_SECRET", str(caught.exception))

    def test_positive_simulated_observation_does_not_authorize(self):
        with patch.object(acl.sys, "platform", "darwin"), patch.object(acl, "_empty_native_acl", return_value=True):
            result = acl.inspect_native_acl(self.fd)
        self.assertFalse(result["execution_allowed"])
        self.assertFalse(result["live_tooling_adopted"])
        self.assertEqual(result["ancestor_trust"], "UNVERIFIED")

    def test_change_to_original_fd_identity_is_rejected(self):
        actual = acl._identity
        seen = [0]
        def identity(value):
            seen[0] += 1
            key = actual(value)
            if seen[0] == 2:
                return key[:-1] + (key[-1] + 1,)
            return key
        with patch.object(acl.sys, "platform", "darwin"), patch.object(acl, "_empty_native_acl", return_value=True):
            with patch.object(acl, "_identity", side_effect=identity):
                with self.assertRaises(Block):
                    acl.inspect_native_acl(self.fd)

    def test_no_network_password_or_acl_mutation(self):
        self.assertFalse(hasattr(acl, "connect"))
        self.assertFalse(hasattr(acl, "capture_password"))
        self.assertFalse(hasattr(acl, "acl_set_fd_np"))
        self.assertFalse(hasattr(acl, "execute_sql"))
        with patch.object(acl.sys, "platform", "darwin"), patch.object(acl, "_empty_native_acl", return_value=True):
            self.assertTrue(os.fstat(self.fd))
            acl.inspect_native_acl(self.fd)
            self.assertTrue(os.fstat(self.fd))


if __name__ == "__main__":
    unittest.main()
