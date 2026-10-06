# TEMP V58.11 Safe Plan Resource → Recall Handoff

Status: implementation in progress  
Branch: `feature/v58-11-safe-resource-recall-handoff`

## Goal

Connect existing Plan Resources to Recall Candidate extraction without
server-fetching arbitrary Resource URLs.

```text
PlanResource reference
→ learner explicitly selects "Recallで使う"
→ learner provides trusted material bytes/text
→ StudyRecallSource(plan_resource_id)
→ existing Candidate extraction
→ Human Review
→ Recall Deck
```

The PlanResource URL remains a reference only.

## Why this design

Current PlanResource stores shared URLs for:

- Google Drive
- OneDrive / SharePoint
- GitHub

Those URLs may point to:

- HTML sharing pages instead of file bytes
- authenticated content
- private organization content
- redirects
- arbitrary hosts after future provider expansion

V58.11 must not perform generic server-side URL fetching.

That avoids:

- SSRF
- auth-token ambiguity
- accidental private-page capture
- provider-specific scraping behavior
- inconsistent file/content interpretation

## Provenance

Add nullable foreign key:

`study_recall_sources.plan_resource_id`

It references `plan_resources.id` and uses `nullOnDelete()`.

A StudyRecallSource may therefore be:

- direct Recall material with no PlanResource provenance
- explicitly derived from one PlanResource reference

Deleting the Resource does not delete Recall history; provenance becomes null.

## Eligible Resource

The Resource must:

1. belong to the same Plan;
2. be visible/editable through the current Plan ownership boundary;
3. be a file Resource;
4. be linked to the Task OR be an unscoped Plan-level Resource.

A Resource explicitly linked only to other Tasks is not offered for this Task.

The server rechecks Plan / Task / Resource ownership on POST.

## Trusted material input

The learner explicitly supplies one of:

### File

- PDF / JPEG / PNG / WEBP
- max 10 MB
- stored using existing private `study-recall-sources/{plan}/{task}` path

### Text

- non-empty pasted material text
- max 50,000 characters

The Resource URL itself is never used as extraction input.

## Extraction

V58.11 reuses:

`StudyRecallCandidateExtractionService`

No separate AI prompt or Candidate contract is introduced.

The created Source:

- keeps `plan_resource_id`
- uses existing source_type / storage_path / source_text
- follows existing pending → ready / failed state
- uses existing Candidate duplicate fingerprint
- uses existing Human Review
- uses V58.7 retry if extraction fails

## Entitlement

The handoff UI may be visible as contextual guidance, but automatic extraction
requires the same `AutomaticAiExecution` capability as existing Recall
material extraction.

Free/manual Recall remains available.

## Recall UI

On the Recall page, when eligible Resources exist, show a collapsed section:

`登録済みResourceからRecall教材を作る`

For each eligible Resource show:

- title
- provider
- whether it is Task-linked or Plan-level
- original external URL as an explicit "参照元を開く" link
- a small trusted-material form

The form accepts:

- one local file OR
- pasted text

It explicitly explains:

"CanoviaはResource URLを自動取得しません。Recallに使う内容をファイルまたは本文として確認して渡してください。"

## Source UI

Recent Recall Source rows show Resource provenance when available:

`Resource: <title>`

The existing private-file "元教材を確認" behavior remains unchanged.

## Failure behavior

If file storage succeeds but Source creation/extraction setup fails before
provider execution, delete the newly stored file.

If provider execution fails:

- keep the Source and private material
- keep plan_resource_id
- set Source failed through existing extractor
- allow V58.7 explicit retry

## Security / privacy

- no remote URL fetching
- no connector token use
- no Drive / OneDrive / GitHub API call
- no public copy of material
- file bytes remain on existing private Recall storage path
- Resource URL is never sent to Native AI as material
- only explicit user-provided bytes/text are sent to extraction

## Migration

One additive migration:

- nullable `plan_resource_id` on `study_recall_sources`
- FK → `plan_resources.id`
- nullOnDelete
- index

No existing row backfill is required.

## Boundaries

V58.11 does not:

- enable general PlanResource device upload
- change PlanResource URL-reference contract
- fetch remote Resource content
- add provider-specific Drive/OneDrive/GitHub ingestion
- auto-promote Recall Candidates
- change scheduler
- change V58.6 Task progression
- change V58.8 batch ingest
- change V58.9 outcome tracing
- change billing

## Validation

Required:

- same-Plan Task-linked Resource can create Recall Source from uploaded PDF
- same-Plan unscoped Resource can create Recall Source from pasted text
- Resource linked only to another Task is not eligible
- Resource from another Plan is rejected
- Resource URL is never requested
- Source stores plan_resource_id
- Candidate extraction uses explicit material, not URL
- provider failure preserves Resource provenance for V58.7 retry
- deleting PlanResource nulls Source provenance without deleting Source
- Source UI shows Resource provenance
- free user cannot trigger automatic extraction
- existing direct Recall intake still works
- V58.7 retry regression remains green
- V58.8 batch ingest regression remains green
- V58.9 outcome trace regression remains green
- account deletion / migration smoke remain green

## Completion

After validation:

1. promote to `docs/V58.11_SAFE_RESOURCE_RECALL_HANDOFF.md`
2. update Product Spec / V41.12 follow-up
3. retire TEMP to pointer
4. remove temporary validation workflow
5. create PR to `main`
6. stop before merge
