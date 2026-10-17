import Foundation
import Network

/*
 * TCP خام إلى الطابعة — §5 الخطوتان 4 و5.
 *
 * ═══ وما قبل «جاهز» غيرُ ما بعده ═══
 *
 * قبل أن يتمّ الاتّصال لم يصل الطابعةَ شيء، فالفشلُ يُسمّى بسببه:
 *   - ECONNREFUSED ← PORT_REFUSED: الجهازُ موجود والمنفذُ مغلق.
 *   - ETIMEDOUT أو انقضاءُ 5 ثوانٍ ← CONNECT_TIMEOUT.
 *   - ما سوى ذلك ← PRINTER_UNREACHABLE. ومنه منعُ «الشبكة المحليّة» في iOS:
 *     يظهر هنا خطأَ وصولٍ لا رمزًا خاصًّا به.
 *
 * وبعد «جاهز» قد يكون جزءٌ من الإيصال خرج من الطابعة. فكلُّ فشلٍ بعده
 * SEND_FAILED مع `connected` و`mayHavePrinted`، ولا يُعاد الإرسالُ أبدًا:
 * الكاشيرُ يرى الورقة ويقرّر.
 */
public struct DriverResult: Equatable, Sendable {
    /// nil = طُبع
    public var error: ErrorCode?
    public var connected: Bool
    public var mayHavePrinted: Bool
    public var bytesSent: Int
    public var connectMilliseconds: Int?
    public var sendMilliseconds: Int?

    public init(
        error: ErrorCode?,
        connected: Bool,
        mayHavePrinted: Bool,
        bytesSent: Int = 0,
        connectMilliseconds: Int? = nil,
        sendMilliseconds: Int? = nil
    ) {
        self.error = error
        self.connected = connected
        self.mayHavePrinted = mayHavePrinted
        self.bytesSent = bytesSent
        self.connectMilliseconds = connectMilliseconds
        self.sendMilliseconds = sendMilliseconds
    }

    public var succeeded: Bool { error == nil }
}

public protocol PrinterSending: Sendable {
    /// `host` عنوانٌ حرفيّ فُحص — لا اسمَ يُحلّ هنا
    func send(
        _ bytes: [UInt8],
        host: String,
        port: Int,
        onStage: @escaping @Sendable (BridgeStage) -> Void
    ) async -> DriverResult
}

public final class NetworkPrinterDriver: PrinterSending {
    public let connectTimeout: TimeInterval
    public let writeTimeout: TimeInterval

    public init(connectTimeout: TimeInterval = 5, writeTimeout: TimeInterval = 30) {
        self.connectTimeout = connectTimeout
        self.writeTimeout = writeTimeout
    }

    public func send(
        _ bytes: [UInt8],
        host: String,
        port: Int,
        onStage: @escaping @Sendable (BridgeStage) -> Void
    ) async -> DriverResult {
        let operation = SendOperation(
            bytes: bytes, host: host, port: port,
            connectTimeout: connectTimeout, writeTimeout: writeTimeout, onStage: onStage
        )
        return await withCheckedContinuation { continuation in
            operation.start(continuation)
        }
    }

    /// يُترجم خطأ ما قبل «جاهز»
    public static func connectFailure(_ error: NWError) -> ErrorCode {
        switch error {
        case let .posix(code):
            switch code {
            case .ECONNREFUSED: return .portRefused
            case .ETIMEDOUT: return .connectTimeout
            default: return .printerUnreachable
            }
        default:
            // DNS (ومنه رفضُ إذن الشبكة المحليّة) وTLS وما يستجدّ
            return .printerUnreachable
        }
    }

    static func endpointHost(_ host: String) -> NWEndpoint.Host? {
        if let v4 = IPv4Address(host), PrinterAddress.ipv4Octets(host) != nil {
            return .ipv4(v4)
        }
        if host.contains(":") {
            if !host.contains("%"), let v6 = IPv6Address(host) {
                return .ipv6(v6)
            }
            // link-local بنطاقه من `HostResolver` — حرفيٌّ رقميّ، فلا يسأل DNS
            let bare = host.split(separator: "%", maxSplits: 1).first.map(String.init) ?? host
            if PrinterAddress.ipv6Bytes(bare) != nil {
                return NWEndpoint.Host(host)
            }
        }
        return nil
    }

    /// إرسالٌ واحد: حالُه كلُّها على طابورِه التسلسليّ، فلا قفل
    private final class SendOperation: @unchecked Sendable {
        private let bytes: [UInt8]
        private let host: String
        private let port: Int
        private let connectTimeout: TimeInterval
        private let writeTimeout: TimeInterval
        private let onStage: @Sendable (BridgeStage) -> Void
        private let queue = DispatchQueue(label: "om.abaad.pos.printer.connection")

        private var connection: NWConnection?
        private var continuation: CheckedContinuation<DriverResult, Never>?
        private var ready = false
        private var finished = false
        private var startedAt = DispatchTime.now()
        private var readyAt: DispatchTime?

        init(
            bytes: [UInt8], host: String, port: Int,
            connectTimeout: TimeInterval, writeTimeout: TimeInterval,
            onStage: @escaping @Sendable (BridgeStage) -> Void
        ) {
            self.bytes = bytes
            self.host = host
            self.port = port
            self.connectTimeout = connectTimeout
            self.writeTimeout = writeTimeout
            self.onStage = onStage
        }

        func start(_ continuation: CheckedContinuation<DriverResult, Never>) {
            queue.async { [self] in
                self.continuation = continuation
                guard let endpointHost = NetworkPrinterDriver.endpointHost(host),
                      (1...65535).contains(port),
                      let endpointPort = NWEndpoint.Port(rawValue: UInt16(port))
                else {
                    finish(DriverResult(error: .internalError, connected: false, mayHavePrinted: false))
                    return
                }

                let tcp = NWProtocolTCP.Options()
                tcp.connectionTimeout = max(1, Int(connectTimeout.rounded(.up)))
                tcp.noDelay = true
                let connection = NWConnection(host: endpointHost, port: endpointPort, using: NWParameters(tls: nil, tcp: tcp))
                self.connection = connection
                connection.stateUpdateHandler = { [self] state in
                    handle(state)
                }

                startedAt = .now()
                onStage(.connecting)
                connection.start(queue: queue)

                queue.asyncAfter(deadline: .now() + connectTimeout) { [self] in
                    if !ready {
                        finish(DriverResult(error: .connectTimeout, connected: false, mayHavePrinted: false))
                    }
                }
            }
        }

        private func handle(_ state: NWConnection.State) {
            guard !finished else { return }
            switch state {
            case .ready:
                guard !ready else { return }
                ready = true
                readyAt = .now()
                write()
            case let .waiting(error), let .failed(error):
                if ready {
                    finish(sendFailure())
                } else {
                    // لا انتظارَ لتغيّر الشبكة ولا إعادة: يُقال السببُ الآن
                    finish(DriverResult(error: NetworkPrinterDriver.connectFailure(error), connected: false, mayHavePrinted: false))
                }
            case .cancelled:
                finish(ready ? sendFailure() : DriverResult(error: .internalError, connected: false, mayHavePrinted: false))
            default:
                break
            }
        }

        private func write() {
            onStage(.sending)
            queue.asyncAfter(deadline: .now() + writeTimeout) { [self] in
                finish(sendFailure())
            }
            // رسالةٌ أخيرة: تُرسَل البايتاتُ ثمّ يُغلق طرفُ الكتابة
            connection?.send(
                content: Data(bytes),
                contentContext: .finalMessage,
                isComplete: true,
                completion: .contentProcessed { [self] error in
                    queue.async { [self] in
                        if error != nil {
                            finish(sendFailure())
                        } else {
                            finish(DriverResult(
                                error: nil, connected: true, mayHavePrinted: true, bytesSent: bytes.count,
                                connectMilliseconds: milliseconds(from: startedAt, to: readyAt),
                                sendMilliseconds: milliseconds(from: readyAt, to: .now())
                            ), graceful: true)
                        }
                    }
                }
            )
        }

        private func sendFailure() -> DriverResult {
            DriverResult(
                error: .sendFailed, connected: true, mayHavePrinted: true,
                connectMilliseconds: milliseconds(from: startedAt, to: readyAt),
                sendMilliseconds: milliseconds(from: readyAt, to: .now())
            )
        }

        /// يُستأنف المنتظِرُ مرّةً واحدة بالضبط، ثمّ يُغلق الاتّصال
        private func finish(_ result: DriverResult, graceful: Bool = false) {
            guard !finished else { return }
            finished = true
            if let connection {
                if graceful {
                    // الإغلاقُ اللطيف يُسلّم ما بقي في المخزن قبل FIN. ويبقى الاتّصالُ
                    // حيًّا بمعالجه حتى يُغلق فعلًا، ثمّ يُفكّ.
                    connection.stateUpdateHandler = { state in
                        if case .cancelled = state {
                            connection.stateUpdateHandler = nil
                        }
                    }
                    connection.cancel()
                } else {
                    connection.stateUpdateHandler = nil
                    connection.forceCancel()
                }
            }
            self.connection = nil
            let continuation = self.continuation
            self.continuation = nil
            continuation?.resume(returning: result)
        }

        private func milliseconds(from start: DispatchTime?, to end: DispatchTime?) -> Int? {
            guard let start, let end, end.uptimeNanoseconds >= start.uptimeNanoseconds else { return nil }
            return Int((end.uptimeNanoseconds - start.uptimeNanoseconds) / 1_000_000)
        }
    }
}
