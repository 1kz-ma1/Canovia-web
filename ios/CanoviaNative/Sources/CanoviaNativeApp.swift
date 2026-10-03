import SwiftUI

@main
struct CanoviaNativeApp: App {
    @StateObject private var webModel = CanoviaWebViewModel()

    var body: some Scene {
        WindowGroup {
            CanoviaRootView(model: webModel)
        }
    }
}
