# Canovia development roadmap

Updated 2026-10-09. **Human-maintained intent; read-only GitHub preview available. PR evidence is optional and bounded; no Task auto-sync.** Repository `1kz-ma1/Canovia-web`.

## Verification semantics
Planned = intent. Implemented = code integrated. CI verified = checks for exact SHA passed. Deployed = that SHA live in an environment. Device verified = explicit device E2E evidence. Unknown is not complete. A merged PR does not establish deployment or device usability.

| Priority | Workstream | Existing evidence | Remaining acceptance |
| --- | --- | --- | --- |
| P0 | Release stability | V58 Workspace/Learning work, PR #373 merged | Confirm specific Render deploy; old/new Learning resume, permissions, autosave, iPhone/PWA/PC E2E |
| P0 | Adaptive Learning | PR #362–#366 single-question events, candidate queue, exam framework, mode recommendation, adjustment; PR #371–#373 legacy resume/immersion | Multiple response types, calibrated evidence/history, real-world study verification; official exam profiles only with verified sources |
| P0 | GitHub-native roadmap | Read-only parser/importer, PR/Issue/CI observation, compact Context endpoint (PR #375–#380); scoped handoff (PR #381); manual revision diff (PR #383–#384); GitHub-first agent pull handoff (PR #385–#386) | Validate actual AI-provider GitHub connector access, real-device copy, actor authorization and provider cost limits; Canovia-private AI delegated API is NOT implemented; no Task bulk JSON mutation |
| P1 | iOS | V52.0 SwiftUI/WKWebView bridge foundation | Latest regression E2E, signing, TestFlight, StoreKit purchase/restore |
| P1 | Pricing / telemetry | ProductKey/Entitlement, BehaviorEvent and AI telemetry | Numerical prices/allowances, MRR/MAU/AI cost/gross-margin dashboard |
| P1 | Evidence-first Plan Draft | V58.71–V58.76 work described in [active plan](../wip/2026-10-08_STATE_FIRST_PRE_RELEASE_EXECUTION_SPEC.md) | Verify actual main and approval/notification flows; cost limits, multiple candidates |
| P2 | Bookkeeping | V58.61–V58.62 initial diagnosis and journal input | Expand question coverage and spaced review |
| P2 | Career | Career Capture, Interview Review, V58.60 exploration | E2E and experience-based exploration; user-confirmed MATCH plus import |
| P2 | Development automation | GitHub App + evidence/collaboration; GitHub-first AI handoff in PR #385–#386; owner-only Canovia-private Context preview, ChatGPT sharing preparation (scope/expiry/revoke + account recovery, NO token; PR #388) | OAuth resource discovery/deny-all foundation (PR #389); RFC7662 token verifier (PR #390); inactive external-subject/Plan policy (under test); then OAuth 2.1 AS, verified identity linking, explicit delegated consent, actor/Plan/repo revocation/audit, idempotent PR/Issue/Commit evidence, team authorization, no false completion |
| P2 | Launch | Existing branding/X assets | App Store legal/assets, TestFlight, release and retention validation |

## Next slices
1. Verify production deployment and iPhone/PWA/desktop Learning regression at an exact SHA.
2. Validate read-only Markdown preview and the user-triggered scoped AI Context copy on a real device, using a linked public GitHub repository; record repo/path/SHA and unknowns.
3. Validate explicit PR/Issue references and available Checks on demand; separately verify deploy and device behavior. Validate manual SHA-pinned revision comparison and GitHub-first AI pull with an actually connected coding AI.
4. Design a consent-based, revocable delegated external-AI read API only if GitHub-first pull cannot meet private-Context needs. Keep Plan/Task history private. Consider opt-in, reviewable execution Task suggestions **only after** user validation.

2026-12-01 is an initial release-readiness target, not proof of launch. Business success means Canovia-only subscription revenue >= JPY 230,000/month for several months.

References: [Product Spec](../CANOVIA_PRODUCT_SPEC.md), [Learning draft](../learning/adaptive-learning-experience-draft.md), [GitHub-native contract](GITHUB_NATIVE_ROADMAP_CONTRACT.md), [Future architecture](../future/CANOVIA_FUTURE_ARCHITECTURE_OVERVIEW.md).
