# Canovia Platform — Environment inventory and release preflight (2026-10-14)

**Status: ACTIVE DEVELOPMENT PREPARATION / PRODUCTION NO-GO.** Platform-lane operational baseline, not a release authorization. This slice changes no production configuration, GitHub rules, Aiven privileges/data, Render deploys, costs or iOS signing. Evidence is time-specific: always re-check at the next decision. AGENTS.md and docs/CANOVIA_DEVELOPMENT_WORKFLOW.md remain authoritative.

## 1. CURRENT / RISK / RECOMMENDED

| Area | CURRENT (observed; subject to change) | RISK | RECOMMENDED / authorization boundary |
| --- | --- | --- | --- |
| Web GitHub | main at investigation snapshot 4b5958b710ff31ee32b5367474fc3b11b7b9a081, production at 15abdc9de6f24b5250fb255c579d1aa55facaf1a. | main continues to advance independently of live. | Use exact immutable release candidate SHA, never moving main for audited releases. |
| Render production | Service srv-dagfn2id0e5s73cac7ng, Singapore Free Docker; branch main; autoDeploy=no / autoDeployTrigger=off; Live deploy dep-db5fgl942hec738pbq30 at 15abdc9...; service metadata has an empty healthCheckPath. | Turning on auto-deploy while bound to main exposes unapproved code. Health-check configuration is not proven. | Keep OFF; owner separately approves branch switch to production, initial manual release and eventual checksPass. |
| GitHub production protection | Active ruleset 24954459: PR merge commits only; no deletion/non-FF; required policy/Workspace/Recovery/MySQL 8.0+8.4 checks; no bypass actors; required approving reviews=0. | Owner GO is **not enforced** by existing review count. main branch protection endpoint returned 403 to the GitHub integration. | Keep Draft/NO-GO until explicit owner release decision; never relax checks. Determine owner-enforceable gate before checksPass. |
| Frozen #513 | Draft PR #513: release/2026-10-13-bb8248f, exact SHA bb8248f25545ae0d600fbb68f66c41c360004e2c; vs live 46 commits/219 paths/2 added migrations. | Old operator audit cannot authorize newer main commits. | Preserve candidate and review its exact diff/CI and current Aiven/backup evidence before promotion. |
| Moving main | At snapshot, 126 commits ahead of live, GitHub API comparison reached 300 displayed paths (possible truncation), 15 added migration files. | Source-code migration additions do not prove Aiven's pending ledger or trigger privileges. | Compare fixed candidate, run disposable MySQL checks and operator-only read-only Aiven schema + ledger against exact SHA. |
| Startup | docker/render-start.sh executes php artisan migrate --force on startup and retries some transient connection errors. | Release and nontransactional DDL are coupled; code rollback does not reverse migrations. Potential error-log redaction merits separate audit. | Inspect each pending migration, data compatibility, grants, rollback/forward-fix plan before deploy; no startup change in this slice. |
| Staging | canovia-mcp-staging srv-db43l4nlk1mc73emseig is Free Docker, autoDeploy OFF; separate Render Postgres 17 dpg-db43rbbncjis73bmigi0-a expires 2026-11-08. | Isolated MCP staging is not proven to exercise full Web/iOS release or Aiven MySQL 8.4 behavior. | Reuse disposable CI; evaluate full staging needs/isolation/cost before proposing infrastructure. |
| Aiven production | Owner-reported project pacekeeper, MySQL service mysql-2d55f17d, database pacekeeper. Earlier #513 operator audit: 94/96 ledger entries applied, 2 pending, at exact old candidate. **Live Aiven is NOT queried here.** | Current DB identity, privileges, backup availability and pending migrations cannot be inferred from repository/Render metadata. | No production DDL/GRANT/backup without explicit approval. Restrict audit to owner-run secretless identity and metadata-only read-only preflight. |
| Backup / recovery | Owner reports TLS mysqldump > gzip > age, ciphertext SHA-256 match local/iCloud, isolated local MySQL 8.4 restore of 91 tables/94 migrations/48,916 rows. Dedicated backup user's SELECT privilege reportedly revoked. | Prior drill does not establish fresh recovery, offsite-download restore or a current recovery time. Free-tier restore/fork support unverified. | Per-release fresh encrypted backup and verified offsite ciphertext, isolation restore or recorded risk acceptance, explicit grant/revoke owner procedure. |
| iOS | Canovia-iOS main inspected at fc0e0c498afe35eda494f63356f7a0bae156bdbb. SwiftUI/WKWebView points to live Web; iOS soft-launch-preflight script exists; native App Icon was documented as pending. | Web changes reach iOS without binary upgrade; no real device tests or signing checks performed in this slice. | Verify account/session/CSRF, file/photo, external Safari, back/deep link, StoreKit read boundary on real iPhone and PWA. |
| CI / branches | Numerous narrowly scoped workflows and hundreds of long-lived feature branches. Initial PR search returned 21 open requests including Draft #513 and multiple active MCP staging Drafts; recent G3 companion grant-race push runs failed. | Cleanup without per-branch verification could discard concurrent lane work; removing existing checks for speed is unsafe. | Add small path-scoped offline preflight tests; hold branch/PR deletion or closure pending explicit inventory + owner approval. |
| Environment and Mac | Render metadata obtained without secret-bearing env values. Mac signing, keychain, backup-directory permissions, actual Render env-key presence and local tool versions not independently inspected. | Neither local developer environment nor effective live env configuration is certified. | Operator-only presence/identity checks with **no credentials/secret values emitted**. |

## 2. ACTIVE — one-command read-only candidate classifier

New: scripts/ops/platform_release_preflight.py (Python standard library). It reads **only local Git objects and remote tracking refs**, makes **no external provider/API/network/database requests**, and never asks for secrets. Inputs are exact release SHA, release/* branch, and an operator-supplied Render Live SHA. It checks:

- Release branch head is still exactly the named SHA.
- Candidate SHA is an ancestor of local origin/main and a descendant of origin/production.
- Candidate differs from production, and operator's supplied Live SHA matches origin/production.
- Changed paths and migration filenames between production and pinned SHA.
- An explicit list of manual gates remains **NOT_VERIFIED** and release_authorized remains **false** unconditionally.

From a full-history Web checkout, update **only local Git tracking refs**:

~~~sh
git fetch origin \
  +refs/heads/main:refs/remotes/origin/main \
  +refs/heads/production:refs/remotes/origin/production \
  +refs/heads/release/2026-10-13-bb8248f:refs/remotes/origin/release/2026-10-13-bb8248f
python3 scripts/ops/platform_release_preflight.py \
  --release-branch release/2026-10-13-bb8248f \
  --release-sha bb8248f25545ae0d600fbb68f66c41c360004e2c \
  --live-sha 15abdc9de6f24b5250fb255c579d1aa55facaf1a
~~~

**Important:** --live-sha is supplied by the operator, not fetched from Render by this script. Use a freshly verified Render Live SHA every time. BLOCK exits 2 on missing/moving refs, mismatched live/production, malformed SHA/branch or incompatible graph. REVIEW_REQUIRED exits 0 to allow a human to inspect the report, **not** to authorize a deployment. Git errors and credential values never enter report output.

The path-scoped .github/workflows/platform-release-preflight.yml runs disposable Git repository tests. It does not replace the production policy, recovery, Workspace or MySQL required checks.

## 3. ACTIVE — SHA-bound production release evidence

Before requesting an owner GO, record **all** of:

1. Fixed release branch/SHA, real Render Live SHA, production SHA, exact full diff; check Composer, Docker start, auth, privacy and mobile-sensitive files.
2. Required checks completed successfully for exact candidate and (for future checksPass) exact production merge SHA. A skipped required check does not count as independent verification.
3. Migration file/order list and disposable MySQL 8.0/8.4 results; owner-run Aiven migration ledger **and actual physical schema** against precisely the pinned SHA. Verify trigger/DDL privileges and historical-row compatibility. Do not equate added files with live pending count.
4. Fresh timestamped encrypted backup, file hash, offsite receipt, storage headroom, isolated recovery method and restore rehearsal status. Record any explicit accepted recovery gap.
5. Owner-only active Render environment identity/presence (APP_ENV, APP_DEBUG, DB_DATABASE/host/TLS, queue, session, MCP and auth flags, mail) without printing secret values.
6. Staging smoke and Web/iOS compatibility matrix; rollback/forward-repair decision and responsible operator; explicit product-owner production GO.
7. Controlled production Merge commit, initial manual Render deploy only after owner permission; observed Live SHA, migrations/errors, login, Study/Development, WKWebView/iPhone, Safari/PWA and post-release health evidence.

The current startup executes migrations as part of application boot. A failed app rollback is **not** a DB down-migration plan; do not use migrate:fresh or destructive down in production.

## 4. Backup & isolated restore operator runbook

**Preparation:** Operator verifies precise service/project/database and live backup support in Aiven dashboard; permissions and disk capacity, local MySQL 8.4, TLS CA, gzip, age, mysqldump and available disk. The dedicated backup account's SELECT grant is reported revoked. Re-enable it only through a **separate explicitly approved time-bounded privilege change**, with an audited revocation plan; never add unattended grant/alter scripts. Aiven Free cannot be assumed to allow fork/restore on demand.

**Collection:** On the trusted owner Mac, use the previously rehearsed TLS-verified logical snapshot pipeline (mysqldump -> gzip -> age) with fail-fast pipe semantics and InnoDB consistent-snapshot assumptions documented; decide how routines/triggers/events are included. Keep credentials out of process arguments/shell history/logs; prevent plaintext dump persistence. Validate age recipient and key custody out-of-band.

**Offsite verification:** Confirm nonzero, authenticated ciphertext, compute SHA-256 of **encrypted bytes**, copy encrypted output to iCloud Drive and compare hashes; retain only non-sensitive timestamps/source SHA/key identity labels. Prior iCloud.com existence check is not proof of offsite re-download integrity.

**Isolated restore:** With separate owner authorization, retrieve a fresh offsite ciphertext copy onto a trusted machine, compare SHA-256, decrypt with owner-held private key, restore into completely independent local MySQL 8.4, verify schema + complete migration ledger + representative row counts, and record duration. Securely clear decrypted scratch data; never send backup contents or keys to GitHub/CI/chat.

**Stop:** If grant revocation, identity, encryption, offsite hash, backup freshness, or restore path cannot be validated, leave release at NO-GO. No Aiven query, dump, DDL, privileges or offsite movement is implemented/executed in this slice.

## 5. FUTURE / NOT VERIFIED / cleanup policy

- **FUTURE:** timed, least-privilege backup automation and regular encrypted offsite drill only after cost/export-policy/key-custody/design approval.
- **FUTURE:** MySQL 8.4-equivalent general staging and real-device smoke lane, reusing existing resources where safely possible; no newly provisioned services now.
- **FUTURE:** after accepted first manual release, bind Render to protected production with autoDeployTrigger=checksPass (not On Commit), confirming required checks actually run on production merge SHA; separate owner approval.
- **NOT VERIFIED:** current Aiven backups/privileges/ledger and Free snapshot recovery, current Render env presence/values, latest real iOS signing/behavior, Mac backup file permissions, offsite downloadable restore, full general-staging suitability, main branch rules (integration 403).
- **KEEP:** release branch and Draft PR #513 (historical pinned candidate); active V5/G3/MCP staging lanes, including newer and older staging Drafts until ownership/ancestry confirmed.
- **REVIEW:** older PR #310 and stale Drafts; require current diff, associated PR disposition and branch owner before deciding closure.
- **DELETE CANDIDATE ONLY:** branches proved merged into latest main with no pending PR, no unique commits, no active ownership and no release-history relevance. This is a classification, **not** permission to delete.

References: docs/CANOVIA_DEVELOPMENT_WORKFLOW.md; docs/development/PRODUCTION_CHECKS_PASS_READINESS_2026_10_13.md; GitHub issue #488 and PR #513; Canovia-iOS README.


## 6. ACTIVE — encrypted backup copy verification (2026-10-14 follow-up)

The read-only local script scripts/ops/platform_backup_ciphertext_check.py validates that two **operator-supplied and independently stored** encrypted .age files have nonzero, identical size, are distinct regular files (not a symlink or same inode), are not group/other-readable, and both match a **previously recorded** SHA-256 of encrypted bytes. It uses Python standard library, does not touch MySQL/Aiven, never decrypts, and prints **no file path, ciphertext, plaintext or hash**.

First retrieve the offsite ciphertext onto the trusted Mac through an **operator-controlled** method (the tool cannot prove an iCloud.com download). Pass the backup-time SHA-256 from the owner's independent non-secret audit log:

~~~sh
python3 scripts/ops/platform_backup_ciphertext_check.py \
  --source "$LOCAL_AGE_FILE" \
  --offsite-copy "$OFFSITE_DOWNLOADED_AGE_FILE" \
  --expected-sha256 "$BACKUP_TIME_CIPHERTEXT_SHA256"
~~~

Missing, identical-inode, altered, symlinked, group-readable or non-.age files return BLOCK and exit 2. Matching copies return **REVIEW_REQUIRED**, exit 0 solely for further human inspection. The result **always** preserves offsite_source_provenance_verified=false, age_decryption_verified=false, isolated_restore_verified=false and release_authorized=false. This is a ciphertext hash/access-mode classifier, **not proof that age encryption is valid or the SQL dump can be restored**.

Do not pass private age identities, database passwords or dumps into CI. This script needs no credentials, does not create backups, does not grant backup account privileges, does not connect to Aiven and does not upload files. A real owner-approved recovery drill remains a separate gate. Existing path-scoped Platform preflight CI is reused for its eight synthetic-file tests (rather than creating a duplicate workflow).
