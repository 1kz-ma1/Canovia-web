# CanoviaNative Prototype

V52.1のiOS shell prototypeです。

Canovia本体はLaravel / Blade / JavaScriptをsource of truthとして維持し、このディレクトリはiOS固有の境界だけを担当します。

## Architecture

- SwiftUI app lifecycle
- one persistent `WKWebView`
- `WKWebsiteDataStore.default()`
- production origin: `https://pacekeeper-d3mm.onrender.com`
- Web runtime injection: `window.__CANOVIA_NATIVE__`
- User-Agent suffix: `CanoviaNative/iOS/<version>`
- bridge handler: `window.webkit.messageHandlers.canovia`
- external http(s): `SFSafariViewController`
- edge swipe: `WKWebView.allowsBackForwardNavigationGestures = true`
- file/photo input: WKWebView standard HTML picker
- custom prototype deep link: `canovia://open?path=/roadmap`

## Generate the Xcode project

The checked-in `project.yml` is the source of truth for the prototype project.

On the Mac:

```bash
cd ios/CanoviaNative
brew install xcodegen   # only when XcodeGen is not installed
make project
open CanoviaNative.xcodeproj
```

The generated `.xcodeproj` is intentionally ignored so project-file churn does not become source of truth.

## Signing

The prototype intentionally does not commit an Apple Development Team.

For a physical iPhone:

1. Open the generated project.
2. Select the CanoviaNative target.
3. Signing & Capabilities -> choose your Development Team.
4. If Xcode reports that `app.canovia.prototype` is unavailable, replace the prototype Bundle Identifier with one owned by your team.

No production signing identity, provisioning profile, certificate, or secret belongs in this repository.

## First launch / session

WKWebView uses its own persistent website data store.

That means:

- after signing in once inside CanoviaNative, the Laravel session persists across app launches;
- the app does not copy or expose session cookies through the JS bridge;
- an existing Safari/PWA login is not assumed to be shared with WKWebView.

V52.1 therefore accepts a one-time first login inside the native app. A secure handoff from an existing Web/PWA session can be designed later if it becomes important.

## Prototype checks

Confirm on a physical iPhone:

- launch and render Home
- Home -> 星座 -> 実行 -> タイムライン
- back/forward edge swipe
- app background -> foreground
- restart and verify signed-in session persists
- external GitHub/resource link opens Safari sheet
- same-origin screenshot/file link stays in the same WKWebView
- Career screenshot file input opens the standard picker
- `canovia://open?path=/roadmap` routes to Constellation

## Not in V52.1

- Universal Links / Associated Domains
- push notifications
- App Store metadata
- production Bundle ID
- production icon / launch artwork
- native camera specialization
- native offline cache
- native rewrite of Canovia surfaces
