import Combine
import Foundation
import WebKit

@MainActor
final class CanoviaWebViewModel: NSObject, ObservableObject {
    @Published private(set) var bridgeReady = false
    @Published private(set) var canGoBack = false
    @Published var externalURL: URL?

    let webView: WKWebView

    private let bridgeProxy: WeakScriptMessageHandler
    private var didLoadInitialURL = false

    override init() {
        let controller = WKUserContentController()
        let proxy = WeakScriptMessageHandler()

        let configuration = WKWebViewConfiguration()
        configuration.websiteDataStore = .default()
        configuration.userContentController = controller
        configuration.defaultWebpagePreferences.allowsContentJavaScript = true
        configuration.allowsInlineMediaPlayback = true
        configuration.applicationNameForUserAgent = "CanoviaNative/iOS/\(Self.appVersion)"

        let runtimePayload: [String: Any] = [
            "platform": "ios",
            "bridgeVersion": 1,
            "appVersion": Self.appVersion,
        ]
        let runtimeJSON = Self.jsonString(runtimePayload) ?? "{}"
        let bootstrap = WKUserScript(
            source: "window.__CANOVIA_NATIVE__ = \(runtimeJSON);",
            injectionTime: .atDocumentStart,
            forMainFrameOnly: true
        )
        controller.addUserScript(bootstrap)

        self.bridgeProxy = proxy
        self.webView = WKWebView(frame: .zero, configuration: configuration)

        super.init()

        proxy.delegate = self
        controller.add(proxy, name: "canovia")

        webView.navigationDelegate = self
        webView.allowsBackForwardNavigationGestures = true
        webView.scrollView.contentInsetAdjustmentBehavior = .never
        webView.scrollView.keyboardDismissMode = .interactive
    }

    deinit {
        webView.configuration.userContentController.removeScriptMessageHandler(forName: "canovia")
    }

    func startIfNeeded() {
        guard !didLoadInitialURL else { return }
        didLoadInitialURL = true

        let request = URLRequest(
            url: CanoviaEnvironment.productionOrigin,
            cachePolicy: .reloadRevalidatingCacheData,
            timeoutInterval: 30
        )
        webView.load(request)
    }

    func openDeepLink(_ url: URL) {
        guard let path = CanoviaEnvironment.sameOriginPath(from: url) else { return }

        if bridgeReady {
            sendToWeb(type: "openPath", payload: ["path": path])
            return
        }

        guard let target = CanoviaEnvironment.url(for: path) else { return }
        webView.load(URLRequest(url: target))
    }

    func notifyAppBecameActive() {
        guard bridgeReady else { return }
        sendToWeb(type: "appBecameActive")
    }

    func requestBack() {
        if bridgeReady {
            sendToWeb(type: "back")
        } else if webView.canGoBack {
            webView.goBack()
        }
    }

    func dismissExternalBrowser() {
        externalURL = nil
    }

    private func sendToWeb(type: String, payload: [String: Any] = [:]) {
        let message: [String: Any] = [
            "type": type,
            "payload": payload,
        ]
        guard let json = Self.jsonString(message) else { return }

        webView.evaluateJavaScript(
            "window.CanoviaNativeBridge?.receive(\(json));"
        )
    }

    private func handleBridgeMessage(_ body: Any) {
        guard
            let envelope = body as? [String: Any],
            let type = envelope["type"] as? String,
            let version = envelope["version"] as? Int,
            version == 1
        else {
            return
        }

        let payload = envelope["payload"] as? [String: Any] ?? [:]

        switch type {
        case "ready":
            bridgeReady = true
            updateNavigationState(payload)
        case "navigationState":
            updateNavigationState(payload)
        case "openExternal":
            guard
                let rawURL = payload["url"] as? String,
                let url = URL(string: rawURL),
                ["https", "http"].contains(url.scheme?.lowercased() ?? "")
            else {
                return
            }
            externalURL = url
        case "fileInputRequested":
            // V52.1 keeps the HTML/WKWebView picker as source of truth.
            // This message is intentionally informational for later native
            // camera/file specialization.
            break
        case "requestClose":
            // The prototype has a single root WKWebView rather than a native
            // navigation stack. At root there is nothing native to pop.
            break
        default:
            break
        }
    }

    private func updateNavigationState(_ payload: [String: Any]) {
        if let bridgeValue = payload["can_go_back"] as? Bool {
            canGoBack = bridgeValue || webView.canGoBack
        } else {
            canGoBack = webView.canGoBack
        }
    }

    private static var appVersion: String {
        Bundle.main.object(forInfoDictionaryKey: "CFBundleShortVersionString") as? String
            ?? "0.1.0"
    }

    private static func jsonString(_ object: Any) -> String? {
        guard JSONSerialization.isValidJSONObject(object) else { return nil }
        guard let data = try? JSONSerialization.data(withJSONObject: object) else { return nil }
        return String(data: data, encoding: .utf8)
    }
}

extension CanoviaWebViewModel: WKScriptMessageHandler {
    nonisolated func userContentController(
        _ userContentController: WKUserContentController,
        didReceive message: WKScriptMessage
    ) {
        guard message.name == "canovia" else { return }

        Task { @MainActor [weak self] in
            self?.handleBridgeMessage(message.body)
        }
    }
}

extension CanoviaWebViewModel: WKNavigationDelegate {
    func webView(_ webView: WKWebView, didFinish navigation: WKNavigation!) {
        canGoBack = webView.canGoBack
    }

    func webViewWebContentProcessDidTerminate(_ webView: WKWebView) {
        webView.reload()
    }
}
