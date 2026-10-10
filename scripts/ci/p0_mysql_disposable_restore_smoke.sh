#!/usr/bin/env bash
# Synthetic MySQL dump -> independent disposable schema -> compare + corruption test.
# NEVER run on Aiven/production. No real accounts, dumps or credentials.
set -euo pipefail
source_db="canovia_p0_ci"
restore_db="canovia_p0_restore_ci"
host="127.0.0.1"

# Unset environment variables must abort under set -u.
if [[ "$GITHUB_ACTIONS" != "true" ||
      "$CANOVIA_P0_DISPOSABLE_MYSQL_CI" != "1" ||
      "$APP_ENV" != "testing" ||
      "$DB_CONNECTION" != "mysql" ||
      "$DB_HOST" != "$host" ||
      "$DB_DATABASE" != "$source_db" ||
      "$DB_USERNAME" != "canovia_ci" ||
      "$DB_PASSWORD" != "ci-only-ephemeral-password" ||
      "$CANOVIA_P0_DISPOSABLE_ROOT_PASSWORD" != "ci-only-root-password" ]]; then
    printf 'p0_disposable_restore: BLOCK_UNSAFE_TARGET\n'
    exit 2
fi

command -v mysql >/dev/null
command -v mysqldump >/dev/null
command -v python3 >/dev/null
umask 077
scratch="$(mktemp -d)"
trap 'rm -rf "$scratch"' EXIT

# All commands are pinned to the throwaway MySQL 8 service on 127.0.0.1.
source_mysql() {
    MYSQL_PWD="$DB_PASSWORD" mysql --no-defaults --protocol=TCP --host="$host" --port=3306 \
      --user=canovia_ci --database="$source_db" --batch --raw --skip-column-names "$@"
}
restored_mysql() {
    MYSQL_PWD="$CANOVIA_P0_DISPOSABLE_ROOT_PASSWORD" mysql --no-defaults --protocol=TCP \
      --host="$host" --port=3306 --user=root --database="$restore_db" \
      --batch --raw --skip-column-names "$@"
}
root_mysql() {
    MYSQL_PWD="$CANOVIA_P0_DISPOSABLE_ROOT_PASSWORD" mysql --no-defaults --protocol=TCP \
      --host="$host" --port=3306 --user=root --batch --raw --skip-column-names "$@"
}
if [[ "$(source_mysql -e 'SELECT LEFT(VERSION(), 1)')" != "8" ]]; then
    printf 'p0_disposable_restore: BLOCK_UNSUPPORTED_ENGINE\n'
    exit 2
fi
# Never overwrite a pre-existing target schema, even in CI.
if [[ "$(root_mysql -e "SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = '$restore_db'")" != "0" ]]; then
    printf 'p0_disposable_restore: BLOCK_EXISTING_TARGET\n'
    exit 2
fi

# Fictional synthetic user row created only in the disposable CI database.
source_mysql -e "INSERT INTO users (name,email,password,created_at,updated_at) VALUES ('CI Restore Probe','p0-ci-restore@example.invalid','synthetic-nonlogin-password',NOW(),NOW())"
if [[ "$(source_mysql -e "SELECT COUNT(*) FROM users WHERE email='p0-ci-restore@example.invalid'")" != "1" ]]; then
    printf 'p0_disposable_restore: BLOCK_MISSING_SYNTHETIC_SENTINEL\n'
    exit 2
fi

# Logical backup of synthetic data ONLY. Do not log SQL or upload artifacts.
MYSQL_PWD="$DB_PASSWORD" mysqldump --no-defaults --protocol=TCP --host="$host" --port=3306 \
    --user=canovia_ci --single-transaction --quick \
    --no-tablespaces --set-gtid-purged=OFF "$source_db" > "$scratch/synthetic.sql"
if [[ ! -s "$scratch/synthetic.sql" ]]; then
    printf 'p0_disposable_restore: BLOCK_EMPTY_DUMP\n'
    exit 2
fi

# Restore all schema and fictional data into an independent disposable DB.
root_mysql -e "CREATE DATABASE \`$restore_db\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
restored_mysql < "$scratch/synthetic.sql"
if [[ "$(restored_mysql -e "SELECT COUNT(*) FROM users WHERE email='p0-ci-restore@example.invalid'")" != "1" ]]; then
    printf 'p0_disposable_restore: BLOCK_SYNTHETIC_ROW_LOSS\n'
    exit 2
fi
for t in intelligence_state_snapshots intelligence_decision_traces learning_answer_evaluation_adjustments; do
    before="$(source_mysql -e "SELECT COUNT(*) FROM \`$t\`")"
    after="$(restored_mysql -e "SELECT COUNT(*) FROM \`$t\`")"
    if [[ "$before" != "$after" ]]; then
        printf 'p0_disposable_restore: BLOCK_TARGET_ROW_COUNT_DRIFT\n'
        exit 2
    fi
done

# Compare complete definitions in ALL tables, columns, FKs and indexes.
# Output stays only in protected disposable CI temp files; no personal rows.
source_mysql < scripts/sql/p0_mysql_disposable_full_schema_fingerprint.sql \
    > "$scratch/source-structural-metadata.tsv"
restored_mysql < scripts/sql/p0_mysql_disposable_full_schema_fingerprint.sql \
    > "$scratch/restored-structural-metadata.tsv"
if [[ ! -s "$scratch/source-structural-metadata.tsv" ||
      ! -s "$scratch/restored-structural-metadata.tsv" ]] \
    || ! cmp -s "$scratch/source-structural-metadata.tsv" "$scratch/restored-structural-metadata.tsv"; then
    printf 'p0_disposable_restore: BLOCK_ALL_TABLE_SCHEMA_PARITY\n'
    exit 2
fi
printf 'p0_disposable_mysql_all_table_schema_parity: pass\n'

# Restore acceptance means valid synthetic data/schema/ledger, NOT production.
restored_mysql < scripts/sql/p0_mysql_readonly_schema_inventory.sql \
    | python3 scripts/ci/p0_mysql_inventory_tsv_import.py --input - \
    > "$scratch/restore-schema.json"
python3 scripts/ci/p0_mysql_inventory_offline_triage.py \
    --report "$scratch/restore-schema.json" > "$scratch/restore-schema-result.json"
python3 - "$scratch/restore-schema-result.json" <<'PY'
import json, sys
with open(sys.argv[1], encoding="utf-8") as handle:
    result = json.load(handle)
if result.get("result") != "REVIEW_REQUIRED" or result.get("codes") != ["TARGETED_METADATA_MATCH_NOT_RELEASE"] or result.get("release_authorized") is not False:
    raise SystemExit("p0_disposable_restore: BLOCK_SCHEMA_RESTORE_DRIFT")
PY

restored_mysql < scripts/sql/p0_mysql_readonly_full_migration_ledger.sql \
    | python3 scripts/ci/p0_mysql_full_ledger_offline_check.py --input - \
    > "$scratch/restore-ledger.json"
python3 - "$scratch/restore-ledger.json" <<'PY'
import json, sys
with open(sys.argv[1], encoding="utf-8") as handle:
    result = json.load(handle)
if result.get("result") != "REVIEW_REQUIRED" or result.get("code") != "FULL_MIGRATION_LEDGER_MATCH_NOT_RELEASE" or result.get("release_authorized") is not False:
    raise SystemExit("p0_disposable_restore: BLOCK_RESTORE_LEDGER_DRIFT")
PY
printf 'p0_disposable_mysql_dump_restore_integrity: pass\n'

# Negative: damage one FK on restored schema ONLY. Classifier must BLOCK it.
restored_mysql -e "ALTER TABLE learning_answer_evaluation_adjustments DROP FOREIGN KEY laea_answer_event_fk"
restored_mysql < scripts/sql/p0_mysql_readonly_schema_inventory.sql \
    | python3 scripts/ci/p0_mysql_inventory_tsv_import.py --input - \
    > "$scratch/tampered-restored-schema.json"
set +e
python3 scripts/ci/p0_mysql_inventory_offline_triage.py \
    --report "$scratch/tampered-restored-schema.json" \
    > "$scratch/tampered-result.json"
triage_code="$?"
set -e
if [[ "$triage_code" != "2" ]]; then
    printf 'p0_disposable_restore: BLOCK_NEGATIVE_CONTROL_UNDETECTED\n'
    exit 2
fi
python3 - "$scratch/tampered-result.json" <<'PY'
import json, sys
with open(sys.argv[1], encoding="utf-8") as handle:
    result = json.load(handle)
if result.get("result") != "BLOCK" or "ADJUSTMENT_APPLIED_CONSTRAINT_DRIFT" not in result.get("codes", []) or result.get("release_authorized") is not False:
    raise SystemExit("p0_disposable_restore: BLOCK_NEGATIVE_CONTROL_UNDETECTED")
PY
# The damaged restored copy must differ from the original across all schema.
restored_mysql < scripts/sql/p0_mysql_disposable_full_schema_fingerprint.sql \
    > "$scratch/tampered-restored-metadata.tsv"
if cmp -s "$scratch/source-structural-metadata.tsv" "$scratch/tampered-restored-metadata.tsv"; then
    printf 'p0_disposable_restore: BLOCK_STRUCTURAL_NEGATIVE_CONTROL\n'
    exit 2
fi
printf 'p0_disposable_mysql_full_schema_drift_detection: pass\n'

# Original source must not have been mutated by the negative control.
if [[ "$(source_mysql -e "SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA='$source_db' AND TABLE_NAME='learning_answer_evaluation_adjustments' AND CONSTRAINT_NAME='laea_answer_event_fk'")" != "1" ]]; then
    printf 'p0_disposable_restore: BLOCK_SOURCE_SCHEMA_CHANGED\n'
    exit 2
fi
printf 'p0_disposable_mysql_restore_drift_detection: pass\n'
printf 'p0_aiven_production_backup_restore_verified: false\n'
