import Foundation
#if canImport(Darwin)
import Darwin
#endif

/*
 * حلُّ أسماء `.local` وحدها — §4.3.
 *
 * ═══ وكلُّ عنوانٍ يُحلّ إليه يُفحص ═══
 *
 * اسمُ `.local` يعلنه أيُّ جهازٍ على الشبكة. فلو أعلن `printer.local` عنوانًا
 * عامًّا أو 127.0.0.1 لصار بابًا خلفيًّا للفحص الخاصّ. فيجب أن يمرّ **كلُّ**
 * عنوانٍ ناتج، ثمّ يُتّصل بالعنوان الحرفيّ الذي فُحص لا بالاسم من جديد —
 * فلا يتبدّل الجوابُ بين الفحص والاتّصال.
 */
public protocol HostResolving: Sendable {
    /// عناوينُ حرفيّة (IPv6 link-local بنطاقه `%en0`)، أو nil إن فشل الحلّ
    func resolve(_ name: String) async -> [String]?
}

/// `getaddrinfo` على خيطٍ جانبيّ — لا يُلغى، فتسابقه مهلة
public struct SystemHostResolver: HostResolving {
    public let timeout: TimeInterval

    public init(timeout: TimeInterval = 5) {
        self.timeout = timeout
    }

    public func resolve(_ name: String) async -> [String]? {
        let once = Once<[String]?>()
        return await withCheckedContinuation { continuation in
            once.set(continuation)
            DispatchQueue.global(qos: .userInitiated).async {
                once.finish(Self.lookup(name))
            }
            DispatchQueue.global(qos: .userInitiated).asyncAfter(deadline: .now() + timeout) {
                once.finish(nil)
            }
        }
    }

    static func lookup(_ name: String) -> [String]? {
        var hints = addrinfo()
        hints.ai_family = AF_UNSPEC
        hints.ai_socktype = SOCK_STREAM
        hints.ai_protocol = IPPROTO_TCP
        var head: UnsafeMutablePointer<addrinfo>?
        guard getaddrinfo(name, nil, &hints, &head) == 0, let first = head else { return nil }
        defer { freeaddrinfo(first) }

        var addresses: [String] = []
        var cursor: UnsafeMutablePointer<addrinfo>? = first
        while let info = cursor {
            if let literal = literal(info.pointee.ai_addr), !addresses.contains(literal) {
                addresses.append(literal)
            }
            cursor = info.pointee.ai_next
        }
        return addresses
    }

    private static func literal(_ address: UnsafeMutablePointer<sockaddr>?) -> String? {
        guard let address else { return nil }
        var buffer = [CChar](repeating: 0, count: Int(INET6_ADDRSTRLEN) + 1)
        switch Int32(address.pointee.sa_family) {
        case AF_INET:
            return address.withMemoryRebound(to: sockaddr_in.self, capacity: 1) { pointer -> String? in
                var ip = pointer.pointee.sin_addr
                guard inet_ntop(AF_INET, &ip, &buffer, socklen_t(buffer.count)) != nil else { return nil }
                return String(cString: buffer)
            }
        case AF_INET6:
            return address.withMemoryRebound(to: sockaddr_in6.self, capacity: 1) { pointer -> String? in
                var ip = pointer.pointee.sin6_addr
                guard inet_ntop(AF_INET6, &ip, &buffer, socklen_t(buffer.count)) != nil else { return nil }
                let text = String(cString: buffer)
                let scope = pointer.pointee.sin6_scope_id
                var interface = [CChar](repeating: 0, count: Int(IF_NAMESIZE) + 1)
                if scope != 0, if_indextoname(scope, &interface) != nil {
                    return text + "%" + String(cString: interface)
                }
                return text
            }
        default:
            return nil
        }
    }
}

/// يختار العنوانَ الذي يُتّصل به بعد أن يمرّ الكلُّ بالفحص — §4.3
public enum ResolvedAddressPolicy {
    /// IPv4 الخاصّ أوّلًا ثمّ IPv6. الفشل: لا عناوين ← PRINTER_UNREACHABLE، وعنوانٌ غيرُ خاصّ ← INVALID_PRINTER_ADDRESS
    public static func choose(_ addresses: [String]?) -> Result<String, PrintingError> {
        guard let addresses, !addresses.isEmpty else {
            return .failure(PrintingError(.printerUnreachable))
        }
        var v4: String?
        var v6: String?
        for address in addresses {
            // النطاقُ (`%en0`) لا يُفحص، بل العنوانُ نفسُه
            let bare = address.split(separator: "%", maxSplits: 1).first.map(String.init) ?? address
            switch PrinterAddress.privateLiteral(bare) {
            case .ipv4?: v4 = v4 ?? address
            case .ipv6?: v6 = v6 ?? address
            default: return .failure(PrintingError(.invalidPrinterAddress))
            }
        }
        if let chosen = v4 ?? v6 {
            return .success(chosen)
        }
        return .failure(PrintingError(.printerUnreachable))
    }
}

/// قيمةٌ تُسلَّم مرّةً واحدة لمن ينتظرها، أيُّ الطرفين سبق
final class Once<Value: Sendable>: @unchecked Sendable {
    private let lock = NSLock()
    private var continuation: CheckedContinuation<Value, Never>?
    private var pending: Value?
    private var done = false

    func set(_ continuation: CheckedContinuation<Value, Never>) {
        lock.lock()
        if done, let value = pending {
            pending = nil
            lock.unlock()
            continuation.resume(returning: value)
            return
        }
        self.continuation = continuation
        lock.unlock()
    }

    func finish(_ value: Value) {
        lock.lock()
        guard !done else {
            lock.unlock()
            return
        }
        done = true
        if let continuation {
            self.continuation = nil
            lock.unlock()
            continuation.resume(returning: value)
        } else {
            pending = value
            lock.unlock()
        }
    }
}
