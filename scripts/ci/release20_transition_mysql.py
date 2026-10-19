#!/usr/bin/env python3
"""Only labelled disposable Docker; no provider connection or raw log artifacts."""
import argparse
import json
import os
from pathlib import Path
import re
import subprocess
import sys
import tempfile
import time

sys.dont_write_bytecode = True
from integrated_release20_audit import BASE, ROOT, source, snapshot, parse_full_ledger, compare
from integrated_release20_diagnose import CANDIDATE
from integrated_release20_semantics import parse

PARENT = 'e6124170e4ab8f92709d7683544d999822a96581'


def contract_from(before, after):
    def rows(raw, kind):
        return [r.split('\t') for r in raw.splitlines() if r.startswith(kind + '\t')]
    old_tables = {r[1] for r in rows(before, 'table')}
    columns = {(r[1], r[3]) for r in rows(before, 'column')}
    indexes = {(r[1], r[2]) for r in rows(before, 'index')}
    foreign = {r[2] for r in rows(before, 'foreign_key')}
    result = dict(kind='SOURCE_DERIVED_PENDING20_ABSENCE_CONTRACT', fixed_candidate=CANDIDATE,
                  absent_tables=sorted({r[1] for r in rows(after, 'table')} - old_tables),
                  absent_columns={}, absent_index_names={}, absent_foreign_key_names=[], absent_trigger_names=[])
    for row in rows(after, 'column'):
        if row[1] in old_tables and (row[1], row[3]) not in columns:
            result['absent_columns'].setdefault(row[1], []).append(row[3])
    for table, name in sorted({(r[1], r[2]) for r in rows(after, 'index')} - indexes):
        if table in old_tables:
            result['absent_index_names'].setdefault(table, []).append(name)
    result['absent_foreign_key_names'] = sorted({r[2] for r in rows(after, 'foreign_key')} - foreign)
    result['absent_trigger_names'] = sorted({r[2] for r in rows(after, 'trigger')})
    return result


def rehearse(series, write_contract=False):
    name, port = {'8.0': ('canovia-integration-80', '13380'), '8.4': ('canovia-integration-84', '13384')}[series]
    with tempfile.TemporaryDirectory(prefix='canovia-transition-private-') as directory:
        os.chmod(directory, 0o700)
        tmp = Path(directory)
        with (tmp / 'errors').open('w+b') as errors:
            def command(args, data=None, env=None, deny=False):
                proc = subprocess.run(args, input=data, env=env, cwd=ROOT, stdout=subprocess.PIPE, stderr=errors, timeout=180)
                if (proc.returncode == 0) == deny:
                    raise ValueError()
                return proc.stdout
            state = json.loads(command(['docker', 'inspect', name]))[0]
            if (state['Config']['Labels'].get('canovia.integration') != '2026-10-16'
                    or state['NetworkSettings']['Ports']['3306/tcp'] != [{'HostIp': '127.0.0.1', 'HostPort': port}]):
                raise ValueError()
            prefix = ['docker', 'exec', '-i', '-e', 'MYSQL_PWD=ci-only-root-password', name]
            def sql(text):
                return command(prefix + ['mysql', '-uroot', '--batch', '--raw', '-N', 'canovia_p0_ci'], text.encode()).decode('utf-8')
            until = time.monotonic() + 60
            while True:
                try:
                    version = sql('SELECT VERSION()').strip()
                    break
                except ValueError:
                    if time.monotonic() > until:
                        raise
                    time.sleep(1)
            if not version.startswith(series + '.'):
                raise ValueError()
            head = command(['git', 'rev-parse', 'HEAD']).decode().strip()
            src, fixed = source(ROOT, head), source(ROOT, CANDIDATE)
            if src['baseline'] != fixed['baseline'] or set(src['candidate']) != set(fixed['candidate']):
                raise ValueError()
            env = {k: os.environ[k] for k in ('PATH', 'PHPRC', 'PHP_INI_SCAN_DIR', 'LD_LIBRARY_PATH', 'LANG') if k in os.environ}
            env.update(APP_ENV='testing', APP_KEY='base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
                       DB_CONNECTION='mysql', DB_HOST='127.0.0.1', DB_PORT=port, DB_DATABASE='canovia_p0_ci',
                       DB_USERNAME='canovia_ci', DB_PASSWORD='ci-only-ephemeral-password', DB_URL='',
                       CACHE_STORE='array', SESSION_DRIVER='database', QUEUE_CONNECTION='sync', MAIL_MAILER='array',
                       CANOVIA_PUBLIC_RELEASE_LEVEL='0', CI='true', CANOVIA_P0_DISPOSABLE_MYSQL_CI='1',
                       CANOVIA_NATIVE_AI_DRIVER='disabled', FEATURE_CANOVIA_AI='false')
            def php(*args):
                return command(['php', *args], env=env)
            def migrate(path=None):
                args = ['artisan', 'migrate', '--force', '--no-interaction']
                if path is not None:
                    args += ['--realpath', '--path=' + str(path)]
                return php(*args)
            def reset():
                sql('DROP DATABASE canovia_p0_ci; CREATE DATABASE canovia_p0_ci CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;')
            old_dir, fixed_dir = tmp / 'old', tmp / 'fixed'
            old_dir.mkdir(); fixed_dir.mkdir()
            for migration in fixed['candidate']:
                path = 'database/migrations/' + migration + '.php'
                raw = command(['git', 'show', CANDIDATE + ':' + path])
                (fixed_dir / (migration + '.php')).write_bytes(raw)
                if migration in fixed['baseline']:
                    (old_dir / (migration + '.php')).write_bytes(raw)
            metadata_sql = (ROOT / 'scripts/sql/integrated_release20_metadata.sql').read_text()
            supplement = (ROOT / 'scripts/sql/integrated_release20_semantic_metadata.sql').read_text()
            ledger_sql = (ROOT / 'scripts/sql/p0_mysql_readonly_full_migration_ledger.sql').read_text()
            ref = json.loads((ROOT / 'scripts/ci/integrated_release20_reference' / (series + '.json')).read_text())
            def capture():
                raw = sql(metadata_sql)
                return raw, parse(sql(supplement), raw)[0]
            def ledger():
                return parse_full_ledger(sql(ledger_sql))
            def data_dump():
                return command(prefix + ['mysqldump', '-uroot', '--no-create-info', '--skip-triggers', '--skip-add-locks',
                               '--skip-comments', '--compact', '--set-gtid-purged=OFF', '--column-statistics=0',
                               '--order-by-primary', '--hex-blob', 'canovia_p0_ci'])
            reset(); migrate(old_dir)
            php('scripts/ci/integration_upgrade_fixture.php', 'seed')
            seal = tmp / 'old-rows.json'
            php('scripts/ci/integration_upgrade_fixture.php', 'snapshot', str(seal))
            baseline_raw, baseline = capture()
            if snapshot(baseline_raw) != ref['baseline94']:
                raise ValueError()
            dump = command(prefix + ['mysqldump', '-uroot', '--single-transaction', '--routines', '--triggers',
                                     '--set-gtid-purged=OFF', '--column-statistics=0', 'canovia_p0_ci'])
            migrate(fixed_dir)
            canonical_raw, canonical = capture()
            if snapshot(canonical_raw) != ref['candidate114']:
                raise ValueError()
            expected = contract_from(baseline_raw, canonical_raw)
            contract_path = ROOT / 'resources/release20_pending_schema_contract.json'
            if write_contract:
                if os.environ.get('GITHUB_ACTIONS') == 'true':
                    raise ValueError()
                contract_path.write_text(json.dumps(expected, indent=2, sort_keys=True) + '\n')
                return dict(result='DISPOSABLE_CONTRACT_DERIVED', version=version, release_authorized=False)
            if json.loads(contract_path.read_text()) != expected:
                raise ValueError()

            reports = {}
            def restore():
                reset()
                command(prefix + ['mysql', '-uroot', 'canovia_p0_ci'], dump)
            def equivalent(a, b):
                return (a['semantics'] == b['semantics']
                        and all(a['physical']['sections'][s] == b['physical']['sections'][s]
                                for s in ('table', 'column', 'check', 'trigger')))
            def retained():
                # Existing fixture verifies every historical column and row;
                # add its known synthetic epoch marker, never a user record.
                sql("INSERT INTO learning_context_source_epochs(plan_id,epoch,last_mutation_kind) SELECT id,7,'synthetic_recovery_seal' FROM plans WHERE category='資格学習'")
                php('scripts/ci/integration_upgrade_fixture.php', 'verify', str(seal))
                php('scripts/ci/release20_transition_fixture.php', 'repeat')
                php('scripts/ci/integration_upgrade_fixture.php', 'verify', str(seal))
                php('scripts/ci/integration_upgrade_fixture.php', 'fences')
            def complete(label):
                migrate()
                raw, shape = capture()
                if set(ledger()) != set(src['candidate']) or not equivalent(shape, canonical):
                    raise ValueError()
                # Original audit is never converted into a waiver/new PASS.
                if compare(src, ledger(), snapshot(raw), ref)['result'] != 'BLOCK':
                    raise ValueError()
                retained()
                reports[label] = dict(result='SYNTHETIC_COMPATIBLE_ONLY', applied_count=114,
                                     legacy_rows_retained=True, repeated_ddl_count=0, strict_audit='BLOCK')
            def blocked(label, diagnostic=False):
                raw, shape = capture()
                before_data, before_ledger = data_dump(), ledger()
                if diagnostic:
                    if equivalent(shape, baseline):
                        raise ValueError()
                    # Existing strict/semantic audit rejects before migration.
                    if compare(fixed, before_ledger, snapshot(raw), ref)['result'] != 'BLOCK':
                        raise ValueError()
                else:
                    php('scripts/ci/release20_transition_fixture.php', 'block')
                if capture()[1] != shape or data_dump() != before_data or ledger() != before_ledger:
                    raise ValueError()
                php('scripts/ci/release20_transition_fixture.php', 'verify-legacy', str(seal))
                reports[label] = dict(result='BLOCK', ddl_after_denial=0, all_rows_and_ledger_retained=True)

            # Synthetic aliases only: no actual Aiven identifiers were read.
            table = 'intelligence_decision_traces'
            old_fk = 'intelligence_decision_traces_user_id_foreign'
            fk = next(r.split('\t') for r in baseline_raw.splitlines() if r.startswith('foreign_key\t' + table + '\t' + old_fk + '\t'))
            if fk[7] not in {'RESTRICT', 'NO ACTION', 'CASCADE', 'SET NULL'} or fk[8] != 'SET NULL':
                raise ValueError()
            def alias_index(name='transition_alias_idx'):
                sql(f'ALTER TABLE {table} RENAME INDEX intelligence_decision_traces_domain_index TO {name}')
            def alias_fk(name='transition_alias_fk', parent='users', delete='SET NULL'):
                if not re.fullmatch('[A-Za-z0-9_]+', name):
                    raise ValueError()
                sql(f'ALTER TABLE {table} DROP FOREIGN KEY {old_fk}; ALTER TABLE {table} ADD CONSTRAINT {name} FOREIGN KEY(user_id) REFERENCES {parent}(id) ON UPDATE {fk[7]} ON DELETE {delete}')
            for label, index, foreign in [('clean', False, False), ('index_alias', True, False),
                                           ('foreign_alias', False, True), ('both_aliases', True, True)]:
                restore()
                if index: alias_index()
                if foreign: alias_fk()
                if not equivalent(capture()[1], baseline):
                    raise ValueError()
                complete(label)
            restore(); alias_index('mcp_active_subject_identity_unique'); complete('index_name_other_table_scope')

            restore()
            sql(f'ALTER TABLE {table} DROP INDEX intelligence_decision_scope_created_idx, ADD INDEX intelligence_decision_scope_created_idx(scope_type,domain,scope_id,created_at)')
            blocked('composite_column_order', diagnostic=True)
            restore()
            sql(f'ALTER TABLE {table} DROP INDEX intelligence_decision_traces_domain_index, ADD UNIQUE INDEX intelligence_decision_traces_domain_index(domain)')
            blocked('unique_semantic_change', diagnostic=True)
            restore()
            sql(f'ALTER TABLE {table} DROP INDEX intelligence_decision_traces_domain_index, ADD INDEX intelligence_decision_traces_domain_index(decision_type)')
            blocked('same_index_name_wrong_definition', diagnostic=True)
            for label, parent, delete in [('foreign_parent_change', 'plans', 'SET NULL'), ('foreign_delete_change', 'users', 'CASCADE')]:
                restore(); alias_fk(old_fk, parent, delete); blocked(label, diagnostic=True)
            restore()
            sql('ALTER TABLE mcp_linked_subjects RENAME INDEX mcp_linked_subjects_identity_fingerprint_unique TO transition_old_alias')
            blocked('M08_old_unique_alias')
            restore()
            sql('ALTER TABLE mcp_linked_subjects ADD UNIQUE INDEX transition_duplicate_unique(identity_fingerprint)')
            blocked('M08_duplicate_unique')
            restore()
            sql('ALTER TABLE mcp_linked_subjects ADD UNIQUE INDEX transition_prefix_unique(identity_fingerprint(16))')
            blocked('M08_conflicting_unique_prefix')
            restore()
            sql('ALTER TABLE mcp_linked_subjects ADD INDEX mcp_active_subject_identity_unique(provider_key)')
            blocked('new_index_name_collision')
            restore()
            sql('ALTER TABLE mcp_linked_subjects DROP INDEX mcp_linked_subjects_identity_fingerprint_unique, ADD UNIQUE INDEX mcp_linked_subjects_identity_fingerprint_unique(identity_fingerprint(16))')
            blocked('M08_same_name_prefix_difference')
            restore()
            alias_fk(expected['absent_foreign_key_names'][0]); blocked('new_foreign_name_database_collision')
            restore()
            sql('CREATE TRIGGER canovia_member_authorization_epoch BEFORE UPDATE ON users FOR EACH ROW SET NEW.name=OLD.name')
            blocked('new_trigger_database_collision')

            for label in ('M03', 'M04', 'M08_COLUMN', 'M08_UNIQUE', 'M18', 'M19', 'M20'):
                restore()
                php('scripts/ci/release20_transition_fixture.php', 'interrupt', label)
                if len(ledger()) not in range(94, 114):
                    raise ValueError()
                if label.startswith('M08'):
                    complete('interrupted_' + label + '_verified_resume')
                else:
                    blocked('interrupted_' + label + '_retry')
            return dict(result='SYNTHETIC_TESTED', engine=series, version=version, tooling_sha=head,
                        diagnostic_base_sha=PARENT, fixed_candidate=CANDIDATE,
                        source_change_requires_new_candidate=True, cases=reports,
                        original_strict_audit_unchanged=True, production_database_modified=False,
                        real_aiven_connected=False, release_authorized=False)


def main():
    p = argparse.ArgumentParser(description=__doc__)
    p.add_argument('--series', choices=('8.0', '8.4'), required=True)
    p.add_argument('--write-contract', action='store_true')
    args = p.parse_args()
    try:
        result = rehearse(args.series, args.write_contract)
    except Exception:
        print(json.dumps(dict(result='BLOCK', code='DISPOSABLE_TRANSITION_FIXTURE_FAILED', release_authorized=False)))
        return 2
    print(json.dumps(result, sort_keys=True))
    return 0


if __name__ == '__main__':
    sys.exit(main())
