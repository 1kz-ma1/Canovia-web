# Development Operational Attestation V4 — scope-bound owner recovery records

> Status: **INTEGRATED INTO `main` BY PR #604; NOT IN VERIFIED RENDER PRODUCTION** (2026-10-14).
> Previous stages: [V1 contextual suggestion](DEVELOPMENT_PRELAUNCH_PREPARATION_V1_2026_10_14.md), [V2 source-labeled GitHub observations](DEVELOPMENT_OPERATIONAL_EVIDENCE_V2_2026_10_14.md), [V3 owner-reported app data/runtime](DEVELOPMENT_OPERATIONAL_PROFILE_V3_2026_10_14.md).
> The [future Operational Readiness specification](DEVELOPMENT_OPERATIONAL_READINESS_SPEC_2026_10_14.md) still governs *independent provider attestations*, which **are not supplied by V4**.

## Reason for this step

V3 knows that a Plan may use a server database, but Canovia does not have an authorized Render/Aiven backup/restore integration. A GitHub deployment event is not a successful Aiven backup or a restore drill. Before allowing a future read-only provider integration, V4 establishes precise environment and operator-attestation scope, per-claim provenance and fail-closed staleness.

## What V4 actually implements

- A **single owner-declared environment binding** per Plan with provider enum (\`render\` / \`other\`), environment enum (\`staging\` / \`production\`), a restricted **nonsecret** resource identifier and monotonically increasing revision on actual target changes. This is NOT a service connection, credential, authenticated identity claim or proof of deployment.
- Two **distinct, explicit, individually revocable** user-confirmed facts: “backup created” and “isolated restore exercise performed”. The server records its own timestamp and the **target revision plus V3 data_store snapshot** on confirmation. No user-supplied date, host/port, database name, password, file, key, log or free-text evidence is accepted.
- Confirmations are available only when the owner explicitly reported a persistent-data category (server DB, on-device, cloud file or mixed); no forced MySQL recovery work for \`data_store=none\`.
- Confirmation remains \`operator_confirmed\`; no “verified”, no inferred cloud retrieval proof, no statement that all data was recovered.
- A changed binding or V3 data store makes old confirmation \`stale\`. So do **7 days** for the backup confirmation and **90 days** for the isolated restore confirmation. These are conservative **re-review reminders**, not authoritative backup retention policies or guarantees of point-in-time restore.
- Records can be individually rescinded with \`clear_backup\` and \`clear_restore\`. Environment bindings can be changed deliberately but old claim timestamps remain stale until reconfirmed or revoked.
- All reads and writes of operational target details are restricted to the **authenticated Plan owner**, Development Plan only; other team actors do not see resource identifiers, timestamps or self-reported confirmations.
- The assessment displays existing six V2 checks; bounded owner confirmations overlay **only** environment, backup and restore. Missing GitHub/provider connections remain \`unknown\` in CI/deploy; \`operator_confirmed\` cannot satisfy the seven authoritative V53.8 quality gates.

## API, consent, security

- \`POST /workspace/development/operational-target/{plan}\`, authenticated, CSRF and rate-limited. Owner and Development domain checked before validation or mutation.
- Allowed actions: \`set_target\`, \`confirm_backup\`, \`confirm_restore\`, \`clear_backup\`, \`clear_restore\`.
- Every confirm action requires an explicit \`attest=yes\` checkbox; the confirmation timestamp is generated on the server. Stored identifiers are allowlisted alphanumeric/underscore/hyphen, length 5–80, **never fetched as an outbound URL**.
- Target changes and claims are transactional, with row locks on existing target; input is never executed as a shell/SQL command. No background provider polling, automatic permissions, new IAM scopes, Aiven privileges or Render configuration.
- For collaborative Plans, V4 operational details are **private to the original owner**. Future sharing would require a separately reviewed explicit access-control design.
- No raw secrets/private logs in source, DB columns or user-visible UI.

## Explicit boundary: automatic external verification not yet implemented

V4 does **not** make a Canovia-to-Render or Canovia-to-Aiven API connection. Although ChatGPT's authenticated workspace can inspect these services when connected, that does **not** authorize the deployed Canovia application to access them or read other users' infrastructure. A later provider integration must supply per-user opt-in credentials with scopes, read-only retrieval, exact provider resource binding, revocation, provider cost/rate-limit policy, tenant checks, drift handling and logs stripped of secrets.

Until then, production environment, DB backup completeness, isolated restore correctness, live deploy SHA and recovery eligibility **remain unverified by Canovia**. Manual confirmation is **not** a release GO.

## Acceptance / CI

- Backup and restore remain independent. A backup claim never proves recovery and vice versa.
- Target revision changes, storage classification changes and aging turn previously confirmed records stale.
- Missing/invalid target or Plan binding cannot import another Plan's evidence.
- \`data_store=none\` keeps recovery provisionally not applicable, even with historical claims.
- Invalid identifiers, unapproved action, absent explicit attestation, missing data-store classification and unrelated extra fields cannot create a valid proof.
- Owner/nonowner/editor/guest Plan boundaries; no read or write of private target details by collaborators.
- V1/V2/V3 regression, lint, Blade compilation, disposable MySQL schema 8.0/8.4 and migration-recovery CI. All DB migrations run in disposable CI, not Aiven production.

**Release isolation**: This V4 PR targets \`main\` only and does not authorize #513, change \`production\` or Render auto-deploy, or migrate the live Aiven DB.
