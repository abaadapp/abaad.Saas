import Foundation

/*
 * تنزيلُ الإيصال بجلسة الـWebView — §5 الخطوة 1.
 *
 * ═══ والجلسةُ كوكيز تُمرَّر، لا مخزنٌ يُشارَك ═══
 *
 * `URLSession` هنا مؤقّتة بلا مخزن كوكيز ولا ذاكرة: يحمل الطلبُ رأسَ `Cookie`
 * الذي بناه المستدعي من كوكيز الـWebView المطابقة للرابط، ولا يُكتب شيءٌ من
 * الردّ في أيّ مخزن. ولا يُسجَّل الرأسُ ولا الكوكيز.
 *
 * ═══ ولا تُتّبع التحويلات ═══
 *
 * انتهاءُ الجلسة في أبعاد تحويلٌ إلى صفحة الدخول. فلو اتُّبع لنُزِّلت صفحةُ HTML
 * وقيل «ليس PDF». والتحويلُ قد يأخذ الكوكيز إلى مكانٍ آخر. فيُرفض، ويصير
 * الـ3xx نفسُه ردًّا: SESSION_EXPIRED.
 */
public protocol ReceiptFetching: Sendable {
    /// `headers`: رأسُ `Cookie` وحده كما يبنيه `CookieFilter.requestHeaders`
    func fetch(_ url: URL, headers: [String: String]) async -> Result<Data, PrintingError>
}

public final class ReceiptDownloader: ReceiptFetching {
    public let timeout: TimeInterval
    public let maxBytes: Int

    public init(timeout: TimeInterval = 20, maxBytes: Int = 10 * 1024 * 1024) {
        self.timeout = timeout
        self.maxBytes = maxBytes
    }

    public func fetch(_ url: URL, headers: [String: String]) async -> Result<Data, PrintingError> {
        guard let scheme = url.scheme?.lowercased(), scheme == "https" || scheme == "http" else {
            return .failure(PrintingError(.receiptDownloadFailed))
        }

        var request = URLRequest(url: url, cachePolicy: .reloadIgnoringLocalAndRemoteCacheData, timeoutInterval: timeout)
        request.httpMethod = "GET"
        request.httpShouldHandleCookies = false
        request.setValue("application/pdf", forHTTPHeaderField: "Accept")
        if let cookie = headers["Cookie"], !cookie.isEmpty {
            request.setValue(cookie, forHTTPHeaderField: "Cookie")
        }

        let configuration = URLSessionConfiguration.ephemeral
        configuration.httpCookieStorage = nil
        configuration.httpShouldSetCookies = false
        configuration.httpCookieAcceptPolicy = .never
        configuration.urlCache = nil
        configuration.urlCredentialStorage = nil
        configuration.requestCachePolicy = .reloadIgnoringLocalAndRemoteCacheData
        configuration.timeoutIntervalForRequest = timeout
        configuration.timeoutIntervalForResource = timeout

        let download = Download(maxBytes: maxBytes)
        return await withCheckedContinuation { continuation in
            download.start(request: request, configuration: configuration, continuation: continuation)
        }
    }

    /// تنزيلٌ واحد: الجلسةُ تحمل مندوبَه حتى تنتهي ثمّ تُبطَل
    private final class Download: NSObject, URLSessionDataDelegate, @unchecked Sendable {
        private let maxBytes: Int
        private let lock = NSLock()
        private var continuation: CheckedContinuation<Result<Data, PrintingError>, Never>?
        private var body = Data()

        init(maxBytes: Int) {
            self.maxBytes = maxBytes
        }

        func start(
            request: URLRequest,
            configuration: URLSessionConfiguration,
            continuation: CheckedContinuation<Result<Data, PrintingError>, Never>
        ) {
            lock.lock()
            self.continuation = continuation
            lock.unlock()
            let session = URLSession(configuration: configuration, delegate: self, delegateQueue: nil)
            session.dataTask(with: request).resume()
            // يُبطَل بعد انتهاء المهمّة، فيُطلَق المندوب
            session.finishTasksAndInvalidate()
        }

        /// يُستأنف المنتظِرُ مرّةً واحدة مهما تتابعت الأحداث بعدها
        private func finish(_ result: Result<Data, PrintingError>) {
            lock.lock()
            let continuation = self.continuation
            self.continuation = nil
            lock.unlock()
            continuation?.resume(returning: result)
        }

        func urlSession(
            _ session: URLSession,
            task: URLSessionTask,
            willPerformHTTPRedirection response: HTTPURLResponse,
            newRequest request: URLRequest,
            completionHandler: @escaping (URLRequest?) -> Void
        ) {
            completionHandler(nil)
        }

        func urlSession(
            _ session: URLSession,
            dataTask: URLSessionDataTask,
            didReceive response: URLResponse,
            completionHandler: @escaping (URLSession.ResponseDisposition) -> Void
        ) {
            guard let http = response as? HTTPURLResponse else {
                finish(.failure(PrintingError(.receiptDownloadFailed)))
                completionHandler(.cancel)
                return
            }
            if let code = ReceiptDownloader.failure(forStatus: http.statusCode) {
                finish(.failure(PrintingError(code)))
                completionHandler(.cancel)
                return
            }
            if http.expectedContentLength > Int64(maxBytes) {
                finish(.failure(PrintingError(.receiptDownloadFailed)))
                completionHandler(.cancel)
                return
            }
            completionHandler(.allow)
        }

        func urlSession(_ session: URLSession, dataTask: URLSessionDataTask, didReceive data: Data) {
            lock.lock()
            body.append(data)
            let tooLarge = body.count > maxBytes
            if tooLarge { body = Data() }
            lock.unlock()
            if tooLarge {
                finish(.failure(PrintingError(.receiptDownloadFailed)))
                dataTask.cancel()
            }
        }

        func urlSession(_ session: URLSession, task: URLSessionTask, didCompleteWithError error: Error?) {
            if error != nil {
                finish(.failure(PrintingError(.receiptDownloadFailed)))
                return
            }
            lock.lock()
            let data = body
            body = Data()
            lock.unlock()
            finish(ReceiptDownloader.isPDF(data) ? .success(data) : .failure(PrintingError(.receiptDownloadFailed)))
        }
    }

    /// 401 أو 419 أو 3xx ← SESSION_EXPIRED، وغيرُ 2xx ← RECEIPT_DOWNLOAD_FAILED، و2xx ← nil
    public static func failure(forStatus status: Int) -> ErrorCode? {
        if status == 401 || status == 419 || (300...399).contains(status) {
            return .sessionExpired
        }
        return (200...299).contains(status) ? nil : .receiptDownloadFailed
    }

    public static func isPDF(_ data: Data) -> Bool {
        data.count >= 4 && data.prefix(4).elementsEqual([0x25, 0x50, 0x44, 0x46]) // %PDF
    }
}

/*
 * كوكيز الـWebView المطابقة لرابطٍ بعينه — كما يرسلها المتصفّح (RFC 6265 §5.4):
 * النطاقُ والمسارُ و`Secure` والصلاحيّة. فلا يخرج كوكيز نطاقٍ آخر إلى الخادم.
 */
public enum CookieFilter {
    public static func matching(_ cookies: [HTTPCookie], for url: URL, now: Date = Date()) -> [HTTPCookie] {
        guard let host = url.host?.lowercased(), !host.isEmpty else { return [] }
        let secure = url.scheme?.lowercased() == "https"
        let path = url.path.isEmpty ? "/" : url.path
        return cookies.filter { cookie in
            if let expires = cookie.expiresDate, expires <= now { return false }
            if cookie.isSecure, !secure { return false }
            return domainMatches(cookie.domain.lowercased(), host: host) && pathMatches(cookie.path, requestPath: path)
        }
    }

    /// `["Cookie": "a=1; b=2"]` أو فارغ
    public static func requestHeaders(_ cookies: [HTTPCookie], for url: URL, now: Date = Date()) -> [String: String] {
        let matched = matching(cookies, for: url, now: now)
        guard !matched.isEmpty else { return [:] }
        return HTTPCookie.requestHeaderFields(with: matched)
    }

    static func domainMatches(_ domain: String, host: String) -> Bool {
        guard !domain.isEmpty else { return false }
        if domain.hasPrefix(".") {
            let bare = String(domain.dropFirst())
            return host == bare || host.hasSuffix(domain)
        }
        return host == domain
    }

    static func pathMatches(_ cookiePath: String, requestPath: String) -> Bool {
        let cookiePath = cookiePath.isEmpty ? "/" : cookiePath
        if requestPath == cookiePath { return true }
        guard requestPath.hasPrefix(cookiePath) else { return false }
        return cookiePath.hasSuffix("/") || requestPath.dropFirst(cookiePath.count).hasPrefix("/")
    }
}
