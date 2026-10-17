import Darwin
import Foundation

/*
 * TCP خام إلى الطابعة — §5 الخطوتان 4 و5.
 *
 * ═══ وما قبل الاتّصال غيرُ ما بعده ═══
 *
 * قبل أن يتمّ الاتّصال لم يصل الطابعةَ شيء، فالفشلُ يُسمّى بسببه:
 *   - ECONNREFUSED ← PORT_REFUSED: الجهازُ موجود والمنفذُ مغلق.
 *   - ETIMEDOUT أو انقضاءُ 5 ثوانٍ ← CONNECT_TIMEOUT.
 *   - ما سوى ذلك ← PRINTER_UNREACHABLE. ومنه منعُ «الشبكة المحليّة» في iOS:
 *     يظهر هنا خطأَ وصولٍ لا رمزًا خاصًّا به.
 *
 * وبعد الاتّصال قد يكون جزءٌ من الإيصال خرج من الطابعة. فكلُّ فشلٍ بعده
 * SEND_FAILED مع `connected` و`mayHavePrinted`، ولا يُعاد الإرسالُ أبدًا:
 * الكاشيرُ يرى الورقة ويقرّر.
 *
 * ═══ والإرسالُ يُحرس بالتقدّم لا بالمدّة ═══
 *
 * كانت للإرسال كلِّه 30 ثانية ثمّ يُقطع الاتّصال. وإيصالٌ طويل على طابعةٍ تطبع
 * الصورةَ ببطء يحتاج أكثر — فكان يخرج إلى منتصفه، والكاشيرُ يرى «انقطع الاتصال».
 *
 * والآن مخزنُ الإرسال صغير (`sendBufferSize`، 16KB)، فلا يقبل النظامُ بايتًا
 * جديدًا إلّا بعد أن تأخذ الطابعةُ ما قبله: كلُّ كتابةٍ تنجح تقدّمٌ حقيقيّ عند الطابعة،
 * ولا يتكدّس الإيصالُ في الجهاز أسرعَ ممّا تطبع (ضغطُ TCP الراجع). وما دامت كتابةٌ
 * تنجح فالإيصالُ يتمّ مهما طال؛ وإن لم تنجح كتابةٌ في `stallTimeout` (15 ثانية)
 * فالطابعةُ متوقّفة — ورقٌ نفد أو غطاءٌ مفتوح — فيُقطع: SEND_FAILED.
 *
 * وكان المشغّلُ على NWConnection، ومخزنُه لا يُضبط: يقبل مئاتِ الكيلوبايتات دفعةً
 * ثمّ يسكت ثوانيَ طويلة والطابعةُ تأخذ — فلا يُعرف التقدّمُ إلّا قفزاتٍ متباعدة، وطابعةٌ
 * بطيئة تبدو متوقّفة. فصار مقبسًا (BSD) كما في Android: يُضبط مخزنُه ويُرى تقدّمُه.
 *
 * ═══ وما تردّه الطابعة يُقرأ ═══
 *
 * بعضُ الطابعات تردّ بايتاتِ حالة. وردٌّ لا يُقرأ ثمّ يُغلق المقبس عليه يجعل النظامَ
 * يقطع الاتّصال بـRST — فيضيع آخرُ الإيصال الذي لم يصل بعد. فيُقرأ ويُهمل طوال
 * الإرسال، وبعد آخر بايتٍ يُغلق طرفُ الكتابة وتُنتظر الطابعةُ لتُغلق، أو `tailTimeout`.
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
    /// `host` عنوانٌ حرفيّ فُحص — لا اسمَ يُحلّ هنا. و`onProgress(sent, total)` بعد كلّ قطعةٍ قُبلت
    func send(
        _ payload: PrintPayload,
        host: String,
        port: Int,
        onStage: @escaping @Sendable (BridgeStage) -> Void,
        onProgress: @escaping @Sendable (Int, Int) -> Void
    ) async -> DriverResult
}

extension PrinterSending {
    /// بايتاتٌ في الذاكرة — صفحةُ الاختبار والاختبارات
    public func send(
        _ bytes: [UInt8],
        host: String,
        port: Int,
        onStage: @escaping @Sendable (BridgeStage) -> Void
    ) async -> DriverResult {
        await send(.bytes(bytes), host: host, port: port, onStage: onStage, onProgress: { _, _ in })
    }
}

public final class NetworkPrinterDriver: PrinterSending {
    public let connectTimeout: TimeInterval
    /// أطولُ مدّةٍ بلا كتابةٍ تنجح — لا مدّةُ الإرسال كلِّه
    public let stallTimeout: TimeInterval
    /// بعد آخر بايت: أطولُ انتظارٍ لإغلاق الطابعة قبل أن نُغلق نحن
    public let tailTimeout: TimeInterval
    public let chunkSize: Int
    /// مخزنُ الإرسال في النظام — صغيرٌ ليكون نجاحُ الكتابة تقدّمًا عند الطابعة
    public let sendBufferSize: Int

    public init(
        connectTimeout: TimeInterval = 5,
        stallTimeout: TimeInterval = 15,
        tailTimeout: TimeInterval = 1.5,
        chunkSize: Int = 16 * 1024,
        sendBufferSize: Int = 16 * 1024
    ) {
        self.connectTimeout = connectTimeout
        self.stallTimeout = stallTimeout
        self.tailTimeout = tailTimeout
        self.chunkSize = max(1, chunkSize)
        self.sendBufferSize = max(1024, sendBufferSize)
    }

    public func send(
        _ payload: PrintPayload,
        host: String,
        port: Int,
        onStage: @escaping @Sendable (BridgeStage) -> Void,
        onProgress: @escaping @Sendable (Int, Int) -> Void
    ) async -> DriverResult {
        // مقبسٌ حاجزٌ بالانتظار (poll) — على خيطٍ له، لا على مجمّع المهامّ
        await withCheckedContinuation { continuation in
            let thread = Thread { [self] in
                continuation.resume(returning: run(payload, host: host, port: port, onStage: onStage, onProgress: onProgress))
            }
            thread.name = "om.abaad.pos.printer.connection"
            thread.start()
        }
    }

    /// يُترجم خطأ ما قبل الاتّصال
    public static func connectFailure(errno code: Int32) -> ErrorCode {
        switch code {
        case ECONNREFUSED: return .portRefused
        case ETIMEDOUT: return .connectTimeout
        // EHOSTUNREACH وENETUNREACH ومنعُ الشبكة المحليّة وما يستجدّ
        default: return .printerUnreachable
        }
    }

    /// عنوانٌ حرفيٌّ فقط (ومنه link-local بنطاقه من `HostResolver`) — فلا يُسأل DNS أبدًا
    static func socketAddress(_ host: String, port: Int) -> (family: Int32, address: sockaddr_storage, length: socklen_t)? {
        guard (1...65535).contains(port) else { return nil }
        let bare = host.split(separator: "%", maxSplits: 1).first.map(String.init) ?? host
        let literal = host.contains(":")
            ? PrinterAddress.ipv6Bytes(bare) != nil && (!host.contains("%") || host.split(separator: "%").count == 2)
            : PrinterAddress.ipv4Octets(host) != nil && !host.contains("%")
        guard literal else { return nil }

        var hints = addrinfo()
        hints.ai_flags = AI_NUMERICHOST | AI_NUMERICSERV
        hints.ai_socktype = SOCK_STREAM
        var info: UnsafeMutablePointer<addrinfo>?
        guard getaddrinfo(host, String(port), &hints, &info) == 0, let first = info else { return nil }
        defer { freeaddrinfo(info) }
        var storage = sockaddr_storage()
        let length = first.pointee.ai_addrlen
        withUnsafeMutableBytes(of: &storage) { buffer in
            buffer.copyMemory(from: UnsafeRawBufferPointer(start: first.pointee.ai_addr, count: Int(length)))
        }
        return (first.pointee.ai_family, storage, length)
    }

    /// مقبسٌ لا يُسقط التطبيقَ بـSIGPIPE، ولا يؤخّر القطعَ الصغيرة، ومخزنُ إرساله صغير، ولا يحجب
    static func configure(_ fd: Int32, sendBuffer: Int) {
        var on: Int32 = 1
        setsockopt(fd, SOL_SOCKET, SO_NOSIGPIPE, &on, socklen_t(MemoryLayout<Int32>.size))
        setsockopt(fd, IPPROTO_TCP, TCP_NODELAY, &on, socklen_t(MemoryLayout<Int32>.size))
        var buffer = Int32(sendBuffer)
        setsockopt(fd, SOL_SOCKET, SO_SNDBUF, &buffer, socklen_t(MemoryLayout<Int32>.size))
        _ = fcntl(fd, F_SETFL, fcntl(fd, F_GETFL) | O_NONBLOCK)
    }

    // MARK: - المهمّة على خيطها

    private func run(
        _ payload: PrintPayload,
        host: String,
        port: Int,
        onStage: @Sendable (BridgeStage) -> Void,
        onProgress: @Sendable (Int, Int) -> Void
    ) -> DriverResult {
        guard let target = Self.socketAddress(host, port: port) else {
            return DriverResult(error: .internalError, connected: false, mayHavePrinted: false)
        }
        let started = DispatchTime.now()
        onStage(.connecting)

        let fd = socket(target.family, SOCK_STREAM, IPPROTO_TCP)
        guard fd >= 0 else {
            return DriverResult(error: .printerUnreachable, connected: false, mayHavePrinted: false)
        }
        defer { close(fd) }
        Self.configure(fd, sendBuffer: sendBufferSize)

        if let failure = connect(fd, to: target) {
            return DriverResult(error: failure, connected: false, mayHavePrinted: false)
        }
        let connectedAt = DispatchTime.now()
        func failed(_ sent: Int) -> DriverResult {
            DriverResult(
                error: .sendFailed, connected: true, mayHavePrinted: true, bytesSent: sent,
                connectMilliseconds: Self.milliseconds(from: started, to: connectedAt),
                sendMilliseconds: Self.milliseconds(from: connectedAt, to: .now())
            )
        }

        onStage(.sending)
        let reader: PayloadReader
        do {
            reader = try payload.reader()
        } catch {
            return failed(0)
        }
        defer { reader.close() }

        var sent = 0
        var pending = Data()
        var lastProgress = DispatchTime.now()
        // أغلقت الطابعةُ طرفَها (FIN): لا يُنتظر منها ردٌّ بعدُ — وإلّا بقي المقبسُ «جاهزًا للقراءة»
        // فدار الـpoll بلا نوم. والكتابةُ التالية تقول هل ما زالت تقرأ
        var peerClosed = false
        while sent < payload.count {
            if pending.isEmpty {
                do {
                    guard let next = try reader.next(max: chunkSize) else { return failed(sent) }
                    pending = next
                } catch {
                    return failed(sent)
                }
            }
            var fds = pollfd(fd: fd, events: Int16(peerClosed ? POLLOUT : POLLOUT | POLLIN), revents: 0)
            let ready = poll(&fds, 1, Self.tickMilliseconds)
            if ready < 0, errno != EINTR {
                return failed(sent)
            }
            if ready > 0 {
                if fds.revents & Int16(POLLIN) != 0 {
                    // ردودُ الطابعة تُقرأ وتُهمل. واتّصالٌ قطعته (RST) يفشل هنا فورًا — لا بعد مهلة التوقّف
                    switch drain(fd) {
                    case .open: break
                    case .closed: peerClosed = true
                    case .failed: return failed(sent)
                    }
                }
                // لا POLLHUP: يرفعه macOS لطرفٍ أُغلق وحده (FIN)، والطابعةُ قد تبقى تقرأ.
                // والانقطاعُ الحقيقيّ يظهر خطأً في القراءة أو الكتابة
                if fds.revents & Int16(POLLERR | POLLNVAL) != 0 {
                    return failed(sent)
                }
                if fds.revents & Int16(POLLOUT) != 0 {
                    let written = pending.withUnsafeBytes { Darwin.send(fd, $0.baseAddress, $0.count, 0) }
                    if written > 0 {
                        sent += written
                        pending.removeFirst(written)
                        lastProgress = .now()
                        onProgress(sent, payload.count)
                    } else if written < 0, errno != EAGAIN, errno != EWOULDBLOCK, errno != EINTR {
                        return failed(sent)
                    }
                }
            }
            if Self.seconds(since: lastProgress) > stallTimeout {
                return failed(sent)
            }
        }

        // الإيصالُ كلُّه في مخزنٍ لا يتّسع إلّا لأقلّه: يُغلق طرفُ الكتابة (FIN بعد آخر بايت)،
        // وتُقرأ ردودُ الطابعة حتى تُغلق هي أو ينقضي `tailTimeout`
        shutdown(fd, SHUT_WR)
        let tailStarted = DispatchTime.now()
        while Self.seconds(since: tailStarted) < tailTimeout {
            var fds = pollfd(fd: fd, events: Int16(POLLIN), revents: 0)
            let ready = poll(&fds, 1, Self.tickMilliseconds)
            if ready > 0, drain(fd) != .open {
                break
            }
            if ready < 0, errno != EINTR {
                break
            }
        }

        return DriverResult(
            error: nil, connected: true, mayHavePrinted: true, bytesSent: sent,
            connectMilliseconds: Self.milliseconds(from: started, to: connectedAt),
            sendMilliseconds: Self.milliseconds(from: connectedAt, to: .now())
        )
    }

    /// nil = تمّ الاتّصال
    private func connect(_ fd: Int32, to target: (family: Int32, address: sockaddr_storage, length: socklen_t)) -> ErrorCode? {
        var address = target.address
        let result = withUnsafePointer(to: &address) {
            $0.withMemoryRebound(to: sockaddr.self, capacity: 1) { Darwin.connect(fd, $0, target.length) }
        }
        if result == 0 { return nil }
        guard errno == EINPROGRESS else { return Self.connectFailure(errno: errno) }

        var fds = pollfd(fd: fd, events: Int16(POLLOUT), revents: 0)
        let deadline = DispatchTime.now().uptimeNanoseconds + UInt64(connectTimeout * 1_000_000_000)
        while true {
            let left = deadline > DispatchTime.now().uptimeNanoseconds
                ? Int32((deadline - DispatchTime.now().uptimeNanoseconds) / 1_000_000)
                : 0
            if left <= 0 { return .connectTimeout }
            let ready = poll(&fds, 1, left)
            if ready == 0 { return .connectTimeout }
            if ready < 0 {
                if errno == EINTR { continue }
                return .printerUnreachable
            }
            break
        }
        var error: Int32 = 0
        var length = socklen_t(MemoryLayout<Int32>.size)
        guard getsockopt(fd, SOL_SOCKET, SO_ERROR, &error, &length) == 0 else { return .printerUnreachable }
        return error == 0 ? nil : Self.connectFailure(errno: error)
    }

    private enum Inbound {
        /// قُرئ ما ردّته الطابعة ولا شيء بعده الآن
        case open
        /// أغلقت الطابعةُ طرفَها (FIN)
        case closed
        /// انقطع الاتّصال (RST أو خطأ)
        case failed
    }

    /// يقرأ ما ردّته الطابعة ويهمله
    private func drain(_ fd: Int32) -> Inbound {
        var scratch = [UInt8](repeating: 0, count: 4096)
        while true {
            let n = scratch.withUnsafeMutableBytes { recv(fd, $0.baseAddress, $0.count, 0) }
            if n > 0 { continue }
            if n == 0 { return .closed }
            return errno == EAGAIN || errno == EWOULDBLOCK || errno == EINTR ? .open : .failed
        }
    }

    private static let tickMilliseconds: Int32 = 250

    private static func seconds(since start: DispatchTime) -> TimeInterval {
        Double(DispatchTime.now().uptimeNanoseconds - start.uptimeNanoseconds) / 1_000_000_000
    }

    private static func milliseconds(from start: DispatchTime?, to end: DispatchTime?) -> Int? {
        guard let start, let end, end.uptimeNanoseconds >= start.uptimeNanoseconds else { return nil }
        return Int((end.uptimeNanoseconds - start.uptimeNanoseconds) / 1_000_000)
    }
}
