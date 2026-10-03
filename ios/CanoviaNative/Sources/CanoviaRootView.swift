import SwiftUI

struct CanoviaRootView: View {
    @ObservedObject var model: CanoviaWebViewModel
    @Environment(\.scenePhase) private var scenePhase

    var body: some View {
        CanoviaWebView(model: model)
            .ignoresSafeArea()
            .onOpenURL { url in
                model.openDeepLink(url)
            }
            .onChange(of: scenePhase) { _, nextPhase in
                if nextPhase == .active {
                    model.notifyAppBecameActive()
                }
            }
            .sheet(
                isPresented: Binding(
                    get: { model.externalURL != nil },
                    set: { presented in
                        if !presented {
                            model.dismissExternalBrowser()
                        }
                    }
                )
            ) {
                if let url = model.externalURL {
                    SafariView(
                        url: url,
                        onDismiss: model.dismissExternalBrowser
                    )
                    .ignoresSafeArea()
                }
            }
    }
}
