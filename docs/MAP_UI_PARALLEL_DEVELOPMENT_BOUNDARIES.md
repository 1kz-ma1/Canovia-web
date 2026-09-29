# Map UI Parallel Development Boundaries

## Purpose

Map UIを複数チャット / branchで並行実装するとき、`resources/views/map/index.blade.php`、`resources/css/app.css`、`docs/CANOVIA_PRODUCT_SPEC.md` のような共有hotspotへ変更が集中してmerge事故が起きることを避ける。

この文書は機能仕様ではなく **変更境界** を定義する。

## Core rule

`resources/views/map/index.blade.php` はcomposition shellとして扱う。

- feature markupを直接追加しない
- 新しいMap機能は既存partialまたは専用extension partialへ追加する
- 新しいfeatureが既存partialと責務衝突する場合は、さらにpartialを追加して分離する

`resources/css/app.css` へ新しいMap feature CSSを追記しない。

- Map CSSの入口は `resources/css/map/index.css`
- featureごとのowned CSS fileを編集する

## Blade ownership

### `map/partials/topbar.blade.php`

既存Hero / help / primary actions / hierarchy header。
大規模Navigation機能を直接ここへ追加せず、Global Navigationはextensionへ置く。

### `map/partials/extensions/global-navigation.blade.php`

Owner:

- 常設Home
- 最大抽象度へ戻る
- hierarchy jump
- mini-map / zoom depth navigation

別機能は原則触らない。

### `map/partials/extensions/surface-controls.blade.php`

Owner:

- Map Surface / Page switch
- user preset selector
- Data LayerのON/OFF control

canonical Plan / Task stateはここで変更しない。

### `map/partials/workspace.blade.php`

Map shellのcompositionだけを担当する。
feature-specific markupを増やさず、Scene / Palette / Overlayをincludeする。

### `map/partials/scene.blade.php`

Owner:

- projected nodes
- projected edges
- node interaction DOM
- gesture controlsと直接関係するcanvas markup

Data Layerのvisual追加は可能な限り `extensions/canvas-overlays.blade.php` を使う。

### `map/partials/extensions/canvas-overlays.blade.php`

Owner:

- progress overlay
- deadline overlay
- owner / collaborator overlay
- Evidence overlay
- Dependency overlay
- personalization explanation / attention hints

Overlayはcanonical entityを作らず、Projectionされたdataのvisual layerとして扱う。

### `map/partials/detail-palette.blade.php`

Owner:

- selected node detail palette chrome
- expand / close

Nodeごとのactual content/actionsは `surface-templates.blade.php` から供給する。

### `map/partials/surface-templates.blade.php`

Owner:

- Palette内のtitle / summary / meta / actions
- Space Station global surface template

### existing `map/partials/space-station-surface.blade.php`

Owner:

- Companion / Inbox / input / information routingのSpace Station UI

Space Station機能はsceneやtopbarへ直接大きなmarkupを増やさない。

## CSS ownership

`resources/css/map/index.css` はimport registryだけを担当する。feature CSSは以下へ。

- `presentation.css`: Node presentation / Detail Palette presentation
- `navigation.css`: Home / hierarchy jump / mini-map / navigation transition visual
- `data-layers.css`: progress / deadline / owner / Evidence / Dependency overlays
- `surfaces.css`: multiple Map Page / Surface / preset UI
- `roadmap.css`: spatial Roadmap
- `space-station.css`: central Space Station input / routing visualization
- `personalization.css`: personalized node emphasis / explanation / pin / hide visuals

同じPRで複数featureを実装しても、別ownerのCSSを混ぜない。

## Service / domain ownership

Presentation変更でcanonical dataを変更しない。

- Plan / Task / Evidenceのsource of truthは既存model/service
- MapはProjection / Attention / Presentation layer
- Overlay / user layout preferenceはcanonical progressやcompletionを書き換えない
- GitHub factとCanovia decisionは既存方針どおり分離する

Roadmap Mapは既存Task / dependency / Coordinationを読むProjectionとして実装し、並行Task表現のためにTask statusを複製しない。

## Documentation

並行feature PRでは原則、feature固有docを作る。

`docs/CANOVIA_PRODUCT_SPEC.md` は複数branchが同時編集すると衝突しやすいため、parallel work中は必要最小限のappendに留める。可能なら各feature PR merge後に統合specを更新する。

## Recommended branch split

- Navigation / Home / mini-map: `map/partials/extensions/global-navigation.blade.php` + `css/map/navigation.css` + dedicated JS/service
- Data Layers: `extensions/surface-controls.blade.php` + `extensions/canvas-overlays.blade.php` + `css/map/data-layers.css`
- Roadmap Map: dedicated route/view/service + `css/map/roadmap.css`; core `map/index.blade.php`は触らない
- Space Station: `map/partials/space-station-surface.blade.php` + `css/map/space-station.css`
- Personalization / Map Pages: `extensions/surface-controls.blade.php` + `css/map/surfaces.css` / `personalization.css`

## Merge order

1. このboundary refactorをmainへmerge
2. 各feature branchはそのmainから作成 / rebase
3. feature owner filesだけ変更
4. shared core (`index.blade.php`, `app.css`) を変更した場合は理由をPR本文へ明記
5. merge前にlatest mainとの差分を再確認

これによりGitのconflictを減らすだけでなく、conflictなしで意味が壊れるsemantic merge事故も防ぐ。
