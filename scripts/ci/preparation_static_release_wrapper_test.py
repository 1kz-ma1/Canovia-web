"""Source-only B build activation, isolated CLI builds, and offline boundaries."""
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
import preparation_static_release_review as review


class AuthorizedSourceWrapper(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.tmp = tempfile.TemporaryDirectory(prefix='preparation-release-review-')
        cls.root = Path(cls.tmp.name)
        cls.source = b.clean_source()
        cls.tree = subprocess.check_output(['git', 'rev-parse', cls.source+'^{tree}'], cwd=b.ROOT, text=True).strip()
        cls.neutral_hash = b.build(cls.root / 'neutral-B', 'B')['sha256']

    @classmethod
    def tearDownClass(cls):
        cls.tmp.cleanup()

    def valid_files(self):
        return w.approved_public_files(self.source, 'B', self.neutral_hash,
                                       w.expected_approval(), self.tree)

    def clone_checkout(self, name):
        target = self.root / name
        subprocess.run(['git', 'clone', '--shared', '--no-checkout', str(b.ROOT), str(target)],
                       check=True, capture_output=True)
        subprocess.run(['git', 'checkout', '--detach', self.source], cwd=target,
                       check=True, capture_output=True)
        return target

    def command(self, checkout, **overrides):
        pins = dict(source_sha=self.source, source_tree=self.tree,
                    profile='B', artifact_sha256=self.neutral_hash)
        pins.update(overrides)
        return [sys.executable, '-B', str(checkout / 'scripts/ci' / Path(w.__file__).name)] + [
            v for key, value in pins.items() for v in ('--' + key.replace('_', '-'), value)]

    def test_missing_or_changed_source_authorization_cannot_be_elevated_by_env(self):
        with patch.dict(os.environ, {'ADOPTED_ORIGIN': w.OWNER_SELECTED_ORIGIN,
                                      'RELEASE_APPROVED': 'true',
                                      'PREPARATION_RELEASE_AUTHORIZATION': 'true'}):
            for authorization in (None, {}, dict(w.expected_approval(), profile='C'),
                                  dict(w.expected_approval(), origin='https://app.rpf-canovia.com'),
                                  dict(w.expected_approval(), release_approved=1)):
                with patch.object(w, 'RELEASE_AUTHORIZATION', authorization), self.assertRaisesRegex(
                        ValueError, 'OWNER_RELEASE_APPROVAL_REQUIRED'):
                    w.main()
        self.assertFalse((b.ROOT / w.PUBLISH_DIR).exists())

    def test_literal_authorization_has_only_owner_approved_b_origin(self):
        self.assertEqual(w.RELEASE_AUTHORIZATION, {
            'kind': 'PREPARATION_B_PUBLICATION_OWNER_APPROVAL_V1',
            'origin': 'https://start.rpf-canovia.com', 'profile': 'B', 'release_approved': True})
        with patch.object(w, 'OWNER_SELECTED_ORIGIN', 'https://app.rpf-canovia.com'):
            with self.assertRaisesRegex(ValueError, 'OWNER_RELEASE_APPROVAL_REQUIRED'):
                w.main()

    def test_real_cli_builds_two_isolated_checkouts_reproducibly_without_overwrite(self):
        expected = w.verify_public_files(self.valid_files(), self.source, self.tree,
                                         self.neutral_hash, w.expected_approval())
        for name in ('cli-one', 'cli-two'):
            checkout = self.clone_checkout(name)
            result = subprocess.run(self.command(checkout), cwd=checkout, capture_output=True, text=True)
            self.assertEqual(result.returncode, 0, result.stderr)
            self.assertEqual(result.stderr, '')
            logged = json.loads(result.stdout)
            self.assertIs(logged.pop('source_build_authorized'), True)
            self.assertEqual(logged, expected)
            destination = checkout / w.PUBLISH_DIR
            self.assertEqual(w.verify_public_directory(destination, self.source, self.tree,
                                                       self.neutral_hash, w.expected_approval()), expected)
            self.assertEqual({p.name for p in checkout.glob('.preparation-release-stage-*')}, set())
            second = subprocess.run(self.command(checkout), cwd=checkout, capture_output=True, text=True)
            self.assertEqual(second.returncode, 2)
            self.assertEqual(second.stdout, '')
            self.assertEqual(second.stderr, 'PREPARATION_RELEASE_BLOCK\n')
            self.assertEqual(w.read_public_directory(destination), self.valid_files())

    def test_cli_rejects_all_wrong_pins_profiles_and_unverified_options(self):
        checkout = self.clone_checkout('cli-negative')
        commands = [self.command(checkout, **change) for change in (
            {'source_sha': '0' * 40}, {'source_sha': self.source[:8]},
            {'source_tree': '0' * 40}, {'artifact_sha256': '0' * 64}, {'profile': 'C'})]
        commands += [self.command(checkout) + [key, 'SYNTHETIC_DO_NOT_LOG'] for key in (
            '--origin', '--authorization', '--plan', '--publish-directory')]
        with patch.dict(os.environ, {'ADOPTED_ORIGIN': 'https://app.rpf-canovia.com',
                                      'RELEASE_APPROVED': 'true',
                                      'PREPARATION_RELEASE_AUTHORIZATION': 'SYNTHETIC_DO_NOT_LOG'}):
            for command in commands:
                with self.subTest(command=command):
                    result = subprocess.run(command, cwd=checkout, capture_output=True, text=True)
                    self.assertEqual(result.returncode, 2)
                    self.assertEqual(result.stdout, '')
                    self.assertEqual(result.stderr, 'PREPARATION_RELEASE_BLOCK\n')
                    self.assertFalse((checkout / w.PUBLISH_DIR).exists())
                    self.assertEqual(list(checkout.glob('.preparation-release-stage-*')), [])

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
                           ('kind', 'SYNTHETIC_WRONG'), ('release_approved', 1)]:
            with self.subTest(key=key, value=value), self.assertRaises(ValueError):
                w.approved_public_files(self.source, 'B', self.neutral_hash,
                                        dict(approval, **{key: value}), self.tree)
        with self.assertRaises(ValueError):
            w.approved_public_files(self.source, 'C', self.neutral_hash, approval, self.tree)
        with self.assertRaises(ValueError):
            w.approved_public_files(self.source, 'B', '0' * 64, approval, self.tree)
        with self.assertRaises(ValueError):
            w.approved_public_files('0' * 40, 'B', self.neutral_hash, approval, self.tree)

    def test_exclusive_staging_never_overwrites(self):
        files = self.valid_files()
        with tempfile.TemporaryDirectory(prefix='review-stage-') as temp:
            dest = w.stage_files(temp, files, self.source, self.tree, self.neutral_hash, w.expected_approval())
            self.assertEqual(dest.name, '.render-static-B')
            self.assertEqual((dest / 'manifest.json').read_bytes(), files['manifest.json'])
            self.assertEqual((dest / 'src/settings.mjs').read_bytes(), files['src/settings.mjs'])
            with self.assertRaises(ValueError):
                w.stage_files(temp, files, self.source, self.tree, self.neutral_hash, w.expected_approval())
            self.assertEqual((dest / 'manifest.json').read_bytes(), files['manifest.json'])

    def test_staging_rejects_extras_symlinks_and_tamper(self):
        files = self.valid_files()
        with tempfile.TemporaryDirectory(prefix='review-stage-reject-') as temp:
            workspace = Path(temp)
            target = workspace / w.PUBLISH_DIR
            target.symlink_to(workspace / 'missing')
            with self.assertRaises(ValueError):
                w.stage_files(workspace, files, self.source, self.tree, self.neutral_hash, w.expected_approval())
            target.unlink()
            for name, payload in [('secret.php', b'SYNTHETIC'),
                                  ('src/settings.mjs', b'')]:
                changed = dict(files, **{name: payload})
                with self.subTest(name=name), self.assertRaises(ValueError):
                    w.stage_files(workspace, changed, self.source, self.tree, self.neutral_hash, w.expected_approval())
                self.assertFalse(target.exists())

    def test_nonempty_tamper_and_resealed_manifest_rejected(self):
        files = self.valid_files()
        for name in ('src/app.mjs', 'src/settings.mjs', '_headers', 'index.html', 'manifest.json'):
            changed = dict(files)
            changed[name] += b'\nSYNTHETIC_TAMPER'
            if name != 'manifest.json':
                manifest = json.loads(changed['manifest.json'])
                manifest['files'][name] = b.digest(changed[name])
                changed['manifest.json'] = b.canonical_manifest(manifest)
            with tempfile.TemporaryDirectory() as temp, self.subTest(name=name):
                with self.assertRaisesRegex(ValueError, 'PUBLIC_BYTES_MISMATCH'):
                    w.stage_files(temp, changed, self.source, self.tree,
                                  self.neutral_hash, w.expected_approval())
                self.assertEqual(list(Path(temp).iterdir()), [])

    def test_tree_and_missing_asset_rejected(self):
        with self.assertRaisesRegex(ValueError, 'SOURCE_TREE_MISMATCH'):
            w.approved_public_files(self.source, 'B', self.neutral_hash,
                                    w.expected_approval(), '0' * 40)
        files = self.valid_files()
        del files['assets/st.webp']
        with self.assertRaises(ValueError):
            w.verify_public_files(files, self.source, self.tree, self.neutral_hash, w.expected_approval())

    def test_failure_does_not_leave_final_or_partial_stage(self):
        files = self.valid_files()
        with tempfile.TemporaryDirectory() as temp:
            # Fail during readback after files have already been written.
            real_read = b.public_file
            def damaged(root, name, cap):
                if '.preparation-release-stage-' in str(root):
                    raise OSError('SYNTHETIC_DISK_FAILURE')
                return real_read(root, name, cap)
            with patch.object(b, 'public_file', side_effect=damaged), self.assertRaises(OSError):
                w.stage_files(temp, files, self.source, self.tree, self.neutral_hash, w.expected_approval())
            self.assertEqual(list(Path(temp).iterdir()), [])

    def test_directory_rejects_links_extras_missing_and_changed_bytes(self):
        files = self.valid_files()
        for mode in ('symlink', 'hardlink', 'extra', 'missing', 'tamper', 'extra-dir'):
            with tempfile.TemporaryDirectory() as temp, self.subTest(mode=mode):
                dest = w.stage_files(temp, files, self.source, self.tree, self.neutral_hash, w.expected_approval())
                path = dest / 'src/app.mjs'
                if mode == 'symlink':
                    path.unlink(); path.symlink_to(dest / 'src/data.mjs')
                elif mode == 'hardlink':
                    os.link(path, Path(temp) / 'second-link')
                elif mode == 'extra':
                    (dest / 'secret.env').write_bytes(b'SYNTHETIC')
                elif mode == 'missing':
                    path.unlink()
                elif mode == 'extra-dir':
                    (dest / 'extra').mkdir()
                else:
                    path.write_bytes(b'SYNTHETIC_NONEMPTY_TAMPER')
                with self.assertRaises((ValueError, OSError)):
                    w.verify_public_directory(dest, self.source, self.tree, self.neutral_hash, w.expected_approval())

    def test_symlinked_workspace_parent_rejected_without_output(self):
        files = self.valid_files()
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            real = root / 'real'
            real.mkdir()
            alias = root / 'alias'
            alias.symlink_to(real, target_is_directory=True)
            with self.assertRaises(ValueError):
                w.stage_files(alias, files, self.source, self.tree, self.neutral_hash, w.expected_approval())
            self.assertEqual(list(real.iterdir()), [])
            w.stage_files(real, files, self.source, self.tree, self.neutral_hash, w.expected_approval())
            with self.assertRaisesRegex(ValueError, 'BAD_PUBLIC_DIRECTORY'):
                w.verify_public_directory(alias / w.PUBLISH_DIR, self.source, self.tree, self.neutral_hash, w.expected_approval())

    def test_offline_receipt_reproducible_but_cannot_authorize_deployment(self):
        args = (self.source, self.tree, self.neutral_hash, self.root / 'neutral-B.zip')
        with tempfile.TemporaryDirectory() as temp:
            output = Path(temp) / 'review'
            receipt = review.review(*args, output)
            self.assertEqual(receipt, review.review(*args, output, verify=True))
            self.assertEqual(receipt['public_files'], 25)
            self.assertNotEqual(receipt['neutral_zip_sha256'], receipt['public_inventory_sha256'])
            self.assertFalse(receipt['deployment_authorized'])
            self.assertEqual(receipt['deployed_artifact'], 'UNVERIFIED')
            self.assertEqual(w.RELEASE_AUTHORIZATION, w.expected_approval())
            other = Path(temp) / 'repeat'
            self.assertEqual(receipt, review.review(*args, other))
            with self.assertRaises(ValueError):
                review.review(*args, output)
            (output / 'review.json').write_bytes(b'{}\n')
            with self.assertRaisesRegex(ValueError, 'REVIEW_RECEIPT_MISMATCH'):
                review.review(*args, output, verify=True)


if __name__ == '__main__':
    unittest.main()
