# PHP 8.4.27 — old Live94 single-session audit contract (OFFLINE ONLY)

> 2026-10-23 / Track H / **INERT SYNTHETIC CONTRACT; NOT A MYSQL CONNECTION OR PRODUCTION GO**.

## Scope and motivation

Old Live remains `15abdc9de6f24b5250fb255c579d1aa55facaf1a`, its migration Git tree `62dd8ce9bae3f73d1a390ea27242094e8165f9a2` has 94 files, and the reviewed PHP/no-DDL hotfix rehearsal is `71824ed16df0615e1860d7f576e6aea546561977`. The historical `p0_mysql_operator_select_only_collect.sh` conducts **three independent MySQL sessions** (grant check, inventory, ledger). The first session's identity/role check does not bind the others. It has no bounded total runtime or verifiable remote cleanup. Source-only integrity PR #839 and risk inventory PR #840 do not activate a safer collector.

This PR adds only a **pure Python state machine for a possible future single-session replacement** under `scripts/ci/php8427_live94_single_session_contract.py`. No client, DNS, TCP, TLS transport, SQL runner, credential loader, real grant parser, runtime operator interface, one-time GO authority, deployment integration, or connection activation exists. Existing e858 94→113 private-target adapters remain separately blocked. This module does not import those adapters or any socket/DB library.

## Contract, not a connection implementation

The synthetic-only `Live94Contract` accepts the pinned Live and migration-tree IDs and a test clock. A test supplies only synthetic fingerprints and explicit assertions in a `SyntheticWitness`, **not evidence authenticated from a real server**. The required, single ordered session is:

| Stage | Condition in the mock |
| --- | --- |
| identity | trusted target/TLS asserted; fixed opaque 64-hex session, server and principal fingerprints; one identity row |
| grants | same fingerprints/TLS, explicit scope `SCHEMA_REFERENCES_AND_MIGRATIONS_SELECT`, active/mandatory roles absent, bounded rows/bytes |
| inventory | same fingerprints/TLS, complete physical inventory assertion, bounded record count/bytes |
| ledger | same fingerprints/TLS, exactly 94 rows, bounded bytes |
| identity_recheck | same session/principal/server/TLS identity at end |
| local_quit | zero result payload; marks intent to close locally, **not** confirmed server termination |
| local_cleanup | explicit synthetic local cleanup; final remote termination still **unverified** |

Hard limits: 120 s total; 10 s between observations; seven stages max; identity/row/byte caps; strictly monotonic finite time; no restart/retry after a STOP or completed sequence. Unexpected order, replay, identity/role/scope drift, TLS mismatch, 93/95 ledger count, oversized, partial/unknown inventory, clock jumps, failed cleanup, missing stage or attempt to `connect()` / `execute()` -> terminal `Block`. Its output is limited to non-sensitive fixed status and stage count, never opaque tokens or real DB contents. `connect()` and `execute()` always fail regardless of arguments.

**Passing sequence result is `verdict=HOLD` with `OFFLINE_SEQUENCE_VALID_REMOTE_TERMINATION_UNVERIFIED`**. The report always states `real_aiven_connected=false`, `real_target_authenticated=false`, `effective_privileges_observed=false`, `schema_94_verified=false`, `owner_go=false`, and `production_go=false`. It is impossible to turn a synthetic flag into actual live authority. The mock does not prove absence of active/mandatory roles; it rejects a mock that lacks the corresponding assertion, which is only a design/test requirement.

## Tests

`tests/ci/test_php8427_live94_single_session_contract.py` uses synthetic witnesses for happy-path HOLD and wrong SHA, missed order, duplicate/replay, bad token, TLS wrong, identity/session/server/principal drift, role overreach or unknown role, partial schema, 93/95 ledger rows, oversized messages, invalid types, terminal cleanup failure, deadlines, time reversal, activation refusal and no sensitive fields in the public report.

`.github/workflows/php8427-live94-single-session-contract.yml` runs isolated unittest and static import boundary checks. CI only reads PR source and synthetic values; **no old collector invoked, no MySQL client or Aiven, no environment secrets, no Render**.

## What is still NOT implemented or verified

1. **Real single-session transport** with immutable reviewed SQL and source bytes tied to actually authenticated TLS server hostname/certificate and CA. A private independent expected target/CA and trusted OS/Python/runtime supply chain must be accepted first. Mock identity fingerprints can be forged and are not an execution permit.
2. **Effective-grant observation** including active/mandatory roles, public and least-privilege SQL-only metadata visibility. The text `SHOW GRANTS FOR CURRENT_USER()` alone is insufficient; unknown/missing evidence remains HOLD.
3. **Bounded real network behavior and remote termination**. A local QUIT/close and synthetic cleanup do not prove the Aiven server ended the session. Failure/timeout/control loss require independent incident/escalation and a new approval before retry.
4. **Physical schema completeness**. Old Live94 migration ledger equality is necessary, but insufficient for index/constraint/TRIGGER/DEFINER differences, MySQL-version/collation anomalies and missing visibility. A real audit's allowlist and operator outputs need independent design and reviews; this module does not evaluate MySQL rows.
5. **Credential/privacy discipline and risk acceptance**, a separately scoped, one-attempt, fresh owner decision, current backup/restore feasibility, protected production PR statuses and exact pinned manual Render deployment remain independent, unfulfilled gates. Do not capture or send credentials, Aiven CA, server host, private grants, signature, PII, raw SQL or raw ledger to ChatGPT/GitHub/CI.

## Next step if approved

Review whether building a genuine private Live94 reader is proportionate to the emergency's latency/security exposure; do not activate either the three-session legacy collector or the e858 private connector by simply adding a flag. Before any live-capable code is adopted, require independent security review, target/CA/role/stop/timeout design, isolated owned-MySQL tests, management Mac verification and explicit separate operator GO for the real one-time metadata read. Any unknown → HOLD. Even future Aiven read-only PASS is not a backup restore or production deploy GO.

Canonical owner ledger: [Issue #488](https://github.com/1kz-ma1/Canovia-web/issues/488). **Disposition: `LIVE94_SYNTHETIC_SINGLE_SESSION_CONTRACT / REAL_AIVEN_NOT_RUN / PRODUCTION_NO_GO`.**
