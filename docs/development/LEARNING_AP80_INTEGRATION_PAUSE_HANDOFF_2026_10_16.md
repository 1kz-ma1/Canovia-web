# Learning / AP科目A80問 — 統合前の開発停止・再開引き継ぎ

> **状態: PAUSED / INTEGRATION HANDOFF（新規機能実装停止）**  
> 作成日: 2026-10-16。調査基準 GitHub development `main`=`eae0cd4fb653af92ebf93fb56b0071b63714a35d`。**再開・統合時は再照合必須**（mainは並行開発で移動する）。  
> 担当: **Learning / AP科目A問題品質・未公開80問候補の編集/監修ゲート**。調整: Orchestrator（開発/本番差分とRelease）、Platform（DB/公開）、Experience（学習UI）、Intelligence（理解度/Context）。  
> 本文は**既存V6.24–V6.28の実装手順を再記述しない**停止時点のインベントリ・判断・依存・再開ゲート。リンク先各仕様が詳細のSource of Truth。

## 1. 担当領域・目的と今回の停止境界

目標は、AP科目A演習向け未公開80問候補の内容精度、解説、誤答選択肢、利用権、学習用途を**問題ごとに確認し、別版のDraftに安全に修正を反映できる準備**を整えること。「問題が正しいこと」と「正答から測定できる知識・能力・診断力」を区別する。

- 今回**停止するもの**: 問題の新規生成/改訂の実装、レビュー自動化、問題集や本番環境への取り込み、Learning運用ルールの変更。次の問7の審査も未着手のまま引き継ぐ。
- 許可された今回の成果物: **docs-only統合引き継ぎ**。既存コード、Question Pack JSON、審査メモ、DB、認証/セッション、環境変数、Render/Aiven/OAuth、PR #513を変えない。
- 完了の意味: AP問題審査**準備機能がmainに存在すること**。80問に独立した専門・権利承認があること、v0.5実ファイルが作成されたこと、Render/実機で機能すること、試験相当の正式品質を証明したことを**意味しない**。

## 2. CURRENT / ACTIVE — 実装済みと検証済みの範囲

| 領域 | 開発`main`上の実装 | 対応証拠/注意 |
| --- | --- | --- |
| v0.4未公開候補 | `resources/question_packs/ap/ap-a-2026-cbt-80-candidate-v2.json`。slug `ap-a-2026-cbt-80-candidate-v2` / version `0.4.0-review-required`、80問（`official` 35、`canovia_original` 40、`derived` 5）、`downloadable=false` / `pending_human_content_and_rights_review`。原本Git blob SHA-1 `d597503caf4588bfd829cf1ed45d0b31a1e39b1e`。 | [#437](https://github.com/1kz-ma1/Canovia-web/pull/437)、[#449](https://github.com/1kz-ma1/Canovia-web/pull/449)など。原本は変更しない。 |
| 審査待ち台帳 | 80問に5観点（正答・誤肢・解説・難易度/重複・権利/出典）。P0:6、P1 IPA:35、P2 Canovia:39。管理者向け `GET /admin/question-packs` に読取審査キュー。 | [#450](https://github.com/1kz-ma1/Canovia-web/pull/450)、[V6.24](LEARNING_AP80_HUMAN_REVIEW_NOTES_V6_24_2026_10_15.md)。Admin画面の存在は本番反映の証拠ではない。 |
| V6.24 | `study:ap80-review-notes`。80行・正規化したQuestion/Pack SHA-256・状態の構造検査。偽承認/古い入力を拒否。 | [#728](https://github.com/1kz-ma1/Canovia-web/pull/728)。本人確認済みの独立監修にはならない。 |
| V6.25 | `study:ap80-revision-compare`。`needs_revision`に指定された**Canovia由来**問題の `prompt` / `explanation`のみ仮想比較。正答、選択肢、出典/タグ等の編集とIPA公式問題の編集は拒否。 | [#732](https://github.com/1kz-ma1/Canovia-web/pull/732)、[比較仕様](LEARNING_AP80_REVISION_RECHECK_V6_25_2026_10_15.md)。内容SHAと5観点の再審査要求を維持。 |
| V6.26 | `study:ap80-prepare-new-draft`。V6.24/25の適切な入力を受け、別slug `ap-a-2026-cbt-80-candidate-v3` / `0.5.0-review-required`をメモリ上で組み立てる。任意の`--write`はlocal/testingのprivate領域に新規・非上書き保存のみ。 | [#736](https://github.com/1kz-ma1/Canovia-web/pull/736)、[新Draft仕様](LEARNING_AP80_SAFE_DRAFT_V6_26_2026_10_15.md)。**本物の審査結果を使った実v0.5ファイル作成は未実施**。 |
| V6.27 | オーナーが別AIチャットで検討・提出した**P0 6件の一次編集メモ**、既存Canovia問題4件の文面/解説案、新規応用問題6案を専用JSONに保存。 | [#761](https://github.com/1kz-ma1/Canovia-web/pull/761)、[記録原文](../learning/review_packets/AP_A_80_OWNER_FIRST_PASS_6Q_V627_2026_10_15.json)、[詳細](LEARNING_AP80_OWNER_FIRST_PASS_V6_27_2026_10_15.md)。追加問題・4修正案とも未公開、独立確認なし。 |
| V6.28 | `study:ap80-review-wave --batch=1..8`。元v0.4とV6.27記録にGit blob SHAで結び、P1 35問+P2 39問を10/10/10/5 + 10/10/10/9の**8バッチ**へ分割。番号/ID/出典/内容ハッシュのみ出力。 | [#763](https://github.com/1kz-ma1/Canovia-web/pull/763)、[Wave運用詳細](LEARNING_AP80_OWNER_REVIEW_WAVES_V6_28_2026_10_15.md)。問題本文/解答/解説はCLIへ出さず、承認状態を変えない。 |
| 関連するLearningの回答保存 | 別経路の従来外部AI演習では、1問の**途中ドラフト保存**と採点済み`StudyPracticeAttempt`を分離。Chromium合成E2Eで204/419・リロード復元を検証、専用Mac Safariラボを準備。 | [#765](https://github.com/1kz-ma1/Canovia-web/pull/765)、[#766](https://github.com/1kz-ma1/Canovia-web/pull/766)、[実機手順](LEARNING_V621_THIRD_PINNED_MAC_SAFARI_ACCEPTANCE_2026_10_15.md)。**Mac Safari実操作/iPhone/本番の受入は未完**。AP80審査とは別スライス。 |

**検証の粒度:** V6.27のPR headでは専用CIが**35 tests / 5,521 assertions**、V6.28のPR headでは**39 tests / 6,939 assertions**（V6.24–V6.27を含む回帰）、どちらもCIの関連5系統が成功。これらは**合成データ・使い捨てSQLite・コード不変性**の検証であり、出題内容の専門的正確性、原著作物の権利、実運用Web/デバイスを保証しない。統合担当者は最新headのCIを再取得すること。

## 3. 停止時点のレビュー実績・未完了事項・既知の制約

### 真実の進捗（異なる分母を混同しない）

| 指標 | 停止時点の観測 |
| --- | --- |
| V6.27 JSONに固定した一次編集メモ | **6/80件**（問13、24、34、48、61、68。全てCanovia由来P0） |
| その後チャットで受領・この引き継ぎに所在だけ記録する追加評価 | **3/80件**：80問中問1 `ipa-2025-autumn-ap-am-q03`（ROC）、問3 `ipa-2025-autumn-ap-am-q04`（CRC）、問5 `ipa-2025-autumn-ap-am-q05`（Best Fit）。**GitHub JSONへは未転記** |
| 会話内の一次判断の累計 | **9/80件**。未提出71件（IPA 32 / Canovia 39） |
| 専門性・本人性・利用権を別々に検証した正式承認 | **0/80件**。V6.24台帳の80行・400観点は正式な独立承認完了を示していない |
| 次の一次審査対象 | **80問中の問7 / `ipa-2025-autumn-ap-am-q06`（ブロック探索・平均比較回数）**。案内済みだが評価結果は**未受領** |
| V6.28のCLI上の未評価残数 | **74件のまま**。6件記録を基準とした固定バッチ出力であり、会話上の追加3件を取り込んだ進捗管理機能ではない。単純に「バグ」とは扱わない |

### 追加3件の引き継ぎ判断（原題は維持）

- **問1 ROC:** IPA問3、正答イを維持。Canovia解説で選択肢アを単なる「適合率系」でなく**偽省略率 `FOR=FN/(FN+TN)`**と明示したい。新問候補: ROCの2点比較（TPR/FPR）。原題の変更提案なし。
- **問3 CRC:** IPA問4、正答ウ維持。解説に生成多項式の次数と検査ビット長、検査ビット込みの受信データを除すること、**余り0≠無誤りの保証**を追加する案。新問候補: GF(2)剰余計算、検出できない誤りパターンの**2件**。
- **問5 Best Fit:** IPA問5、正答イ維持。選択規則/探索順序/管理データ構造/外部断片化を明確化。新問候補: First/Best/Worst Fitの連続割当て1件。

上記はオーナーが外部AIの評価を確認してチャットへ提出した**参考一次メモ**。本人性を証明した独立専門監修、IPAからの個別許諾、正式な転載品質の保証ではない。元の公式問題文・選択肢とCanovia独自解説を区別して再検証する。会話の評価全文や追加問題全文を、承認済み教材としてGitや実DBに昇格させない。

### 未完了・リスク（未確認事項は不具合と断定しない）

1. **内容レビュー**: 未評価71件。残存P1/P2の原典、正答、独自解説、誤肢一意性、重複、適切な難度・学習用途を継続して確認する必要あり。真のIRT/選択肢別統計は未測定。
2. **利用権・出典**: IPA公式35件の過去問原文、表記調整、学習者画面の出典、権利条件を公開用途ごとに確認する。出典メタデータやIPA FAQの存在だけで自動承認しない。
3. **実修正・版更新**: Gitにある4修正案は**候補資料**。実際のV6.24審査記録＋V6.25修正提案を使ったv0.5私有ファイルは作っていない。元v0.4は変更しない。
4. **IPA由来問題の解説改訂経路**: 問1/3/5は**IPA問題IDのまま解説のみ修正したい**という案だが、**現行V6.25はIPA source questionの編集（解説だけでも）を拒否**。現行のCanovia4件用フローを迂回してIPA編集を通してはいけない。再開時に独立の著作/解説境界、原文不変、監修とSHA再発行を設計するかの判断が必要。
5. **新しい問題集への採用**: Git内の応用6件に加え、会話内の応用4件は未監修候補。現行80問へ自動追加しない。別ID/別用途の問題集合、難易度/測定対象の検証が必要。
6. **UI/実機**: Adminキューはread-only。新しいAP80の学習者向け公開実績はなし。従来外部AI演習のMac Safari実機保存・復帰も未証明（Chromiumの合成E2Eと区別）。
7. **不具合管理**: 本調査で**AP80スライスの再現済み新規実行時エラーは特定していない**。未レビュー問7の「平均比較回数の近似/定数項」は**次回の審査観点**であり、確認前に不具合・正誤として登録しない。

## 4. PR・ブランチ・SHAと統合先（主要証跡）

| PR | 対象 / ブランチ（代表） | development `main`への状態 / squash SHA |
| --- | --- | --- |
| [#728](https://github.com/1kz-ma1/Canovia-web/pull/728) | V6.24 / `feature/learning-v624-ap80-human-review-intake-20261015` | merged `9cba4286921eb1eb66fb7def966d6886cc9f4848` |
| [#732](https://github.com/1kz-ma1/Canovia-web/pull/732) | V6.25 / `feature/learning-v625-ap80-revision-comparison-20261015` | merged `554187d055134a4b2a8b7daedbf9ae6de7da84e3` |
| [#736](https://github.com/1kz-ma1/Canovia-web/pull/736) | V6.26 / `feature/learning-v626-ap80-new-draft-packaging-20261015` | merged `cf1e6382f9711bac0e84f8e236ab107dd6693997` |
| [#761](https://github.com/1kz-ma1/Canovia-web/pull/761) | V6.27 / `feature/learning-v627-ap80-owner-review-rebased-20261015` | merged `9bd3376896a6a428d5dcffe5f576c26cee0a76e8` |
| [#763](https://github.com/1kz-ma1/Canovia-web/pull/763) | V6.28 / `feature/learning-v628-ap80-remaining-review-waves-20261015` | merged `0209bfe29b9c5daeeab5a7c4cc3e5ed8c1e0c777` |
| [#760](https://github.com/1kz-ma1/Canovia-web/pull/760) | V6.27旧PR / `feature/learning-v627-ap80-owner-editorial-review-packet-20261015` | **closed, NOT merged**。競合回避のため#761で置換。後から二重統合しない |
| [#765](https://github.com/1kz-ma1/Canovia-web/pull/765), [#766](https://github.com/1kz-ma1/Canovia-web/pull/766) | 別Learning領域: 初回ドラフト保存UIとMac Safari受入環境 | merged `ce3c57dd3fbfcbef606d32920822d1c9b8eaf72b`、`eae0cd4fb653af92ebf93fb56b0071b63714a35d`。実機受入は別途 |

**PR/branch状態はスナップショット**。残っているfeatureブランチは作業中/未統合を意味しない。今回確認時、AP80のopen PRは存在せず、別途リリース [#513](https://github.com/1kz-ma1/Canovia-web/pull/513) (`release/2026-10-13-bb8248f` → `production`) が**未マージのDraft / NO-GO**。統合判断では`main` SHA、PR merge SHA、branch名、Renderの実Live SHAを混同しない。

## 5. 本番反映・DB・認証・環境・他領域への依存

**2026-10-16 read-only照会の観測**:
- GitHub `production`=`15abdc9de6f24b5250fb255c579d1aa55facaf1a`。
- Render **Canovia Web** は`branch=main`、`autoDeploy=no` / `autoDeployTrigger=off`、最新の`live`デプロイは**`15abdc9de6f24b5250fb255c579d1aa55facaf1a`**（2026-10-11のデプロイ）。v6.24–v6.28、v6.21近傍の開発用マージをRenderに適用した実績ではない。
- **要注意の差分**: AGENTS.mdのリリース方針は「将来`production`のみをRender Auto Deploy対象にする」。**現在のRenderサービスはまだ`main`参照**かつOFF。初回手動デプロイと実機受入を済ませる前にONへ変更しない。ブランチ切替、初回本番Deploy、復旧・監査はPlatform/Releaseの別決裁事項。Canovia-Welcome staticもOFFのまま。
- **DB**: AP80レビュー用CLIはlocal/testingのみ、Repo resourceと編集者のローカル入力を参照。専用CIは合成SQLite/必要に応じ使い捨てSQLiteテストDBだけ。実際のAiven MySQLへの接続・スキーマ/データ変更/Importは未承認・未実行。将来のQuestionPack Importや`QuestionPackPublicationReadinessService`は本物のsource+rights+内容SHAを確認した後の**別段階**。
- **認証/権限**: `GET /admin/question-packs`の既存管理者認証と権限チェックに依存。読み取り専用キュー/CLIは審査者本人性・独立性を証明せず、V6.24の`reviewer_id`やrights evidence refは自己申告フィールド。権限追加、ユーザー/Canovia個人Contextの外部共有、OAuth変更は本スライスの対象外。
- **環境変数/鍵**: 新たな本番`.env`項目を導入していない。`APP_ENV=local/testing`限定、CIは`DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`, `CACHE_STORE=array`, `SESSION_DRIVER=array`, `NATIVE_AI_DRIVER=disabled`を使用。APIキーやDB URLを審査資料・ログへ出さない。
- **Experience**: 学習「今やること」と演習起動/モード切替の共有UI、Web/モバイル/403・419の表示と復帰に回帰リスク。実UI表示と原問題集リリースは独立。
- **Intelligence**: Canovia Learning Context Snapshot/Watch、Task Evidenceとの結合・理解度推定。正答=習熟確定とせず、本人確認/Source of Truth/自動通知OFF条件を維持。
- **Platform/Release**: 本番マイグレーション19件の固定SHA監査、DB整合性・リストア不確実性、Session/CSRF、entitlement/cost・iOS WebView。AP80資料の存在だけでリリース判定やQuestion Pack`publishable`を変えない。
- **別の2026 AP公式シラバス経路**: [V6.20](https://github.com/1kz-ma1/Canovia-web/pull/713)–[V6.23](https://github.com/1kz-ma1/Canovia-web/pull/722)の出典/カバレッジ照合と独立審査は、**2025秋のIPA原題35問の出典/権利監修とは別**。機械的なタグ照合を「2026試験全範囲に正式対応済み」と推定しない。

## 6. 統合時のリスクと必須の検証

| 優先 | リスク | 統合担当者の必須確認 |
| --- | --- | --- |
| P0 | 開発mainと本番の差異、誤Auto Deploy | 最新main・production・Render liveの**3つのSHA**とAuto Deploy設定をread-onlyで照合。公開操作は行わない。 |
| P0 | 監修メモの「承認」への誤格上げ | 6件のGit編集メモ + 3件の会話所見と、本物の0件の承認を区別。`review_state`, rights/status, `downloadable`, `can_publish`が未承認のままか確認。 |
| P0 | v0.4改変/現行版とreview hash不一致 | Git blob `d597503...`とv0.4 80行、35/45出典、80個のcanonical per-item hash、凍結された正答/選択肢/出典の不変性をテスト。 |
| P0 | IPA改訂経路の誤用 | V6.25で**公式問題の解説だけでも編集不可**、元問題文/選択肢/公式正答とCanovia書き下ろし解説を明確に分離。権利/出典の再確認なく変換・公開しない。 |
| P0 | 本番DB、Auth/Session、個人学習証拠への副作用 | MySQL 8.0/8.4使い捨てrehearsal・release監査、Login/403/419、actor/Planスコープ、権限/Evidence権限を所定のRelease手順で再試験。**本引継ぎ作業で実行しない**。 |
| P1 | 審査CLIと本番UIの取り違え | ローカル`study:ap80-review-wave --batch=1`で番号/ID/hashだけ確認。Admin画面が本番でも見えると断言しない。 |
| P1 | 旧外部AI演習と新LearningRun混同 | 合成Chromiumの1問draft 204・419・リロード復元、採点済みAttemptの生成時点、Task進捗のユーザー確定、別個の`LearningRun`回帰を確認。Mac Safari/iPhoneは別途実機検証が必要。 |
| P1 | 公式模試相当性の過大主張 | 80問の構成、IPA 2026シラバス/形式、時間、未見問題・重複・解説/権利を独立検証。実受験者データのない推定難易度を統計的識別力と呼ばない。 |

**再実行テスト（コード変更が認められた将来のみ）**: `ApExamCandidateOwnerReviewWavesV628Test`, `ApExamCandidateOwnerEditorialPacketV627Test`, `ApExamCandidateDraftPreparationV626Test`, `ApExamCandidateRevisionComparisonV625Test`, `ApExamCandidateHumanReviewIntakeV624Test`, `ApExamCandidateQualityReviewTest`, `ApExamCandidateRevisionPreviewTest`, `ApExamCandidateBundleAuditTest`。dedicated `.github/workflows/learning-v628-ap80-review-waves.yml`と全Required CIを**正確なPR head**で確認する。Release時は[development workflow](../CANOVIA_DEVELOPMENT_WORKFLOW.md)のproduction-mysql-integrity、V56.12 Workspace Resume、Production Migration Recovery、iOS/Web Smoke、実Live SHA確認などを適用。

## 7. 再開順序（Product ownerが明示的に再開を指示した場合のみ）

1. **最初に**この文書の`PAUSED`を尊重し、Orchestratorが最新`main`、`production`、Render Live、`AGENTS.md`、関係PR/CI、既存問題/rights/evidenceのSHAを再取得。今回のSHAを「現在」に流用しない。
2. **会話所見の保存範囲を決める**: 問1/3/5の一次編集所見をv0.4の出典と照合して別の未承認編集資料へ記録するかオーナーが判断。既存V6.27記録やV6.28バッチを勝手に「9件承認」に書き換えない。
3. **審査を継続**: 問7（ブロック探索）から、IPA残32件→Canovia39件を進める。各問の誤答一意性/解説/難易度/測定能力、公開時の出典表示・利用条件を確認。必要なら独立専門家/権利担当の監修体制と証拠保存方法を先に決定。
4. **修正経路を分ける**: Canovia P0既存4件の`prompt/explanation`提案はV6.24実記録→V6.25差分検証→V6.26**新未承認**Draft。IPA35件は公式原文不変と独自解説の扱いを別途仕様化・審査するまで元ファイルを触らない。
5. **応用問題は別審査**: V6.27 JSONの6候補＋チャット由来4候補を、別IDと測定目的・原典/権利・選択肢検証のある未公開候補として評価。80問に自動合流しない。
6. **品質完了後も別Release審査**: 監修者本人性と著作/権利、canonical SHA/DB版、必要な管理者Import/公開承認、公式試験プロファイル、プラン/セッション/デバイス回帰を完了。`release/*`を固定しAiven/Render監査、オーナー本番GO/NO-GO後に初回手動Deployと物理iPhone/Web Smokeを実施。現時点の[#513](https://github.com/1kz-ma1/Canovia-web/pull/513)はGOではない。

## 8. 統合仕様に移す設計判断 / FUTURE DESIGN と非採用事項

| 区分 | 判断 |
| --- | --- |
| **CURRENT / ACTIVE の拘束** | 問題セットの`mode`（基礎/診断/模試）と問題`source`（公式/Canovia自作）を混同しない。`QuestionPack`公開可否・`response_schema/grading_rule`・`source_reference`・内容SHA/人手審査を破壊せず互換維持。 |
| **CURRENT / EDITORIAL（未反映）** | 「原題を残し、解説を改善し、必要なら応用問題を**別ID**で追加」。P0 6件、IPA 3件の一次編集所見は採用判定ではなく案。実問題集の出題方針・タグ/習熟度状態はまだ変更していない。 |
| **FUTURE / CANDIDATE** | 各問題に`assessed_skill`や用途（基礎、弱点、実戦）、誤答肢ごとの仮説的弱点、追試の結果と診断確信度、出題ランクを持たせる。**誤答1回で弱点確定せず、正答1回で理解を保証しない**。実装/API/schemaは未確定。 |
| **FUTURE / CANDIDATE** | 公式原題とCanoviaが独自に記述した解説の個別版管理・ライセンス表示・差分監修の安全なワークフロー、変更時に審査/承認をリセットする統合審査UX。未実装。 |
| **FUTURE / CANDIDATE** | 80問模試向けの未見問題分離、問種/習熟度/出題頻度、公式試験仕様との実測較正、適応出題と試験再現モードの分離。構想だけで「2026 AP公式準拠」の公開文言にしない。 |
| **NOT IN SCOPE** | DB triggerによるLearning Contextの自動Watch、無断AI出題/公開、自動通知、OAuth拡大、外部教材の無審査取込、権利/独立監修のAI自己承認、Render Auto DeployやAivenスキーマ変更。 |
| **設計の権威** | Latest mainの実装→[Product Spec](../CANOVIA_PRODUCT_SPEC.md)→[Adaptive Learning Experience暫定仕様](../learning/adaptive-learning-experience-draft.md)と本書/関連V6.xx→[Future Architecture](../future/CANOVIA_FUTURE_ARCHITECTURE_OVERVIEW.md)。`docs/future`だけで開発再開しない。 |

## 9. 引き継ぎのチェックポイント

**最初の5分**: `AGENTS.md`確認 → `main` HEAD/production/Render Liveを比較 → PR #728/#732/#736/#761/#763と#513を照合 → v0.4 sourceのGit blobとreview pendingを検証 → Chatの追加3件はGit未反映、次の問7は未評価と認識する。

**停止条件の継続**: オーナーによる再開指示が出るまで新機能実装・未検証PR強制マージ・公開・DB/Secrets/OAuth/Render変更を行わない。今回の引き継ぎ文書のみを統合用参照点とし、旧V6.24–V6.28詳細、既存GitHubのhistory/運用docsを維持する。
