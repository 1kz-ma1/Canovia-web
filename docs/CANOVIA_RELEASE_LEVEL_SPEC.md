# Canovia Release Level / Feature Flag Specification

> Status: Product contract + V58.20 Foundation + V58.21 Release Gate + V58.22 Product Preview + V58.23 Early Access Observability + V58.24 Release Review implemented  
> Updated: 2026-10-07  
> Scope: Early Access staged release, admin preview, beta rollout

V58.20でFoundationを実装済み:

- `ReleaseLevel` / `ReleaseLevelService`
- config-based Public Release Level
- Super Admin Release Preview
- user-specific Level override
- Career Level 3 gating
- server-side middleware
- Workspace / onboarding visibility integration
- Admin Economy Inspector access control

V58.21でFeature maturity inventoryとread-only Release Gateを追加済み。

- L1 = stable Study / Development Core
- L2 = presentation-only Product Preview
- L3 = selected Beta capabilities
- L4 = Internal mutation / unfinished capability

V58.22で `product.preview.index` を実装し、L2 automated gateはManual Review候補へ進んだ。
Initial Early Access planning targetはL2 Product Preview。Public LevelのDB/Admin操作は引き続き未実装。

V58.23でEarly Access disclosure / Feedback context / activation・retention observabilityを追加済み。

- `admin.early_access.index`
- `early_access_registered`
- `early_access_session_started`
- DB factベースActivation
- D1 / D7 operational retention

L1 structural gateではFeedback送信routeとAdmin Early Access observability routeの存在も検証する。

## 1. Purpose

Feature Flagを個別スイッチとして使うだけでなく、複数機能・UI・権限を整合した状態で公開する上位概念として **Release Level** を定義する。

Release Levelは「完成機能の数」ではない。

> そのLevelだけでCanoviaとして成立する、検証済みの公開構成

を表す。

## 2. Responsibility boundaries

### Feature Flag

問い:

> この個別機能を現在有効にするか。

用途:

- kill switch
- internal experiment
- limited beta
- feature-specific validation
- staged enable / disable

### Release Level

問い:

> このactorへ、どの整合済みCanovia構成を提供するか。

複数Feature Flag / Navigation / Surface / permissionをまとめる上位契約。

### Entitlement

問い:

> 公開済みFeatureを、このactorが契約・権利上利用できるか。

Release Level / Feature FlagとEntitlementは別責務とする。

### Ownership / Authorization

Plan / Task / Repository等の個別resourceへの権限はさらに別責務。

## 3. Separate release contexts

PublicとAdmin Previewを同じLevel stateへ紐付けない。

### Public Release Level

一般ユーザーの標準本番構成。

```text
public_release_level
```

新規一般ユーザーは原則これを継承する。

### Admin Preview Level

管理者本人の検証用構成。

```text
admin_preview_level
```

Publicを変更せず、未公開Levelを本番コード上で確認できる。

### User Access Level

ユーザー単位の段階公開。

```text
user_access_level
```

用途:

- Early Access tester
- Beta tester
- support validation
- gradual rollout

例:

```text
Public user       = Level 2
Beta user         = Level 3
Admin preview     = Level 4
```

## 4. User-selectable release channel

一般ユーザーが任意にInternal Levelへ上がることは許可しない。

将来的に運営が許可した範囲のみ:

- Stable
- Beta

等をユーザーが選択できる構造は許容する。

ユーザー自身の選択は、運営が許可したmaximum levelを超えない。

## 5. Initial level model

名称・所属Featureは実装時に最新mainへ合わせて確定する。
初期Product contractは以下。

### Level 0 — Core Stable

Safe baseline.

- Authentication
- Goal / Plan / Task
- basic Home
- basic execution
- Evidence / record
- Settings

障害時のSafe Mode候補にもなる。

### Level 1 — Early Access Core

Level 0 +

- stable Study
- stable Development
- Workspace switch
- basic Telemetry
- Feedback
- Early Access disclosure

このLevel単体で新規ユーザーがCanoviaの中核価値を理解できること。

### Level 2 — Product Preview

Level 1 +

- Premium Coming Soon
- Pro Coming Soon
- Dev Pro Coming Soon
- plan preview / preview videos
- Product Intelligence preparation guidance
- stable preview surfaces

「今使える価値」と「今後の方向」を同時に理解できる状態。

### Level 3 — Beta Expansion

Level 2 +

- Career Beta after dedicated UI / flow alignment
- beta workspaces
- pre-release Premium/Pro capabilities selected for validation
- advanced telemetry required for those validations

一般公開前の利用者検証。

### Level 4 — Internal Preview

Admin / developer only.

- unfinished UI
- experimental workspace
- Pro / Dev Pro experiments
- debug surfaces
- internal metrics
- incomplete flows

一般ユーザーへ直接公開しない。

## 6. Feature dependencies

Featureは依存関係を持てる。

Example:

```text
career_interview_review
requires:
- career_workspace
- career_application_pipeline
```

Release Levelは依存が解決されたFeature setだけを公開する。

単一FlagをONにしたことで壊れたNavigation / missing surface / permission mismatchが発生する状態をPublic Release Levelへ入れない。

## 7. UI consistency

Featureの公開可否はボタン一つのshow/hideだけではない。

少なくとも以下を一体で考える。

- Navigation
- Home / Mode Top
- Empty State
- Settings
- Workspace Switcher
- Action / CTA
- Dashboard / Analysis
- Upgrade UI
- Deep Link
- server route / API permission

Careerを非公開にする場合、Career routeだけでなくCareerを前提にするHome card / onboarding / switcher entryも同じ契約で扱う。

## 8. Backend enforcement

Release Level / Feature Flagはpresentationだけに使わない。

未公開FeatureのURLやAPIへ直接アクセスしても利用できないことをserver-sideで保証する。

ただし一般ユーザー向けpresentationでは、単純403よりNot Available / Coming Soon等の適切な遷移を優先する。

Ownership / authorization failureと「未公開」を混同しない。

## 9. Data compatibility on downgrade

上位Levelで作成したデータをLevel downgrade時に削除しない。

Example:

```text
Career BetaでApplication作成
→ Level 2へ戻る
→ Career UIは非表示
→ dataは保持
→ 再解放時に復元
```

Schema / data migrationはRelease Level表示制御と分離する。

## 10. Override priority

Initial priority:

```text
1. Emergency Kill Switch
2. Explicit safety / feature override
3. Actor-specific access / beta override
4. Public Release Level
5. Default feature state
```

Entitlementは「公開されたFeatureを利用できるか」の別評価として適用する。

## 11. Admin controls

Admin UXでは最低限以下を分離して操作できるようにする。

### Public

- current Public Release Level
- next candidate Level
- Levelごとのincluded features
- release gate state

### Admin Preview

- own preview Level
- debug override
- Public stateとの差分

### User

- access Level
- beta participation
- approved feature override

AdminのPreview変更はPublic stateを変更しない。

## 12. Promotion flow

```text
Development
→ Internal Preview
→ Admin Preview
→ selected Beta users
→ Public Release candidate
→ Stable
```

完成した単一Featureを即座に全ユーザーへ公開しない。

## 13. Release gate

Public Levelへ昇格する最低条件:

- major flow成立
- blocker 500 / unintended 403なし
- dependency解決
- backend permission整合
- Empty State確認
- mobile / responsive確認
- downgrade / rollback確認
- Telemetry確認
- feedback path確認
- kill switch確認
- data lossなし
- privacy / ownership境界確認

細かいvisual polishはRelease blockerにしない。

## 14. Early Access recommended state

Initial recommendation after V58.22:

```text
Early Access candidate = Level 2
Admin Preview          = Level 4
Selected Beta          = Level 3
```

これはPublic Release Levelを自動変更しない。L2のManual Release Checks完了後にProduct Ownerが公開判断する。

Study / Developmentを安定した主要カテゴリとして公開し、Careerは専用UI / basic flowを揃えてからLevel 3 Betaへ昇格させる。

## 15. Interaction with monetization

Release LevelとPaid Planは同じ軸ではない。

Example:

```text
Feature is in Public Release Level
+
actor has Pro entitlement
→ Pro capability usable

Feature is not in Public Release Level
+
actor has Pro entitlement
→ not publicly usable
```

Admin Previewでは将来のFree / Premium / Pro presentationを確認できるが、Public Release状態へ影響させない。

## 16. Existing architecture compatibility

既存の:

- `FeatureFlagService`
- `FeatureAccessService`
- Admin Free / Premium Preview
- Product Grant / Entitlement
- ownership boundaries

を置き換えるのではなく、Release Levelをその上位presentation / release contractとして追加する。

既存Admin PreviewのFree / Premium viewは「契約プレビュー」。
Admin Preview Levelは「公開成熟度プレビュー」であり、別軸として扱う。

## 17. Non-goals of this specification

V58.21 Release Gate後も、この文書だけでは実装しない:

- Public Release LevelのDB永続化 / Admin切替
- checkout / billing
- percentage rollout engine
- billing
- Premium / Pro entitlement resolver migration
- Career public release
- automatic release promotion
- autonomous feature activation


## V58.24 Manual Release Review

Release GateのManual Checkへstable keyを付与し、Passed / Failed / Pendingを永続化する。

Canonical detail:

- `docs/V58.24_RELEASE_REVIEW.md`

Final decision:

```text
Automatic blocked -> BLOCKED
Automatic ready + Failed -> REVIEW FAILED
Automatic ready + Pending -> MANUAL REVIEW
Automatic ready + all Passed -> READY FOR RELEASE
```

Review結果はPublic Release Levelを変更しない。
READYはProduction昇格を自動実行する状態ではなく、Product Ownerが公開操作を検討できる状態を意味する。

L2 reviewはL0 + L1 + L2の累積Manual Checkを対象とする。

V58.24ではL2→L0 downgrade regressionも追加し、Surface非表示とdata preservationを自動検証する。


## V58.25 Personalization Bootstrap release contract

Personalization Bootstrap minimum:

```text
L1 Early Access Core
```

L1 structural required routes:

- `personalization.show`
- `personalization.store`

L1 manual review:

- `personalization_first_use`
  - diagnosis
  - Plan Seed
  - existing Plan create
  - mobile / desktop

L0 downgrade:

- Personalization Account entry hidden
- direct route blocked by Release Level middleware
- new First Run falls back to stable Goal Discovery / Plan create path
- saved Personalization Context is not deleted

Personalization Eligibility does not replace:

- Release Level
- Feature Flag
- Entitlement
- Ownership


## V58.26 Capability Activation release contract

Minimum:

```text
L1 Early Access Core
```

L1 structural required route:

- `capabilities.setup.start`

L1 manual review:

- `github_capability_activation`
  - Preview
  - Interest
  - Readiness
  - single Repository candidate
  - multi Repository no-auto-selection
  - Setup start
  - abandon
  - completed state
  - mobile / desktop

Capability Activation must not bypass:

- Release Level
- Feature Access
- Plan ownership / edit access
- existing GitHub App verification


## V58.27 Living Profile release contract

Minimum:

```text
L1 Early Access Core
```

L1 structural required route:

- `personalization.updates.index`

L1 manual review:

- `living_profile_context_update`
  - low-risk auto update
  - high-impact pending candidate
  - confirm
  - dismiss
  - no repeated prompt from same candidate
  - self-reported preservation
  - mobile / desktop

Living Profile must not bypass:

- Release Level
- Entitlement / Feature Access
- self-reported source provenance
- explicit confirmation boundary for high-impact changes


## V58.28 Study Behavior Personalization release contract

Minimum:

```text
L1 Early Access Core
```

No new structural route.

L1 manual review:

- `study_behavior_personalization`
  - 1–2 assessed attempts: no Living Profile transition
  - 3rd assessed attempt: practice-focused observed Context
  - Growth Experience visible
  - self-reported Study stage preserved
  - Guidance preserved
  - later attempts update metrics without duplicate candidate
  - mobile / desktop

Study Behavior Personalization must not bypass or rewrite:

- current Study Intelligence
- Plan / Task progress
- Release Level
- Entitlement / Feature Access
- self-reported Personalization Context


## V58.29 Plan Lifecycle Personalization release contract

Minimum:

```text
L1 Early Access Core
```

No new structural route.

L1 manual review:

- `plan_lifecycle_personalization`
  - genuinely new Plan → new_plan refresh
  - createOrFirst retry → no duplicate
  - incomplete Plan → no completion refresh
  - final active Task → one plan_completed refresh
  - cancelled Task does not block completion
  - reopen / re-complete → no duplicate in V58.29
  - no Personalization Context → no silent profile creation
  - non-owner / collaborator must not mutate owner Personalization

V58.29 does not introduce automatic Plan mutation or a new Plan status.


## V58.30 Plan Completion Fingerprint release contract

Minimum:

```text
L1 Early Access Core
```

No new route / migration.

L1 manual review:

- `plan_completion_fingerprint`
  - runtime status/progress only → same fingerprint
  - material Task edit → fingerprint changes
  - first completion → revision 1
  - same-structure re-complete → no new revision
  - material change + re-complete → revision 2
  - new Task + re-complete → revision 2
  - V58.29 memory → silent legacy baseline
  - post-baseline material change → revision 2
  - cancellation can establish Plan completion
  - cancelled trigger Task is not labelled completed

V58.30 does not change Release Level, Entitlement, Plan content, or Guidance automatically.


## V58.31 Return-after-Absence release contract

Minimum:

```text
L1 Early Access Core
```

No new route / migration.

L1 manual review:

- `return_after_absence_personalization`
  - first tracked visit → baseline only
  - <14 days → no refresh
  - >=14 days → one return refresh
  - same-day navigation → no duplicate
  - prefetch ignored
  - self-reported Context preserved
  - Guidance preserved
  - no Personalization Context → no silent profile creation
  - mobile / desktop

V58.31 does not introduce automatic replanning or re-onboarding.


## V58.32 Candidate Evidence Reopen release contract

Minimum:

```text
L1 Early Access Core
```

No new route / migration.

L1 manual review:

- `context_candidate_evidence_reopen`
  - initial high-impact Candidate has evidence revision 1
  - dismiss snapshots current signal strength
  - same evidence → stays dismissed
  - changed fingerprint at same strength → stays dismissed
  - stronger signal → reopens once
  - reopened explanation visible
  - pending Candidate silently refreshes evidence snapshot
  - legacy dismissed Candidate does not reopen at strength 1
  - legacy dismissed Candidate may reopen at stronger signal
  - self-reported experience preserved
  - Guidance preserved
  - mobile / desktop

V58.32 does not change Release Level or Entitlement automatically.
