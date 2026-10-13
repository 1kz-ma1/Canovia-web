# MCP staging: Auth0 CIMD external/internal client identity binding

Status: **DRAFT / OFF BY DEFAULT / NOT A LIVE CHATGPT ACCEPTANCE** (2026-10-14)

## Grounded problem

- A verified Auth0 access token was received at isolated stage during a ChatGPT connection, but the strict MCP verifier rejected the signed client claim with `jwt_client_azp_mismatch` on 2026-10-14 00:23 JST.
- Owner twice confirmed the Auth0 Success Exchange event's displayed client ID matches the existing Render `CANOVIA_MCP_ALLOWED_CLIENT_ID`; that alone does NOT prove the signed `azp` equals the internal ID.
- Some later ChatGPT attempts show OAuth exchange success but no observable authenticated MCP POST. They must not be conflated with the JWT rejection.
- Auth0 documents that manual CIMD registrations retain a generated internal `client_id` (often `tpc_`) and an `external_client_id` CIMD URL; the OAuth flow uses the CIMD URL as the client identifier. Auth0 also documents that default access tokens use `azp` to identify the OAuth client.
- The real token's `azp` **value and actual Auth0 registration pair remain unverified**.

Official references:
- Auth0 CIMD registration and actual authorization token identity: https://auth0.com/docs/get-started/auth0-overview/create-applications/register-applications-with-cimd
- Auth0 default `azp` vs RFC 9068 `client_id`: https://auth0.com/docs/secure/tokens/access-tokens/access-token-profiles
- OpenAI stable CIMD and OAuth callback requirements: https://developers.openai.com/plugins/build/auth
- Auth0 Management API client response supports `external_metadata_type` and `external_client_id`: https://auth0.com/docs/api/management/v2/clients/get-clients

## Proposed strict, staging-only compatibility path

- Source branch is based on currently **live isolated staging SHA `dcd60340c088887eee5a8ebe98554a056cf94877`**, not moving main.
- An extra, OFF-by-default operator-attested binding is required: `CANOVIA_STAGING_MCP_CIMD_MAPPING_VERIFIED=false` (default) and `CANOVIA_STAGING_MCP_CIMD_EXTERNAL_CLIENT_ID=` (default blank).
- The new mapping path additionally requires `APP_ENV=staging`, `CANOVIA_STAGING_ISOLATED=true`, existing independently enabled synthetic/read/MCP pilot flags, selected `auth0_jwt` verifier, Auth0 internal allowlisted client ID in exact `tpc_` shape, AND **exact** stable `https://chatgpt.com/oauth/client.json` (not wildcard, another domain or arbitrary callback ID).
- Only after RS256/JWKS verification, apply the SAME strict signed claim-policy to this exact external URL. No unverified claim is trusted, and it is never sufficient to pass auth merely because an ID has a `chatgpt.com` URL shape.
- On success normalize only the returned MCP principal to the existing, separately allowed Auth0 internal ID. Existing per-Plan fingerprint, subject linking, scope, revocation and authorization are unchanged.
- Account-link verification never accepts CIMD mapping. Production and default environments never accept it.
- The single synthetic Development Plan consent is REVOKED. Neither an authorized `initialize` nor `tools/list` grants read access to `tools/call`.

## Operator-only evidence required BEFORE enabling or deploying

1. Inspect the Auth0 **same application record** (Dashboard or read-only Management GET, not through ChatGPT chat): internal `client_id` exactly equals the existing Render `CANOVIA_MCP_ALLOWED_CLIENT_ID`; `external_metadata_type` is exactly `cimd`; `external_client_id` is exactly `https://chatgpt.com/oauth/client.json`. Verify the selected ChatGPT connection uses stable RFC9207 issuer-bound callback. If any detail is uncertain, STOP.
2. Inspect only existing *fixed-category* Render logs on an actual signed rejected token. A `jwt_azp_chatgpt_cimd_form` category supports the URL-shape hypothesis but does not prove the exact URL or the Auth0 record. Do not share, print or log raw access tokens, JWTs, JWT payloads, Authorization headers, auth codes, URL query strings or client secrets.
3. Require reviewed GitHub Actions success for the EXACT PR HEAD and negative scenarios: wrong client, extra/wrong `client_id`, bad scope, wrong audience, expired token, production disabled, account-link client isolated, signature invalid, mapping OFF.
4. Only after separate owner approval may an operator set the two new **stage-only** env values to the attested exact pair, and manually deploy the reviewed SHA via Render **Deploy a specific commit** with Auto Deploy OFF. Do not use Render trigger_deploy against moving main. Test authenticated `initialize` and `tools/list`, no Plan reads until new explicit synthetic consent.
5. If results diverge, restore exact previous stage SHA `dcd60340c088887eee5a8ebe98554a056cf94877` and revert new stage-only mapping flag to `false`. Do not alter production/Aiven, Auth0 third-party grants, client registration, DCR, or real user data.

## Unresolved

We cannot inspect the owner's Auth0 Management app registration from the current connected tools, nor access the real signed JWT; no authority to assert the external/internal binding exists. The code is a fail-closed compatibility **candidate**, not a production fix and not an acceptance pass.
