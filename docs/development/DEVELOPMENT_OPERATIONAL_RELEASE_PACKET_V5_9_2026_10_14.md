# Canovia Development Operational Readiness V5.9 — Fixed candidate audit packet

> **Status: implementation on feature branch; not yet deployed or verified against Aiven/Render/iPhone (2026-10-14).**
> Scope: a local, offline **read-only and permanently unapproved** checklist for one reviewed release candidate. It creates no Canovia Plan/Task, GitHub release PR or application-side stored claim.

## Purpose and evidence boundaries

V5.8 [migration review](DEVELOPMENT_OPERATIONAL_SCHEMA_RELEASE_V5_8_2026_10_14.md) can classify an operator-held metadata/ledger snapshot at an exact Git checkout SHA. Its result is **never permission to perform DDL** and its source is not independently authenticated. V5.9 turns that bounded result and the existing #488 production requirements into a candidate-specific **audit index**: the missing approvals and independent evidence are always visible even after the V5.8 metadata structure matches.

The generator never takes secrets, raw DB data, attestations, checked PASS booleans, evidence URLs or user-authored release notes. It does not read a database, authenticate Render, fetch private Plans, call an API, upload anything, push to Git, deploy, run migrations or generate a real owner approval. It writes a deterministic JSON packet only to **standard output**; a human may keep a local copy. Do not commit/upload an operator packet containing later human annotations.

## Required local candidate pin (no branch creation by this tool)

Before creating a packet, an operator must inspect the full pending release diff versus the **independently observed production Live SHA**, pin a branch named `release/YYYY-MM-DD-<shortsha>` at the exact reviewed full 40-character SHA, and check out that SHA. GitHub `main` is fast-moving; merely citing the latest main is not proof of a reviewed release.

V5.9 refuses unless all three conditions hold simultaneously:
1. `--candidate-sha` is exactly 40 lowercase hexadecimal characters.
2. `--release-ref` is an exact local `refs/heads/release/YYYY-MM-DD-<7–12-character SHA prefix>` and that ref resolves to the supplied full SHA.
3. `git rev-parse HEAD` resolves to the same SHA.

If the branch is deleted, moved, the checkout is different, or the input contains an arbitrary ref, result is `BLOCK / CANDIDATE_REF_NOT_PINNED` and **the submitted SHA/ref are not echoed**. This verifies local Git identity **at the instant of generation**, not immutable signed custody, a protected GitHub branch or an observed Live service.

## Offline runbook

Run the existing V5.8 operator SELECT-only collector and V5.8 offline verifier only after separate real-service identity/grant/backup authorization. **Raw metadata/ledger TSV files remain on the operator machine; do not upload them into GitHub, CI, chat or app logs.** The V5.8 output is fixed-key JSON, deliberately marked unauthenticated. Save it locally only, then execute within the exact pinned checkout:

```sh
python3 scripts/ci/development_operational_v59_candidate_packet.py \
  --candidate-sha '<40-character reviewed release commit>' \
  --release-ref 'release/YYYY-MM-DD-<matching 7–12 hex prefix>' \
  --v58-report '<path to local sanitized V5.8 result JSON>' \
  > candidate-v59-local.json
```

For a **pre-audit packet**, omit `--v58-report`. The schema requirement is then `UNVERIFIED / NO_V58_METADATA_REPORT`. A valid V5.8 `REVIEW_REQUIRED` JSON marks only the schema item `REPORTED_REVIEW_REQUIRED_UNVERIFIED`, with `independently_verified=false`. A valid V5.8 `BLOCK` remains `BLOCK`; malformed or unexpected input returns `BLOCK / UNTRUSTED_V58_REPORT` with no source content in the output. Unknown keys, true verification/approval flags, unknown PASS codes, oversized/symlinked report files and non-string classifications are denied. Do not pass a raw CSV/TSV or a credential JSON instead of the sanitized result.

A zero process exit code means only *the audit checklist JSON was generated*. Every successful packet itself has `result=BLOCK`, `release_authorized=false`, and no independently verified production evidence. Failure exit code 2 indicates unsafe/invalid input.

## Independent review requirements

Every generated packet includes the full SHA/ref, a fixed-item inventory and one summary blocker. It enumerates:
- Full unshipped diff + all pending migrations since exact Live SHA; production resource/database/permissions identity; actual schema/ledger and DDL feasibility.
- Old/new app compatibility on additive schema; backup availability, isolated restore and untested-risk decision.
- Branch protection, required unconditional checks and first-deploy Auto Deploy OFF; explicit owner go/no-go.
- Actual production deployment/Live SHA, desktop+PWA+session smoke, **physical iPhone WKWebView** verification and non-destructive rollback.

Each item begins `UNVERIFIED` unless an unauthenticated V5.8 schema classification is supplied, in which case it is explicitly **reported, not verified**. There is no "all boxes green" percentage or automatic state transition. The packet **cannot independently verify** any human assertion.

## Policy and compatibility

- Current `main` is development integration. This slice does not create a release branch; pinning/review is a **separate operator act**.
- Aiven MySQL database identity must be confirmed separately (`pacekeeper`, not the formerly mistaken `defaultdb`). Check effective `DB_URL` precedence and actual selected DB; the packet cannot do this.
- V5.3/V5.7 additive migrations remain unapplied to production until owner-approved migration impact, backup and isolated recovery gates are satisfied. An unshipped candidate may contain **other** pending migrations; their full review is mandatory.
- Never run `migrate:rollback`, `migrate:fresh`, DDL, data deletion or service config changes as a side effect of generating a checklist.
- V5.8 offline V5.3/5.7 schema status alone is not a full release audit and never proves a backup, restore, owner approval, deployment SHA or device E2E.
- Promotion from an approved `release/*` to `production` requires a separate exact-candidate owner authorization, protected PR, mandatory green checks and a **merge commit**; first actual Render deploy separately authorized with Auto Deploy OFF until accepted.

## Automated acceptance

- Fixed checklist requirement IDs, default unverified states and false authorization/production side-effect flags.
- Ref/checkout/40-character SHA check against a disposable local Git test fixture including missing, retargeted and moved refs.
- V5.8 JSON whitelist/malformed/secret injection/unknown result/true authorization denial.
- CLI negative case emits only a fixed BLOCK code (no submitted ref or SHA).
- Development CI and P0 disposable MySQL 8.0/8.4 matrix both run offline V5.9 tests; no live provider calls.
- Real operator Aiven/Render/iPhone tests remain **NOT VERIFIED**, including the exact Release #488 owner go/no-go.
