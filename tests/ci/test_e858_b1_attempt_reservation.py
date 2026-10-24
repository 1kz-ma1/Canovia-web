"""B-mode local reservation: owned temp directories only, never real Aiven."""
import concurrent.futures
import hashlib
import json
import os
from pathlib import Path
import stat
import sys
import tempfile
import unittest
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[2] / 'scripts/ci'))
from e858_phase1 import b1_attempt_reservation as reservation
from e858_phase1 import b1_package, launcher_candidate, opener_candidate, practical
from e858_phase1.private_binding import Block as ExistingBlock

ATTEMPT = 'a' * 32
TOOLING = 'b' * 40


class LocalReservationTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory(prefix='e858-owned-reservation-')
        self.root = Path(self.tmp.name)
        os.chmod(self.root,0o700)
        self.fd = os.open(str(self.root),os.O_RDONLY|os.O_DIRECTORY|os.O_NOFOLLOW|os.O_CLOEXEC)

    def tearDown(self):
        os.close(self.fd)
        self.tmp.cleanup()

    def files(self):
        return sorted(x.name for x in self.root.iterdir())

    def test_write_once_atomically_in_private_directory_without_authority(self):
        with patch('socket.socket') as sock, patch('socket.getaddrinfo') as dns, patch('builtins.print') as printed:
            result = reservation.reserve(self.fd,ATTEMPT,TOOLING)
            dns.assert_not_called();sock.assert_not_called();printed.assert_not_called()
        self.assertEqual(result['result'],'LOCAL_ATTEMPT_RESERVED_NOT_AUTHORIZED')
        self.assertEqual(result['retry_same_id_in_same_directory'],'REJECTED')
        for field in ('durable_rollback_resistance','owner_identity_verified',
                      'fresh_b2_go_granted','execution_allowed','real_aiven_connected','production_go'):
            self.assertIs(result[field],False)
        self.assertEqual(result['remote_state'],'UNVERIFIED')
        self.assertEqual(len(self.files()),1)
        marker = self.root/self.files()[0]
        self.assertEqual(stat.S_IMODE(marker.stat().st_mode),0o600)
        data = marker.read_bytes()
        self.assertNotIn(ATTEMPT.encode('ascii'),data)
        self.assertEqual(json.loads(data)['state'],'CONSUMED_ON_RESERVATION_NOT_EXECUTION')
        self.assertIs(json.loads(data)['execution_allowed'],False)
        self.assertNotIn(str(self.root),str(result)+data.decode('ascii'))
        with self.assertRaises(reservation.Block):reservation.authorize(result)

    def test_duplicate_rejected_across_reopen_and_tooling_change(self):
        reservation.reserve(self.fd,ATTEMPT,TOOLING)
        reopened=os.open(str(self.root),os.O_RDONLY|os.O_DIRECTORY|os.O_NOFOLLOW|os.O_CLOEXEC)
        try:
            with self.assertRaises(reservation.Block):
                reservation.reserve(reopened,ATTEMPT,TOOLING)
            with self.assertRaises(reservation.Block):
                reservation.reserve(reopened,ATTEMPT,'c'*40)
            self.assertEqual(len(self.files()),1)
        finally:os.close(reopened)

    def test_concurrent_duplicate_only_one_succeeds(self):
        def attempt(_):
            try:
                return reservation.reserve(self.fd,ATTEMPT,TOOLING)['result']
            except reservation.Block:
                return 'BLOCK'
        with concurrent.futures.ThreadPoolExecutor(max_workers=8) as pool:
            results=list(pool.map(attempt,range(8)))
        self.assertEqual(results.count('LOCAL_ATTEMPT_RESERVED_NOT_AUTHORIZED'),1)
        self.assertEqual(results.count('BLOCK'),7)
        self.assertEqual(len(self.files()),1)

    def test_fsync_failure_keeps_attempt_consumed(self):
        actual=os.fsync
        calls=[]
        def fail_file_sync(fd):
            calls.append(fd)
            raise OSError('SYNTHETIC_FSYNC_FAILURE_PRIVATE_CANARY')
        with patch.object(reservation.os,'fsync',side_effect=fail_file_sync):
            with self.assertRaises(reservation.Block) as ex:
                reservation.reserve(self.fd,ATTEMPT,TOOLING)
        self.assertEqual(str(ex.exception),'')
        self.assertTrue(calls)
        self.assertEqual(len(self.files()),1)
        with self.assertRaises(reservation.Block):
            reservation.reserve(self.fd,ATTEMPT,TOOLING)
        # A failed fsync must NOT make a remote or other retry safe.
        self.assertEqual(self.files()[0][:8],'attempt-')

    def test_reject_untrusted_modes_symlink_fd_and_invalid_inputs(self):
        bad=[
            (self.fd,'A'*32,TOOLING),
            (self.fd,ATTEMPT,'A'*40),
            (self.fd,ATTEMPT,'a'),
            (self.fd,True,TOOLING),
            (self.fd,None,TOOLING),
            (True,ATTEMPT,TOOLING),
            (0,ATTEMPT,TOOLING),
        ]
        for fd,at,tool in bad:
            with self.subTest(fd=fd,at=at,tool=tool):
                with self.assertRaises(reservation.Block):
                    reservation.reserve(fd,at,tool)
        self.assertEqual(self.files(),[])
        os.chmod(self.root,0o755)
        try:
            with self.assertRaises(reservation.Block):
                reservation.reserve(self.fd,ATTEMPT,TOOLING)
        finally:os.chmod(self.root,0o700)
        with tempfile.TemporaryFile() as f:
            with self.assertRaises(reservation.Block):
                reservation.reserve(f.fileno(),ATTEMPT,TOOLING)
        marker='attempt-'+hashlib.sha256(ATTEMPT.encode('ascii')).hexdigest()+'.reserved'
        (self.root/marker).symlink_to('/tmp/does-not-exist')
        with self.assertRaises(reservation.Block):
            reservation.reserve(self.fd,ATTEMPT,TOOLING)
        self.assertTrue((self.root/marker).is_symlink())

    def test_any_partial_write_leaves_marker_and_blocks_retry(self):
        with patch.object(reservation.os,'write',side_effect=OSError('CANARY')):
            with self.assertRaises(reservation.Block):
                reservation.reserve(self.fd,ATTEMPT,TOOLING)
        self.assertEqual(len(self.files()),1)
        with self.assertRaises(reservation.Block):
            reservation.reserve(self.fd,ATTEMPT,TOOLING)

    def test_neither_review_nor_reservation_activates_live_paths(self):
        self.assertIn('scripts/ci/e858_phase1/b1_attempt_reservation.py',b1_package.FILES)
        with patch('socket.socket') as sock, patch('socket.getaddrinfo') as dns:
            reservation.reserve(self.fd,ATTEMPT,TOOLING)
            for obj,method in ((launcher_candidate.LauncherCandidate,'run'),
                               (opener_candidate.OpenerCandidate,'open'),
                               (practical.Adapter,'prepare'),
                               (practical.PrivateWire,'connect')):
                with self.subTest(method=method):
                    with self.assertRaises(ExistingBlock):
                        getattr(object.__new__(obj),method)()
            dns.assert_not_called();sock.assert_not_called()


if __name__=='__main__':
    unittest.main()
