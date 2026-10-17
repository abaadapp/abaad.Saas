import Foundation

/*
 * تنفيذُ مهمّةٍ فُحصت — §5 و§6.
 *
 *   printReceipt: الطابور ← downloading_receipt ← rendering ← (أحاديّ) ← connecting ← sending
 *   testPrinter:  الطابور ← (يُرسم هنا) ← connecting ← sending
 *   openReceipt:  تنزيل ← حوارُ الطباعة في النظام (خارج الطابور: لا يلمس الطابعة)
 *
 * لا يعرف WebKit ولا UIKit: الكوكيز والعرضُ يُمرَّران إليه دوالّ، فيُختبر
 * المسارُ كلُّه بطابعةٍ وتنزيلٍ مزيَّفين.
 *
 * ويعمل خارج الخيط الرئيسيّ: الرسمُ والترميزُ والانتظار كلُّها هنا.
 */

/// حوارُ الطباعة في النظام — يطبّقه التطبيق بـ`UIPrintInteractionController`
@MainActor
public protocol ReceiptPresenting: AnyObject, Sendable {
    /// true إن عُرض الحوار. لا ينتظر إغلاقَه: الصفحةُ تنتظر الردّ بمهلة
    func present(pdf: Data, jobName: String) async -> Bool
}

public final class PrintJobRunner: Sendable {
    public let fetcher: ReceiptFetching
    public let driver: PrinterSending
    public let resolver: HostResolving
    public let queue: PrintJobQueue
    private let clock: @Sendable () -> Date

    public init(
        fetcher: ReceiptFetching = ReceiptDownloader(),
        driver: PrinterSending = NetworkPrinterDriver(),
        resolver: HostResolving = SystemHostResolver(),
        queue: PrintJobQueue = PrintJobQueue(),
        clock: @escaping @Sendable () -> Date = { Date() }
    ) {
        self.fetcher = fetcher
        self.driver = driver
        self.resolver = resolver
        self.queue = queue
        self.clock = clock
    }

    /// كوكيز الـWebView لرابطٍ بعينه، رأسًا جاهزًا — يُنادى على الخيط الرئيسيّ
    public typealias CookieHeaderProvider = @MainActor @Sendable (URL) async -> [String: String]

    public func run(
        _ job: BridgeJob,
        cookies: @escaping CookieHeaderProvider,
        presenter: ReceiptPresenting?,
        progress: @escaping @Sendable (BridgeStage) -> Void
    ) async -> BridgeResult {
        let started = DispatchTime.now()
        PrintLog.started(job.id, method: job.method)
        let reporting: @Sendable (BridgeStage) -> Void = { stage in
            PrintLog.stage(job.id, stage, elapsedMilliseconds: Self.milliseconds(since: started))
            progress(stage)
        }

        let result: BridgeResult
        switch job.kind {
        case let .printReceipt(_, url, printer):
            result = await queue.submit {
                await self.printReceipt(job, url: url, printer: printer, cookies: cookies, progress: reporting)
            } ?? .failure(.queueFull, jobId: job.id, effectivePort: printer.port)

        case let .testPrinter(printer, deviceName, printerName):
            result = await queue.submit {
                await self.testPrinter(job, printer: printer, deviceName: deviceName, printerName: printerName, progress: reporting)
            } ?? .failure(.queueFull, jobId: job.id, effectivePort: printer.port)

        case let .openReceipt(invoice, url):
            result = await openReceipt(job, invoice: invoice, url: url, cookies: cookies, presenter: presenter)
        }

        PrintLog.finished(job.id, method: job.method, code: result.code, milliseconds: Self.milliseconds(since: started))
        return result
    }

    // MARK: - المسارات

    private func printReceipt(
        _ job: BridgeJob,
        url: URL,
        printer: PrinterTarget,
        cookies: @escaping CookieHeaderProvider,
        progress: @escaping @Sendable (BridgeStage) -> Void
    ) async -> BridgeResult {
        progress(.downloadingReceipt)
        let pdf: Data
        switch await download(job, url: url, cookies: cookies) {
        case let .success(data): pdf = data
        case let .failure(error): return .failure(error.code, jobId: job.id, effectivePort: printer.port)
        }

        progress(.rendering)
        let bytes: [UInt8]
        do {
            let raster = try PDFRasterizer.rasterize(pdf, widthDots: printer.paper.dots)
            bytes = EscPosEncoder.encode(raster, options: .receipt)
        } catch {
            return .failure(.receiptRenderFailed, jobId: job.id, effectivePort: printer.port)
        }

        return await deliver(job, bytes: bytes, to: printer, progress: progress)
    }

    private func testPrinter(
        _ job: BridgeJob,
        printer: PrinterTarget,
        deviceName: String?,
        printerName: String?,
        progress: @escaping @Sendable (BridgeStage) -> Void
    ) async -> BridgeResult {
        let content = TestPageContent(
            deviceName: deviceName,
            printerName: printerName,
            address: printer.displayAddress,
            port: printer.port,
            paper: printer.paper,
            date: clock()
        )
        let bytes: [UInt8]
        do {
            bytes = EscPosEncoder.encode(try TestReceiptRenderer.render(content), options: .receipt)
        } catch {
            return .failure(.receiptRenderFailed, jobId: job.id, effectivePort: printer.port)
        }
        return await deliver(job, bytes: bytes, to: printer, progress: progress)
    }

    private func openReceipt(
        _ job: BridgeJob,
        invoice: String,
        url: URL,
        cookies: @escaping CookieHeaderProvider,
        presenter: ReceiptPresenting?
    ) async -> BridgeResult {
        let pdf: Data
        switch await download(job, url: url, cookies: cookies) {
        case let .success(data): pdf = data
        case let .failure(error): return .failure(error.code, jobId: job.id)
        }
        guard let presenter else {
            return .failure(.internalError, jobId: job.id)
        }
        let presented = await presenter.present(pdf: pdf, jobName: invoice)
        return presented ? .success(jobId: job.id) : .failure(.internalError, jobId: job.id)
    }

    // MARK: - الخطوات المشتركة

    private func download(_ job: BridgeJob, url: URL, cookies: @escaping CookieHeaderProvider) async -> Result<Data, PrintingError> {
        let started = DispatchTime.now()
        let headers = await cookies(url)
        let result = await fetcher.fetch(url, headers: headers)
        let bytes = (try? result.get())?.count ?? 0
        PrintLog.download(job.id, url: url, outcome: result.failureCode, bytes: bytes, milliseconds: Self.milliseconds(since: started))
        return result
    }

    /// العنوانُ الذي يُتّصل به ثمّ الإرسال — والمنفذُ الفعليّ في النتيجة دائمًا
    private func deliver(
        _ job: BridgeJob,
        bytes: [UInt8],
        to printer: PrinterTarget,
        progress: @escaping @Sendable (BridgeStage) -> Void
    ) async -> BridgeResult {
        let host: String
        switch printer.address {
        case let .ipv4(literal), let .ipv6(literal):
            host = literal
        case let .mdns(name):
            switch ResolvedAddressPolicy.choose(await resolver.resolve(name)) {
            case let .success(literal): host = literal
            case let .failure(error): return .failure(error.code, jobId: job.id, effectivePort: printer.port)
            }
        }

        let outcome = await driver.send(bytes, host: host, port: printer.port, onStage: progress)
        PrintLog.printer(job.id, host: host, port: printer.port, result: outcome)
        if let error = outcome.error {
            return .failure(
                error,
                jobId: job.id,
                connected: outcome.connected,
                mayHavePrinted: outcome.mayHavePrinted,
                effectivePort: printer.port
            )
        }
        return .success(jobId: job.id, printed: true, connected: true, effectivePort: printer.port)
    }

    static func milliseconds(since start: DispatchTime) -> Int {
        Int((DispatchTime.now().uptimeNanoseconds - start.uptimeNanoseconds) / 1_000_000)
    }
}

extension Result where Failure == PrintingError {
    var failureCode: ErrorCode? {
        if case let .failure(error) = self { return error.code }
        return nil
    }
}
