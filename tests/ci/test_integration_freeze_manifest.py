import importlib.util
from pathlib import Path
import subprocess
import tempfile
import unittest
from unittest.mock import patch

SPEC = importlib.util.spec_from_file_location('freeze', Path(__file__).resolve().parents[2] / 'scripts/ci/integration_freeze_manifest.py')
freeze = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(freeze)


class FreezeManifestTest(unittest.TestCase):
    def test_risk_queues_overlap_and_cover_shared_paths(self):
        self.assertIn('database_migration_trigger', freeze.risk_areas('database/migrations/epoch.php'))
        self.assertIn('authentication_oauth_session_csrf', freeze.risk_areas('app/Http/Controllers/McpOAuthPlanConsentController.php'))
        self.assertIn('mcp_personal_plan_authorization', freeze.risk_areas('app/Http/Controllers/McpOAuthPlanConsentController.php'))
        self.assertIn('actions_production_protection', freeze.risk_areas('.github/workflows/production-promotion-policy.yml'))
        self.assertEqual(['shared_manual_review'], freeze.risk_areas('README.md'))

    def test_preparation_allocation_is_conservative_and_never_content_approval(self):
        examples = {
            'database/migrations/new.php': 'PRELAUNCH_REQUIRED',
            'app/Http/Controllers/AuthController.php': 'PRELAUNCH_REQUIRED',
            'package.json': 'PRELAUNCH_REQUIRED',
            'package-lock.json': 'PRELAUNCH_REQUIRED',
            'app/Services/PrivateFileLifecycleService.php': 'SECURITY_REVIEW_REQUIRED',
            'scripts/ops/integrated_release20_collect.py': 'SECURITY_REVIEW_REQUIRED',
            'scripts/ci/e858_phase1/owned_activation_fixture.py': 'SECURITY_REVIEW_REQUIRED',
            '.github/workflows/production-promotion-policy.yml': 'SECURITY_REVIEW_REQUIRED',
            'static-welcome/index.html': 'SECURITY_REVIEW_REQUIRED',
            'docs/future/spec.md': 'FUTURE_ONLY',
            'tests/Feature/ExampleTest.php': 'FUTURE_ONLY',
            'unreviewed-plugin.php': 'UNKNOWN',
        }
        for path, expected in examples.items():
            item = freeze.preparation_classification(path, True)
            self.assertEqual(item['category'], expected)
            self.assertFalse(item['content_review_certified'])
            self.assertFalse(item['live_isolation_certified'])
        path = next(iter(freeze.ISOLATED_HANDLERS))
        self.assertEqual(freeze.preparation_classification(path, True)['category'], 'DEFERRABLE_ISOLATED')
        self.assertEqual(freeze.preparation_classification(path, False)['category'], 'SECURITY_REVIEW_REQUIRED')

    def test_every_path_is_classified_and_changed_or_missing_isolation_downgrades_b(self):
        paths = [*freeze.ISOLATED_HANDLERS, 'composer.lock', 'unknown-extension']
        for drift in (False, True, 'unavailable'):
            def fake_git(root, *args):
                if drift == 'unavailable':
                    raise subprocess.CalledProcessError(1, ['git'])
                return 'changed' if drift and args[-1].startswith('candidate:') else 'same'
            rows = [dict(path=p) for p in paths]
            with patch.object(freeze, 'ISOLATION_OBJECTS', {k: 'same' for k in freeze.ISOLATION_TREES}), patch.object(freeze, 'git', side_effect=fake_git):
                result = freeze.preparation_review(Path('.'), 'candidate', rows)
            self.assertTrue(result['classification_complete'])
            self.assertEqual(sum(result['category_counts'].values()), len(paths))
            self.assertEqual(result['category_counts']['DEFERRABLE_ISOLATED'], 0 if drift else len(freeze.ISOLATED_HANDLERS))
            self.assertEqual(result['category_counts']['UNKNOWN'], 1)
            self.assertFalse(result['release_authorized'])
            self.assertFalse(result['independent_review_completed'])
            self.assertFalse(result['source_adopted'])

    def test_squashed_review_basis_commit_is_not_needed_for_object_seal_comparison(self):
        calls = []
        def candidate_only(root, *args):
            calls.append(args[-1])
            self.assertTrue(args[-1].startswith('candidate:'))
            return freeze.ISOLATION_OBJECTS[args[-1].split(':', 1)[1]]
        rows = [dict(path=next(iter(freeze.ISOLATED_HANDLERS)))]
        with patch.object(freeze, 'git', side_effect=candidate_only):
            report = freeze.preparation_review(Path('.'), 'candidate', rows)
        self.assertTrue(report['application_isolation_trees_unchanged'])
        self.assertEqual(report['category_counts']['DEFERRABLE_ISOLATED'], 1)
        self.assertEqual(len(calls), len(freeze.ISOLATION_OBJECTS))
        self.assertFalse(report['release_authorized'])

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
            self.assertNotIn('preparation_review', result)
            reviewed = freeze.manifest(root, base, candidate, include_preparation_review=True)
            self.assertEqual(308, sum(reviewed['preparation_review']['category_counts'].values()))
            self.assertTrue(all('preparation_review' in row for row in reviewed['changed_paths']))
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
