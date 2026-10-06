# TEMP V58.8 Recall Batch Ingest

Status: implementation in progress  
Branch: `feature/v58-8-recall-batch-ingest`

## Goal

Allow a learner to select multiple Recall material pages/images in one action
and extract Recall Candidates with one Native AI run.

```text
2-5 images / PDFs
→ one user submission
→ one StudyRecallSource per file
→ one Native AI structured extraction
→ Candidate.source_index
→ Candidate assigned to its evidence Source
→ Human Review
```

This reduces repeated upload / provider execution friction without changing the
existing Source / Candidate / Deck authority boundaries.

## Data model

No batch table and no migration.

Existing invariant remains:

`1 StudyRecallSource = 1 saved material file`

A batch is an operation over multiple Source rows, not a new persistent entity.

Reasons:

- V41.12 private file authorization remains valid per Source
- V58.7 retry remains available per failed Source
- source deletion / account deletion remain simple
- Candidate continues to reference one primary evidence Source

## Intake limits

Batch upload accepts:

- 2-5 files
- PDF / JPEG / PNG / WEBP
- max 10 MB per file
- max 20 MB total request material

Single-file and pasted-text intake remain unchanged.

## One-provider-run policy

V58.8 must not call Native AI once per file.

All saved Sources are converted to ordered input parts and sent through one
`NativeAiGateway::generateStructured()` call.

Before each file part, a small input-text marker identifies:

- zero-based source_index
- original filename
- source type

The final prompt contains the same manifest and requires every Candidate to
return its primary `source_index`.

## Candidate contract

Batch candidate adds required:

- source_index: integer in the submitted Source order

Existing fields remain:

- prompt
- answer
- note
- tags
- source_excerpt
- confidence

The candidate is persisted with the `StudyRecallSource` referenced by
`source_index`.

Existing uniqueness remains authoritative:

`task_id + fingerprint(prompt|answer)`

Cross-page duplicate concepts therefore create only one Candidate.

## Source result projection

On success all batch Sources become `ready` and share the same
`native_ai_run_id`.

Each Source's `candidate_count` equals newly created Candidates attributed to
that Source.

On Native AI failure all batch Sources become `failed` and retain the same
failed run ID.

V58.7 can then retry each saved Source independently.

## Human Review

No AI output enters the Recall Deck directly.

Batch results land as normal pending `StudyRecallCandidate` rows and use the
existing Human Review / promote / reject flow.

## UI

The existing single material form remains.

Automatic AI users additionally get a compact "複数ページをまとめて取り込む"
form:

- multiple file picker
- 2-5 files notice
- total 20 MB notice
- explicit batch submit

No batch UI is shown when Automatic AI extraction is unavailable.

## Security / privacy

- files are stored privately using the existing Recall Source path
- no public file URL is created
- no remote URL fetching
- no Plan Resource URL fetching
- all Sources belong to the current Plan / Task
- base64 bytes are not persisted in NativeAiRun metadata/request hash

Input-part request fingerprints include hashes of both file payloads and
source-marker text, never raw Base64 bytes.

## Failure cleanup

Before provider execution, if saving any file/Source fails:

- already saved batch files are deleted
- already created batch Source rows are deleted
- provider is not called

Once provider execution begins, Sources are retained on failure so V58.7 retry
can recover them.

## Boundaries

V58.8 does not:

- change single-file intake
- change text intake
- change Recall scheduling
- change V58.6 Task progression
- change V58.7 retry
- auto-promote Candidates
- add background jobs
- fetch arbitrary URLs
- add a migration
- change entitlement/billing

## Validation

Required:

- two images produce two Sources, one Native AI run and mapped Candidates
- mixed image/PDF input preserves source_index ordering
- per-Source candidate_count is correct
- duplicate Candidate content across Sources is not duplicated
- provider failure marks every Source failed with one run ID
- fewer than 2 or more than 5 files is rejected
- total material above 20 MB is rejected before storage/provider call
- free user is forbidden
- batch UI appears only for Automatic AI users
- V41.12 Candidate regression remains green
- V58.7 retry regression remains green
- V58.6 progression regression remains green

## Completion

After validation:

1. promote to `docs/V58.8_RECALL_BATCH_INGEST.md`
2. update Product Spec / V41.12 follow-up
3. retire TEMP to pointer
4. remove temporary validation workflow
5. create PR to `main`
6. stop before merge
