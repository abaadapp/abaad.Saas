import Foundation

/*
 * تنفيذُ مهمّةٍ فُحصت — §5 و§6.
 *
 *   printReceipt: الطابور ← downloading_receipt ← rendering (إلى ملفّ التخزين) ← connecting ← sending
 *   testPrinter:  الطابور ← (يُرسم هنا) ← connecting ← sending
 *   openReceipt:  تنزيل ← حوارُ الطباعة في النظام (خارج الطابور: لا يلمس الطابعة)
 *
 * والصفحةُ تنتظر ما دامت تسمع (§3): المرحلةُ تُعاد نبضةً كلَّ ثانيةٍ أثناء الرسم
 * والإرسال، فلا يكفّ الـshim عن انتظار إيصالٍ طويلٍ ما زال يُطبع.
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
        let contact = PageContact(acceptedAt: clock(), clock: clock) { stage, changed in
            if changed {
                PrintLog.stage(job.id, stage, elapsedMilliseconds: Self.milliseconds(since: started))
            }
            progress(stage)
        }
        PrintLog.started(job.id, method: job.method)

        let result: BridgeResult
        switch job.kind {
        case let .printReceipt(_, url, printer):
            result = await queue.submit {
                await self.printReceipt(job, url: url, printer: printer, cookies: cookies, contact: contact)
            } ?? .failure(.queueFull, jobId: job.id, effectivePort: printer.port)

        case let .testPrinter(printer, deviceName, printerName):
            result = await queue.submit {
                await self.testPrinter(job, printer: printer, deviceName: deviceName, printerName: printerName, contact: contact)
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
        contact: PageContact
    ) async -> BridgeResult {
        // انتظرت في الطابور حتى ربّما كفّت الصفحةُ عن الانتظار ← لا شيءَ يبدأ
        if contact.silentTooLong(job.method) {
            return .failure(.queueFull, jobId: job.id, effectivePort: printer.port)
        }

        contact.tell(.downloadingReceipt)
        let pdf: Data
        switch await download(job, url: url, cookies: cookies) {
        case let .success(data): pdf = data
        case let .failure(error): return .failure(error.code, jobId: job.id, effectivePort: printer.port)
        }

        // يُرسم كلُّه إلى ملفٍّ قبل أن يُرسل منه بايت: رسمٌ يفشل لا يترك نصفَ إيصال
        contact.tell(.rendering)
        let spool: ReceiptSpool
        do {
            spool = try ReceiptSpool.make(pdf: pdf, widthDots: printer.paper.dots) { contact.beat(.rendering) }
        } catch {
            return .failure(.receiptRenderFailed, jobId: job.id, effectivePort: printer.port)
        }
        defer { spool.remove() }

        return await deliver(job, payload: spool.payload, to: printer, contact: contact)
    }

    private func testPrinter(
        _ job: BridgeJob,
        printer: PrinterTarget,
        deviceName: String?,
        printerName: String?,
        contact: PageContact
    ) async -> BridgeResult {
        if contact.silentTooLong(job.method) {
            return .failure(.queueFull, jobId: job.id, effectivePort: printer.port)
        }

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
        return await deliver(job, payload: .bytes(bytes), to: printer, contact: contact)
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
        payload: PrintPayload,
        to printer: PrinterTarget,
        contact: PageContact
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

        // لم تسمع الصفحةُ منّا مدّةً ربّما كفّت فيها عن الانتظار ← لا اتّصال
        if contact.silentTooLong(job.method) {
            return .failure(.queueFull, jobId: job.id, effectivePort: printer.port)
        }

        let outcome = await driver.send(
            payload,
            host: host,
            port: printer.port,
            onStage: { contact.tell($0) },
            onProgress: { _, _ in contact.beat(.sending) }
        )
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

/*
 * ما سمعته الصفحةُ منّا ومتى — §3 و§5.
 *
 * الـshim يكفّ عن انتظار طلبٍ لم يسمع عنه شيئًا في مهلته. فكلُّ مرحلةٍ تُقال له،
 * والمرحلةُ الجارية تُعاد نبضةً كلَّ `beatInterval` ما دام العملُ يتقدّم. و`lastHeard`
 * آخرُ ما قيل له (أو لحظةُ القبول): منه تُحسب `ConnectDeadline`.
 */
final class PageContact: @unchecked Sendable {
    static let beatInterval: TimeInterval = 1

    private let lock = NSLock()
    private let clock: @Sendable () -> Date
    private let send: @Sendable (BridgeStage, Bool) -> Void
    private var heardAt: Date
    private var toldAt = DispatchTime.now()
    private var stage: BridgeStage?

    init(acceptedAt: Date, clock: @escaping @Sendable () -> Date, send: @escaping @Sendable (BridgeStage, Bool) -> Void) {
        self.heardAt = acceptedAt
        self.clock = clock
        self.send = send
    }

    var lastHeard: Date {
        lock.lock()
        defer { lock.unlock() }
        return heardAt
    }

    func silentTooLong(_ method: BridgeMethod) -> Bool {
        ConnectDeadline.expired(method, silentSince: lastHeard, now: clock())
    }

    /// مرحلةٌ تُقال الآن
    func tell(_ stage: BridgeStage) {
        lock.lock()
        let changed = self.stage != stage
        self.stage = stage
        heardAt = clock()
        toldAt = .now()
        lock.unlock()
        send(stage, changed)
    }

    /// نبضةٌ إن مضت ثانيةٌ على آخر ما قيل
    func beat(_ stage: BridgeStage) {
        lock.lock()
        let due = Double(DispatchTime.now().uptimeNanoseconds - toldAt.uptimeNanoseconds) / 1_000_000_000 >= Self.beatInterval
        lock.unlock()
        if due {
            tell(stage)
        }
    }
}
