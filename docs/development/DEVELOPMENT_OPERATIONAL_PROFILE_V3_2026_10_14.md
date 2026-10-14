# Development Operational Profile V3 — self-reported context, no verification claim

> Status: implementation on feature branch; **not deployed** as of 2026-10-14.
> Evolves [V1](DEVELOPMENT_PRELAUNCH_PREPARATION_V1_2026_10_14.md) and [V2](DEVELOPMENT_OPERATIONAL_EVIDENCE_V2_2026_10_14.md).
> Future independent attestation and deploy gates remain governed by [Operational Readiness](DEVELOPMENT_OPERATIONAL_READINESS_SPEC_2026_10_14.md).

## Motivation

A single generic "back up a MySQL database" preflight is inappropriate for a static website or a mobile app whose data remains on the device. V3 records **exactly three small, optional self-reported classifications per Plan** and uses them to adapt the existing **non-binding** V2 checks.

## Current / V3 implementation

- A dedicated \`development_operational_profiles\` table with one row per Plan, \`stage\`, \`runtime\`, \`data_store\` and timestamps. Additive migration with Plan FK cascade, no changes to existing Plan or release/evidence tables.
- Owner-only authenticated, rate-limited \`POST /workspace/development/operational-profile/{plan}\`. Guard the real Plan owner ID and Development domain. No guest token, Editor/Viewer or legacy Creative rescue cross-over. All fields optional; values whitelisted and validated. Unknown is allowed.
- A small collapsed form on the Development **Work** surface, adjacent to V1/V2 guidance. Three selects: lifecycle (\`unknown,prototype,preview,public_launch,live\`), runtime (\`unknown,web,ios,android,hybrid,backend,other\`), data storage (\`unknown,none,local_device,server_db,cloud_files,mixed\`). No URLs, DB names, hostnames, secrets, free text, API calls, or automatic infrastructure probing.
- Profile contents are loaded **only** for the authenticated Plan owner on the selected Work surface. No cross-member storage classification disclosure, no provider request on GET, and GET does not update the new profile.
- Prototype suppresses automatic public-launch prompts even when a prospective launch cue exists, but a user can explicitly request a check. \`public_launch/live\` triggers a single context-aware suggestion without keyword reliance.
- \`data_store=none\`: data is \`declared\` (not verified), backup and isolated restore are \`not_applicable\` **provisionally by owner self-report**, not independent assertion that there is no data. There is no requirement for a MySQL restore test.
- \`data_store=local_device\`: device backup/migration/recovery instructions, never assume server MySQL.
- \`data_store=server_db\`: preserve \`unknown\` for backup/restore until independently attested.
- \`data_store=cloud_files\`: versioning and deleted/overwritten file restoration.
- \`runtime=ios/android/hybrid\`: explicitly suggest signing/distribution and real-device verification in deployment guidance.
- All GitHub CI/deploy statuses remain V2 source-scoped, exact-SHA observations; self-reports cannot turn them into \`verified\`, and do not change V53.8 seven quality gates.

## Privacy, operational safety, scope

The user can change the three classifications; clearing one to \`unknown\` must restore caution. Profile updates require explicit owner POST/CSRF validation. Do not export this information through MCP, AI context, public Plan, collaborator views or other connectors. It is neither proof of environment/DB state nor a release approval.

This is **not** the solution to automated provider inspection, restoration verification, migration-impact assessment or unattended deployment. They require scoped provider consent, independent evidence, tenant and identity security review and distinct implementation.

## Regression acceptance

- Unknown/prototype: Work remains lightweight. Opt-in remains available.
- Public launch or live self-report: one suggestion, no release approval.
- No durable data: backup and restore conditional \`not_applicable\`, not \`verified\`; changing to database restores \`unknown\` and need to check both separately.
- Local-only data: device-specific recovery; not MySQL.
- Mobile app: real-device and store distribution still required even if GitHub deploy observation is successful.
- Owner partial updates retain previous values; invalid input never mutates; arbitrary fields/secrets are not persisted.
- Foreign Plan, non-Development Plan, viewer/editor, guest tokens cannot update or read the private profile. Normal release/workspace intelligence remains usable.
- Migration must be tested in SQLite feature tests and targeted disposable MySQL schema CI; no modification to Aiven or Render production.

Do not promote/merge PR #513 or turn on Render auto-deploy through this work.
