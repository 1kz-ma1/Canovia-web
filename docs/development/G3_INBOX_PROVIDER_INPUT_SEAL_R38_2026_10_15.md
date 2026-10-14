# G3-R38 — Inbox AI Exact Provider-Input Byte Seal

**2026-10-15 | Platform | G3 OPEN | no provider or production changes**

## Audited gap

R37 closed unjournaled private-file writes and unnecessary remote cleanup
reads. Further audit of Study Scope, Recall and Inbox native-AI paths shows:

- Study Scope: \`StudyScopeCaptureCommitFence::assertInputMatches\` already
  binds the *actual* attachment bytes hash to the original authorization
  / source signature before an AI request. Post-provider, a fresh live
  source signature is checked inside the draft's DB transaction.
- Single/batch Recall: \`StudyRecallExtractionCommitFence::assertInputMatches\`
  provides equivalent source-ID keyed attachment preflight; commit
  checks live owner/source metadata. These checks are not S3 generation proof.
- **Inbox AI suggestion**: \`suggestAndPersist()\` signed file bytes A,
  then \`suggest()->inputParts()\` read bytes again to construct the AI
  payload, but *did not compare the second read to the original signature
  until after provider completion*. An A->B->A sequence could therefore
  create a candidate from B and still pass final signature A. If the
  attachment disappeared between reads, the prior implementation silently
  called the AI provider without the attachment.

## R38 implementation

1. \`InboxIntelligenceService::inputParts()\` records the SHA-256 of the
   exact byte string base64-encoded into the request. A missing/unreadable
   file is a \`native_ai_source_changed\` denial rather than a text-only
   AI submission. Text and URL items continue without file reads.
2. \`InboxAiSuggestionCommitFence::signature()\` accepts an *internal*
   optional input-bytes SHA-256 for the re-evaluation. A new
   \`assertInputMatches()\` recomputes the source+actor+original
   Plan membership epoch signature using that exact input hash, and
   compares it against the signature captured before reading the
   provider attachment. Missing/extra/invalid input hashes fail closed.
3. \`suggestAndPersist()\` supplies its original signature to
   \`suggest()\`, which invokes the new check before
   \`NativeAiGateway::generateStructured()\`. The existing
   post-provider DB authorization/source check remains unchanged.
   Public call sites using \`suggest()\` without the original signature
   do not themselves persist an AI suggestion; only the durable
   \`suggestAndPersist()\` flow makes this security claim.

## Disposable evidence and limits

The Inbox G3 feature suite now tests:
- unchanged image bytes => normal AI request and candidate;
- first read A / second read B => provider called **zero** times and
  no new \`native_ai_runs\` or suggestion;
- attachment disappears between signature and input => provider called
  zero times, no fallback to an unbound text-only request;
- existing post-provider changes still deny stale candidate;
- unchanged text routing remains usable.

Run on the Inbox G3 workflow (SQLite feature suite) and disposable MySQL
8.0/8.4 regression matrix, at an exact PR HEAD. No new database migration,
SDK, authentication, secret, cloud access, Aiven, Render, AWS API, external
billing or production operation.

**Important:** SHA-256 is a *content* fingerprint, not an immutable S3
version/generation identity. A->B->A entirely between the two reads,
a provider mutation during processing, or arbitrary external writers
can still evade content-only continuity. A successful CI simulation
is **not** independent provider attestation, not a real AWS trial, and
not authorization for conditional object deletion. R27–R31 approval
gates remain untouched.

**G3 OPEN. provider_attested=false; delete_authorized=false;
production_migrations_approved=false; owner_provider_go=false.**
