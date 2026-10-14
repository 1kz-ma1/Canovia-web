# G3-R31 — Exit Decision and Independent Evidence Gap Audit

**2026-10-14 | G3 OPEN | Decision: HOLD external provider proof; no live rollout**

R11–R26 developed application-side private-file guards and synthetic denial contracts. R27–R30 produced an **unapproved** evidence inventory, isolated collection plan, owner decision request and hypothetical scope check. R31 compares those outputs to the code that actually exists and records an explicit engineering decision: **stop adding synthetic acceptance layers as a substitute for provider tests**.

## Pinned code-state audit

Read-only CLI:

```text
python3 scripts/ops/g3_exit_decision_audit.py --repo . --sha <reviewed-40-character-commit>
```

The R31 script examines only ordinary Git blobs at the **specified commit**. It never follows current mutable worktree state for its conclusions. Seven source-contract categories are assessed:

| Category | What the script can confirm | What it cannot prove |
| --- | --- | --- |
| Five additive migrations | All five expected SQL migration source files exist | Production Aiven deployment, execution, reversibility, migration approval |
| Default-OFF test flags | R23–R26 environment flags have literal default-false declarations | Runtime production configuration or feature activation |
| Unavailable independent verifier | The R25 implementation still declares no authenticated provider proof | Actual S3 response authenticity or that no other code path bypasses safeguards |
| G3 provider CI references | PG/MySQL workflows reference the four R23–R26 feature suites and source files | Live object-store concurrency, provider behavior, actual deployment |
| R27–R30 offline tools | Four offline validators have rejection-state markers, nonzero exit, and tests | Real reviewer identity, authenticated authority, cloud scope enforcement |
| R27–R30 decision documents | Each records G3 OPEN | Independent approval, original observation integrity |
| G3 exit audit open state | Audit records the outstanding production migration and G3 hold | Production database inventory or actual service state |

Presence of files and literal strings is **only a limited source check**, not a whole-program proof, a cryptographic integrity proof, a CI-run provenance assertion, nor a substitute for manual code review. The script cannot inspect Render, Aiven, IAM, provider object generations, secrets, certificates, production deployments, or real operator authorization. Git history that an attacker controls can contain plausible files; do not interpret `SOURCE_PRESENT_UNATTESTED` as external evidence.

Successful local checks yield `HOLD_EXTERNAL_PROOF_REQUIRED`, exit code **2**; missing, symlinked or drifted source gives `HOLD_LOCAL_CONTRACT_DRIFT`, also exit **2**. Both set all attestation, replay, writer-fence, safe deletion, crash recovery, owner GO, migration approval, G3 exit, retry/delete, production release and provider-call flags to **false**. The CI command checks the exact checkout and requires the external-evidence hold verdict.

## Evidence state and decision (R31)

| Gate | Verified scope | Outstanding independently verifiable acceptance |
| --- | --- | --- |
| Existing lock / upload-intent / cleanup code | Local source and synthetic database tests | Physical race, restart, and rollback under real operator conditions |
| Provider observation, nonce, response and version | R21–R26 synthetic test-only classifications | Provider-authenticated *write-time* generation proof (not self-asserted headers or APP_KEY HMAC) |
| Versioning/IAM/external writers | None from a live provider | Account/bucket policy evidence, concurrent writers and ABA-safe fencing |
| Conditional delete/retry | Deny-only defaults | Supported generation-conditional operation proved with isolated tests, separate destructive-test approval |
| R27 evidence dossier | Offline SHA-256 reference checking | Authenticated original source records and independent reviewer decisions |
| R28 collection plan | Offline plan schema | Explicitly approved isolated environment and named responsible operator |
| R29 cost & access packet | Unapproved proposed caps | Verified owner GO, real spend enforcement, scoped expiring identity |
| R30 owner checklist | All out-of-band pending | Independently authenticated signature, actual runtime enforcement |
| Production Aiven | Five migration source files | Owner-authorized operator audit, migration/backup/rollback evidence and explicit production approval |
| App / Render launch | Development branch CI only | Reviewed release SHA, physical Web/iPhone smoke and separate production promotion |

**Decision: G3 is NOT ready for production and is NOT ready for autonomous cleanup/deletion.**

**Recommended next decision:** The owner may either **(A) keep the proof gate on hold** while focusing on production-independent priorities, or **(B) explicitly authorize a narrowly scoped disposable-provider trial** with specific account/region/principal, spending and expiry limits, operator, secure evidence custody, permitted non-destructive actions and revocation plan. No default choice authorizes any tool to run a live test. A separate second-phase approval is needed before even testing conditional deletion in that disposable account.

R31 should be treated as a **checkpoint rather than R32 automatically adding another synthetic classifier**. If the user selects A, G3 stays OPEN with no further implementation loops. If B, first independently verify the provider capability claim and actual owner-approved environment; do not expose real identifiers or credentials in Git.

## R31 scope and limitations

New files: a deterministic pinned offline source-audit command, isolated local-Git regression tests, integration in Platform Release Preflight (Offline), and this decision record with audit/runbook cross-references. No new migration, SDK, cloud calls, database access, authorization service, deployment, or production changes. Owner authorization has **not** been received for a live test. Five prior G3 additive migrations remain production-unapproved.

**G3 OPEN.**
