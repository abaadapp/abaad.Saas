import Foundation
import Testing
@testable import AbaadPrinting

@Suite("Trusted origins")
struct TrustedOriginsTests {
    @Test("configured list is normalised with an explicit default port")
    func normalisation() {
        let trusted = TrustedOrigins(configured: " https://POS.Example.test , https://app.example.test:8443/ ,http://dev.example.test", fallbackBaseURL: nil)
        #expect(trusted.normalized == ["https://pos.example.test:443", "https://app.example.test:8443", "http://dev.example.test:80"])
        #expect(trusted.webOrigins == ["https://pos.example.test", "https://app.example.test:8443", "http://dev.example.test"])
    }

    @Test("empty configuration falls back to the base URL origin")
    func fallback() {
        let trusted = TrustedOrigins(configured: "", fallbackBaseURL: URL(string: "https://pos.example.test/login?x=1"))
        #expect(trusted.normalized == ["https://pos.example.test:443"])
        #expect(TrustedOrigins(configured: nil, fallbackBaseURL: nil).isEmpty)
    }

    @Test("entries with a path, a query, credentials or junk are dropped")
    func invalidEntries() {
        let trusted = TrustedOrigins([
            "https://pos.example.test/pos",
            "https://pos.example.test?x=1",
            "https://user@pos.example.test",
            "ftp://pos.example.test",
            "pos.example.test",
            "https://x.test\"];alert(1);//",
            "https://bad host.test",
            "",
        ])
        #expect(trusted.isEmpty)
    }

    @Test("isTrusted compares normalised origins")
    func isTrusted() {
        let trusted = TrustedOrigins(["https://pos.example.test"])
        #expect(trusted.isTrusted("https://pos.example.test"))
        #expect(trusted.isTrusted("https://POS.EXAMPLE.TEST:443"))
        #expect(!trusted.isTrusted("http://pos.example.test"))
        #expect(!trusted.isTrusted("https://pos.example.test:8443"))
        #expect(!trusted.isTrusted("https://pos.example.test.evil.test"))
        #expect(!trusted.isTrusted(nil))
        #expect(trusted.isTrusted(url: URL(string: "https://pos.example.test/pos/sell?x=1")))
    }

    @Test("WKSecurityOrigin parts: port 0 means the scheme default")
    func securityOriginParts() {
        #expect(TrustedOrigins.normalize(scheme: "https", host: "POS.example.test", port: 0) == "https://pos.example.test:443")
        #expect(TrustedOrigins.normalize(scheme: "https", host: "pos.example.test", port: 8443) == "https://pos.example.test:8443")
        #expect(TrustedOrigins.normalize(scheme: "http", host: "pos.example.test", port: 0) == "http://pos.example.test:80")
        #expect(TrustedOrigins.normalize(scheme: "file", host: "", port: 0) == nil)
        #expect(TrustedOrigins.normalize(scheme: "about", host: "", port: 0) == nil)
    }

    @Test("receipt URL: the accepted URL is the one that was checked")
    func acceptedURL() {
        let trusted = TrustedOrigins(["https://pos.example.test"])
        let url = trusted.receiptURLIfAllowed(pageOrigin: "https://pos.example.test", receiptURL: "https://pos.example.test/pos/receipt/INV-1/pdf?expected_business_id=3")
        #expect(url?.host == "pos.example.test")
        #expect(url?.query == "expected_business_id=3")
        #expect(trusted.receiptURLIfAllowed(pageOrigin: "https://pos.example.test", receiptURL: "https://pos.example.test\\@evil.test/x") == nil)
        #expect(trusted.receiptURLIfAllowed(pageOrigin: "https://pos.example.test", receiptURL: " https://pos.example.test/x") == nil)
        #expect(trusted.receiptURLIfAllowed(pageOrigin: "https://pos.example.test", receiptURL: "https://pos.example.test%2eevil.test/x") == nil)
        #expect(trusted.receiptURLIfAllowed(pageOrigin: nil, receiptURL: "https://pos.example.test/x") == nil)
    }
}
