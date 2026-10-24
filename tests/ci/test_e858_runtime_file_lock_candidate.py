"""B1 read-only runtime drift boundary, synthetic and offline only."""
import os
from pathlib import Path
import sys
import unittest
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[2] / 'scripts/ci'))
from e858_phase1 import runtime_file_lock_candidate as lock
from e858_phase1 import b1_package, private_distribution as dist
from e858_phase1.practical import Program
from e858_phase1.private_binding import Block
import e858_b_offline as offline


class RuntimeFileLock(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.root = offline.ROOT
        cls.head = offline.git(cls.root, 'rev-parse', 'HEAD').decode().strip()
        cls.pins = offline.integrity(cls.root, cls.head)
        cls.archive = (Path(os.environ['B1_BUILD_DIR']) / 'b1-candidate.zip').read_bytes()
        cls.archive_sha = dist._sha(cls.archive)
        cls.program = Program(cls.archive, cls.archive_sha, cls.pins)

    def new_lock(self):
        return lock.RuntimeFileLockCandidate(self.program, str(self.root),
                                             self.archive, self.archive_sha, self.head)

    def test_positive_read_only_observation(self):
        candidate = self.new_lock()
        try:
            result = candidate.check()
            self.assertEqual(result['result'], 'B1_PUBLIC_RUNTIME_FILE_ORIGIN_RECHECKED')
            self.assertEqual(result['tooling_sha'], self.head)
            self.assertGreater(result['source_count'], 0)
            for key in ('owner_approval_verified','live_tooling_adopted','execution_allowed',
                        'real_aiven_connected','production_go'):
                self.assertIs(result[key], False)
            self.assertEqual(result['file_placement'], 'OBSERVED_EXISTING_CHECKOUT_NOT_INSTALLED')
            self.assertNotIn(str(self.root), str(result))
        finally:
            candidate.close()
        with self.assertRaises(Block):
            candidate.check()

    def test_source_only_recheck_never_invokes_checkout_or_program(self):
        candidate=self.new_lock()
        try:
            with patch.object(b1_package, 'verify', side_effect=AssertionError('NO_GIT_AFTER_R1')), \
                 patch.object(self.program, 'check', side_effect=AssertionError('PROGRAM_OWNED_BY_ENTRY')):
                report=candidate.check_sources()
                self.assertEqual(report['result'],'B1_PUBLIC_SOURCE_IDENTITIES_RECHECKED')
                self.assertFalse(report['execution_allowed'])
        finally:candidate.close()

    def test_unknown_pins_or_untrusted_program_blocks(self):
        for program, root, archive, sha, head in (
            (object(), str(self.root), self.archive, self.archive_sha, self.head),
            (self.program, str(self.root), self.archive, '0'*64, self.head),
            (self.program, str(self.root), self.archive, self.archive_sha, 'f'*40),
            (self.program, 'relative', self.archive, self.archive_sha, self.head),
            (self.program, str(self.root), bytearray(self.archive), self.archive_sha, self.head),
        ):
            with self.subTest(kind=type(program).__name__, root=root, head=head):
                with self.assertRaises(Block):
                    lock.RuntimeFileLockCandidate(program, root, archive, sha, head)

    def test_checkout_failure_terminal_and_redacted(self):
        candidate = self.new_lock()
        with patch.object(b1_package, 'verify', side_effect=RuntimeError('SYNTHETIC_SECRET')):
            with self.assertRaises(Block) as caught:
                candidate.check()
        self.assertNotIn('SYNTHETIC_SECRET', str(caught.exception))
        with self.assertRaises(Block):
            candidate.check()

    def test_change_in_sys_path_is_rejected(self):
        candidate = self.new_lock()
        try:
            with patch.object(lock.sys, 'path', list(sys.path) + ['/UNTRUSTED_SYNTHETIC_IMPORT']):
                with self.assertRaises(Block):
                    candidate.check()
        finally:
            candidate.close()

    def test_loaded_module_retarget_does_not_pass(self):
        candidate = self.new_lock()
        try:
            module_name = 'e858_phase1.practical'
            module = sys.modules[module_name]
            with patch.object(module, '__file__', '/SYNTHETIC_OTHER_IMPORT'):
                with self.assertRaises(Block):
                    candidate.check()
        finally:
            candidate.close()

    def test_source_file_identity_changed_is_rejected(self):
        candidate = self.new_lock()
        target = next(iter(candidate._source_ids))
        candidate._source_ids[target] = ('UNTRUSTED',)
        with self.assertRaises(Block):
            candidate.check()

    def test_no_execution_entrypoint_or_private_input(self):
        for attr in ('run', 'connect', 'activate', 'install', 'load_credentials', 'grant_go'):
            self.assertFalse(hasattr(lock.RuntimeFileLockCandidate, attr))


if __name__ == '__main__':
    unittest.main()
