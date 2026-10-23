#!/usr/bin/env bash
# Ephemeral GitHub Actions MySQL 8.0/8.4 ONLY.
# NEVER apply certificate replacement, TLS reload or these commands to Aiven.
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
  echo "LIVE94_SAN_TLS: BLOCK_NONDISPOSABLE" >&2
  exit 2
fi
[[ "$MYSQL_SERVICE_CONTAINER" =~ ^[a-f0-9]{12,64}$ ]] || {
  echo "LIVE94_SAN_TLS: BLOCK_CONTAINER" >&2
  exit 2
}

umask 077
scratch="$(mktemp -d "$RUNNER_TEMP/live94-san-tls.XXXXXXXX")"
result_path="$RUNNER_TEMP/live94-positive-san-observations.tsv"
[[ ! -e "$result_path" ]] || { echo "LIVE94_SAN_TLS: RESULT_PATH_EXISTS" >&2; exit 2; }
trap 'rm -rf -- "$scratch"; rm -f -- "$result_path"' EXIT

root_mysql() {
  MYSQL_PWD=ci-only-root-password timeout -k 2s 12s mysql --no-defaults \
    --protocol=TCP --host=127.0.0.1 --port=3306 --connect-timeout=5 \
    --ssl-mode=DISABLED --user=root --database=canovia_live94_synth_ci \
    --batch --raw --skip-column-names "$@"
}
reader_tls() {
  MYSQL_PWD=ci-only-live94-password timeout -k 2s 12s mysql --no-defaults \
    --protocol=TCP --host=localhost --port=3306 --connect-timeout=5 \
    --ssl-mode=VERIFY_IDENTITY --ssl-ca="$scratch/ca.pem" \
    --user=live94_ci --database=canovia_live94_synth_ci \
    --batch --raw --skip-column-names "$@"
}

# Only touch this disposable server when it uses the expected auto-generated
# names. No SET GLOBAL, startup configuration, external provider operations.
paths="$(root_mysql -e 'SELECT @@GLOBAL.ssl_ca,@@GLOBAL.ssl_cert,@@GLOBAL.ssl_key')"
if [[ "$paths" != $'ca.pem\tserver-cert.pem\tserver-key.pem' ]]; then
  echo "LIVE94_SAN_TLS: UNEXPECTED_DISPOSABLE_TLS_PATHS" >&2
  exit 2
fi

openssl req -x509 -newkey rsa:2048 -sha256 -nodes -days 1 \
  -subj '/CN=live94-disposable-test-ca' \
  -addext 'basicConstraints=critical,CA:TRUE' \
  -addext 'keyUsage=critical,keyCertSign,cRLSign' \
  -keyout "$scratch/ca-key.pem" -out "$scratch/ca.pem" >/dev/null 2>&1

openssl req -new -newkey rsa:2048 -nodes -sha256 \
  -subj '/CN=localhost' \
  -keyout "$scratch/server-key.pem" -out "$scratch/server.csr" >/dev/null 2>&1

cat > "$scratch/server-ext.cnf" <<'EOF'
basicConstraints=critical,CA:FALSE
keyUsage=critical,digitalSignature,keyEncipherment
extendedKeyUsage=serverAuth
subjectAltName=DNS:localhost
EOF

openssl x509 -req -in "$scratch/server.csr" \
  -CA "$scratch/ca.pem" -CAkey "$scratch/ca-key.pem" \
  -CAcreateserial -days 1 -sha256 \
  -extfile "$scratch/server-ext.cnf" \
  -out "$scratch/server-cert.pem" >/dev/null 2>&1

# SAN must be localhost DNS only; numeric 127.0.0.1 is intentionally absent.
openssl x509 -in "$scratch/server-cert.pem" -noout -ext subjectAltName \
  | grep -Fq 'DNS:localhost'
if openssl x509 -in "$scratch/server-cert.pem" -noout -ext subjectAltName \
  | grep -Fq 'IP Address:127.0.0.1'; then
  echo "LIVE94_SAN_TLS: INCORRECT_SAN_FIXTURE" >&2
  exit 2
fi

# Replace the generated files ONLY in the ephemeral CI MySQL container.
# Server reads new certificates at ALTER INSTANCE RELOAD TLS; old sessions
# retain their context. This operation is strictly forbidden for real Aiven.
for file in ca.pem server-cert.pem server-key.pem; do
  docker cp "$scratch/$file" "$MYSQL_SERVICE_CONTAINER:/var/lib/mysql/$file"
done
docker exec -u 0 "$MYSQL_SERVICE_CONTAINER" sh -c \
  'chown mysql:mysql /var/lib/mysql/ca.pem /var/lib/mysql/server-cert.pem /var/lib/mysql/server-key.pem && chmod 0644 /var/lib/mysql/ca.pem /var/lib/mysql/server-cert.pem && chmod 0600 /var/lib/mysql/server-key.pem'
root_mysql -e 'ALTER INSTANCE RELOAD TLS'

# Now require both trusted CA and correct hostname from a real libmysqlclient.
cipher="$(reader_tls -e "SHOW SESSION STATUS LIKE 'Ssl_cipher'")"
[[ "$cipher" == $'Ssl_cipher\t'?* && "$cipher" != $'Ssl_cipher\t' ]] || {
  echo "LIVE94_SAN_TLS: MATCHING_SAN_NO_CIPHER" >&2
  exit 2
}
echo 'DISPOSABLE_VERIFY_IDENTITY_MATCHING_SAN=PASS'

# Same valid CA, wrong requested host/IP -> TLS connection MUST fail.
if MYSQL_PWD=ci-only-live94-password timeout -k 2s 12s mysql --no-defaults \
  --protocol=TCP --host=127.0.0.1 --port=3306 --connect-timeout=5 \
  --ssl-mode=VERIFY_IDENTITY --ssl-ca="$scratch/ca.pem" \
  --user=live94_ci --database=canovia_live94_synth_ci \
  -e 'SELECT 1' >/dev/null 2>&1; then
  echo "LIVE94_SAN_TLS: WRONG_HOST_ACCEPTED" >&2
  exit 2
fi

# Correct requested host but the previous auto-generated/disconnected test
# CA is an untrusted root; do not accept it as this new custom SAN authority.
openssl req -x509 -newkey rsa:2048 -sha256 -nodes -days 1 \
  -subj '/CN=untrusted-disposable-test-ca' \
  -addext 'basicConstraints=critical,CA:TRUE' \
  -addext 'keyUsage=critical,keyCertSign,cRLSign' \
  -keyout "$scratch/untrusted-key.pem" -out "$scratch/untrusted-ca.pem" >/dev/null 2>&1
if MYSQL_PWD=ci-only-live94-password timeout -k 2s 12s mysql --no-defaults \
  --protocol=TCP --host=localhost --port=3306 --connect-timeout=5 \
  --ssl-mode=VERIFY_IDENTITY --ssl-ca="$scratch/untrusted-ca.pem" \
  --user=live94_ci --database=canovia_live94_synth_ci \
  -e 'SELECT 1' >/dev/null 2>&1; then
  echo "LIVE94_SAN_TLS: WRONG_CA_ACCEPTED" >&2
  exit 2
fi
echo 'DISPOSABLE_VERIFY_IDENTITY_BAD_HOST_AND_CA=DENIED'

# Only the existing reviewed 7 source-pinned SQL can be used in the test.
# A successful TLS handshake does not transform incomplete fixture metadata
# into an authorized schema, live Aiven audit, remote close or production GO.
reader_tls < "$RUNNER_TEMP/audit.sql" > "$result_path"
python3 -I -B scripts/ci/php8427_live94_disposable_mysql_smoke.py verify \
  --checkout "$RUNNER_TEMP/old-live94-mysql-fixture" \
  --path "$result_path" > "$scratch/verification.json"
python3 - "$scratch/verification.json" <<'PY'
import json,sys
r=json.load(open(sys.argv[1], encoding="utf-8"))
assert r["verdict"]=="HOLD"
assert r["code"]=="DISPOSABLE_SAME_SESSION_94_LEDGER_NO_SCHEMA_GO"
assert r["same_client_connection_observed"] is True
assert r["synthetic_physical_metadata_intentionally_incomplete"] is True
assert r["real_tls_peer_authenticated"] is False
assert r["real_aiven_connected"] is False
assert r["production_go"] is False
print("DISPOSABLE_POSITIVE_HOST_VERIFIED_7_PINNED_SQL=PASS; SCHEMA=HOLD")
PY
echo 'REAL_AIVEN_TLS=NOT_TESTED; REMOTE_END=UNVERIFIED; PRODUCTION_GO=NO'
