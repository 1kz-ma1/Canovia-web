# Canovia Platform / Ecosystem — Future Design

> Status: **Concept**  
> Implementation status: **not implemented by this document**  
> Authority: latest main and Active Specs remain authoritative  
> Overview: `docs/future/CANOVIA_FUTURE_ARCHITECTURE_OVERVIEW.md`

---

## 1. Purpose

This document preserves the long-term platform direction that may connect:

- Canovia Core
- first-party specialist products
- partner products
- Developer Pro
- external execution providers

The target is not:

> everything must happen inside Canovia Core.

The target is:

> Canovia understands the user's current Goal / Context and connects them to the best execution method, then receives enough Result / Evidence to continue replanning.

---

## 2. Current foundations

Do not duplicate current behavior here.

Relevant current Source of Truth:

### Personalization / Context

- `docs/CANOVIA_PERSONALIZATION_SPEC.md`
- `docs/V58.25_PERSONALIZATION_BOOTSTRAP.md`
- `docs/V58.26_CAPABILITY_ACTIVATION_FOUNDATION.md`
- `docs/V58.27_LIVING_PROFILE_FOUNDATION.md`
- `docs/V58.28_STUDY_BEHAVIOR_PERSONALIZATION.md`

### Execution / Providers

- `docs/V55.6_EXECUTION_ECOSYSTEM_FOUNDATION.md`
- `docs/EXECUTION_ECOSYSTEM_DRAFT.md`
- `docs/V57.8_OPT_IN_CODING_AGENT_HANDOFF.md`

### GitHub / Development

- current V57.x Development / GitHub specs

### Entitlement / Release

- `docs/V40.6_ENTITLEMENT_FOUNDATION.md`
- `docs/CANOVIA_MONETIZATION_SPEC.md`
- `docs/CANOVIA_RELEASE_LEVEL_SPEC.md`

These foundations are CURRENT or PARTIAL. Cross-app Platform contracts below are FUTURE.

---

## 3. Canovia Family

Possible long-term family:

```text
Canovia Family

Canovia Core
Developer Pro
possible Study specialist product
possible Routine / Calendar product
other first-party products
partner products
```

This is a possible product family, not a committed product roadmap.

Each product may have independent UI and domain depth while sharing authorized platform capabilities.

---

## 4. Canovia Account

Future Canovia Account may represent:

```text
Identity
+
authorized Shared Context
+
Consent
+
Entitlement
+
Connected Apps
+
Activity Links
```

Important:

```text
signed in with Canovia
!=
all Canovia data is shared
```

Identity and Data Permission must remain separate.

---

## 5. Sign in with Canovia

Future candidate:

```text
App
↓
Sign in with Canovia
↓
Canovia Identity
↓
Authentication
↓
Consent / Scope
↓
Authorization Code
↓
App return
↓
Token
↓
Login complete
```

OAuth 2.x / OpenID Connect / PKCE are candidate technologies, not current implementation requirements.

Never share a Canovia password or Core session credential directly with another app.

---

## 6. Understood State

The platform advantage should be more than SSO.

Target:

```text
Signed-in State
+
authorized reusable Context
=
Understood State
```

A new app may begin with useful knowledge, if authorized, instead of asking the user to repeat everything.

Example:

```text
known:
- GitHub familiarity
- Study Goal
- guidance preference

missing:
- current repository
- app-specific review preference

→ ask only missing items
```

---

## 7. Shared Personalization Context

Future Context layers:

### Account Context

Cross-domain tendencies.

Examples:

- guidance preference
- language
- general availability tendency

### Domain Context

Domain-shared understanding.

Examples:

```text
development.*
study.*
routine.*
```

### App Context

App-specific state.

Examples:

- connected repository
- review configuration
- app-specific setup state

Do not reduce all user understanding into a single generic experience level.

Prefer scoped concepts such as:

- `development.experience`
- `github.familiarity`
- `study.ap.readiness`
- `guidance.preference`

---

## 8. Context metadata

Future reusable Context may need:

```text
value
scope
source
confidence
updated_at
consent
```

Candidate extensions:

- provenance
- schema_version
- freshness
- expires_at
- evidence reference

Current database fields are not automatically migrated to this conceptual shape.

---

## 9. Context freshness

Context has different lifetimes.

Example:

```text
development experience
→ relatively durable

weekly available time
→ volatile
```

A future consumer should conceptually ask:

```text
Exists?
Reliable?
Fresh?
Authorized?
```

before reuse.

---

## 10. Differential Onboarding

Target:

```text
New App
↓
Required Context Declaration
↓
authorized Canovia Context lookup
↓
Reusable Context
↓
Missing / Stale / Unauthorized Context
↓
ask only the missing items
↓
Start
```

This is the future generalization of current Personalization Bootstrap.

Current Bootstrap remains Source of Truth for current onboarding behavior.

---

## 11. Context inheritance UX

Context reuse must be visible enough to preserve trust.

Possible UX:

```text
Canoviaから引き継ぎ可能

✓ GitHub利用
✓ Development経験Context
✓ Guidance preference

追加で必要

・現在のRepository
・Review設定
```

High-impact inheritance may require confirmation.

---

## 12. Consent / Scope

Future permission may use scopes such as:

```text
profile.basic
context.development
context.learning
context.routine
activity.development
activity.study
calendar.availability
```

These are conceptual examples only.

Authentication permission and data-access permission must be distinct.

---

## 13. Revocation

Future users should be able to revoke access by:

- App
- Domain
- Scope

Revocation design must consider:

- access token
- refresh token
- cache
- copied data
- derived context
- future recommendations based on revoked data

Derived Context requires explicit treatment; deleting only the source token is insufficient.

---

## 14. Family Entitlement

Current entitlement Source of Truth remains current V40.6 / Monetization specs.

Future direction may support family-level products such as:

```text
canovia.core.premium
canovia.developer.pro
canovia.routine.pro
canovia.bundle.all_access
```

Product names must not be embedded directly in feature business logic.

This Future concept does not change current `ProductKey` or grant semantics.

---

## 15. Family navigation

Potential cross-product navigation:

```text
Routine App
"Canoviaで計画を見る"
↓
Universal Link / Deep Link
↓
Canovia Core
↓
related Plan

and reverse
```

Navigation should preserve relevant context where authorized.

---

## 16. External Data Ecosystem

Canovia understanding may eventually combine:

```text
Self Report
+
Canovia Behavior
+
Calendar Context
+
Routine Activity
+
Development Activity
+
Learning Activity
+
External Execution Result
```

Data collection must remain need-based and consent-scoped.

---

## 17. Do not replace specialist apps

A specialist app can remain the best execution surface.

Canovia Core's role:

```text
cross-domain Context
↓
decide / recommend next action
↓
select execution surface
↓
receive result
↓
replan
```

Examples:

- busy calendar → reduce Study load
- routine consistency drops → reduce Plan pressure
- PR merged → reflect Development progress
- Study performance improves → shift from weakness reinforcement to general practice

---

## 18. Activity Contract

Provider-specific data should eventually normalize into shared concepts.

Conceptual Activity:

```text
type
started_at
completed_at
duration
status
result
metrics
evidence
source
```

Other conceptual contracts may include:

### Commitment / Availability

- starts_at
- ends_at
- flexibility
- source

### Routine

- recurrence
- completion
- streak
- source

Actual schema belongs to a future Active Spec.

---

## 19. Capability Contract

Providers should be described by what they can do, not only app name.

Examples:

```text
study.practice.exam
study.flashcard
coding.repository
calendar.availability
routine.execution
fitness.activity
```

First-party and partner products should use the same conceptual capability language where practical.

---

## 20. Bidirectional Execution

Early provider integration can be one-way:

```text
External App
→ Activity / Evidence
→ Canovia
```

Mature direction:

```text
Canovia
→ Execution Request
→ Specialist Provider
→ Execution
→ Result
→ Canovia
→ Replanning
```

Example:

```text
Canovia
英単語20問 / Medium / 15分
↓
Study provider
↓
18/20 / 14分
↓
Canovia updates next learning action
```

---

## 21. Curated Execution Network

Do not start with an unrestricted open marketplace.

Preferred initial model:

> Curated Execution Network

Providers should meet quality boundaries around:

- privacy
- security
- UX
- data contract
- reliability
- revocation
- result semantics

Capability strategy may conceptually classify:

- CORE
- FIRST_PARTY_RESERVED
- PARTNER_ELIGIBLE
- PARTNER_FILLED
- UNDECIDED

These are Future strategy labels, not current enums.

---

## 22. First-party vs Partner

### Partner / external

Primary value:

> breadth.

Use when good external execution already exists.

### First-party

Candidate when:

- strong synergy with unique Canovia Context
- required data unavailable externally
- dedicated / high-frequency UX needed
- standalone demand exists
- Core would become bloated
- product can become a platform reference implementation

No Future candidate is automatically approved as first-party.

---

## 23. Calendar / Routine candidates

Calendar / Routine may be strong future fits, but do not pre-decide build-vs-partner.

```text
good provider exists
→ integrate

provider gap
+ high user value
+ strong Canovia synergy
→ first-party candidate
```

No Routine / Calendar implementation is authorized by this document.

---

## 24. First-party products as reference implementations

Canovia-created products should not depend on secret one-off contracts if avoidable.

Long-term preference:

```text
Identity Contract
Context Contract
Consent Contract
Activity Contract
Capability Contract
```

used by both first-party and partner integrations where practical.

Canovia itself becomes the first customer of its platform contracts.

---

## 25. Privacy by design

As Context power increases, privacy requirements increase.

Principles:

- data minimization
- explicit purpose
- scope limitation
- consent
- user control
- revocation
- sensitive-domain separation
- derived-context awareness

```text
Canovia Family
!=
automatic full-data sharing
```

---

## 26. Data minimization

Forbidden principle:

> collect it because it is available.

Required conceptual check:

```text
Need
+
Purpose
+
Permission
```

Only use data satisfying the intended contract.

---

## 27. Trust UX

For high-friction or high-permission integrations:

```text
Value Preview
↓
Consent
↓
Setup
```

The user should understand:

- what is requested
- why it is needed
- what becomes easier
- what it will not be used for

Current Capability Activation is the active foundation for this UX principle.

---

## 28. Ideal family experience

Future example:

```text
new Routine product
↓
Canoviaで続ける
↓
authorized context available:
- weekdays busy
- Study Goal exists
- evening execution tendency
- concise guidance preference
↓
missing:
- wake time
- routines to manage
↓
2 questions
↓
Start
```

This is an experience target only.

---

## 29. Current Architecture Gaps

Before this Future direction can become real, important gaps include:

- no cross-app identity provider
- no OAuth/OIDC authorization server
- no cross-app scope/consent store
- no generalized Shared Context contract
- no context freshness/expiry contract
- no app-level revocation model
- no generic Activity API
- no generic Capability Provider API
- no partner certification/governance
- no family navigation contract
- no cross-product entitlement sync

These gaps are expected; they are not current bugs.

---

## 30. Recommended promotion sequence

Do not promote the entire platform at once.

Candidate order:

1. continue source-aware Living Profile in Core
2. mature generic Capability Activation
3. mature Execution Provider contracts inside Core
4. define explicit Activity / Capability contract
5. validate one first-party or tightly controlled external provider
6. define consent/scopes for that narrow case
7. only then evaluate cross-app identity / Shared Context
8. Differential Onboarding after Context reuse is trustworthy
9. partner ecosystem only after first-party contract proves stable

---

## 31. Non-goals

This document does not implement:

- Sign in with Canovia
- OAuth/OIDC
- Shared Context DB
- provider marketplace
- partner APIs
- cross-product billing
- Routine App
- Calendar App
- unrestricted third-party access
