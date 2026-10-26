"""Offline-only contracts: synthetic settings and frozen Git assets, no HTTP."""
import copy
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest
from unittest.mock import patch
import zipfile

import preparation_static_build as b
import preparation_static_render as r


class RenderPreparation(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.temp = tempfile.TemporaryDirectory(prefix='render-offline-test-')
        cls.root = Path(cls.temp.name)
        cls.source = b.clean_source()
        cls.hashes = {p: b.build(cls.root / p, p)['sha256'] for p in ('B', 'C')}

    @classmethod
    def tearDownClass(cls):
        cls.temp.cleanup()

    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(dir=self.root)
        self.work = Path(self.temp.name)

    def tearDown(self):
        self.temp.cleanup()

    def prepare(self, profile='B', **kwargs):
        return r.prepare(self.root / (profile + '.zip'), self.source, profile,
                         self.hashes[profile], **kwargs)

    def test_both_profiles_plan_roundtrip_and_exact_staging(self):
        for profile in ('B', 'C'):
            plan = self.work / (profile + '.json')
            first = self.prepare(profile, plan_out=plan)
            stage = self.work / ('.render-static-' + profile)
            result = self.prepare(profile, plan_path=plan, stage=stage)
            self.assertEqual(result['offline_render_plan'], 'PASS')
            self.assertEqual(result['deployment'], 'BLOCKED_ORIGIN_NOT_ADOPTED')
            self.assertEqual(result['hosting'], 'LIVE_VERIFICATION_REQUIRED')
            self.assertEqual(result['render_build_environment'], 'UNKNOWN')
            self.assertFalse(result['release_approved'])
            self.assertFalse(first['staged'])
            self.assertEqual(b.verify_directory(stage), b.verify_directory(self.root / profile))
            names = {p.relative_to(stage).as_posix() for p in stage.rglob('*') if p.is_file()}
            self.assertEqual(len(names), 25)
            self.assertNotIn(plan.name, names)
            self.assertIn('ADOPTED_ORIGIN=null', (stage / 'src/settings.mjs').read_text())
            self.assertEqual((stage / 'manifest.json').read_bytes(), (self.root / profile / 'manifest.json').read_bytes())
            with self.assertRaises(ValueError):
                self.prepare(profile, stage=stage)

    def test_unsafe_settings_no_outputs(self):
        mutations = [
            ('autoDeployTrigger', 'commit'), ('autoDeployTrigger', 'after_ci_checks_pass'),
            ('previews', {'generation': 'automatic'}), ('previews', {'generation': 'manual'}),
            ('routes', [{'type': 'rewrite', 'source': '/*', 'destination': '/index.html'}]),
            ('routes', [{'type': 'redirect', 'source': '/*', 'destination': 'https://unadopted.invalid'}]),
            ('unknownPathPolicy', 'SPA'), ('rootDir', 'static-welcome'),
            ('publishPath', '.'), ('publishPath', 'public'), ('buildCommand', 'true'),
            ('buildEnvironment', {'SKIP_INSTALL_DEPS': 'false'}),
            ('envGroups', ['canonical']), ('customDomains', ['unadopted.invalid']),
            ('adopted_origin', 'https://unadopted.invalid'), ('release_approved', True),
            ('release_approved', 0), ('provider_runtime', 'PASS'), ('extra', True),
            ('source_sha', '0' * 40), ('artifact_sha256', '0' * 64), ('profile', 'C'),
        ]
        for key, value in mutations:
            plan = r.plan_for(self.source, 'B', self.hashes['B'])
            plan[key] = value
            path = self.work / 'plan.json'
            path.write_text(json.dumps(plan))
            stage = self.work / '.render-static-B'
            with self.subTest(key=key, value=value), self.assertRaises(ValueError):
                self.prepare(plan_path=path, stage=stage)
            self.assertFalse(stage.exists())

    def test_header_policy_missing_duplicate_extra_path_csp_cache(self):
        original = r.plan_for(self.source, 'B', self.hashes['B'])
        changes = []
        rows = original['headers']
        changes.extend([rows[:-1], rows + [rows[0]], rows + [dict(path='/*', name='Set-Cookie', value='x=1')]])
        for index, key, value in [(0, 'path', '/assets/*'), (0, 'value', "default-src *"),
                                  (0, 'name', 'content-security-policy'), (4, 'value', 'public, max-age=3600')]:
            changed = copy.deepcopy(rows)
            changed[index][key] = value
            changes.append(changed)
        for headers in changes:
            with self.subTest(headers=headers), self.assertRaises(ValueError):
                r.verify_plan(dict(original, headers=headers), self.source, 'B', self.hashes['B'])

    def test_plan_parser_duplicate_unknown_malformed_and_unsafe_file(self):
        path = self.work / 'plan.json'
        for raw in ('{}', '[]', '{', '{"profile":"B","profile":"C"}', 'null'):
            path.write_text(raw)
            with self.subTest(raw=raw), self.assertRaises(ValueError):
                self.prepare(plan_path=path)
        path.unlink()
        os.mkfifo(path)
        with self.assertRaises(ValueError):
            self.prepare(plan_path=path)
        path.unlink()
        path.symlink_to(self.root / 'B/index.html')
        with self.assertRaises(OSError):
            self.prepare(plan_path=path)

    def test_source_hash_profile_and_zip_mismatch(self):
        for source, profile, digest in [('main', 'B', self.hashes['B']),
                                         ('0' * 40, 'B', self.hashes['B']),
                                         (self.source, 'B', '0' * 64),
                                         (self.source, 'C', self.hashes['B']),
                                         (self.source, 'B', 'bad')]:
            with self.subTest(source=source, profile=profile), self.assertRaises(ValueError):
                r.prepare(self.root / 'B.zip', source, profile, digest)

    def test_artifact_extra_file_and_rehashed_tamper(self):
        for name in ('.env', 'sw.js', 'render.yaml', '404.html'):
            path = self.work / 'tamper.zip'
            with zipfile.ZipFile(self.root / 'B.zip') as old, zipfile.ZipFile(path, 'w') as z:
                for info in old.infolist():
                    if info.filename != name:
                        z.writestr(info, old.read(info.filename))
                info = zipfile.ZipInfo(name)
                info.create_system = 3
                info.external_attr = 0o100644 << 16
                z.writestr(info, b'SYNTHETIC_ONLY')
            with self.subTest(name=name), self.assertRaises(ValueError):
                r.prepare(path, self.source, 'B', b.digest(path.read_bytes()))

    def test_dirty_checkout_no_git_and_old_python_block(self):
        # A new synthetic file in THIS disposable checkout proves clean-source enforcement.
        canary = b.ROOT / '.preparation-render-dirty-probe'
        with canary.open('x') as stream:
            stream.write('SYNTHETIC_ONLY')
        try:
            with self.assertRaisesRegex(ValueError, 'CLEAN_SOURCE_REQUIRED'):
                self.prepare()
        finally:
            canary.unlink()
        with patch.dict(os.environ, {'PATH': ''}), self.assertRaises(OSError):
            self.prepare()
        with patch.object(sys, 'version_info', (3, 8)), self.assertRaises(ValueError):
            self.prepare()

    def test_output_collision_symlink_wrong_publish_and_source_directory(self):
        bad = self.work / 'bad'
        with self.assertRaises(ValueError):
            self.prepare(stage=bad)
        alias = self.work / '.render-static-B'
        alias.symlink_to(self.root / 'B', target_is_directory=True)
        with self.assertRaises(ValueError):
            self.prepare(stage=alias)
        alias.unlink()
        with self.assertRaises(ValueError):
            self.prepare(plan_out=alias, stage=alias)
        with self.assertRaises(ValueError):
            self.prepare(stage=b.ROOT / '.render-static-B')

    def test_reserved_render_command_always_blocks_without_output(self):
        plan = r.plan_for(self.source, 'B', self.hashes['B'])
        import shlex
        command = shlex.split(plan['buildCommand'])
        # Use the current interpreter; the command itself has only pinned safe tokens.
        command[0] = sys.executable
        with patch.dict(os.environ, {'ADOPTED_ORIGIN': 'https://unadopted.invalid', 'RELEASE_APPROVED': 'true'}):
            result = subprocess.run(command, cwd=b.ROOT, capture_output=True, text=True)
        self.assertEqual(result.returncode, 2)
        self.assertEqual(result.stdout, '')
        self.assertEqual(result.stderr, 'PREPARATION_RENDER_BLOCK\n')
        self.assertFalse((b.ROOT / plan['publishPath']).exists())

    def test_cli_no_argument_leak(self):
        result = subprocess.run([sys.executable, '-B', str(Path(r.__file__)), '--secret=SYNTHETIC_CANARY'],
                                capture_output=True, text=True)
        self.assertEqual(result.returncode, 2)
        self.assertEqual(result.stdout, '')
        self.assertEqual(result.stderr, 'PREPARATION_RENDER_BLOCK\n')


if __name__ == '__main__':
    unittest.main()
