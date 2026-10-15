# Learning Context Watch v0.5 — source mutation lock order hardening

Date: 2026-10-15 (JST)
Owning lanes: Intelligence + Learning. Release status: **development-only lock hardening, no automatic monitoring or delivery**.

## Observed gap

Source Capture v0.2 (#707) and Watch Persistence v0.3 (#711) already acquire the parent Plan row's `FOR UPDATE` lock before reading Task state and applied/approved practice records. MCP Study proposal confirmation also uses Plan → Task → Grant/Proposal ordering. However the **legacy Task create/update/delete controller** writes without locking the parent Plan, and **legacy Study Practice apply** originally locked Attempt → Task without first locking Plan. Those routes could update visible Study data while a reader was constructing its checksum, and could participate in a conflicting lock order with MCP and Context Watch.

## This bounded corrective slice

- TaskController::store now locks the owning Plan and re-authorizes owner before creating Task/dependencies. Update locks live Plan → live Task and rechecks owner before updating and syncing dependencies/resources. The before-status/title for activity log comes from the locked Task. Destroy locks Plan → Task and re-authorizes owner before deletion. All these steps occur in transaction; deletes are not split from their lock.
- StudyPracticeController::applyAssessment now obtains **Plan → Task → Attempt** locks and verifies current Study category and Task authorization before advancing Task progress and setting `applied_at`. Strategy and progression calculations use the locked Plan and Task inside the same transaction. Previously captured user answers/assessment are not re-evaluated and the existing one-time apply behavior remains.
- Watch capture and persistence source code, MCP permission scopes, underlying snapshot schema and dedupe values are unchanged. No new migration, cron, queue, notification provider, feature activation or production deployment.
- New reproducible **two-physical-connection** tests exercise Plan-lock contention for Task update/delete, applied Study evidence and manual Watch comparison. Each test uses a standalone disposable local PostgreSQL 17 / MySQL 8.4 database, bounds the second connection's lock wait, verifies no partial write or premature persisted review event, then confirms normal operation after unlocking. Existing Study progression/Practice and Watch contract suites rerun in SQLite. Real DB tests can only run with explicit CI-only env + exact loopback database and cannot be pointed at real Aiven/Render.

## What this does **not** prove

The platform has other Task and Study writers (review/Companion/AI/automation/derived evidence, legacy imports, direct SQL, external integrations). They are **not all inventoried and governed by the same shared Plan lock** here. Source Capture's eligible history is bounded; a correction outside the 8-attempt window is not a complete historical event record. Cross-database single-row contention validates this slice's participating writer paths, NOT a global serializability proof, multi-table atomic event history, or notification delivery eligibility. A wait timeout safely rolls back a losing writer; the request may show a generic error until UX-level collision retries are separately reviewed.

Next acceptance needed before automatic checks: inventory/opt-in writer paths and consistently enforce Plan-first lock or a stronger source-level revision epoch/fence, test concurrent independent connections across every source mutation including corrections/deletions and opt-out/ownership transfer, validate actual MySQL isolation and retention sweeper, explicit duration-bound owner consent, rate limits/costs. Prior to user notifications: quiet-hours, revocation, delivery-idempotency, opt-in push/email and end-to-end device tests. Do NOT infer release or real AP pilot authorization from this change.

## Owner and deployment boundary

Default `CANOVIA_LEARNING_CONTEXT_WATCH_PERSISTENCE_ENABLED=false` remains unchanged, and no background polling or notifications are introduced. Development `main` integration can be squash-merged if required CI green and diff reviewed. `production` branch promotion, live Aiven DDL, Render auto deploy, synthetic MCP Staging and personal AP pilot HOLD are independent and unchanged.
