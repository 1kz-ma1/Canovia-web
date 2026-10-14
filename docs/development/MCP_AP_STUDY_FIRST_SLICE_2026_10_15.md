# Canovia MCP → AP exam study: first usable slice

Date: 2026-10-15 JST. Development-main feature candidate; **not deployed, not real-user data access**.

## Objective: fastest reliable ChatGPT-to-Canovia learning loop

The owner wants to use the plugin for the upcoming Applied Information Technology Engineer (AP) examination. Stage currently proves only a **synthetic Development Plan overview**. Do not equate it with live access to the owner's learning records.

### Implemented in this slice (read-only)
1. `canovia_get_study_plan_context`, a **second** authenticated MCP tool visible only when `CANOVIA_MCP_STUDY_READ_ENABLED=true` and `APP_ENV != staging`. The existing development tool stays development-only.
2. Requires already proven **signed/introspected ChatGPT bearer + immutable subject link + exact private study Plan ownership + explicit per-Plan overview/tasks grant + unexpired scope**, checked under DB locks against revocation/ownership changes. No collaborative, development or unrelated Plan may cross to the Study tool.
3. `overview`: limited Plan title + **owner-recorded deadline**, not a verified official exam date. `tasks`: up to eight active Task titles, state and recorded progress. Never share question, answer, scoring, attempt, description, evidence, identity or AI state. Metadata-only access audit.
4. A study Plan owner has an opt-in UI in the study Workspace using the **existing secure OAuth + separate final confirmation**. Default 7-day consent rather than indefinite sharing. The account confirmation and revocation UX are reused.
5. `study_read_enabled` defaults OFF. Even when switched ON in a misconfigured `staging` environment, all real Study MCP reads and consents are refused. The synthetic staging Live service must remain fixed at its independently approved SHA.

### Not completed in this slice

**Not usable yet against a real AP account**. Existing Canovia MCP Staging is a synthetic-only service, and it cannot be used to read the owner's actual AP Plan. A separately approved safe real-user deployment, user-initiated GitHub/Auth0 subject linking, explicit Study Plan consent and ChatGPT connector re-discovery are all necessary. The new tool isn't automatically attached to the currently connected staging plugin.

The original OAuth resource uses the existing `canovia.development.read` scope. For rapid MVP, a separately OFF-by-default Study feature + per-Plan grant isolates new study records, but a future generic connector should receive a dedicated `canovia.study.read` scope before broader launch.

### Next slices, strictly in order for AP usefulness

**B — Assessment summary read (review + opt-in)**: allow an additional *explicitly consented* scope to export bounded recent study attempt aggregate scores and weakness tags, never raw questions/answers, transcripts or private resources. Tests must prove per-user Plan ownership, cross-client separation, truncated output and revocation.

**C — Study result record (separate WRITE grant)**: create narrow user-confirmed `record_study_practice_result` endpoint/tool with independently registered OAuth write scope, Plan/task owner checks, typed bounded results, idempotency/replay protection, rate limits, user confirmation, and append-only audit. Never treat model-generated marking as verified exam assessment; only persist what owner has approved. The owner wants 10-question AP drills and wrong-topic feedback to update Canovia automatically.

**D — Pilot acceptance**: connect an explicitly approved owner study Plan (no guessing Plan ID), confirm fresh OAuth linking and overview then task consent, request and revoke, check denial with an existing JWT; no Aiven/production writes until separately authorized. Do not reuse synthetic fixture for real-user data.

**Operational boundary:** development `main` PR can merge only after CI and review. `production` branch, Render Web service configuration/deploy, Auth0 tenant setup, Aiven, and real Plans are independent security gates requiring owner approval. Current staging pin and Auto Deploy OFF remain unchanged.
