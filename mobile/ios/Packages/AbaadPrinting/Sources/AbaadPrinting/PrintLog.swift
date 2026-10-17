import Foundation
import os

/*
 * سجلُّ الطباعة — §9.
 *
 * يُسجَّل: رقمُ المهمّة، والطريقة، والمراحل، والمدد، ورمزُ الخطأ، و`host:port`
 * الطابعة، وعددُ البايتات، ومسارُ الرابط بلا استعلام (خاصًّا).
 *
 * ولا يُسجَّل أبدًا: الكوكيز، ورؤوسُ الطلب، والاستعلام (`?expected_business_id=…`)،
 * ومحتوى الإيصال أو بايتاتُه. فلا دالّةَ هنا تقبل شيئًا منها أصلًا.
 */
public enum PrintLog {
    static let logger = Logger(subsystem: "om.abaad.pos", category: "printer")

    public static func started(_ jobId: String, method: BridgeMethod) {
        logger.info("job \(jobId, privacy: .public) \(method.rawValue, privacy: .public) started")
    }

    public static func stage(_ jobId: String, _ stage: BridgeStage, elapsedMilliseconds: Int) {
        logger.info("job \(jobId, privacy: .public) stage \(stage.rawValue, privacy: .public) at \(elapsedMilliseconds, privacy: .public)ms")
    }

    /// المسارُ وحده من الرابط — بلا مضيفٍ ولا استعلامٍ ولا جزء
    public static func download(_ jobId: String, url: URL, outcome: ErrorCode?, bytes: Int, milliseconds: Int) {
        let path = url.path
        logger.info("job \(jobId, privacy: .public) receipt \(path, privacy: .private) \(outcome?.rawValue ?? "ok", privacy: .public) \(bytes, privacy: .public)B \(milliseconds, privacy: .public)ms")
    }

    public static func printer(_ jobId: String, host: String, port: Int, result: DriverResult) {
        logger.info("job \(jobId, privacy: .public) printer \(host, privacy: .public):\(port, privacy: .public) \(result.error?.rawValue ?? "ok", privacy: .public) sent \(result.bytesSent, privacy: .public)B connect \(result.connectMilliseconds ?? -1, privacy: .public)ms send \(result.sendMilliseconds ?? -1, privacy: .public)ms")
    }

    public static func finished(_ jobId: String, method: BridgeMethod, code: ErrorCode?, milliseconds: Int) {
        if let code {
            logger.error("job \(jobId, privacy: .public) \(method.rawValue, privacy: .public) failed \(code.rawValue, privacy: .public) after \(milliseconds, privacy: .public)ms")
        } else {
            logger.info("job \(jobId, privacy: .public) \(method.rawValue, privacy: .public) done after \(milliseconds, privacy: .public)ms")
        }
    }

    public static func rejected(_ jobId: String, code: ErrorCode) {
        logger.error("job \(jobId, privacy: .public) rejected \(code.rawValue, privacy: .public)")
    }

    public static func dropped(_ reason: String) {
        logger.error("message dropped: \(reason, privacy: .public)")
    }
}
