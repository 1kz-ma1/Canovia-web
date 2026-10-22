"""Offline package provenance delta; depends on a freshly verified INERT archive.

No credential, owner directory, trusted TTY, real target, SQL or live connector.
Tests check only PyMySQL 1.1.2 wheel bytes and this job's installed sources.
"""
import hashlib
import os
from pathlib import Path
import sys
import unittest
from unittest.mock import patch
import zipfile

sys.path.insert(0, str(Path(__file__).resolve().parents[2] / 'scripts/ci'))
from e858_phase1 import private_distribution as dist
from e858_phase1.private_binding import Block


class PinnedWheelInstallation(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        artifact = Path(os.environ['BUILD_DIR']) / 'candidate.zip'
        with zipfile.ZipFile(artifact) as archive:
            cls.wheel = archive.read(dist.WHEEL_PATH)
        if hashlib.sha256(cls.wheel).hexdigest() != dist.WHEEL_SHA:
            raise AssertionError('PINNED_WHEEL_MISSING')

    def test_real_installed_record_sources_match_verified_wheel(self):
        result = dist.verify_pymysql_wheel_installation(self.wheel)
        self.assertEqual(result['result'], 'PINNED_WHEEL_RECORD_SOURCES_MATCH_OBSERVED')
        self.assertEqual(result['wheel_sha256'], dist.WHEEL_SHA)
        self.assertGreater(result['source_count'], 0)
        self.assertEqual(result['installed_sources_sha256'],
                         dist.runtime_components()['pymysql_sources_sha256'])
        self.assertEqual(result['untracked_files'], 'UNVERIFIED')
        self.assertEqual(result['runtime_linkage_closure'], 'UNVERIFIED')
        self.assertFalse(result['runtime_complete'])
        self.assertFalse(result['live_tooling_adopted'])
        self.assertFalse(result['execution_allowed'])

    def test_corrupt_wheel_and_wrong_types_fail_closed(self):
        for candidate in (None, '', b'', bytearray(self.wheel),
                          self.wheel + b'x', self.wheel[:-1]):
            with self.subTest(kind=type(candidate).__name__):
                with self.assertRaises(Block):
                    dist.verify_pymysql_wheel_installation(candidate)

    def test_tampered_installed_source_fails_closed(self):
        original = dist._public_file
        attempts = [0]
        def modified(path, cap, allow_empty=False):
            raw = original(path, cap, allow_empty=allow_empty)
            attempts[0] += 1
            return b'SYNTHETIC_SOURCE_MODIFICATION' if attempts[0] == 1 else raw
        with patch.object(dist, '_public_file', side_effect=modified):
            with self.assertRaises(Block) as caught:
                dist.verify_pymysql_wheel_installation(self.wheel)
        self.assertGreater(attempts[0], 0)
        self.assertNotIn('SYNTHETIC_SOURCE_MODIFICATION', str(caught.exception))

    def test_installed_distribution_unavailable_or_mismatched_blocks(self):
        with patch.object(dist.metadata, 'distribution',
                          side_effect=RuntimeError('SYNTHETIC_METADATA_ERROR')):
            with self.assertRaises(Block) as caught:
                dist.verify_pymysql_wheel_installation(self.wheel)
        self.assertNotIn('SYNTHETIC_METADATA_ERROR', str(caught.exception))
        with patch.object(dist.os.path, 'samefile', return_value=False):
            with self.assertRaises(Block):
                dist.verify_pymysql_wheel_installation(self.wheel)

    def test_receipt_never_claims_runtime_complete_or_execution_approval(self):
        result = dist.verify_pymysql_wheel_installation(self.wheel)
        self.assertEqual(set(result), {
            'result', 'wheel_sha256', 'source_count', 'installed_sources_sha256',
            'untracked_files', 'runtime_linkage_closure', 'runtime_complete',
            'live_tooling_adopted', 'execution_allowed'
        })
        self.assertNotIn('path', str(result).lower())
        self.assertNotIn('host', str(result).lower())
        self.assertNotIn('principal', str(result).lower())
        self.assertNotIn('password', str(result).lower())


if __name__ == '__main__':
    unittest.main()
