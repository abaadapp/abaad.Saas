import AbaadPrinting
import UIKit
import WebKit

/*
 * الصدفة — §10.
 *
 *   - أصلٌ موثوق ← يُحمَّل داخل الـWebView.
 *   - أيُّ رابطٍ آخر ← المتصفّحُ الخارجيّ، ولا يُحمَّل هنا أبدًا.
 *   - `window.open` أو `target=_blank` إلى أصلٍ موثوق ← يُنزَّل بالجلسة نفسِها:
 *     PDF ← حوارُ الطباعة كما في `openReceipt`، وغيرُه ← الـWebView الرئيسيّة.
 *
 * والجلسةُ في `websiteDataStore = .default()`: تبقى بين التشغيلات كما في Safari،
 * ومنها يأخذ الجسرُ الكوكيز حين ينزّل الإيصال.
 */
final class WebViewController: UIViewController {
    private let config: AppConfig
    private let presenter = ReceiptPresenter()
    private var bridge: AbaadPrinterBridge?
    private var webView: WKWebView?

    init(config: AppConfig) {
        self.config = config
        super.init(nibName: nil, bundle: nil)
    }

    @available(*, unavailable)
    required init?(coder: NSCoder) {
        fatalError("init(coder:) is not used")
    }

    override func viewDidLoad() {
        super.viewDidLoad()
        view.backgroundColor = .systemBackground
        presenter.hostView = view

        guard config.isUsable, let baseURL = config.baseURL else {
            showMissingConfiguration()
            return
        }

        let configuration = WKWebViewConfiguration()
        configuration.websiteDataStore = .default()
        if let shim = Self.loadShim() {
            let bridge = AbaadPrinterBridge(trustedOrigins: config.trustedOrigins, shimSource: shim, presenter: presenter)
            bridge.install(on: configuration)
            self.bridge = bridge
        }

        let webView = WKWebView(frame: .zero, configuration: configuration)
        webView.navigationDelegate = self
        webView.uiDelegate = self
        webView.allowsBackForwardNavigationGestures = false
        webView.translatesAutoresizingMaskIntoConstraints = false
        view.addSubview(webView)
        // داخل المنطقة الآمنة: لا تختفي أزرارُ الصفحة خلف الشقّ أو شريط الرجوع
        let guide = view.safeAreaLayoutGuide
        NSLayoutConstraint.activate([
            webView.leadingAnchor.constraint(equalTo: guide.leadingAnchor),
            webView.trailingAnchor.constraint(equalTo: guide.trailingAnchor),
            webView.topAnchor.constraint(equalTo: guide.topAnchor),
            webView.bottomAnchor.constraint(equalTo: guide.bottomAnchor),
        ])
        self.webView = webView
        webView.load(URLRequest(url: baseURL))
    }

    /// الـshim من حزمة التطبيق (`mobile/shared/js/abaad-printer-shim.js` كما هو).
    /// غيابُه لا يوقف التطبيق: تبقى الطباعةُ من المتصفّح كما في الويب.
    private static func loadShim() -> String? {
        guard let url = Bundle.main.url(forResource: "abaad-printer-shim", withExtension: "js"),
              let source = try? String(contentsOf: url, encoding: .utf8)
        else {
            assertionFailure("abaad-printer-shim.js is missing from the app bundle")
            return nil
        }
        return source
    }

    private func showMissingConfiguration() {
        let label = UILabel()
        label.text = "لم يُضبط عنوان أبعاد في إعداد البناء"
        label.textAlignment = .center
        label.numberOfLines = 0
        label.font = .preferredFont(forTextStyle: .headline)
        label.textColor = .secondaryLabel
        label.translatesAutoresizingMaskIntoConstraints = false
        view.addSubview(label)
        let guide = view.readableContentGuide
        NSLayoutConstraint.activate([
            label.leadingAnchor.constraint(equalTo: guide.leadingAnchor),
            label.trailingAnchor.constraint(equalTo: guide.trailingAnchor),
            label.centerYAnchor.constraint(equalTo: view.centerYAnchor),
        ])
    }

    // MARK: - التوجيه

    private func isTrustedWeb(_ url: URL) -> Bool {
        let scheme = url.scheme?.lowercased()
        return (scheme == "https" || scheme == "http") && config.trustedOrigins.isTrusted(url: url)
    }

    /// إلى التطبيق المناسب خارجيًّا — وما لا معنى له خارج الصفحة (javascript وblob وdata وfile) لا يخرج
    private func openExternally(_ url: URL) {
        guard let scheme = url.scheme?.lowercased(),
              !["javascript", "blob", "data", "file", "about"].contains(scheme)
        else { return }
        UIApplication.shared.open(url, options: [:], completionHandler: nil)
    }

    /// نافذةٌ جديدة إلى أصلٍ موثوق: PDF ← حوارُ الطباعة، وغيرُه ← الـWebView الرئيسيّة
    private func openTrustedWindow(_ url: URL, from webView: WKWebView) {
        let store = webView.configuration.websiteDataStore.httpCookieStore
        Task { [weak self, weak webView] in
            let cookies = await withCheckedContinuation { (continuation: CheckedContinuation<[HTTPCookie], Never>) in
                store.getAllCookies { continuation.resume(returning: $0) }
            }
            let result = await ReceiptDownloader().fetch(url, headers: CookieFilter.requestHeaders(cookies, for: url))
            guard let self, let webView else { return }
            if case let .success(pdf) = result, await self.presenter.present(pdf: pdf, jobName: url.lastPathComponent) {
                return
            }
            // ليس PDF، أو انتهت الجلسة ← يُفتح هنا، فتظهر الصفحةُ أو شاشةُ الدخول
            webView.load(URLRequest(url: url))
        }
    }

    // MARK: - حوارات JavaScript

    /// يُعرض الحوارُ إن أمكن، وإلّا يُجاب فورًا — فلا تتعلّق الصفحة بانتظار ردٍّ لا يأتي
    private func presentPanel(_ alert: UIAlertController, otherwise fallback: () -> Void) {
        guard viewIfLoaded?.window != nil, presentedViewController == nil else {
            fallback()
            return
        }
        present(alert, animated: true)
    }
}

extension WebViewController: WKNavigationDelegate {
    func webView(
        _ webView: WKWebView,
        decidePolicyFor navigationAction: WKNavigationAction,
        decisionHandler: @escaping @MainActor @Sendable (WKNavigationActionPolicy) -> Void
    ) {
        guard let url = navigationAction.request.url else {
            decisionHandler(.cancel)
            return
        }
        let scheme = url.scheme?.lowercased() ?? ""

        guard let target = navigationAction.targetFrame else {
            // نافذةٌ جديدة: يقرّرها `createWebViewWith` أدناه
            decisionHandler(.allow)
            return
        }

        if !target.isMainFrame {
            // الإطاراتُ الفرعيّة لا تبلغ الجسر (الإطارُ الرئيسيّ وحده)، فتُترك لمحتواها
            decisionHandler(["http", "https", "about", "data", "blob"].contains(scheme) ? .allow : .cancel)
            return
        }

        if scheme == "about" || isTrustedWeb(url) {
            decisionHandler(.allow)
            return
        }
        decisionHandler(.cancel)
        openExternally(url)
    }

    func webViewWebContentProcessDidTerminate(_ webView: WKWebView) {
        // انهار محرّكُ الصفحة (ذاكرةٌ مثلًا) ← تُعاد بدل شاشةٍ بيضاء
        webView.reload()
    }
}

extension WebViewController: WKUIDelegate {
    func webView(
        _ webView: WKWebView,
        createWebViewWith configuration: WKWebViewConfiguration,
        for navigationAction: WKNavigationAction,
        windowFeatures: WKWindowFeatures
    ) -> WKWebView? {
        // لا نوافذَ منبثقة في التطبيق أصلًا: يُوجَّه الرابطُ ويُعاد nil
        if let url = navigationAction.request.url {
            if isTrustedWeb(url) {
                openTrustedWindow(url, from: webView)
            } else {
                openExternally(url)
            }
        }
        return nil
    }

    func webView(
        _ webView: WKWebView,
        runJavaScriptAlertPanelWithMessage message: String,
        initiatedByFrame frame: WKFrameInfo,
        completionHandler: @escaping @MainActor @Sendable () -> Void
    ) {
        let alert = UIAlertController(title: nil, message: message, preferredStyle: .alert)
        alert.addAction(UIAlertAction(title: "حسنًا", style: .default) { _ in completionHandler() })
        presentPanel(alert, otherwise: completionHandler)
    }

    func webView(
        _ webView: WKWebView,
        runJavaScriptConfirmPanelWithMessage message: String,
        initiatedByFrame frame: WKFrameInfo,
        completionHandler: @escaping @MainActor @Sendable (Bool) -> Void
    ) {
        let alert = UIAlertController(title: nil, message: message, preferredStyle: .alert)
        alert.addAction(UIAlertAction(title: "إلغاء", style: .cancel) { _ in completionHandler(false) })
        alert.addAction(UIAlertAction(title: "تأكيد", style: .default) { _ in completionHandler(true) })
        presentPanel(alert) { completionHandler(false) }
    }

    func webView(
        _ webView: WKWebView,
        runJavaScriptTextInputPanelWithPrompt prompt: String,
        defaultText: String?,
        initiatedByFrame frame: WKFrameInfo,
        completionHandler: @escaping @MainActor @Sendable (String?) -> Void
    ) {
        let alert = UIAlertController(title: nil, message: prompt, preferredStyle: .alert)
        alert.addTextField { $0.text = defaultText }
        alert.addAction(UIAlertAction(title: "إلغاء", style: .cancel) { _ in completionHandler(nil) })
        alert.addAction(UIAlertAction(title: "حسنًا", style: .default) { [weak alert] _ in
            completionHandler(alert?.textFields?.first?.text ?? "")
        })
        presentPanel(alert) { completionHandler(nil) }
    }
}
