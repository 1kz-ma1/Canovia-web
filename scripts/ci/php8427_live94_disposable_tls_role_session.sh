#!/usr/bin/env bash
# GitHub ephemeral MySQL only. NEVER use with Aiven credentials or target.
set -euo pipefail
if [[ "${GITHUB_ACTIONS:-}" != true ||
      "${CANOVIA_LIVE94_DISPOSABLE_CI:-}" != 1 ||
      "${CANOVIA_P0_DISPOSABLE_MYSQL_CI:-}" != 1 ||
      "${APP_ENV:-}" != testing ||
      "${DB_CONNECTION:-}" != mysql ||
      "${DB_HOST:-}" != 127.0.0.1 ||
      "${DB_PORT:-}" != 3306 ||
      "${DB_DATABASE:-}" != canovia_live94_synth_ci ||
      "${DB_USERNAME:-}" != canovia_ci ||
      "${DB_PASSWORD:-}" != ci-only-ephemeral-password ||
      "${CANOVIA_P0_DISPOSABLE_ROOT_PASSWORD:-}" != ci-only-root-password ||
      "${MYSQL_SERVICE_CONTAINER:-}" == '' ||
      "${RUNNER_TEMP:-}" != /* ]]; then
  echo 'LIVE94_TLS_ROLE_SESSION: BLOCK_NONDISPOSABLE' >&2
  exit 2
fi
umask 077
scratch="$(mktemp -d "$RUNNER_TEMP/live94-role-tls.XXXXXXXX")"
trap 'rm -rf -- "$scratch"' EXIT
root_mysql() {
  MYSQL_PWD=ci-only-root-password timeout -k 2s 12s mysql --no-defaults \
    --protocol=TCP --host=127.0.0.1 --port=3306 --connect-timeout=5 \
    --user=root --database=canovia_live94_synth_ci --batch --raw --skip-column-names "$@"
}
reader_mysql() {
  MYSQL_PWD=ci-only-live94-password timeout -k 2s 12s mysql --no-defaults \
    --protocol=TCP --host=127.0.0.1 --port=3306 --connect-timeout=5 \
    --user=live94_ci --database=canovia_live94_synth_ci --batch --raw --skip-column-names "$@"
}

# Restore initial scoped account from the previous overgrant negative test.
root_mysql -e "REVOKE SELECT ON canovia_live94_synth_ci.users FROM 'live94_ci'@'%'"
if reader_mysql -e 'SELECT id FROM users LIMIT 1' >/dev/null 2>&1; then
  echo 'BASELINE_OVERPRIVILEGED' >&2; exit 2
fi
# A role elevates real effective privileges, even if direct grants look narrow.
root_mysql -e "CREATE ROLE 'live94_ci_unsafe_role'@'%'"
root_mysql -e "GRANT SELECT ON canovia_live94_synth_ci.users TO 'live94_ci_unsafe_role'@'%'"
root_mysql -e "GRANT 'live94_ci_unsafe_role'@'%' TO 'live94_ci'@'%'"
root_mysql -e "SET DEFAULT ROLE 'live94_ci_unsafe_role'@'%' TO 'live94_ci'@'%'"
[[ "$(reader_mysql -e 'SELECT CURRENT_ROLE()')" != NONE ]] || {
  echo 'ACTIVE_ROLE_NOT_OBSERVED' >&2; exit 2;
}
reader_mysql -e 'SELECT id FROM users LIMIT 1' >/dev/null
MYSQL_PWD=ci-only-live94-password timeout -k 2s 15s mysql --no-defaults \
  --protocol=TCP --host=127.0.0.1 --port=3306 --connect-timeout=5 \
  --user=live94_ci --database=canovia_live94_synth_ci \
  --batch --raw --skip-column-names \
  < "$RUNNER_TEMP/audit.sql" > "$scratch/active-role.tsv"
if python3 -I -B scripts/ci/php8427_live94_disposable_mysql_smoke.py verify \
  --checkout "$RUNNER_TEMP/old-live94-mysql-fixture" \
  --path "$scratch/active-role.tsv" > "$scratch/active-role-result.json"; then
  echo 'ACTIVE_OVERPRIVILEGED_ROLE_ACCEPTED' >&2; exit 2
fi
grep -Fq '"verdict": "BLOCK"' "$scratch/active-role-result.json"
echo 'SYNTHETIC_DEFAULT_ACTIVE_OVERPRIVILEGE_ROLE=BLOCK'
root_mysql -e "SET DEFAULT ROLE NONE TO 'live94_ci'@'%'"
root_mysql -e "REVOKE 'live94_ci_unsafe_role'@'%' FROM 'live94_ci'@'%'"
root_mysql -e "DROP ROLE 'live94_ci_unsafe_role'@'%'"
[[ "$(reader_mysql -e 'SELECT CURRENT_ROLE()')" == NONE ]] || {
  echo 'ROLE_NOT_REMOVED' >&2; exit 2;
}

# CA is only the generated test-server CA; never use private user CA.
[[ "$MYSQL_SERVICE_CONTAINER" =~ ^[a-f0-9]{12,64}$ ]] || {
  echo 'UNTRUSTED_DISPOSABLE_CONTAINER' >&2; exit 2;
}
docker cp "$MYSQL_SERVICE_CONTAINER:/var/lib/mysql/ca.pem" "$scratch/ca.pem"
test -s "$scratch/ca.pem"
cipher="$(MYSQL_PWD=ci-only-live94-password timeout -k 2s 12s mysql --no-defaults \
  --protocol=TCP --host=127.0.0.1 --port=3306 --connect-timeout=5 \
  --user=live94_ci --database=canovia_live94_synth_ci \
  --ssl-mode=VERIFY_CA --ssl-ca="$scratch/ca.pem" --batch --raw \
  --skip-column-names -e "SHOW SESSION STATUS LIKE 'Ssl_cipher'")"
[[ "$cipher" == $'Ssl_cipher\t'?* && "$cipher" != $'Ssl_cipher\t' ]] || {
  echo 'VERIFIED_CA_CIPHER_MISSING' >&2; exit 2;
}
# Auto-generated MySQL server cert does not match 127.0.0.1: must refuse.
if MYSQL_PWD=ci-only-live94-password timeout -k 2s 12s mysql --no-defaults \
  --protocol=TCP --host=127.0.0.1 --port=3306 --connect-timeout=5 \
  --user=live94_ci --database=canovia_live94_synth_ci \
  --ssl-mode=VERIFY_IDENTITY --ssl-ca="$scratch/ca.pem" \
  -e 'SELECT 1' >/dev/null 2>&1; then
  echo 'INVALID_HOSTNAME_ACCEPTED' >&2; exit 2
fi
openssl req -x509 -newkey rsa:2048 -nodes -days 1 \
  -subj '/CN=wrong-ephemeral-ca' -keyout "$scratch/wrong.key" \
  -out "$scratch/wrong.pem" >/dev/null 2>&1
if MYSQL_PWD=ci-only-live94-password timeout -k 2s 12s mysql --no-defaults \
  --protocol=TCP --host=127.0.0.1 --port=3306 --connect-timeout=5 \
  --user=live94_ci --database=canovia_live94_synth_ci \
  --ssl-mode=VERIFY_CA --ssl-ca="$scratch/wrong.pem" \
  -e 'SELECT 1' >/dev/null 2>&1; then
  echo 'UNTRUSTED_CA_ACCEPTED' >&2; exit 2
fi
root_mysql -e "ALTER USER 'live94_ci'@'%' REQUIRE SSL"
if MYSQL_PWD=ci-only-live94-password timeout -k 2s 12s mysql --no-defaults \
  --protocol=TCP --host=127.0.0.1 --port=3306 --connect-timeout=5 \
  --user=live94_ci --database=canovia_live94_synth_ci \
  --ssl-mode=DISABLED -e 'SELECT 1' >/dev/null 2>&1; then
  echo 'PLAINTEXT_ACCEPTED' >&2; exit 2
fi
echo 'SYNTHETIC_CA_VERIFIED=PASS; WRONG_CA_HOSTNAME_PLAINTEXT=DENIED'
echo 'POSITIVE_VERIFY_IDENTITY_WITH_CUSTOM_SAN=NOT_TESTED'

# Independent root processlist observation of an already closed client.
cid="$(reader_mysql -e 'SELECT CONNECTION_ID()')"
[[ "$cid" =~ ^[1-9][0-9]*$ ]] || { echo 'BAD_CID' >&2; exit 2; }
[[ "$(root_mysql -e "SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE ID=$cid")" == 0 ]] || {
  echo 'NORMAL_THREAD_STILL_PRESENT' >&2; exit 2;
}
echo 'DISPOSABLE_NORMAL_CLIENT_THREAD_GONE=OBSERVED'

# Forced local timeout. Server-side SLEEP(10) can outlive local client;
# independently poll only this synthetic server for eventual thread removal.
set +e
MYSQL_PWD=ci-only-live94-password timeout -k 2s 3s mysql --no-defaults \
  --protocol=TCP --host=127.0.0.1 --port=3306 --connect-timeout=5 \
  --user=live94_ci --database=canovia_live94_synth_ci \
  --unbuffered --batch --raw --skip-column-names \
  -e 'SELECT CONNECTION_ID(); SELECT SLEEP(10);' \
  > "$scratch/forced.out" 2> "$scratch/forced.err" &
child=$!
for _ in $(seq 1 30); do
  [[ -s "$scratch/forced.out" ]] && break
  sleep 0.1
done
forced_cid="$(head -1 "$scratch/forced.out")"
wait "$child"
rc=$?
set -e
[[ "$forced_cid" =~ ^[1-9][0-9]*$ && "$rc" -eq 124 ]] || {
  echo 'LOCAL_TIMEOUT_NOT_OBSERVED' >&2; exit 2;
}
ended=0
for _ in $(seq 1 30); do
  if [[ "$(root_mysql -e "SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE ID=$forced_cid")" == 0 ]]; then
    ended=1
    break
  fi
  sleep 0.5
done
[[ "$ended" -eq 1 ]] || { echo 'REMOTE_THREAD_STILL_UNKNOWN' >&2; exit 2; }
echo 'DISPOSABLE_FORCED_TIMEOUT_REMOTE_THREAD_EVENTUALLY_GONE=OBSERVED'
echo 'REAL_AIVEN_TLS_AND_REMOTE_END=UNVERIFIED; PRODUCTION_GO=NO'
