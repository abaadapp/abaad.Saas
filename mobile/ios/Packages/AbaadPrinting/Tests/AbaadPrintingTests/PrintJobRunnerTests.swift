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

/// طابعةٌ مزيَّفة: تُعلن المرحلتين كالحقيقيّة، وتقرأ الحمولةَ كلَّها وتحفظ ما وصلها
final class FakeDriver: PrinterSending, @unchecked Sendable {
    let outcome: DriverResult
    let sent = Recorder<(host: String, port: Int, bytes: [UInt8])>()

    init(_ outcome: DriverResult = DriverResult(error: nil, connected: true, mayHavePrinted: true)) {
        self.outcome = outcome
    }

    func send(
        _ payload: PrintPayload,
        host: String,
        port: Int,
        onStage: @escaping @Sendable (BridgeStage) -> Void,
        onProgress: @escaping @Sendable (Int, Int) -> Void
    ) async -> DriverResult {
        onStage(.connecting)
        if outcome.connected { onStage(.sending) }
        let bytes = (try? payload.allBytes()) ?? []
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

    /// ساعةٌ تقفز: أوّلُ قراءةٍ لحظةُ القبول، وما بعدها بعد `later` ثانية — كأنّ المهمّة انتظرت في الطابور
    final class JumpingClock: @unchecked Sendable {
        private let lock = NSLock()
        private var reads = 0
        let later: TimeInterval

        init(later: TimeInterval) {
            self.later = later
        }

        func now() -> Date {
            lock.lock()
            defer { lock.unlock() }
            reads += 1
            return Date(timeIntervalSince1970: reads == 1 ? 0 : later)
        }
    }

    @Test("a receipt that waited past the connect deadline is never sent — the page has stopped waiting")
    func staleReceiptIsNotSent() async {
        let driver = FakeDriver()
        let clock = JumpingClock(later: ConnectDeadline.printReceipt + 1)
        let runner = PrintJobRunner(fetcher: FakeFetcher(.success(pdf)), driver: driver, resolver: FakeResolver(answer: nil), clock: { clock.now() })

        let result = await runner.run(receiptJob(target(port: 9101)), cookies: cookies, presenter: nil) { _ in }

        #expect(result == .failure(.queueFull, jobId: "j1", effectivePort: 9101))
        #expect(driver.sent.all.isEmpty)
    }

    @Test("inside the connect deadline the receipt is sent")
    func receiptInsideDeadlineIsSent() async {
        let driver = FakeDriver()
        let clock = JumpingClock(later: ConnectDeadline.printReceipt - 1)
        let runner = PrintJobRunner(fetcher: FakeFetcher(.success(pdf)), driver: driver, resolver: FakeResolver(answer: nil), clock: { clock.now() })

        let result = await runner.run(receiptJob(target(port: 9101)), cookies: cookies, presenter: nil) { _ in }

        #expect(result == .success(jobId: "j1", printed: true, connected: true, effectivePort: 9101))
        #expect(driver.sent.all.count == 1)
    }

    @Test("a test page that waited past its deadline is never sent")
    func staleTestPageIsNotSent() async {
        let driver = FakeDriver()
        let clock = JumpingClock(later: ConnectDeadline.testPrinter + 1)
        let runner = PrintJobRunner(fetcher: FakeFetcher(.success(pdf)), driver: driver, resolver: FakeResolver(answer: nil), clock: { clock.now() })
        let job = BridgeJob(id: "t2", kind: .testPrinter(printer: target(), deviceName: nil, printerName: nil), pageOrigin: "https://pos.example.test:443")

        let result = await runner.run(job, cookies: cookies, presenter: nil) { _ in }

        #expect(result == .failure(.queueFull, jobId: "t2", effectivePort: 9100))
        #expect(driver.sent.all.isEmpty)
    }

    /*
     * لا صمتَ أطولَ من انتظار الـshim — مقروءًا من ملفّه نفسِه.
     *
     * الصفحةُ تنتظر ما دامت تسمع. فكلُّ مدّةٍ لا يُقال فيها لها شيء أقصرُ من مهلتها:
     * الطابورُ ثمّ الاتّصال، والتنزيل، وتوقّفُ الطابعة ثمّ ذيلُ الإغلاق، وما بين نبضتين.
     */
    @Test("no silence the native side allows is as long as the shim's wait")
    func deadlineFitsInsideTheShimWait() throws {
        let url = Vectors.mobileDirectory().appendingPathComponent("shared/js/abaad-printer-shim.js")
        let shim = try String(contentsOf: url, encoding: .utf8)
        func wait(_ method: String) throws -> TimeInterval {
            let regex = try NSRegularExpression(pattern: "\\b\(method): (\\d+)")
            let range = NSRange(shim.startIndex..., in: shim)
            let match = try #require(regex.firstMatch(in: shim, range: range))
            let digits = try #require(Range(match.range(at: 1), in: shim))
            return try #require(TimeInterval(shim[digits])) / 1000
        }
        let driver = NetworkPrinterDriver()
        let download = ReceiptDownloader().timeout

        for (method, deadline) in [("printReceipt", ConnectDeadline.printReceipt), ("testPrinter", ConnectDeadline.testPrinter)] {
            let shim = try wait(method)
            #expect(deadline + driver.connectTimeout < shim, "\(method): queue + connect")
            #expect(driver.stallTimeout + driver.tailTimeout < shim, "\(method): stall + tail")
            #expect(PageContact.beatInterval < shim, "\(method): between beats")
        }
        #expect(download < (try wait("printReceipt")), "download")
        #expect(try wait("printReceipt") == 120)
    }

    /*
     * والمهلةُ تُحسب من آخر ما سمعته الصفحة لا من قبول الطلب.
     *
     * ساعةٌ تتقدّم 30 ثانيةً مع كلّ قراءة: من القبول إلى الاتّصال أكثرُ من 75 ثانية.
     * لكنّ الصفحةَ سمعت «downloading» ثمّ «rendering» في الطريق، فلم تكفّ عن الانتظار
     * — وإيصالٌ طويلٌ يُرسم طويلًا لا يُرفض لطوله.
     */
    @Test("a job the page keeps hearing from is sent however long it has run")
    func heardJobIsSent() async {
        let driver = FakeDriver()
        let clock = SteppingClock(step: 30)
        let runner = PrintJobRunner(fetcher: FakeFetcher(.success(pdf)), driver: driver, resolver: FakeResolver(answer: nil), clock: { clock.now() })

        let result = await runner.run(receiptJob(target()), cookies: cookies, presenter: nil) { _ in }

        #expect(result == .success(jobId: "j1", printed: true, connected: true, effectivePort: 9100))
        #expect(driver.sent.all.count == 1)
        #expect(clock.reads > 3)
    }

    /// ساعةٌ تتقدّم `step` ثانيةً مع كلّ قراءة
    final class SteppingClock: @unchecked Sendable {
        private let lock = NSLock()
        private var count = 0
        let step: TimeInterval

        init(step: TimeInterval) {
            self.step = step
        }

        var reads: Int {
            lock.lock()
            defer { lock.unlock() }
            return count
        }

        func now() -> Date {
            lock.lock()
            defer { lock.unlock() }
            count += 1
            return Date(timeIntervalSince1970: TimeInterval(count - 1) * step)
        }
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
