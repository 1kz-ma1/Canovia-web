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
