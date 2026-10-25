#!/usr/bin/env python3
"""Owned cutover coordination rehearsal; no real endpoint/credential/DDL inputs.

Current-source original->preparation on ONE synthetic SQLite. Historical full
94->114 MySQL and logical-restore proofs are reused, not rerun or impersonated.
"""
import argparse
import hashlib
import json
import os
from pathlib import Path
import selectors
import sqlite3
import subprocess
import sys

sys.dont_write_bytecode = True
from preparation_mac_lab import ROOT, SafetyError, serve, stop_server, start_owned_server, check_checkout
sys.path.insert(0, str(ROOT / 'scripts/ops'))
from platform_release_preflight import CUTOVER_ORDER, cutover_order

# Fixed private-child program; never a worker CLI, broker, arbitrary SQL or host.
ACTOR = r'''
import sqlite3,sys
conn = None
try:
    if sys.argv[2] == 'inflight':
        conn = sqlite3.connect(sys.argv[1], timeout=0.2)
        conn.execute('BEGIN IMMEDIATE')
        conn.execute("UPDATE plans SET description='SYNTHETIC_UNCOMMITTED' WHERE id=1")
    print('OWNED_READY', flush=True)
    sys.stdin.readline()
finally:
    if conn is not None:
        conn.rollback()
        conn.close()
'''


def require(value, code):
    if value is not True:
        raise SafetyError(code)


def actor(database, role, processes):
    require(role in ('idle', 'inflight'), 'CUTOVER_ACTOR_ROLE')
    child = subprocess.Popen([sys.executable, '-I', '-B', '-c', ACTOR, str(database), role],
                             stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL,
                             env={'PATH': os.defpath})
    processes.append(child)
    with selectors.DefaultSelector() as selector:
        selector.register(child.stdout, selectors.EVENT_READ)
        require(bool(selector.select(5)), 'CUTOVER_ACTOR_UNOBSERVABLE')
        require(child.stdout.readline(32) == b'OWNED_READY\n' and child.poll() is None, 'CUTOVER_ACTOR_FAILED')
    return child


def finish(child):
    if child.poll() is None:
        child.stdin.write(b'finish\n')
        child.stdin.flush()
        child.stdin.close()
        child.wait(timeout=5)
    require(child.returncode == 0, 'CUTOVER_CHILD_EXIT_UNVERIFIED')
    for stream in (child.stdin, child.stdout, child.stderr):
        if stream is not None and not stream.closed:
            stream.close()


def lock_free(database):
    connection = sqlite3.connect(database, timeout=0.1)
    try:
        connection.execute('BEGIN IMMEDIATE')
        connection.rollback()
        return True
    except sqlite3.OperationalError as error:
        # Unknown errors must not be called an observed idle connection.
        return False if getattr(error, 'sqlite_errorcode', None) in (5, 6) or str(error) == 'database is locked' else None
    finally:
        connection.close()


def snapshot(database, original=None):
    # Fixed synthetic schema only. No dumps or row content leave this function.
    result = {}
    with sqlite3.connect(database) as db:
        db.row_factory = sqlite3.Row
        for table in ('users', 'plans', 'tasks', 'study_practice_attempts', 'work_sessions', 'jobs'):
            columns = 'id,name,email,password,release_level_override' if table == 'users' else '*'
            rows = [dict(r) for r in db.execute(f'SELECT {columns} FROM {table} ORDER BY id')]
            if original is not None:
                ids = set(original[table]['ids'])
                rows = [r for r in rows if r['id'] in ids]
            result[table] = {'ids': [r['id'] for r in rows], 'digest': hashlib.sha256(json.dumps(rows, sort_keys=True).encode()).hexdigest()}
    return result


def pins(env, expected, source):
    check_checkout(ROOT, source)
    for key in ('APP_KEY', 'SESSION_COOKIE', 'DB_DATABASE', 'DB_URL', 'LARAVEL_STORAGE_PATH',
                'APP_URL', 'APP_ENV', 'CANOVIA_PUBLIC_RELEASE_LEVEL', 'APP_CONFIG_CACHE'):
        require(env.get(key) == expected.get(key), 'CUTOVER_RUNTIME_BINDING_DRIFT')
    require(env.get('CANOVIA_PREPARATION_ONLY') == 'true', 'CUTOVER_PROFILE_DRIFT')
    require(not Path(env['APP_CONFIG_CACHE']).exists(), 'CUTOVER_STALE_CONFIG')


def rehearse(source, artifacts, executable=None):
    artifacts.mkdir(parents=True, exist_ok=True)
    processes, cases = [], []
    def blocked(name, operation, scope='VERIFIED_ISOLATED'):
        try:
            operation()
        except SafetyError:
            cases.append({'case': name, 'outcome': 'BLOCKING', 'verification': scope})
            return
        raise SafetyError('CUTOVER_NEGATIVE_DID_NOT_BLOCK')

    with serve('original', source, processes=processes, keep_failed=True) as (env, old_web):
        database = Path(env['DB_DATABASE'])
        private = database.parent
        env['CANOVIA_CUTOVER_FIXTURE'] = '1'
        if executable:
            env['CANOVIA_BROWSER_EXECUTABLE_PATH'] = str(Path(executable).resolve(strict=True))
        log_path = private / 'cutover-private.log'
        with log_path.open('w') as log:
            os.chmod(log_path, 0o600)
            def browser(script, *args, profile=None):
                try:
                    subprocess.run(['node', 'scripts/ci/' + script, *args], cwd=ROOT, env=profile or env,
                                   stdout=log, stderr=log, check=True, timeout=300)
                except subprocess.SubprocessError:
                    raise SafetyError('CUTOVER_BROWSER_STAGE_FAILED') from None
            browser('preparation_cutover_session_smoke.mjs', 'before')
            with sqlite3.connect(database) as db:
                db.execute("INSERT INTO jobs(queue,payload,attempts,reserved_at,available_at,created_at) VALUES ('synthetic-quarantined','{}',0,NULL,0,0)")
            preserved = snapshot(database)
            actors = []
            def quiescent():
                require(old_web.poll() is not None, 'CUTOVER_OLD_WEB_REMAINS')
                require(all(p.poll() is not None for p in actors), 'CUTOVER_OLD_CONSUMER_REMAINS')
                require(lock_free(database), 'CUTOVER_INFLIGHT_OR_UNKNOWN')
            blocked('old_web_still_writable', quiescent)
            worker = actor(database, 'idle', processes); actors.append(worker)
            stop_server(old_web)
            blocked('new_web_only_with_old_worker', quiescent)
            blocked('old_worker_version_remains', quiescent)
            finish(worker)
            restarted = actor(database, 'idle', processes); actors.append(restarted)
            blocked('supervisor_restart', quiescent)
            finish(restarted)
            busy = actor(database, 'inflight', processes)
            blocked('inflight_transaction', lambda: require(lock_free(database), 'CUTOVER_INFLIGHT'))
            finish(busy)
            require(lock_free(database), 'CUTOVER_LOCAL_TRANSACTION_NOT_RELEASED')
            blocked('remote_observation_unknown', lambda: require(None, 'CUTOVER_REMOTE_UNVERIFIED'), 'VERIFIED_SOURCE')
            with sqlite3.connect(database) as db:
                pending = db.execute('SELECT COUNT(*) FROM jobs').fetchone()[0]
            blocked('unaccounted_pending_jobs', lambda: require(pending == 0, 'CUTOVER_PENDING_JOBS_UNACCOUNTED'))
            quiescent()
            # Pending rows are retained/quarantined: no consumer, no replay, no purge.
            require(snapshot(database, preserved) == preserved, 'CUTOVER_PRESERVED_ROWS_CHANGED')
            appearing = actor(database, 'idle', processes); actors.append(appearing)
            blocked('writer_appears_during_migration_stage', quiescent)
            finish(appearing)
            candidate = {**env, 'CANOVIA_PREPARATION_ONLY': 'true'}
            for key in ('APP_KEY', 'APP_URL', 'DB_DATABASE', 'CANOVIA_PUBLIC_RELEASE_LEVEL'):
                drift = {**candidate, key: 'synthetic-drift'}
                blocked('runtime_drift_' + key.lower(), lambda d=drift: pins(d, candidate, source), 'VERIFIED_SOURCE')
            blocked('web_profile_off', lambda: pins({**candidate, 'CANOVIA_PREPARATION_ONLY': 'false'}, candidate, source), 'VERIFIED_SOURCE')
            stale = Path(candidate['APP_CONFIG_CACHE'])
            stale.write_text('SYNTHETIC_STALE_NOT_EXECUTED')
            blocked('stale_cached_config', lambda: pins(candidate, candidate, source))
            stale.unlink()
            partial = list(CUTOVER_ORDER[:8]) + ['STOP', 'MIGRATION_ONCE']
            require(cutover_order(partial)['state'] == 'BLOCKING', 'CUTOVER_RETRY_NOT_BLOCKED')
            cases.append({'case': 'partial_ddl_and_unapproved_retry_order', 'outcome': 'BLOCKING', 'verification': 'VERIFIED_SOURCE'})
            trace = list(CUTOVER_ORDER[:9])
            quiescent(); pins(candidate, candidate, source)
            new_web = start_owned_server(candidate, log, processes)
            try:
                trace.append('LIMITED_WEB_STARTED')
                browser('preparation_cutover_session_smoke.mjs', 'after', profile=candidate)
                ui = {**candidate, 'CANOVIA_PREPARATION_INTEREST_BASE_URL': candidate['APP_URL'],
                      'CANOVIA_PREPARATION_INTEREST_FIXTURE': '1', 'CANOVIA_INTEREST_ENGINE': 'chromium',
                      'CANOVIA_INTEREST_SCREENSHOT_DIR': str((artifacts / 'mobile').resolve()),
                      'CANOVIA_INTEREST_SCREENSHOT': str((artifacts / 'result-desktop.png').resolve())}
                browser('preparation_interest_browser_smoke.mjs', profile=ui)
                require(snapshot(database, preserved) == preserved, 'CUTOVER_ACCOUNT_PLAN_HISTORY_CHANGED')
                trace.append('ACCEPTANCE')
            finally:
                stop_server(new_web)
            trace.append('SAFE_SHUTDOWN')
            require(cutover_order(trace)['state'] == 'VERIFIED_SOURCE', 'CUTOVER_ORDER_INVALID')
            require(all(p.poll() is not None for p in processes), 'CUTOVER_CLEANUP_UNVERIFIED')
            report = dict(result='VERIFIED_ISOLATED', source_sha=source, plan_order=cutover_order(trace),
                          negative_cases=cases, negative_case_count=len(cases), legacy_rows_preserved=True,
                          pending_jobs_retained_without_attempt=True, old_synthetic_session_continues=True,
                          csrf_and_specialist_denial=True, controlled_original_to_preparation=True,
                          migration_scope='EXISTING_94_TO_114_MYSQL_AND_POLICY_CI_REUSED_NOT_REEXECUTED',
                          historical_production_binary_executed=False, actual_service_worker_verified=False,
                          real_remote_termination_verified=False, real_aiven_verified=False,
                          cleanup_verified=True, release_authorized=False, production_modified=False)
    # Report only after owned server/actor cleanup and disposable DB removal.
    require(not private.exists(), 'CUTOVER_PRIVATE_CLEANUP_UNVERIFIED')
    (artifacts / 'cutover-report.json').write_text(json.dumps(report, indent=2))
    return report


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--source-sha', required=True)
    parser.add_argument('--artifacts', type=Path, required=True)
    parser.add_argument('--browser-executable')
    args = parser.parse_args()
    try:
        result = rehearse(args.source_sha, args.artifacts, args.browser_executable)
        print(json.dumps({k: result[k] for k in ('result', 'source_sha', 'negative_case_count', 'cleanup_verified', 'release_authorized')}))
    except (SafetyError, OSError, ValueError, sqlite3.Error, subprocess.SubprocessError) as error:
        code = str(error) if isinstance(error, SafetyError) and str(error).replace('_', '').isupper() and len(str(error)) < 80 else 'CUTOVER_EXECUTION_UNVERIFIED'
        print(json.dumps(dict(result='BLOCKING', code=code, scope='OWNED_REHEARSAL_ONLY', release_authorized=False)))
        raise SystemExit(2)
