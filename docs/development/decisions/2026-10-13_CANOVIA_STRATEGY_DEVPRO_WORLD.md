# Canovia strategic decision record — Core, internal Dev Pro and World

> Decision date: **2026-10-13 (JST)**
> Decision owner: **Product owner**
> Record state: **ACCEPTED strategic direction / NOT an implementation or release authorization**
> Status of evidence: strategy discussion; no measured user outcomes implied
> Process: [Product Decision Memory operating specification](../PRODUCT_DECISION_MEMORY_OPERATING_SPEC_2026_10_13.md)

This record preserves the rationale and boundaries of the current product discussion. ACCEPTED applies to the **direction**, not to availability, production data permission, funding, or a feature's implementation. If a later product choice differs, add a superseding decision rather than rewriting these initial expectations.

## D-2026-10-13-01 — Canovia's enduring user value

**Problem.** A broad goal-management/AI product risks looking like many unrelated tools while obscuring why longitudinal Context matters.

**Decision.** Canovia aims to prevent everyday actions and experiences from becoming disposable records: retain their potential to gain meaning later, help a person discover grounded connections they did not initially recognize, and make those insights usable in later decisions and domains. Value accumulates through *validated, authorized and relevant* Context rather than sheer data volume.

**Constraints.**
- The product does not promise that every action is meaningful or that inferred personality is an objective truth.
- Keep Facts, Context, Evidence, Interpretation and Recommendation distinct; derived claims need source, uncertainty, freshness and user challenge/correction paths.
- Avoid compulsory tracking, surveillance-style defaults, fabricated confidence and optimizing time-spent for its own sake.
- User agency, reasonable deletion/export, and permission-limited reuse outrank model personalization.

**Hypothesis (unverified).** When cross-domain Context demonstrably reduces re-explanation or reveals actionable and defensible connections, users will value Canovia more than a standalone task list.

## D-2026-10-13-02 — Release first; validate Context utility

**Problem.** Further foundational work without real users creates uncertainty and risks indefinite pre-release improvements. Launching without adequate data protection would be unacceptable.

**Decision.** Complete a security- and quality-gated **limited early-access release of the existing practical Canovia UX** as the immediate product priority. Study/Development are initial candidate experiences; ChatGPT can serve as one optional entry/return surface to demonstrate authorized Context continuity. Do not delay the release to build a game-map experience or finished external Dev Pro.

**Non-negotiable launch gates (to verify by Platform/release owner).**
- Real actor/tenant/Plan scoping, default-deny MCP/cross-service access, reviewed consent/revocation and no unauthorized read/write.
- No sensitive information in logs, tokens or public GitHub; safe auth/session/storage, backup/recovery, deletion/control paths and incident response appropriate to the pilot's data.
- Exact deployed SHA, required CI, iPhone/PWA/desktop critical-path acceptance, operational rollback and production-data risk review.
- Clear privacy notice, allowed data categories, data minimization and external AI provider limitations. Do not infer a privacy guarantee from current source-lineage unit tests.
- Production environment and release permissions remain governed by `AGENTS.md` / release gate; nothing here authorizes deploy, production branch changes, billing, or external resource exposure.

**Value validation: proposed metrics, not measured targets.**
- First meaningful value event / time-to-first-value (event contract and denominator defined before use).
- Context reuse usefulness: fraction of eligible *consented* reuse instances in which the user confirms reduced effort or a useful new insight.
- Return/retention e.g. D7/D14, with known selection/attribution caveats.
- Error/privacy incident rate, user trust and cost per active user as safety/economic guardrails.
- Exploration example only: 15–30 opted-in pilot users observed over 4–6 weeks; illustrative target candidates first-value >=70%, D14 >=30%, useful-reuse >=50%. **These are not approved launch thresholds, estimates of expected outcomes, or historical results.** Final metric definitions, event instrumentation and gates require validation.

**Phase transition rule.** Do not wait for a perfect UX. After a fixed pilot review window, use *quality of evidence + observed value + safety + support capacity + AI cost* to choose: improve the core loop, add one connector, or test a specialist app. Passing one percentage is not automatic authorization. If core utility remains unproven, don't disguise failure with more integrations.

**ChatGPT boundary.** Optional ChatGPT/MCP connection may lower first-use friction and show Context continuity, but Canovia must retain provider-independent source-of-truth Context. Identity linkage is not blanket consent, and no model may access other Plans or users by default. Current MCP staged implementation/permissions must be independently verified before claimed ready.

## D-2026-10-13-03 — Internal-first Dev Pro and Product Memory

**Problem.** Full external Dev Pro requires UI, billing, entitlement, access governance and support, which could absorb capacity before user validation. Yet better internal decisions and automatic-improvement foundations could leverage *every* later product.

**Decision.** Immediately adopt the GitHub-first [Product Decision Memory process](../PRODUCT_DECISION_MEMORY_OPERATING_SPEC_2026_10_13.md) as a **top-priority low-overhead development practice**. Then explore a small internal-only Dev Pro / Product Intelligence loop **after core public-readiness gates**, without waiting for external product UX or pricing. Internal development precedes external Dev Pro commercialization.

**Initial loop (candidate, not yet implemented):**
`hypothesis (recorded before outcome) → bounded evidence → comparison with caveats → reviewed decision package → approved specification/PR → release measurement → learning`.

**Order of promotion.** GitHub decisions first → metric/release mapping → manual experiment → deterministic outcome check/Evidence Health → Decision Memory → bounded AI summaries → approved agent-assisted implementation. No self-directed production changes, unchecked provider access, default unlimited AI cost or algorithmic product-owner replacement.

**Potential internal success metrics (not baselined):**
- Human elapsed/active effort from issue identification to accepted improvement PR.
- Share of evidence-backed proposals accepted as useful, including rejected suggestions and reasons.
- Share of releases whose intended effect and guardrails could be measured and evaluated with stated uncertainty.
- Implementation/analysis cost, review workload, incidents and regressions.

**Rationale.** The internal loop can improve decisions about Canovia and ultimately Canovia World; repeated, trustworthy internal validation creates a stronger case for later Developer Pro externalization than launching it as an untested paid feature.

**Status boundary.** Only the *documentary practice* starts now. The Product Intelligence runtime, new APIs, metric registry, Agent orchestration, auto-PR and external Dev Pro remain future/conditional scopes; promote each through separate latest-main investigation and Active Spec.

## D-2026-10-13-04 — Canovia World as a distinct first-party candidate

**Problem.** A fully game-like map placed inside the existing app would add release risk and navigation complexity, and prevent differentiation between execution-oriented and discovery/engagement-oriented UX.

**Decision.** Preserve **Canovia World** (working name; Canovia Social is an alternative name, not a committed brand) as a **separate future first-party app candidate**, not an automatic replacement UI or an already approved new product. World presents lived experience, connections and possible future paths as an explorable spatial world; Canovia remains a practical Goal/Action/Context product.

**Experience principles.**
- Stable recognizable landmarks for navigation; personalized growth, terrain, bridges and new regions reflect authorized experiences and evidence.
- 'Discovery' means a provenance-linked, fallible interpretation, not guaranteed talent, objective human worth or gamified completion scoring.
- Historical experiences retain visibility where the user chooses; absent activity does not decay the world or penalize the user.
- Rewards/check-in cues should inform real changes rather than encourage compulsive opening, streak anxiety or fabricated resources.
- Core execution remains accessible without navigating a complex map; mobile performance/accessibility/low-motion and empty-world behavior matter.
- Sharing is **opt-in and separately projected**. Hidden numeric details do not make mapped life events private; public landmarks, shape and connections may themselves disclose sensitive traits.

**Architecture boundary.**
- Future shared account login `!=` cross-app data permission. Separate consent by app, purpose, scope and revocation; minimize data.
- Core remains authoritative for Fact/Evidence/Context/Plan. World owns only its presentation/state (layout, cosmetics, animations etc.) and consumes a future authorized, versioned projection/API; no direct Core DB or unrestricted private shortcut.
- Prefer a read-only first pilot; any World-origin activity mutation/replanning requires explicit new Active Spec and approval policy.
- Avoid choosing game technology/engine, billing, social graphs or independent app launch date before independent demand and privacy/operating cost evaluation.

**Validation.** Prototype only after Core value baseline and internal capacity permit. Confirm the app has standalone user utility beyond 'same Canovia data with different skin', return behavior without manipulation, navigational comprehension, safe sharing, maintenance cost and compatibility with the future Platform/Context Contract.

Concept detail: [Canovia World companion product](../../future/CANOVIA_WORLD_COMPANION_APP_CONCEPT.md).

## D-2026-10-13-05 — Prioritization and review

**Current sequence (strategic, not an unrestricted implementation backlog):**
1. **Safety + public-readiness / bounded pilot for existing Canovia**, including optional provider connection only when specifically verified.
2. **Product Decision Memory Stage 0**, immediately and in parallel as a lightweight GitHub process.
3. **Internal Product Intelligence / Dev Pro slices**, gated by availability/quality of initial telemetry and scoped resource budget.
4. **World concept/prototype**, independently evaluated; do not block Core release.
5. **External Dev Pro / additional specialist products**, only after separately demonstrated demand and reusable contracts.

If a security, data-integrity or release-blocking issue emerges, it overrides automation/World expansion.

**Options rejected for now:** complete game-map rewrite before launch; immediately ship World as an alternate Beta UI; launch external Developer Pro with billing before an internal feedback loop; build a fully autonomous improvement engine up-front; accumulate activity data without demonstrated utility and purpose-specific consent.

**Revisit when:** limited-release acceptance is complete; pilot yields usable consented observations; proposed internal Product Intelligence can show time/decision gains; World proves a distinct value proposition; or privacy/API/platform constraints change materially.

## Evidence and outcome ledger

| Item | As of 2026-10-13 | Next evidence required |
| --- | --- | --- |
| Owner's strategic direction | ACCEPTED for preservation | Future reconsideration via superseding decision |
| Decision Memory documentation | Process specification created in this documentation change | PR review, exact head CI, main merge |
| Limited-release user outcomes | **UNMEASURED** | Consent-safe pilot cohort, baseline, tracked return/reuse |
| Internal Dev Pro engine | **FUTURE / NOT IMPLEMENTED BY THIS RECORD** | Separate scoped Active Spec and tests |
| Canovia World | **FUTURE / CONCEPT** | Prototype experiment and independent demand |
| Cross-app identity/context sharing | **FUTURE / NOT IMPLEMENTED BY THIS RECORD** | Consent & threat model, scoped API contract |

### Follow-up log

Add dated entries here with deployment SHA, metric definition, sample, segment, window, concurrent changes, result, confidence and next decision. **Do not replace the initial hypotheses above.** At creation time there are no valid new outcomes to append.
