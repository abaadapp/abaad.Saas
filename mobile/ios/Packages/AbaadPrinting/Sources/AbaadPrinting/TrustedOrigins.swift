import Foundation

/*
 * الأصولُ الموثوقة — من إعداد البناء وحده، لا من الصفحة ولا من الخادم.
 *
 * ═══ والتطبيعُ شكلٌ واحد ═══
 *
 * يُقارن الطرفُ الأصليّ الأصولَ بعد تطبيعها إلى `scheme://host:port`: المخطّطُ
 * والمضيفُ بأحرفٍ صغيرة، والمنفذُ الافتراضيّ صريح (443 أو 80). فيستوي
 * `https://POS.EXAMPLE.TEST` و`https://pos.example.test:443`، ولا يستوي
 * `http://…` و`https://…` أبدًا.
 *
 * أمّا الـshim فيقارن بـ`location.origin` كما يكتبه المتصفّح — بلا المنفذ
 * الافتراضيّ — فيُعطى `webOrigins` بذلك الشكل.
 */
public struct TrustedOrigins: Equatable, Sendable {
    /// مطبَّعةً بالمنفذ الصريح — للمقارنة هنا
    public let normalized: [String]
    /// كما يكتبها `location.origin` — للـshim
    public let webOrigins: [String]

    public init(_ origins: [String]) {
        var normalized: [String] = []
        var web: [String] = []
        for raw in origins {
            guard let parts = Self.configuredParts(raw) else { continue }
            let origin = Self.format(parts, explicitPort: true)
            guard !normalized.contains(origin) else { continue }
            normalized.append(origin)
            web.append(Self.format(parts, explicitPort: false))
        }
        self.normalized = normalized
        self.webOrigins = web
    }

    /// من سطر الإعداد: أصولٌ مفصولةٌ بفواصل. فارغًا ← أصلُ العنوان الأساسيّ وحده.
    public init(configured: String?, fallbackBaseURL: URL?) {
        let list = (configured ?? "")
            .split(separator: ",")
            .map { $0.trimmingCharacters(in: .whitespacesAndNewlines) }
            .filter { !$0.isEmpty }
        if list.isEmpty, let base = fallbackBaseURL, let origin = Self.normalize(url: base) {
            self.init([origin])
        } else {
            self.init(list)
        }
    }

    public var isEmpty: Bool { normalized.isEmpty }

    public func isTrusted(_ origin: String?) -> Bool {
        guard let origin, let normal = Self.normalize(origin) else { return false }
        return normalized.contains(normal)
    }

    public func isTrusted(url: URL?) -> Bool {
        guard let url else { return false }
        return isTrusted(Self.normalize(url: url))
    }

    /*
     * §4.5 — رابطُ الإيصال:
     *   - مطلق، وبلا `user:pass@`.
     *   - أصلُه يساوي أصلَ الصفحة، والصفحةُ نفسُها موثوقة.
     *   - و`http` لا يمرّ إلّا إن كان ذلك الأصلُ نفسُه في القائمة.
     */
    public func isReceiptURLAllowed(pageOrigin: String?, receiptURL: String) -> Bool {
        receiptURLIfAllowed(pageOrigin: pageOrigin, receiptURL: receiptURL) != nil
    }

    /// الرابطُ المقبول نفسُه — فيُنزَّل ما فُحص لا نصٌّ يُحلَّل من جديد
    public func receiptURLIfAllowed(pageOrigin: String?, receiptURL: String) -> URL? {
        guard let pageOrigin, let page = Self.normalize(pageOrigin), normalized.contains(page) else { return nil }
        guard let components = Self.strictComponents(receiptURL),
              components.user == nil, components.password == nil,
              let parts = Self.parts(of: components),
              Self.format(parts, explicitPort: true) == page,
              let url = components.url
        else { return nil }
        return url
    }

    // MARK: - التطبيع

    /// أصلُ رابطٍ مطبَّعًا، أو nil إن لم يكن http(s) بمضيف
    public static func normalize(url: URL) -> String? {
        guard let components = URLComponents(url: url, resolvingAgainstBaseURL: false),
              let parts = parts(of: components)
        else { return nil }
        return format(parts, explicitPort: true)
    }

    /// أصلٌ نصًّا (`https://host[:port]`، أو رابطٌ كامل) مطبَّعًا
    public static func normalize(_ origin: String) -> String? {
        guard let components = strictComponents(origin), let parts = parts(of: components) else { return nil }
        return format(parts, explicitPort: true)
    }

    /// من أجزاءٍ منفصلة — كما يعطيها `WKSecurityOrigin` (المنفذ 0 = الافتراضيّ)
    public static func normalize(scheme: String, host: String, port: Int) -> String? {
        let scheme = scheme.lowercased()
        guard let defaultPort = defaultPorts[scheme] else { return nil }
        guard let host = plainHost(host) else { return nil }
        let effective = port > 0 ? port : defaultPort
        guard (1...65535).contains(effective) else { return nil }
        return format(Parts(scheme: scheme, host: host, port: effective), explicitPort: true)
    }

    // MARK: - داخليّ

    struct Parts: Equatable {
        let scheme: String
        let host: String
        let port: Int
    }

    private static let defaultPorts = ["https": 443, "http": 80]

    /// الأصلُ في الإعداد: مخطّطٌ ومضيفٌ ومنفذٌ اختياريّ، لا مسارَ ولا استعلامَ ولا مستخدم
    private static func configuredParts(_ raw: String) -> Parts? {
        let trimmed = raw.trimmingCharacters(in: .whitespacesAndNewlines)
        guard let components = strictComponents(trimmed),
              components.user == nil, components.password == nil,
              components.percentEncodedPath.isEmpty || components.percentEncodedPath == "/",
              components.percentEncodedQuery == nil, components.percentEncodedFragment == nil
        else { return nil }
        return parts(of: components)
    }

    /// لا مسافاتٍ ولا محارفَ تحكّم ولا شرطةً مائلةً عكسيّة: لا يُترك للمحلِّل أن يخمّن
    private static func strictComponents(_ string: String) -> URLComponents? {
        guard !string.isEmpty else { return nil }
        for scalar in string.unicodeScalars {
            if scalar.value <= 0x20 || scalar.value == 0x7F || scalar == "\\" || scalar.value > 0x7E {
                return nil
            }
        }
        return URLComponents(string: string)
    }

    private static func parts(of components: URLComponents) -> Parts? {
        guard let scheme = components.scheme?.lowercased(),
              let defaultPort = defaultPorts[scheme],
              let rawHost = components.percentEncodedHost, !rawHost.isEmpty
        else { return nil }
        guard let host = plainHost(rawHost) else { return nil }
        let port = components.port ?? defaultPort
        guard (1...65535).contains(port) else { return nil }
        return Parts(scheme: scheme, host: host, port: port)
    }

    /// اسمٌ أو IPv4 أو IPv6 (بقوسين أو بدونهما) بأحرفٍ صغيرة — بلا ترميز `%` ولا محارفَ أخرى.
    /// ويُعاد IPv6 بلا قوسين، فيضعهما `format` وحده.
    private static func plainHost(_ raw: String) -> String? {
        var host = raw.lowercased()
        if host.hasPrefix("[") {
            guard host.hasSuffix("]"), host.count > 2 else { return nil }
            host = String(host.dropFirst().dropLast())
        }
        guard !host.isEmpty else { return nil }
        if host.contains(":") {
            return host.allSatisfy { ($0.isASCII && $0.isHexDigit) || $0 == ":" || $0 == "." } ? host : nil
        }
        return host.allSatisfy { ($0.isASCII && ($0.isLetter || $0.isNumber)) || $0 == "-" || $0 == "." } ? host : nil
    }

    private static func format(_ parts: Parts, explicitPort: Bool) -> String {
        let host = parts.host.contains(":") ? "[\(parts.host)]" : parts.host
        if !explicitPort, defaultPorts[parts.scheme] == parts.port {
            return "\(parts.scheme)://\(host)"
        }
        return "\(parts.scheme)://\(host):\(parts.port)"
    }
}
