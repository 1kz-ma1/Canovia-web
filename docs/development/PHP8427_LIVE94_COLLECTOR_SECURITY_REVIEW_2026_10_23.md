# PHP 8.4.27 — Legacy Live94 collector security review (NO AIVEN CONNECTION)

> 2026-10-23 / Track H only / **SOURCE REVIEW COMPLETED, LIVE EXECUTION HOLD**.
> Do not confuse this with e858's 94→113 Phase1 / private P1–R1–R6 approvals. Owner request: review old collector safety, **not** run it.

## Fixed reviewed assets

- Old deployed Web/production source: `15abdc9de6f24b5250fb255c579d1aa55facaf1a`.
- Verified 94-migration Git tree: `62dd8ce9bae3f73d1a390ea27242094e8165f9a2`.
- Immutable old collector: `scripts/ops/p0_mysql_operator_select_only_collect.sh` Git blob `be4bc3ff3ceb5bfb6fef29fc3dae473665c64299`.
- Exact seven old SQL/collector/validator blobs checked in [PR #839](https://github.com/1kz-ma1/Canovia-web/pull/839), whose source gate is `scripts/ci/php8427_live94_offline_source_gate.py`. That source gate **is not automatically chained to the old collector**.
- Frozen PHP runtime patch rehearsal: `71824ed16df0615e1860d7f576e6aea546561977`, no new migrations or prod deployment.
- Do not execute `main`'s newer migration ledger comparator for this old 94 baseline.

## Confirmed source contract (static observation, not real execution)

The historical collector sets a restrictive `umask 077`, blocks ambient `MYSQL_PWD`, `DB_PASSWORD`, `DB_URL`, `DATABASE_URL` and `MYSQL_TEST_LOGIN_FILE`; requires operator/target/grant flags, invokes `mysql --no-defaults --login-path=<private name> --ssl-mode=VERIFY_IDENTITY --ssl-ca=<private file> --database=<private name>`, checks `SHOW GRANTS FOR CURRENT_USER()` with strict `REFERENCES` on the exact schema and `SELECT` on only `migrations`, and calls two reviewed read-only SQL scripts. Its outputs use sanitized triage and migration classifications and say release authorized false.

These are useful controls, **not enough to approve a live Aiven connection**.

## Eight blocking gaps and remediation requirements

| Fixed blocker | Observed issue | Required independent solution before a separate owner GO |
| --- | --- | --- |
| `P0_LEGACY_COLLECTOR_NOT_CHAINED_TO_SOURCE_GATE` | Seven files are only checked as readable immediately before running, not pinned and monitored at the execution boundary | Use a reviewed immutable source/command binding and no modification interval (TOCTOU), with a fail-closed operator-local execution design |
| `P0_LEGACY_MYSQL_CLIENT_LOGIN_IDENTITY_NOT_ATTESTED` | `mysql` and `python3` are resolved from mutable `PATH`; selected private login-path/CA are not authenticated against an independently trusted expected target | Independently review trusted client/interpreter, `.mylogin.cnf` account/target and CA provenance, hostname + server identity, with no leak or trust downgrade |
| `P0_LEGACY_THREE_SESSIONS_NOT_BOUND_BY_SERVER_IDENTITY` | `SHOW GRANTS`, schema inventory and migration ledger are **three independent MySQL sessions**; first-session authorization does not bind later sessions' identity/roles/permissions | Independently verify same intended authenticated service/database/principal/effective privileges on every session, or use a new reviewed single-session bounded transport; reject drift/failover ambiguity |
| `P0_LEGACY_NO_WALL_CLOCK_AND_REMOTE_TERMINATION_BOUND` | No explicit per-connect, full-job or remote-result termination bounds; shell may wait indefinitely. EXIT trap is not remote session termination evidence | Reviewed deadlines, strict finite request/response/cleanup caps, no auto retry, STOP/HOLD on unknown; distinguish local process death from server-session end |
| `P0_LEGACY_EFFECTIVE_ROLE_SCOPE_NOT_VERIFIED` | Strict text `SHOW GRANTS FOR CURRENT_USER()` rejects many broad roles but does not independently prove effective/mandatory roles in that actual session or next sessions | Separately attest current account and active/mandatory roles/effective privileges; any invisible/broad/unknown grants -> STOP |
| `P0_LEGACY_RAW_SCRATCH_LIFETIME_NOT_ACCEPTED` | `mktemp -d` and `trap rm -rf EXIT` place raw grant and sanitized metadata in temp; SIGKILL/crash/swap/backup can leave data despite successful exit cleanup | Private controlled storage, protection from backup/sync, approved retention/deletion and incident plan; never GitHub, ChatGPT or CI |
| `P0_OPERATOR_APPROVAL_AND_INCIDENT_RESPONSE_NOT_SIGNED` | Environment variables `...APPROVED=YES` and `...VERIFIED=YES` are procedural claims, not independent operator authentication or timestamped one-use consent | Owner-private target/scope/UTC one-attempt review, signed residual risks and credible credential/admin incident route, approved only for one reviewed execution |
| `P0_LIVE_SCHEMA_AND_RESTORABLE_BACKUP_NOT_OBSERVED` | Targeted physical metadata and 94 ledger are unobserved in real Aiven; provider backup availability and actual restore are untested | Additional independently approved read-only physical/ledger scope and independently credible recovery plan; a future 94/94 result does not itself authorize release |

### Vendor source notes (checked on October 23, 2026)

- [MySQL 8.4 option-file semantics](https://dev.mysql.com/doc/refman/8.4/en/option-file-options.html): `--no-defaults` **still reads `.mylogin.cnf`**, including default login groups; `--login-path` does not alone prove service identity.
- [MySQL 8.4 `SHOW GRANTS`](https://dev.mysql.com/doc/refman/8.4/en/show-grants.html): active/default/mandatory roles differ; `SHOW GRANTS FOR user` and `SHOW GRANTS` without FOR have different mandatory-role inclusion. A strict string parser is not a comprehensive effective-privilege audit.
- A process timeout/signal or COM_QUIT sent by a client is not standalone proof of remote MySQL session termination. Do not infer this from the synthetic owned-MySQL tests of Track E.

## New independent offline code

`scripts/ci/php8427_live94_collector_risk_review.py` calls the already-reviewed **source-only gate** and reads only fixed `15abdc9` collector bytes; it does **not** execute the script, invoke `mysql`, import DB libraries, read CA/password/login-path/hostname, or open sockets. It reports source contract observations and always returns **HOLD, owner_go=false, production_go=false, real_aiven_connection_executed=false**. Missing, unpinned, malformed and changed source is BLOCK/HOLD with fixed nonsecret codes. It is intentionally not a full Bash parser: exact Git blob verification is necessary before any observations.

`tests/ci/test_php8427_live94_collector_risk_review.py` tests missing TLS/CA flag, wrong/missing SQL, missing grant gate, missing operator gate, malformed/unpinned source, and every result remains HOLD. The GitHub Actions job runs only on an ephemeral, detached 94-source **worktree**, not owner secrets, Render, Aiven or network client.

**Passing CI means documented blocker detection succeeded; never safe-to-connect.**

## Next choice / remaining gates

1. An independent review decides whether to **replace** the historical 3-connection shell collector with a narrowly scoped bounded, source-pinned, non-privileged transport, or explicitly accepts a sufficiently hardened one-off existing client. **Neither option is selected here**; do not silently activate the e858 real opener.
2. Before any implementation that can connect, validate trusted user Mac/runtime/CA/target/grants and the residual timeout, session-termination and retention/incident risks. Use owner-private records, not public hashes or credentials.
3. Only an explicitly authorized **separate** read-only attempt on the verified real Aiven service may obtain current ledger/physical evidence, with no DDL/DML/privilege changes or retry. The owner's earlier `進めて` is not a live connection GO.
4. Independent real backup/recovery acceptance, five actual GitHub `production` required checks, production merge approval, separately approved Render exact-SHA manual deployment and device smoke remain distinct.

**Outcome: `LIVE94_COLLECTOR_DESK_REVIEWED / LEGACY_COLLECTOR_NOT_APPROVED / REAL_AIVEN_NOT_RUN / PRODUCTION_NO_GO`.**
