# Learning V6.21 — 第3の固定Mac Safari受入ラボ（オーナー実機用）

> 2026-10-15 | **Mac Safariの手動受入は未実施**。オーナーがMacのターミナルとSafariで操作すること。
> 検証対象は [PR #765](https://github.com/1kz-ma1/Canovia-web/pull/765) の開発用統合コミット `ce3c57dd3fbfcbef606d32920822d1c9b8eaf72b` に固定。
> これはWeb公開、iPhone Safari/WKWebView、Render/Aiven、TestFlight、App StoreのGOではない。

## 目的と分離

V6.21の従来の外部AI演習で、**1問の途中回答がサーバー保存済みか、失敗したか、再読み込み後に復元するか**をMac Safariで観察する。採点済み `StudyPracticeAttempt` は途中回答だけでは作られず、評価JSONの明示取り込み後に作られる。新方式のQuestion Bank `LearningRun`（1問ずつ採点）とは区別する。

既存の安全な `scripts/ci/prelaunch_ia2i_local_lab.py` に **3番目の厳密に固定されたSHAのみ**を追加した。

| 比較対象 | 固定コミット | 127.0.0.1のポート | セッションCookie | SQLite / Git worktree |
| --- | --- | ---: | --- | --- |
| 旧IA-2h | `069e9b5790345feb9bb9edd437ac1c5a39a73efd` | 8797 | `canovia-ia2i-session` | 旧環境を保持 |
| IA-2jのAction優先UI | `fdbe29de79f0ca7df255a978e13f94f248a3ff32` | 8798 | `canovia-ia2j-session` | 別の既存/新規環境を保持 |
| **V6.21の途中保存表示** | **`ce3c57dd3fbfcbef606d32920822d1c9b8eaf72b`** | **8799** | **`canovia-v621-session`** | **新規専用** |

ヘルパーは未知のSHA、異なるSHAでのDB使い回し、.env、キャッシュ済みLaravel設定、シンボリックリンク、既存状態の上書きを拒否する。外部DBのURLやAWS/GitHubキーは継承しない。独立したowner-onlyラボ内の新規SQLite以外にマイグレーションしない。**ただしOSレベルで送信先を遮断するものではなく、外向き通信はファイアウォールでブロックしていない**。本物のアカウント/教材/秘密情報/外部AI資格情報は入力しないこと。

## Macターミナルの手順（必ず新しい作業フォルダ）

前提: 既存Macクローン `$HOME/Developer/Canovia-web` があること。**このPRがCI合格・開発mainマージされた後**のヘルパーを使う。古い8797/8798のLaravelサーバーを `Control+C` で止める（データは消えない）。他のプライベートウィンドウを閉じる。localhostのCookieの一部はポートを跨ぐことがある。

### 1. コードを取得し、検証コミットを固定

```bash
ROOT="$HOME/Developer/Canovia-web"
FROZEN="$HOME/Developer/Canovia-V621-ce3c57dd"
LAB="$HOME/Library/Application Support/Canovia-V621/ce3c57dd"
SHA="ce3c57dd3fbfcbef606d32920822d1c9b8eaf72b"

git -C "$ROOT" status --short
git -C "$ROOT" fetch origin main
git -C "$ROOT" cat-file -t "$SHA"
git -C "$ROOT" pull --ff-only origin main
git -C "$ROOT" worktree add --detach "$FROZEN" "$SHA"
git -C "$FROZEN" rev-parse HEAD
```

必ず `commit` と表示され、最後に上記40文字のSHAが一致すること。ローカル変更、fast-forward不可、既存worktreeパスなどのエラー時に `reset --hard` / `clean` / 強制削除はしない。作業を止めて原因を確認する。

### 2. 専用SQLiteを一度だけ新規作成

```bash
python3 "$ROOT/scripts/ci/prelaunch_ia2i_local_lab.py" init --checkout "$FROZEN" --lab-dir "$LAB" --source-sha "$SHA"
python3 "$ROOT/scripts/ci/prelaunch_ia2i_local_lab.py" check --checkout "$FROZEN" --lab-dir "$LAB" --source-sha "$SHA"
```

出力の `IA2I_SOURCE_PINNED=ce3c57dd...`、`IA2I_LOCAL_URL=http://127.0.0.1:8799`、`IA2I_DB=ISOLATED_OWNER_ONLY_SQLITE` を照合。識別子 `IA2I_` は旧ヘルパーからの互換表記。最初の `VENDOR_READY=false` / `ASSETS_READY=false` は正常。誤ったDBやSHAなら中断。

### 3. 別worktree内だけで依存関係とアセットを構築

```bash
cd "$FROZEN"
env -i HOME="$HOME" PATH="$PATH" TMPDIR="${TMPDIR:-/tmp}" composer install --no-scripts --no-plugins --no-interaction --prefer-dist --no-progress
env -i HOME="$HOME" PATH="$PATH" TMPDIR="${TMPDIR:-/tmp}" npm ci --ignore-scripts
env -i HOME="$HOME" PATH="$PATH" TMPDIR="${TMPDIR:-/tmp}" npm run build
python3 "$ROOT/scripts/ci/prelaunch_ia2i_local_lab.py" check --checkout "$FROZEN" --lab-dir "$LAB" --source-sha "$SHA"
```

これらはネットワーク経由で固定バージョンのサードパーティ依存をダウンロードする。**`VENDOR_READY=true` と `ASSETS_READY=true` の両方**が出ること。.envや他ラボのvendor、SQLite、鍵、storageはコピーしない。

### 4. 新しいSQLiteのみマイグレーションし、localhostで起動

```bash
python3 "$ROOT/scripts/ci/prelaunch_ia2i_local_lab.py" migrate --checkout "$FROZEN" --lab-dir "$LAB" --source-sha "$SHA" --confirm-disposable
python3 "$ROOT/scripts/ci/prelaunch_ia2i_local_lab.py" serve --checkout "$FROZEN" --lab-dir "$LAB" --source-sha "$SHA" --confirm-disposable
```

起動後、同じMacの**Safariプライベートウィンドウ**から <http://127.0.0.1:8799/> を開く。実際のPHPセッション/CSRFが有効な `APP_ENV=local`。実験終了時はサーバーターミナルで `Control+C`。旧8797/8798のWorktree/SQLiteには一切触れない。

## Safariでの手動受入項目

1. **初回導線:** 合成の学習Planと合成アカウントを作り、今やることの最優先Actionが見えるか確認。Missing Context入力が要求されたらUIから入力・本人確認を進める。初回Task作成までの導線が存在しない/繋がらない場合は **BLOCKED** と記録し、Taskを勝手に捏造して「初回体験成功」としない。
2. **従来の外部AI演習:** この使い捨てPlan内の合成Taskから「AI演習」を開き、JSON取込の**手動で貼り付ける**を使う。必要なら下記合成JSONの `123` / `456` を画面URLの実際のPlan/Task IDに置き換える（別Plan/TaskのIDでの取込は拒否が正しい）。本物のAP問題・個人学習情報は使わない。
3. **1問だけ回答:** Q1で「A」を選択。約1秒ほど操作を中断し、画面に **「途中回答をサーバーへ保存しました（採点・学習履歴への反映はまだです）。」** が出るか観察。これは**途中下書きが保存された**という意味であり、採点完了ではない。
4. **復元:** Safariで通常の再読み込みを行う。Q1の「A」が保持され、2問目へ進めるか（または未回答のQ2が自動表示されるか）確認。可能なら別タブではなく同じプライベートウィンドウで確認。確認できなければ FAIL と記録。ここで採点履歴ゼロは正常。
5. **保存・エラーの意味:** Q2を入力した後、表示が「保存中」→「サーバーへ保存しました」に遷移すること。実環境で故意に419/403を起こす必要はない。**403/419/500や「保存できませんでした」が発生したら FAIL、表示と発生した操作を控える**。V6.21のChromium CIでは模擬419から正しい失敗表示を検証済み。
6. **確定境界:** 「回答をまとめて評価へ進む」で評価依頼を表示できること。合成の評価JSONを実際に取り込まない限り採点済みAttemptがないのは正常。実際に合成評価を取り込んだ場合だけ採点済み履歴を確認し、Task進捗が自動確定されたと主張しない。
7. **IA-2jの表示回帰:** 今やることがAction優先、根拠の詳細が学習分析/準備/履歴へ分離されているか、操作可能なリンク・ボタンに被りや画面外はみ出しがないかを確認。

合成JSONの例（**IDを実際の合成Plan/Taskに差し替えてから**取込）:

```json
{
  "flow": "study_practice",
  "target_plan": {"id": 123},
  "target_task": {"id": 456},
  "title": "V6.21 Safari 合成2問",
  "questions": [
    {
      "id": "q1", "type": "single_choice",
      "prompt": "合成Q1：テスト専用の選択問題",
      "choices": [
        {"id": "A", "label": "選択肢A"},
        {"id": "B", "label": "選択肢B"}
      ]
    },
    {
      "id": "q2", "type": "text",
      "prompt": "合成Q2：テスト専用の記述問題"
    }
  ]
}
```

### 結果メモ（まだ埋めない）

```text
SOURCE_PINNED=ce3c57dd3fbfcbef606d32920822d1c9b8eaf72b
PORT=8799
DEVICE=Mac Safari
LOCAL_LAB=owner-only disposable SQLite
INITIAL_PLAN_AND_TASK=NOT_RUN
EXTERNAL_JSON_IMPORT=NOT_RUN
ONE_ANSWER_SERVER_SAVE=NOT_RUN
RELOAD_RESUME=NOT_RUN
SECOND_ANSWER_SERVER_SAVE=NOT_RUN
EVALUATION_HANDOFF=NOT_RUN
ACTION_FIRST_LAYOUT=NOT_RUN
UNEXPECTED_403_419_500=NOT_RUN
IPHONE_WKWEBVIEW=NOT_RUN
RENDER_DEPLOY=NOT_RUN
PUBLIC_RELEASE=NO_GO
```

PASS / FAIL / BLOCKED は実際に操作した場合のみ書き換え、実データを含まない画面写真と、失敗時の画面遷移を残す。検証結果の報告を受けて修正PRを判断する。

## GitHub CI範囲

既存の `Pre-Launch IA-2i Local Mac Lab Guardrails` をPython 3.9 / 3.12の両方で実行する。合成Git履歴に対して、第三SHAの明示選択、旧2系統との独立性、3つのポート/Cookie、使い捨てSQLite以外への誘導拒否、.env / 秘密環境変数の隔離、同一ラボの再利用拒否をテストする。**GitHub CI成功はMacで実際にSafariを開いた証拠にはならない。**

本番 `production`、Render Auto Deploy（OFF維持）、Aiven、通知、課金、実ユーザーPlan、外部AIプロバイダ/キーは変更しない。Issue #488の公開リリースゲートは独立にNO-GOのまま。
