# e858 Source-bound監査セット：設計・検証・Mac受入

正本 [#488](https://github.com/1kz-ma1/Canovia-web/issues/488)。候補採用とrelease pinは[owner記録](https://github.com/1kz-ma1/Canovia-web/issues/488#issuecomment-6260662593)で完了。順序は **候補pin済み → 本toolingの実装/CI/Mac受入 → 実Aivenの別承認**。未マージの[#794](https://github.com/1kz-ma1/Canovia-web/pull/794)は指定HEADの履歴資料として参照し、変更しない。

## 実装前に固定する契約

- candidateはe858の40桁SHA/tree、新release ref、114 manifest/sealに固定。tooling SHAは別引数で、候補の子孫・clean/full-history・実ファイルblobと照合する。旧94・元監査/参照/SQL/grants guardを変更しない。
- **本実装はoffline専用。`--collect`は常に接続前BLOCK**。operator承認flag、資格情報、mysql client、実接続用transportは提供しない。stream制限は使い捨てDockerと偽clientで検証するライブラリであり、本番接続の入口ではない。
- baseline94と完成114は固定sourceを両MySQLへ二度独立適用し、全参照を再生成比較する。旧referenceとの一致は生成後の回帰検査で、旧JSONをコピーして生成とは扱わない。
- 原strict物理hash、追加の意味/方向/生成式等の厳密hash、名前を除くIndex/FK多重集合を分離する。名前差はstrict BLOCK。metadata-onlyで二重identity/実権限/完全TRIGGER/backup/writerをPASSにしない。
- metadataは固定SQL ID・単一statementのみ。現在databaseのcatalogとmigration/batchだけ。bounded readerはwall/全体時間、stream bytes、行/セル、cap+1、stderr/不正encoding/部分行/中断を固定BLOCKにする。再試行なし、raw出力なし。
- referenceの自身のcommitをJSONに埋める循環は作らない。**生成時tooling commit**と実行module/SQL/旧library/PHP生成入力のblob集合をreferenceに封印し、最終tooling HEADがその子孫かつ同blobであることを検査。最終HEAD/refファイル自体のblobとCI runはMac packetで別照合する。実装変更時は生成元commit・二重生成・参照・CI/Mac受入が失効する。

## 正負検証の設計

| 境界 | 正例 | 負例・停止 |
| --- | --- | --- |
| source/tooling | 採用済pin、旧94/114、同seal、生成blob | candidate/tooling/ref/SQL差、dirty/shallow/symlink/ignored差替、release drift |
| reference | 両版の二重再構築94/114 | 元proposed JSON転用、候補/生成元/正規化/集合hash差 |
| SQL/stream | 固定ID、完全ASCII行、上限内 | 未知ID/任意SQL/複文、cap+1、slow/oversize/partial/error/interruption |
| structure | strict94/114一致と状態分離 | 名前差、定義/列順/UNIQUE/prefix/式/可視性/FK動作差、部分DDL/衝突 |
| visibility/permissions | 合成の定義観測 | narrow userのtrigger0、権限過多、不可視を完全とする誤判定 |
| migration guard | 固定sourceの正常移行/中断保持 | M08二重有効、別名旧UNIQUE、M18/M19/M20残骸、M04→M05依存欠損 |
| publication | 固定code/件数/source pinのみ | raw schema/account/host/secret/object/例外本文を出さない |

実行結果・新HEAD・CI/Macコマンドは本票の後半とDraft PR/#488へ追記する。本番のstrict名前差、完全可視性、実DDL/binlog、実データ、backup/復元、writer、Web/iOS/Auth0/Renderは引き続き未検証。**PRODUCTION_NO_GO_HOLD**。
