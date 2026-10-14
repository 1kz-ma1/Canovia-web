# Canovia Development Operational Readiness V5.6 — Accessible stale-form recovery

> Status: feature PR candidate / not deployed (2026-10-14).
> Scope: explicitly owner-authorized Development Work POST actions for V5.3 service match and V5.5 revision/storage-fenced backup & restore confirmations.

## Motivation and experience

V5.5 intentionally denied old-form confirmations with HTTP 409, preventing a user from recording recovery on a target/environment/storage other than the one they inspected. The generic Laravel 409 error, however, did not explain what the user should do next—particularly confusing inside iOS WKWebView where an outdated tab can remain visible.

V5.6 keeps the fail-closed behavior and exact HTTP 409 status but renders a narrow, accessible Canovia message: the target environment or storage may have changed, nothing was recorded, and the owner needs to **open the latest Development Work and personally check the current service/environment/storage** before confirming again. The page contains only a safe, server-constructed same-app route, never an arbitrary URL, OAuth callback, previous form state, submitted secret or service identifier. No automatic retry, GET-side write, or user-agreed continuation is performed.

## Implementation

- Controller: replace only the two V5.5/V5.3 **stale confirmation** guards with an owner-scoped `rejectStaleConfirmation(Request, Plan): never` response.
- Still validate owner and Development domain **before** examining the operation. Existing 404 for another user or collaborative Editor remains.
- A stale service ID/target revision or backed-up/restored data-store mismatch raises an `HttpResponseException` inside the ongoing DB transaction, so no human attestation fields are persisted. Other unrelated 409/422/validation cases are not converted.
- HTML/form `Accept: text/html`: render `workspace.development.operational-stale-conflict` using the normal Canovia layout, a visible 409 heading, a three-step instruction and a single same-origin `GET workspace.development.index` link with `plan_id`, `surface=work`, `release_preflight=1`. GET re-evaluates owner and reads current V3/V4/V5 status; it does **not** automatically confirm.
- JSON/`expectsJson()`: 409 JSON with stable `code=development_operational_stale`, safe user-readable `message`, and `reload_url`; no private target/status/credentials. Both branches set `Cache-Control: private, no-store` and `Referrer-Policy: no-referrer`.
- The UI uses `role=alert`, semantic heading and mobile-friendly minimum touch target. It does not add JavaScript or use `history.back()` which might resubmit a stale form.

## Acceptance

- Service confirmation with old revision: 409, accessible safe link, no service ID or attacker-supplied redirect, server timestamp unchanged.
- Backup/restore with mismatched target revision or selected storage: same HTML 409 page and no writes; old V5.5 behavior stays.
- JSON client gets a stable code/reload URL at 409 and never receives private target data.
- Other actor/Editor is denied with 404 **before** custom 409 page or URL is revealed.
- Valid current revision/category confirmations still require fresh `attest=yes`; explicit clear actions unchanged.
- The link reopens current Work and does not itself create a confirmation.
- Existing Development V1–V5.6 CI, Blade compilation, Workspace Resume, Platform and Migration Recovery must pass at the PR HEAD.

## Security, scope and residual work

No schema change, new authorization, release gate, deployment, external provider observation, Render credential, Plan/Task mutation, outbound network call or production Aiven change. An operator self-report never turns into independent `verified` evidence or permits release.

This is a code-level PHP/Laravel/browser-template acceptance, **not** a real-Web deployment or physical-iPhone WKWebView acceptance. Existing V5.5 manual checklist remains mandatory and must be performed against an explicitly approved non-production deployed SHA. Production promotion, Aiven schema audit/DDL and real device testing remain separate gates. The V5.5 A→B→A storage-epoch residual issue is not fixed here.
