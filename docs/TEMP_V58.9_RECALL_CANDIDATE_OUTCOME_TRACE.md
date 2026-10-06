# TEMP V58.9 Recall Candidate Outcome Trace

Status: implementation in progress  
Branch: `feature/v58-9-recall-candidate-outcome-trace`

## Goal

Connect AI-generated Recall Candidate provenance to actual Recall review
outcomes without pretending that learner difficulty is the same thing as
Candidate quality.

```text
StudyRecallCandidate
→ Human Review / promote
→ StudyRecallItem
→ actual Again / Hard / Good / Easy reviews
→ traceable Evidence + outcome projection
```

V58.9 creates an observation layer, not an automatic quality re-scoring system.

## Why this boundary matters

A learner answering `Again` does not prove the generated card was bad.

Possible causes include:

- the concept itself is difficult;
- the learner has not studied it enough;
- the card wording is weak;
- source material is ambiguous;
- review timing is challenging.

Therefore V58.9 must not:

- lower Candidate confidence from poor Recall;
- auto-reject cards;
- rewrite prompts/answers;
- change extraction ranking;
- change scheduler intervals.

It only preserves the provenance/outcome relationship for later analysis.

## Review Evidence provenance

When a reviewed Recall Item came from one or more promoted Candidates, the
existing `study_recall_reviewed` Evidence additionally records ordered,
bounded lineage arrays:

- `study_recall_candidate_ids`
- `study_recall_source_ids`
- `candidate_confidences`

The arrays use the same Candidate ordering. This covers the valid case where
multiple reviewed Candidates were edited/promoted into the same existing Recall
Item.

Manual Recall cards keep these arrays empty.

The existing Evidence remains one row per idempotent Recall review.

## Intelligence normalization

`TaskEvidenceAdapter` exposes only bounded known fields:

- Candidate ID list
- Source ID list
- Candidate confidence list

It does not expose:

- Candidate prompt
- answer
- source excerpt
- raw AI payload

This preserves the current normalized-facts boundary.

## Outcome projection

Add a provider-free `StudyRecallCandidateOutcomeService`.

For promoted Candidates in the current Plan / Task it derives:

- candidate confidence
- review count
- Again / Hard / Good / Easy counts
- latest rating
- current lapse count
- current mastery state
- self-rated recall success percent
- outcome state

Outcome states:

```text
unobserved
developing
needs_reinforcement
retained
```

Rules:

- no reviews -> `unobserved`
- current item is mastered -> `retained`
- at least 2 reviews AND Again is at least half of reviews -> `needs_reinforcement`
- otherwise -> `developing`

These are learner/card outcomes, not Candidate quality labels.

## Aggregate projection

Task-level aggregate separates Candidate lineage count from unique Recall Item
outcomes so shared Items never double-count reviews:

- promoted Candidate count
- promoted unique Item count
- observed Candidate count
- observed unique Item count
- retained unique Item count
- reinforcement-needed unique Item count
- total unique review count
- self-rated successful recall count
- self-rated recall success percent
- average original Candidate confidence

Confidence bands are descriptive only:

- high: 85-100
- medium: 60-84
- low: 0-59

For each band expose:

- promoted count
- observed count
- retained count
- review count
- Again count

No automatic calibration or causal claim is produced.

## UI

On Recall page, when at least one AI Candidate has been promoted, expose a
compact native `<details>` section:

`AI候補の実Recall結果`

It shows:

- promoted Candidate / unique Item
- observed unique Item
- retained unique Item
- review count
- self-rated recall success
- original AI confidence average
- recent promoted Candidate outcome rows

The UI must explicitly say:

- this is an observation;
- difficult Recall does not automatically mean a bad Candidate;
- no automatic Candidate re-scoring occurs.

Manual-only Recall Decks do not render this section.

## Existing / legacy data

No migration or backfill is required.

Existing promoted Candidates already have `promoted_item_id`, so projection
works for historical reviews through DB relations.

Only newly recorded `study_recall_reviewed` Evidence gains direct Candidate
provenance fields.

## Boundaries

V58.9 does not:

- change Candidate extraction
- change batch ingest
- change Candidate Human Review
- change Recall scheduler
- change V58.6 Task progression
- call AI/provider
- mutate Candidate confidence
- create quality scores
- add a migration
- change entitlement/billing

## Validation

Required:

- promoted Candidate review Evidence contains ordered Candidate/Source/confidence lineage arrays
- multiple Candidates sharing one Item do not lose provenance or double-count aggregate reviews
- manual card review Evidence uses empty provenance arrays
- TaskEvidenceAdapter normalizes the three new known lineage facts
- outcome projection handles unobserved / developing / reinforcement / retained
- aggregate metrics are deterministic
- confidence bands are descriptive and deterministic
- Recall UI appears only when promoted Candidate lineage exists
- no AI/provider traffic from outcome projection
- V41.11 Recall regression remains green
- V41.12 Candidate regression remains green
- V58.6 progression regression remains green
- V58.7 retry regression remains green
- V58.8 batch ingest regression remains green

## Completion

After validation:

1. promote to `docs/V58.9_RECALL_CANDIDATE_OUTCOME_TRACE.md`
2. update Product Spec / V41.12 follow-up
3. retire TEMP to pointer
4. remove temporary validation workflow
5. create PR to `main`
6. stop before merge
