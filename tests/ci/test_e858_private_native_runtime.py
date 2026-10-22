"""INERT Darwin linkage observation; no actual owner terminal or secrets."""
import os
from pathlib import Path
import platform
import sys
import tempfile
import unittest
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[2] / 'scripts/ci'))
from e858_phase1 import private_native_runtime as native
from e858_phase1.private_binding import Block


def simulated_entry():
    return dict(binary_sha256='a' * 64, direct_links='DIRECT_LINKS_OBSERVED_NOT_CLOSED',
                direct_link_count=1, direct_links_sha256='b' * 64,
                codesign='LOCAL_CODESIGN_EXIT_ZERO_NOT_OWNER_TRUST')


class NativeRuntimeObservation(unittest.TestCase):
    def test_non_macos_refuses(self):
        with patch.object(native.sys, 'platform', 'linux'):
            with self.assertRaises(Block):
                native.observe_native_runtime()

    def test_synthetic_darwin_never_claims_authority(self):
        with patch.object(native.sys, 'platform', 'darwin'), \
             patch.object(native, '_observe_component', return_value=simulated_entry()):
            result = native.observe_native_runtime()
        self.assertEqual(result['kind'], 'INERT_DARWIN_NATIVE_RUNTIME_OBSERVATION')
        self.assertEqual(result['python']['codesign'],
                         'LOCAL_CODESIGN_EXIT_ZERO_NOT_OWNER_TRUST')
        self.assertEqual(result['transitive_linkage'], 'UNVERIFIED')
        self.assertEqual(result['dyld_shared_cache'], 'UNVERIFIED')
        self.assertEqual(result['operator_identity'], 'UNVERIFIED')
        self.assertFalse(result['runtime_complete'])
        self.assertFalse(result['live_tooling_adopted'])
        self.assertFalse(result['execution_allowed'])
        self.assertFalse(result['real_aiven_connected'])
        self.assertNotIn(str(Path.home()), str(result))

    def test_owned_synthetic_binary_hash_and_link_observation(self):
        with tempfile.TemporaryDirectory(prefix='e858-synthetic-native-') as temp:
            binary = Path(temp) / 'synthetic-runtime'
            binary.write_bytes(b'INERT_SYNTHETIC_BINARY')
            observations = []
            def tool(name, args):
                observations.append((name, args[:2]))
                if name.endswith('otool'):
                    return (0, b'/synthetic/header:\n\t/usr/lib/libSystem.B.dylib (compatibility version 1.0.0, current version 1.0.0)\n')
                return (0, b'')
            with patch.object(native, '_invoke', side_effect=tool):
                result = native._observe_component(binary)
            self.assertEqual(result['binary_sha256'], native._sha(binary.read_bytes()))
            self.assertEqual(result['direct_link_count'], 1)
            self.assertEqual(result['direct_links'], 'DIRECT_LINKS_OBSERVED_NOT_CLOSED')
            self.assertEqual(result['codesign'], 'LOCAL_CODESIGN_EXIT_ZERO_NOT_OWNER_TRUST')
            self.assertEqual(len(observations), 2)
            self.assertNotIn(temp, str(result))

    def test_missing_and_modified_component_fails_closed(self):
        with self.assertRaises(Block):
            native._observe_component('/nonexistent/synthetic/fixture')
        with tempfile.TemporaryDirectory(prefix='e858-synthetic-native-') as temp:
            binary = Path(temp) / 'synthetic-runtime'
            binary.write_bytes(b'FIXTURE')
            real = native._public_file
            seen = [0]
            def changed(path, cap, allow_empty=False):
                seen[0] += 1
                result = real(path, cap, allow_empty=allow_empty)
                return result if seen[0] == 1 else b'SYNTHETIC_TAMPER'
            with patch.object(native, '_public_file', side_effect=changed), \
                 patch.object(native, '_invoke', return_value=(1, b'')):
                with self.assertRaises(Block):
                    native._observe_component(binary)

    def test_failed_tools_stay_unverified_instead_of_pass(self):
        with tempfile.TemporaryDirectory(prefix='e858-synthetic-native-') as temp:
            binary = Path(temp) / 'synthetic-runtime'
            binary.write_bytes(b'FIXTURE')
            with patch.object(native, '_invoke', return_value=(1, b'')):
                result = native._observe_component(binary)
            self.assertEqual(result['direct_links'], 'UNVERIFIED')
            self.assertEqual(result['codesign'], 'UNVERIFIED')
            self.assertIsNone(result['direct_links_sha256'])

    def test_unknown_tools_are_blocked_without_exposing_messages(self):
        with self.assertRaises(Block):
            native._invoke('/usr/local/bin/unknown', ('-L', '/none'))
        with patch.object(native.subprocess, 'run', side_effect=OSError('SYNTHETIC_SECRET')):
            with self.assertRaises(Block) as caught:
                native._invoke('/usr/bin/otool', ('-L', '/synthetic'))
        self.assertNotIn('SYNTHETIC_SECRET', str(caught.exception))

    def test_actual_darwin_components_only_when_available(self):
        if sys.platform != 'darwin':
            self.skipTest('Darwin binary/tool observation is macOS-only')
        result = native.observe_native_runtime()
        self.assertEqual(result['os'], 'darwin')
        self.assertIn(result['python']['direct_links'],
                      ('DIRECT_LINKS_OBSERVED_NOT_CLOSED', 'UNVERIFIED'))
        self.assertIn(result['python']['codesign'],
                      ('LOCAL_CODESIGN_EXIT_ZERO_NOT_OWNER_TRUST', 'UNVERIFIED'))
        self.assertFalse(result['execution_allowed'])
        self.assertFalse(result['runtime_complete'])


if __name__ == '__main__':
    unittest.main()
