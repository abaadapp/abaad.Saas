import CoreGraphics
import Foundation
import Testing
@testable import AbaadPrinting

/// تنزيلٌ مزيَّف: يردّ بما أُعطي ويحفظ ما طُلب
final class FakeFetcher: ReceiptFetching, @unchecked Sendable {
    let result: Result<Data, PrintingError>
    let requests = Recorder<(URL, [String: String])>()

    init(_ result: Result<Data, PrintingError>) {
        self.result = result
    }

    func fetch(_ url: URL, headers: [String: String]) async -> Result<Data, PrintingError> {
        requests.append((url, headers))
        return result
    }
}

/// طابعةٌ مزيَّفة: تُعلن المرحلتين كالحقيقيّة، وتحفظ ما وصلها
final class FakeDriver: PrinterSending, @unchecked Sendable {
    let outcome: DriverResult
    let sent = Recorder<(host: String, port: Int, bytes: [UInt8])>()

    init(_ outcome: DriverResult = DriverResult(error: nil, connected: true, mayHavePrinted: true)) {
        self.outcome = outcome
    }

    func send(_ bytes: [UInt8], host: String, port: Int, onStage: @escaping @Sendable (BridgeStage) -> Void) async -> DriverResult {
        onStage(.connecting)
        if outcome.connected { onStage(.sending) }
        sent.append((host, port, bytes))
        var result = outcome
        result.bytesSent = outcome.error == nil ? bytes.count : 0
        return result
    }
}

struct FakeResolver: HostResolving {
    let answer: [String]?

    func resolve(_ name: String) async -> [String]? {
        answer
    }
}

@MainActor
final class FakePresenter: ReceiptPresenting {
    var presented: [(Data, String)] = []

    func present(pdf: Data, jobName: String) async -> Bool {
        presented.append((pdf, jobName))
        return true
    }
}

@Suite("Print job runner")
struct PrintJobRunnerTests {
    let receiptURL = URL(string: "https://pos.example.test/pos/receipt/INV-1/pdf?expected_business_id=3")!
    let pdf = makePDF(pages: [(CGSize(width: 200, height: 300), CGRect(x: 20, y: 20, width: 160, height: 260))])
    let cookies: PrintJobRunner.CookieHeaderProvider = { _ in ["Cookie": "abaad_session=s3cret"] }

    func target(_ address: PrinterAddress = .ipv4("192.168.1.50"), port: Int = 9100, paper: PaperProfile = .mm80) -> PrinterTarget {
        PrinterTarget(address: address, displayAddress: address.host, port: port, paper: paper)
    }

    func receiptJob(_ printer: PrinterTarget) -> BridgeJob {
        BridgeJob(id: "j1", kind: .printReceipt(invoice: "INV-1", receiptURL: receiptURL, printer: printer), pageOrigin: "https://pos.example.test:443")
    }

    @Test("printReceipt: download → render → connect → send, all stages in order")
    func printReceipt() async throws {
        let fetcher = FakeFetcher(.success(pdf))
        let driver = FakeDriver()
        let runner = PrintJobRunner(fetcher: fetcher, driver: driver, resolver: FakeResolver(answer: nil))
        let stages = Recorder<BridgeStage>()

        let result = await runner.run(receiptJob(target()), cookies: cookies, presenter: nil) { stages.append($0) }

        #expect(result == .success(jobId: "j1", printed: true, connected: true, effectivePort: 9100))
        #expect(stages.all == [.downloadingReceipt, .rendering, .connecting, .sending])
        #expect(fetcher.requests.all.first?.0 == receiptURL)
        #expect(fetcher.requests.all.first?.1 == ["Cookie": "abaad_session=s3cret"])

        let sent = try #require(driver.sent.all.first)
        #expect(sent.host == "192.168.1.50")
        #expect(sent.port == 9100)
        // تهيئة، ثمّ GS v 0 بعرض 72 بايتًا (576 نقطة)، وفي الآخر قصّ
        #expect(Array(sent.bytes[0..<8]) == [0x1B, 0x40, 0x1D, 0x76, 0x30, 0x00, 72, 0])
        #expect(Array(sent.bytes.suffix(7)) == [0x1B, 0x64, 0x04, 0x1D, 0x56, 0x42, 0x00])
    }

    @Test("a session that expired stops before the printer")
    func sessionExpired() async {
        let driver = FakeDriver()
        let runner = PrintJobRunner(fetcher: FakeFetcher(.failure(PrintingError(.sessionExpired))), driver: driver, resolver: FakeResolver(answer: nil))
        let result = await runner.run(receiptJob(target(port: 9101)), cookies: cookies, presenter: nil) { _ in }
        #expect(result == .failure(.sessionExpired, jobId: "j1", effectivePort: 9101))
        #expect(driver.sent.all.isEmpty)
    }

    @Test("an unreadable PDF → RECEIPT_RENDER_FAILED")
    func renderFailure() async {
        let driver = FakeDriver()
        let runner = PrintJobRunner(fetcher: FakeFetcher(.success(Data("%PDF-garbage".utf8))), driver: driver, resolver: FakeResolver(answer: nil))
        let result = await runner.run(receiptJob(target()), cookies: cookies, presenter: nil) { _ in }
        #expect(result.code == .receiptRenderFailed)
        #expect(driver.sent.all.isEmpty)
    }

    @Test("a failure after connecting keeps connected and mayHavePrinted")
    func sendFailed() async {
        let driver = FakeDriver(DriverResult(error: .sendFailed, connected: true, mayHavePrinted: true))
        let runner = PrintJobRunner(fetcher: FakeFetcher(.success(pdf)), driver: driver, resolver: FakeResolver(answer: nil))
        let result = await runner.run(receiptJob(target()), cookies: cookies, presenter: nil) { _ in }
        #expect(result == .failure(.sendFailed, jobId: "j1", connected: true, mayHavePrinted: true, effectivePort: 9100))
        #expect(result.message == ErrorCode.sendFailed.message)
        #expect(driver.sent.all.count == 1)
    }

    @Test("mDNS: every resolved address must be private, then the checked IP is dialled")
    func mdns() async throws {
        let okDriver = FakeDriver()
        let ok = PrintJobRunner(fetcher: FakeFetcher(.success(pdf)), driver: okDriver, resolver: FakeResolver(answer: ["fe80::2%en0", "192.168.1.77"]))
        let okResult = await ok.run(receiptJob(target(.mdns("printer.local"))), cookies: cookies, presenter: nil) { _ in }
        #expect(okResult.ok)
        #expect(try #require(okDriver.sent.all.first).host == "192.168.1.77")

        let mixedDriver = FakeDriver()
        let mixed = PrintJobRunner(fetcher: FakeFetcher(.success(pdf)), driver: mixedDriver, resolver: FakeResolver(answer: ["192.168.1.77", "8.8.8.8"]))
        let mixedResult = await mixed.run(receiptJob(target(.mdns("printer.local"))), cookies: cookies, presenter: nil) { _ in }
        #expect(mixedResult.code == .invalidPrinterAddress)
        #expect(mixedDriver.sent.all.isEmpty)

        let loopback = PrintJobRunner(fetcher: FakeFetcher(.success(pdf)), driver: FakeDriver(), resolver: FakeResolver(answer: ["127.0.0.1"]))
        #expect(await loopback.run(receiptJob(target(.mdns("printer.local"))), cookies: cookies, presenter: nil) { _ in }.code == .invalidPrinterAddress)

        let missing = PrintJobRunner(fetcher: FakeFetcher(.success(pdf)), driver: FakeDriver(), resolver: FakeResolver(answer: nil))
        #expect(await missing.run(receiptJob(target(.mdns("printer.local"))), cookies: cookies, presenter: nil) { _ in }.code == .printerUnreachable)
    }

    @Test("testPrinter draws its own page on the configured paper and skips download")
    func testPrinter() async throws {
        let fetcher = FakeFetcher(.failure(PrintingError(.internalError)))
        let driver = FakeDriver()
        let runner = PrintJobRunner(fetcher: fetcher, driver: driver, resolver: FakeResolver(answer: nil), clock: { Date(timeIntervalSince1970: 0) })
        let stages = Recorder<BridgeStage>()
        let job = BridgeJob(id: "t1", kind: .testPrinter(printer: target(port: 9101, paper: .mm58), deviceName: "الصندوق 1", printerName: nil), pageOrigin: "https://pos.example.test:443")

        let result = await runner.run(job, cookies: cookies, presenter: nil) { stages.append($0) }

        #expect(result == .success(jobId: "t1", printed: true, connected: true, effectivePort: 9101))
        #expect(stages.all == [.connecting, .sending])
        #expect(fetcher.requests.all.isEmpty)
        let sent = try #require(driver.sent.all.first)
        #expect(Array(sent.bytes[0..<8]) == [0x1B, 0x40, 0x1D, 0x76, 0x30, 0x00, 48, 0])
    }

    @Test("openReceipt downloads with the session and hands the PDF to the system dialog")
    func openReceipt() async {
        let presenter = await FakePresenter()
        let runner = PrintJobRunner(fetcher: FakeFetcher(.success(pdf)), driver: FakeDriver(), resolver: FakeResolver(answer: nil))
        let job = BridgeJob(id: "o1", kind: .openReceipt(invoice: "INV-1", receiptURL: receiptURL), pageOrigin: "https://pos.example.test:443")

        let result = await runner.run(job, cookies: cookies, presenter: presenter) { _ in }

        #expect(result == .success(jobId: "o1"))
        let presented = await presenter.presented
        #expect(presented.count == 1)
        #expect(presented.first?.0 == pdf)
        #expect(presented.first?.1 == "INV-1")
    }
}
