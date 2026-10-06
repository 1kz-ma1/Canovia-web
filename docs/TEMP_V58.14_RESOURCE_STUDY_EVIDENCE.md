# TEMP V58.14 Resource Study Completion Evidence

Status: implementation in progress  
Branch: `feature/v58-14-resource-study-evidence`

## Goal

Turn Resource Study from a recommendation-only Activity into an executable,
measurable Study Activity without treating link-open events as learning.

```text
Task recommends Resource Study
→ dedicated Resource Study surface
→ learner opens registered material
→ learner explicitly records completion outcome
→ study_resource_study_completed Evidence
→ V58.12 Activity outcome observation
```

## Core boundary

Opening a Resource URL is NOT completion Evidence.

Canovia records Resource Study only after an explicit POST from the learner.

V58.14 does not infer:

- whether the page was actually read;
- percentage read;
- comprehension;
- mastery;
- Task progress.

## Resource selection

The dedicated surface prefers:

1. Resources explicitly linked to the current Task;
2. otherwise Plan-level Resources.

The learner may record which registered Resource was used.

If a `resource_id` is submitted, the server rechecks that the Resource belongs
to the same Plan and is available in the current Task Resource context.

Resource URLs are opened in the browser only.

Canovia does not server-fetch or inspect them.

## Evidence

New Task Evidence type:

`study_resource_study_completed`

Metadata:

- resource_id nullable
- resource_title nullable
- outcome_rating
- reflection nullable

Allowed outcome values:

- needs_review
- partial
- covered

Confidence:

`0.65`

This confirms the learner explicitly reported doing Resource Study.
It does not prove understanding.

Idempotency key:

`study-resource:{request_uuid}`

## Intelligence normalization

Allowed normalized facts:

- resource_id
- outcome_rating

Do not expose:

- resource URL
- resource title
- free-text reflection

through the normalized Intelligence boundary.

## V58.12 Activity outcome observation

Add `study_resource_study_completed` as a tracked Activity Evidence.

Between adjacent valid Practice assessments:

- only Resource Study Evidence → attribute one Resource Study before/after observation;
- repeated Resource Study Evidence → one score observation with multiple usage events;
- mixed Resource Study + another Activity → ambiguous and exclude.

Resource Study is no longer permanently `unmeasured`.

Without explicit Resource Study Evidence it remains `waiting`.

## Recommendation / progress boundary

V58.14 does not alter:

- StudyActivityPolicy scoring;
- StudyMethodRecommendation scoring;
- Task progress;
- Task completion;
- Practice Reliability;
- Recall mastery.

It changes the Resource Study URL from the generic Resource library to the
dedicated execution surface.

## Execution ecosystem

Existing:

- capability `study.resource`
- provider `canovia.study.resource`

remain authoritative.

No new capability/provider is added.

## Free path

Resource Study execution and Evidence recording are non-AI native behavior.

No new entitlement is required.

## No migration

Existing TaskEvidence and PlanResource tables are sufficient.

## Validation

Required:

- Resource Study recommendation routes to dedicated surface
- Task-linked Resources are preferred over Plan-level Resources
- Plan-level Resources are used when Task has none
- cross-Plan Resource submission is rejected
- unavailable other-Task Resource submission is rejected
- opening/showing Resource URL does not create Evidence
- explicit completion creates one idempotent Evidence row
- Evidence does not mutate Task progress/status
- normalized Intelligence facts exclude URL/title/reflection
- TaskEvidence label/summary are readable
- V58.12 maps Resource Study between Practice assessments
- repeated Resource Study events remain one observation interval
- mixed Resource Study + Recall interval is excluded
- Resource Study UI is provider-free
- V56.15 Study Method Recommendation regression remains green
- V58.12 Activity outcome observation regression remains green
- V55.6 Execution Ecosystem regression remains green

## Completion

After validation:

1. promote to `docs/V58.14_RESOURCE_STUDY_EVIDENCE.md`
2. update Product Spec / V41.10 / V58.12
3. retire TEMP to pointer
4. remove temporary validation workflow
5. create PR to `main`
6. stop before merge
