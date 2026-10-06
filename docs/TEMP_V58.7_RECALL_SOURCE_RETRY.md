# TEMP V58.7 Recall Source Retry

Status: implementation in progress  
Branch: `feature/v58-7-recall-source-retry`

## Goal

Allow a failed Recall material extraction to be retried from the already saved
private `StudyRecallSource` without asking the learner to upload or paste the
same material again.

```text
saved StudyRecallSource
→ extraction failed
→ source remains private + status=failed
→ explicit user Retry
→ same source is re-evaluated
→ pending Recall Candidates
→ Human Review
```

## Scope

V58.7 only covers retrying an existing failed `StudyRecallSource`.

Supported saved source types remain unchanged:

- text
- image
- pdf

The existing `StudyRecallCandidateExtractionService` remains the only
candidate extraction path.

## Retry boundary

Retry is allowed only when:

1. Plan is a Study Plan;
2. Task belongs to the Plan;
3. actor can edit the Task;
4. `AutomaticAiExecution` entitlement is available;
5. Source belongs to the same Plan and Task;
6. Source status is `failed`;
7. the saved source material still exists:
   - text: non-empty `source_text`
   - image/pdf: private `storage_path` exists

The server rechecks these conditions on POST.

## Mutation

Retry does not create a second Source.

It reuses the same `study_recall_sources.id` and calls the existing extraction
service.

On success:

- Source -> `ready`
- latest Native AI run ID is stored
- `candidate_count` reflects newly created candidates
- Candidate review flow remains unchanged

On provider/extraction failure:

- Source remains `failed`
- latest failed Native AI run ID is retained by the existing extractor
- user returns to Recall with a retryable status message

## Duplicate behavior

Existing Candidate uniqueness remains authoritative:

`task_id + fingerprint(prompt|answer)`

Retry must not create duplicate pending/promoted cards for candidate content
that already exists for the Task.

No retry-specific candidate table or reset path is added.

## UX

In Recall "最近取り込んだ教材":

- failed Source shows a clear failed state
- if material is still available and actor can edit + use Automatic AI:
  `再抽出`
- ready/pending Source does not show retry
- private source file remains accessible only through the existing authorized
  file route

The normal upload/text intake remains unchanged.

## Boundaries

V58.7 does not:

- auto-retry in background
- add remote URL fetching
- fetch Plan Resource URLs
- change Recall source storage
- change candidate Human Review
- change Recall scheduling / Task progression
- add migrations
- change billing

## Validation

Required:

- failed text Source can retry successfully
- failed PDF/image Source reuses existing private bytes
- successful retry reuses same Source row
- duplicate Candidate content is not duplicated
- non-failed Source retry is blocked
- foreign Plan/Task Source retry is blocked
- missing private file retry is blocked without provider call
- free user retry is forbidden
- retry UI appears only for eligible failed Source
- V41.12 Recall Candidate regressions remain green

## Completion

After validation:

1. promote to `docs/V58.7_RECALL_SOURCE_RETRY.md`
2. update Product Spec / V41.12 follow-up
3. retire TEMP to pointer
4. remove temporary validation workflow
5. create PR to `main`
6. stop before merge
