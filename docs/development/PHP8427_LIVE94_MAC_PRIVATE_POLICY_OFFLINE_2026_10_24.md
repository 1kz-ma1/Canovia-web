# PHP 8.4.27 Track H — Mac private Aiven policy preflight (OFFLINE ONLY)

**2026-10-24.** This is an *unconnected policy inspection adapter*, not a production-capable DB reader, credential manager or approval mechanism. The prior Mac source-only preflight (#851) and disposable MySQL source-bound one-client harness (#856) remain independent. Live production source stays pinned to `15abdc9de6f24b5250fb255c579d1aa55facaf1a` (94 migrations, 7 audit source blobs); emergency PHP 8.4.27 candidate `71824ed16df0615e1860d7f576e6aea546561977` is unreleased.

## Why this is the next justified step

The developer previously supplied owner-reported managed-Mac observations: old-Live checkout at the exact SHA, Python 3.12 isolated preflight PASS/HOLD, Apple Git/Python signatures, and Homebrew `mysql@8.4` bottle receipt. None authenticates the *Aiven service* or grants permission to read it. Aiven's [MySQL TLS guidance](https://aiven.io/docs/platform/concepts/tls-ssl-certificates) requires the **Aiven project CA and MySQL `VERIFY_IDENTITY`** for CA+hostname authentication. Being able to parse a local CA certificate is not an Aiven handshake or proof it is the correct project CA.

An operator eventually needs one private, reviewable source of the expected host/port/schema/audit user/CA configuration, separate from secrets and the source tree. `scripts/ops/php8427_live94_mac_private_policy_offline.py` provides **only syntax, local file and source integrity checks** for such a future policy. There is intentionally **no function that launches MySQL, opens a socket, resolves DNS, reads a password/login-path, or connects to Aiven**. The existing Python source gate is allowed to run local `git` commands to check Git objects, without network.

## Private policy shape (never commit this JSON)

The owner/operator must source host/port/schema and the CA file **independently from the correct production service in Aiven Console**, on their own device. Put these values only in a private, owner-owned **0600 JSON file** outside all Git repositories. The following is **fictional structure only**, not an instruction to use the example values or to connect:

```json
{
  "protocol": "MYSQL_TCP",
  "service_host": "mysql-placeholder.example.invalid",
  "service_port": 25000,
  "database": "live94audit",
  "reader_user": "reader_live94",
  "ca_file": "/absolute/private/operator/path/project-ca.pem",
  "tls_mode": "VERIFY_IDENTITY",
  "query_scope": "OLD_LIVE94_PINNED_7_SELECTS",
  "credential_handling": "SEPARATE_SECURITY_DESIGN_REQUIRED",
  "execution": "OFFLINE_REVIEW_ONLY"
}
```

This file must contain **no password, token, credential/SSH key, connection URL, owner approval flag, grant assertion, arbitrary SQL or other fields**. Do not send the private JSON, host, username, CA file/path or screenshots to ChatGPT or GitHub. The parent directory must be owned by the operator and not writable by a group or others; files cannot be symlinks or under the reviewed code or old-source checkouts. The CA file is loaded by `ssl.SSLContext(ssl.PROTOCOL_TLS_CLIENT).load_verify_locations()` **offline only**, and must be an owner-owned, bounded, non-symlink PEM file in a safe parent directory. This does **not** authenticate its Aiven Console origin, validity at connection time, CRL status or live host identity.

## Planned private Mac invocation — OFFLINE; do not run until requested

Using a reviewed, fixed Git main merge SHA that contains this adapter (not the moving `main`), once the operator has created the private file above, the future command format is:

```bash
python3.12 -I -B \
  "$HOME/Developer/Canovia-Reviewed-Tool/scripts/ops/php8427_live94_mac_private_policy_offline.py" \
  --checkout "$HOME/Developer/Canovia-Live94-Offline-Review" \
  --private-policy "$HOME/.config/canovia-live94-private/audit-policy.json"
```

**This is not a command to run in this PR; no user action is needed while CI is being reviewed.** The tool first invokes the existing Mac-only, non-CI `preflight()` to verify old Live94 Git source and local binary basic permissions. It then rejects extra/duplicate/unknown JSON fields, administrative reader usernames (including `avnadmin`), unsafe host/port/schema syntax, policy/CA bad owner/permissions, symlinks, malformed CA PEM, query-scope or TLS downgrades. It constructs a would-be MySQL 8.4 CLI argument tuple in memory **without a credential mechanism, execution or logs**; requires `--no-defaults --no-login-paths --protocol=TCP --ssl-mode=VERIFY_IDENTITY --skip-reconnect`, disables local infile/system commands and prohibits password argv.

The old Live94 source-bound **seven approved SELECTs** and before/after identity/grants markers are reloaded by the existing `immutable_packet()` code and checked for expected structure, never submitted to MySQL.

On a successful *offline* check, the result is **`HOLD`** with code `OFFLINE_POLICY_AND_PINNED_SQL_REVIEWED_PRIVATE_OWNER_ATTESTATION_REQUIRED`. Error results expose only `OFFLINE_MAC_POLICY_OR_FROZEN_SQL_REJECTED`. No target, filename, username, connection string, CA bytes or MySQL output are echoed. Even the successful result has `server_identity_authenticated=false`, `ca_provider_origin_authenticated=false`, `credential_transport_approved=false`, `reader_effective_grants_verified=false`, `real_aiven_connected=false`, `owner_aiven_read_go=false` and `production_go=false`.

## Acceptance tests and limitations

- Offline 8-test suite covers narrow JSON schema, rejected extra and duplicate keys, secret-field attempts, admin username/host/port/TLS downgrades, symlink/file permissions, unusable CA PEM, bad SQL framing, fixed CLI flags and Linux CI rejection.
- GitHub Actions **runs only on Linux**. Unit tests assess policy parsing with a locally generated **fictional** X.509 CA and mock SQL bytes. A separate job verifies the actual Linux CLI refuses to impersonate an operator Mac. Mac-platform pass remains **operator-reported**, not independently proven by CI.
- Policy acceptance is not real provider target authentication. A local owner-entered FQDN, CA, port and DB are only asserted strings, not independent Aiven provider records. A malicious/wrong provider host could still satisfy this syntax checker. Actual `VERIFY_IDENTITY` handshake + independently confirmed provider identity remains a completely separate gate.
- No real secret-handling implementation is provided. The synthetic CI executor embeds a **fictional** password in a disposable Docker wrapper; NEVER reuse that approach with real provider accounts. A real operator client requires a privately reviewed credential mechanism, secure one-session time/output ceilings and termination/incident handling, plus a **fresh, per-attempt owner GO**.
- Neither the completed source and synthetic DB smoke nor this policy validator proves real current 94/94 migration ledger, relevant physical schema/index/trigger/collation state, provider-side session termination, accepted backup restoration, protected production required checks, owner production merge GO or manual Render deployment authorization.
- No changes to `production`, Render Auto Deploy, Aiven users/allowlists/schema, release branch, paid infrastructure, production backups, e858 P1/R1–R6/B2, PHP emergency candidate or real audit account. **Production remains NO-GO.**

**Verdict on all-green CI: `MAC_PRIVATE_POLICY_OFFLINE_VALIDATOR_IMPLEMENTED / REAL_AIVEN_NOT_RUN / NO_LIVE_CREDENTIAL_TRANSPORT / RESTORE_HOLD / PRODUCTION_NO_GO`.**

Canonical release decision ledger: [Issue #488](https://github.com/1kz-ma1/Canovia-web/issues/488).
