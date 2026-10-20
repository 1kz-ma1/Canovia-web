#!/usr/bin/env python3
"""Synthetic-only logical restore into an independent network-isolated MySQL.

Fixed labelled Docker sources only. No remote host, credentials, dump-file input,
real backup, provider restore or production authorization capability.
"""
import argparse
from collections import defaultdict
import hashlib
import json
from pathlib import Path
import re
import subprocess
import time
import uuid

ROOT = Path(__file__).resolve().parents[2]
DATABASE = 'canovia_p0_ci'
LABEL = 'canovia.restore.synthetic'
PASSWORD = 'ci-only-root-password'


class Block(Exception):
    pass


def require(condition, code):
    if not condition:
        raise Block(code)


def execute(argv, data=None, deny=None, timeout=120):
    """Never leak commands, SQL, metadata, dump bytes or client errors."""
    try:
        p = subprocess.run(argv, input=data, stdout=subprocess.PIPE,
                           stderr=subprocess.PIPE, timeout=timeout)
    except (OSError, subprocess.TimeoutExpired):
        raise Block('SYNTHETIC_COMMAND_UNAVAILABLE') from None
    if deny is not None:
        require(p.returncode != 0 and re.search(rb'ERROR ' + str(deny).encode() + rb'\b', p.stderr),
                'EXPECTED_ENGINE_REJECTION_NOT_OBSERVED')
        return b''
    require(p.returncode == 0, 'SYNTHETIC_COMMAND_FAILED')
    return p.stdout


def identity(state, series):
    port = {'8.0': '13380', '8.4': '13384'}[series]
    require(state['Config']['Labels'].get('canovia.integration') == '2026-10-16'
            and state['NetworkSettings']['Ports'].get('3306/tcp') ==
            [{'HostIp': '127.0.0.1', 'HostPort': port}], 'SOURCE_DISPOSABLE_IDENTITY_REJECTED')


def inventory(row):
    # Privileged inventory on this owned synthetic instance; absence seen by a
    # narrow real principal would NOT prove absence of hidden objects.
    cells = row.strip().split(b'\t')
    require(len(cells) == 5 and all(x.isdigit() for x in cells), 'INVENTORY_UNVERIFIED')
    tables, nontransactional, views, routines, events = map(int, cells)
    require(tables > 0 and not any([nontransactional, views, routines, events]),
            'UNSUPPORTED_DUMP_OBJECTS_OR_ENGINE')


def parity(before, after, code):
    require(before == after, code)


def quote(identifier):
    require(re.fullmatch(r'[A-Za-z0-9_]+', identifier) is not None, 'UNSUPPORTED_SCHEMA_IDENTIFIER')
    return '`' + identifier + '`'


def sql(container, statement, user='root', deny=None):
    password = PASSWORD if user == 'root' else 'ci-only-export-password'
    return execute(['docker', 'exec', '-i', '-e', 'MYSQL_PWD=' + password, container,
                    'mysql', '-u' + user, '--batch', '--raw', '-N', DATABASE],
                   statement.encode(), deny=deny)


def dump(container, user='root', data_only=False):
    options = ['--single-transaction', '--quick', '--skip-lock-tables', '--no-tablespaces',
               '--set-gtid-purged=OFF', '--column-statistics=0', '--hex-blob',
               '--default-character-set=utf8mb4', '--skip-comments', '--order-by-primary']
    if data_only:
        options += ['--no-create-info', '--skip-triggers', '--skip-add-locks', '--skip-disable-keys', '--compact']
    else:
        options += ['--triggers']
    password = PASSWORD if user == 'root' else 'ci-only-export-password'
    return execute(['docker', 'exec', '-e', 'MYSQL_PWD=' + password, container,
                    'mysqldump', '-u' + user] + options + [DATABASE])


def orphans(container):
    raw = sql(container, "SELECT TABLE_NAME,CONSTRAINT_NAME,ORDINAL_POSITION,COLUMN_NAME,"
              "REFERENCED_TABLE_NAME,REFERENCED_COLUMN_NAME,"
              "IF(REFERENCED_TABLE_SCHEMA=DATABASE(),1,0) FROM information_schema.KEY_COLUMN_USAGE "
              "WHERE TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL "
              "ORDER BY TABLE_NAME,CONSTRAINT_NAME,ORDINAL_POSITION")
    groups = defaultdict(list)
    for row in raw.decode('ascii').splitlines():
        child, name, ordinal, column, parent, referenced, local = row.split('\t')
        require(local == '1' and ordinal.isdigit(), 'CROSS_SCHEMA_FOREIGN_KEY_UNSUPPORTED')
        groups[(child, name, parent)].append((column, referenced))
    queries = []
    for (child, _, parent), columns in groups.items():
        join = ' AND '.join('c.' + quote(c) + '=p.' + quote(p) for c, p in columns)
        present = ' AND '.join('c.' + quote(c) + ' IS NOT NULL' for c, _ in columns)
        queries.append('SELECT COUNT(*) FROM ' + quote(child) + ' c LEFT JOIN ' + quote(parent)
                       + ' p ON ' + join + ' WHERE ' + present + ' AND p.' + quote(columns[0][1]) + ' IS NULL')
    require(bool(queries), 'FOREIGN_KEY_INVENTORY_MISSING')
    counts = sql(container, ' UNION ALL '.join(queries)).splitlines()
    require(len(counts) == len(queries) and all(x == b'0' for x in counts), 'RESTORED_FOREIGN_KEY_ORPHAN')
    return len(queries)


def trigger_probe(container, deny=None):
    # Rollback preserves the restored data. Object parity alone cannot show
    # that a restored DEFINER principal exists and can activate the trigger.
    return sql(container, 'START TRANSACTION; SET @p=(SELECT MIN(id) FROM plans); '
               'SET @e=(SELECT authorization_epoch FROM plans WHERE id=@p); '
               'UPDATE plans SET is_collaborative=1-is_collaborative WHERE id=@p; '
               'SELECT authorization_epoch=@e+1 FROM plans WHERE id=@p; ROLLBACK;', deny=deny)


def cleanup(target_id, run, source, users):
    cleanup_failed = False
    try:
        if target_id:
            current = json.loads(execute(['docker', 'inspect', target_id]))[0]
            require(current['Id'] == target_id and current['Config']['Labels'].get(LABEL) == run,
                    'CLEANUP_TARGET_IDENTITY_REJECTED')
            execute(['docker', 'rm', '-f', target_id])
            require(not execute(['docker', 'ps', '-aq', '--filter', 'label=' + LABEL + '=' + run]).strip(),
                    'CLEANUP_UNVERIFIED')
    except (Block, KeyError, ValueError):
        cleanup_failed = True
    for user in users:
        try:
            sql(source, "DROP USER '" + user + "'@'%'")
        except Block:
            cleanup_failed = True
    require(not cleanup_failed, 'CLEANUP_UNVERIFIED')


def rehearse(series, artifacts, expected=114):
    require(expected in [94, 114], 'UNSUPPORTED_RECOVERY_POINT')
    execute(['git', '-C', str(ROOT), 'diff', '--quiet', 'HEAD'])
    candidate = execute(['git', '-C', str(ROOT), 'rev-parse', 'HEAD']).decode().strip()
    source = {'8.0': 'canovia-integration-80', '8.4': 'canovia-integration-84'}[series]
    state = json.loads(execute(['docker', 'inspect', source]))[0]
    identity(state, series)
    require(sql(source, 'SELECT COUNT(*) FROM migrations').strip() == str(expected).encode(),
            'SOURCE_LEDGER_MISMATCH')
    inv_sql = ("SELECT (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()),"
               "(SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() "
               "AND TABLE_TYPE='BASE TABLE' AND (ENGINE IS NULL OR ENGINE<>'InnoDB')),"
               "(SELECT COUNT(*) FROM information_schema.VIEWS WHERE TABLE_SCHEMA=DATABASE()),"
               "(SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA=DATABASE()),"
               "(SELECT COUNT(*) FROM information_schema.EVENTS WHERE EVENT_SCHEMA=DATABASE())")
    inventory(sql(source, inv_sql))
    version = sql(source, 'SELECT VERSION()').decode().strip()
    require(version.startswith(series + '.'), 'MYSQL_VERSION_MISMATCH')
    schema_sql = (ROOT / 'scripts/sql/integrated_release20_metadata.sql').read_text()
    extra_sql = ("SELECT TABLE_NAME,INDEX_NAME,SEQ_IN_INDEX,COALESCE(COLLATION,'') "
                 "FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() "
                 "ORDER BY TABLE_NAME,INDEX_NAME,SEQ_IN_INDEX; "
                 "SELECT TRIGGER_NAME,DEFINER,SQL_MODE,CHARACTER_SET_CLIENT,COLLATION_CONNECTION,DATABASE_COLLATION "
                 "FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() ORDER BY TRIGGER_NAME")
    schema = sql(source, schema_sql) + sql(source, extra_sql)
    before = dump(source, data_only=True)
    source_uuid = sql(source, 'SELECT @@server_uuid')
    source_id = state['Id']
    run = uuid.uuid4().hex
    name = 'canovia-restore-' + series.replace('.', '') + '-' + run[:12]
    target_id = None
    users_created = []
    cases = {}
    start = time.monotonic()
    try:
        # New CI-only export principals, never the metadata inspector. TRIGGER
        # allows trigger DDL too; this is NOT a read-only database role.
        for user, privileges in [('canovia_export_ci', 'SELECT,SHOW VIEW,TRIGGER'),
                                 ('canovia_export_narrow_ci', 'SELECT,SHOW VIEW')]:
            sql(source, "CREATE USER '" + user + "'@'%' IDENTIFIED BY 'ci-only-export-password'")
            users_created.append(user)
            sql(source, 'GRANT ' + privileges + " ON canovia_p0_ci.* TO '" + user + "'@'%'")
        sql(source, "UPDATE plans SET title=title WHERE 1=0", user='canovia_export_ci', deny=1142)
        sql(source, "CREATE TABLE forbidden_export_write(id INT)", user='canovia_export_ci', deny=1142)
        cases['export_profile_data_and_table_ddl_denied'] = True
        payload = dump(source, 'canovia_export_ci')
        require(bool(payload), 'EMPTY_DUMP')
        digest = hashlib.sha256(payload).digest()
        # No mount, no published port, no external network, fresh tmpfs datadir.
        target_id = execute(['docker', 'run', '-d', '--pull=never', '--name', name,
            '--label', LABEL + '=' + run, '--network=none', '--memory=1g', '--cpus=1',
            '--tmpfs', '/var/lib/mysql:rw,nosuid,noexec,size=1g',
            '-e', 'MYSQL_ROOT_PASSWORD=' + PASSWORD, '-e', 'MYSQL_DATABASE=' + DATABASE,
            state['Image'], '--log-bin-trust-function-creators=1']).decode().strip()
        target = json.loads(execute(['docker', 'inspect', target_id]))[0]
        require(target['Id'] != source_id and target['Image'] == state['Image']
                and target['HostConfig']['NetworkMode'] == 'none'
                and not target['HostConfig']['PortBindings'] and not target['HostConfig']['Binds']
                and target['Config']['Labels'].get(LABEL) == run,
                'INDEPENDENT_TARGET_IDENTITY_REJECTED')
        for _ in range(90):
            try:
                execute(['docker', 'exec', '-e', 'MYSQL_PWD=' + PASSWORD, target_id,
                         'mysql', '--protocol=TCP', '-h127.0.0.1', '-uroot', '-Nse', 'SELECT 1'], timeout=5)
                break
            except Block:
                time.sleep(1)
        else:
            raise Block('INDEPENDENT_TARGET_NOT_READY')
        require(sql(target_id, 'SELECT @@server_uuid') != source_uuid, 'SERVER_INSTANCE_NOT_INDEPENDENT')

        def restore(data):
            # Destructive reset is only for this new owned synthetic target,
            # between deliberately independent negative fixtures. Never live.
            sql(target_id, 'DROP DATABASE canovia_p0_ci; CREATE DATABASE canovia_p0_ci '
                'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;')
            execute(['docker', 'exec', '-i', '-e', 'MYSQL_PWD=' + PASSWORD, target_id,
                     'mysql', '-uroot', DATABASE], data)

        def verify():
            parity(sql(target_id, 'SELECT COUNT(*) FROM migrations').strip(), str(expected).encode(),
                   'RESTORED_LEDGER_MISMATCH')
            parity(schema, sql(target_id, schema_sql) + sql(target_id, extra_sql), 'RESTORED_SCHEMA_MISMATCH')
            checked = orphans(target_id)
            parity(before, dump(target_id, data_only=True), 'RESTORED_DATA_MISMATCH')
            return checked

        def rejected(action, code):
            try:
                action()
            except Block as error:
                require(str(error) == code, 'NEGATIVE_CASE_WRONG_CLASSIFICATION')
            else:
                raise Block('NEGATIVE_CASE_ACCEPTED')

        rejected(lambda: parity(digest, hashlib.sha256(payload[:-1]).digest(), 'DUMP_INTEGRITY_MISMATCH'),
                 'DUMP_INTEGRITY_MISMATCH')
        cases['dump_integrity_change_rejected'] = True
        restore(payload)
        checked_fks = verify()
        cases['independent_restore_full_parity'] = True
        if expected == 114:
            # CREATE TRIGGER can succeed despite a nonexistent definer. Verify
            # the engine rejects use before preparing the synthetic principal.
            trigger_probe(target_id, deny=1449)
            cases['missing_definer_runtime_rejected'] = True
            sql(target_id, "CREATE USER 'canovia_ci'@'%' IDENTIFIED BY 'ci-only-ephemeral-password'; "
                "GRANT SELECT,UPDATE,TRIGGER ON canovia_p0_ci.* TO 'canovia_ci'@'%';")
            parity(trigger_probe(target_id).strip(), b'1', 'RESTORED_TRIGGER_NOT_EFFECTIVE')
            sql(target_id, 'UPDATE plan_members SET user_id=(SELECT user_id FROM plans '
                'WHERE id=plan_members.plan_id) WHERE id>0', deny=1644)
            sql(target_id, 'INSERT INTO learning_context_source_epochs(plan_id,epoch) '
                'SELECT plan_id,1 FROM learning_context_source_epochs LIMIT 1', deny=1062)
            cases['restored_trigger_binding_and_unique_enforced'] = True
            # A client may succeed while omitting invisible triggers. Refuse
            # either client failure or full-schema mismatch; neither is PASS.
            try:
                narrow = dump(source, 'canovia_export_narrow_ci')
            except Block:
                cases['trigger_invisible_export_rejected'] = True
            else:
                restore(narrow)
                rejected(verify, 'RESTORED_SCHEMA_MISMATCH')
                cases['trigger_invisible_export_rejected'] = True
            restore(payload)
            sql(target_id, 'SET FOREIGN_KEY_CHECKS=0; INSERT INTO learning_context_source_epochs'
                '(plan_id,epoch) VALUES (999999999,1); SET FOREIGN_KEY_CHECKS=1;')
            rejected(verify, 'RESTORED_FOREIGN_KEY_ORPHAN')
            cases['orphan_rows_rejected'] = True
            restore(payload)
            sql(target_id, 'ALTER TABLE learning_context_source_epochs DROP FOREIGN KEY '
                'learning_context_source_epochs_plan_id_foreign')
            rejected(verify, 'RESTORED_SCHEMA_MISMATCH')
            cases['missing_constraint_rejected'] = True
        restore(payload)
        sql(target_id, 'UPDATE tasks SET progress_percent=progress_percent+1 WHERE id>0')
        rejected(verify, 'RESTORED_DATA_MISMATCH')
        cases['altered_data_rejected'] = True
        # Syntactically valid truncated dump can exit zero. A successful mysql
        # process alone is not successful restoration.
        restore(b'SET NAMES utf8mb4;\n')
        rejected(lambda: sql(target_id, 'SELECT COUNT(*) FROM migrations'), 'SYNTHETIC_COMMAND_FAILED')
        cases['client_success_incomplete_restore_rejected'] = True
        restore(payload)
        verify()
        parity(before, dump(source, data_only=True), 'SOURCE_WRITES_DURING_REHEARSAL')
        parity(schema, sql(source, schema_sql) + sql(source, extra_sql), 'SOURCE_DDL_DURING_REHEARSAL')
        cases['source_data_and_schema_unchanged'] = True
    finally:
        cleanup(target_id, run, source, users_created)
    result = dict(result='passed', scope='SYNTHETIC_INDEPENDENT_LOGICAL_RESTORE_ONLY',
                  candidate_sha=candidate, series=series, version=version, source_ledger=expected,
                  restored_ledger=expected, source_clean_verified=True, independent_server=True,
                  network_isolated=True, plaintext_dump_written_to_disk=False,
                  target_and_tmpfs_removed=True, export_principals_removed=True,
                  foreign_keys_checked=checked_fks, dump_bytes=len(payload), cases=cases,
                  elapsed_seconds=round(time.monotonic() - start, 2),
                  aiven_backup_verified=False, real_export_verified=False, release_authorized=False)
    artifacts.mkdir(parents=True, exist_ok=True)
    suffix = '-94' if expected == 94 else ''
    (artifacts / (source + '-restore' + suffix + '.json')).write_text(json.dumps(result, indent=2) + '\n')
    return result


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--artifacts', type=Path, required=True)
    parser.add_argument('--series', choices=['8.0', '8.4', 'all'], default='all')
    parser.add_argument('--expected-ledger', type=int, choices=[94, 114], default=114)
    args = parser.parse_args()
    try:
        for series in ['8.0', '8.4'] if args.series == 'all' else [args.series]:
            print(json.dumps(rehearse(series, args.artifacts.resolve(), args.expected_ledger)))
    except Exception as error:
        # Error text deliberately omitted: even a broken metadata parser or
        # third-party command cannot put internal objects/raw data in CI.
        print(json.dumps(dict(result='BLOCK', code=str(error) if isinstance(error, Block)
                              else 'SYNTHETIC_RESTORE_NOT_VERIFIED',
                              aiven_backup_verified=False, release_authorized=False)))
        return 2
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
