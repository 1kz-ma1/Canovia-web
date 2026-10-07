# Product Intelligence / Automatic Improvement — Future Design

> Status: **Concept**  
> Implementation status: **not implemented by this document**  
> Current telemetry is a foundation, not Product Intelligence  
> Overview: `docs/future/CANOVIA_FUTURE_ARCHITECTURE_OVERVIEW.md`

---

## 1. Purpose

Product Intelligence is the future system that helps Canovia answer:

- what is happening?
- what evidence supports it?
- why might it be happening?
- what should be tested next?
- what was decided and why?
- did the change work?
- what did we learn?

It is not equivalent to analytics dashboards or AI-generated suggestions.

---

## 2. Current foundations

Current implemented foundations include:

- Early Access telemetry
- user behavior events
- Goal / Plan / Task / Evidence
- Development GitHub Evidence
- Living Profile source provenance
- Release/version context

Current sources:

- `docs/V58.23_EARLY_ACCESS_OBSERVABILITY.md`
- `docs/CANOVIA_PRODUCT_SPEC.md`
- current Study / Development evidence specs
- `docs/V58.27_LIVING_PROFILE_FOUNDATION.md`

These do not mean Evidence Pipeline, Hypothesis Intelligence, Opportunity Engine, or Automatic Improvement are implemented.

---

## 3. Automatic Improvement thesis

Future Automatic Improvement is not:

> AI gives an improvement idea.

Target loop:

```text
Reality
↓
Evidence
↓
Hypothesis
↓
Decision
↓
Implementation
↓
Measurement
↓
Learning
↓
Revalidation
```

Its value comes from preserving the chain rather than optimizing only one step.

---

## 4. Canovia-specific advantage

Potential advantage comes from longitudinal Context:

```text
Goal
Plan
Task
Execution
Result
Behavior
Context
Replanning
```

Product changes can be evaluated against real user execution and outcomes rather than only clicks.

This makes Automatic Improvement a plausible FIRST_PARTY_RESERVED candidate, but that classification is still Future strategy.

---

## 5. Hypothesis Intelligence

Target:

```text
Idea / Problem
↓
Hypothesis
↓
Expected Result
↓
Decision
↓
Implementation
↓
Observed Result
↓
Evaluation
↓
Learning
↓
Future Decision
```

Store the reason before observing the result where practical.

---

## 6. Pre-commit hypothesis

Before implementation, future Product Intelligence may preserve:

- hypothesis
- success criterion
- expected direction
- evaluation window
- target segment
- known confounders

Purpose:

> reduce hindsight bias.

After results arrive, do not rewrite the original expectation.

---

## 7. Example

```text
Hypothesis
Personalization Bootstrap reduces zero-to-Plan friction.

Expected
Plan creation +15%
First execution +10%

Actual
Plan creation 41% → 57%
First execution 29% → 39%
Onboarding drop-off +2%

Evaluation
PARTIALLY_SUPPORTED

Learning
Development users may experience question-count friction.

Next
reduce required Development questions.
```

Example values are illustrative, not current measured Canovia results.

---

## 8. Evaluation vocabulary

Avoid binary success/failure when evidence is uncertain.

Candidate evaluations:

- SUPPORTED
- PARTIALLY_SUPPORTED
- INCONCLUSIVE
- NOT_SUPPORTED
- CONTRADICTED
- INSUFFICIENT_EVIDENCE

Confidence remains separate.

---

## 9. Avoid causal overclaim

If multiple changes occur simultaneously:

```text
Personalization change
+
traffic mix change
+
onboarding copy change
```

do not state direct causality without appropriate evidence.

Prefer:

> evidence is consistent with a contribution, confidence medium.

---

## 10. Evidence Pipeline

Future pipeline:

```text
Raw Events
↓
Validation
↓
Normalization
↓
Context Enrichment
↓
Metric Computation
↓
Evidence Assembly
↓
Hypothesis Evaluation
↓
Pattern Extraction
↓
Decision Support
```

AI reasoning must sit after evidence quality controls, not before them.

---

## 11. Pipeline failure risk

```text
Wrong Data
↓
Wrong Evidence
↓
Convincing AI Inference
↓
False Success Pattern
↓
Automated Wrong Decision
```

A stronger model does not repair invalid evidence.

Principle:

> Evidence Pipeline correctness is at least as important as model intelligence.

---

## 12. Fact / Context / Evidence / Inference / Conclusion

Do not collapse these layers.

Example:

### FACT

```text
Plan Creation
41% → 57%
```

### CONTEXT

Another onboarding change happened in the same period.

### EVIDENCE

Plan creation rose after the release.

### INFERENCE

The release may have contributed.

### CONCLUSION

Supported tendency.

### CONFIDENCE

Medium.

Metric → Success Pattern must not be a direct jump.

---

## 13. Data Lineage

Future goal:

```text
Pattern
↓
Hypothesis Evaluation
↓
Evidence
↓
Metric Snapshot
↓
Query / Computation
↓
Normalized Events
↓
Raw Events
```

A human should be able to ask:

> why did Canovia reach this conclusion?

and inspect the lineage.

---

## 14. Evidence Health

Future Evidence Health may evaluate:

- event coverage
- missing events
- duplicate rate
- schema mismatch
- sample size
- segment bias
- tracking coverage
- pipeline failures
- late events
- metric-definition change

If Evidence Health is poor, "unknown" is valid.

---

## 15. Unknown is a correct answer

Automatic Improvement must be allowed to return:

- Insufficient Evidence
- Inconclusive
- Low Confidence

It must not manufacture a success/failure conclusion because the workflow expects one.

---

## 16. Human View vs Machine View

Machine-facing evidence may preserve:

- raw/normalized data
- metric definition
- segment
- confidence
- confounders
- lineage
- schema
- experiment
- release
- deployment
- PR
- feature flag

Human-facing view may compress to:

- hypothesis
- result
- key metrics
- confidence
- learning
- cautions

Simplifying presentation must not simplify away decision evidence.

---

## 17. Decision Memory / Product Memory

Canovia should eventually remember not only:

> what was built

but:

- why it was built
- what was expected
- what occurred
- what was learned

Target:

```text
Feature History
+
Decision History
=
Product Memory
```

---

## 18. Success Pattern

Future Product Intelligence may extract Canovia-specific patterns.

Example:

> experiences that ask for more initial information can still improve retention when they immediately return obvious personalized value.

This is a candidate learning, not a permanent law.

---

## 19. Higher-order Pattern

Do not stop at copying local success.

Example hierarchy:

```text
Observation
Personalized onboarding improved an outcome
↓
Local Pattern
Personalized onboarding helps
↓
Underlying Principle
early "this is for me" value matters
↓
Higher-order Pattern
reuse known Context and ask only missing information
to preserve personalization while reducing friction
```

This allows abandoning a successful old UI when a better mechanism satisfies the deeper principle.

---

## 20. Pattern freshness

Patterns are revisable.

```text
Pattern discovered
↓
evidence accumulates
↓
confidence increases
↓
environment changes
↓
revalidation
↓
Confirmed / Weakened / Retired
```

No Success Pattern becomes permanent truth.

---

## 21. Opportunity Engine

Future admin system may identify:

> what is worth building next?

Potential inputs:

- user friction
- feature requests
- support / votes
- retention
- revenue
- category usage
- manual work
- execution gaps
- missing provider
- provider quality
- Canovia synergy
- first-party overlap
- development cost
- maintenance cost

Opportunity detection is not automatic approval.

---

## 22. Opportunity evaluation

Candidate dimensions:

- User Pain
- Canovia Synergy
- Data Advantage
- Standalone Demand
- Provider Availability
- Revenue Potential
- Build Cost
- Maintenance Cost
- Strategic Moat

Target flow:

```text
Observe
↓
Opportunity Candidate
↓
Value Hypothesis
↓
Comparison
↓
Recommendation
↓
Human Approval
```

---

## 23. Opportunity Admin Board

Possible future actions:

- IGNORE
- WATCH
- FIND_PARTNER
- PROTOTYPE_IN_CANOVIA
- FIRST_PARTY_CANDIDATE

A candidate should show:

- reason it exists
- user pain
- evidence
- provider alternative
- first-party advantage
- prototype path
- standalone signal
- revenue hypothesis
- MVP concept

---

## 24. Automatic Improvement decision boundary

Problem Detection must not directly invoke production implementation.

Preferred:

```text
Observation
↓
Evidence
↓
Hypothesis
↓
Decision Package
↓
Human / Policy decision
↓
Specification
↓
Implementation
↓
Measurement
```

High-impact product decisions remain human-owned.

---

## 25. Builder / Reviewer / Governor

Future Product Intelligence may align with:

### Builder

prepares implementation.

### Reviewer

independently evaluates result / evidence.

### Governor

applies policy:

- Auto
- Human Review
- Reject

Principle:

```text
AI Recommendation
!=
Final Decision
```

---

## 26. Product Constitution

Automatic improvement should not optimize a single KPI blindly.

Future decision context may include product-owner-defined constraints:

- User Value
- Business Goals
- Product Principles
- UX constraints
- Security
- Privacy
- Trust
- long-term architecture

Examples:

- do not optimize revenue at the expense of user trust
- do not maximize DAU as an isolated objective
- do not increase unnecessary user decision load
- safety boundaries outrank growth metrics

---

## 27. Hypothesis beyond product development

The same conceptual engine may support different domains.

Canovia Pro example:

```text
Goal Hypothesis
"morning study improves consistency"
```

Developer Pro examples:

- Product Hypothesis
- UX Hypothesis
- Growth Hypothesis
- Pricing Hypothesis
- Feature Hypothesis

The shared abstraction is:

```text
Hypothesis
→ Evidence
→ Outcome
→ Learning
```

This remains Future.

---

## 28. Metrics over vision-only claims

Strategic beliefs should become measurable hypotheses when possible.

Examples:

```text
Study specialized UI is a strong entry
→ Plan creation / retention

Personalization reduces friction
→ onboarding completion / first execution

GitHub Integration adds Development value
→ connection / retention / automation usage

Automatic Improvement adds value
→ proposal adoption / measured impact

Users will pay
→ paid conversion / retention
```

---

## 29. Current Architecture Gaps

Missing before Product Intelligence can be promoted:

- canonical metric registry
- evidence validation pipeline
- stable release/feature lineage
- hypothesis store
- evaluation-window contract
- confidence model
- confounder representation
- decision memory
- evidence health
- pattern store
- pattern freshness/revalidation
- opportunity model
- human approval workflow for opportunities

These are expected gaps, not bugs.

---

## 30. Recommended promotion sequence

1. keep current telemetry reliable
2. define a small metric/feature/release relation
3. add one manual hypothesis record flow
4. preserve expected result before release
5. add deterministic post-release evaluation
6. add Evidence Health
7. add Decision Memory
8. only then introduce AI summarization
9. pattern extraction after enough validated decisions exist
10. Opportunity Engine after Product Memory becomes useful
11. Automatic Improvement only after the earlier layers are trusted

---

## 31. Non-goals

This document does not implement:

- Hypothesis Engine
- Evidence Pipeline
- Data Lineage
- Evidence Health
- Pattern Engine
- Opportunity Engine
- Automatic Improvement Engine
- autonomous product decisions
- automatic production changes
