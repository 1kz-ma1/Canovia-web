# PHP 8.4.27 Track H — Mac owner offline-only source and runtime preflight

> 2026-10-23. **No Aiven connection, login, TLS network handshake, metadata SQL, production PR, or Render deployment.** A return code of 0 is only a completed **HOLD** preflight, never authority to run a collector.

## Why this phase

Prior PRs #839–#847 proved old Live94 Git source integrity, bounded synthetic identity/session sequencing, 7 exact pinned SQL SELECTs, real disposable MySQL 8.0/8.4 negative grants/roles/TLS/termination behavior, and valid positive `VERIFY_IDENTITY` against **localhost with ephemeral synthetic SAN/CA**. These do not prove that a specific **operator Mac** is trustworthy or that the real Aiven target/CA/account/session is safe. The historical three-client `scripts/ops/p0_mysql_operator_select_only_collect.sh` remains **NOT APPROVED for live execution**.

Original deployed old Live SHA: `15abdc9de6f24b5250fb255c579d1aa55facaf1a` (94 migration files; tree `62dd8ce9bae3f73d1a390ea27242094e8165f9a2`). Frozen PHP 8.4.27/no-DDL emergency candidate `71824ed16df0615e1860d7f576e6aea546561977` remains independent, **not deployed**. e858's 94→113 Phase1 P1/R1–R6/B2 authorization is a different release track.

## New standalone private operator check

`scripts/ops/php8427_live94_mac_offline_preflight.py` accepts **only** `--checkout` pointing to an absolute, clean, detached old-Live94 checkout. It requires execution as isolated, bytecode-free Python (`python3 -I -B`) on a macOS host, and refuses use in GitHub Actions, synthetic MySQL CI, or with ambient MySQL/application passwords, DB URLs, nonstandard client defaults, real operator approval flags or Aiven connection settings in the process environment. It validates basic local file properties/permissions of the `git`, `mysql` and `python3` binaries **without invoking MySQL** and then calls the already-reviewed source gate to check the old Live HEAD, precise 94 migration tree and all seven fixed audit code/SQL Git blobs. The source gate calls *local git metadata only*.

Success returns **`verdict=HOLD`** and `OFFLINE_SOURCE_AND_LOCAL_PERMISSIONS_CHECKED_PRIVATE_ATTESTATION_REQUIRED`. Non-Mac/CI, missing or writable executables, wrong checkout/dirty source, unisolated Python or ambient credential/approval variables return a fixed BLOCK-condition code with `verdict=HOLD`, exit 2. Results contain only public codes/booleans, not real paths, account IDs, credentials, certificate data or package hashes.

This check does **not**:
- execute the historical collector, MySQL, any SQL, Aiven queries, a TLS handshake, ping/DNS, login-path or credential read;
- determine executable provenance/codesign/notarization, private CA authenticity, MySQL option-file contents, OS integrity, sandbox/backup/swap retention, server identity, actual grants or remote termination;
- grant live read permission, approve a production hotfix, run a backup restore, or authorize any change to `production`/Render.

**Important:** `git` itself is a local executable and checked only for basic file owner/mode here. A trusted owner must verify tool provenance independently, using local signed distributions and their own private operational record. Basic filesystem permissions alone cannot establish software supply-chain trust.

## Intended future private-Mac procedure — OFFLINE ONLY

Do **not** run an Aiven collector based on this document. Only after the reviewed PR has been merged, an owner may, in an ordinary interactive terminal, prepare a clean, detached checkout of the exact old Live commit from the Canovia repository **without Aiven settings**:

```bash
cd "$HOME/Developer/Canovia-web"
git cat-file -t 15abdc9de6f24b5250fb255c579d1aa55facaf1a
git worktree add --detach \
  "$HOME/Developer/Canovia-Live94-Offline-Review" \
  15abdc9de6f24b5250fb255c579d1aa55facaf1a

python3 -I -B \
  "$HOME/Developer/Canovia-web/scripts/ops/php8427_live94_mac_offline_preflight.py" \
  --checkout "$HOME/Developer/Canovia-Live94-Offline-Review"
```

The private owner MUST independently verify the reviewed script and checkout came from the exact intended Git commit before execution. If checkout exists already, do not overwrite it; validate it privately first. If source, environment, tool trust, or settings differ: **STOP/HOLD** rather than adding unsafe flags, filling Aiven secrets in a shell, disabling security gates, or copying environment variables/CA files into ChatGPT.

The only appropriate ChatGPT-facing information is a **nonsecret fixed status** such as `OFFLINE_SOURCE_AND_LOCAL_PERMISSIONS_CHECKED_PRIVATE_ATTESTATION_REQUIRED` or a generic BLOCK code. Never share real Aiven host/account, connection string, login path, CA, secret, raw grants/ledger, local sensitive paths, hashes of private records, screenshot of credentials, or signed approval forms.

## Independent owner-private check that remains necessary

Before a *future* real SELECT-only Aiven attempt, privately verify:
1. macOS patch state, trusted CLI/Python binary distribution and signed provenance; actual `.mylogin.cnf` account/host and chosen Aiven **production** DB (not staging), independently cross-checked against provider; correct CA trust and SAN/hostname identity with **no TLS downgrade**;
2. narrowly scoped read-only principal, effective and mandatory/active roles, metadata visibility constraints, fixed seven SQL digests **at execution**, no DML/DDL/privilege modifications; no unsafe client defaults, shell tracing or output path;
3. bounded connection/query/job times, private output storage, local/interrupted/remote session observation and incident escalation procedure, no automatic retries/backup copies, explicit STOP on ambiguous termination or identity drift;
4. confidential risk acceptance and **fresh, separately scoped, one-attempt owner GO** for real Aiven SELECT. It must NOT be inferred from the word `進めて`, `CANOVIA_P0_OPERATOR_APPROVED`, this script's exit code, GitHub CI, or an old e858 P1 record;
5. independent recoverable backup/restore acceptance, protected `production` PR with **all five** required GitHub App checks and separate production merge GO, then an explicitly approved exact-SHA manual Render deployment (never the moving `main`) and Web/iPhone post-deploy smoke.

If the real collector cannot satisfy security requirements, it remains prohibited. A separate code path designed for a hardened single-session source-bound transport requires an independent review before live use.

## CI proof

The [main-only source preflight workflow](../../.github/workflows/php8427-live94-mac-offline-preflight.yml) performs synthetic Mac-policy unit tests on Linux, a detached old Live94 source-pin test and **requires** the actual Linux CI invocation to be BLOCKED as non-Mac. Neither CI nor the script contains a database transport. **Simulating Mac in unit tests does not certify an actual Mac device.**

Status ledger: [Issue #488](https://github.com/1kz-ma1/Canovia-web/issues/488).

**Verdict: `MAC_LOCAL_OFFLINE_PREFLIGHT_SPECIFIED_AND_TESTABLE / PRIVATE_MACHINE_NOT_YET_ASSESSED / REAL_AIVEN_NOT_RUN / PRODUCTION_NO_GO`.**
