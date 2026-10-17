import Foundation
import JavaScriptCore
import Testing
@testable import AbaadPrinting

/*
 * الـprelude والـshim الحقيقيّان في JavaScriptCore: تُرسل الصفحةُ طلبًا، فيمرّ
 * بـ`BridgeRequest.decide` كما في الجسر، ثمّ تعود المراحلُ والنتيجة عبر
 * `__abaadPrinterReceive(m)` — الاستدعاءُ نفسُه الذي يجريه `callAsyncJavaScript`.
 * فخطأٌ نحويّ في السطر المحقون، أو شكلُ ردٍّ لا يفهمه الـshim، يسقط هنا.
 */
@Suite("Prelude + shim round trip")
struct ShimRoundTripTests {
    let trusted = TrustedOrigins(["https://pos.example.test"])

    func context(origin: String) throws -> JSContext {
        let context = try #require(JSContext())
        var failure: String?
        context.exceptionHandler = { _, exception in failure = exception?.toString() }
        context.evaluateScript("""
            var window = globalThis;
            window.top = window;
            window.self = window;
            window.location = { origin: \(try String(data: JSONSerialization.data(withJSONObject: [origin]), encoding: .utf8)!)[0] };
            window.setTimeout = function () { return 1; };
            window.clearTimeout = function () {};
            var posted = [];
            window.webkit = { messageHandlers: { abaadPrinter: { postMessage: function (s) { posted.push(s); } } } };
            """)
        let shim = try String(contentsOf: Vectors.mobileDirectory().appendingPathComponent("shared/js/abaad-printer-shim.js"), encoding: .utf8)
        context.evaluateScript(AbaadPrinterBridge.prelude(trustedOrigins: trusted) + shim)
        #expect(failure == nil, "script error: \(failure ?? "")")
        return context
    }

    func receive(_ context: JSContext, _ message: BridgeOutgoing) throws {
        let json = try #require(message.jsonString())
        let receive = try #require(context.objectForKeyedSubscript("__abaadPrinterReceive"))
        #expect(receive.isObject)
        receive.call(withArguments: [json])
    }

    @Test("a trusted page posts a request the native side accepts, and gets stages and a result back")
    func roundTrip() throws {
        let context = try context(origin: "https://pos.example.test")
        #expect(context.evaluateScript("typeof window.__abaadPrinterConfig").toString() == "undefined")
        #expect(context.evaluateScript("AbaadPrinter.isAvailable()").toBool())
        #expect(context.evaluateScript("AbaadPrinter.platform").toString() == "ios")

        context.evaluateScript("""
            var stages = [];
            var outcome = null;
            AbaadPrinter.printReceipt({
                invoice: 'INV-000123',
                receiptUrl: 'https://pos.example.test/pos/receipt/INV-000123/pdf?expected_business_id=3',
                printer: { id: 7, name: 'طابعة', model: null, connection: 'network', address: '192.168.1.50', port: null, paperWidth: 80 }
            }, function (s) { stages.push(s); }).then(function (r) { outcome = JSON.stringify(r); });
            """)
        let posted = try #require(context.evaluateScript("posted[0]").toString())

        let origin = MessageOrigin(isMainFrame: true, frameOrigin: "https://pos.example.test:443", pageURL: URL(string: "https://pos.example.test/pos/sell"))
        guard case let .accept(job) = BridgeRequest.decide(body: posted, origin: origin, trusted: trusted) else {
            Issue.record("native side refused \(posted)")
            return
        }

        for stage in [BridgeStage.downloadingReceipt, .rendering, .connecting, .sending] {
            try receive(context, .progress(id: job.id, stage: stage))
        }
        try receive(context, .result(id: job.id, result: .success(jobId: job.id, printed: true, connected: true, effectivePort: 9100)))

        #expect(context.evaluateScript("stages.join(',')").toString() == "downloading_receipt,rendering,connecting,sending")
        let outcome = try #require(context.evaluateScript("outcome").toString())
        let result = try #require(try JSONSerialization.jsonObject(with: Data(outcome.utf8)) as? [String: Any])
        #expect(result["ok"] as? Bool == true)
        #expect(result["jobId"] as? String == job.id)
        #expect(result["effectivePort"] as? Int == 9100)
    }

    @Test("a failure keeps its code, Arabic message and flags through the shim")
    func failureRoundTrip() throws {
        let context = try context(origin: "https://pos.example.test")
        context.evaluateScript("""
            var outcome = null;
            AbaadPrinter.testPrinter({ printer: { connection: 'network', address: '192.168.1.50', port: 9100 } })
                .then(function (r) { outcome = JSON.stringify(r); });
            """)
        let posted = try #require(context.evaluateScript("posted[0]").toString())
        let id = try #require((try JSONSerialization.jsonObject(with: Data(posted.utf8)) as? [String: Any])?["id"] as? String)

        try receive(context, .result(id: id, result: .failure(.sendFailed, jobId: id, connected: true, mayHavePrinted: true, effectivePort: 9100)))

        let outcome = try #require(context.evaluateScript("outcome").toString())
        let result = try #require(try JSONSerialization.jsonObject(with: Data(outcome.utf8)) as? [String: Any])
        #expect(result["ok"] as? Bool == false)
        #expect(result["code"] as? String == "SEND_FAILED")
        #expect(result["message"] as? String == ErrorCode.sendFailed.message)
        #expect(result["connected"] as? Bool == true)
        #expect(result["mayHavePrinted"] as? Bool == true)
    }

    @Test("an untrusted page is refused by the shim before anything is posted")
    func untrustedPage() throws {
        let context = try context(origin: "https://evil.test")
        #expect(!context.evaluateScript("AbaadPrinter.isAvailable()").toBool())
        context.evaluateScript("var outcome = null; AbaadPrinter.openReceipt({}).then(function (r) { outcome = r.code; });")
        #expect(context.evaluateScript("outcome").toString() == "UNTRUSTED_ORIGIN")
        #expect(context.evaluateScript("posted.length").toInt32() == 0)
    }
}
