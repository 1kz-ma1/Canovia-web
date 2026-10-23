# PHP 8.4.27 — exact deployed Live94 audit-source gate (offline only)

> 2026-10-23 / Security hotfix / **SOURCE-ONLY REVIEW — NOT AIVEN OR PRODUCTION GO**.
>
> Scope is exclusively the unchanged **deployed old Live SHA** `15abdc9de6f24b5250fb255c579d1aa55facaf1a` and its original 94 Laravel migration files. Do **not** confuse this with the moving development `main`, the e858 fixed 94→113 audit, or the PHP hotfix build candidate `71824ed16df0615e1860d7f576e6aea546561977`.

## Decision and constraints

The old Live tree already contains a human-operated `p0_mysql_operator_select_only_collect.sh` that uses `--ssl-mode=VERIFY_IDENTITY`, a private login-path and CA, restricts `SHOW GRANTS` to `REFERENCES` on the inspected schema and `SELECT` on its `migrations` table, and classifies read-only `information_schema`/migration results without exposing raw account/data strings. **However**, the 2026-10-23 review found gaps before this historical code could be accepted as a new Live94 emergency auditing route:

1. Original collector checks that local SQL/Python files exist and are readable, but **does not bind executable source to the reviewed old SHA/blob IDs**. A local modified or injected SQL file could otherwise be executed under the restricted account.
2. Original full-ledger classifier returns `REVIEW_REQUIRED` even with known pending migrations. **An emergency runtime-only cutover is not accepted as a verified no-pending migration baseline** until exactly 94 applied/94 expected and no pending items are independently observed.
3. `SHOW GRANTS` textual validation and `VERIFY_IDENTITY` do not independently prove that the selected service/account/CA match the intended live instance, that effective mandatory/active MySQL roles are absent, that historical physical metadata is complete, or that remote sessions always terminate safely. Separately reviewed operator/target/incident and restoration evidence remain necessary.

## New code: no executable Aiven connection path

`scripts/ci/php8427_live94_offline_source_gate.py` accepts only `--checkout /absolute/clean/path` and optionally `--sanitized-ledger-summary /local/reviewed/output.json`. It **does not import the historical collector, use mysql, open sockets, start Docker, read credentials or connect to Aiven**.

It requires:
- Exact checked-out HEAD `15abdc9de6f24b5250fb255c579d1aa55facaf1a`; **no moving main** or wrong production commit.
- Exact tree object `database/migrations=62dd8ce9bae3f73d1a390ea27242094e8165f9a2`, with exactly 94 regular `*.php` files; byte-for-byte content must match each tracked Git blob. Unexpected files, symlinks, missing files and source edits are BLOCK.
- The seven historical audit shell, SQL, Python files must each match their exact pinned Git blob bytes, including the guard/importer/grants validator/full-ledger comparator; a `git status --porcelain --untracked-files=all` dirty checkout is BLOCK.
- Optional **already sanitized** JSON must equal the original full-ledger classifier's strict 94/94, pending 0, no forward repair pending, no unknown extra fields, and `release_authorized=false`. The older classifier's `KNOWN_PENDING_MIGRATIONS` with any pending item is BLOCK. This JSON can be forged; the checker does **not** authenticate it as real Aiven.
- On a matching source, the only positive code is `LIVE94_SOURCE_PINNED_NO_AIVEN` or `LIVE94_SANITIZED_LEDGER_CONSISTENT_UNAUTHENTICATED`, always `REVIEW_REQUIRED` and `release_authorized=false`. No `GO` or credential-bearing command is generated.

### Explicitly unsafe to infer

Even if this offline guard passes, it is **NOT**:
- authorization to run `scripts/ops/p0_mysql_operator_select_only_collect.sh` or the newer main audit collector;
- an owner signing R1–R6 / P1, or a fresh B2 connection grant for e858;
- demonstration that the old collector is safe against every MySQL client configuration, Python dependency/import, OS compromise, TLS/DNS/failover issue, remote termination uncertainty, or privileged-role ambiguity;
- evidence of current actual 94/94 Aiven ledger or complete physical schema;
- proof of a restorable production backup, DDL permission, protected production PR checks, Render release or iPhone smoke.

**Do not blindly execute the old collector after source-only review.** Before any connection, independently assess the original collector's runtime dependencies, trusted operator account/CA/client source, exact service identity and privilege/role interpretation, the confidential record retention and incident plan, connection timeout/termination limits, and a separately authorized read-only session. Never paste CA, private paths, login-path, host/account/roles/grants, raw ledger, secret data or signatures into GitHub/ChatGPT. A reviewed alternative procedure may supersede the legacy collector without weakening the safeguards.

## Offline CI verification

`.github/workflows/php8427-live94-offline-source-gate.yml` is an independent **main-target PR-only** audit, not a production required-check context. It uses checkout of a **detached temporary old Live Git worktree** under an ephemeral GitHub runner; no Aiven, DB, credentials, Docker, network SQL or Render.

The tests verify:
- immutable SHA/old 94-migration tree and seven exact Git blob sources;
- reject tampering of the SQL file and an additional migration PHP file, then clean up the temporary checkout;
- synthetic 94/94 sanitized summary yields `REVIEW_REQUIRED`, while pending/invalid/extra/duplicate-secret fields BLOCK;
- fails with no source or symlink, with non-secret fixed error codes and no accidental release authorized output.

The runner's GitHub source checkout is online only to obtain repository Git objects. The **new Python preflight itself** issues only local `git` commands and filesystem reads and has no network code. Passing CI never certifies future owner-machine trust or current Aiven state.

## Follow-up gate order — Track H only

1. Source-only CI green on exact PR SHA; review full 4-file diff and record the fixed Git blob IDs. Current PHP hotfix rehearsal head `71824ed1` remains unchanged.
2. Have a separate operator/security review choose a suitable **actual** metadata-only method for the historical 94 baseline, not the e858 94→113 audit by accidental fallback; accept local runtime, least-privilege inspector, target CA, TLS/session termination and privacy risk before any real connection.
3. With a fresh scope-bounded explicit owner read-only approval, a vetted privately controlled client may collect the actual baseline without data/DDL writes; verify real physical state, 94/94 and no pending migrations. The method and consent must be independently accepted. Any unexpected role, schema drift, missing row of metadata, unknown migration, query/connection termination uncertainty -> STOP/HOLD.
4. Independently accept a credible **current real-data recovery/backup** plan before any manual Web update. A provider backup listing alone isn't a successful isolated restore test.
5. Only then proceed to an separately owner-approved **protected** `production` PR with all five real GitHub required contexts for fixed release SHA, and still later an approved manual Render deploy + Web/iPhone smoke. Never deploy moving `main`; Auto Deploy stays OFF.

Canonical issue: [#488](https://github.com/1kz-ma1/Canovia-web/issues/488). Emergency SHA: `15abdc9...` Live, `71824ed1...` runtime candidate. E858 Phase1 risk ledger and credentials are a **different** track.

**Current verdict: `OFFLINE_SOURCE_PREFLIGHT_ONLY / REAL_AIVEN_NOT_RUN / RECOVERY_NOT_ACCEPTED / PRODUCTION_NO_GO`.**
