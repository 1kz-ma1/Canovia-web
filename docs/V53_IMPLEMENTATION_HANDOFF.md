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

Status: **MERGED**

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

Status: **MERGED**

Branch:

`feature/v53-2-decision-readiness-engine`

Permanent spec:

`docs/V53.2_DECISION_READINESS_ENGINE.md`

Implemented so far:

- `StudyReadinessEvaluator`
- structured Study gap codes
- conservative readiness confidence policy
- `DecisionCandidate`
- `StudyDecisionEngine`
- deterministic candidate ordering / selection
- readiness / decision fingerprints
- durable `intelligence_decision_traces`
- `DecisionTraceStore`
- generic `DecisionOrchestrator`
- V53.2 behavior tests

Important semantics:

- no usable practice Evidence => readiness score remains null
- Ready requires score >=80, at least 3 practice attempts, recall Evidence and no observed weakness
- Decision input pins the exact State snapshot, not only semantic State
- arbitrary provider payloads are not persisted in Decision traces
- V53.2 remains provider-neutral and deterministic

### V53.3 — Reasoning Router

Status: **MERGED**

Branch:

`feature/v53-3-reasoning-router`

Permanent spec:

`docs/V53.3_REASONING_ROUTER.md`

Implemented so far:

- `CandidateDecisionEngine`
- `DecisionReasoningProvider`
- `OpenAiDecisionReasoningProvider`
- `ReasoningRouter`
- `ReasoningRunStore`
- `ReasoningCostEstimator`
- `ReasonedDecisionOrchestrator`
- durable `intelligence_reasoning_runs`
- deterministic / auto / openai modes
- existing FeatureAccessService entitlement reuse
- exact-candidate Structured Output selection
- deterministic fallback on provider failure
- successful exact-reasoning reuse to avoid duplicate AI cost
- latency / token / optional cost / baseline-agreement telemetry
- V53.3 behavior tests

Important semantics:

- default mode remains deterministic
- OpenAI cannot invent a new candidate
- final confidence cannot exceed candidate confidence
- provider/model telemetry is not part of semantic Decision identity
- provider failure is not cached as successful reasoning
- conversation AI remains separate from Decision reasoning

### V53.4 — Study Capture

Status: **MERGED**

Branch:

`feature/v53-4-study-capture`

Permanent spec:

`docs/V53.4_STUDY_SCOPE_CAPTURE.md`

Implemented:

- Free `study_scope_capture` capability
- image / screenshot / PDF test-range capture
- private source reuse through existing `InboxItem`
- `StudyScopeCapture` draft/review lifecycle
- `StudyScopeItem` confirmed scope facts
- structured Native AI extraction
- subjects / units / pages / date extraction
- ambiguity preservation instead of forced inference
- source preview and review/edit UI
- manual fallback when Native AI is unavailable or fails
- Human Confirmation before confirmed scope mutation
- Plan-level Study Scope entry
- Study category-profile support beyond certification-only categories
- confirmed-scope edit/reconfirm
- unconfirmed capture discard / private file cleanup

Important semantics:

- Study Capture is Free core
- AI draft is not Study truth
- no Task creation from V53.4
- no Task progress mutation
- V53.4 itself did not project Intelligence State; V53.5 now projects only Human-confirmed scope after confirmation
- confirmed `StudyScopeItem` is the V53.5 input
- full OCR transcript is not persisted
- uncertain dates keep source text and normalized date null

### V53.5 — Study Intelligence

Status: **IMPLEMENTED — PR PENDING**

Branch:

`feature/v53-5-study-intelligence`

Permanent spec:

`docs/V53.5_STUDY_INTELLIGENCE.md`

Implemented:

- `StudyScopeEvidenceMatcher`
- `StudyRemainingWorkEstimator`
- `StudyIntelligenceStateBuilder`
- `StudyExamReadinessEvaluator`
- `StudyPlanIntelligenceService`
- confirmed scope semantic dedupe
- conservative scope → Evidence matching
- Coverage from safely observed confirmed scope
- Mastery from linked Practice Evidence
- Retention from linked Recall Evidence
- Speed explicitly unmeasured rather than guessed
- normalized remaining Study Units / remaining load %
- deadline pressure and conflicting exam-date handling
- exam-relative Readiness
- snapshot refresh after Scope confirm / Practice / Recall Evidence
- ephemeral GET evaluation without snapshot spam
- Study diagnostic surface
- Study Activity / Practice / Recall expanded from certification-only to all Study-profile Plans

Important semantics:

- Task progress remains outside Study State truth
- AI Draft scope remains outside Study State truth
- subject-only Evidence is not spread across multiple same-subject units
- conflicting confirmed exam dates are surfaced instead of guessed
- remaining effort is Study Units, not fake minutes
- Speed remains null until authoritative active-solving timing exists
- V53.5 calculations are deterministic and do not require OpenAI
- no automatic Task generation or Task progress mutation

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

- V53.1 State / Evidence Foundation is merged
- PR #209 merged with commit d5f6ddf92b9a64b9e04d6d9d3cb5ef84a7aa4b36
- V53.2 Decision & Readiness Engine is in progress
- Study remains the first validation domain
- deterministic Readiness and Decision are implemented before adding OpenAI routing

V53.1 validation checkpoint:

- GitHub Actions run: #37159953066
- Intelligence PHP lint: success
- V53.0 contract regression: success
- V53.1 State / Evidence tests: success
- Execution Evidence regression: success
- Study Practice regression: success
- GitHub Evidence regression: success
- temporary validation workflow removed after success

Current V53.2 checkpoint:

- PR #210 merged
- merge commit: 0e0e848f056c4ecca44b8f03943fb74c54bff1a0
- normalized StateSnapshot is the primary input
- explainable Gap / Readiness implemented
- deterministic Decision candidates implemented
- durable Decision trace implemented
- Product Spec and permanent V53.2 spec synchronized
- validation complete
- temporary validation workflow removed after success

V53.2 validation checkpoint:

- GitHub Actions run: #37167463399
- V53.2 PHP lint: success
- V53.0 contract regression: success
- V53.1 State / Evidence regression: success
- V53.2 Decision / Readiness tests: success
- Execution Evidence regression: success
- Study Practice regression: success
- GitHub Evidence regression: success

Current V53.3 checkpoint:

- PR #211 merged
- merge commit: c6563d04903960f380d60fcb80987472f5b8c15d
- deterministic V53.2 policy remains baseline/fallback
- provider-neutral reasoning interfaces implemented
- existing NativeAiGateway reused; no second OpenAI client
- existing AutomaticAiExecution entitlement boundary reused
- OpenAI may only select from Canovia-approved candidates
- reasoning observability and successful-request reuse implemented
- Product Spec and permanent V53.3 spec synchronized
- validation complete
- temporary validation workflow removed after success

V53.3 validation checkpoint:

- GitHub Actions run: #37169302773
- V53.3 PHP lint: success
- V53.0 contract regression: success
- V53.1 State / Evidence regression: success
- V53.2 Decision / Readiness regression: success
- V53.3 Reasoning Router tests: success
- Native AI regression: success
- Feature Access regression: success
- Study Practice regression: success
- GitHub Evidence regression: success

Current V53.4 checkpoint:

- PR #212 merged
- merge commit: 9b8e05fe5fe3bf10903f69f691ae6d564ee76820
- source files reuse existing private Inbox storage
- Native AI output is a reviewable Draft only
- Human Confirmation creates confirmed StudyScopeItem facts
- provider failure preserves source and allows manual confirmation
- ordinary school test categories are accepted through Study profile
- Product Spec and permanent V53.4 spec synchronized
- validation complete
- temporary validation workflow removed after success
- validation run: #37170118250

Current V53.5 checkpoint:

- confirmed StudyScopeItem is the scope source of truth
- Practice / Recall TaskEvidence is normalized through the existing Evidence boundary
- conservative scope-to-Evidence matching implemented
- Coverage / Mastery / Retention implemented
- Speed intentionally remains unmeasured
- remaining work is expressed as normalized Study Units, not minutes
- confirmed exam-date conflict is explicit
- exam-relative Readiness implemented
- State snapshot refresh hooks added to Scope confirm / Practice / Recall
- school-test Study profiles can use Study Activity / Practice / Recall
- diagnostic Study Intelligence surface added
- Product Spec and permanent V53.5 spec synchronized
- validation complete
- validation run: #37171521259
- temporary validation workflow removed after success
- PR: pending creation

Next after V53.5 merge:

- V53.6 Adaptive Action
- rank the biggest current Gap / remaining scope
- produce a small current Action set
- explain why the Action is best now
- keep Task as optional / durable projection
- retire or change Actions when new Evidence changes State

## Completion / deletion rule

This file is intentionally temporary.

When the entire V53 series has been implemented and the durable architecture is reflected in permanent docs:

1. verify permanent docs contain the final architecture
2. remove stale handoff-only notes
3. **delete `docs/V53_IMPLEMENTATION_HANDOFF.md`**
4. include its deletion in the final V53 completion PR

If implementation changes direction before completion, update this file immediately so a new chat does not continue from obsolete assumptions.
