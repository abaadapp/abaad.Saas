import CoreGraphics
import Foundation
import Network
@testable import AbaadPrinting

/// المتّجهاتُ المشتركة مع Android — `mobile/shared/fixtures/printing-vectors.json`
enum Vectors {
    static func mobileDirectory() -> URL {
        var directory = URL(fileURLWithPath: #filePath).deletingLastPathComponent()
        while directory.path != "/" {
            if FileManager.default.fileExists(atPath: directory.appendingPathComponent("shared/fixtures/printing-vectors.json").path) {
                return directory
            }
            directory = directory.deletingLastPathComponent()
        }
        fatalError("mobile/shared/fixtures/printing-vectors.json not found above \(#filePath)")
    }

    static func load() throws -> [String: Any] {
        let url = mobileDirectory().appendingPathComponent("shared/fixtures/printing-vectors.json")
        let object = try JSONSerialization.jsonObject(with: Data(contentsOf: url))
        guard let root = object as? [String: Any] else { throw VectorError.malformed }
        return root
    }

    static func section(_ name: String) throws -> [String: Any] {
        guard let section = try load()[name] as? [String: Any] else { throw VectorError.malformed }
        return section
    }

    enum VectorError: Error {
        case malformed
    }
}

func hex(_ bytes: [UInt8]) -> String {
    bytes.map { String(format: "%02x", $0) }.joined()
}

extension PrintPayload {
    /// الحمولةُ كلُّها في الذاكرة — للاختبارات وحدها
    func allBytes() throws -> [UInt8] {
        let reader = try reader()
        defer { reader.close() }
        var out: [UInt8] = []
        while let chunk = try reader.next(max: 64 * 1024) {
            out += chunk
        }
        return out
    }
}

/*
 * قراءةُ مهمّة ESC/POS كما تقرؤها الطابعة (§7): تهيئة، ثمّ صور، ثمّ تغذيةٌ وقصّ.
 * فيُعدّ القصُّ أمرًا لا نمطَ بايتات — صورةٌ قد تحوي 1D 56 42 00 في بياناتها.
 */
struct ParsedJob {
    var bytesPerRow = 0
    var rows: [[UInt8]] = []
    var commands: [String] = []

    var cuts: Int { commands.filter { $0 == "cut" }.count }
}

func parseEscPos(_ bytes: [UInt8]) throws -> ParsedJob {
    var job = ParsedJob()
    var i = 0
    func need(_ n: Int) throws {
        guard i + n <= bytes.count else { throw ParseError.truncated(at: i) }
    }
    while i < bytes.count {
        try need(2)
        switch (bytes[i], bytes[i + 1]) {
        case (0x1B, 0x40):
            job.commands.append("init")
            i += 2
        case (0x1B, 0x64):
            try need(3)
            job.commands.append("feed \(bytes[i + 2])")
            i += 3
        case (0x1D, 0x56):
            try need(4)
            guard bytes[i + 2] == 0x42, bytes[i + 3] == 0x00 else { throw ParseError.unknown(at: i) }
            job.commands.append("cut")
            i += 4
        case (0x1D, 0x76):
            try need(8)
            guard bytes[i + 2] == 0x30, bytes[i + 3] == 0x00 else { throw ParseError.unknown(at: i) }
            let x = Int(bytes[i + 4]) | Int(bytes[i + 5]) << 8
            let y = Int(bytes[i + 6]) | Int(bytes[i + 7]) << 8
            i += 8
            try need(x * y)
            if job.bytesPerRow == 0 { job.bytesPerRow = x }
            guard x == job.bytesPerRow else { throw ParseError.widthChanged(at: i) }
            for row in 0..<y {
                job.rows.append(Array(bytes[(i + row * x)..<(i + (row + 1) * x)]))
            }
            i += x * y
            job.commands.append("image")
        default:
            throw ParseError.unknown(at: i)
        }
    }
    return job
}

enum ParseError: Error {
    case truncated(at: Int)
    case unknown(at: Int)
    case widthChanged(at: Int)
}

/// قيمةٌ كما تصل من JSON حقًّا: `{"v": <نصّ>}` يمرّ بـJSONSerialization
func jsonValue(_ literal: String) throws -> Any? {
    let object = try JSONSerialization.jsonObject(with: Data("{\"v\": \(literal)}".utf8)) as? [String: Any]
    return object?["v"]
}

/// PDF يُولَّد في الاختبار: صفحاتٌ بيضاء، في كلٍّ منها مستطيلٌ أسود
func makePDF(pages: [(size: CGSize, black: CGRect)]) -> Data {
    let data = NSMutableData()
    var mediaBox = CGRect(origin: .zero, size: pages.first?.size ?? CGSize(width: 100, height: 100))
    let consumer = CGDataConsumer(data: data as CFMutableData)!
    let context = CGContext(consumer: consumer, mediaBox: &mediaBox, nil)!
    for page in pages {
        var box = CGRect(origin: .zero, size: page.size)
        let info = [kCGPDFContextMediaBox as String: Data(bytes: &box, count: MemoryLayout<CGRect>.size)] as CFDictionary
        context.beginPDFPage(info)
        context.setFillColor(CGColor(red: 0, green: 0, blue: 0, alpha: 1))
        context.fill(page.black)
        context.endPDFPage()
    }
    context.closePDF()
    return data as Data
}

/// يجمع ما يُستدعى من خيوطٍ مختلفة
final class Recorder<Value>: @unchecked Sendable {
    private let lock = NSLock()
    private var values: [Value] = []

    func append(_ value: Value) {
        lock.lock()
        values.append(value)
        lock.unlock()
    }

    var all: [Value] {
        lock.lock()
        defer { lock.unlock() }
        return values
    }
}

/*
 * ينتظر `body` حتى `seconds` على الأكثر، ثمّ يعود بلا نتيجة.
 * و`.timeLimit` لا يكفي هنا: المُرسِل يدور على خيطه لا في مهمّة، فلا يُلغى —
 * ومُرسِلٌ لا يستسلم يُعلّق التشغيلَ كلَّه بدل أن يُسقط اختبارًا.
 */
func within<T: Sendable>(_ seconds: TimeInterval, _ body: @escaping @Sendable () async -> T) async -> T? {
    let first = Once<T?>()
    Task { first.finish(await body()) }
    Task {
        try? await Task.sleep(nanoseconds: UInt64(seconds * 1_000_000_000))
        first.finish(nil)
    }
    return await withCheckedContinuation { first.set($0) }
}

/// ينتظر شرطًا قصيرًا دون نومٍ أعمى طويل
func eventually(timeout: TimeInterval = 3, _ condition: @Sendable () async -> Bool) async -> Bool {
    let deadline = Date().addingTimeInterval(timeout)
    while Date() < deadline {
        if await condition() { return true }
        try? await Task.sleep(nanoseconds: 5_000_000)
    }
    return await condition()
}

/// منفذٌ على 127.0.0.1 لا يستمع إليه أحد
func unusedLoopbackPort() -> Int {
    let fd = socket(AF_INET, SOCK_STREAM, 0)
    defer { close(fd) }
    var address = sockaddr_in()
    address.sin_len = UInt8(MemoryLayout<sockaddr_in>.size)
    address.sin_family = sa_family_t(AF_INET)
    address.sin_port = 0
    address.sin_addr.s_addr = inet_addr("127.0.0.1")
    _ = withUnsafePointer(to: &address) {
        $0.withMemoryRebound(to: sockaddr.self, capacity: 1) { bind(fd, $0, socklen_t(MemoryLayout<sockaddr_in>.size)) }
    }
    var length = socklen_t(MemoryLayout<sockaddr_in>.size)
    _ = withUnsafeMutablePointer(to: &address) {
        $0.withMemoryRebound(to: sockaddr.self, capacity: 1) { getsockname(fd, $0, &length) }
    }
    return Int(UInt16(bigEndian: address.sin_port))
}

/*
 * خادمٌ صغير على 127.0.0.1 بـNWListener: يجمع ما يصله من كلّ اتّصال، ويردّ — إن
 * أُعطي ردًّا — بعد أن يكتمل رأسُ HTTP.
 */
final class LoopbackServer: @unchecked Sendable {
    private let queue = DispatchQueue(label: "test.loopback.server")
    private let response: Data?
    private var listener: NWListener?
    private var connections: [NWConnection] = []
    private var received: [Data] = []
    private var completed = 0

    /// `response`: nil ← مصبٌّ يجمع حتى يُغلق المرسِل
    init(response: Data? = nil) {
        self.response = response
    }

    func start() async throws -> Int {
        let parameters = NWParameters.tcp
        parameters.requiredLocalEndpoint = .hostPort(host: .ipv4(.loopback), port: .any)
        parameters.allowLocalEndpointReuse = true
        let listener = try NWListener(using: parameters)
        self.listener = listener
        listener.newConnectionHandler = { [self] connection in
            connections.append(connection)
            let index = received.count
            received.append(Data())
            connection.start(queue: queue)
            receive(on: connection, index: index)
        }
        let ready = Once<Int?>()
        listener.stateUpdateHandler = { state in
            switch state {
            case .ready: ready.finish(listener.port.map { Int($0.rawValue) })
            case .failed, .cancelled: ready.finish(nil)
            default: break
            }
        }
        listener.start(queue: queue)
        let port = await withCheckedContinuation { ready.set($0) }
        guard let port else { throw ServerError.notReady }
        return port
    }

    private func receive(on connection: NWConnection, index: Int) {
        connection.receive(minimumIncompleteLength: 1, maximumLength: 64 * 1024) { [self] content, _, isComplete, error in
            if let content { received[index].append(content) }
            if let response, received[index].range(of: Data("\r\n\r\n".utf8)) != nil {
                connection.send(content: response, contentContext: .finalMessage, isComplete: true, completion: .contentProcessed { _ in
                    connection.cancel()
                })
                completed += 1
                return
            }
            if isComplete || error != nil {
                completed += 1
                connection.cancel()
                return
            }
            receive(on: connection, index: index)
        }
    }

    /// ما وصل في كلّ اتّصالٍ اكتمل
    func requests(timeout: TimeInterval = 3, expected: Int = 1) async -> [Data] {
        _ = await eventually(timeout: timeout) { self.queue.sync { self.completed >= expected } }
        return queue.sync { received }
    }

    func stop() {
        queue.sync {
            listener?.cancel()
            connections.forEach { $0.cancel() }
        }
    }

    enum ServerError: Error {
        case notReady
    }
}

/*
 * طابعةٌ على 127.0.0.1 بمقابس POSIX: مخزنُ استقبالٍ صغير كطابعةٍ حقيقيّة، وقراءةٌ
 * بالسرعة التي يُطلب منها — كلُّها، أو بطيئةً بإيقاع، أو تتوقّف، أو تقطع بـRST.
 * وقد تردّ بايتاتِ حالةٍ أثناء القراءة كما تفعل بعضُ الطابعات.
 */
final class PacedPrinter: @unchecked Sendable {
    enum Pace {
        /// تقرأ ما يصل فورًا
        case fast
        /// `bytes` كلَّ `every` ثانية
        case slow(bytes: Int, every: TimeInterval)
        /// تقرأ `after` بايتًا ثمّ لا تقرأ شيئًا أبدًا (ورقٌ نفد)
        case stall(after: Int)
        /// تقرأ `after` بايتًا ثمّ تقطع بـRST
        case reset(after: Int)
    }

    private let lock = NSLock()
    private let pace: Pace
    private let receiveBuffer: Int32
    private let replyEvery: Int?
    private var listener: Int32 = -1
    private var received: [UInt8] = []
    private var closedByPeer = false
    private var finished = false
    private var firstByteAt: Date?
    private var lastByteAt: Date?

    init(pace: Pace, receiveBuffer: Int32 = 4096, replyEvery: Int? = nil) {
        self.pace = pace
        self.receiveBuffer = receiveBuffer
        self.replyEvery = replyEvery
    }

    func start() throws -> Int {
        listener = socket(AF_INET, SOCK_STREAM, 0)
        var buffer = receiveBuffer
        setsockopt(listener, SOL_SOCKET, SO_RCVBUF, &buffer, socklen_t(MemoryLayout<Int32>.size))
        var address = sockaddr_in()
        address.sin_len = UInt8(MemoryLayout<sockaddr_in>.size)
        address.sin_family = sa_family_t(AF_INET)
        address.sin_port = 0
        address.sin_addr.s_addr = inet_addr("127.0.0.1")
        let bound = withUnsafePointer(to: &address) {
            $0.withMemoryRebound(to: sockaddr.self, capacity: 1) { bind(listener, $0, socklen_t(MemoryLayout<sockaddr_in>.size)) }
        }
        guard bound == 0, listen(listener, 1) == 0 else { throw PrinterError.cannotListen }
        var length = socklen_t(MemoryLayout<sockaddr_in>.size)
        _ = withUnsafeMutablePointer(to: &address) {
            $0.withMemoryRebound(to: sockaddr.self, capacity: 1) { getsockname(listener, $0, &length) }
        }
        let thread = Thread { [self] in serve() }
        thread.start()
        return Int(UInt16(bigEndian: address.sin_port))
    }

    private func serve() {
        let client = accept(listener, nil, nil)
        close(listener)
        guard client >= 0 else { return }
        var buffer = receiveBuffer
        setsockopt(client, SOL_SOCKET, SO_RCVBUF, &buffer, socklen_t(MemoryLayout<Int32>.size))
        var chunk = [UInt8](repeating: 0, count: 64 * 1024)
        var total = 0
        var nextReply = replyEvery ?? Int.max
        loop: while true {
            var limit = chunk.count
            switch pace {
            case .fast:
                break
            case let .slow(bytes, every):
                limit = bytes
                Thread.sleep(forTimeInterval: every)
            case let .stall(after), let .reset(after):
                if total >= after { break loop }
                limit = min(limit, after - total)
            }
            let n = chunk.withUnsafeMutableBytes { recv(client, $0.baseAddress, limit, 0) }
            if n <= 0 {
                lock.lock()
                closedByPeer = n == 0
                lock.unlock()
                break
            }
            lock.lock()
            received += chunk[0..<n]
            firstByteAt = firstByteAt ?? Date()
            lastByteAt = Date()
            lock.unlock()
            total += n
            if total >= nextReply {
                // حالةُ الطابعة: بايتٌ واحد كما يردّ DLE EOT
                var status: UInt8 = 0x12
                _ = send(client, &status, 1, 0)
                nextReply += replyEvery ?? Int.max
            }
        }
        switch pace {
        case .stall:
            // لا تقرأ ولا تُغلق — كطابعةٍ نفد ورقُها
            lock.lock()
            finished = true
            lock.unlock()
            Thread.sleep(forTimeInterval: 30)
            close(client)
            return
        case .reset:
            var linger = linger(l_onoff: 1, l_linger: 0)
            setsockopt(client, SOL_SOCKET, SO_LINGER, &linger, socklen_t(MemoryLayout<linger>.size))
        default:
            break
        }
        close(client)
        lock.lock()
        finished = true
        lock.unlock()
    }

    struct Snapshot {
        var received: [UInt8]
        var closedByPeer: Bool
        var finished: Bool
        var firstByteAt: Date?
        var lastByteAt: Date?
    }

    var snapshot: Snapshot {
        lock.lock()
        defer { lock.unlock() }
        return Snapshot(received: received, closedByPeer: closedByPeer, finished: finished, firstByteAt: firstByteAt, lastByteAt: lastByteAt)
    }

    /// حتى تُغلق المهمّة من طرفنا أو تنقضي المهلة
    func waitUntilFinished(timeout: TimeInterval) async -> Snapshot {
        _ = await eventually(timeout: timeout) { self.snapshot.finished }
        return snapshot
    }

    enum PrinterError: Error {
        case cannotListen
    }
}
