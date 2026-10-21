"""Only new bridge boundaries; existing31 TLS/B20 negatives are reused."""
import contextlib
import io
import json
from pathlib import Path
import subprocess
import sys
import unittest
from unittest.mock import patch

ROOT=Path(__file__).resolve().parents[2]
sys.path.insert(0,str(ROOT/'scripts/ci'))
import e858_full94_tls as full
import e858_tls_host as host

CANARY='SYNTHETIC_PRIVATE_INPUT_DO_NOT_ECHO'


class FullBridgeBoundaryTests(unittest.TestCase):
    def test_unknown_runtime_profile_never_builds_or_connects(self):
        with patch.object(host,'certificate_material') as cert, patch.object(host,'run') as run:
            with self.assertRaises(host.Block):host.rehearse('8.0','a'*40,'real_aiven')
            cert.assert_not_called();run.assert_not_called()

    def test_new_entrypoint_rejects_live_credentials_export_and_free_sql(self):
        for args in (['--host',CANARY],['--password',CANARY],['--login-path',CANARY],
                     ['--ca',CANARY],['--collect'],['--execute'],['--sql',CANARY],['--dump'],
                     ['--series','8.0','--tooling-sha','a'*40,'--live']):
            proc=subprocess.run([sys.executable,'-I','-B',str(ROOT/'scripts/ci/e858_full94_tls.py'),*args],capture_output=True)
            self.assertEqual(2,proc.returncode)
            self.assertNotIn(CANARY.encode(),proc.stdout+proc.stderr)
            result=json.loads(proc.stdout)
            self.assertEqual('BLOCK',result['result']);self.assertFalse(result['real_aiven_connected'])

    def test_source_mismatch_cannot_enter_docker(self):
        with patch.object(host,'assets',side_effect=ValueError(CANARY)),patch.object(host,'run') as run:
            with self.assertRaises(ValueError):host.rehearse('8.0','a'*40,'full94')
            run.assert_not_called()


if __name__=='__main__':unittest.main()
