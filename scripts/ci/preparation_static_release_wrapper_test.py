"""Synthetic offline review of the dormant Render publication wrapper."""
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest
from unittest.mock import patch

import preparation_static_build as b
import preparation_static_release_wrapper as w


class DormantWrapper(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.tmp = tempfile.TemporaryDirectory(prefix='preparation-release-review-')
        cls.root = Path(cls.tmp.name)
        cls.source = b.clean_source()
        cls.neutral_hash = b.build(cls.root / 'neutral-B', 'B')['sha256']

    @classmethod
    def tearDownClass(cls):
        cls.tmp.cleanup()

    def valid_files(self):
        return w.approved_public_files(self.source, 'B', self.neutral_hash,
                                       w.expected_approval())

    def test_default_cli_remains_blocked_even_with_env_overrides(self):
        with patch.dict(os.environ, {'ADOPTED_ORIGIN': w.OWNER_SELECTED_ORIGIN,
                                      'RELEASE_APPROVED': 'true',
                                      'PREPARATION_RELEASE_AUTHORIZATION': 'true'}):
            command = [sys.executable, '-B', str(Path(w.__file__)),
                       '--source-sha', self.source, '--profile', 'B',
                       '--artifact-sha256', self.neutral_hash]
            result = subprocess.run(command, cwd=b.ROOT, capture_output=True, text=True)
        self.assertEqual(result.returncode, 2)
        self.assertEqual(result.stdout, '')
        self.assertEqual(result.stderr, 'PREPARATION_RELEASE_BLOCK\n')
        self.assertFalse((b.ROOT / w.PUBLISH_DIR).exists())

    def test_exact_review_assets_have_only_owner_selected_origin(self):
        files = self.valid_files()
        self.assertEqual(len(files), 25)
        self.assertEqual(set(files), set(b.candidate_files(self.source, 'B')[0]) | {'manifest.json'})
        self.assertEqual(files['src/settings.mjs'],
                         b"export const PROFILE='B';\nexport const ADOPTED_ORIGIN='https://start.rpf-canovia.com';\n")
        manifest = json.loads(files['manifest.json'])
        self.assertEqual(manifest['source_sha'], self.source)
        self.assertEqual(manifest['adopted_origin'], w.OWNER_SELECTED_ORIGIN)
        self.assertTrue(manifest['release_approved'])
        self.assertEqual(manifest['neutral_artifact_sha256'], self.neutral_hash)
        self.assertEqual(manifest['files']['src/settings.mjs'],
                         b.digest(files['src/settings.mjs']))
        for key in ('sw.js', '.env', 'render.yaml', 'admin.php'):
            self.assertNotIn(key, files)
        self.assertIn(b"connect-src 'none'", files['_headers'])
        self.assertFalse(any(x.endswith('.php') for x in files))

    def test_foreign_authorizations_and_wrong_profiles_rejected(self):
        approval = w.expected_approval()
        for key, value in [('origin', 'https://start.canovia.com'),
                           ('origin', 'http://start.rpf-canovia.com'),
                           ('origin', 'https://app.rpf-canovia.com'),
                           ('profile', 'C'), ('release_approved', False),
                           ('kind', 'SYNTHETIC_WRONG')]:
            with self.subTest(key=key, value=value), self.assertRaises(ValueError):
                w.approved_public_files(self.source, 'B', self.neutral_hash,
                                        dict(approval, **{key: value}))
        with self.assertRaises(ValueError):
            w.approved_public_files(self.source, 'C', self.neutral_hash, approval)
        with self.assertRaises(ValueError):
            w.approved_public_files(self.source, 'B', '0' * 64, approval)
        with self.assertRaises(ValueError):
            w.approved_public_files('0' * 40, 'B', self.neutral_hash, approval)

    def test_exclusive_staging_never_overwrites(self):
        files = self.valid_files()
        with tempfile.TemporaryDirectory(prefix='review-stage-') as temp:
            dest = w.stage_files(temp, files)
            self.assertEqual(dest.name, '.render-static-B')
            self.assertEqual((dest / 'manifest.json').read_bytes(), files['manifest.json'])
            self.assertEqual((dest / 'src/settings.mjs').read_bytes(), files['src/settings.mjs'])
            with self.assertRaises(ValueError):
                w.stage_files(temp, files)
            self.assertEqual((dest / 'manifest.json').read_bytes(), files['manifest.json'])

    def test_staging_rejects_extras_symlinks_and_tamper(self):
        files = self.valid_files()
        with tempfile.TemporaryDirectory(prefix='review-stage-reject-') as temp:
            workspace = Path(temp)
            target = workspace / w.PUBLISH_DIR
            target.symlink_to(workspace / 'missing')
            with self.assertRaises(ValueError):
                w.stage_files(workspace, files)
            target.unlink()
            for name, payload in [('secret.php', b'SYNTHETIC'),
                                  ('src/settings.mjs', b'')]:
                changed = dict(files, **{name: payload})
                with self.subTest(name=name), self.assertRaises(ValueError):
                    w.stage_files(workspace, changed)
                self.assertFalse(target.exists())


if __name__ == '__main__':
    unittest.main()
