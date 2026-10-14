# G3-R34 — Disposable Laravel/MySQL/S3 Cross-Layer SIGKILL Trial

**2026-10-15 | Platform lane | G3 OPEN | CI-only; not production approval**

## Purpose and source files

R33 proved S3-compatible storage generation retention after a real process
restart but did not prove the Laravel/MySQL transaction boundary. R34 runs
real independently booted Laravel PHP processes against disposable MySQL 8.4
and a separate localhost versioned S3-compatible server.

- `tests/ci/g3_r34_laravel_crash.php`: real opt-in Laravel journal, fence,
  file upload and Inbox owner creation.
- `tests/ci/g3_r34_orchestrator.py`: actual PHP SIGKILL, S3 SIGKILL,
  restart and new-client cross-layer state observation.
- `.github/workflows/g3-disposable-laravel-mysql-s3-crash.yml`: disposable
  runner with exact fake credentials, temporary DB and storage.

## Acceptance cases

| Case | Actual process checkpoint | Expected durable state after fresh-process restart |
| --- | --- | --- |
| prewrite | PrivateFileUploadIntentService::prepare committed; stopped before S3 PUT | journal=prepared, owner=0, fence=0, object=absent |
| midtransaction | Actual PrivateFileUploadService::storeAndPersist completed object write and inserted Inbox owner inside an uncommitted MySQL transaction; then SIGKILL | journal=prepared, owner=0, fence=0, object=present |
| postcommit | Real journal prepare, path-fenced S3 PUT and Inbox owner commit; stopped before intent settlement | journal=prepared, owner=1, fence=1, object=present |
| success | Actual PrivateFileUploadService::storeAndPersist returned successfully | journal=committed, owner=1, fence=1, object=present |

The postcommit case is explicitly constructed using **actual** journal,
path fence, database and S3 services, but is **not** a fault-injection
hook inside storeAndPersist. The midtransaction and success cases do
invoke storeAndPersist itself. No production code is instrumented.

The CI runner next SIGKILLs the MinIO container, asserts TCP failure,
restarts the same container with the same temporary data volume, then
uses fresh Laravel/Python clients to check the journal, Inbox owner,
fence, object bytes, exactly three versions and zero delete markers.
Verification performs no delete, write, journal settlement or automatic retry.

## Non-production trust boundaries

- Must be CI + APP_ENV testing with localhost 127.0.0.1 endpoints,
  exact disposable MySQL name, fake S3 principal, fixed bucket prefix,
  randomized private object paths and explicit opt-in.
- Cloud profiles, session tokens, web identity and container role
  credential sources are refused. No GitHub secrets are used.
- Flysystem S3 adapter is installed on this **disposable CI runner only**;
  no application composer.lock, runtime default or migration source changes.
- The historical third-party mirrored MinIO image is pinned by R33 digest
  solely for disposable testing; **not** independently authentic upstream,
  AWS proof, trusted production dependency or provider attestation.
- No Render, production Aiven, customer files, IAM mutation, live S3,
  conditional DeleteObject, unattended worker or paid external resources.
- The five previous additive production G3 migrations remain unapproved.

## Evidence and remaining gates

CI definitions are not empirical proof. Record the exact PR HEAD, actual
run, observed SIGKILL exits, MySQL state and server-restart version evidence
when they have completed. A failed setup/run must be reported as unproven.

Even after success, no authentic AWS signature/IAM, physical host power
failure, independent external writer, ABA-safe generation-conditional
deletion, real HTTP user/controller E2E, all writer-coverage reconciliation,
Aiven migration audit or live Web/iPhone evidence has been established.

**G3 OPEN. provider_attested=false, delete_authorized=false,
production_migrations_approved=false.**

## First measured CI execution (prior to evidence-append HEAD)

- Exact tested HEAD: `fe7245a96a8f4b8182ad6ee040da34ee6bba4e4b`
- GitHub Actions run: https://github.com/1kz-ma1/Canovia-web/actions/runs/38590003942
- Job: **G3 R34 real Laravel MySQL S3 crash recovery — SUCCESS**
- PHP process outcomes: `prewrite CRASH_READY`, `midtransaction CRASH_READY`,
  `postcommit CRASH_READY`, `success SUCCESS`; the parent confirmed the
  three crashed processes terminated from real SIGKILL.
- Independent fresh-Laravel verification: all four
  `R34_VERIFY_PASS` markers observed after MinIO SIGKILL/restart.
  MySQL persisted prepared versus committed journal/owner/fence status
  as specified in the table; midtransaction owner/fence were rolled back.
- Real HTTP provider generations: **three S3 object generations retained**
  after server restart, exact content bytes verified and **no delete markers**.
- This success predates this appended record. Final PR acceptance still
  requires required CI green for the updated exact HEAD and a full diff review.
- The experiment demonstrates **the four bounded states only**; it neither
  proves a real AWS provider nor grants any deletion or production authority.

## Final acceptance after evidence-append

- Final tested PR head: `372f5ed8a8c75ee8c335d90b2e027e4a97ce47ab`.
- Final [R34 trial run](https://github.com/1kz-ma1/Canovia-web/actions/runs/38590328725): **success**.
- The other required checks at that head were successful: Platform Release Preflight,
  Production Migration Recovery Validation, Development Prelaunch Preparation,
  and V56.12 Workspace Resume Validation.
- PR #661 merged to development `main` as
  `cfa3ee04a2b7306557088fe0ed83e5d44016430a`.
- This evidence changes neither production status nor G3 OPEN; see R35 for
  the external-provider deletion HOLD and the next independent approval gate.
