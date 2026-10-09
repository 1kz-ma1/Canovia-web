#!/usr/bin/env bash
# Verify REFERENCES-only schema metadata visibility + migrations-only SELECT.
# Runs ONLY against the pinned disposable MySQL GitHub Actions CI container.
set -euo pipefail
if [[ "${GITHUB_ACTIONS:-}" != "true" ||
      "${CANOVIA_P0_DISPOSABLE_MYSQL_CI:-}" != "1" ||
      "${APP_ENV:-}" != "testing" ||
      "${DB_CONNECTION:-}" != "mysql" ||
      "${DB_HOST:-}" != "127.0.0.1" ||
      "${DB_DATABASE:-}" != "canovia_p0_ci" ||
      "${DB_USERNAME:-}" != "canovia_ci" ||
      "${DB_PASSWORD:-}" != "ci-only-ephemeral-password" ||
      "${CANOVIA_P0_DISPOSABLE_ROOT_PASSWORD:-}" != "ci-only-root-password" ]]; then
  echo 'p0_metadata_visibility: BLOCK_NONDISPOSABLE' >&2
  exit 2
fi

root_mysql() {
  MYSQL_PWD="$CANOVIA_P0_DISPOSABLE_ROOT_PASSWORD" mysql --no-defaults \
    --protocol=TCP --host=127.0.0.1 --port=3306 --user=root \
    --batch --raw --skip-column-names "$@"
}
readonly_mysql() {
  MYSQL_PWD='ci-only-metadata-password' mysql --no-defaults \
    --protocol=TCP --host=127.0.0.1 --port=3306 \
    --user=p0_metadata_ci --database=canovia_p0_ci \
    --batch --raw --skip-column-names "$@"
}

umask 077
scratch="$(mktemp -d)"
trap 'rm -rf -- "$scratch"' EXIT

root_mysql -e "CREATE USER 'p0_metadata_ci'@'%' IDENTIFIED BY 'ci-only-metadata-password'"
root_mysql -e "GRANT REFERENCES ON canovia_p0_ci.* TO 'p0_metadata_ci'@'%'"
root_mysql -e "GRANT SELECT ON canovia_p0_ci.migrations TO 'p0_metadata_ci'@'%'"

readonly_mysql -e 'SHOW GRANTS FOR CURRENT_USER()' > "$scratch/grants"
python3 scripts/ci/p0_mysql_metadata_grants_guard.py \
  --database canovia_p0_ci --input "$scratch/grants" \
  > "$scratch/grant-status"

# A MySQL account with only the ledger table's SELECT privilege can otherwise
# falsely report missing users, parents and child table constraints.
# REFERENCES ON schema must make these information_schema rows visible without
# making application user rows readable.
table_visible="$(readonly_mysql -e "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('users','intelligence_decision_traces','learning_answer_evaluation_adjustments')")"
[[ "$table_visible" == "3" ]] || {
  echo 'p0_metadata_visibility: BLOCK_HIDDEN_TABLE_METADATA' >&2; exit 2;
}
if readonly_mysql -e 'SELECT id FROM users LIMIT 1' >/dev/null 2>/dev/null; then
  echo 'p0_metadata_visibility: BLOCK_PERSONAL_DATA_READABLE' >&2
  exit 2
fi

readonly_mysql < scripts/sql/p0_mysql_readonly_schema_inventory.sql \
  | python3 scripts/ci/p0_mysql_inventory_tsv_import.py --input - \
  > "$scratch/schema.json"
python3 scripts/ci/p0_mysql_inventory_offline_triage.py \
  --report "$scratch/schema.json" > "$scratch/schema-result.json"
python3 - "$scratch/schema-result.json" <<'PY'
import json,sys
with open(sys.argv[1], encoding="utf-8") as handle: r=json.load(handle)
if r.get("codes") != ["TARGETED_METADATA_MATCH_NOT_RELEASE"] or r.get("release_authorized") is not False:
    raise SystemExit("p0_metadata_visibility: BLOCK_SCHEMA_NOT_VISIBLE")
PY

readonly_mysql < scripts/sql/p0_mysql_readonly_full_migration_ledger.sql \
  | python3 scripts/ci/p0_mysql_full_ledger_offline_check.py --input - \
  > "$scratch/ledger.json"
python3 - "$scratch/ledger.json" <<'PY'
import json,sys
with open(sys.argv[1], encoding="utf-8") as handle: r=json.load(handle)
if r.get("code") != "FULL_MIGRATION_LEDGER_MATCH_NOT_RELEASE" or r.get("release_authorized") is not False:
    raise SystemExit("p0_metadata_visibility: BLOCK_LEDGER_NOT_VISIBLE")
PY

echo 'p0_metadata_only_grants_and_sql_inventory: pass'
echo 'p0_unsafe_production_credentials_used: false'
