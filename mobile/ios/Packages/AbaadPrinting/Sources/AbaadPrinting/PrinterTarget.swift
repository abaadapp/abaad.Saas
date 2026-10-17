import Foundation
#if canImport(Darwin)
import Darwin
#endif

/*
 * هدفُ الطباعة — §4.2 إلى §4.4 و§4.6.
 *
 * ═══ لماذا عنوانٌ على الشبكة المحليّة وحدها ═══
 *
 * الجسرُ يفتح مقبسَ TCP خامًا من جهاز الكاشير إلى ما تسمّيه الصفحة. فلو قُبل
 * أيُّ عنوان لصار التطبيقُ أداةً تطرق بها صفحةٌ مخترَقة خوادمَ الإنترنت أو
 * خدماتِ الجهاز نفسِه (127/8). فلا يمرّ إلّا:
 *
 *   - IPv4 خاصّ حرفيًّا: 10/8، 172.16/12، 192.168/16، 169.254/16.
 *   - IPv6 خاصّ حرفيًّا: ULA ‏fc00::/7 أو link-local ‏fe80::/10.
 *   - اسمُ mDNS ينتهي بـ`.local` — ثمّ يجب أن يمرّ كلُّ عنوانٍ يُحلّ إليه بالفحص نفسِه.
 *
 * ولا يُسأل DNS عن شيءٍ هنا: الفحصُ نصّيٌّ بحت، والحلُّ لأسماء `.local` وحدها
 * يجري في `HostResolver` قبل الاتّصال.
 */
public enum PrinterAddress: Equatable, Sendable {
    case ipv4(String)
    case ipv6(String)
    /// الاسمُ بأحرفٍ صغيرة — يُحلّ قبل الاتّصال
    case mdns(String)

    public enum Kind: String, Sendable {
        case ipv4, ipv6, mdns, invalid
    }

    public var kind: Kind {
        switch self {
        case .ipv4: return .ipv4
        case .ipv6: return .ipv6
        case .mdns: return .mdns
        }
    }

    /// النصُّ كما يُتّصل به (للحرفيّ) أو يُحلّ (للاسم)
    public var host: String {
        switch self {
        case let .ipv4(value), let .ipv6(value), let .mdns(value):
            return value
        }
    }

    /// يُقصّ أوّلًا، ثمّ يُصنَّف. nil ← INVALID_PRINTER_ADDRESS
    public static func parse(_ raw: String) -> PrinterAddress? {
        let value = raw.trimmingCharacters(in: .whitespacesAndNewlines)
        guard !value.isEmpty, value.count <= 253 else { return nil }
        if let literal = privateLiteral(value) {
            return literal
        }
        if !value.contains(":"), let name = mdnsName(value) {
            return .mdns(name)
        }
        return nil
    }

    public static func kind(of raw: String) -> Kind {
        parse(raw)?.kind ?? .invalid
    }

    /// عنوانٌ حرفيّ خاصّ وحده — وبه يُفحص كلُّ ما يُحلّ إليه اسمُ mDNS
    public static func privateLiteral(_ value: String) -> PrinterAddress? {
        if let octets = ipv4Octets(value) {
            return isPrivateIPv4(octets) ? .ipv4(value) : nil
        }
        if value.contains(":"), let bytes = ipv6Bytes(value) {
            return isPrivateIPv6(bytes) ? .ipv6(value.lowercased()) : nil
        }
        return nil
    }

    /*
     * IPv4 بيدٍ لا بـ`inet_aton`: تلك تقبل `192.168.1` و`0xC0.168.1.1` و`010.0.0.1`
     * (ثمانيًّا). هنا أربعةُ أجزاءٍ عشريّة بالضبط، بلا أصفارٍ بادئة إلّا «0» نفسَه،
     * وكلٌّ منها 0–255.
     */
    public static func ipv4Octets(_ value: String) -> [UInt8]? {
        let parts = value.split(separator: ".", omittingEmptySubsequences: false)
        guard parts.count == 4 else { return nil }
        var octets: [UInt8] = []
        for part in parts {
            guard !part.isEmpty, part.count <= 3,
                  part.allSatisfy({ $0 >= "0" && $0 <= "9" }),
                  part == "0" || !part.hasPrefix("0"),
                  let number = Int(part), number <= 255
            else { return nil }
            octets.append(UInt8(number))
        }
        return octets
    }

    public static func isPrivateIPv4(_ o: [UInt8]) -> Bool {
        guard o.count == 4 else { return false }
        switch (o[0], o[1]) {
        case (10, _): return true
        case (172, 16...31): return true
        case (192, 168): return true
        case (169, 254): return true
        default: return false
        }
    }

    /// IPv6 حرفيّ وحده: `inet_pton` لا يسأل DNS. والنطاقُ (`%en0`) والأقواسُ والمنفذ
    /// تُرفض قبله — فـ`inet_pton` في Darwin يقبل النطاق
    public static func ipv6Bytes(_ value: String) -> [UInt8]? {
        guard value.contains(":"), value.allSatisfy({ ($0.isASCII && $0.isHexDigit) || $0 == ":" || $0 == "." }) else { return nil }
        var address = in6_addr()
        let parsed = value.withCString { inet_pton(AF_INET6, $0, &address) }
        guard parsed == 1 else { return nil }
        return withUnsafeBytes(of: &address) { Array($0) }
    }

    public static func isPrivateIPv6(_ b: [UInt8]) -> Bool {
        guard b.count == 16 else { return false }
        let uniqueLocal = (b[0] & 0xFE) == 0xFC          // fc00::/7
        let linkLocal = b[0] == 0xFE && (b[1] & 0xC0) == 0x80 // fe80::/10
        return uniqueLocal || linkLocal
    }

    /// `printer.local`: تسمياتٌ من [a-z0-9-]، لا فارغة، ولا تبدأ ولا تنتهي بـ`-`
    static func mdnsName(_ value: String) -> String? {
        let name = value.lowercased()
        guard name.hasSuffix(".local") else { return nil }
        let labels = name.split(separator: ".", omittingEmptySubsequences: false)
        guard labels.count >= 2 else { return nil }
        for label in labels {
            guard !label.isEmpty, label.count <= 63,
                  label.allSatisfy({ ($0 >= "a" && $0 <= "z") || ($0 >= "0" && $0 <= "9") || $0 == "-" }),
                  !label.hasPrefix("-"), !label.hasSuffix("-")
            else { return nil }
        }
        return name
    }
}

/// المنفذ — §4.4: null ← 9100، وإلّا عددٌ صحيح من JSON بين 1 و65535
public enum PrinterPort {
    /// المنفذُ الافتراضيّ الموثَّق للطباعة الخام (RAW / JetDirect)
    public static let defaultRaw = 9100

    /// nil ← INVALID_PORT. النصُّ والكسرُ والمنطقيّ مرفوضة.
    public static func parse(_ value: Any?) -> Int? {
        if value == nil || value is NSNull {
            return defaultRaw
        }
        guard let port = JSONNumbers.integer(value), (1...65535).contains(port) else { return nil }
        return port
    }
}

/// هدفٌ فُحص كلُّه — لا يُبنى إلّا من `parse`
public struct PrinterTarget: Equatable, Sendable {
    public let address: PrinterAddress
    /// العنوانُ كما أُدخل بعد القصّ — لصفحة الاختبار والسجلّ
    public let displayAddress: String
    /// المنفذُ الذي يُتّصل به فعلًا، ويُعاد في كلّ نتيجة
    public let port: Int
    public let paper: PaperProfile

    public init(address: PrinterAddress, displayAddress: String, port: Int, paper: PaperProfile) {
        self.address = address
        self.displayAddress = displayAddress
        self.port = port
        self.paper = paper
    }

    /// رفضٌ بترتيب §4: الاتّصال، ثمّ العنوان، ثمّ المنفذ
    public struct Rejection: Error, Equatable, Sendable {
        public let code: ErrorCode
        public let effectivePort: Int?
    }

    public static func parse(_ json: Any?) -> Result<PrinterTarget, Rejection> {
        guard let object = json as? [String: Any] else {
            return .failure(Rejection(code: .invalidJob, effectivePort: nil))
        }
        guard (object["connection"] as? String) == "network" else {
            return .failure(Rejection(code: .unsupportedConnection, effectivePort: nil))
        }
        guard let rawAddress = object["address"] as? String, let address = PrinterAddress.parse(rawAddress) else {
            return .failure(Rejection(code: .invalidPrinterAddress, effectivePort: nil))
        }
        guard let port = PrinterPort.parse(object["port"]) else {
            return .failure(Rejection(code: .invalidPort, effectivePort: nil))
        }
        return .success(PrinterTarget(
            address: address,
            displayAddress: rawAddress.trimmingCharacters(in: .whitespacesAndNewlines),
            port: port,
            paper: PaperProfile.fromJSON(object["paperWidth"])
        ))
    }
}
