import Foundation
import WebKit

/*
 * الجسرُ في WKWebView — §1 و§4.1.
 *
 * ═══ ما يحقنه ═══
 *
 * سطرٌ يضع `window.__abaadPrinterConfig`، ثمّ `shared/js/abaad-printer-shim.js`
 * كما هو، عند بدء المستند وفي الإطار الرئيسيّ وحده. والأصولُ الموثوقة في ذلك
 * السطر مرمَّزةٌ بـJSONSerialization، لا بلصق نصّ.
 *
 * ═══ وما لا يثق به ═══
 *
 * لا يثق بالـshim ولا بالصفحة: كلُّ رسالةٍ تمرّ بـ`BridgeRequest.decide` من جديد
 * — الإطارُ رئيسيّ، وأصلُه (`frameInfo.securityOrigin`) وأصلُ الصفحة
 * (`webView.url`) موثوقان ومتساويان، والحمولةُ كلُّها صالحة — قبل أيّ تنزيلٍ أو
 * اتّصال. والردُّ يُمرَّر وسيطًا إلى `callAsyncJavaScript`، ولا يُبنى نصُّ
 * JavaScript بلصق القيم.
 */
@MainActor
public final class AbaadPrinterBridge: NSObject, WKScriptMessageHandler {
    public nonisolated static let handlerName = "abaadPrinter"
    nonisolated static let receiveCall = "window.__abaadPrinterReceive(m)"

    public let trustedOrigins: TrustedOrigins
    private let shimSource: String
    private let presenter: ReceiptPresenting?
    private let runner: PrintJobRunner

    /// `shimSource`: نصُّ `abaad-printer-shim.js` من حزمة التطبيق — الحزمةُ هذه لا تحمله
    public init(
        trustedOrigins: TrustedOrigins,
        shimSource: String,
        presenter: ReceiptPresenting?,
        runner: PrintJobRunner = PrintJobRunner()
    ) {
        self.trustedOrigins = trustedOrigins
        self.shimSource = shimSource
        self.presenter = presenter
        self.runner = runner
        super.init()
    }

    /// يُنادى قبل إنشاء الـWKWebView بهذا الإعداد
    public func install(on configuration: WKWebViewConfiguration) {
        let controller = configuration.userContentController
        let script = WKUserScript(
            source: Self.prelude(trustedOrigins: trustedOrigins) + shimSource,
            injectionTime: .atDocumentStart,
            forMainFrameOnly: true
        )
        controller.addUserScript(script)
        controller.removeScriptMessageHandler(forName: Self.handlerName)
        // المتحكّمُ يمسك معالجَه بقوّة: وسيطٌ ضعيف يمنع الدورة
        controller.add(WeakScriptMessageHandler(self), name: Self.handlerName)
    }

    /// السطرُ الذي يسبق الـshim — §1
    public nonisolated static func prelude(trustedOrigins: TrustedOrigins) -> String {
        let data = (try? JSONSerialization.data(withJSONObject: trustedOrigins.webOrigins, options: [.withoutEscapingSlashes]))
            ?? Data("[]".utf8)
        let origins = String(data: data, encoding: .utf8) ?? "[]"
        return "window.__abaadPrinterConfig = { platform: 'ios', trustedOrigins: \(origins), "
            + "post: function (s) { window.webkit.messageHandlers.abaadPrinter.postMessage(s); }, "
            + "listen: function (r) { window.__abaadPrinterReceive = r; } };\n"
    }

    public func userContentController(_ userContentController: WKUserContentController, didReceive message: WKScriptMessage) {
        guard let webView = message.webView else {
            PrintLog.dropped("no web view")
            return
        }
        let security = message.frameInfo.securityOrigin
        let origin = MessageOrigin(
            isMainFrame: message.frameInfo.isMainFrame,
            frameOrigin: TrustedOrigins.normalize(scheme: security.protocol, host: security.host, port: security.port),
            pageURL: webView.url
        )

        switch BridgeRequest.decide(body: message.body, origin: origin, trusted: trustedOrigins) {
        case let .drop(reason):
            PrintLog.dropped(reason)
        case let .reject(id, result):
            PrintLog.rejected(id, code: result.code ?? .internalError)
            ReplyChannel(webView: webView, trustedOrigins: trustedOrigins).send(.result(id: id, result: result))
        case let .accept(job):
            start(job, in: webView)
        }
    }

    // MARK: - داخليّ

    private func start(_ job: BridgeJob, in webView: WKWebView) {
        let cookieStore = webView.configuration.websiteDataStore.httpCookieStore
        let cookies: PrintJobRunner.CookieHeaderProvider = { [weak cookieStore] url in
            guard let cookieStore else { return [:] }
            let all = await withCheckedContinuation { (continuation: CheckedContinuation<[HTTPCookie], Never>) in
                cookieStore.getAllCookies { continuation.resume(returning: $0) }
            }
            return CookieFilter.requestHeaders(all, for: url)
        }

        // المراحلُ والنتيجةُ على الطابور الرئيسيّ بترتيبها: FIFO، فلا تسبق نتيجةٌ مرحلتَها
        let id = job.id
        let channel = ReplyChannel(webView: webView, trustedOrigins: trustedOrigins)
        let progress: @Sendable (BridgeStage) -> Void = { stage in
            DispatchQueue.main.async {
                channel.send(.progress(id: id, stage: stage))
            }
        }

        let runner = self.runner
        let presenter = self.presenter
        Task.detached(priority: .userInitiated) {
            let result = await runner.run(job, cookies: cookies, presenter: presenter, progress: progress)
            DispatchQueue.main.async {
                channel.send(.result(id: id, result: result))
            }
        }
    }
}

/*
 * طريقُ الردّ إلى الصفحة. يمسك الـWebView ضعيفًا: إن أُغلقت أثناء الطباعة
 * يضيع الردّ ولا تبقى حيّةً لأجله.
 */
@MainActor
final class ReplyChannel {
    private weak var webView: WKWebView?
    private let trustedOrigins: TrustedOrigins

    init(webView: WKWebView, trustedOrigins: TrustedOrigins) {
        self.webView = webView
        self.trustedOrigins = trustedOrigins
    }

    /// إلى الصفحة الحاليّة إن كانت موثوقة — فإن تنقّلت إلى غيرها لا يصلها شيء
    func send(_ message: BridgeOutgoing) {
        guard let webView, trustedOrigins.isTrusted(url: webView.url), let json = message.jsonString() else { return }
        webView.callAsyncJavaScript(AbaadPrinterBridge.receiveCall, arguments: ["m": json], in: nil, in: .page, completionHandler: nil)
    }
}

/// يمرّر الرسائل إلى الجسر دون أن يمسكه — `WKUserContentController` يمسك معالجاته بقوّة
@MainActor
final class WeakScriptMessageHandler: NSObject, WKScriptMessageHandler {
    private weak var target: WKScriptMessageHandler?

    init(_ target: WKScriptMessageHandler) {
        self.target = target
    }

    func userContentController(_ userContentController: WKUserContentController, didReceive message: WKScriptMessage) {
        target?.userContentController(userContentController, didReceive: message)
    }
}
