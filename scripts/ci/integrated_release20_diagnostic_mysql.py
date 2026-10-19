#!/usr/bin/env python3
"""Synthetic-only MySQL 8.0/8.4 diagnosis fixtures. All subprocess IO is private.

DDL/GRANT is strictly confined to pre-labelled loopback disposable containers.
This harness is never an operator/Aiven procedure; no raw artifacts are emitted.
"""
import argparse
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile

sys.dont_write_bytecode = True
from integrated_release20_audit import BASE, ROOT, source, snapshot, parse_full_ledger
from integrated_release20_diagnose import CANDIDATE, KIND, diagnose, projections
from p0_mysql_metadata_grants_guard import verify_grants
from integrated_release20_semantics import KIND as SEMANTIC_KIND, parse, semantic_diagnose
from integrated_release20_semantic_fixtures import canonical_cases, structural_cases


def rehearse(series, write_reference=False, write_semantic_reference=False, fixed_source_only=False):
    name, port = {'8.0': ('canovia-integration-80', '13380'),
                  '8.4': ('canovia-integration-84', '13384')}[series]
    with tempfile.TemporaryDirectory(prefix='canovia-diagnostic-ci-') as tmp:
        os.chmod(tmp, 0o700)
        with (Path(tmp) / 'private-errors').open('w+b') as errors:
            def run(argv, data=None, env=None, deny=False):
                proc = subprocess.run(argv, input=data, env=env, stdout=subprocess.PIPE,
                                      stderr=errors, timeout=180, cwd=ROOT)
                if (proc.returncode == 0) == deny:
                    raise ValueError()
                return proc.stdout.decode('utf-8') if not deny else ''

            state = json.loads(run(['docker', 'inspect', name]))[0]
            if (state['Config']['Labels'].get('canovia.integration') != '2026-10-16'
                    or state['NetworkSettings']['Ports']['3306/tcp'] != [{'HostIp': '127.0.0.1', 'HostPort': port}]):
                raise ValueError()
            head = run(['git', 'rev-parse', 'HEAD']).strip()
            src = source(ROOT, CANDIDATE)
            if fixed_source_only:
                from release20_proposal_packet import packet
                packet(head)  # Verify independent proposal and immutable audit.
            elif source(ROOT, head) != src:
                raise ValueError()
            # Ensure ignored/assume-unchanged source cannot change expectations.
            protected = ['scripts/sql/integrated_release20_metadata.sql',
                         'scripts/sql/p0_mysql_readonly_full_migration_ledger.sql',
                         'scripts/ci/integrated_release20_reference/' + series + '.json']
            for path in protected:
                if run(['git', 'show', CANDIDATE + ':' + path]).encode() != (ROOT / path).read_bytes():
                    raise ValueError()

            def sql(statement, narrow=False, deny=False):
                account, password = ('canovia_diagnostic_auditor', 'ci-only-diagnostic-password') if narrow else ('root', 'ci-only-root-password')
                return run(['docker', 'exec', '-i', '-e', 'MYSQL_PWD=' + password, name,
                            'mysql', '-u' + account, '--database=canovia_p0_ci',
                            '--batch', '--raw', '--skip-column-names'], statement.encode(), deny=deny)

            if not sql('SELECT VERSION()').startswith(series + '.'):
                raise ValueError()
            sql('DROP DATABASE canovia_p0_ci; CREATE DATABASE canovia_p0_ci CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;')
            env = {key: os.environ[key] for key in ('PATH', 'PHPRC', 'PHP_INI_SCAN_DIR', 'LD_LIBRARY_PATH', 'LANG') if key in os.environ}
            env.update(APP_ENV='testing', APP_KEY='base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
                       DB_CONNECTION='mysql', DB_HOST='127.0.0.1', DB_PORT=port, DB_DATABASE='canovia_p0_ci',
                       DB_USERNAME='canovia_ci', DB_PASSWORD='ci-only-ephemeral-password', DB_URL='',
                       CACHE_STORE='array', SESSION_DRIVER='array', QUEUE_CONNECTION='sync', MAIL_MAILER='array',
                       CANOVIA_PUBLIC_RELEASE_LEVEL='0', CI='true', CANOVIA_P0_DISPOSABLE_MYSQL_CI='1',
                       CANOVIA_NATIVE_AI_DRIVER='disabled', FEATURE_CANOVIA_AI='false')
            migration_dir = Path(tmp) / 'migrations'
            migration_dir.mkdir()
            for migration in src['baseline']:
                path = 'database/migrations/' + migration + '.php'
                (migration_dir / (migration + '.php')).write_text(run(['git', 'show', BASE + ':' + path]))
            run(['php', 'artisan', 'migrate', '--realpath', '--path=' + str(migration_dir), '--force', '--no-interaction'], env=env)
            metadata_sql = (ROOT / protected[0]).read_text()
            ledger_sql = (ROOT / protected[1]).read_text()
            reference = json.loads((ROOT / protected[2]).read_text())
            baseline_raw, old_ledger = sql(metadata_sql), parse_full_ledger(sql(ledger_sql))
            if snapshot(baseline_raw) != reference['baseline94']:
                raise ValueError()
            diagnostic = dict(kind=KIND, candidate_sha=CANDIDATE, baseline_sha=BASE,
                              migration_seal=src['migration_seal'], engine=series,
                              baseline94=projections(baseline_raw))
            supplement = (ROOT / 'scripts/sql/integrated_release20_semantic_metadata.sql').read_text()
            semantic = dict(kind=SEMANTIC_KIND, candidate_sha=CANDIDATE, baseline_sha=BASE,
                            migration_seal=src['migration_seal'], engine=series,
                            baseline94=parse(sql(supplement), baseline_raw)[0])
            semantic_reports = structural_cases(sql, metadata_sql, supplement)
            semantic_reports.update(canonical_cases(sql, src, old_ledger, baseline_raw, reference,
                                                     diagnostic, semantic, metadata_sql, supplement))
            reports = {}

            def check(label, ledger, raw, expected_result, expected_sections):
                result = diagnose(src, ledger, raw, reference, diagnostic)
                actual = {s['section']: s['classification'] for s in result.get('sections', []) if s['classification'] != 'MATCH'}
                if result['result'] != expected_result or actual != expected_sections or result['release_authorized']:
                    raise ValueError()
                reports[label] = dict(result=result['result'], code=result['code'], differences=actual)
                return result

            check('normal94', old_ledger, baseline_raw, 'REVIEW_REQUIRED', {})
            sql('ALTER TABLE users CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci')
            check('collation_only94', old_ledger, sql(metadata_sql), 'BLOCK',
                  {'table': 'COLLATION_ONLY_DIFFERENCE', 'column': 'COLLATION_ONLY_DIFFERENCE'})
            sql('ALTER TABLE users CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci')
            sql("ALTER TABLE users ALTER COLUMN name SET DEFAULT 'diagnostic-only'")
            check('definition94', old_ledger, sql(metadata_sql), 'BLOCK', {'column': 'DEFINITION_DIFFERENCE'})
            sql('ALTER TABLE users ALTER COLUMN name DROP DEFAULT')
            sql('CREATE TABLE diagnostic_partial_ddl (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY)')
            check('partial_ddl94', old_ledger, sql(metadata_sql), 'BLOCK',
                  {'table': 'COUNT_DIFFERENCE', 'column': 'COUNT_DIFFERENCE', 'index': 'COUNT_DIFFERENCE'})
            sql('DROP TABLE diagnostic_partial_ddl')
            if snapshot(sql(metadata_sql)) != reference['baseline94']:
                raise ValueError()
            # Execute the exact candidate's 20 new definitions, not moving HEAD.
            for migration in set(src['candidate']) - set(src['baseline']):
                path = 'database/migrations/' + migration + '.php'
                (migration_dir / (migration + '.php')).write_text(run(['git', 'show', CANDIDATE + ':' + path]))
            run(['php', 'artisan', 'migrate', '--realpath', '--path=' + str(migration_dir), '--force', '--no-interaction'], env=env)
            full_raw, full_ledger = sql(metadata_sql), parse_full_ledger(sql(ledger_sql))
            if snapshot(full_raw) != reference['candidate114']:
                raise ValueError()
            diagnostic['candidate114'] = projections(full_raw)
            semantic['candidate114'] = parse(sql(supplement), full_raw)[0]
            full_semantic = semantic_diagnose(src, full_ledger, full_raw, reference, diagnostic,
                                             sql(supplement), semantic)
            if full_semantic['result'] != 'REVIEW_REQUIRED' or any(s['classification'] != 'MATCH' for s in full_semantic['semantic_sections']):
                raise ValueError()
            semantic_reports['semantic_normal114'] = dict(result='REVIEW_REQUIRED')
            check('normal114', full_ledger, full_raw, 'REVIEW_REQUIRED', {})
            sql('ALTER TABLE learning_context_source_epochs DROP FOREIGN KEY learning_context_source_epochs_plan_id_foreign')
            check('missing_foreign_key114', full_ledger, sql(metadata_sql), 'BLOCK', {'foreign_key': 'COUNT_DIFFERENCE'})
            sql('ALTER TABLE learning_context_source_epochs ADD CONSTRAINT learning_context_source_epochs_plan_id_foreign FOREIGN KEY (plan_id) REFERENCES plans(id) ON DELETE CASCADE')
            sql('ALTER TABLE learning_context_source_epochs ADD CONSTRAINT diagnostic_check CHECK (epoch >= 0)')
            check('unexpected_check114', full_ledger, sql(metadata_sql), 'BLOCK', {'check': 'COUNT_DIFFERENCE'})
            sql('ALTER TABLE learning_context_source_epochs DROP CHECK diagnostic_check')
            # Only this labelled disposable server gets a synthetic account.
            sql("DROP USER IF EXISTS 'canovia_diagnostic_auditor'@'%'; CREATE USER 'canovia_diagnostic_auditor'@'%' IDENTIFIED BY 'ci-only-diagnostic-password'; GRANT REFERENCES ON canovia_p0_ci.* TO 'canovia_diagnostic_auditor'@'%'; GRANT SELECT ON canovia_p0_ci.migrations TO 'canovia_diagnostic_auditor'@'%';")
            try:
                if not verify_grants(sql('SHOW GRANTS FOR CURRENT_USER()', narrow=True), 'canovia_p0_ci'):
                    raise ValueError()
                # Narrow auditor really cannot read users or create triggers.
                sql('SELECT * FROM users LIMIT 0', narrow=True, deny=True)
                sql('CREATE TRIGGER diagnostic_denied BEFORE UPDATE ON users FOR EACH ROW SET NEW.name=OLD.name', narrow=True, deny=True)
                narrow_raw = sql(metadata_sql, narrow=True)
                narrow_semantic = semantic_diagnose(src, full_ledger, narrow_raw, reference, diagnostic,
                                                   sql(supplement, narrow=True), semantic)
                if narrow_semantic['result'] != 'BLOCK' or any(s['classification'] != 'MATCH' for s in narrow_semantic['semantic_sections']):
                    raise ValueError()
                semantic_reports['narrow_semantic_visibility'] = dict(result='BLOCK', index_and_foreign_key_definitions_match=True,
                                                                    trigger_visibility_proven=False)
                result = check('trigger_invisible114', full_ledger, narrow_raw, 'BLOCK', {'trigger': 'COUNT_DIFFERENCE'})
                if result['trigger_visibility'] != 'ABSENCE_OR_INVISIBILITY_NOT_DISTINGUISHABLE':
                    raise ValueError()
                # The SAME empty trigger view also occurs for actual absence.
                triggers = [line.split('\t') for line in full_raw.splitlines() if line.startswith('trigger\t')]
                if len(triggers) != 3:
                    raise ValueError()
                for row in triggers:
                    if not all(c.isalnum() or c == '_' for c in row[2]):
                        raise ValueError()
                    sql('DROP TRIGGER `' + row[2] + '`')
                absent_raw = sql(metadata_sql)
                check('trigger_absent114', full_ledger, absent_raw, 'BLOCK', {'trigger': 'COUNT_DIFFERENCE'})
                if snapshot(absent_raw) != snapshot(narrow_raw):
                    raise ValueError()
            finally:
                sql("DROP USER IF EXISTS 'canovia_diagnostic_auditor'@'%'")
            # Rehearse all 20 candidate migrations with historical aliases.
            # Semantic equality at94 does NOT prove migration-name compatibility.
            historical = Path(tmp) / 'historical-only'
            historical.mkdir()
            for migration in src['baseline']:
                (historical / (migration + '.php')).write_bytes((migration_dir / (migration + '.php')).read_bytes())
            for alias_transition in (False, True):
                sql('DROP DATABASE canovia_p0_ci; CREATE DATABASE canovia_p0_ci CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;')
                run(['php', 'artisan', 'migrate', '--realpath', '--path=' + str(historical), '--force', '--no-interaction'], env=env)
                sql('ALTER TABLE intelligence_decision_traces RENAME INDEX intelligence_decision_traces_domain_index TO diagnostic_alias_idx')
                fk = next(row.split('\t') for row in baseline_raw.splitlines() if row.startswith('foreign_key\tintelligence_decision_traces\tintelligence_decision_traces_user_id_foreign\t'))
                sql(f'ALTER TABLE intelligence_decision_traces DROP FOREIGN KEY intelligence_decision_traces_user_id_foreign, ADD CONSTRAINT diagnostic_alias_fk FOREIGN KEY (user_id) REFERENCES users(id) ON UPDATE {fk[7]} ON DELETE {fk[8]}')
                if alias_transition:
                    sql('ALTER TABLE mcp_linked_subjects RENAME INDEX mcp_linked_subjects_identity_fingerprint_unique TO diagnostic_identity_alias')
                before_alias = sql(metadata_sql)
                check_alias = semantic_diagnose(src, old_ledger, before_alias, reference, diagnostic, sql(supplement), semantic)
                if check_alias['result'] != 'BLOCK' or any(s['classification'] != 'NAME_ONLY_DIFFERENCE' for s in check_alias['semantic_sections']):
                    raise ValueError()
                run(['php', 'artisan', 'migrate', '--realpath', '--path=' + str(migration_dir), '--force', '--no-interaction'], env=env)
                after_alias, after_ledger = sql(metadata_sql), parse_full_ledger(sql(ledger_sql))
                if set(after_ledger) != set(src['candidate']):
                    raise ValueError()
                result = semantic_diagnose(src, after_ledger, after_alias, reference, diagnostic, sql(supplement), semantic)
                expected = {'index': 'SEMANTIC_DEFINITION_DIFFERENCE' if alias_transition else 'NAME_ONLY_DIFFERENCE',
                            'foreign_key': 'NAME_ONLY_DIFFERENCE'}
                if result['result'] != 'BLOCK' or {s['section']: s['classification'] for s in result['semantic_sections']} != expected:
                    raise ValueError()
                semantic_reports['upgrade_named_transition_alias' if alias_transition else 'upgrade_p0_aliases'] = dict(
                    result='BLOCK', applied_count=114, classifications=expected)
            semantic_path = ROOT / 'scripts/ci/integrated_release20_semantic_reference' / (series + '.json')
            if write_semantic_reference:
                if os.environ.get('GITHUB_ACTIONS') == 'true':
                    raise ValueError()
                semantic_path.parent.mkdir(exist_ok=True)
                semantic_path.write_text(json.dumps(semantic, indent=2, sort_keys=True) + '\n')
            elif json.loads(semantic_path.read_text()) != semantic:
                raise ValueError()
            path = ROOT / 'scripts/ci/integrated_release20_diagnostic_reference' / (series + '.json')
            if write_reference:
                if os.environ.get('GITHUB_ACTIONS') == 'true':
                    raise ValueError()
                path.parent.mkdir(exist_ok=True)
                path.write_text(json.dumps(diagnostic, indent=2, sort_keys=True) + '\n')
            elif json.loads(path.read_text()) != diagnostic:
                raise ValueError()
            return dict(result='SYNTHETIC_TESTED', tooling_sha=head, candidate_sha=CANDIDATE,
                        engine=series, fixtures=reports, semantic_fixtures=semantic_reports, reference_exact_hashes_unchanged=True,
                        real_aiven_connected=False, release_authorized=False)


def main():
    p = argparse.ArgumentParser(description=__doc__)
    p.add_argument('--series', choices=('8.0', '8.4'), required=True)
    p.add_argument('--write-reference', action='store_true')
    p.add_argument('--write-semantic-reference', action='store_true')
    p.add_argument('--fixed-source-only', action='store_true', help='Rehearse original c09 migrations under independently checked proposal tooling')
    args = p.parse_args()
    try:
        result = rehearse(args.series, args.write_reference, args.write_semantic_reference, args.fixed_source_only)
    except Exception:
        print(json.dumps({'result': 'BLOCK', 'code': 'DISPOSABLE_DIAGNOSTIC_FIXTURE_FAILED'}))
        return 2
    print(json.dumps(result, sort_keys=True))
    return 0


if __name__ == '__main__':
    sys.exit(main())
