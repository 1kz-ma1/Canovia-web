import importlib.util
from pathlib import Path
import subprocess
import tempfile
import unittest

SPEC = importlib.util.spec_from_file_location('freeze', Path(__file__).resolve().parents[2] / 'scripts/ci/integration_freeze_manifest.py')
freeze = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(freeze)


class FreezeManifestTest(unittest.TestCase):
    def test_complete_delta_and_migration_identity_without_approval(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            def git(*args):
                return subprocess.check_output(['git', *args], cwd=root, text=True, stderr=subprocess.DEVNULL).strip()
            git('init', '-q')
            git('config', 'user.email', 'fixture@example.invalid')
            git('config', 'user.name', 'Fixture')
            migrations = root / 'database/migrations'
            migrations.mkdir(parents=True)
            (migrations / 'one.php').write_text('old')
            (root / 'removed').write_text('old')
            git('add', '.')
            git('commit', '-qm', 'base')
            base = git('rev-parse', 'HEAD')
            (migrations / 'two.php').write_text('new')
            (migrations / 'one.php').write_text('changed')
            (root / 'removed').unlink()
            for i in range(305):
                (root / f'file-{i}').write_text('new')
            git('add', '.')
            git('commit', '-qm', 'candidate')
            candidate = git('rev-parse', 'HEAD')
            result = freeze.manifest(root, base, candidate)
            self.assertEqual(308, result['changed_path_count'])
            self.assertEqual((1, 2), (result['baseline_migration_count'], result['candidate_migration_count']))
            self.assertEqual({'A', 'M'}, {r['change'] for r in result['migration_changes']})
            self.assertEqual(git('rev-parse', candidate + ':database/migrations/two.php'),
                             next(r for r in result['migration_changes'] if r['change'] == 'A')['candidate_blob'])
            self.assertFalse(result['release_authorized'])
            self.assertFalse(result['live_sha_verified'])
            self.assertTrue(result['production_pending_migrations'].startswith('UNKNOWN'))
            with self.assertRaises(subprocess.CalledProcessError):
                freeze.manifest(root, candidate, base)
            with self.assertRaises(ValueError):
                freeze.manifest(root, base, 'HEAD')
            git('rev-parse', '--git-dir')
            (root / '.git/shallow').write_text(candidate + '\n')
            with self.assertRaises(ValueError):
                freeze.manifest(root, base, candidate)


if __name__ == '__main__':
    unittest.main()
