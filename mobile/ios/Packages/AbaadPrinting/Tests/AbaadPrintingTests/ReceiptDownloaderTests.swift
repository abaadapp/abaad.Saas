import Foundation
import Testing
@testable import AbaadPrinting

@Suite("Receipt downloader")
struct ReceiptDownloaderTests {
    func response(_ status: String, headers: [String] = [], body: Data = Data()) -> Data {
        var head = "HTTP/1.1 \(status)\r\n"
        for header in headers + ["Content-Length: \(body.count)", "Connection: close"] {
            head += header + "\r\n"
        }
        return Data((head + "\r\n").utf8) + body
    }

    func download(_ reply: Data, maxBytes: Int = 10 * 1024 * 1024) async throws -> (Result<Data, PrintingError>, [String]) {
        let server = LoopbackServer(response: reply)
        let port = try await server.start()
        defer { server.stop() }
        let url = URL(string: "http://127.0.0.1:\(port)/pos/receipt/INV-1/pdf?expected_business_id=3")!
        let result = await ReceiptDownloader(timeout: 5, maxBytes: maxBytes).fetch(url, headers: ["Cookie": "abaad_session=s3cret"])
        let requests = await server.requests(timeout: 1)
        return (result, requests.map { String(decoding: $0, as: UTF8.self) })
    }

    @Test("a PDF is returned, and the request carries Accept and the session cookie")
    func pdf() async throws {
        let body = Data("%PDF-1.4\n%fake".utf8)
        let (result, requests) = try await download(response("200 OK", headers: ["Content-Type: application/pdf"], body: body))
        #expect(try result.get() == body)
        let request = try #require(requests.first)
        #expect(request.hasPrefix("GET /pos/receipt/INV-1/pdf?expected_business_id=3 HTTP/1.1\r\n"))
        #expect(request.lowercased().contains("accept: application/pdf\r\n"))
        #expect(request.contains("abaad_session=s3cret"))
    }

    @Test("a redirect is not followed → SESSION_EXPIRED")
    func redirect() async throws {
        let (result, requests) = try await download(response("302 Found", headers: ["Location: /login"]))
        #expect(result.failureCode == .sessionExpired)
        #expect(requests.count == 1)
    }

    @Test("401 and 419 → SESSION_EXPIRED; 500 → RECEIPT_DOWNLOAD_FAILED")
    func statuses() async throws {
        #expect(try await download(response("401 Unauthorized")).0.failureCode == .sessionExpired)
        #expect(try await download(response("419 Page Expired")).0.failureCode == .sessionExpired)
        #expect(try await download(response("500 Internal Server Error")).0.failureCode == .receiptDownloadFailed)
        #expect(ReceiptDownloader.failure(forStatus: 204) == nil)
        #expect(ReceiptDownloader.failure(forStatus: 304) == .sessionExpired)
        #expect(ReceiptDownloader.failure(forStatus: 404) == .receiptDownloadFailed)
    }

    @Test("HTML instead of a PDF → RECEIPT_DOWNLOAD_FAILED")
    func notPDF() async throws {
        let (result, _) = try await download(response("200 OK", headers: ["Content-Type: text/html"], body: Data("<html>login</html>".utf8)))
        #expect(result.failureCode == .receiptDownloadFailed)
    }

    @Test("a body over the cap → RECEIPT_DOWNLOAD_FAILED")
    func tooLarge() async throws {
        let body = Data("%PDF".utf8) + Data(repeating: 0x20, count: 5000)
        let (result, _) = try await download(response("200 OK", body: body), maxBytes: 1000)
        #expect(result.failureCode == .receiptDownloadFailed)
    }
}

@Suite("Cookie filter")
struct CookieFilterTests {
    func cookie(_ name: String, domain: String, path: String = "/", secure: Bool = false, expires: Date? = nil) -> HTTPCookie {
        var properties: [HTTPCookiePropertyKey: Any] = [.name: name, .value: "v", .domain: domain, .path: path]
        if secure { properties[.secure] = "TRUE" }
        if let expires { properties[.expires] = expires }
        return HTTPCookie(properties: properties)!
    }

    @Test("only cookies the browser would send go with the request")
    func matching() {
        let url = URL(string: "https://pos.example.test/pos/receipt/1/pdf")!
        let now = Date()
        let cookies = [
            cookie("host", domain: "pos.example.test"),
            cookie("parent", domain: ".example.test"),
            cookie("secure", domain: "pos.example.test", secure: true),
            cookie("pos-path", domain: "pos.example.test", path: "/pos"),
            cookie("other", domain: "other.test"),
            cookie("sibling", domain: "admin.example.test"),
            cookie("suffix", domain: "s.pos.example.test"),
            cookie("admin-path", domain: "pos.example.test", path: "/admin"),
            cookie("prefix-path", domain: "pos.example.test", path: "/po"),
            cookie("expired", domain: "pos.example.test", expires: now.addingTimeInterval(-60)),
        ]
        let names = CookieFilter.matching(cookies, for: url, now: now).map(\.name)
        #expect(names == ["host", "parent", "secure", "pos-path"])

        let insecure = CookieFilter.matching(cookies, for: URL(string: "http://pos.example.test/pos/x")!, now: now).map(\.name)
        #expect(!insecure.contains("secure"))

        let header = CookieFilter.requestHeaders([cookie("a", domain: "pos.example.test")], for: url)
        #expect(header == ["Cookie": "a=v"])
        #expect(CookieFilter.requestHeaders([cookie("b", domain: "other.test")], for: url).isEmpty)
    }
}
