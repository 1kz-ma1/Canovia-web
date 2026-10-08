# Canovia-private Development Context — owner preview contract

Updated: 2026-10-09. Current implementation scope: **session-only, owner-initiated preview**. This is not a ChatGPT MCP connector, OAuth server, delegated consent or permission grant.

## Why this slice exists

GitHub contains development specifications and source code. Canovia additionally holds private Plan and Task information. ChatGPT cannot be allowed to read it just because an agent can browse GitHub or because the user has a session cookie. Before implementing external access, build and test an intentionally **narrow, inspectable payload**. This prevents accidentally publishing historic WorkLogs, personal task descriptions, owner tokens, shared-team information or other domain data.

## Access boundary (active)

- New `GET /workspace/development/private-context/{plan}/preview` is **web-session authenticated** and throttled. No API token, bearer token, OAuth endpoint or public MCP route is introduced.
- Only the authenticated owner of a **non-collaborative**, currently Development-classified Plan may request its preview. Team Plans, guests, former collaborators, other owners, legacy Creative Plans and other domains fail closed. Sharing a Plan link, GitHub App installation, or Plan membership never grants this permission.
- Owner authorization is checked on **every** request. Explicit user action in the Development **作業** surface starts the read; no background polling or automatic AI sending.
- Params: `scope=overview|tasks` (default overview), `limit=1..8` (default 5), with validated JSON response and `Cache-Control: private, no-store`.
- Whitelist-only projection. Both scopes expose only bounded Plan title and classification label, a timestamp and explicit caveats. `tasks` adds up to 8 active Task **titles**, recorded status and recorded percentage (if set), but never descriptions, notes, resource URLs, evidence, WorkLogs, private tokens, Plan/Task database IDs, membership/user details or prior attempts. Mark count truncation if necessary.
- `recorded_progress_percent` is a persisted value, **not** an AI inference and does not prove shipped/CI/device verification. No updates to Plan/Task/Evidence. This endpoint intentionally does **not** query GitHub.

## UI and non-exfiltration

Development 作業 surface shows **`AI共有内容を確認`** only for an authenticated owner of a personal Development Plan. The first click fetches and renders the candidate data **in a user-visible read-only textbox**, with `概要のみ` / `進行中Taskを含む` scope choice. No context is embedded in initial HTML, stored in session/localStorage, or sent to an LLM. Users can choose **`表示内容をコピー`** after inspecting it, with a manual-select fallback for iOS/WKWebView. Do not label this screen as “ChatGPT connected” or “OAuth authorized”.

## Deferred: ChatGPT MCP OAuth

Official ChatGPT plugin MCP authorization requires an OAuth 2.1 authorization-code + PKCE (S256) flow, protected resource and authorization-server metadata, validated audience/issuer/scopes, client identification, consent and revocation. Use an established provider or a security-reviewed implementation—**do not** adapt existing HMAC provider activity credentials, reuse browser cookies as bearer tokens, mint unsigned Plan links, or expose this preview route unauthenticated. See [OpenAI plugin authentication](https://developers.openai.com/plugins/build/auth) and [MCP server](https://developers.openai.com/plugins/build/mcp-server) for current expectations.

A later PR must design the approval and revocation UX for a named ChatGPT client, bound actor, Plan, expiration and allowlisted scopes; verify privileges and current Plan membership **on each delegated read**; audit safe metadata without raw context/token; and implement all required negative access tests before enabling a public /mcp endpoint. Until then this slice must never issue a grant or claim direct ChatGPT → Canovia private read access.

## Acceptance

- Owner can preview only allowed fields and scope. Default returns **no Tasks**; task scope is an explicit choice.
- Cross-account, unauthenticated, team/shared, guest-owned and non-Development access are denied before any Task data is queried or leaked.
- Strict bounds, deterministic ordering, no-store, no writes, no secrets/description/note leakage.
- HTML/JS renders server-provided values as text, never HTML; fails visibly on denied/unavailable responses.
- PHP Feature and unit tests are in required CI. Merge only with all required checks green.
- Separate Render deployment check and iPhone/PWA/desktop E2E remain unverified until observed.
