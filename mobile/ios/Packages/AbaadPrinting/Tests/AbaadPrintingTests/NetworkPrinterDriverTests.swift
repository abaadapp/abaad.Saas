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
     * كان يخرج إلى منتصفه. والطابعةُ هنا تقرأ بسرعةٍ ثابتة نحو 50 ثانية، ومخزنُ إرسالنا 16KB.
     *
     * وسرعتُها وطولُ الإيصال من `loopbackBuffering()`: ثلثُ ما يخزّنه النظامُ في الثانية
     * (20KB/ث على الأقلّ). فما في مخازن النظام لا يتجاوز ثلاث ثوانٍ من القراءة — فيبقى
     * الإرسالُ نفسُه فوق 30 ثانية بهامشٍ واسع، ولا يفتح المستقبِلُ نافذتَه بخطوةٍ تبدو توقّفًا.
     */
    // الحدُّ حارسٌ من التعليق لا شرطُ نجاح: نحو 50 ثانية إرسالًا، وعلى CI بثلاث أنوية مزدحمة بلغ الاختبارُ 99 ثانية
    @Test("a slow printer that needs more than 30 seconds still receives every byte", .timeLimit(.minutes(3)))
    func slowPrinterGetsEverything() async throws {
        let buffered = await offThePool { loopbackBuffering() }
        let rate = min(max(20 * 1024, buffered / 3), 600 * 1024)
        let printer = PacedPrinter(pace: .slow(bytes: rate / 10, every: 0.1))
        let port = try printer.start()
        let payload = await offThePool { (0..<(rate * 50)).map { UInt8(truncatingIfNeeded: $0 &* 131 &+ 17) } }
        let progress = Recorder<(Int, Date)>()

        let result = await NetworkPrinterDriver(stallTimeout: 15).send(
            .bytes(payload), host: "127.0.0.1", port: port, onStage: { _ in }
        ) { sent, _ in progress.append((sent, Date())) }
        // مدّةُ الإرسال بساعة المُرسِل على خيطه — لا بعد أن يُستأنف الاختبار
        let elapsed = Double(result.sendMilliseconds ?? 0) / 1000

        #expect(result.error == nil)
        #expect(result.bytesSent == payload.count)
        #expect(elapsed > 30, "the send itself lasted \(elapsed)s (\(rate) B/s, \(buffered) B held by the system)")

        let seen = await printer.waitUntilFinished(timeout: 15)
        #expect(seen.received.count == payload.count)
        #expect(seen.received == payload)
        #expect(seen.closedByPeer)

        // وتقدّمٌ لا يقف: لا فجوةَ بين علامتَي تقدّمٍ تقترب من حدّ التوقّف (15 ث)
        let times = progress.all.map(\.1)
        let widest = zip(times, times.dropFirst()).map { $1.timeIntervalSince($0) }.max() ?? 0
        #expect(widest < 7.5, "widest gap between signs of progress: \(widest)s")
    }

    /*
     * مخزنُ الإرسال الصغير هو ما يجعل كلَّ كتابةٍ تنجح تقدّمًا عند الطابعة: بمخزن النظام
     * (128KB فأكثر، ويكبر وحده) يُقبل الإيصالُ في الجهاز دفعةً ثمّ يسكت التقدّمُ والطابعةُ
     * تأخذ — فتبدو طابعةٌ بطيئة متوقّفة. ولا يُرى هذا على 127.0.0.1 بثبات (macOS يرفع
     * المخازنَ هناك)، فيُفحص المقبسُ نفسُه.
     */
    @Test("the socket refuses to hold more than a small send buffer, never raises SIGPIPE, and never blocks")
    func socketIsConfigured() throws {
        func option(_ fd: Int32, _ level: Int32, _ name: Int32) -> Int32 {
            var value: Int32 = 0
            var length = socklen_t(MemoryLayout<Int32>.size)
            getsockopt(fd, level, name, &value, &length)
            return value
        }
        let plain = socket(AF_INET, SOCK_STREAM, IPPROTO_TCP)
        let configured = socket(AF_INET, SOCK_STREAM, IPPROTO_TCP)
        defer {
            close(plain)
            close(configured)
        }
        NetworkPrinterDriver.configure(configured, sendBuffer: NetworkPrinterDriver().sendBufferSize)

        #expect(NetworkPrinterDriver().sendBufferSize == 16 * 1024)
        #expect(option(configured, SOL_SOCKET, SO_SNDBUF) <= 64 * 1024)
        #expect(option(configured, SOL_SOCKET, SO_SNDBUF) < option(plain, SOL_SOCKET, SO_SNDBUF))
        #expect(option(configured, SOL_SOCKET, SO_NOSIGPIPE) != 0)
        #expect(option(configured, IPPROTO_TCP, TCP_NODELAY) != 0)
        #expect(fcntl(configured, F_GETFL) & O_NONBLOCK != 0)
    }

    /*
     * وطابعةٌ توقّفت (ورقٌ نفد، غطاءٌ مفتوح) تُقطع بعد `stallTimeout` بلا تقدّم —
     * SEND_FAILED وربما طُبع جزء، ولا إعادة.
     */
    @Test("a printer that stops reading fails after the stall timeout", .timeLimit(.minutes(1)))
    func stalledPrinterFails() async throws {
        let printer = PacedPrinter(pace: .stall(after: 50_000))
        let port = try printer.start()

        let progress = Recorder<Date>()
        let sending = Recorder<Date>()
        let sent = await within(30) {
            await NetworkPrinterDriver(stallTimeout: 2).send(
                .bytes([UInt8](repeating: 0x55, count: 32 * 1024 * 1024)), host: "127.0.0.1", port: port,
                onStage: { if $0 == .sending { sending.append(Date()) } }
            ) { _, _ in progress.append(Date()) }
        }
        let result = try #require(sent, "still sending 30 s after the printer stopped reading")
        // كلُّ الأوقات على خيط المُرسِل: بدءُ الإرسال، وكلُّ كتابةٍ تمّت، ولحظةُ الفشل (`sendMilliseconds`
        // منذ بدء الإرسال). فلا يدخل فيها ما ينتظره الاختبارُ حتى يُستأنف على جهازٍ مزدحم
        let sendingAt = try #require(sending.all.first)
        let failedAt = sendingAt.addingTimeInterval(Double(try #require(result.sendMilliseconds)) / 1000)

        #expect(result.error == .sendFailed)
        #expect(result.connected)
        #expect(result.mayHavePrinted)
        #expect(result.bytesSent >= 50_000)

        // يُحكم بالصمت لا بالمدّة: بعد أن تقف الطابعة يبقى النظامُ يقبل حتى تمتلئ مخازنُه — على
        // 127.0.0.1 مئاتُ الكيلوبايتات في ثوانٍ تختلف من جهازٍ إلى جهاز، وكلُّها تقدّمٌ صادق. ثمّ
        // من آخر كتابةٍ تمّت إلى الفشل `stallTimeout` بالضبط: لا أقلّ (فلا تُقطع طابعةٌ بطيئة
        // قبله) ولا أكثر إلّا دورةَ poll (250ms) ومهلةَ الجدولة
        let lastAccepted = try #require(progress.all.last)
        let silence = failedAt.timeIntervalSince(lastAccepted)
        #expect(silence > 1.9 && silence < 3.5, "failed \(silence)s after the last accepted write; the stall timeout is 2s")
    }

    /// والقطعُ يُرى عند وقوعه — لا بعد مهلة التوقّف (هنا 30 ثانية لا تُبلغ)
    @Test("a connection cut after part of the receipt is SEND_FAILED that may have printed", .timeLimit(.minutes(1)))
    func cutAfterPartIsSendFailed() async throws {
        let printer = PacedPrinter(pace: .reset(after: 100_000))
        let port = try printer.start()

        let sent = await within(25) {
            await NetworkPrinterDriver(stallTimeout: 30).send(
                .bytes([UInt8](repeating: 0x33, count: 32 * 1024 * 1024)), host: "127.0.0.1", port: port, onStage: { _ in }
            ) { _, _ in }
        }
        let result = try #require(sent, "a reset connection was not noticed")
        // بساعة المُرسِل: من بدء الإرسال إلى الفشل — والطابعةُ تقطع بعد 100KB
        let noticed = Double(try #require(result.sendMilliseconds)) / 1000

        #expect(noticed < 5, "noticed after \(noticed)s")
        #expect(result.error == .sendFailed)
        #expect(result.connected)
        #expect(result.mayHavePrinted)
        #expect(result.bytesSent > 0)
        let seen = await printer.waitUntilFinished(timeout: 5)
        #expect(seen.received.count == 100_000)
    }

    /// طابعةٌ تُغلق طرفَ كتابتها أوّلَ الاتّصال وتبقى تقرأ: الإيصالُ يصلها كلُّه، ولا يُعدّ الإغلاقُ فشلًا
    @Test("a printer that closes its own side first and keeps reading still receives everything", .timeLimit(.minutes(1)))
    func halfClosedPrinterGetsEverything() async throws {
        let printer = PacedPrinter(pace: .fast, closeWriteSide: true)
        let port = try printer.start()
        let payload = (0..<1_000_000).map { UInt8(truncatingIfNeeded: $0 &* 13 &+ 5) }

        let sent = await within(25) {
            await NetworkPrinterDriver().send(.bytes(payload), host: "127.0.0.1", port: port, onStage: { _ in }) { _, _ in }
        }
        let result = try #require(sent)

        #expect(result.error == nil)
        let seen = await printer.waitUntilFinished(timeout: 10)
        #expect(seen.received == payload)
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
