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


def rehearse(series, write_reference=False):
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
            if source(ROOT, head) != src:
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
            path = ROOT / 'scripts/ci/integrated_release20_diagnostic_reference' / (series + '.json')
            if write_reference:
                if os.environ.get('GITHUB_ACTIONS') == 'true':
                    raise ValueError()
                path.parent.mkdir(exist_ok=True)
                path.write_text(json.dumps(diagnostic, indent=2, sort_keys=True) + '\n')
            elif json.loads(path.read_text()) != diagnostic:
                raise ValueError()
            return dict(result='SYNTHETIC_TESTED', tooling_sha=head, candidate_sha=CANDIDATE,
                        engine=series, fixtures=reports, reference_exact_hashes_unchanged=True,
                        real_aiven_connected=False, release_authorized=False)


def main():
    p = argparse.ArgumentParser(description=__doc__)
    p.add_argument('--series', choices=('8.0', '8.4'), required=True)
    p.add_argument('--write-reference', action='store_true')
    args = p.parse_args()
    try:
        result = rehearse(args.series, args.write_reference)
    except Exception:
        print(json.dumps({'result': 'BLOCK', 'code': 'DISPOSABLE_DIAGNOSTIC_FIXTURE_FAILED'}))
        return 2
    print(json.dumps(result, sort_keys=True))
    return 0


if __name__ == '__main__':
    sys.exit(main())
