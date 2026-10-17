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

    @Test("connect errors map to the contract codes")
    func errorMapping() {
        #expect(NetworkPrinterDriver.connectFailure(.posix(.ECONNREFUSED)) == .portRefused)
        #expect(NetworkPrinterDriver.connectFailure(.posix(.ETIMEDOUT)) == .connectTimeout)
        #expect(NetworkPrinterDriver.connectFailure(.posix(.EHOSTUNREACH)) == .printerUnreachable)
        #expect(NetworkPrinterDriver.connectFailure(.posix(.ENETUNREACH)) == .printerUnreachable)
        #expect(NetworkPrinterDriver.connectFailure(.posix(.ENETDOWN)) == .printerUnreachable)
        // رفضُ إذن الشبكة المحليّة يظهر خطأَ DNS (kDNSServiceErr_PolicyDenied)
        #expect(NetworkPrinterDriver.connectFailure(.dns(-65570)) == .printerUnreachable)
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
