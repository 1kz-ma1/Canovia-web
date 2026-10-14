"""Disposable local-git tests for R27 (no cloud, production, or credentials)."""
from __future__ import annotations

import copy
import hashlib
import json
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest

sys.path.insert(0, str(Path(__file__).resolve().parents[2]))
from scripts.ops.g3_provider_evidence_inventory import (  # noqa: E402
    REQUIREMENTS, evaluate, load_manifest, InvalidInventory,
)


class EvidenceInventoryTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.repo = Path(self.tmp.name)
        self.git('init', '-q', '-b', 'main')
        self.git('config', 'user.name', 'CI')
        self.git('config', 'user.email', 'ci@example.invalid')
        self.manifest = {
            'schema': 'canovia.g3.provider_evidence_inventory.v1',
            'candidate_sha': '',
            'provider': 's3_candidate',
            'environment': 'disposable',
            'requirements': [],
        }
        self.documents = {}
        for requirement in REQUIREMENTS:
            document = 'docs/development/r27_' + requirement + '.md'
            evidence_path = self.repo / document
            evidence_path.parent.mkdir(parents=True, exist_ok=True)
            evidence_path.write_text('untrusted local fixture ' + requirement + '\n', encoding='utf-8')
            self.documents[requirement] = document
        self.git('add', '.')
        self.git('commit', '-qm', 'pinned fixture')
        self.manifest['candidate_sha'] = self.git('rev-parse', 'HEAD')
        for requirement, role in REQUIREMENTS.items():
            document = self.documents[requirement]
            digest = hashlib.sha256((self.repo / document).read_bytes()).hexdigest()
            self.manifest['requirements'].append({
                'id': requirement,
                'owner_role': role,
                'review_state': 'candidate',
                'evidence': {'path': document, 'sha256': digest},
            })

    def git(self, *args):
        return subprocess.check_output(
            ['git', '-C', str(self.repo), *args],
            text=True, stderr=subprocess.DEVNULL,
        ).strip()

    def outcome(self, manifest=None):
        return evaluate(self.repo, manifest if manifest is not None else self.manifest)

    def assert_no_permission(self, result):
        for key in ('provider_verified', 'generation_verified',
                    'replay_protection_verified', 'writer_fencing_verified',
                    'crash_recovery_verified', 'release_authorized',
                    'retry_authorized', 'delete_authorized',
                    'production_modified'):
            self.assertIs(result[key], False, key)
        self.assertNotIn('untrusted local fixture', json.dumps(result))
        self.assertNotIn(str(self.repo), json.dumps(result))

    def test_all_matching_docs_still_require_manual_review_and_never_authorize(self):
        result = self.outcome()
        self.assertEqual(result['result'], 'MANUAL_REVIEW_ONLY')
        self.assertEqual(result['pinned_document_count'], len(REQUIREMENTS))
        self.assertTrue(all(v == 'PIN_MATCH_UNTRUSTED'
                            for v in result['requirements'].values()))
        self.assert_no_permission(result)

    def test_missing_candidate_is_hold(self):
        self.manifest['requirements'][0]['review_state'] = 'missing'
        self.manifest['requirements'][0]['evidence'] = None
        result = self.outcome()
        self.assertEqual(result['result'], 'BLOCK')
        self.assertEqual(result['requirements']['provider_identity_and_trust_root'], 'MISSING')
        self.assert_no_permission(result)

    def test_tampered_hash_and_invalid_ref_are_denied(self):
        self.manifest['requirements'][0]['evidence']['sha256'] = 'f' * 64
        result = self.outcome()
        self.assertEqual(result['result'], 'BLOCK')
        self.assertEqual(result['pinned_document_count'], len(REQUIREMENTS) - 1)
        self.assert_no_permission(result)

        for bad in ('../secret.md', 'docs/development/../secret.md',
                    'https://example.invalid/secrets.md', 'docs/development/a//b.md',
                    'docs/development/secret.txt'):
            other = copy.deepcopy(self.manifest)
            other['requirements'][0]['evidence']['path'] = bad
            self.assertEqual(self.outcome(other)['result'], 'INVALID')

    def test_moved_commit_cannot_reuse_old_hash(self):
        (self.repo / self.documents['provider_identity_and_trust_root']).write_text('different untrusted data\n', encoding='utf-8')
        self.git('add', '.')
        self.git('commit', '-qm', 'changed artifact')
        candidate = copy.deepcopy(self.manifest)
        candidate['candidate_sha'] = self.git('rev-parse', 'HEAD')
        self.assertEqual(self.outcome(candidate)['result'], 'BLOCK')
        self.assertEqual(self.outcome()['result'], 'MANUAL_REVIEW_ONLY')

    def test_forged_approval_and_wrong_topology_are_invalid(self):
        for change in (
            {'approved': True}, {'release_authorized': True},
            {'schema': 'provider.verified'}, {'provider': 'aws_production'},
            {'environment': 'production'}, {'candidate_sha': 'HEAD'},
        ):
            m = copy.deepcopy(self.manifest)
            m.update(change)
            result = self.outcome(m)
            self.assertEqual(result['result'], 'INVALID')
            self.assert_no_permission(result)

    def test_duplicate_missing_or_forged_requirement_owner_is_invalid(self):
        for change in ('duplicate', 'missing', 'fake_role', 'approved_state',
                       'extra_verified', 'bad_missing_evidence'):
            m = copy.deepcopy(self.manifest)
            if change == 'duplicate':
                m['requirements'][0] = copy.deepcopy(m['requirements'][1])
            elif change == 'missing':
                m['requirements'].pop()
            elif change == 'fake_role':
                m['requirements'][0]['owner_role'] = 'owner_approved'
            elif change == 'approved_state':
                m['requirements'][0]['review_state'] = 'verified'
            elif change == 'extra_verified':
                m['requirements'][0]['verified'] = True
            else:
                m['requirements'][0]['review_state'] = 'missing'
            result = self.outcome(m)
            self.assertEqual(result['result'], 'INVALID')
            self.assert_no_permission(result)

    def test_absent_pinned_commit_and_bad_document_are_not_authority(self):
        m = copy.deepcopy(self.manifest)
        m['candidate_sha'] = '1' * 40
        self.assertEqual(self.outcome(m)['result'], 'BLOCK')
        m['candidate_sha'] = 'a' * 39
        self.assertEqual(self.outcome(m)['result'], 'INVALID')

    def test_shared_document_cannot_impersonate_two_independent_requirements(self):
        m = copy.deepcopy(self.manifest)
        m['requirements'][1]['evidence'] = copy.deepcopy(m['requirements'][0]['evidence'])
        result = self.outcome(m)
        self.assertEqual(result['result'], 'INVALID')
        self.assert_no_permission(result)

    def test_symlink_blob_is_rejected_even_with_matching_hash(self):
        outside = self.repo / 'outside.txt'
        outside.write_text('secret placeholder\n', encoding='utf-8')
        link = self.repo / 'docs/development/symlink_r27.md'
        link.symlink_to('../../outside.txt')
        self.git('add', '.')
        self.git('commit', '-qm', 'symlink blob')
        m = copy.deepcopy(self.manifest)
        m['candidate_sha'] = self.git('rev-parse', 'HEAD')
        m['requirements'][0]['evidence'] = {
            'path': 'docs/development/symlink_r27.md',
            'sha256': hashlib.sha256(b'../../outside.txt').hexdigest(),
        }
        result = self.outcome(m)
        self.assertEqual(result['result'], 'BLOCK')
        self.assert_no_permission(result)

    def test_duplicate_json_keys_and_nonexistent_manifest_fail_closed(self):
        document = self.repo / 'manifest.json'
        document.write_text('{"schema":"x","schema":"y"}', encoding='utf-8')
        with self.assertRaises(InvalidInventory):
            load_manifest(document)
        with self.assertRaises(InvalidInventory):
            load_manifest(self.repo / 'does_not_exist.json')


if __name__ == '__main__':
    unittest.main()
