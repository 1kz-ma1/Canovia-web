# V53 Canovia Intelligence — Implementation Handoff

> **TEMPORARY IMPLEMENTATION DOCUMENT**
>
> This file exists so implementation can continue safely across ChatGPT conversations and handoffs.
>
> **DELETE THIS FILE when the V53 large update is fully implemented and its durable decisions have been incorporated into permanent product / architecture documentation.**
>
> Do not leave this file as long-term product specification after V53 completion.

## Why V53 exists

Canovia has become strong at creating and managing Plans and Tasks, but Task-centered execution can become a constraint.

The new direction is:

```text
Goal
→ Current State
→ Gap / Readiness
→ Decision
→ Next Action
→ Evidence
→ Outcome
→ State update / Replan
```

Task remains visible and trustworthy for users, but Task is a projection of current execution intent rather than the source of truth.

The first public focus domains are:

- Study
- Development

Canovia remains architecturally general so more domains can be added later.

## Product direction

### Study

Target experience:

```text
test-range photo / screenshot / PDF
→ parse subjects and units
→ estimate required learning
→ diagnose current level
→ generate initial actions
→ practice
→ automatically record Evidence
→ update mastery / readiness
→ replan
```

The user should not need to manually break down every subject or estimate every study task.

Study must support ordinary school tests as well as professional exams.

### Development

Target experience:

```text
repository connection
→ observe Issue / Branch / Commit / PR / Review / CI / Merge / Deploy
→ normalize as Evidence
→ update development State
→ calculate release readiness
→ propose next Action
```

GitHub changes should inform Canovia automatically.

PR merge alone must not blindly mean 100% complete; verification and quality gates may still be missing.

## Intelligence architecture

```text
Evidence Collector
      ↓
State Builder
      ↓
Readiness Engine
      ↓
Decision Engine
      ↓
Action Generator
      ↓
Execution Surface
      ↓
Outcome Collector
      └──────────→ State Builder
```

Reasoning later sits behind the Decision boundary:

```text
Canovia Intelligence
      ↓
Reasoning Router
├─ deterministic logic
├─ domain rules
├─ OpenAI
└─ future Canovia model
```

Natural conversation can continue to use OpenAI. The strategic asset to own is Canovia-specific decision intelligence, not a general-purpose conversational foundation model.

## Data strategy

From the beginning, preserve the ability to learn from:

```text
State before
→ Decision
→ Action
→ Outcome
→ State after
```

This is more strategically useful than merely storing chat transcripts.

Future Canovia-owned models may target narrow problems such as:

- required study effort estimation
- weakness classification
- study readiness prediction
- Next Action ranking
- GitHub progress estimation
- release readiness prediction

Do not train or optimize against user-private raw content by default.

## Free / Premium direction

Free must still experience the actual Canovia loop, including a limited amount of Native AI where it is required for the product to work.

Avoid:

```text
Free = copy prompt to external AI
Premium = actual Canovia
```

Prefer:

```text
Free
→ basic capture
→ basic State / Readiness
→ basic Next Action
→ limited replanning

Premium
→ higher-frequency replanning
→ long-term analysis
→ cross-context intelligence
→ deeper personalization
→ automatic execution
→ higher-cost reasoning
```

External AI remains valuable as an execution destination, especially for development prompt handoff.

Optional rewarded ads may later fund extra expensive AI operations, but normal execution surfaces should not interrupt focus with unsolicited advertising.

## Implementation stages

### V53.0 — Intelligence Contract

Status: **MERGED**

Purpose:

- define State / Evidence / Readiness / Decision / Action / Outcome / Confidence
- define generic processing interfaces
- make Task a downstream projection, not a Core dependency
- document how existing TaskEvidence / GuidedExecution / GitHub services fit
- no database migration yet
- no UI change yet

Branch:

`feature/v53-0-intelligence-contract`

Permanent spec:

`docs/V53.0_INTELLIGENCE_CONTRACT.md`

### V53.1 — State / Evidence Foundation

Status: **IN PROGRESS**

Branch:

`feature/v53-1-state-evidence-foundation`

Permanent spec:

`docs/V53.1_STATE_EVIDENCE_FOUNDATION.md`

Implemented so far:

- `TaskEvidenceAdapter`
- canonical JSON normalization
- Evidence / State / snapshot fingerprints
- stable Evidence trace references
- StudyStateBuilder
- durable `intelligence_state_snapshots`
- StateSnapshotStore with idempotent exact-snapshot persistence
- raw provider/free-form payload exclusion
- V53.1 contract tests

Important semantics:

- `state_fingerprint` excludes captured time and represents semantic State
- `state_reference` includes snapshot time and represents one observation
- same snapshot retry must dedupe
- same semantic State observed later must create a new snapshot
- Task progress is not imported as Study State truth

### V53.2 — Decision & Readiness Engine

Planned:

- deterministic readiness primitives
- explainable gap representation
- decision candidate selection
- confidence handling
- decision trace suitable for future evaluation
- no provider-specific reasoning in feature code

### V53.3 — Reasoning Router

Planned:

- provider-neutral reasoning contract
- deterministic/domain/OpenAI routing
- cost / latency / quality telemetry
- structured outputs
- future Canovia-model insertion point
- conversation AI and decision AI remain separate concerns

### V53.4 — Study Capture

Planned:

- image / screenshot / PDF test-range capture
- extract subjects / units / pages / deadlines
- reviewable structured import
- minimal manual input
- ordinary school tests must work, not only professional exams

### V53.5 — Study Intelligence

Planned:

- mastery
- coverage
- retention
- speed
- estimated effort remaining
- exam readiness
- diagnostic and practice feedback
- adaptive remaining work

### V53.6 — Adaptive Action

Planned:

- Action-first Home
- explain why now
- Task as optional/durable projection
- retire or change Actions when Evidence changes
- user can inspect Plan / Task / Evidence / Decision history for trust

### V53.7 — Developer Evidence Sync

Planned:

- GitHub event → normalized Evidence
- Issue / Branch / Commit / PR / Review / CI / Merge / Deploy
- automatic observation rather than manual refresh as the long-term goal
- do not map events directly to progress without confidence / quality semantics

### V53.8 — Developer Readiness

Planned:

- implementation readiness
- tests
- review
- deploy
- verification
- spec synchronization
- release readiness
- next action based on missing quality gates

### V53.9 — Intelligence UX

Planned:

- default presentation: current state, readiness, biggest gap, next action, reason
- details: Task / Plan / Evidence / Decision history
- avoid turning the product back into a large manually maintained task list
- validate Study and Development acquisition surfaces before expanding domains

## Existing assets to preserve

Do not rebuild these casually:

- `TaskEvidence`
- `TaskMilestone`
- `GuidedExecution`
- `GitHubEvidenceDecisionService`
- `NativeAiRun`
- existing Study Practice / Recall data
- Product Grant / Entitlement architecture
- iOS SwiftUI + WKWebView shell

V53 should integrate them behind the new Intelligence boundaries where useful.

## Current implementation checkpoint

V53.0 work started from Canovia-web `main`.

Added so far:

- `App\Intelligence\Enums\IntelligenceDomain`
- `App\Intelligence\Enums\ReadinessLevel`
- `App\Intelligence\Data\Confidence`
- `EvidenceObservation`
- `StateSnapshot`
- `ReadinessAssessment`
- `Decision`
- `ActionProposal`
- `OutcomeObservation`
- `StateBuilder`
- `ReadinessEvaluator`
- `DecisionEngine`
- `ActionGenerator`
- `OutcomeInterpreter`

V53.0 implementation checkpoint:

- contract tests: complete
- permanent V53.0 spec: complete
- Product Spec synchronization: complete
- validation / regression run: complete
- validation run: GitHub Actions #37144761375
- temporary validation workflow: removed after success
- PR #208: merged
- merge commit: b2e6ad6f46968cdf5d4b554b42b6b1ce25e2e578
- database migration: intentionally none
- user-facing UI change: intentionally none

Validation result:

- Intelligence PHP lint: success
- V53.0 contract test: success
- V40.6 Entitlement regression: success
- V46.8 GitHub Evidence regression: success
- V52.0 iOS readiness regression: success

Current implementation:

- V53.1 State / Evidence Foundation is in progress
- existing TaskEvidence is normalized rather than duplicated
- Study is the first concrete StateBuilder
- durable State storage is now introduced with controlled normalized fields
- next before V53.1 completion: validation, Product Spec sync, PR

## Completion / deletion rule

This file is intentionally temporary.

When the entire V53 series has been implemented and the durable architecture is reflected in permanent docs:

1. verify permanent docs contain the final architecture
2. remove stale handoff-only notes
3. **delete `docs/V53_IMPLEMENTATION_HANDOFF.md`**
4. include its deletion in the final V53 completion PR

If implementation changes direction before completion, update this file immediately so a new chat does not continue from obsolete assumptions.
