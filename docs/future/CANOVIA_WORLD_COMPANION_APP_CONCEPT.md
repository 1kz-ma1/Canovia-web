# Canovia World — Independent Spatial Companion App

> Status: **CONCEPT / candidate first-party product**
> Recorded: 2026-10-13
> Working names: `Canovia World` (preferred concept label), `Canovia Social` (alternative, social emphasis not adopted)
> Authority: Future Design only; **not** a current app, alternate UI Beta, approved public product, contract, or feature backlog
> Strategic decision: [2026-10-13 Core / Dev Pro / World](../development/decisions/2026-10-13_CANOVIA_STRATEGY_DEVPRO_WORLD.md)

## 1. Product thesis

Canovia Core makes action, evidence and longitudinal understanding useful. Canovia World may express their authorized, imperfectly understood relationships as a spatially explorable and personally meaningful environment. **Effort becomes a discoverable landscape rather than disappearing into completed-task history.**

Unlike a replacement UI for the practical Canovia app, a future separate World app can optimize for spatial comprehension, self-reflection, enjoyable exploration and optional self-expression; users of either app could benefit from their shared Canovia Context if they deliberately consent.

This is a hypothesis to test, not proof of novelty, product-market fit, or release authorization.

## 2. Why a social-game-like spatial surface

- **Spatial memory:** persistent landmarks/buildings maintain recognizable locations and roles even as capabilities grow.
- **Expansibility:** new regions can branch from related experiences without burying known destinations in deeper menus.
- **Visible history:** completed activities may contribute to a persistent world rather than becoming hidden rows.
- **Possibility discovery:** new evidence-backed connections may create bridges, paths, and explorable areas.
- **Expressiveness:** a world can convey complexity without exposing detailed task numbers—**but its shape can still reveal personal information**.
- **Daily return:** real change is worth checking; no synthetic scarcity/streak pressure as a retention tactic.

Not a substitute for clear task actions, accessible navigation, consent or evidence validation.

## 3. Candidate experience model

Conceptual node types (not schemas):
- **Landmark:** stable place for a type of action/capability, shared enough for easy recognition.
- **Experience region:** user-specific space derived from authorized, meaningful activity signals.
- **Link/bridge:** explainable cross-activity relationship, with sources and uncertainty.
- **Discovery:** revisable potential/path suggestion based on multiple observations, never an assertion of personality or merit.
- **World decoration:** purely cosmetic/private World-owned data, never the authority for Plan/Task progress.

Suggested hybrid geography: stable common landmark/navigation grammar + individualized growth and links. Dedicated simple action route/fallback is always available.

Sample *fictional* path:
`learning exercise Evidence + development debugging Evidence → possible practical-learning preference (hypothesis) → optional study-action suggestion → user confirms, declines or ignores`.
A hypothesis may be removed or weaken when source provenance expires; old evidence should not be presented as current truth.

## 4. Representation invariants

1. No claim that time spent equals achievement; no universal 'life level' or comparative human worth.
2. Do not punish inactivity, hide past earned visibility by default, or invent resource drops to lure daily openings.
3. Clear map states: observed / inferred / unverified / user-confirmed; make 'why this appeared' discoverable.
4. Stable familiar locations; expansion rather than arbitrary re-layout. Accessible list/text alternative, keyboard/screen-reader support, reduced motion, low-end/mobile performance and comprehensible empty state.
5. Explainable and reversible display options. An internal potential hypothesis is never silently promoted to a confirmed personal trait.
6. Core activity and progress do not depend on World adoption.

## 5. Shared account and data — FUTURE boundary

Conceptual split:

```text
Canovia App                  Canovia World
  practical execution          exploration / world presentation
          \                      /
            authenticated + explicitly authorized
                  Canovia Context API
                         |
          Core Facts / Evidence / Context / Plan
```

- Future SSO/Identity contract is **not** a grant to all private data.
- Consent for World must be specific to app, purpose, data categories, revocable scopes and lifecycle. Include deletion/retention and reauthorization flows.
- World reads a purpose-scoped, minimized projection; **no direct production DB access, session/token reuse shortcut or implicit export of all Context**.
- Prefer first beta as read-only context projection and user-owned cosmetic state; progression/action write-back requires independent human-approved contracts.
- Any derived/inferred map content needs lineage, freshness, confidence and safe suppression if its authority changes.
- Keep first-party access boundaries at least as strict as third-party; no 'trusted app' exemption.
- Do not expose real user data in source repository, analytics, screenshots or demos.

## 6. Social/privacy separation

Social features are **not** a prerequisite for the first version and have separate moderation, abuse prevention and consent costs.

- Map is **private by default**.
- Public presentation must be a separate **allowlisted export/projection** of user-chosen items—not a visually obscured version of the complete private map.
- A building, pathway, density or region unlock can leak health, work, qualifications, identity or other inferred information even if metrics are hidden. Test inference risks.
- Avoid implicit leaderboards/rankings. World complexity isn't a score.
- Social sharing and friend discovery require distinct opt-in, block/report and safety policies before implementation.

## 7. Relationship to Canovia Dev Pro

World should not block early Core release. Internal Dev Pro/Product Intelligence may later support its specification, experiment design, usability metrics, PR execution and post-release outcomes. World itself could then be a practical test of reusable creation/improvement processes, **not** automatic evidence that they succeeded.

Relevant Future sources:
- [Platform / Ecosystem](CANOVIA_PLATFORM_ECOSYSTEM.md)
- [Product Creation System](PRODUCT_CREATION_SYSTEM.md)
- [Product Intelligence](PRODUCT_INTELLIGENCE_AUTOMATIC_IMPROVEMENT.md)
- [Developer Pro](DEVELOPER_PRO_AI_DEVELOPMENT_ORCHESTRATION.md)

## 8. Validation and possible promotion

Before an independently distributed app is authorized:
1. Core context reuse demonstrates usefulness with real users and reliable privacy gates.
2. Prototype establishes navigation comprehension, delightful return without coercion and independent value beyond an alternate UI.
3. Cross-app access model has scope-specific consent, revoke, deletion, source-freshness, isolation, threat modeling and logging tests.
4. Estimate iOS/app store compliance, build/maintenance burden, accessibility and support operations.
5. Product owner approves a small Active Spec with acceptance tests, cost and explicit rollout audience; promotion is not automatic.

Choose separate-app versus internal prototype using user evidence, not uniqueness claims alone. No database, app store listing, runtime flag, billing product, new CI requirement or product launch is authorized by this document.

## 9. Non-goals now

- No implementation of game world rendering or new map overlay in current Canovia.
- No personal analytics dashboard replacement.
- No stand-alone `World` account/token architecture yet.
- No Canovia Family shared Context API/DB implementation.
- No social feed, ranking, public profile default or gameplay retention mechanics.
- No World purchase/subscription plan before demonstrated independent demand.
