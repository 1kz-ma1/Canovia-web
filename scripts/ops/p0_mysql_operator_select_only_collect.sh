#!/usr/bin/env bash
# Owner/operator-initiated, fail-closed, SELECT-only P0 metadata evidence collection.
# NOT for GitHub Actions deployment, Render shell, or privileged production credentials.
# Never reads application rows. Never changes Aiven users, grants, allowlists or data.
set -euo pipefail
umask 077

block() {
  printf 'P0_READONLY_COLLECT: BLOCK (%s)\n' "$1" >&2
  exit 2
}

[[ "$#" -eq 0 ]] || block 'UNEXPECTED_ARGUMENTS'
[[ "${CANOVIA_P0_OPERATOR_APPROVED:-}" == "YES" ]] || block 'OPERATOR_APPROVAL_REQUIRED'
[[ "${CANOVIA_P0_TARGET_VERIFIED:-}" == "YES" ]] || block 'TARGET_VERIFICATION_REQUIRED'
[[ "${CANOVIA_P0_GRANTS_REVIEWED:-}" == "YES" ]] || block 'READONLY_ACCOUNT_REVIEW_REQUIRED'
[[ "${GITHUB_ACTIONS:-}" != "true" ]] || block 'CI_NOT_AN_OPERATOR'
[[ -z "${MYSQL_PWD:-}" && -z "${DB_URL:-}" && -z "${DATABASE_URL:-}" ]] || block 'ENV_CREDENTIALS_FORBIDDEN'
[[ -z "${DB_PASSWORD:-}" ]] || block 'APP_CREDENTIALS_FORBIDDEN'

login="${CANOVIA_P0_LOGIN_PATH:-}"
database="${CANOVIA_P0_DATABASE:-}"
ca="${CANOVIA_P0_CA_FILE:-}"
[[ "$login" =~ ^[A-Za-z0-9_-]{1,60}$ ]] || block 'LOGIN_PATH_REQUIRED'
[[ "$database" =~ ^[A-Za-z0-9_-]{1,64}$ ]] || block 'DATABASE_IDENTITY_REQUIRED'
[[ -n "$ca" && -f "$ca" && -r "$ca" ]] || block 'TLS_CA_REQUIRED'

repo="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
for tool in mysql python3 mktemp; do
  command -v "$tool" >/dev/null 2>&1 || block 'MISSING_OPERATOR_TOOL'
done
for f in \
  scripts/sql/p0_mysql_readonly_schema_inventory.sql \
  scripts/sql/p0_mysql_readonly_full_migration_ledger.sql \
  scripts/ci/p0_mysql_inventory_tsv_import.py \
  scripts/ci/p0_mysql_inventory_offline_triage.py \
  scripts/ci/p0_mysql_full_ledger_offline_check.py; do
  [[ -r "$repo/$f" ]] || block 'PINNED_SOURCE_REQUIRED'
done

# The profile and target must be independently verified against Aiven Console.
# --no-defaults prevents an unrelated ordinary MySQL options file from silently
# replacing parameters. MySQL still reads its encrypted login-path file.
# VERIFY_IDENTITY authenticates the TLS peer against the operator's Aiven CA.
mysql_args=(
  --no-defaults
  "--login-path=$login"
  --ssl-mode=VERIFY_IDENTITY
  "--ssl-ca=$ca"
  "--database=$database"
  --batch --raw --skip-column-names --skip-auto-rehash
)

scratch="$(mktemp -d)" || block 'PRIVATE_TEMP_REQUIRED'
trap 'rm -rf -- "$scratch"' EXIT

# SHOW GRANTS is read-only and intentionally used *before* the SELECT inventory.
# Explicitly reject admin/DML/DDL privileges, grant options, dynamic roles and
# unknown permission formats. Do not print the identity, host or grant strings.
if ! mysql "${mysql_args[@]}" -e 'SHOW GRANTS FOR CURRENT_USER()' \
    > "$scratch/grants" 2>/dev/null; then
  block 'GRANT_INSPECTION_FAILED'
fi
has_select=0
while IFS= read -r grant || [[ -n "$grant" ]]; do
  case "$grant" in
    "GRANT USAGE ON "* ) ;;
    "GRANT SELECT ON "* ) has_select=1 ;;
    * ) block 'NON_SELECT_GRANT_OR_UNVERIFIED_ROLE' ;;
  esac
  [[ "$grant" != *" WITH GRANT OPTION"* ]] || block 'GRANT_OPTION_FORBIDDEN'
done < "$scratch/grants"
[[ "$has_select" -eq 1 ]] || block 'SELECT_ONLY_GRANT_NOT_PROVEN'

# mysql failures and SQL source/errors are not echoed. The GitHub-reviewed
# SQL reads information_schema and the non-personal Laravel migrations ledger.
# Only fixed status codes produced by strict offline importers reach stdout.
if ! mysql "${mysql_args[@]}" \
    < "$repo/scripts/sql/p0_mysql_readonly_schema_inventory.sql" 2>/dev/null \
    | python3 "$repo/scripts/ci/p0_mysql_inventory_tsv_import.py" --input - \
       > "$scratch/sanitized-inventory.json"; then
  block 'INVENTORY_QUERY_OR_IMPORT_FAILED'
fi
if ! python3 "$repo/scripts/ci/p0_mysql_inventory_offline_triage.py" \
    --report "$scratch/sanitized-inventory.json" > "$scratch/triage.json"; then
  # BLOCK is an expected *safe decision*, not a reason to reveal raw output.
  printf 'P0_READONLY_COLLECT: SCHEMA_BLOCK\n'
  cat "$scratch/triage.json"
  exit 2
fi

if ! mysql "${mysql_args[@]}" \
    < "$repo/scripts/sql/p0_mysql_readonly_full_migration_ledger.sql" 2>/dev/null \
    | python3 "$repo/scripts/ci/p0_mysql_full_ledger_offline_check.py" --input - \
       > "$scratch/ledger.json"; then
  printf 'P0_READONLY_COLLECT: LEDGER_BLOCK\n'
  cat "$scratch/triage.json"
  cat "$scratch/ledger.json"
  exit 2
fi

# Human-verified service identity, production backups, and restoration remain
# external release gates even when both checks report REVIEW_REQUIRED.
printf 'P0_READONLY_COLLECT: REVIEW_REQUIRED_NOT_RELEASE\n'
cat "$scratch/triage.json"
cat "$scratch/ledger.json"
printf 'AIVEN_REAL_RESTORE_VERIFIED: false\n'
printf 'PRODUCTION_RELEASE_AUTHORIZED: false\n'
