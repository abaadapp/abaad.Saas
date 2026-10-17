import Foundation

/*
 * لغةُ العقد بين الصفحة والتطبيق — mobile/PROTOCOL.md §3 و§8.
 *
 * الرموزُ ورسائلُها هنا حرفيًّا كما في جدول §8: الواجهةُ تعرض الرسالة كما
 * تصلها، فلا تُعاد صياغتها في مكانٍ آخر.
 */

/// رموزُ الفشل التي يُعيدها الطرفُ الأصليّ — لا رمزَ خارج جدول §8
public enum ErrorCode: String, CaseIterable, Codable, Sendable {
    case untrustedOrigin = "UNTRUSTED_ORIGIN"
    case invalidJob = "INVALID_JOB"
    case receiptURLRejected = "RECEIPT_URL_REJECTED"
    case unsupportedConnection = "UNSUPPORTED_CONNECTION"
    case invalidPrinterAddress = "INVALID_PRINTER_ADDRESS"
    case invalidPort = "INVALID_PORT"
    case sessionExpired = "SESSION_EXPIRED"
    case receiptDownloadFailed = "RECEIPT_DOWNLOAD_FAILED"
    case receiptRenderFailed = "RECEIPT_RENDER_FAILED"
    case printerUnreachable = "PRINTER_UNREACHABLE"
    case portRefused = "PORT_REFUSED"
    case connectTimeout = "CONNECT_TIMEOUT"
    case sendFailed = "SEND_FAILED"
    case queueFull = "QUEUE_FULL"
    case internalError = "INTERNAL"

    /// الرسالةُ العربيّة كما في الجدول
    public var message: String {
        switch self {
        case .untrustedOrigin, .invalidJob, .receiptURLRejected:
            return "طلب طباعة مرفوض"
        case .unsupportedConnection:
            return "الطباعة المباشرة تدعم طابعات الشبكة فقط حاليًا"
        case .invalidPrinterAddress:
            return "عنوان الطابعة غير صالح — يجب أن يكون عنوانًا على الشبكة المحلية"
        case .invalidPort:
            return "رقم المنفذ غير صالح"
        case .sessionExpired:
            return "انتهت الجلسة — سجّل الدخول من جديد"
        case .receiptDownloadFailed:
            return "تعذر تنزيل الإيصال"
        case .receiptRenderFailed:
            return "تعذر تجهيز الإيصال للطباعة"
        case .printerUnreachable:
            return "تعذر الوصول إلى عنوان الطابعة"
        case .portRefused:
            return "المنفذ لا يستجيب"
        case .connectTimeout:
            return "انتهت مهلة الاتصال"
        case .sendFailed:
            return "انقطع الاتصال أثناء الإرسال — تحقق من الورق قبل إعادة الطباعة"
        case .queueFull:
            return "الطابعة مشغولة — حاول بعد لحظات"
        case .internalError:
            return "تعذرت الطباعة"
        }
    }
}

/// المراحلُ التي تراها الصفحة في `onProgress`
public enum BridgeStage: String, Codable, Sendable {
    case downloadingReceipt = "downloading_receipt"
    case rendering
    case connecting
    case sending
}

public enum BridgeMethod: String, Codable, Sendable {
    case printReceipt
    case testPrinter
    case openReceipt
}

/*
 * أطولُ صمتٍ تبدأ بعده مهمّةٌ أو تتّصل بالطابعة — §5.
 *
 * الصفحةُ تكفّ عن الانتظار إن لم تسمع عن الطلب شيئًا 120 ثانية للإيصال و60
 * للاختبار (الـshim)، فتقول للكاشير «لم يصل ردّ» وتعرض «إعادة الطباعة». ومهمّةٌ
 * تأخّرت في الطابور خلف إيصالٍ طويل ثمّ بدأت بعدها تطبع إيصالًا لا يعلم به أحد —
 * والإعادةُ فوقه إيصالٌ ثانٍ.
 *
 * فالصمتُ يُحسب من آخر ما قيل للصفحة (لحظةُ القبول إن لم يُقل شيء): مهمّةٌ صمتت
 * أكثر من هذه المهلة لا تبدأ ولا تتّصل، والردُّ QUEUE_FULL. وما بعد البدء لا يصمت
 * طويلًا: كلُّ مرحلةٍ تُقال، والجاريةُ تُعاد نبضةً كلَّ ثانية (`PageContact`).
 */
public enum ConnectDeadline {
    public static let printReceipt: TimeInterval = 75
    public static let testPrinter: TimeInterval = 15

    public static func limit(for method: BridgeMethod) -> TimeInterval? {
        switch method {
        case .printReceipt: return printReceipt
        case .testPrinter: return testPrinter
        case .openReceipt: return nil
        }
    }

    public static func expired(_ method: BridgeMethod, silentSince: Date, now: Date) -> Bool {
        guard let limit = limit(for: method) else { return false }
        return now.timeIntervalSince(silentSince) > limit
    }
}

/// خطأٌ يحمل رمزَه من العقد — يُرمى داخل المسار ويُترجم إلى `BridgeResult`
public struct PrintingError: Error, Equatable, Sendable {
    public let code: ErrorCode

    public init(_ code: ErrorCode) {
        self.code = code
    }
}

/// نتيجةُ كلّ طلب — §8. والمفاتيحُ الفارغة لا تُكتب أصلًا.
public struct BridgeResult: Encodable, Equatable, Sendable {
    public var ok: Bool
    public var jobId: String?
    public var code: ErrorCode?
    public var message: String?
    public var printed: Bool?
    public var connected: Bool?
    public var mayHavePrinted: Bool?
    public var effectivePort: Int?

    public static func success(
        jobId: String,
        printed: Bool? = nil,
        connected: Bool? = nil,
        effectivePort: Int? = nil,
        message: String? = nil
    ) -> BridgeResult {
        BridgeResult(
            ok: true, jobId: jobId, code: nil, message: message,
            printed: printed, connected: connected, mayHavePrinted: nil, effectivePort: effectivePort
        )
    }

    public static func failure(
        _ code: ErrorCode,
        jobId: String? = nil,
        connected: Bool? = nil,
        mayHavePrinted: Bool? = nil,
        effectivePort: Int? = nil
    ) -> BridgeResult {
        BridgeResult(
            ok: false, jobId: jobId, code: code, message: code.message,
            printed: nil, connected: connected, mayHavePrinted: mayHavePrinted, effectivePort: effectivePort
        )
    }
}

/// ما يُرسل إلى الصفحة: مرحلةٌ أو نتيجة — §3
public enum BridgeOutgoing: Equatable, Sendable {
    case progress(id: String, stage: BridgeStage)
    case result(id: String, result: BridgeResult)

    private struct ProgressBody: Encodable {
        let id: String
        let type = "progress"
        let stage: BridgeStage
    }

    private struct ResultBody: Encodable {
        let id: String
        let type = "result"
        let result: BridgeResult
    }

    /// نصُّ JSON الذي يُمرَّر وسيطًا إلى `__abaadPrinterReceive` — لا يُلصق في شيفرة
    public func jsonString() -> String? {
        let encoder = JSONEncoder()
        encoder.outputFormatting = [.sortedKeys, .withoutEscapingSlashes]
        let data: Data?
        switch self {
        case let .progress(id, stage):
            data = try? encoder.encode(ProgressBody(id: id, stage: stage))
        case let .result(id, result):
            data = try? encoder.encode(ResultBody(id: id, result: result))
        }
        return data.flatMap { String(data: $0, encoding: .utf8) }
    }
}
