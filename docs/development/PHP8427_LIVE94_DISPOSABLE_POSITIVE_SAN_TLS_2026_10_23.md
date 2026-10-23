# PHP 8.4.27 — Live94 positive hostname-verified TLS (disposable MySQL ONLY)

**2026-10-23 / Track H / CI-only.** Does not authorize an Aiven connection, a production merge, or Render deployment. The historical Live source is `15abdc9de6f24b5250fb255c579d1aa55facaf1a`, and the frozen PHP/no-DDL emergency candidate is `71824ed16df0615e1860d7f576e6aea546561977`.

## Goal and boundary

PR #844 verified `VERIFY_CA` on auto-generated disposable MySQL certificates and rejected wrong CAs and unmatched hostname identities. This did **not** demonstrate positive `VERIFY_IDENTITY`. The official MySQL 8.0/8.4 guidance specifies hostname matching of certificate CN/SAN, and supports `ALTER INSTANCE RELOAD TLS` after replacing the files used by the current SSL context:

- [MySQL 8.0 connection options / VERIFY_IDENTITY](https://dev.mysql.com/doc/refman/8.0/en/connection-options.html)
- [MySQL 8.4 encrypted connection TLS reload](https://dev.mysql.com/doc/refman/8.4/en/using-encrypted-connections.html)
- [MySQL 8.4 server auto-generated certificate paths](https://dev.mysql.com/doc/refman/8.4/en/creating-ssl-rsa-files-using-mysql.html)

The new `scripts/ci/php8427_live94_disposable_positive_san_tls.sh` is **restricted to an already-running GitHub Actions disposable MySQL service** and runs *after* the PR #843/#844 synthetic authorization, source query and TLS negative tests. Its exact environment guard requires `GITHUB_ACTIONS=true`, all existing specific synthetic DB/app credential markers, localhost, and the service's ephemeral Docker container ID. A syntax and non-CI invocation unit test runs first; missing or changed guard inputs must stop before Docker, SQL or key work. The script never sees an Aiven hostname, private CA, authenticated Aiven account, or production service.

## Positive and negative scenarios

1. Generate an ephemeral 1-day self-signed test CA and a server certificate signed by that CA, explicitly carrying **DNS SAN `localhost` only**, under restrictive runner temporary permissions. Verify the SAN in the generated certificate before installing it. No private CA material is ever included in Git, an Actions artifact, or test logs.
2. Independently confirm the **disposable** service's live TLS context points to the standard generated names `ca.pem`, `server-cert.pem`, `server-key.pem`. Only if those names match, replace those three files **inside the disposable container only**, restore MySQL file ownership and key permissions, and execute `ALTER INSTANCE RELOAD TLS` using the throwaway root over local TCP. This TLS configuration mutation is **not permissible** against Aiven or any shared/persistent database.
3. With the exact fictional `live94_ci` reader, connect over TCP to **`localhost`** using the generated CA and **`--ssl-mode=VERIFY_IDENTITY`**. Require a nonempty negotiated cipher.
4. Attempt the same TLS method using **`127.0.0.1`** instead of `localhost`, which lacks matching IP SAN: connection **must fail**. Attempt **`localhost`** but with a newly generated untrusted CA: connection **must fail**. These are fail-closed negative controls.
5. In the positively authenticated disposable TLS connection, execute the original **seven Git-pinned old-Live94 SELECTs** and the synthetic same-session grants/identity wrapper. The existing redacted verifier must return **HOLD** on the intentionally incomplete synthetic physical schema. The fictional 94 migration names are not real Aiven evidence.
6. Remove ephemeral TSV, test CA, and private keys from the ephemeral runner on normal exit. No raw MySQL TSV, connection identifiers, grants or certificate private keys are printed, copied into Git or attached to Actions artifacts.

## Precisely what PASS would mean

- A **disposable test-server** positive CN/SAN host match with a trusted ephemeral CA was observed for the actual MySQL 8.0/8.4 client and server pair.
- Wrong host/IP SAN or CA was denied, and the original seven SQL statements ran inside the bounded temporary **single CI client session**.
- It **does not** validate real Aiven DNS/CA/cert rotation, effective Aiven grants, host device provenance, genuine backup/restore, production physical schema, Aiven 94/94 migration ledger, unexpected network loss, or remote Aiven session termination.
- `ALTER INSTANCE RELOAD TLS` is an intentional write to disposable CI server configuration; **not** a permitted production operation. Existing user privilege and SQL tests still use fictional CI data only.
- The script does not create a production login, real audit transport, or owner GO and it does not unblock the e858 94→113 Phase1 connection contract.
- Even all-green CI is **`SYNTHETIC_POSITIVE_VERIFY_IDENTITY=PASS` / `REAL_AIVEN_NOT_RUN` / `PRODUCTION_NO_GO`**.

## Remaining gates

Independent operator-machine runtime, exact service TLS identity and CA at execution, effective roles and privilege scope, trusted command/SQL binding, private incident plan and acceptable session-end uncertainty require distinct review. Any real Aiven SELECT-only audit needs separate explicit owner approval, with a credible current backup and rollback plan. Then require real protected `production` PR five status contexts, production merge owner GO and later distinct exact-SHA Render deploy owner GO. No movement to `production` occurs from this CI work.

Evidence ledger: [Issue #488](https://github.com/1kz-ma1/Canovia-web/issues/488).
