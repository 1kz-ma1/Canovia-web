"""Pinned local Git / mocked GitHub GET, never a provider or approval fixture."""
import copy
import json
import os
import shutil
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest

sys.path.insert(0, str(Path(__file__).resolve().parents[2]))
from scripts.ops.platform_release_preflight import preparation_evaluate, preparation_github, PREPARATION_CI


class PreparationPreflightTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.root = Path(self.tmp.name)
        self.git('init', '-q', '-b', 'main')
        self.git('config', 'user.name', 'Synthetic')
        self.git('config', 'user.email', 'synthetic@example.invalid')
        self.migrations = self.root / 'database/migrations'
        self.migrations.mkdir(parents=True)
        for i in range(94):
            (self.migrations / f'{i:03}.php').write_text('<?php // synthetic old')
        self.commit()
        self.old = self.git('rev-parse', 'HEAD')
        for i in range(94, 114):
            (self.migrations / f'{i:03}.php').write_text('<?php // synthetic new')
        workflow = self.root / '.github/workflows/prelaunch-shared-preparation.yml'
        workflow.parent.mkdir(parents=True)
        workflow.write_text('Synthetic source inventory: PreparationWorkerStopTest.php')
        self.commit()
        self.head = self.git('rev-parse', 'HEAD')
        self.git('update-ref', 'refs/remotes/origin/main', self.head)
        self.pages = {}
        rows = []
        for i, (name, path) in enumerate(PREPARATION_CI.items(), 1):
            rows.append(dict(name=name, head_sha=self.head, app={'slug': 'github-actions'}, status='completed', conclusion='success',
                             details_url=f'https://github.com/1kz-ma1/Canovia-web/actions/runs/{i}/job/{i}'))
            self.pages[f'actions/runs/{i}'] = dict(head_sha=self.head, repository={'full_name': '1kz-ma1/Canovia-web'},
                                                 path=path, status='completed', conclusion='success')
        self.pages[f'commits/{self.head}/check-runs?per_page=100'] = {'total_count': len(rows), 'check_runs': rows}
        for branch in ('main', 'production'):
            self.pages['rules/branches/' + branch] = []

    def git(self, *args):
        return subprocess.check_output(['git', '-C', str(self.root), *args], text=True, stderr=subprocess.DEVNULL).strip()

    def commit(self):
        self.git('add', '.')
        self.git('commit', '-qm', 'synthetic')

    def api(self, path):
        self.assertTrue(path.startswith('repos/1kz-ma1/Canovia-web/'))
        return copy.deepcopy(self.pages[path.removeprefix('repos/1kz-ma1/Canovia-web/')])

    def report(self, github=False):
        return preparation_evaluate(self.root, self.head, self.old, self.api if github else None)

    def test_offline_inventory_does_not_infer_runtime_or_ci_success(self):
        report = self.report()
        self.assertEqual(report['migration_inventory'], dict(historical_count=94, candidate_count=114, added_count=20, old_blobs_preserved=True))
        self.assertEqual(report['checks']['migration_inventory_source_only'], 'PASS')
        self.assertEqual(report['checks']['exact_head_ci'], 'UNKNOWN')
        self.assertEqual(report['checks']['worker_cutover'], 'LIVE_VERIFICATION_REQUIRED')
        self.assertEqual(report['result'], 'BLOCKING_SHARED_REMAINING')
        self.assertFalse(report['release_authorized'])
        self.assertFalse(report['source_adopted'])

    def test_even_all_github_success_cannot_certify_old_workers_or_real_restore(self):
        report = self.report(True)
        self.assertEqual(report['checks']['exact_head_ci'], 'PASS')
        self.assertEqual(report['checks']['production_rules_read'], 'PASS')
        self.assertEqual(report['checks']['old_new_writer_coexistence'], 'UNKNOWN')
        self.assertEqual(report['checks']['worker_cutover'], 'LIVE_VERIFICATION_REQUIRED')
        self.assertEqual(report['checks']['recovery_readiness'], 'LIVE_VERIFICATION_REQUIRED')
        self.assertEqual(report['checks']['real_device_acceptance'], 'OWNER_ACCEPTANCE_REQUIRED')
        self.assertEqual(report['result'], 'BLOCKING_SHARED_REMAINING')
        self.assertFalse(report['production_modified'])

    def test_modified_or_missing_old_migration_is_not_compatible(self):
        (self.migrations / '001.php').write_text('<?php // changed old')
        self.commit()
        self.head = self.git('rev-parse', 'HEAD')
        self.git('update-ref', 'refs/remotes/origin/main', self.head)
        self.assertEqual(self.report()['checks']['migration_inventory_source_only'], 'FAIL')
        (self.migrations / '002.php').unlink()
        self.commit()
        self.head = self.git('rev-parse', 'HEAD')
        self.git('update-ref', 'refs/remotes/origin/main', self.head)
        self.assertEqual(self.report()['result'], 'FAIL')

    def test_unexpected_migration_count_fails(self):
        (self.migrations / '114.php').write_text('<?php // unreviewed')
        self.commit()
        self.head = self.git('rev-parse', 'HEAD')
        self.git('update-ref', 'refs/remotes/origin/main', self.head)
        self.assertEqual(self.report()['result'], 'FAIL')

    def test_invalid_source_and_missing_git_fail_closed(self):
        self.assertEqual(preparation_evaluate(self.root, 'main;secret', self.old)['result'], 'FAIL')
        self.assertEqual(preparation_evaluate(self.root / 'absent', self.head, self.old)['result'], 'FAIL')
        self.git('update-ref', 'refs/remotes/origin/main', self.old)
        self.assertEqual(self.report()['checks']['source_integrity'], 'FAIL')

    def test_pending_skipped_or_missing_required_jobs_are_unknown(self):
        rows = self.pages[f'commits/{self.head}/check-runs?per_page=100']['check_runs']
        for conclusion in ('skipped', None):
            rows[0]['conclusion'] = conclusion
            self.assertEqual(self.report(True)['checks']['exact_head_ci'], 'UNKNOWN')
        rows.pop()
        self.pages[f'commits/{self.head}/check-runs?per_page=100']['total_count'] = len(rows)
        self.assertEqual(self.report(True)['checks']['exact_head_ci'], 'UNKNOWN')

    def test_failed_job_fails_readiness(self):
        self.pages[f'commits/{self.head}/check-runs?per_page=100']['check_runs'][0]['conclusion'] = 'failure'
        self.assertEqual(self.report(True)['checks']['exact_head_ci'], 'FAIL')
        self.assertEqual(self.report(True)['result'], 'FAIL')

    def test_same_named_job_wrong_source_or_workflow_cannot_supply_evidence(self):
        for field, value in [('head_sha', self.old), ('path', '.github/workflows/unrelated.yml')]:
            original = copy.deepcopy(self.pages['actions/runs/1'])
            self.pages['actions/runs/1'][field] = value
            self.assertEqual(self.report(True)['checks']['github_evidence'], 'UNKNOWN')
            self.pages['actions/runs/1'] = original
        self.pages[f'commits/{self.head}/check-runs?per_page=100']['check_runs'][0]['app']['slug'] = 'untrusted'
        self.assertEqual(self.report(True)['checks']['github_evidence'], 'UNKNOWN')

    def test_run_not_completed_and_incomplete_pagination_cannot_pass(self):
        self.pages['actions/runs/1']['status'] = 'in_progress'
        self.assertEqual(self.report(True)['checks']['exact_head_ci'], 'UNKNOWN')
        self.pages[f'commits/{self.head}/check-runs?per_page=100']['total_count'] = 101
        self.assertEqual(self.report(True)['checks']['github_evidence'], 'UNKNOWN')

    def test_squash_result_can_reuse_only_the_exact_tested_tree(self):
        self.git('commit', '--allow-empty', '-qm', 'synthetic squash same tree')
        candidate = self.git('rev-parse', 'HEAD')
        self.git('update-ref', 'refs/remotes/origin/main', candidate)
        report = preparation_evaluate(self.root, candidate, self.old, self.api, self.head)
        self.assertEqual(report['checks']['ci_tree_binding'], 'PASS')
        self.assertEqual(report['checks']['exact_head_ci'], 'PASS')
        (self.root / 'changed.txt').write_text('unverified delta')
        self.commit()
        different = self.git('rev-parse', 'HEAD')
        self.git('update-ref', 'refs/remotes/origin/main', different)
        report = preparation_evaluate(self.root, different, self.old, self.api, self.head)
        self.assertEqual(report['checks']['ci_tree_binding'], 'FAIL')
        self.assertEqual(report['checks']['exact_head_ci'], 'UNKNOWN')
        self.assertEqual(report['result'], 'FAIL')

    def test_cli_github_read_only_mode_does_not_write_import_cache(self):
        tool = self.root / 'tool'
        tool.mkdir()
        original = Path(__file__).resolve().parents[2] / 'scripts/ops/platform_release_preflight.py'
        script = tool / 'platform_release_preflight.py'
        shutil.copyfile(original, script)
        # A private, disposable GET-metadata stub, not a live approval/client.
        (tool / 'pages.json').write_text(json.dumps(self.pages))
        (tool / 'platform_ci_runtime_report.py').write_text(
            "import json\nfrom pathlib import Path\n"
            "def api(path):\n    return json.loads(Path(__file__).with_name('pages.json').read_text())[path.removeprefix('repos/1kz-ma1/Canovia-web/')]\n")
        result = subprocess.run([sys.executable, str(script), '--repo', str(self.root), '--preparation',
                                 '--release-sha', self.head, '--live-sha', self.old, '--github-readonly'],
                                capture_output=True, text=True, timeout=20, env={"PATH": os.defpath, "GIT_CONFIG_NOSYSTEM": "1"})
        self.assertEqual(result.returncode, 2)
        self.assertEqual(json.loads(result.stdout)['checks']['exact_head_ci'], 'PASS')
        self.assertEqual(list(tool.rglob('__pycache__')), [])

    def test_api_exception_is_sanitized_and_never_replaced_by_claims(self):
        def unavailable(path):
            raise RuntimeError('SYNTHETIC_PRIVATE_ERROR_DO_NOT_PRINT')
        result = preparation_github(self.head, unavailable)
        self.assertNotIn('SYNTHETIC_PRIVATE_ERROR_DO_NOT_PRINT', str(result))
        self.assertEqual(result['checks'], {'github_evidence': 'UNKNOWN'})


if __name__ == '__main__':
    unittest.main()
