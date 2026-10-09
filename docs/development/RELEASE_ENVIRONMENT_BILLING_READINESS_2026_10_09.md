# Canovia — final pre-publication environment and billing checklist

Owner decision: 2026-10-09. **Do not upgrade or buy hosting now.**
Purchase or upgrade only when a measured technical or launch need exists,
with fresh pricing and explicit owner approval. Review all outstanding
items during the **final public-release preparation**, before launch.
This is a checklist, not permission to change any infrastructure.

## Required pre-launch decisions (currently deferred)

| Review | Current evidence / acceptance | Decision |
| --- | --- | --- |
| Render production compute | `Canovia` in `My Workspace` is on Free; determine load, cold-start, Free-hour budget, real current paid-Web price, and whether minimum paid compute is needed | Pending final pre-launch review |
| Render workspace subscription | Paid compute is distinct from an account/workspace subscription; never conflate the two | No workspace upgrade approved |
| Staging Web and IdP | Separate `canovia-mcp-staging` Free Web and PostgreSQL test DB; estimate independent IdP RAM, hosting and recurring expenses | Pay only if required and approved |
| Staging Postgres expiration | Free DB `dpg-db43rbbncjis73bmigi0-a` expires 2026-11-08; synthetic records are disposable and not backed up | Decide renewal/migration before expiry if still needed |
| Production database identity | **Aiven MySQL database `pacekeeper`**. On 2026-10-09, mistakenly switching `DB_DATABASE` to `defaultdb` caused legacy login failure. | Freeze the verified identity; add safe deployment checks before launch |
| Production backup and recovery | Aiven backup existence, retention, tested restore, schema safety and ownership have not been independently verified | Must verify before public user data |
| Authentication / session | Password sign-in recovered on `pacekeeper`; sessions use Render Key Value `canovia-session` with persistence OFF (may require login after restart) | Validate iPhone PWA, Safari, desktop, token rotation and acceptable persistence |
| Password reset mail | `MAIL_MAILER` unset defaults to `log`, not real outbound delivery; test real mail transport and anti-abuse limits | Must enable/test reliable delivery before launch |
| MCP production exposure | Currently default OFF; isolated staging must prove scoped consent, token introspection, revocation and cross-user denial | Do not enable until separate security signoff |
| App Store and billing | TestFlight, StoreKit entitlements, restore purchases, privacy/legal text and actual server cost per user | Complete in final launch review |
| Rollback | Document Render deploy rollback, DB migration compatibility and emergency disable switches | Rehearse on staging first |

## Operational invariants

- Render workspace is **My Workspace**,
  `tea-d4vb3f6mcj7s73djkrqg`.
- Production service `srv-dagfn2id0e5s73cac7ng`; use Aiven MySQL
  `pacekeeper`. Staging service `srv-db43l4nlk1mc73emseig` and
  its separate Render PostgreSQL `canovia_mcp_staging_db` must not
  reuse production credentials, APP_KEY, session stores or user records.
- During staging MCP preparation, keep production account, login,
  database schema, environment configuration and paid plan unchanged.
  Code merge to main may auto-deploy production; require CI review
  and explicit note of any stage-gated changes.
- Never commit or paste passwords, full DB URLs, API keys, bearer
  tokens or personal Plan data into a repository, chat or logs.
- "Running migrations" and HTTP 200 for `/up` alone are not
  sufficient proof of production identity, backups or end-user login.
- Do not assume contract costs and pricing stay fixed. Check the
  provider dashboard before authorizing a purchase or plan change.
- No upgrade is scheduled or automated by this document.

References: [staging synthetic MCP test](MCP_STAGING_SYNTHETIC_READ_FIXTURE_2026_10_09.md),
[staging provider decision](MCP_IDP_SELECTION_AND_STAGING_POSTGRES_2026_10_09.md),
[development roadmap](ROADMAP.md), and
[release level specification](../CANOVIA_RELEASE_LEVEL_SPEC.md).
