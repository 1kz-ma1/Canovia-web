import copy
import importlib.util
from pathlib import Path
import sys
import unittest

sys.path.insert(0, str(Path(__file__).resolve().parents[2] / 'scripts/ci'))
import integrated_release20_audit as audit


class IntegratedRelease20Test(unittest.TestCase):
    def setUp(self):
        self.old = {f'old{i}': str(i) for i in range(94)}
        self.new = {**self.old, **{f'new{i}': str(i) for i in range(20)}}
        self.src = dict(baseline=self.old, candidate=self.new, migration_seal='sealed')
        self.physical = audit.snapshot('engine\t8.0\n'
                                      'table\tfixture\tBASE TABLE\tInnoDB\tutf8mb4_unicode_ci\n'
                                      'column\tfixture\t1\tid\tbigint unsigned\tNO\tNULL\tauto_increment\t\n'
                                      'index\tfixture\tPRIMARY\t0\t1\tid\t0\tBTREE\tYES\t\n')
        self.ref = dict(kind='DISPOSABLE_SOURCE_REFERENCE_ONLY', baseline_sha=audit.BASE,
                        migration_seal='sealed', engine='8.0',
                        baseline94=self.physical, candidate114=self.physical)

    def test_exact94_has_twenty_pending_but_never_authorizes(self):
        out = audit.compare(self.src, dict.fromkeys(self.old, 1), self.physical, self.ref)
        self.assertEqual('EXACT94_PENDING20_METADATA_REVIEW_ONLY', out['code'])
        self.assertEqual(20, out['pending_count'])
        self.assertFalse(out['release_authorized'])
        self.assertFalse(out['trigger_visibility_independently_verified'])

    def test_all114_is_not_restore_or_release_acceptance(self):
        out = audit.compare(self.src, dict.fromkeys(self.new, 1), self.physical, self.ref)
        self.assertEqual('EXACT114_METADATA_REVIEW_ONLY', out['code'])
        self.assertFalse(out['real_restore_verified'])
        self.assertTrue(out['requires_runtime_grants_binlog_review'])

    def test_unknown_history_and_missing_historical_records_deny(self):
        for rows in ({**dict.fromkeys(self.old, 1), 'PRIVATE_MARKER': 1},
                     dict.fromkeys(list(self.old)[1:], 1),
                     {**dict.fromkeys(self.old, 1), 'new0': 2}):
            out = audit.compare(self.src, rows, self.physical, self.ref)
            self.assertEqual('BLOCK', out['result'])
            self.assertNotIn('PRIVATE_MARKER', str(out))

    def test_every_physical_section_detects_partial_or_unexpected_ddl(self):
        for section in audit.WIDTHS:
            physical = copy.deepcopy(self.physical)
            physical['sections'][section]['sha256'] = 'unexpected'
            out = audit.compare(self.src, dict.fromkeys(self.new, 1), physical, self.ref)
            self.assertEqual('BLOCK', out['result'])

    def test_engine_source_missing_reference_and_unknown_reference_deny(self):
        for changes in (dict(engine='8.4'), dict(migration_seal='other'),
                        dict(kind='PRODUCTION_DUMP'), dict(baseline94={}), dict(baseline_sha='other')):
            ref = {**self.ref, **changes}
            self.assertEqual('BLOCK', audit.compare(
                self.src, dict.fromkeys(self.old, 1), self.physical, ref)['result'])

    def test_strict_metadata_rejects_empty_truncated_duplicates_and_injected_rows(self):
        valid = 'engine\t8.0\n'
        for raw in ('', valid, valid + valid, valid.replace('\n', '\r\n'),
                    valid + 'table\tsecret\n', valid + 'PRIVATE_MARKER\tx\n',
                    valid + 'table\tx\tBASE TABLE\tInnoDB\t\0\n'):
            with self.assertRaises(audit.InvalidLedger):
                audit.snapshot(raw)


if __name__ == '__main__':
    unittest.main()
