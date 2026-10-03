import Foundation

enum CanoviaEnvironment {
    static let productionOrigin = URL(string: "https://pacekeeper-d3mm.onrender.com")!

    static func sameOriginPath(from url: URL) -> String? {
        if url.scheme?.lowercased() == "canovia" {
            guard let components = URLComponents(url: url, resolvingAgainstBaseURL: false) else {
                return nil
            }

            if let explicitPath = components.queryItems?
                .first(where: { $0.name == "path" })?
                .value,
               explicitPath.hasPrefix("/") {
                return explicitPath
            }

            let rawPath = url.path.isEmpty ? "/" : url.path
            return rawPath.hasPrefix("/") ? rawPath : "/\(rawPath)"
        }

        guard
            let originHost = productionOrigin.host?.lowercased(),
            let host = url.host?.lowercased(),
            host == originHost,
            ["https", "http"].contains(url.scheme?.lowercased() ?? "")
        else {
            return nil
        }

        var result = url.path.isEmpty ? "/" : url.path
        if let query = url.query, !query.isEmpty {
            result += "?\(query)"
        }
        if let fragment = url.fragment, !fragment.isEmpty {
            result += "#\(fragment)"
        }
        return result
    }

    static func url(for path: String) -> URL? {
        guard path.hasPrefix("/") else { return nil }
        return URL(string: path, relativeTo: productionOrigin)?.absoluteURL
    }
}
