#!/usr/bin/env bash
# Prepare reviewed Canovia Aiven metadata-only audit SOURCES on an operator's
# local machine. Never connects to MySQL, Aiven or Render and never authorizes
# a production database read, deploy or migration.
set -euo pipefail
umask 077

fail() {
  printf 'PINNED_MYSQL19_STAGE: BLOCK (%s)\n' "$1" >&2
  exit 2
}

LIVE_SHA='15abdc9de6f24b5250fb255c579d1aa55facaf1a'
CANDIDATE_SHA='6d7dbcfedd35f33ba2827ed8c7c3d98ca30fc3d6'
# Reviewed operator tool implementation (PR #752), not moving main.
AUDIT_SHA='e04c646c78e021268801cacb1d8ef1b0ad276101'

[[ "$#" -eq 2 && "$1" == '--destination' ]] || fail EXPLICIT_DESTINATION_REQUIRED
target_input="$2"
[[ "$target_input" == /* && "$target_input" != */ && "$target_input" != *'/../'* && "$target_input" != *'/./'* ]] || fail ABSOLUTE_CANONICAL_DESTINATION_REQUIRED
[[ -n "${target_input##*/}" && "${target_input##*/}" != '.' && "${target_input##*/}" != '..' ]] || fail INVALID_DESTINATION
[[ "${CI:-}" != 'true' && "${GITHUB_ACTIONS:-}" != 'true' ]] || fail INTERACTIVE_OPERATOR_MACHINE_REQUIRED
for program in git python3 mktemp bash; do
  command -v "$program" >/dev/null 2>&1 || fail LOCAL_DEPENDENCY_MISSING
done
caller="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd -P)" || fail SOURCE_REPO_UNVERIFIED
[[ "$(git -C "$caller" rev-parse --show-toplevel 2>/dev/null)" == "$caller" ]] || fail SOURCE_REPO_UNVERIFIED
parent="$(cd -- "$(dirname -- "$target_input")" && pwd -P)" || fail DESTINATION_PARENT_NOT_FOUND
target="$parent/$(basename -- "$target_input")"
[[ "$target" != "$caller" && "$target" != "$caller/"* ]] || fail DESTINATION_INSIDE_SOURCE_REPO
[[ "$caller" != "$target" && "$caller" != "$target/"* ]] || fail DESTINATION_CONTAINS_SOURCE_REPO
[[ ! -e "$target" && ! -L "$target" ]] || fail DESTINATION_ALREADY_EXISTS

# Require immutable objects already in LOCAL Git. Missing commit never fetches.
for sha in "$AUDIT_SHA" "$LIVE_SHA" "$CANDIDATE_SHA"; do
  git -C "$caller" cat-file -e "$sha^{commit}" >/dev/null 2>&1 || fail PINNED_OBJECT_NOT_PRESENT_LOCALLY
done

# mkdir is atomic. Never overwrite an existing destination.
mkdir -m 700 -- "$target" || fail DESTINATION_CREATE_DENIED
complete=false
cleanup() {
  if [[ "$complete" != true ]]; then
    rm -rf -- "$target"
  fi
}
trap cleanup EXIT

for item in audit live candidate; do
  git -c core.hooksPath=/dev/null -C "$caller" clone --quiet --local --no-hardlinks --no-checkout \
    -- "$caller" "$target/$item" >/dev/null 2>&1 || fail LOCAL_CLONE_FAILED
done
git -c core.hooksPath=/dev/null -C "$target/audit" checkout --quiet --detach "$AUDIT_SHA" >/dev/null 2>&1 || fail AUDIT_CHECKOUT_FAILED
git -c core.hooksPath=/dev/null -C "$target/live" checkout --quiet --detach "$LIVE_SHA" >/dev/null 2>&1 || fail LIVE_CHECKOUT_FAILED
git -c core.hooksPath=/dev/null -C "$target/candidate" checkout --quiet --detach "$CANDIDATE_SHA" >/dev/null 2>&1 || fail CANDIDATE_CHECKOUT_FAILED

# Use the independently reviewed pinned audit script, NOT moving main.
# Clear ambient DB credentials: the preflight must not read mysql or Aiven.
if ! env -u DB_URL -u DATABASE_URL -u MYSQL_PWD -u DB_PASSWORD -u MYSQL_TEST_LOGIN_FILE \
  CANOVIA_PINNED_MYSQL19_LIVE_CHECKOUT="$target/live" \
  CANOVIA_PINNED_MYSQL19_CANDIDATE_CHECKOUT="$target/candidate" \
  bash "$target/audit/scripts/ops/prelaunch_pinned_mysql19_collect.sh" --preflight-offline \
    > "$target/offline-verdict.txt" 2>/dev/null; then
  fail PINNED_OFFLINE_PREFLIGHT_FAILED
fi
grep -Fxq 'PINNED_MYSQL19_LOCAL_SOURCE: OFFLINE_SOURCE_INTEGRITY_CONFIRMED_NOT_AIVEN_READY' "$target/offline-verdict.txt" \
  || fail PINNED_OFFLINE_VERDICT_UNEXPECTED
grep -Fxq 'PRODUCTION_RELEASE_AUTHORIZED: false' "$target/offline-verdict.txt" \
  || fail RELEASE_GATE_UNEXPECTED

complete=true
printf 'PINNED_MYSQL19_STAGE: OFFLINE_PREPARED_NO_AIVEN_CONNECTION\n'
printf 'SOURCE_PINS: AUDIT_REVIEWED_LIVE94_CANDIDATE113\n'
printf 'AUDIT_READY_FOR_OPERATOR_REVIEW: false\n'
printf 'PRODUCTION_DATABASE_QUERIED: false\n'
printf 'PRODUCTION_RELEASE_AUTHORIZED: false\n'
printf 'NEXT: Review local offline-verdict.txt; verify Aiven identity/grants/CA privately before any separately approved live SELECT.\n'
