import copy
import importlib.util
import io
import json
import os
from pathlib import Path
import sys
import tempfile
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[2]
sys.path[:0] = [str(ROOT / 'scripts/ci'), str(ROOT / 'scripts/ops')]
import integrated_release20_audit as audit
import integrated_release20_diagnose as diag
import integrated_release20_diagnostic_collect as collect

RAW = ('engine\t8.0\n'
       'table\tPRIVATE_OBJECT\tBASE TABLE\tInnoDB\tutf8mb4_unicode_ci\n'
       'column\tPRIVATE_OBJECT\t1\tPRIVATE_COLUMN\tvarchar(100)\tNO\tNULL\t\tutf8mb4_unicode_ci\n'
       'index\tPRIVATE_OBJECT\tPRIVATE_INDEX\t0\t1\tPRIVATE_COLUMN\t0\tBTREE\tYES\t\n')


class DiagnosisTest(unittest.TestCase):
    def setUp(self):
        self.old = {f'old{i}': str(i) for i in range(94)}
        self.new = {**self.old, **{f'new{i}': str(i) for i in range(20)}}
        self.src = dict(baseline=self.old, candidate=self.new, migration_seal='sealed')
        self.ledger = dict.fromkeys(self.old, 1)
        self.ref = dict(kind='DISPOSABLE_SOURCE_REFERENCE_ONLY', baseline_sha=audit.BASE,
                        migration_seal='sealed', engine='8.0',
                        baseline94=audit.snapshot(RAW), candidate114=audit.snapshot(RAW))
        self.aux = dict(kind=diag.KIND, candidate_sha=diag.CANDIDATE, baseline_sha=audit.BASE,
                        migration_seal='sealed', engine='8.0',
                        baseline94=diag.projections(RAW), candidate114=diag.projections(RAW))

    def diagnose(self, raw=RAW, **changes):
        return diag.diagnose(changes.get('src', self.src), changes.get('ledger', self.ledger), raw,
                             changes.get('ref', self.ref), changes.get('aux', self.aux))

    def test_match_retains_all_original_gates(self):
        expected = audit.compare(self.src, self.ledger, audit.snapshot(RAW), self.ref)
        out = self.diagnose()
        for key, value in expected.items():
            self.assertEqual(value, out[key])
        self.assertFalse(out['diagnosis_authorizes_release'])
        self.assertEqual('ABSENCE_OR_INVISIBILITY_NOT_DISTINGUISHABLE', out['trigger_visibility'])

    def test_collation_only_still_blocks_two_sections(self):
        out = self.diagnose(RAW.replace('utf8mb4_unicode_ci', 'utf8mb4_0900_ai_ci'))
        self.assertEqual('BLOCK', out['result'])
        self.assertEqual(2, out['mismatched_section_count'])
        self.assertEqual({'table', 'column'}, {s['section'] for s in out['sections'] if s['classification'] == 'COLLATION_ONLY_DIFFERENCE'})
        self.assertNotIn('PRIVATE', json.dumps(out))
        self.assertNotIn('utf8mb4', json.dumps(out))
        self.assertNotIn('sha256', json.dumps(out))

    def test_definition_count_identity_and_mixed_differences(self):
        cases = [(RAW.replace('varchar(100)', 'varchar(200)'), 'column', 'DEFINITION_DIFFERENCE'),
                 (RAW.replace('PRIVATE_INDEX', 'OTHER_PRIVATE_INDEX'), 'index', 'OBJECT_IDENTITY_DIFFERENCE'),
                 (RAW + 'trigger\tPRIVATE_OBJECT\tPRIVATE_TRIGGER\tUPDATE\tBEFORE\t1\t00\n', 'trigger', 'COUNT_DIFFERENCE')]
        for raw, section, classification in cases:
            out = self.diagnose(raw)
            self.assertEqual('BLOCK', out['result'])
            self.assertEqual(classification, next(s for s in out['sections'] if s['section'] == section)['classification'])
            self.assertNotIn('PRIVATE', json.dumps(out))
        out = self.diagnose(RAW.replace('varchar(100)', 'varchar(200)').replace('utf8mb4_unicode_ci', 'utf8mb4_0900_ai_ci'))
        column = next(s for s in out['sections'] if s['section'] == 'column')
        self.assertEqual('DEFINITION_DIFFERENCE', column['classification'])
        self.assertTrue(column['collation_difference'])

    def test_partial_or_unknown_ledger_does_not_guess_reference(self):
        for ledger in ({'PRIVATE_LEDGER': 1}, dict(list(self.ledger.items())[:-1])):
            out = self.diagnose(ledger=ledger)
            self.assertEqual('BLOCK', out['result'])
            self.assertEqual('LEDGER_NOT_AT_REFERENCE_BOUNDARY', out['diagnosis_code'])
            self.assertNotIn('sections', out)
            self.assertNotIn('PRIVATE', json.dumps(out))

    def test_reference_tampering_cannot_relax_a_block(self):
        for field in ('kind', 'candidate_sha', 'baseline_sha', 'migration_seal', 'engine'):
            aux = {**self.aux, field: 'PRIVATE_BAD'}
            out = self.diagnose(aux=aux)
            self.assertEqual('BLOCK', out['result'])
            self.assertNotIn('PRIVATE', json.dumps(out))
        aux = copy.deepcopy(self.aux)
        aux['baseline94']['physical']['sections']['column']['sha256'] = 'private'
        self.assertEqual('DIAGNOSTIC_REFERENCE_REJECTED', self.diagnose(aux=aux)['diagnosis_code'])
        aux = copy.deepcopy(self.aux)
        aux['baseline94']['projections']['column']['identity'] = 'PRIVATE'
        self.assertEqual('BLOCK', self.diagnose(aux=aux)['result'])

    def test_malformed_rows_and_duplicate_identities_rejected(self):
        for raw in (RAW[:-1], RAW + 'PRIVATE_ROW\tx\n', RAW.replace('NO', 'N\0O'),
                    RAW + 'column\tPRIVATE_OBJECT\t2\tPRIVATE_COLUMN\tvarchar(200)\tNO\tNULL\t\tutf8mb4_unicode_ci\n'):
            with self.assertRaises(audit.InvalidLedger):
                self.diagnose(raw)


class DiagnosticCollectorTest(unittest.TestCase):
    def test_missing_new_approval_ci_and_wrong_target_never_query(self):
        for env in ({}, {'CANOVIA_RELEASE20_OPERATOR_APPROVED': 'YES', 'CANOVIA_P0_TARGET_VERIFIED': 'YES'},
                    {'CANOVIA_RELEASE20_DIAGNOSTIC_APPROVED': 'YES', 'CANOVIA_P0_TARGET_VERIFIED': 'YES'},
                    {'CI': 'true', 'CANOVIA_RELEASE20_SEMANTIC_APPROVED': 'YES', 'CANOVIA_P0_TARGET_VERIFIED': 'YES'}):
            with patch.dict(os.environ, env, clear=True), patch.object(collect, 'private_query') as query:
                with self.assertRaises(ValueError):
                    collect.collect('a' * 40, diag.CANDIDATE)
                query.assert_not_called()

    def test_default_offline_no_query_and_redacted_errors(self):
        argv = ['diagnose', '--tooling-sha', 'a' * 40, '--candidate-sha', diag.CANDIDATE]
        with patch.object(sys, 'argv', argv), patch.object(collect, 'preflight'), \
                patch.object(collect, 'collect') as call, patch('sys.stdout', new_callable=io.StringIO) as output:
            self.assertEqual(0, collect.main())
            self.assertEqual('OFFLINE_DIAGNOSTIC_READY_NO_CONNECTION', json.loads(output.getvalue())['code'])
            call.assert_not_called()
        for args in (argv, argv + ['--PRIVATE_ARGUMENT']):
            with patch.object(sys, 'argv', args), patch.object(collect, 'preflight', side_effect=ValueError('PRIVATE_HOST_SECRET')), \
                    patch('sys.stdout', new_callable=io.StringIO) as out, patch('sys.stderr', new_callable=io.StringIO) as err:
                self.assertEqual(2, collect.main())
                self.assertNotIn('PRIVATE', out.getvalue().replace('PRIVATE_INPUT', ''))
                self.assertEqual('', err.getvalue())

    def test_unknown_candidate_stops_before_source_or_network(self):
        with patch.object(collect, 'original_preflight') as preflight:
            with self.assertRaises(ValueError):
                collect.preflight('a' * 40, 'b' * 40)
            preflight.assert_not_called()

    def test_overprivileged_account_stops_before_ledger(self):
        with tempfile.TemporaryDirectory() as tmp:
            ca = Path(tmp) / 'ca.pem'
            ca.write_text('synthetic')
            env = dict(CANOVIA_RELEASE20_SEMANTIC_APPROVED='YES', CANOVIA_P0_TARGET_VERIFIED='YES',
                       CANOVIA_RELEASE20_RELEASE_REF=diag.RELEASE_REF, CANOVIA_P0_LOGIN_PATH='synthetic',
                       CANOVIA_P0_DATABASE='synthetic', CANOVIA_P0_CA_FILE=str(ca))
            with patch.dict(os.environ, env, clear=True), patch.object(collect, 'preflight'), \
                    patch.object(collect, 'release_boundary'), patch.object(collect, 'private_query', return_value='PRIVATE_OVERBROAD') as query:
                with self.assertRaises(ValueError):
                    collect.collect('a' * 40, diag.CANDIDATE)
                self.assertEqual(1, query.call_count)
                self.assertIn('--ssl-mode=VERIFY_IDENTITY', query.call_args.args[0])

    def test_query_failure_does_not_echo_raw_output_and_removes_private_files(self):
        directories = []
        def fail(args, **kwargs):
            directories.append(Path(kwargs['stdout'].name).parent)
            kwargs['stdout'].write(b'PRIVATE_TABLE_AND_DATA')
            kwargs['stderr'].write(b'PRIVATE_HOST_SECRET')
            raise subprocess.TimeoutExpired(args, 60)
        import subprocess
        with patch.object(collect.subprocess, 'run', side_effect=fail), patch('sys.stdout', new_callable=io.StringIO) as out:
            with self.assertRaises(subprocess.TimeoutExpired):
                collect.private_query(['mysql'], 'SELECT 1')
            self.assertEqual('', out.getvalue())
        self.assertTrue(directories)
        self.assertTrue(all(not p.exists() for p in directories))


if __name__ == '__main__':
    unittest.main()
