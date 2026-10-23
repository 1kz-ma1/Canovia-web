# PHP 8.4.27 emergency — old Live94 disposable MySQL execution evidence

> 2026-10-23 / Track H / **CI-only simulated data, no Aiven, no production GO**.

## Objective and exact source

The actual old production/Render Live SHA remains `15abdc9de6f24b5250fb255c579d1aa55facaf1a`. PRs #839–#842 already locked its 94 source migrations and seven historical SELECT statements (six targeted P0 physical checks and one complete Laravel migration ledger SELECT); #841 modeled a single-session protocol without an executable transport. This PR tests the **actual SQL against throwaway MySQL 8.0 and 8.4**. It neither introduces an Aiven operator reader nor modifies the PHP emergency candidate `71824ed16df0615e1860d7f576e6aea546561977`.

## Synthetic-only acceptance protocol

The new workflow `.github/workflows/php8427-live94-disposable-mysql-smoke.yml` creates a disposable MySQL service with a fixed CI-only schema `canovia_live94_synth_ci`, loopback `127.0.0.1:3306`, and documented **fictional CI credentials only**. Root's CREATE/INSERT/GRANT steps are **limited to this throwaway GitHub Actions container**, never to Aiven. No environment variable, MySQL target address or database name is taken from a production deployment.

`scripts/ci/php8427_live94_disposable_mysql_smoke.py` contains **no MySQL/DB/socket call**. It must first verify a completely clean, detached checkout of the old Live SHA using the existing seven blob pins and the precise 94-migration tree. It extracts the seven bytestrings from the immutable SQL manifest (no caller SQL) and writes only to `RUNNER_TEMP` with restrictive permissions. A deliberately **minimal and incomplete** fictional schema is created: `migrations` with exactly 94 **historical file identifiers but synthetic batch values**, and `users` with one fictional `.invalid` record.

The CI creates a limited MySQL reader with only `REFERENCES` on the CI schema and `SELECT` on its `migrations` table. Real MySQL must reject (a) `users` SELECT, (b) migrations INSERT, and (c) CREATE TABLE. The CI then executes the seven **source-pinned SELECTs** plus a GRANTS statement and begin/end identity markers **within one MySQL CLI session**, subject to `--connect-timeout=5`, GNU timeout (65 seconds plus 5-second local kill grace), size and row constraints. The private TSV remains local to the runner and is never uploaded. The parser, using the original old-Live grant/physical TSV/complete ledger validators, insists on the same connection id, server UUID, current principal, selected CI database and no roles across begin/end. It rejects broad grants and confirms the **fictional 94/94 ledger**.

A second, negative-control execution **deliberately adds unauthorized SELECT on synthetic `users`** using the disposable root account, and the redacted verifier must BLOCK that overgrant.

## Limits / security boundaries

- This is a local MySQL TCP test **without authenticated server TLS**; it does not demonstrate real Aiven hostname, CA, DNS, client-runtime provenance or TLS peer assurance.
- The read-only reader is authenticated to disposable CI-only DB, **not** to the real production Aiven instance.
- The old P0 SQL is a *targeted* inventory. The tiny fixture is intentionally missing many required P0 tables, columns and constraints, and the verifier requires that incompleteness to be recognized. 94/94 fictional ledger correctness does not establish any current real physical schema.
- The parser's begin/end identity matching proves continuity **for the CI MySQL client session only**. It does not prove a remotely authenticated single production Aiven session, guarantee that every stage met #841's simulated 10-second deadline, or verify that the MySQL server closed its session after the local client exited. The local timeout controls cannot serve as remote shutdown proof.
- Even successful tests report only `HOLD` with `real_aiven_connected=false`, `real_tls_peer_authenticated=false`, `remote_session_termination_verified=false`, `real_schema_verified=false` and `production_go=false`.
- The check requires `GITHUB_ACTIONS=true`, explicit disposable marker, exact synthetic DB/credentials and fixed loopback. Nevertheless environment variables are not a cryptographic operator attestation; the code cannot be copied as an Aiven read script.
- GitHub test logs display fixed outcome codes only; no raw TSV, GRANTS, database rows, host/account secrets, CA or private incident signatures are committed/uploaded.

## Separate remaining decision gates

1. Independently review whether a real, operator-controlled, runtime-pinned single-session TLS reader is worth adopting for emergency PHP rollout. It must bind the exact **seven** statements at execution, independently authenticate service/CA/principal, verify **effective** roles and privileges, fully cap reads/time, safely manage private buffers, and establish acceptable STOP/session-end/incident handling.
2. Confirm a **current** real-Aiven 94-migration ledger and adequate physical metadata using a separately authorized read-only method. A failed/incomplete visibility result remains HOLD; 94/94 alone is never a complete physical-schema attestation.
3. Independently accept a restorable backup/recovery plan and verify all five actual `production` required checks on the pinned emergency PR head.
4. Require explicit separate owner approval for the protected production merge and later Render deployment / Web-iPhone smoke. Render watches `main` with Auto Deploy OFF: never deploy moving main. A live Aiven SELECT requires **a new explicitly scoped owner decision**.

Canonical status: [Issue #488](https://github.com/1kz-ma1/Canovia-web/issues/488). **Old Live94 disposable MySQL evidence only; REAL_AIVEN_NOT_RUN; PRODUCTION_NO_GO.**
