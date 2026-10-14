"""Only temporary synthetic encrypted-looking bytes; no user data or keys."""
from __future__ import annotations

import hashlib
from pathlib import Path
import sys
import tempfile
import unittest

sys.path.insert(0, str(Path(__file__).resolve().parents[2]))
from scripts.ops.platform_backup_ciphertext_check import verify


class CiphertextCheckTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        base = Path(self.temp.name)
        self.source = base / "sample.age"
        self.copy = base / "offsite-downloaded.age"
        content = b"synthetic-ciphertext-bytes-only\x00\x11\x22"
        self.expected = hashlib.sha256(content).hexdigest()
        for path in (self.source, self.copy):
            path.write_bytes(content)
            path.chmod(0o600)

    def check(self, source=None, copy=None, digest=None):
        return verify(source or self.source, copy or self.copy, digest or self.expected)

    def test_matching_copies_still_require_restore_and_owner_go(self):
        result = self.check()
        self.assertEqual(result["result"], "REVIEW_REQUIRED")
        self.assertTrue(result["ciphertext_hash_verified"])
        self.assertFalse(result["isolated_restore_verified"])
        self.assertFalse(result["release_authorized"])

    def test_mismatched_encrypted_copy_blocks(self):
        self.copy.write_bytes(b"tampered")
        self.assertEqual(self.check()["result"], "BLOCK")

    def test_world_readable_copy_blocks(self):
        self.copy.chmod(0o644)
        self.assertEqual(self.check()["code"], "CIPHERTEXT_FILE_UNSAFE_OR_UNAVAILABLE")

    def test_symlink_copy_blocks(self):
        self.copy.unlink()
        self.copy.symlink_to(self.source)
        self.assertEqual(self.check()["result"], "BLOCK")

    def test_same_inode_blocks(self):
        self.assertEqual(self.check(copy=self.source)["code"], "COPIES_NOT_INDEPENDENT_OR_SIZE_MISMATCH")

    def test_invalid_digest_blocks(self):
        self.assertEqual(self.check(digest="bad")["code"], "INVALID_EXPECTED_DIGEST")

    def test_unencrypted_extension_or_missing_file_blocks(self):
        other = self.copy.with_suffix(".sql")
        other.write_bytes(self.source.read_bytes())
        other.chmod(0o600)
        self.assertEqual(self.check(copy=other)["result"], "BLOCK")
        self.assertEqual(self.check(copy=self.copy.with_name("missing.age"))["result"], "BLOCK")

    def test_hash_matches_but_claims_remain_unverified(self):
        report = self.check()
        self.assertFalse(report["offsite_source_provenance_verified"])
        self.assertFalse(report["age_decryption_verified"])
        self.assertNotIn(str(self.source), str(report))
        self.assertNotIn(self.expected, str(report))


if __name__ == "__main__":
    unittest.main()
