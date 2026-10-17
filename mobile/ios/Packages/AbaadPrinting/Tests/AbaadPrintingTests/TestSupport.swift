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
