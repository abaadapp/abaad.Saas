import Foundation

/*
 * من رسالةٍ خام إلى مهمّةٍ فُحصت كلُّها — §3 و§4، قبل أيّ تنزيلٍ أو اتّصال.
 *
 * دالّةٌ بحتة: نصُّ الرسالة وما يُعرف عن مصدرها، ولا WKWebView. فتُختبر كما هي،
 * ويبقى الجسرُ نفسُه ناقلًا لا يقرّر شيئًا.
 *
 * ═══ وترتيبُ الفحص ═══
 *
 *   1. الإطارُ رئيسيّ — وإلّا تُهمل: الردُّ لا يصل إلّا الإطارَ الرئيسيّ، وهو لم يرسلها.
 *   2. `id` نصٌّ إلى 64 حرفًا — وإلّا تُهمل: لا يُعرف لمن يُردّ.
 *   3. الأصل: أصلُ الإطار موثوق، وأصلُ الصفحة (`webView.url`) موثوقٌ ويساويه ← UNTRUSTED_ORIGIN.
 *   4. `v` = 1، و`method` معروفة، و`payload` كائن ← INVALID_JOB.
 *   5. الحمولة: الاتّصال، ثمّ العنوان، ثمّ المنفذ، ثمّ رابطُ الإيصال — بترتيب §4.
 */

/// ما يُعرف عن مصدر الرسالة من WebKit — لا من الرسالة نفسِها
public struct MessageOrigin: Equatable, Sendable {
    public var isMainFrame: Bool
    /// أصلُ الإطار المرسِل (`WKFrameInfo.securityOrigin`) مطبَّعًا
    public var frameOrigin: String?
    /// عنوانُ الصفحة الحاليّة (`webView.url`)
    public var pageURL: URL?

    public init(isMainFrame: Bool, frameOrigin: String?, pageURL: URL?) {
        self.isMainFrame = isMainFrame
        self.frameOrigin = frameOrigin
        self.pageURL = pageURL
    }
}

public enum BridgeJobKind: Equatable, Sendable {
    case printReceipt(invoice: String, receiptURL: URL, printer: PrinterTarget)
    case testPrinter(printer: PrinterTarget, deviceName: String?, printerName: String?)
    case openReceipt(invoice: String, receiptURL: URL)
}

public struct BridgeJob: Equatable, Sendable {
    public let id: String
    public let kind: BridgeJobKind
    /// أصلُ الصفحة التي طلبت — مطبَّعًا
    public let pageOrigin: String

    public init(id: String, kind: BridgeJobKind, pageOrigin: String) {
        self.id = id
        self.kind = kind
        self.pageOrigin = pageOrigin
    }

    public var method: BridgeMethod {
        switch kind {
        case .printReceipt: return .printReceipt
        case .testPrinter: return .testPrinter
        case .openReceipt: return .openReceipt
        }
    }

    public var printer: PrinterTarget? {
        switch kind {
        case let .printReceipt(_, _, printer), let .testPrinter(printer, _, _): return printer
        case .openReceipt: return nil
        }
    }
}

public enum BridgeDecision: Equatable, Sendable {
    /// لا ردّ: لا يُعرف لمن، أو ليس من الإطار الرئيسيّ
    case drop(reason: String)
    /// ردٌّ بالفشل على `id`
    case reject(id: String, result: BridgeResult)
    case accept(BridgeJob)
}

public enum BridgeRequest {
    public static let version = 1
    public static let maxIdLength = 64
    /// الأسماءُ في صفحة الاختبار تُقصّ إلى 60 حرفًا — §3
    public static let maxNameLength = 60
    static let maxInvoiceLength = 64

    public static func decide(body: Any?, origin: MessageOrigin, trusted: TrustedOrigins) -> BridgeDecision {
        guard origin.isMainFrame else {
            return .drop(reason: "not main frame")
        }
        guard let text = body as? String,
              let data = text.data(using: .utf8),
              let object = (try? JSONSerialization.jsonObject(with: data)) as? [String: Any]
        else {
            return .drop(reason: "not a JSON object string")
        }
        // طولُ النصّ بوحدات UTF-16 كما يعدّه JavaScript
        guard let id = object["id"] as? String, !id.isEmpty, id.utf16.count <= maxIdLength else {
            return .drop(reason: "invalid id")
        }

        guard let pageOrigin = trustedPageOrigin(origin, trusted: trusted) else {
            return reject(id, .untrustedOrigin)
        }

        guard let v = JSONNumbers.integer(object["v"]), v == version,
              let methodName = object["method"] as? String,
              let method = BridgeMethod(rawValue: methodName),
              let payload = object["payload"] as? [String: Any]
        else {
            return reject(id, .invalidJob)
        }

        switch method {
        case .printReceipt:
            guard let invoice = invoice(payload["invoice"]) else { return reject(id, .invalidJob) }
            let printer: PrinterTarget
            switch PrinterTarget.parse(payload["printer"]) {
            case let .success(target): printer = target
            case let .failure(rejection): return reject(id, rejection.code, effectivePort: rejection.effectivePort)
            }
            guard let url = receiptURL(payload["receiptUrl"], pageOrigin: pageOrigin, trusted: trusted) else {
                return reject(id, .receiptURLRejected, effectivePort: printer.port)
            }
            return .accept(BridgeJob(id: id, kind: .printReceipt(invoice: invoice, receiptURL: url, printer: printer), pageOrigin: pageOrigin))

        case .testPrinter:
            let printer: PrinterTarget
            switch PrinterTarget.parse(payload["printer"]) {
            case let .success(target): printer = target
            case let .failure(rejection): return reject(id, rejection.code, effectivePort: rejection.effectivePort)
            }
            guard let deviceName = optionalName(payload["deviceName"]),
                  let printerName = optionalName(payload["printerName"])
            else { return reject(id, .invalidJob, effectivePort: printer.port) }
            return .accept(BridgeJob(id: id, kind: .testPrinter(printer: printer, deviceName: deviceName.value, printerName: printerName.value), pageOrigin: pageOrigin))

        case .openReceipt:
            guard let invoice = invoice(payload["invoice"]) else { return reject(id, .invalidJob) }
            guard let url = receiptURL(payload["receiptUrl"], pageOrigin: pageOrigin, trusted: trusted) else {
                return reject(id, .receiptURLRejected)
            }
            return .accept(BridgeJob(id: id, kind: .openReceipt(invoice: invoice, receiptURL: url), pageOrigin: pageOrigin))
        }
    }

    /// الإطارُ والصفحةُ كلاهما موثوقان ومتساويان — §4.1
    public static func trustedPageOrigin(_ origin: MessageOrigin, trusted: TrustedOrigins) -> String? {
        guard origin.isMainFrame,
              let frame = origin.frameOrigin.flatMap(TrustedOrigins.normalize),
              let pageURL = origin.pageURL,
              let page = TrustedOrigins.normalize(url: pageURL),
              frame == page,
              trusted.isTrusted(page)
        else { return nil }
        return page
    }

    // MARK: - داخليّ

    private static func reject(_ id: String, _ code: ErrorCode, effectivePort: Int? = nil) -> BridgeDecision {
        .reject(id: id, result: .failure(code, jobId: id, effectivePort: effectivePort))
    }

    private static func receiptURL(_ value: Any?, pageOrigin: String, trusted: TrustedOrigins) -> URL? {
        guard let string = value as? String else { return nil }
        return trusted.receiptURLIfAllowed(pageOrigin: pageOrigin, receiptURL: string)
    }

    /// رقمُ الفاتورة نصٌّ غيرُ فارغ — اسمًا لمهمّة الطباعة في النظام وحده
    private static func invoice(_ value: Any?) -> String? {
        guard let string = value as? String else { return nil }
        let trimmed = string.trimmingCharacters(in: .whitespacesAndNewlines)
        guard !trimmed.isEmpty else { return nil }
        return String(trimmed.prefix(maxInvoiceLength))
    }

    private struct OptionalName {
        let value: String?
    }

    /// غائبٌ أو null ← بلا اسم، ونصٌّ ← يُقصّ إلى 60 حرفًا، وما سوى ذلك ← INVALID_JOB
    private static func optionalName(_ value: Any?) -> OptionalName? {
        if value == nil || value is NSNull {
            return OptionalName(value: nil)
        }
        guard let string = value as? String else { return nil }
        let trimmed = string.trimmingCharacters(in: .whitespacesAndNewlines)
        return OptionalName(value: trimmed.isEmpty ? nil : String(trimmed.prefix(maxNameLength)))
    }
}
