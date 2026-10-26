#!/usr/bin/env python3
"""Exact local Git source inventory. No network, DB access or release approval."""
from __future__ import annotations

import argparse
import json
from pathlib import Path
import re
import subprocess


def git(root: Path, *args: str) -> str:
    return subprocess.check_output(
        ['git', *args], cwd=root, text=True, stderr=subprocess.DEVNULL,
        timeout=30,
    ).strip()


def classification(path: str) -> dict:
    if path.startswith('docs/future/'):
        return dict(area='Future', state='FUTURE_NOT_IMPLEMENTATION',
                    test='not a runtime acceptance claim', rollback='no runtime effect',
                    exposure='NOT_APPROVED')
    if path.startswith('database/migrations/'):
        return dict(area='Platform/DB', state='SOURCE_ONLY',
                    test='exact-SHA MySQL 8.0/8.4 upgrade, retry and preservation',
                    rollback='forward recovery; never infer migration down safety', exposure='OWNER_GATE')
    if path.startswith(('.github/', 'docker/', 'scripts/')):
        return dict(area='Platform/Release', state='SOURCE_ONLY',
                    test='applicable workflow and offline negative controls',
                    rollback='revert code; external changes require separate approval', exposure='INTERNAL')
    lower = path.lower()
    area = next((a for term, a in [('mcp', 'MCP'), ('learningcontext', 'Intelligence'),
                ('learning_context', 'Intelligence'), ('study', 'Learning'),
                ('practice', 'Learning'), ('development', 'Development'),
                ('github', 'Development'), ('career', 'Expansion')] if term in lower), 'Shared/Experience')
    return dict(area=area, state='SOURCE_ONLY', test='owner-scoped targeted tests and release regression',
                rollback='retain data; lower exposure or revert compatible code', exposure='REVIEW_REQUIRED')


def risk_areas(path: str) -> list[str]:
    """Overlapping review queues, never an automated security approval."""
    value = path.lower()
    rules = {
        'database_migration_trigger': ('database/', 'migration', 'mysql', 'trigger'),
        'authentication_oauth_session_csrf': ('auth', 'oauth', 'session', 'csrf', 'middleware'),
        'mcp_personal_plan_authorization': ('mcp', 'planpolicy', 'planownership', 'delegated'),
        'learning_development_intelligence': ('study', 'learning', 'practice', 'development', 'intelligence'),
        'release_feature_entitlement': ('release', 'featureflag', 'feature_flag', 'entitlement'),
        'private_file_external_provider': ('private_file', 'privatefile', 'storage', 'provider', 'github'),
        'web_wkwebview_ui': ('resources/', 'routes/', 'http/', 'browser', 'vite', 'package'),
        'composer_docker_render_worker': ('composer', 'docker/', 'render', 'worker', 'queue', 'jobs/'),
        'actions_production_protection': ('.github/', 'promotion', 'workflow', 'ruleset'),
    }
    matches = [area for area, terms in rules.items() if any(term in value for term in terms)]
    return matches or ['shared_manual_review']


# Work allocation, not approval. Narrow B claims are tied to the application
# trees reviewed after the reset-mail guard; changes require a new review.
PREPARATION_REVIEW_BASIS = '65dd5499471e77da062c739471128efe162ac723'
# Objects remain reachable from the candidate even after squash/branch deletion.
ISOLATION_OBJECTS = {
    'app': '68b19bb19d7bbc633c0a0475bef182c05dea6c7d',
    'bootstrap': '3dfc58f7e5f95fab5956ed595c4440762e5224c0',
    'config': '1f156aab4e55462d1eab2395c928f67125336950',
    'routes': '467dc94fddd591e13f9448aba8cfd51aa9afb499',
    'resources': '58b26914ca56c4a385bd936c60af9c55d226e50d',
    'composer.json': 'f6928b8c320277418c1c26805b4bf95459687b5a',
    'composer.lock': '59ca8630c36f930b572f8455669ffd511b75209b',
    'public': '2c459b6e190608da067972de53414f8589624f6d',
    'Dockerfile': 'dbdb69eb4041841cbec6808c8ab1327ac44b63be',
    'docker/nginx.conf': 'd69ddf8144545abe5a1c45402dc89102376e28a3',
    'docker/php-production.ini': '353ae9f4a6c240097c73aac6f326e3e7b1c03801',
    'docker/supervisord.conf': '00c50a9d153cdcf768099631c7bd3155d9c13bfb',
    'docker/render-start.sh': '79167ad4acc225f0e34524a950db42e43fa60739',
    'docker/startup-migration-policy.sh': '19ca41a1721c73c3225efabd98757a9b1d286e9d',
}
ISOLATION_TREES = tuple(ISOLATION_OBJECTS)
ISOLATED_HANDLERS = {
    'app/Http/Controllers/StudyPracticeReviewController.php',
    'app/Http/Controllers/StudyPracticeRetryController.php',
    'app/Http/Controllers/AdaptiveLearningHistoryController.php',
    'app/Http/Controllers/AdaptiveLearningNextActionController.php',
}
REQUIRED_SHARED = {
    'AGENTS.md', '.dockerignore', 'Dockerfile', 'composer.json', 'composer.lock',
    'package.json', 'package-lock.json',
    'bootstrap/app.php', 'app/Providers/AppServiceProvider.php',
    'routes/web.php', 'routes/console.php', 'config/canovia.php', 'config/release_levels.php',
    'config/prelaunch_interest_diagnostic.php', 'config/canovia_public_roadmap.php',
    'config/canovia_future_media.php', 'vite.config.js',
    'app/Http/Controllers/AuthController.php', 'app/Http/Controllers/HomeController.php',
    'app/Http/Controllers/PlanController.php', 'app/Http/Controllers/PrelaunchInterestController.php',
    'app/Http/Controllers/PersonalizationController.php', 'app/Http/Controllers/CanoviaFutureController.php',
    'app/Http/Middleware/EnsurePreparationSurface.php',
    'app/Bus/Middleware/EnsurePreparationExecution.php',
    'app/Services/ReleaseLevelService.php', 'app/Services/FeatureAccessService.php',
    'app/Services/FeatureFlagService.php', 'app/Services/PreparationExecutionGuard.php',
    'app/Services/PersonalizationContextService.php', 'app/Services/PrelaunchInterestContextService.php',
    'app/Services/PrelaunchInterestScoringService.php', 'app/Services/GuestPlanClaimService.php',
    'app/Services/AccountDeletionService.php', 'app/Models/Plan.php', 'app/Models/User.php',
    'app/Console/Commands/CheckReleaseSchemaLedger.php',
    'app/Support/Release20PendingSchemaGuard.php', 'app/Support/Release20IdentityMigrationGuard.php',
    'resources/release20_pending_schema_contract.json', 'resources/js/preparation.mjs',
    'resources/views/layouts/app.blade.php', 'resources/views/layouts/preparation.blade.php',
    'docs/CANOVIA_DEVELOPMENT_WORKFLOW.md',
    'docs/development/CANOVIA_INTEGRATION_FREEZE_RELEASE_MASTER_2026_10_16.md',
    'docs/development/PRELAUNCH_PREPARATION_SHARED_DB_RELEASE_STRATEGY_2026_10_23.md',
    'docs/development/PRELAUNCH_AIVEN_RUNTIME_DDL_AND_RECOVERY_GATE_2026_10_15.md',
    'scripts/ci/integration_freeze_manifest.py', 'scripts/ops/platform_release_preflight.py',
    'scripts/ci/preparation_cutover_rehearsal.py', 'scripts/ci/integration_mysql_upgrade.py',
    'scripts/ci/integration_disposable_restore.py',
}
PREPARATION_CLASSES = ('PRELAUNCH_REQUIRED', 'DEFERRABLE_ISOLATED',
                       'SECURITY_REVIEW_REQUIRED', 'FUTURE_ONLY', 'UNKNOWN')


def preparation_classification(path: str, isolation_sealed: bool) -> dict:
    """Conservative path allocation. No automated claim of content review/GO."""
    if path in REQUIRED_SHARED or path.startswith(('database/migrations/', 'resources/views/preparation/', 'public/images/future/')):
        category, reason = 'PRELAUNCH_REQUIRED', 'SHARED_OR_SELECTED_PREPARATION_CONTRACT'
    elif path in ISOLATED_HANDLERS:
        category, reason = (('DEFERRABLE_ISOLATED', 'REVIEWED_ROUTE_HANDLERS_ONLY_NOT_SCHEMA_OR_DEPENDENCIES')
                            if isolation_sealed else ('SECURITY_REVIEW_REQUIRED', 'ISOLATION_BASIS_CHANGED_OR_UNAVAILABLE'))
    elif path.startswith(('app/', 'bootstrap/', 'config/', 'routes/', 'resources/', 'public/',
                          'docker/', '.github/', 'static-welcome/')) or path.startswith('Dockerfile'):
        category, reason = 'SECURITY_REVIEW_REQUIRED', 'RUNTIME_DEPENDENCY_OR_EXTERNAL_IO_REVIEW'
    elif path.startswith('scripts/ci/test_'):
        category, reason = 'FUTURE_ONLY', 'TEST_ASSET_NOT_PRODUCTION_ENTRY'
    elif path.startswith('scripts/'):
        category, reason = 'SECURITY_REVIEW_REQUIRED', 'EXECUTABLE_OR_AUDIT_ASSET_NOT_LIVE_ADOPTED'
    elif path.startswith(('docs/', 'tests/', 'local-preview/')) or path == 'phpunit.xml':
        category, reason = 'FUTURE_ONLY', 'DOCUMENT_OR_DEV_ASSET_NOT_PRODUCTION_ENTRY'
    else:
        category, reason = 'UNKNOWN', 'NO_REVIEWED_ALLOCATION_RULE'
    return dict(category=category, reason=reason, automatic_classification=True,
                content_review_certified=False, live_isolation_certified=False,
                scope='SOURCE_WORK_ALLOCATION_ONLY_NOT_RELEASE_APPROVAL')


def preparation_review(root: Path, candidate: str, rows: list[dict]) -> dict:
    sealed = False
    try:
        sealed = all(git(root, 'rev-parse', candidate + ':' + path) == expected
                     for path, expected in ISOLATION_OBJECTS.items())
    except subprocess.SubprocessError:
        pass
    for row in rows:
        row['preparation_review'] = preparation_classification(row['path'], sealed)
    return dict(review_basis=PREPARATION_REVIEW_BASIS, isolation_object_seals=ISOLATION_OBJECTS,
                application_isolation_trees_unchanged=sealed,
                category_counts={key: sum(r['preparation_review']['category'] == key for r in rows)
                                 for key in PREPARATION_CLASSES},
                classification_complete=len(rows) == sum(1 for r in rows if r['preparation_review']['category'] in PREPARATION_CLASSES),
                content_review_certified=False, independent_review_completed=False,
                release_authorized=False, source_adopted=False)


def manifest(root: Path, base: str, candidate: str, *, include_preparation_review=False) -> dict:
    if not all(re.fullmatch('[0-9a-f]{40}', value) for value in (base, candidate)):
        raise ValueError('Use full immutable commit SHAs, not moving refs')
    if git(root, 'rev-parse', '--is-shallow-repository') != 'false':
        raise ValueError('Full local Git history required')
    for value in (base, candidate):
        if git(root, 'rev-parse', '--verify', value + '^{commit}') != value:
            raise ValueError('Commit identity mismatch')
    git(root, 'merge-base', '--is-ancestor', base, candidate)
    paths = git(root, '-c', 'core.quotePath=false', 'diff', '--name-status', '--no-renames',
                base, candidate).splitlines()
    rows = []
    for line in paths:
        status, path = line.split('\t', 1)
        blob = None if status == 'D' else git(root, 'rev-parse', candidate + ':' + path)
        rows.append(dict(path=path, change=status, candidate_blob=blob,
                         baseline_blob=None if status == 'A' else git(root, 'rev-parse', base + ':' + path),
                         risk_areas=risk_areas(path),
                         dependency='review against shared auth/session/publication contracts',
                         owner_assignment='PATH_CLASSIFICATION_REQUIRES_REVIEW', **classification(path)))
    migration_rows = [row for row in rows if row['path'].startswith('database/migrations/')]
    def migrations(sha: str) -> list[str]:
        return [p for p in git(root, 'ls-tree', '-r', '--name-only', sha,
                               'database/migrations').splitlines() if p.endswith('.php')]
    result = dict(schema_version=1, base_sha=base, candidate_sha=candidate,
                ahead_commits=int(git(root, 'rev-list', '--count', base + '..' + candidate)),
                changed_path_count=len(rows), changed_paths=rows,
                baseline_migration_count=len(migrations(base)),
                candidate_migration_count=len(migrations(candidate)),
                migration_changes=migration_rows,
                risk_area_counts={area: sum(area in row['risk_areas'] for row in rows)
                                  for area in sorted({a for row in rows for a in row['risk_areas']})},
                production_pending_migrations='UNKNOWN_REQUIRES_SEPARATE_OPERATOR_AUDIT',
                live_sha_verified=False, ci_verified=False, device_verified=False,
                release_authorized=False, verdict='SOURCE_INVENTORY_ONLY_PRODUCTION_NO_GO')
    if include_preparation_review:
        result['preparation_review'] = preparation_review(root, candidate, rows)
    return result


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--repo', type=Path, default=Path(__file__).resolve().parents[2])
    parser.add_argument('--base', required=True)
    parser.add_argument('--candidate', required=True)
    parser.add_argument('--preparation-review', action='store_true', help='Classify every path A-E for review allocation; never content approval')
    args = parser.parse_args()
    try:
        result = manifest(args.repo, args.base, args.candidate, include_preparation_review=args.preparation_review)
    except (ValueError, OSError, subprocess.SubprocessError):
        print(json.dumps(dict(verdict='BLOCK_INVALID_OR_INCOMPLETE_GIT_SOURCE', release_authorized=False)))
        return 1
    print(json.dumps(result, ensure_ascii=False, indent=2))
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
