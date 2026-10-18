import copy
import json
import os
from pathlib import Path
import sys
import tempfile
import unittest
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[2] / 'scripts/ci'))
sys.path.insert(0, str(Path(__file__).resolve().parents[2] / 'scripts/ops'))
import integrated_release20_audit as audit
import integrated_release20_diagnose as diag
import integrated_release20_semantics as sem
import integrated_release20_diagnostic_collect as collect


class ConstraintSemanticsTest(unittest.TestCase):
    def setUp(self):
        self.legacy = ('engine\t8.0\n'
                       'table\tPRIVATE_TABLE\tBASE TABLE\tInnoDB\tutf8mb4_unicode_ci\n'
                       'column\tPRIVATE_TABLE\t1\ta\tint\tNO\tNULL\t\t\n'
                       'index\tPRIVATE_TABLE\tPRIMARY\t0\t1\ta\t0\tBTREE\tYES\t\n'
                       'index\tPRIVATE_TABLE\tPRIVATE_INDEX\t1\t1\ta\t0\tBTREE\tYES\t\n'
                       'index\tPRIVATE_TABLE\tPRIVATE_INDEX\t1\t2\tb\t0\tBTREE\tYES\t\n'
                       'index\t' + '\t'.join(sem.DEPENDENCY_KEY) + '\t0\t1\tidentity_fingerprint\t0\tBTREE\tYES\t\n'
                       'foreign_key\tPRIVATE_TABLE\tPRIVATE_FK\t1\ta\tPRIVATE_PARENT\tx\tRESTRICT\tCASCADE\tLOCAL\n'
                       'foreign_key\tPRIVATE_TABLE\tPRIVATE_FK\t2\tb\tPRIVATE_PARENT\ty\tRESTRICT\tCASCADE\tLOCAL\n')
        self.raw = self.supplement(self.legacy)
        old = {f'old{i}': str(i) for i in range(94)}
        self.src = dict(baseline=old, candidate={**old, **{f'new{i}': str(i) for i in range(20)}}, migration_seal='sealed')
        self.ledger = dict.fromkeys(old, 1)
        physical = audit.snapshot(self.legacy)
        self.ref = dict(kind='DISPOSABLE_SOURCE_REFERENCE_ONLY', baseline_sha=audit.BASE,
                        migration_seal='sealed', engine='8.0', baseline94=physical, candidate114=physical)
        self.aux = dict(kind=diag.KIND, candidate_sha=diag.CANDIDATE, baseline_sha=audit.BASE,
                        migration_seal='sealed', engine='8.0', baseline94=diag.projections(self.legacy), candidate114=diag.projections(self.legacy))
        parsed, _ = sem.parse(self.raw, self.legacy)
        self.expected = dict(kind=sem.KIND, candidate_sha=diag.CANDIDATE, baseline_sha=audit.BASE,
                             migration_seal='sealed', engine='8.0', baseline94=parsed, candidate114=parsed)

    @staticmethod
    def supplement(legacy):
        out = 'semantic_version\t1\n'
        for line in legacy.splitlines():
            row = line.split('\t')
            if row[0] == 'index':
                out += 'semantic_' + line + '\tA\t\n'
            elif row[0] == 'foreign_key':
                out += 'semantic_' + line + '\tNONE\t' + row[3] + '\n'
        return out

    def diagnose(self, legacy=None, raw=None, reference=None):
        legacy = self.legacy if legacy is None else legacy
        return sem.semantic_diagnose(self.src, self.ledger, legacy, self.ref, self.aux,
                                     self.supplement(legacy) if raw is None else raw,
                                     self.expected if reference is None else reference)

    def test_match_retains_unverified_trigger_and_all_release_gates(self):
        out = self.diagnose()
        self.assertEqual('REVIEW_REQUIRED', out['result'])
        self.assertFalse(out['release_authorized'])
        self.assertFalse(out['trigger_visibility_independently_verified'])
        self.assertEqual('EXPECTED_NAME_AND_DEFINITION', out['migration_name_dependency']['classification'])

    def test_only_constraint_names_differ_but_strict_block_is_never_relaxed(self):
        legacy = self.legacy.replace('PRIVATE_INDEX', 'OTHER_PRIVATE_INDEX').replace('PRIVATE_FK', 'OTHER_PRIVATE_FK')
        out = self.diagnose(legacy)
        self.assertEqual('BLOCK', out['result'])
        self.assertEqual(2, out['mismatched_section_count'])
        self.assertTrue(all(s['classification'] == 'NAME_ONLY_DIFFERENCE' for s in out['semantic_sections']))
        self.assertNotIn('PRIVATE', json.dumps(out))
        self.assertNotIn('sha256', json.dumps(out))

    def test_named_transition_alias_is_a_separate_migration_block(self):
        out = self.diagnose(self.legacy.replace(sem.DEPENDENCY_KEY[1], 'PRIVATE_ALIAS'))
        self.assertEqual('NAME_ONLY_DIFFERENCE', out['semantic_sections'][0]['classification'])
        self.assertEqual('SEMANTIC_ALIAS_ONLY_BLOCK', out['migration_name_dependency']['classification'])
        self.assertEqual('BLOCK', out['result'])

    def test_all_index_properties_and_primary_status_are_compared(self):
        for pos, value in ((1, 'PRIVATE_OTHER_TABLE'), (3, '0'), (5, 'c'), (6, '8'),
                           (7, 'HASH'), (8, 'NO'), (9, '612B31')):
            lines = self.legacy.splitlines()
            for i, line in enumerate(lines):
                row = line.split('\t')
                if row[0] == 'index' and row[2] == 'PRIVATE_INDEX':
                    row[pos] = value
                    lines[i] = '\t'.join(row)
            out = self.diagnose('\n'.join(lines) + '\n')
            self.assertEqual('SEMANTIC_DEFINITION_DIFFERENCE', out['semantic_sections'][0]['classification'])
        # Renaming PRIMARY into an ordinary unique index is NOT name-only.
        out = self.diagnose(self.legacy.replace('\tPRIMARY\t', '\tPRIVATE_UNIQUE\t'))
        self.assertEqual('SEMANTIC_DEFINITION_DIFFERENCE', out['semantic_sections'][0]['classification'])

    def test_direction_and_nullability_supplement_can_tighten_legacy_match(self):
        for suffix in ('\tD\t\n', '\tA\tYES\n'):
            raw = self.raw.replace('\tA\t\n', suffix)
            out = self.diagnose(raw=raw)
            self.assertEqual('BLOCK', out['result'])
            self.assertEqual('SUPPLEMENTAL_CONSTRAINT_DIFFERENCE', out['code'])

    def test_all_foreign_key_semantics_are_compared(self):
        for pos, value in ((1, 'PRIVATE_CHILD'), (4, 'other_child'), (5, 'PRIVATE_OTHER_PARENT'),
                           (6, 'other_parent'), (7, 'CASCADE'), (8, 'RESTRICT'), (9, 'FOREIGN')):
            lines = self.legacy.splitlines()
            for i, line in enumerate(lines):
                row = line.split('\t')
                if row[0] == 'foreign_key':
                    row[pos] = value
                    lines[i] = '\t'.join(row)
            out = self.diagnose('\n'.join(lines) + '\n')
            self.assertEqual('SEMANTIC_DEFINITION_DIFFERENCE', out['semantic_sections'][1]['classification'])
        for old, new in (('\tNONE\t', '\tFULL\t'), ('\tNONE\t2\n', '\tNONE\t1\n')):
            out = self.diagnose(raw=self.raw.replace(old, new))
            self.assertEqual('SEMANTIC_DEFINITION_DIFFERENCE', out['semantic_sections'][1]['classification'])

    def test_grouping_and_multiplicity_prevent_equal_count_cancellation(self):
        # Replace the composite (a,b) index by duplicate single-a parts: same
        # total row count, different object count; never flatten into a set.
        changed = self.legacy.replace('PRIVATE_INDEX\t1\t2\tb', 'PRIVATE_SECOND\t1\t1\ta')
        out = self.diagnose(changed)
        self.assertEqual('SEMANTIC_DEFINITION_DIFFERENCE', out['semantic_sections'][0]['classification'])
        self.assertEqual(out['semantic_sections'][0]['expected_row_count'], out['semantic_sections'][0]['observed_row_count'])
        # Same object and row counts, replacing b by a also must be rejected.
        changed = self.legacy.replace('PRIVATE_INDEX\t1\t2\tb', 'PRIVATE_INDEX\t1\t2\ta')
        out = self.diagnose(changed)
        self.assertEqual('SEMANTIC_DEFINITION_DIFFERENCE', out['semantic_sections'][0]['classification'])

    def test_incomplete_duplicate_or_cross_snapshot_input_is_rejected(self):
        first = self.raw.splitlines()[1] + '\n'
        for raw in ('', self.raw[:-1], self.raw + first, self.raw.replace('semantic_version\t1\n', ''),
                    self.raw.replace('PRIVATE_PARENT', 'PRIVATE_OTHER_PARENT'), self.raw + 'PRIVATE_UNKNOWN\tx\n'):
            out = self.diagnose(raw=raw)
            self.assertEqual('BLOCK', out['result'])
            self.assertEqual('SEMANTIC_REFERENCE_OR_PRIVATE_INPUT_REJECTED', out['semantic_code'])
            self.assertNotIn('PRIVATE', json.dumps(out).replace('PRIVATE_INPUT', ''))

    def test_semantic_reference_cannot_override_original_reference_or_source(self):
        for key in ('kind', 'candidate_sha', 'baseline_sha', 'migration_seal', 'engine'):
            ref = {**self.expected, key: 'PRIVATE_WRONG'}
            self.assertEqual('BLOCK', self.diagnose(reference=ref)['result'])
        ref = copy.deepcopy(self.expected)
        ref['baseline94']['physical']['sections']['index']['sha256'] = 'PRIVATE_WRONG'
        self.assertEqual('BLOCK', self.diagnose(reference=ref)['result'])

    def test_approved_mock_collector_binds_both_metadata_streams_without_extra_reads(self):
        # No network: mock only the SQL transport; execute real parsing,
        # splitting, grant validation, semantic comparison and output assembly.
        old = {f'2026_01_01_{i:06d}_synthetic': str(i) for i in range(94)}
        src = dict(baseline=old, candidate={**old, **{f'new{i}': str(i) for i in range(20)}}, migration_seal='sealed')
        ledger = ''.join('migration_ledger\t' + key + '\t1\n' for key in old)
        legacy = self.legacy.replace('PRIVATE_INDEX', 'PRIVATE_ALIAS').replace('PRIVATE_FK', 'PRIVATE_ALIAS_FK')
        combined = legacy + self.supplement(legacy)
        grants = 'GRANT REFERENCES ON `synthetic`.* TO `ci`@`localhost`\nGRANT SELECT ON `synthetic`.`migrations` TO `ci`@`localhost`\n'
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            for folder, data in (('integrated_release20_reference', self.ref),
                                 ('integrated_release20_diagnostic_reference', self.aux),
                                 ('integrated_release20_semantic_reference', self.expected)):
                path = root / 'scripts/ci' / folder / '8.0.json'
                path.parent.mkdir(parents=True)
                path.write_text(json.dumps(data))
            sql = root / 'scripts/sql'
            sql.mkdir()
            for name, text in (('p0_mysql_readonly_full_migration_ledger.sql', 'LEDGER_SELECT'),
                               ('integrated_release20_metadata.sql', 'LEGACY_SELECT'),
                               ('integrated_release20_semantic_metadata.sql', 'SUPPLEMENTAL_SELECT')):
                (sql / name).write_text(text)
            ca = root / 'ca.pem'
            ca.write_text('synthetic')
            env = dict(CANOVIA_RELEASE20_SEMANTIC_APPROVED='YES', CANOVIA_P0_TARGET_VERIFIED='YES',
                       CANOVIA_RELEASE20_RELEASE_REF=diag.RELEASE_REF, CANOVIA_P0_LOGIN_PATH='synthetic',
                       CANOVIA_P0_DATABASE='synthetic', CANOVIA_P0_CA_FILE=str(ca))
            for data in (combined, legacy):
                with patch.dict(os.environ, env, clear=True), patch.object(collect, 'ROOT', root), \
                        patch.object(collect, 'preflight', return_value=src), patch.object(collect, 'release_boundary'), \
                        patch.object(collect, 'private_query', side_effect=[grants, ledger, data]) as query:
                    out = collect.collect('a' * 40, diag.CANDIDATE)
                    self.assertEqual(3, query.call_count)
                    self.assertEqual('LEGACY_SELECT\nSUPPLEMENTAL_SELECT', query.call_args_list[2].args[1])
                    self.assertEqual('BLOCK', out['result'])
                    if data == combined:
                        self.assertTrue(all(s['classification'] == 'NAME_ONLY_DIFFERENCE' for s in out['semantic_sections']))
                        self.assertFalse(out['trigger_visibility_independently_verified'])
                    else:
                        self.assertEqual('SEMANTIC_REFERENCE_OR_PRIVATE_INPUT_REJECTED', out['semantic_code'])
                    self.assertNotIn('PRIVATE', json.dumps(out).replace('PRIVATE_INPUT', ''))


if __name__ == '__main__':
    unittest.main()
