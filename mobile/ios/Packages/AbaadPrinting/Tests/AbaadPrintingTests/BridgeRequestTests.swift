import Foundation
import Testing
@testable import AbaadPrinting

/*
 * الفحصُ قبل أيّ تنزيلٍ أو اتّصال — §3 و§4 — بلا WKWebView: نصُّ الرسالة
 * وما يُعرف عن مصدرها.
 */
@Suite("Bridge message validation")
struct BridgeRequestTests {
    let trusted = TrustedOrigins(["https://pos.example.test", "https://app.example.test:8443"])
    let page = MessageOrigin(
        isMainFrame: true,
        frameOrigin: "https://pos.example.test:443",
        pageURL: URL(string: "https://pos.example.test/pos/sell")
    )

    func message(id: Any = "j1-1", v: Any = 1, method: Any = "printReceipt", payload: Any) -> String {
        let object: [String: Any] = ["v": v, "id": id, "method": method, "payload": payload]
        let data = try! JSONSerialization.data(withJSONObject: object)
        return String(data: data, encoding: .utf8)!
    }

    func printer(_ overrides: [String: Any] = [:]) -> [String: Any] {
        var printer: [String: Any] = [
            "id": 7, "name": "طابعة الكاشير", "model": NSNull(), "connection": "network",
            "address": "192.168.1.50", "port": NSNull(), "paperWidth": 80,
        ]
        for (key, value) in overrides { printer[key] = value }
        return printer
    }

    func receiptPayload(printer: [String: Any]? = nil, url: String = "https://pos.example.test/pos/receipt/INV-000123/pdf?expected_business_id=3") -> [String: Any] {
        ["invoice": "INV-000123", "receiptUrl": url, "printer": printer ?? self.printer()]
    }

    func code(_ decision: BridgeDecision) -> ErrorCode? {
        if case let .reject(_, result) = decision { return result.code }
        return nil
    }

    // MARK: - المقبول

    @Test("a valid printReceipt is accepted with the checked target")
    func acceptsPrintReceipt() throws {
        let decision = BridgeRequest.decide(body: message(payload: receiptPayload()), origin: page, trusted: trusted)
        guard case let .accept(job) = decision else {
            Issue.record("expected accept, got \(decision)")
            return
        }
        #expect(job.id == "j1-1")
        #expect(job.pageOrigin == "https://pos.example.test:443")
        guard case let .printReceipt(invoice, url, target) = job.kind else {
            Issue.record("wrong kind")
            return
        }
        #expect(invoice == "INV-000123")
        #expect(url.absoluteString == "https://pos.example.test/pos/receipt/INV-000123/pdf?expected_business_id=3")
        #expect(target.address == .ipv4("192.168.1.50"))
        #expect(target.port == 9100)
        #expect(target.paper == .mm80)
    }

    @Test("testPrinter: mDNS name, 58mm, names trimmed to 60 characters")
    func acceptsTestPrinter() {
        let long = String(repeating: "ص", count: 80)
        let payload: [String: Any] = [
            "printer": printer(["address": "XP-80.local", "port": 9101, "paperWidth": 58]),
            "deviceName": "  الصندوق 1  ",
            "printerName": long,
        ]
        let decision = BridgeRequest.decide(body: message(method: "testPrinter", payload: payload), origin: page, trusted: trusted)
        guard case let .accept(job) = decision, case let .testPrinter(target, deviceName, printerName) = job.kind else {
            Issue.record("expected testPrinter, got \(decision)")
            return
        }
        #expect(target.address == .mdns("xp-80.local"))
        #expect(target.displayAddress == "XP-80.local")
        #expect(target.port == 9101)
        #expect(target.paper == .mm58)
        #expect(deviceName == "الصندوق 1")
        #expect(printerName == String(repeating: "ص", count: 60))
    }

    @Test("openReceipt is accepted without a printer")
    func acceptsOpenReceipt() {
        let payload: [String: Any] = ["invoice": "INV-1", "receiptUrl": "https://pos.example.test/pos/receipt/INV-1/pdf"]
        let decision = BridgeRequest.decide(body: message(method: "openReceipt", payload: payload), origin: page, trusted: trusted)
        guard case let .accept(job) = decision, case .openReceipt = job.kind else {
            Issue.record("expected openReceipt, got \(decision)")
            return
        }
    }

    @Test("a non-default trusted port works end to end")
    func nonDefaultPort() {
        let origin = MessageOrigin(isMainFrame: true, frameOrigin: "https://app.example.test:8443", pageURL: URL(string: "https://app.example.test:8443/pos"))
        let decision = BridgeRequest.decide(
            body: message(payload: receiptPayload(url: "https://app.example.test:8443/pos/receipt/1/pdf")),
            origin: origin, trusted: trusted
        )
        guard case .accept = decision else {
            Issue.record("expected accept, got \(decision)")
            return
        }
    }

    // MARK: - المُهمَل

    @Test("messages that cannot be answered are dropped")
    func drops() {
        let iframe = MessageOrigin(isMainFrame: false, frameOrigin: page.frameOrigin, pageURL: page.pageURL)
        #expect(BridgeRequest.decide(body: message(payload: receiptPayload()), origin: iframe, trusted: trusted) == .drop(reason: "not main frame"))

        for body in [nil, 42, ["v": 1] as [String: Any], "not json", "[1,2]", "\"string\""] as [Any?] {
            if case .drop = BridgeRequest.decide(body: body, origin: page, trusted: trusted) {} else {
                Issue.record("expected drop for \(String(describing: body))")
            }
        }
        for id in [7, NSNull(), "", String(repeating: "x", count: 65)] as [Any] {
            if case .drop = BridgeRequest.decide(body: message(id: id, payload: receiptPayload()), origin: page, trusted: trusted) {} else {
                Issue.record("expected drop for id \(id)")
            }
        }
        // 64 حرفًا بالضبط مقبول
        if case .accept = BridgeRequest.decide(body: message(id: String(repeating: "x", count: 64), payload: receiptPayload()), origin: page, trusted: trusted) {} else {
            Issue.record("a 64-character id must be accepted")
        }
    }

    // MARK: - المرفوض بردّ

    @Test("UNTRUSTED_ORIGIN: untrusted frame, untrusted page, or the two differ")
    func untrusted() {
        let cases = [
            MessageOrigin(isMainFrame: true, frameOrigin: "https://evil.test:443", pageURL: URL(string: "https://evil.test/")),
            MessageOrigin(isMainFrame: true, frameOrigin: "https://pos.example.test:443", pageURL: URL(string: "https://evil.test/")),
            MessageOrigin(isMainFrame: true, frameOrigin: "https://evil.test:443", pageURL: URL(string: "https://pos.example.test/")),
            MessageOrigin(isMainFrame: true, frameOrigin: "http://pos.example.test:80", pageURL: URL(string: "http://pos.example.test/")),
            MessageOrigin(isMainFrame: true, frameOrigin: nil, pageURL: URL(string: "https://pos.example.test/")),
            MessageOrigin(isMainFrame: true, frameOrigin: "https://pos.example.test:443", pageURL: nil),
        ]
        for origin in cases {
            let decision = BridgeRequest.decide(body: message(payload: receiptPayload()), origin: origin, trusted: trusted)
            #expect(code(decision) == .untrustedOrigin, "\(origin)")
            if case let .reject(id, result) = decision {
                #expect(id == "j1-1")
                #expect(result.jobId == "j1-1")
                #expect(result.message == "طلب طباعة مرفوض")
            }
        }
    }

    @Test("INVALID_JOB: version, method, payload shape")
    func invalidJob() {
        for v in [2, 0, true, "1", NSNull(), 1.5] as [Any] {
            #expect(code(BridgeRequest.decide(body: message(v: v, payload: receiptPayload()), origin: page, trusted: trusted)) == .invalidJob, "v \(v)")
        }
        #expect(code(BridgeRequest.decide(body: message(method: "printBytes", payload: receiptPayload()), origin: page, trusted: trusted)) == .invalidJob)
        #expect(code(BridgeRequest.decide(body: message(method: 3, payload: receiptPayload()), origin: page, trusted: trusted)) == .invalidJob)
        #expect(code(BridgeRequest.decide(body: message(payload: "x"), origin: page, trusted: trusted)) == .invalidJob)
        #expect(code(BridgeRequest.decide(body: message(payload: ["receiptUrl": "https://pos.example.test/x", "printer": printer()]), origin: page, trusted: trusted)) == .invalidJob)
        var noPrinter = receiptPayload()
        noPrinter["printer"] = "192.168.1.50"
        #expect(code(BridgeRequest.decide(body: message(payload: noPrinter), origin: page, trusted: trusted)) == .invalidJob)
        let badName: [String: Any] = ["printer": printer(), "deviceName": 5]
        #expect(code(BridgeRequest.decide(body: message(method: "testPrinter", payload: badName), origin: page, trusted: trusted)) == .invalidJob)
    }

    @Test("target checks run in §4 order: connection, address, port, receipt URL")
    func targetOrder() {
        func decide(_ printer: [String: Any], url: String = "https://pos.example.test/x") -> BridgeDecision {
            BridgeRequest.decide(body: message(payload: receiptPayload(printer: printer, url: url)), origin: page, trusted: trusted)
        }
        #expect(code(decide(printer(["connection": "usb", "address": "8.8.8.8", "port": "x"]))) == .unsupportedConnection)
        #expect(code(decide(printer(["connection": NSNull()]))) == .unsupportedConnection)
        #expect(code(decide(printer(["address": "8.8.8.8", "port": "x"]))) == .invalidPrinterAddress)
        #expect(code(decide(printer(["address": 19216815]))) == .invalidPrinterAddress)
        #expect(code(decide(printer(["port": "9100"]))) == .invalidPort)
        #expect(code(decide(printer(["port": true]))) == .invalidPort)
        #expect(code(decide(printer(["port": 9100.5]))) == .invalidPort)

        let rejected = decide(printer(["port": 9101]), url: "https://evil.test/x")
        #expect(code(rejected) == .receiptURLRejected)
        if case let .reject(_, result) = rejected {
            #expect(result.effectivePort == 9101)
        }
        #expect(code(decide(printer(), url: "/pos/receipt/1/pdf")) == .receiptURLRejected)
        #expect(code(decide(printer(), url: "http://pos.example.test/x")) == .receiptURLRejected)
        // رابطٌ صحيح لكن من أصلٍ موثوقٍ آخر غير أصل الصفحة
        #expect(code(decide(printer(), url: "https://app.example.test:8443/x")) == .receiptURLRejected)
    }

    // MARK: - ما يُحقن وما يُردّ

    @Test("prelude encodes the trusted origins as JSON and wires the iOS handlers")
    func prelude() throws {
        let prelude = AbaadPrinterBridge.prelude(trustedOrigins: trusted)
        #expect(prelude.hasPrefix("window.__abaadPrinterConfig = { platform: 'ios', trustedOrigins: [\"https://pos.example.test\",\"https://app.example.test:8443\"], "))
        #expect(prelude.contains("post: function (s) { window.webkit.messageHandlers.abaadPrinter.postMessage(s); }"))
        #expect(prelude.contains("listen: function (r) { window.__abaadPrinterReceive = r; }"))
        #expect(AbaadPrinterBridge.handlerName == "abaadPrinter")
        #expect(AbaadPrinterBridge.receiveCall == "window.__abaadPrinterReceive(m)")
    }

    @Test("the shared shim exists where the app bundles it from")
    func shimExists() throws {
        let url = Vectors.mobileDirectory().appendingPathComponent("shared/js/abaad-printer-shim.js")
        let source = try String(contentsOf: url, encoding: .utf8)
        #expect(source.contains("window.__abaadPrinterConfig"))
        #expect(source.contains("'AbaadPrinter'"))
    }

    @Test("results and progress serialise to the §3/§8 shape")
    func outgoingShape() throws {
        let failure = try #require(BridgeOutgoing.result(id: "j1", result: .failure(.sendFailed, jobId: "j1", connected: true, mayHavePrinted: true, effectivePort: 9100)).jsonString())
        let object = try #require(try JSONSerialization.jsonObject(with: Data(failure.utf8)) as? [String: Any])
        #expect(object["id"] as? String == "j1")
        #expect(object["type"] as? String == "result")
        let result = try #require(object["result"] as? [String: Any])
        #expect(result["ok"] as? Bool == false)
        #expect(result["code"] as? String == "SEND_FAILED")
        #expect(result["message"] as? String == "انقطع الاتصال أثناء الإرسال — تحقق من الورق قبل إعادة الطباعة")
        #expect(result["connected"] as? Bool == true)
        #expect(result["mayHavePrinted"] as? Bool == true)
        #expect(result["effectivePort"] as? Int == 9100)
        #expect(result["jobId"] as? String == "j1")
        #expect(result["printed"] == nil)

        let success = try #require(BridgeOutgoing.result(id: "j2", result: .success(jobId: "j2", printed: true, connected: true, effectivePort: 9101)).jsonString())
        #expect(success == #"{"id":"j2","result":{"connected":true,"effectivePort":9101,"jobId":"j2","ok":true,"printed":true},"type":"result"}"#)

        let progress = try #require(BridgeOutgoing.progress(id: "j3", stage: .downloadingReceipt).jsonString())
        #expect(progress == #"{"id":"j3","stage":"downloading_receipt","type":"progress"}"#)
    }

    @Test("error codes and Arabic messages match the §8 table exactly")
    func errorTable() {
        let table: [(String, String)] = [
            ("UNTRUSTED_ORIGIN", "طلب طباعة مرفوض"),
            ("INVALID_JOB", "طلب طباعة مرفوض"),
            ("RECEIPT_URL_REJECTED", "طلب طباعة مرفوض"),
            ("UNSUPPORTED_CONNECTION", "الطباعة المباشرة تدعم طابعات الشبكة فقط حاليًا"),
            ("INVALID_PRINTER_ADDRESS", "عنوان الطابعة غير صالح — يجب أن يكون عنوانًا على الشبكة المحلية"),
            ("INVALID_PORT", "رقم المنفذ غير صالح"),
            ("SESSION_EXPIRED", "انتهت الجلسة — سجّل الدخول من جديد"),
            ("RECEIPT_DOWNLOAD_FAILED", "تعذر تنزيل الإيصال"),
            ("RECEIPT_RENDER_FAILED", "تعذر تجهيز الإيصال للطباعة"),
            ("PRINTER_UNREACHABLE", "تعذر الوصول إلى عنوان الطابعة"),
            ("PORT_REFUSED", "المنفذ لا يستجيب"),
            ("CONNECT_TIMEOUT", "انتهت مهلة الاتصال"),
            ("SEND_FAILED", "انقطع الاتصال أثناء الإرسال — تحقق من الورق قبل إعادة الطباعة"),
            ("QUEUE_FULL", "الطابعة مشغولة — حاول بعد لحظات"),
            ("INTERNAL", "تعذرت الطباعة"),
        ]
        #expect(ErrorCode.allCases.map(\.rawValue) == table.map(\.0))
        for (raw, text) in table {
            #expect(ErrorCode(rawValue: raw)?.message == text, "\(raw)")
        }
        #expect([BridgeStage.downloadingReceipt, .rendering, .connecting, .sending].map(\.rawValue) == ["downloading_receipt", "rendering", "connecting", "sending"])
    }
}
