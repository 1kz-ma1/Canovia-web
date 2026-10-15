#!/usr/bin/env bash
# Owner-operated, read-only evidence collection for EXACT frozen MySQL 94->113.
# This is not an Aiven permission change, schema migration or deployment.
set -euo pipefail
umask 077

block() {
  printf 'PINNED_MYSQL19_READONLY: BLOCK (%s)\n' "$1" >&2
  exit 2
}
[[ "$#" -eq 0 ]] || block UNEXPECTED_ARGUMENTS
[[ "${CANOVIA_PINNED_MYSQL19_OPERATOR_APPROVED:-}" == "YES" ]] || block OPERATOR_APPROVAL_REQUIRED
[[ "${CANOVIA_P0_TARGET_VERIFIED:-}" == "YES" ]] || block TARGET_VERIFICATION_REQUIRED
[[ "${CANOVIA_P0_GRANTS_REVIEWED:-}" == "YES" ]] || block METADATA_ONLY_GRANTS_REVIEW_REQUIRED
[[ "${GITHUB_ACTIONS:-}" != "true" && "${CI:-}" != "true" ]] || block CI_NOT_OPERATOR
[[ -z "${DB_URL:-}" && -z "${DATABASE_URL:-}" && -z "${MYSQL_PWD:-}" && -z "${DB_PASSWORD:-}" ]] || block ENV_CREDENTIALS_FORBIDDEN

login="${CANOVIA_P0_LOGIN_PATH:-}"
database="${CANOVIA_P0_DATABASE:-}"
ca="${CANOVIA_P0_CA_FILE:-}"
live="${CANOVIA_PINNED_MYSQL19_LIVE_CHECKOUT:-}"
candidate="${CANOVIA_PINNED_MYSQL19_CANDIDATE_CHECKOUT:-}"
[[ "$login" =~ ^[A-Za-z0-9_-]{1,60}$ ]] || block LOGIN_PATH_REQUIRED
[[ "$database" =~ ^[A-Za-z0-9_-]{1,64}$ ]] || block DATABASE_IDENTITY_REQUIRED
[[ -n "$ca" && -f "$ca" && -r "$ca" ]] || block VERIFIED_CA_REQUIRED
[[ -d "$live" && -d "$candidate" ]] || block IMMUTABLE_SOURCE_CHECKOUTS_REQUIRED

repo="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
for t in mysql python3 git mktemp; do
  command -v "$t" >/dev/null 2>&1 || block MISSING_TOOL
done
for f in scripts/sql/p0_mysql_readonly_full_migration_ledger.sql \
  scripts/sql/prelaunch_pinned_mysql19_select_only_snapshot.sql \
  scripts/ci/p0_mysql_metadata_grants_guard.py \
  scripts/ci/prelaunch_pinned_mysql19_offline_gate.py; do
  [[ -r "$repo/$f" ]] || block AUDIT_SOURCE_MISSING
done

# Verify source commit pins *before* opening any MySQL connection.
[[ "$(git -C "$live" rev-parse --verify HEAD 2>/dev/null)" == "15abdc9de6f24b5250fb255c579d1aa55facaf1a" ]] || block LIVE_SOURCE_SHA_MISMATCH
[[ "$(git -C "$candidate" rev-parse --verify HEAD 2>/dev/null)" == "6d7dbcfedd35f33ba2827ed8c7c3d98ca30fc3d6" ]] || block CANDIDATE_SOURCE_SHA_MISMATCH

# The login path holds credentials outside this repo; -no-defaults and
# VERIFY_IDENTITY ensure no alternate mysql config or unverified TLS identity.
args=(--no-defaults "--login-path=$login" --ssl-mode=VERIFY_IDENTITY
  "--ssl-ca=$ca" "--database=$database"
  --batch --raw --skip-column-names --skip-auto-rehash)
scratch="$(mktemp -d)" || block PRIVATE_SCRATCH_FAILED
trap 'rm -rf -- "$scratch"' EXIT

# SHOW GRANTS is read-only, but this MUST inspect the metadata account, not
# the application's migration principal. No raw account/grant output.
if ! mysql "${args[@]}" -e 'SHOW GRANTS FOR CURRENT_USER()' > "$scratch/grants" 2>/dev/null; then
  block GRANT_QUERY_FAILED
fi
if ! python3 "$repo/scripts/ci/p0_mysql_metadata_grants_guard.py" \
  --database "$database" --input "$scratch/grants" > "$scratch/grant-check" 2>/dev/null; then
  block OVERPRIVILEGED_OR_INCOMPLETE_AUDITOR
fi
if ! mysql "${args[@]}" \
  < "$repo/scripts/sql/p0_mysql_readonly_full_migration_ledger.sql" \
  > "$scratch/ledger.tsv" 2>/dev/null; then
  block LEDGER_READ_FAILED
fi
if ! mysql "${args[@]}" \
  < "$repo/scripts/sql/prelaunch_pinned_mysql19_select_only_snapshot.sql" \
  > "$scratch/schema.tsv" 2>/dev/null; then
  block METADATA_SNAPSHOT_FAILED
fi

# CLI reads local plaintext only and outputs hardcoded verdict/counters,
# never host, account, database name, credentials or migration identifiers.
if ! python3 "$repo/scripts/ci/prelaunch_pinned_mysql19_offline_gate.py" \
  --live-root "$live" --candidate-root "$candidate" \
  --ledger "$scratch/ledger.tsv" --schema "$scratch/schema.tsv" \
  > "$scratch/result.json" 2>/dev/null; then
  printf 'PINNED_MYSQL19_READONLY: AUDIT_BLOCK\n'
  cat "$scratch/result.json"
  exit 2
fi
printf 'PINNED_MYSQL19_READONLY: REVIEW_REQUIRED_NOT_RELEASE\n'
cat "$scratch/result.json"
printf 'AIVEN_MIGRATION_ACCOUNT_TRIGGERS_DDL_CAPABILITY_VERIFIED: false\n'
printf 'PRODUCTION_RELEASE_AUTHORIZED: false\n'
