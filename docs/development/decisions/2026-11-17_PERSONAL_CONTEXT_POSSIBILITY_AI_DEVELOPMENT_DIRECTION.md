# D-2026-11-17 — Personal Context × 可能性発見 × AI開発支援の長期方針

- Decision ID: `D-2026-11-17-PERSONAL-CONTEXT-POSSIBILITY-DEV`
- 日付: 2026-11-17 JST
- 判断主体: Canovia Product Owner（構想の評価を受け、選択的な記録を指示）
- 状態: **ACCEPTED FOR STRATEGIC PRESERVATION / FUTURE CONCEPT**。**実装・検証・課金・公開・ロードマップ昇格は未承認**。
- 記録の性質: Product Decision Memory。今回の新しい差分・判断境界・未検証仮説を残すもので、Active Spec、実装タスク、製品要件を新設しない。
- 参照時点: Canovia-web開発 `main` `a84dda3a91d07ca32e7ffe0e1c9a4cac77733c5f`、Canovia-iOS `main` `fc92fba3c34e626c67b3abfb172f00cd260123df`。既存Draft PRはmain実装と混同しない。
- 安全・公開: **`SECURITY_BLOCKED / PRODUCTION_NO_GO_HOLD / release_approved=false` を変更しない。**

## 1. 今回、保持する戦略上の差分

既存のCanovia理念「Goal → Plan → Action → Evidence → Replanning」、Living Profile、Developer ProのContext/Orchestration Layer、および専門Workspace構想を**置換しない**。次の3点だけを、将来の体験・検証のために明確化する。

1. **可能性を小さく試す導線**: 本人が目標をまだ定めていない状態も対象とし、許可されたContextから「可能性の仮説」を提示した後、小さな実体験・本人の振り返り・任意のPlan作成へ接続する。
2. **AI開発支援の段階化**: 既存Developmentの上に、まず**A案（外部AIへの手動受け渡しを前提とする司令塔支援）**の価値を検証する。**B案（Canovia管理下でのエージェント実行）**はAと論理的なタスク・証拠・承認契約を共有する将来候補であり、実行権限・隔離・原価・運用責務は別境界とする。A/Bのハイブリッドは未検証候補。
3. **Personal Contextの成果への接続**: ただ記録を増やすのではなく、許可された経験・判断・結果を他の活動の判断材料として再利用し、本人にとって有用かを測る。Contextの蓄積量そのものを成功指標にしない。

この記録は新ブランド・新規専門製品・自律改善エンジンの開発承認ではない。

## 2. 既存の正本との重複と境界

| 既存の正本 | すでにあること | 今回の差分 / 非変更 |
| --- | --- | --- |
| [Product Spec](../../CANOVIA_PRODUCT_SPEC.md) | 目標・行動・実績・伴走、FactとInterpretationの分離、外部実行手段の活用 | 初期目標の手前にある「試す」という体験を将来候補として具体化。現在のNow/Next/Futureを変更しない |
| [Personalization Spec](../../CANOVIA_PERSONALIZATION_SPEC.md)、[V58.32](../../V58.32_CONTEXT_CANDIDATE_EVIDENCE_REOPEN.md) | self_reported / observed / inferred、Context更新候補、根拠・確認・dismiss/reopen | 本人の能力や適性を確定しない体験起点の仮説検証を追加検討。現行のContextスキーマ・評価器は変更しない |
| [V47.2 Execution Distribution](../../V47.2_EXECUTION_DISTRIBUTION.md) | 人間が選ぶ既存Taskの分配、一時セッションのBundle、外部AI向けPrompt | 自動Task選択・永続エージェント担当管理は**未実装・未承認**。既存分配をその機能と呼ばない |
| [V57.8 Coding Agent Handoff](../../V57.8_OPT_IN_CODING_AGENT_HANDOFF.md)、[V46.7 GitHub Return](../../V46.7_GITHUB_RETURN_EVIDENCE.md) | 確認付き手動引継ぎ、GitHubのPR/CI/Commitを証拠として回収 | A案はここを再利用。外部チャットの完全な自動回収・自動実行を既存機能と誤認しない |
| [Developer Pro Future Design](../../future/DEVELOPER_PRO_AI_DEVELOPMENT_ORCHESTRATION.md) | Product Context / Living Spec / Coding Agent / Review / Human Approval、費用管理の構想 | A先行・B条件付き、現行の実例を通じた価値検証を強調。別のDeveloper Pro正本を作らない |
| [Platform / Ecosystem Future](../../future/CANOVIA_PLATFORM_ECOSYSTEM.md) | Account/Domain/Appの許可付きContext、Capability/Provider/Activity、専門サービス連携 | 可能性発見→専門的な小体験→Contextへの還流を将来の利用シナリオとして扱う |
| [2026-10-13戦略決定](2026-10-13_CANOVIA_STRATEGY_DEVPRO_WORLD.md) | Contextの長期価値、Release優先、Internal-first Dev Pro、World候補 | 優先順位を変更せず、体験・検証の解像度のみ上げる |

## 3. 可能性発見から実現まで（Future Experience）

想定する任意の循環:

```text
本人が許可した事実・申告・既存Context
  → 可能性の仮説（根拠・不確実性・確認可能な範囲を表示）
  → 本人の選択（試す / 保留 / 拒否）
  → 小さな体験（必要に応じて既存の外部ツールを利用）
  → 体験後の本人の振り返り + 確認できた成果
  → Context候補の訂正・更新（必要なら本人確認）
  → 任意のPlan作成・継続、または別の可能性へ
```

- 「関心がある可能性」と「能力・適性の客観評価」は別。短い体験の成否を性格診断・職業適性・能力ランクへ変換しない。
- 参加、Context共有、Plan化、継続を強制しない。拒否や体験をやめたことを失敗点・ネガティブ能力評価にしない。
- 過去の未達経験は本人が選べる再検討材料になり得るが、再提示にはdismiss/鮮度・根拠変更の規則が必要。
- **Development Workspaceは最初の社内検証候補**。他Workspaceの一般需要・課金意思・第一提供順を意味しない。Study / Career / Creative / Work等は未承認の専門体験候補。
- Conceptual 3-layer: Personal Context / Canovia Core / Specialized Workspaces。現行のWorkspaceModeと専門外部Providerの責務を上書きしない。

## 4. AI開発支援 A/B案の責務

| 観点 | A：司令塔支援（優先検証候補） | B：自律開発（将来候補） |
| --- | --- | --- |
| Canoviaの役割 | Goal/Plan/仕様/依存/承認/証拠照合を整理し、作業単位の外部AI引継ぎを用意 | Aの論理司令塔に加え、承認済み作業の起動・隔離・結果回収を管理 |
| エージェント実行 | 利用者が選んだ外部AIへ手動送信・手動結果受け渡しを許容。自動アクセスを約束しない | 接続先の公式API/契約、sandbox、最小権限、予算管理を確認して初めて検討 |
| 共通にできる概念 | Task/依存/Context fingerprint/実行指示/PR・SHA/観測証拠/判断履歴/承認 | 同左。ただし実行権限、secret、課金、監査、故障時の復旧は別の契約 |
| 完了判定 | 担当AIの自己報告と、GitHubのPR/CI/merge/deploy/device evidenceを区別 | 同じ証拠境界を維持。ツール呼出し成功だけで製品受入にしない |
| 許可 | 高影響変更・本番操作は人間の明示承認が必要 | Bでも承認を省略しない。自律Productionを既定にしない |

- A/Bを別の票台帳・Task正本・GitHub Return基盤に分岐させない。一方、現在のV47.2 Session Bundleを永続的なAgent RunやTask状態の正本と誤用しない。
- 司令塔の判断も間違い得る。報告・GitHub由来Fact・推定・推奨を分け、固定SHA/根拠の鮮度と人間レビューを保存する構想を優先する。
- 外部AIへCanoviaのPrivate Plan、cookie、GitHub token等を無制限に渡さない。現行のactor-bound認可と明示同意を前提にする。

## 5. Personal Contextの信頼性原則

1. **区分**: 本人申告、外部・内部で確認した事実、AI推定、本人が認めた解釈、判断履歴を混同しない。GitHub活動の観測は著者本人・技術能力の証明にならない。
2. **来歴と鮮度**: source、scope、観測時点、更新時点、revision、confidence、関連Evidence、利用目的・同意範囲を必要最小限で扱う。confidenceの高低は自動適用許可ではない。
3. **訂正・削除と依存関係**: 本人による訂正・撤回・アクセス停止を想定し、派生した推定・推薦・キャッシュにも影響を及ぼす。将来の横断Contextの実現は別途設計・安全審査が必要。
4. **本人の主体性**: AIは可能性を提示できても、進路や才能を断定・強制しない。拒否・スキップ・活動休止は本人の選択。
5. **有用性の測定**: Context再利用によって説明の再入力、判断負荷、体験開始の摩擦が実際に減るかを評価。蓄積量、滞在時間、起動回数だけを成功指標にしない。

## 6. 価値とコスト：判断は保留する仮説

| 仮説ID | 未検証の価値仮説 | 将来測定する候補（今回の計測実施なし） |
| --- | --- | --- |
| H01 | A案で複数AIの調整負荷や仕様の手戻りが減る | 調整時間、報告とGitHub証拠の不一致、PR受入率、再作業時間 |
| H02 | 小さな体験が単なる診断より自己理解・次の自発的行動を支える | 体験選択率、完了後の本人評価、任意Plan作成、後日の自発的継続 |
| H03 | 許可付きContext再利用が継続的な価値を生む | 再説明削減、本人が有用と評価した提案、誤推定・訂正率、信頼 |
| H04 | B案の実行原価を十分な成功率・手戻り削減で正当化できる | 実行あたり総原価、再試行、レビュー労力、受入成果あたり原価、収益性 |

- 競合には並列Coding Agent・タスク/PR管理がすでにあるため、**「並列AI」それ自体を独自優位と主張しない**。差別化仮説はContextの正しい再利用と意思決定の継続性。
- 1日1億トークン規模は極端利用を想定した**未計測のストレス仮説**であり、既存ChatGPT利用をAPI請求に換算した実績ではない。価格、課金枠、API原価、BYOK/従量モデルは未承認。
- AI不要な処理の決定論的実行、目的別Context取得、モデル別総コストと再試行上限は将来の検討方向。GitHub/Codex等の公式連携・利用規約を確認するまで自動送受信を約束しない。
- 低頻度・低コストに成立する価値を先に確かめ、B案のインフラ、Secret運用、実行予算・停止条件は後続の独立承認に委ねる。

## 7. 記録分類と非目標

**既存方針の具体化として保存**: 可能性の仮説→小体験→本人の振り返り→任意Plan、Contextの改善効果を測る原則。

**Future Designとして保持**: A先行/B条件付き・同じ司令塔論理契約と実行セキュリティ境界の分離、専門Workspace連携の概念。既存Developer Pro / Platform Future Designを正本として参照する。

**未検証の仮説**: H01〜H04。成果が出る、需要がある、費用削減が可能、継続率が改善するとは主張しない。

**今は採用しない**: 新たな固定料金・プラン権限、自律本番変更、適性ランキング、無制限のContext収集、開発Workspace以外の実装順序確定、正式ブランドコピー、MVP/公開目標の変更。

将来表現候補「まだ知らない自分の可能性に出会い、それを確かな未来へ育てていく場所。」は**仮案**。公開コピーとして承認しない。

## 8. 優先順位・再検討条件

- 既存の[戦略決定（2026-10-13）](2026-10-13_CANOVIA_STRATEGY_DEVPRO_WORLD.md)に従い、**Coreの安全性・公開準備 → 軽量なDecision Memory → 内部での価値検証 → 条件付き拡張**の順序を維持する。
- 2026-12-01の初期公開準備目標、Nativeの移行/保護/実機セキュリティHOLD、既存のCI・本番承認ゲートを変更しない。公開時期の達成を保証する記録ではない。
- 再検討トリガー: MVP安全受入後に検証余力ができたとき、A案の実測効果があるとき、本人の体験による有用性が観察されたとき、Context許可・訂正・削除を安全に成立させられるとき、接続先の公式仕様・競争・原価が変化したとき。
- **本記録の作成は実装・検証開始の承認ではない**。着手時は最新main・AGENTS.md・Active Specを再評価し、Product Ownerが範囲・測定・安全・費用を明示決定する。

## 9. 判断履歴と変更範囲

- 元の入力: Product Ownerによる「Personal Context × 可能性発見 × AI開発支援」構想、および司令塔による既存仕様との評価。
- 今回の承認: 評価後に**重複を避けて長期方針を整理・記録することのみ**。
- 実施しない: 新Issue、コード変更、実行系テスト/検証、CI起動、ランタイム設定変更、既存ロードマップ変更、課金変更、GitHub main統合、Render/Aiven/Apple操作、公開Futureカード変更。
- 将来の評価は、上記仮説を検証する**別の明示指示**を受けてから行う。
