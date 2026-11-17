# Future Online Support — P01–P08 製品判断パケット

2026-11-17 JST。状態: **PROPOSED / P01–P08すべてOPEN**。
**SECURITY_BLOCKED / PRODUCTION_NO_GO_HOLD / release_approved=false**。

## 今回確定したことと判断待ちのこと

A案（既存Laravelの候補・票台帳を単一正本とする構成）は優先アーキテクチャとして承認済み。ユーザーは、iOS #46 `ceeb2449b7a227d5fa6e693a1d0758c389ce690f` + Web #923 `a7b2a1a32b07b78e7f67df46f060535dcdbf1b4f` のLinux隔離統合結果を **PASS_SCOPED** として受け入れた。共有投票・取消・重複要求・応答喪失時GET再照合・server再起動保持に限る。Mac/Simulator/Keychain/ATS/live TLS/本番DB/製品利用の認定ではない。

機能実装は一区切りとして停止。本資料は全体管理側の判断材料であり、製品仕様の採択、API変更、migration実行、製品UI接続、main統合、本番有効化を行わない。両PRはDraft。本資料の新HEAD組合せを実行済みPASSとは呼ばない。将来案のテストはすべて **NOT_IMPLEMENTED / NOT_RUN**。

第一候補は **C2: 明示的な支持意思に期限を持たせる「現在の支持」**。credential更新は認証手段の継続、支持の再確認は個々の候補への意思表示として分離する。credentialが90日で切れても票が無期限に残り、本人の取消経路がなくなる現状を、製品仕様として無条件に承認しない。

既存契約: [F-0 v1](FUTURE_ONLINE_SUPPORT_2026_11_16.md)。決定状態の正本: [A案の承認記録](decisions/2026-11-17_FUTURE_SUPPORT_LARAVEL_AUTHORITY.md)。ここでのC2や日数例は承認済み仕様ではない。

## 現行実装の観測

| 項目 | 固定SHA時点の挙動 / 不足 |
| --- | --- |
| Native credential | 発行から90日の絶対期限。hashのみserver保存。期限後は読取・支持・取消・revokeとも401。更新・rotation・復旧なし |
| 集計 | Native `SupportAuthority`、Web `CanoviaFutureController`とも`votes`の行数。credential期限による除外なし。自分の票にも同じ期限問題がある |
| Web identity | guestはsession/encrypted cookie由来、cookie寿命は2年。登録利用者は`user:{id}`。Nativeは`native:{serverUUID}`。cookie寿命、login session、支持意思の期限は同一ではない |
| 台帳 | `roadmap_votes`のunique(feature,voter_key)。支持意思の期限・再確認・世代・revision・取消履歴はない。firstOrCreateの繰返しを再同意とみなせない |
| 個別取消 / revoke | 個別取消は非公開・投票停止候補で拒否。revokeは自分の全票を削除し失効させるが認証metadataは残る。再revokeは401で、応答喪失後の完了証明にはならない |
| account削除 | 現行`AccountDeletionService`は`user_id`により`roadmap_votes`を明示削除する。将来linkした匿名票の所有者mappingにもこの削除責務を継承する必要がある。旧来の不整合行の有無は未調査 |
| Native interface | expires_atをdecode検証後、Credential/Stateには保持しない。hasActorはlocal credentialの存在。ViewModelには更新・復旧・削除・統合の入口がない |

観測元: `app/Services/FutureSupport/SupportAuthority.php`、`app/Http/Controllers/CanoviaFutureController.php`、`app/Services/BehaviorIdentityService.php`、`app/Services/AccountDeletionService.php`、`experiments/FutureSupport/database/credentials.php`、`database/migrations/2026_09_23_000700_create_canovia_future_tables.php`。本番データの件数・内訳は **UNKNOWN**。default OFFを根拠に「移行対象なし」と推定しない。実データ読取・削除・移行は今回行わない。

## P02: Support数の意味を比較する

| 案 | 意味 / 利点 | 期限・紛失・統合の課題 | 評価 |
| --- | --- | --- | --- |
| H0 累積した過去の支持 | 過去の意思表明を保存。変動が小さい | 取消不能票、重複identityが累積。「現在の支持」とは表示できない。別途保持期限・削除方法が必要 | 現行の無期限・取消不能状態をそのまま製品承認しない |
| C1 credential有効中の票 | 認証期限で集計を絞りやすい | credential更新だけで全候補が延長される。Web guest/登録accountとの不均衡。復旧で古い票が復活し得る | 比較対象。第一候補にしない |
| **C2 支持意思に期限** | 候補ごとの明示支持/再確認から有効期間L。受動的な利用と支持を分離 | 更新・再確認・取消・世代の契約と共通集計が必要。期限でcountが減る説明も必要 | **第一候補、未承認** |
| C3 accountで取消まで継続 | 回復可能なaccountと削除経路を前提に継続意思と定義 | account必須の障壁。休眠時の意味・保持期限が別途必要。匿名利用の問題は解決しない | account中心へ方針変更する場合の比較案 |

C2の **L=90日** は比較用の初期案にすぎない。現行credentialの90日と自動的に同じ値にしない。全identityに同じLを適用する案を第一候補とし、匿名だけ短い期限等を採るなら差と理由を別途決定する。「現在」は一人一票の本人性や現在の人口を保証する表現ではない。

### C2の整合性条件（採択した場合の提案）

server時刻tで、明示支持があり、取り消されず、支持期限内で、所有者の有効な世代に属し、削除/失効/統合済みでない票だけを数える。匿名票の有効終端は少なくとも `min(support_intent_until, actor_control_until)`。tが終端と等しければ除外する。登録accountはlogin session期限ではなくaccountの状態と支持意思期限を使う。Web guestのserver側control期限は別途必要で、2年cookieを支持意思と読み替えない。

- 一つのLaravel台帳でWeb/Nativeのcount・本人状態・取消・進捗を同じ規則へ揃える。Nativeの一覧だけfilterする変更では成立しない。
- GET、画面再表示、login、credential更新、同じ要求の再試行は支持の再確認ではない。既に支持済みの通常set(true)も、専用の明示再確認なしに期限を延長しない案とする。
- 有効なcredentialのrotationは同じactor/voter_key/世代を維持する。credentialが一度失効期限に達した場合の復旧は新世代とし、旧世代の票を復活させない。
- 例: credentialが90日目まで、80日目に再確認した支持意思が170日目まででも、90日目にcountから外れる。95日目にcredentialを復旧しても旧票は復活せず、改めて個別の支持確認を要する。epochまたは同等の失効記録が必要。
- 取消・期限到達による集計除外は即時。物理削除jobの遅延でcountへ戻さない。全票削除・account統合・backup復元でも同じ条件を守る。
- 同じoperationのreplayは期限を延ばさない。後発の取消より古い支持要求が届いても復活させない。operation IDとexpected revision等で順序/重複を判定する。
- countは観測時点の値。異なる時点の応答同士の同数は保証しない。同一DB・同一as-of時刻で共通規則の一致を検証する。

## P01 / P04 / P05: credentialの継続と紛失

| 場面 / 選択肢 | 推奨する比較基準 / 残る判断 |
| --- | --- |
| 期限前更新: 同一token延長 | 同actorを維持しやすく応答喪失も扱いやすいが、漏洩secretの寿命を延長する。支持期限は延長しない。無期限rolling更新にするか、絶対上限も設けるかOPEN |
| 期限前更新: token rotation | 同actorでsecretを交換できる。旧token失効と新tokenのvault保存・応答喪失を設計する必要。hashのみのserverでは、通常のreceiptだけで失った新token本文を再取得できない。二段階確定または限定的な安全な再受領方式の別設計が必要 |
| 更新をしない | credential90日で操作権を失う。C2なら票も期限で除外できるが、削除と再同意の経路は依然必要。期限通知だけでは解決しない |
| 期限後の限定grace G | G=0と、例として30日の削除/復旧専用期間を比較する。日数未承認。期限切れtokenを通常APIへ再許可せず、保有証明・scope・rate制限を限定。revoked/deleted/mergedの復旧は禁止 |
| grace内で復旧 | 同じ所有者の回復と新しい世代の開始。古い支持の再有効化とは分ける。期限後のtokenだけで許される操作と、追加proofを要する操作をsecurityレビューする |
| token紛失 / grace外 | UUID・installation_id・client user_id・cookieの一致を所有権証明にしない。別途発行した回復proof/accountがなければ対象指定の削除や統合を許可しない。C2の自動失効・有限保持により残存を上限内に抑える |
| 再install: credential残存 | serverで有効性を確認し同actorを利用。Keychainが残る/消えると約束しない。locked/read errorを紛失とみなさない |
| 再install/別端末: credentialなし | 明示同意後の新actorは別identity。旧票は遠隔から紛失を検知できず、有効終端まで残り得る。暫定的な同一人の重複をゼロと保証できない。自動作成・旧所有者の推定linkは禁止 |

更新時点・同意（手動/前景更新）・期限予告・期限後案内はP01の製品判断。background更新を支持の延長にしない。401やtimeoutを契機に黙って新actorを発行しない。端末時計ではなくserver期限を使い、時計差は表示上の補助に限る。

## P03 / P04 / P07: 取消・削除と保全

第一候補は、公開/投票停止とは独立して本人の支持撤回を可能にする方式。非公開候補でも所有者がstable key等の正当な参照を持つ場合に、最小限の操作receiptだけを返し、他者の存在・非公開本文・票数を漏らさない。閲覧資格と撤回資格を分けるAPI設計が必要。

| 操作 | 提案する効果 | 注意点 |
| --- | --- | --- |
| 個別取消 | 即時に現在countから除外。取消revision/必要最小のtombstoneを保持 | credential自体は維持。後着の古い支持要求で復活させない |
| 全票削除 | 所有する全候補の票を除外・削除 | 非公開/停止候補も対象。匿名actor失効と同時か、操作権を残すかを明示選択 |
| credential revoke | 操作権を停止。全票除外/削除との関係を仕様で固定 | 現行は全票削除込みだがmetadataは残る。「全情報消去」と表示しない |
| 識別metadata等の消去 | 定めた期限でhash/ID/receipt/log/backupを処理 | 受付、active store完了、backup期限到来を区別。削除receipt自体の最小保持も必要 |

期限切れ後の取消経路は、(a)削除専用grace、(b)別の回復proofまたはaccountでの所有権確認、(c)証明喪失時は自動失効と期限付き消去、を比較する。a/bを設けても全proof喪失からの任意対象削除は許可しない。無期限保持と本人取消不能の組合せを残さない。

保持変数は **未決定**: `R_vote`（失効後の票/取消記録）、`R_auth`（credential metadata）、`R_receipt`、`R_log`、`R_backup`、削除処理SLA `D`、grace `G`。起算点、目的、例外、責任者、監視・期限超過時stop条件まで全体管理側で決める。R_receiptは最大retry窓を覆い、期限後は「不明/照会期限切れ」とし古い操作を新規実行しない。IP/access/監視logとbackupは台帳削除とは別。

削除APIは冪等なoperationと限定的な結果照会を提案する。応答喪失後の401は完了証明にならない。secret失効後の照会は、操作にだけ結び付く推測困難なproofなどを別途監査する。第三者のactor列挙を許可しない。server完了確認→vault cleanupの途中失敗を再起動後も回復でき、失敗を新actor発行で隠さない。

backup復元時は削除tombstone/high-waterと期限を先に再適用し、取り消した票・失効した世代を再集計しない。rollbackのために削除済み票を復活させない。今回backup/削除jobや本番運用を実装しない。

## P06: account統合

| 方針 | 集計 / 移行への影響 |
| --- | --- |
| 匿名票を破棄しaccountで再確認 | 意味が明確で重複を抑えるが、支持の再入力が必要。削除/破棄にも同意とreceiptが必要 |
| **有効な支持の集合を移管** | 比較の第一候補。source/targetの同じ候補は一票に集約。両方activeなら合計2→1。期限は勝手にnow+Lへresetしない。重複時にどちらの期限を採るかはpreviewで明示し決定する |
| 元票とaccount票を両方維持 | 同一意思を二重計上しやすい。「統合済み」と表示する方式には不適切 |

有効な匿名credentialの保有証明、認証済みaccount、明示preview/同意をすべて要求する。期限切れcredentialは通常の統合権限を持たず、P04の限定回復を先に解決する。clientからのuser_id指定、installation_id、Web cookieの流用では統合しない。

previewはsource/target revision、policy version、account世代、期限を固定。commitで再検証し、変化したら再preview。同時投票・取消・revoke・account切替とのtransaction/lock順序を設計する。mapping、重複集約、旧credential退役、receiptを原子的に確定し、sourceの票を別actorとして残さない。応答喪失や再実行で二重移管しない。

取り消した/期限切れの票を移管で復活させない。現行の「行がない」ことは最新の取消意思の時刻を証明しないため、単純なlatest-winsを採らない。account削除では移管済みの票・mapping・認証metadata・receiptの扱いもP03の保持規則へ揃える。

## 全体管理側の意思決定表

すべてOPEN。以下の担当は責任分担の提案であり、個人の就任を確定しない。全体管理/製品責任者が最終採否を記録し、privacy/security/運用/API/UI担当が各制約をレビューする。

| ID | 今回選ぶ事項 / 第一候補 | 決定後の成果物 / 対応テスト |
| --- | --- | --- |
| P01 | credential90日絶対期限の継続可否、更新方式/上限/通知/同意。支持期限と分離 | credential lifecycle契約・安全なsecret交換 / T01–T05 |
| P02 | C2「現在の支持」、L=90日は比較用、全identityの共通意味、旧票扱い | 集計predicate・as-of・期限表示・移行ルール / T01–T03,T12,T16–T18 |
| P03 | 非公開/停止候補も本人撤回。個別/全票/失効/全情報消去を区別。R群とD | privacy保持表・削除receipt・復元規則 / T08–T11,T20 |
| P04 | grace Gとscope、回復proof、全proof喪失時の自動失効/有限消去 | 回復権限表・案内・拒否条件 / T04–T07 |
| P05 | 残存/紛失/lockedを分け、再同意、新actorによる重複上限を説明 | reinstall/端末変更受入、Apple物理試験計画 / T06,T07,T21 |
| P06 | 移管方式、重複期限、両者proof、preview/commit、account削除連動 | 原子的mapping/receipt設計 / T13–T15,T20 |
| P07 | 操作単位の証明、照会期限、vault失敗/再起動からの回復 | versioned API・Native state machine / T05,T09,T19,T21 |
| P08 | 一人一票と約束しない。更新/発行/回復/削除の乱用・費用・責任者・stop条件 | rate/proxy監査、予算・incident/削除窓口、公開条件 / T22 |

決定順序は P02（何を数えるか）→P01/P03/P04/P05（期限と制御）→P06/P07（統合と応答保証）→P08/移行・公開受入。部分的な選択で相互に矛盾する仕様を公開しない。今回は意思決定を依頼するための材料を揃える段階で、実装再開の承認を推定しない。

## 既存データの移行への影響（設計のみ）

1. 将来承認された監査環境で件数と来歴を分類する。Native metadataと一致/不一致、期限切れ/失効済み、Web guest、登録account、user_id-null等の不整合/孤立行、停止候補、複数identityの重複を区別。現在の本番内訳はUNKNOWN。
2. **第一候補は旧票をlegacy_unconfirmedとして区別し、明示再確認後にC2へ参加させる方式**。旧票を消してテストを通さず、現在countからの除外と物理保持/消去を分ける。全旧票をcutover時から90日有効にresetしない。
3. 代案は有限の移行猶予中だけ「旧仕様の支持」と区別して表示する方式、または証拠があるtimestampを明示承認して暫定使用する方式。現行created_at/updated_atを最新の支持同意とみなす根拠はない。猶予終了・取消経路・残存期間を決めるまで採らない。
4. 将来必要なschema候補: 票のintent/valid_until/policy_version/世代/revisionと必要最小の取消情報、認証metadataのcontrol期限/世代/rotation状態、所有者mapping、操作receipt。既存票台帳を正本とし、別の票正本を増やさない。具体的DDL・index・locking・backfillは別レビュー。
5. expand→合成backfill→shadow projection比較→承認されたcutoverを設計する。Web/Native/本人状態/進捗の全readerとwriterを同一policyへ切替える。古いwriterが同意なしで新countへ票を追加しないようversion制御する。片側だけ期限を適用するrolloutは禁止。
6. count低下を需要消失や障害と断定しない。policy/as-ofの変更として説明する。thresholdや手動の開発/公開statusを票の減少で自動変更しない。
7. 将来の実行前に、承認された保全snapshot・復旧手順・削除反映を検証する。障害時はwrite停止とforward repairを基本案とし、rollbackで取消/期限切れ票を再有効化しない。本資料はmigration実行許可ではない。

## 必要になり得るAPI変更（未実装）

現行v1はbody/queryを空に限定し、余分な入力を422にする。以下を黙ってv1へ追加しない。新versionまたは明示交渉の契約、旧clientのread/write終了方針を採択後に設計する。path名・DTO・status codeも未確定。

| 契約候補 | 必要な保証 |
| --- | --- |
| policy / own actor状態の読取 | server_as_of、policy_version、credential/control期限、許可scopeを返す。token/hash/他者IDを返さない。expired actorの照会は限定proofに分離 |
| credential更新 / 限定回復 | 同actor/世代規則、rotation確定、失効secret拒否、失った新secretの安全な回復。単なるidempotency keyだけで解決済みとしない |
| 支持set / 明示再確認 | operation ID、expected revision、policy version、server確定の支持期限。新しい再確認と同じ要求のretryを区別 |
| 個別取消 / 全票削除 / revoke | 停止候補も最小receiptで撤回可能、operation scope、acceptedとcompleteの区別、expired grace権限 |
| operation結果照会 | 曖昧な応答のcommit/失敗/unknownを識別。期限付き・所有者限定。GETのdesired state一致だけで個別操作commitを証明しない |
| account統合preview / commit | 両者proof、revision/account世代、変化時再preview、原子的対応表と再試行receipt |
| feature projection | count_as_of、共通policy、自分の支持状態/期限/revision。匿名他者の一覧は返さない |

Nativeにはserver期限とscope、policy、操作receipt/revision、復旧状態の型が必要。現行FutureRepository/ViewModelの実在する入口とは区別し、UIへ未実装memberを渡さない。freshnessは取得成功だけで永続freshにせず、支持期限・server as-of・許容cache ageを踏まえて失効時の再読込/表示を設計する。offline/unknownを0票へ変換しない。device時刻はserver権限の代替にしない。最終SwiftUI接続は引き続き保留。

## 合成受入計画 — 全件NOT_IMPLEMENTED / NOT_RUN

採択後の別実装に対する計画。合成専用DB/actor、注入可能なserver clock、実時間sleepなしで境界を固定する。現在のPASS_SCOPEDや期限切れでも票が残る既存characterization testを、以下の合格証拠へ流用しない。

| ID | 条件と期待（C2等の採択を前提とする提案） |
| --- | --- |
| T01 | credential/支持期限の直前・同時刻・直後。等号でcount除外、purge前でも同じ。整数/時刻精度・timezoneを固定 |
| T02 | credential90日、支持170日の別期限。90日で除外、95日の回復では復活せず、明示再確認のみ有効 |
| T03 | GET/login/再表示/期限前credential更新/同一retry/通常set(true)で支持期限が延びない。別の明示再確認だけ更新 |
| T04 | grace直前/境界/直後のscope。期限後tokenは通常書込不可。revoked/deleted/mergedの回復不可 |
| T05 | rotation前後の応答喪失、旧/新secret競合、vault write失敗。二重actorなし、失った新secretを安全に扱えない場合は停止 |
| T06 | proof紛失、偽UUID/installation_id/user_id指定、他者回復/削除拒否。旧票は上限までに自動除外/消去 |
| T07 | reinstallでcredential残存/消失/locked/read errorを区別。自動発行なし、明示新actorでも旧所有権を獲得しない |
| T08 | 非公開/停止候補の本人取消成功と他者拒否。候補本文・他者count等の情報漏洩なし |
| T09 | 取消後に古い支持要求/重複再確認が到着。revision conflictまたは既存receipt、票の復活・期限延長なし |
| T10 | purge job遅延/停止/失敗。期限票は集計されず、SLA監視・再開後消去が成立 |
| T11 | R群/Dの境界、削除完了とbackup期限の区別。backup復元後もtombstone/世代/期限で復活なし |
| T12 | 失効・取消・更新の並行transactionと再起動。確定順序と同一as-of count/own-stateが整合 |
| T13 | source/target双方activeの同じ候補が2→1、片側だけactiveなら1。期限を暗黙延長せず、取消/期限切れは復活しない |
| T14 | 統合preview後のaccount切替/投票/取消/expiry。古いpreviewのcommit拒否、再previewを要求 |
| T15 | 統合中断・応答喪失・多重commit・revoke競合・server再起動。同じreceipt、二重移管/残存二重票なし |
| T16 | 全legacy分類の合成fixture。created/updatedから同意を捏造せずlegacy_unconfirmed、元データ保持と照合 |
| T17 | Web guest/Native/accountを同じDB/clockで集計。全readerと本人状態・進捗に同一policyを適用 |
| T18 | 旧v1 client/writerの混在・cutover失敗。無断の新policy票生成なし、破壊的rollbackなし |
| T19 | DELETE lost-ack後にreceipt確認。401を完了としない。receipt期限後はunknown、stale操作再実行なし |
| T20 | account削除でlink済み票・metadata/mapping/receiptを規則通り処理。他accountの票を消さない |
| T21 | server完了→vault削除失敗→再起動の回復。offline/stale/期限不明を0票/削除完了としない。Apple物理試験は別途必須 |
| T22 | 発行/更新/回復/削除/照会のrate limit、proxy偽装、proof総当たり、log秘匿、予算stop条件。環境設定は今回変更しない |

## 再開・製品採用の条件

全体管理側がP01–P08と数値L/G/R群/D・責任者を記録し、移行ルールとversioned APIをprivacy/security/API/UI側で照合することが、次の実装範囲を定める前提。合成計画の具体化・実装・検証、Apple実機/Keychain/reinstall、実運用DB並行性、保持/削除/復元運用、乱用・費用監査が残る。

新規利用者は同意・期限・回復限界・取消方法が理解でき、期限後に残存支持を無期限に数えないこと。既存利用者は旧票を勝手に現在意思へ変換せず、アクセス可能な所有者だけが再確認/移管でき、証明喪失でも定めた上限内に除外/消去されることを受入条件案とする。製品採用・main merge・production公開は、その後の別判断。両PR Draft、API default OFFとhard production deny、既存security/release gateを維持する。
