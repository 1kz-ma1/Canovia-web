import importlib.util
import io
import json
import os
from pathlib import Path
import sys
import tempfile
from types import SimpleNamespace
import unittest
from unittest.mock import patch

spec = importlib.util.spec_from_file_location('collector20', Path(__file__).resolve().parents[2] / 'scripts/ops/integrated_release20_collect.py')
collector = importlib.util.module_from_spec(spec)
spec.loader.exec_module(collector)


class Release20CollectorTest(unittest.TestCase):
    def test_ci_or_missing_approval_never_invokes_mysql(self):
        for env in ({'CI': 'true'}, {}, {'CANOVIA_RELEASE20_OPERATOR_APPROVED': 'YES'}):
            with patch.dict(os.environ, env, clear=True), patch.object(collector.subprocess, 'run') as run:
                with self.assertRaises(ValueError):
                    collector.collect('a' * 40)
                run.assert_not_called()

    def test_default_is_offline_and_never_calls_collect(self):
        with patch.object(sys, 'argv', ['collector', '--source-sha', 'a' * 40]), \
             patch.object(collector, 'preflight') as preflight, \
             patch.object(collector, 'collect') as collect, patch('sys.stdout', new_callable=io.StringIO) as out:
            self.assertEqual(0, collector.main())
            self.assertEqual('OFFLINE_SOURCE20_READY_NO_CONNECTION', json.loads(out.getvalue())['code'])
            preflight.assert_called_once()
            collect.assert_not_called()

    def test_draft_or_missing_release_ref_cannot_be_used_for_real_collection(self):
        for ref in ('', 'feature/draft', 'release/../main'):
            with patch.dict(os.environ, {'CANOVIA_RELEASE20_RELEASE_REF': ref}, clear=True), \
                 patch.object(collector.subprocess, 'run') as run:
                with self.assertRaises(ValueError):
                    collector.release_boundary('a' * 40)
                run.assert_not_called()

    def test_private_exception_is_redacted(self):
        with patch.object(sys, 'argv', ['collector', '--source-sha', 'a' * 40]), \
             patch.object(collector, 'preflight', side_effect=ValueError('PRIVATE_HOST_AND_SECRET')), \
             patch('sys.stdout', new_callable=io.StringIO) as out:
            self.assertEqual(2, collector.main())
            self.assertNotIn('PRIVATE_HOST_AND_SECRET', out.getvalue())

    def test_overprivileged_account_stops_before_any_ledger_or_metadata_query(self):
        with tempfile.TemporaryDirectory() as tmp:
            env = dict(CANOVIA_RELEASE20_OPERATOR_APPROVED='YES', CANOVIA_P0_TARGET_VERIFIED='YES',
                       CANOVIA_P0_LOGIN_PATH='synthetic_audit', CANOVIA_P0_DATABASE='synthetic_ci',
                       CANOVIA_P0_CA_FILE=tmp + '/ca.pem')
            Path(env['CANOVIA_P0_CA_FILE']).write_text('synthetic only')
            def query(args, **kwargs):
                self.assertIn('--ssl-mode=VERIFY_IDENTITY', args)
                self.assertEqual(b'SHOW GRANTS FOR CURRENT_USER()', kwargs['input'])
                kwargs['stdout'].write(b'GRANT ALL PRIVILEGES ON *.* TO `ci`@`localhost`\n')
                return SimpleNamespace(returncode=0)
            with patch.dict(os.environ, env, clear=True), patch.object(collector, 'preflight', return_value={}), \
                 patch.object(collector, 'release_boundary'), \
                 patch.object(collector.subprocess, 'run', side_effect=query) as run:
                with self.assertRaisesRegex(ValueError, 'NARROW_METADATA_GRANTS_REQUIRED'):
                    collector.collect('a' * 40)
                self.assertEqual(1, run.call_count)


if __name__ == '__main__':
    unittest.main()
