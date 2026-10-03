import SwiftUI
import WebKit

struct CanoviaWebView: UIViewRepresentable {
    @ObservedObject var model: CanoviaWebViewModel

    func makeUIView(context: Context) -> WKWebView {
        model.startIfNeeded()
        return model.webView
    }

    func updateUIView(_ uiView: WKWebView, context: Context) {}
}
