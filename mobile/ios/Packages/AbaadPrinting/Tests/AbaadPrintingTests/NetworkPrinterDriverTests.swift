import Foundation
import Network
import Testing
@testable import AbaadPrinting

@Suite("Network printer driver")
struct NetworkPrinterDriverTests {
    @Test("the printer receives exactly the bytes sent, then the connection closes")
    func deliversExactBytes() async throws {
        let printer = LoopbackServer()
        let port = try await printer.start()
        defer { printer.stop() }

        let bytes = (0..<200_000).map { UInt8(truncatingIfNeeded: $0 &* 31 &+ 7) }
        let stages = Recorder<BridgeStage>()
        let result = await NetworkPrinterDriver().send(bytes, host: "127.0.0.1", port: port) { stages.append($0) }

        #expect(result.error == nil)
        #expect(result.connected)
        #expect(result.bytesSent == bytes.count)
        #expect(stages.all == [.connecting, .sending])

        let received = await printer.requests()
        #expect(received.count == 1)
        #expect(received.first.map { [UInt8]($0) } == bytes)
    }

    @Test("nobody listening → PORT_REFUSED, not connected, nothing printed")
    func refused() async {
        let port = unusedLoopbackPort()
        let stages = Recorder<BridgeStage>()
        let started = Date()
        let result = await NetworkPrinterDriver().send([0x1B, 0x40], host: "127.0.0.1", port: port) { stages.append($0) }

        #expect(result.error == .portRefused)
        #expect(!result.connected)
        #expect(!result.mayHavePrinted)
        #expect(stages.all == [.connecting])
        #expect(Date().timeIntervalSince(started) < 4)
    }

    @Test("a host that is not an IP literal is never dialled")
    func refusesNames() async {
        let result = await NetworkPrinterDriver().send([0x1B, 0x40], host: "printer.local", port: 9100) { _ in }
        #expect(result.error == .internalError)
        #expect(!result.connected)
    }

    /*
     * طابعةٌ بطيئة تأخذ أكثر من 30 ثانية — ويصلها الإيصالُ كلُّه.
     *
     * كانت للإرسال كلِّه 30 ثانية ثمّ يُقطع: إيصالٌ طويل على طابعةٍ تطبع الصورةَ ببطء
     * كان يخرج إلى منتصفه. والطابعةُ هنا تقرأ 2KB كلَّ عُشر ثانية (20KB/ث)، ومخزنُها
     * 4KB، ومخزنُ إرسالنا 16KB. فـ1MB تقرؤه في نحو 49 ثانية — ويبقى الإرسالُ نفسُه
     * أكثر من 30 ثانية ولو بقي في مخازن النظام ما يبلغ 200KB لم تقرأه بعد (macOS يكبّر
     * مخزنَ الاستقبال على 127.0.0.1 وحده: رأينا فيه حتى 160KB). ولا يقف التقدّمُ طويلًا.
     */
    @Test("a slow printer that needs more than 30 seconds still receives every byte", .timeLimit(.minutes(2)))
    func slowPrinterGetsEverything() async throws {
        let printer = PacedPrinter(pace: .slow(bytes: 2048, every: 0.1))
        let port = try printer.start()
        let payload = (0..<1_000_000).map { UInt8(truncatingIfNeeded: $0 &* 131 &+ 17) }
        let progress = Recorder<(Int, Date)>()

        let started = Date()
        let result = await NetworkPrinterDriver(stallTimeout: 15).send(
            .bytes(payload), host: "127.0.0.1", port: port, onStage: { _ in }
        ) { sent, _ in progress.append((sent, Date())) }
        let elapsed = Date().timeIntervalSince(started)

        #expect(result.error == nil)
        #expect(result.bytesSent == payload.count)
        #expect(elapsed > 30, "the send itself lasted \(elapsed)s")

        let seen = await printer.waitUntilFinished(timeout: 30)
        #expect(seen.received.count == payload.count)
        #expect(seen.received == payload)
        #expect(seen.closedByPeer)

        // وتقدّمٌ لا يقف: لا فجوةَ بين علامتَي تقدّمٍ تقترب من حدّ التوقّف (15 ث)
        let times = progress.all.map(\.1)
        let widest = zip(times, times.dropFirst()).map { $1.timeIntervalSince($0) }.max() ?? 0
        #expect(widest < 7.5, "widest gap between signs of progress: \(widest)s")
    }

    /*
     * وطابعةٌ توقّفت (ورقٌ نفد، غطاءٌ مفتوح) تُقطع بعد `stallTimeout` بلا تقدّم —
     * SEND_FAILED وربما طُبع جزء، ولا إعادة.
     */
    @Test("a printer that stops reading fails after the stall timeout", .timeLimit(.minutes(1)))
    func stalledPrinterFails() async throws {
        let printer = PacedPrinter(pace: .stall(after: 50_000))
        let port = try printer.start()

        let started = Date()
        let sent = await within(20) {
            await NetworkPrinterDriver(stallTimeout: 1).send(
                .bytes([UInt8](repeating: 0x55, count: 32 * 1024 * 1024)), host: "127.0.0.1", port: port, onStage: { _ in }
            ) { _, _ in }
        }
        let result = try #require(sent, "still sending 20 s after the printer stopped reading")
        let elapsed = Date().timeIntervalSince(started)

        #expect(result.error == .sendFailed)
        #expect(result.connected)
        #expect(result.mayHavePrinted)
        #expect(result.bytesSent >= 50_000)
        #expect(elapsed < 10, "took \(elapsed)s")
        // ولا يتكدّس الإيصالُ في الجهاز: ما قُبل ولم تأخذه الطابعة قليل (مخزنٌ صغير)
        let inDevice = result.bytesSent - printer.snapshot.received.count
        #expect(inDevice < 512 * 1024, "\(inDevice) bytes accepted but never read by the printer")
    }

    @Test("a connection cut after part of the receipt is SEND_FAILED that may have printed", .timeLimit(.minutes(1)))
    func cutAfterPartIsSendFailed() async throws {
        let printer = PacedPrinter(pace: .reset(after: 100_000))
        let port = try printer.start()

        let result = await NetworkPrinterDriver(stallTimeout: 5).send(
            .bytes([UInt8](repeating: 0x33, count: 32 * 1024 * 1024)), host: "127.0.0.1", port: port, onStage: { _ in }
        ) { _, _ in }

        #expect(result.error == .sendFailed)
        #expect(result.connected)
        #expect(result.mayHavePrinted)
        #expect(result.bytesSent > 0)
        let seen = await printer.waitUntilFinished(timeout: 5)
        #expect(seen.received.count == 100_000)
    }

    /// طابعةٌ تردّ بايتاتِ حالةٍ أثناء الطباعة: تُقرأ وتُهمل، والإيصالُ يصل كلُّه
    @Test("status bytes the printer sends back do not cost the end of the receipt", .timeLimit(.minutes(1)))
    func repliesAreDrained() async throws {
        let printer = PacedPrinter(pace: .fast, replyEvery: 8 * 1024)
        let port = try printer.start()
        let payload = (0..<1_000_000).map { UInt8(truncatingIfNeeded: $0 &* 7 &+ 3) }

        let result = await NetworkPrinterDriver().send(.bytes(payload), host: "127.0.0.1", port: port, onStage: { _ in }) { _, _ in }

        #expect(result.error == nil)
        let seen = await printer.waitUntilFinished(timeout: 10)
        #expect(seen.received == payload)
    }

    @Test("connect errors map to the contract codes")
    func errorMapping() {
        #expect(NetworkPrinterDriver.connectFailure(errno: ECONNREFUSED) == .portRefused)
        #expect(NetworkPrinterDriver.connectFailure(errno: ETIMEDOUT) == .connectTimeout)
        #expect(NetworkPrinterDriver.connectFailure(errno: EHOSTUNREACH) == .printerUnreachable)
        #expect(NetworkPrinterDriver.connectFailure(errno: ENETUNREACH) == .printerUnreachable)
        #expect(NetworkPrinterDriver.connectFailure(errno: ENETDOWN) == .printerUnreachable)
        // منعُ الشبكة المحليّة في iOS يردّ المقبسَ بخطأ وصول
        #expect(NetworkPrinterDriver.connectFailure(errno: EPERM) == .printerUnreachable)
    }

    @Test("only IP literals become socket addresses — a name is never looked up")
    func literalsOnly() {
        #expect(NetworkPrinterDriver.socketAddress("192.168.1.50", port: 9100)?.family == AF_INET)
        #expect(NetworkPrinterDriver.socketAddress("fd00::1", port: 9100)?.family == AF_INET6)
        #expect(NetworkPrinterDriver.socketAddress("fe80::1%lo0", port: 9100)?.family == AF_INET6)
        #expect(NetworkPrinterDriver.socketAddress("printer.local", port: 9100) == nil)
        #expect(NetworkPrinterDriver.socketAddress("192.168.1.50", port: 0) == nil)
        #expect(NetworkPrinterDriver.socketAddress("192.168.1.50", port: 65_536) == nil)
    }
}

@Suite("Print job queue")
struct PrintJobQueueTests {
    actor Gate {
        private var open = false
        private var waiters: [CheckedContinuation<Void, Never>] = []

        func wait() async {
            if open { return }
            await withCheckedContinuation { waiters.append($0) }
        }

        func release() {
            open = true
            waiters.forEach { $0.resume() }
            waiters.removeAll()
        }
    }

    @Test("one running, two waiting, the fourth is QUEUE_FULL at once; order is kept")
    func fourthIsRejected() async {
        let queue = PrintJobQueue()
        let gate = Gate()
        let order = Recorder<Int>()

        async let first: Int? = queue.submit {
            await gate.wait()
            order.append(1)
            return 1
        }
        #expect(await eventually { await queue.isBusy })

        async let second: Int? = queue.submit { order.append(2); return 2 }
        #expect(await eventually { await queue.waitingCount == 1 })
        async let third: Int? = queue.submit { order.append(3); return 3 }
        #expect(await eventually { await queue.waitingCount == 2 })

        // QUEUE_FULL فوريّ: لو انتظرت الرابعةُ خلف البوّابة لسقط الاختبار هنا بدل أن يعلق
        let fourth = Recorder<Int?>()
        Task { fourth.append(await queue.submit { order.append(4); return 4 }) }
        #expect(await eventually(timeout: 2) { !fourth.all.isEmpty }, "the fourth job must be refused at once")

        await gate.release()
        let results = await [first, second, third]
        #expect(results == [1, 2, 3])
        #expect(await eventually(timeout: 2) { !fourth.all.isEmpty })
        #expect(fourth.all.count == 1 && fourth.all.first! == nil)
        #expect(order.all == [1, 2, 3])
        #expect(await queue.isBusy == false)
        #expect(await queue.waitingCount == 0)
    }
}
