import Foundation

/*
 * ما يُرسل إلى الطابعة — §5.
 *
 * صفحةُ الاختبار بايتاتٌ قليلة في الذاكرة. والإيصالُ يُكتب أوّلًا إلى ملفٍّ مؤقّت
 * (`ReceiptSpool`) ثمّ يُقرأ منه قطعةً قطعة: فلا يُرسل منه بايتٌ قبل أن يُرسم كلُّه
 * — رسمٌ يفشل في منتصفه لا يترك نصفَ إيصالٍ على الورق — ولا يُحمل كلُّه في الذاكرة.
 */
public enum PrintPayload: Sendable {
    case bytes([UInt8])
    case file(URL, count: Int)

    public var count: Int {
        switch self {
        case let .bytes(bytes): return bytes.count
        case let .file(_, count): return count
        }
    }

    func reader() throws -> PayloadReader {
        switch self {
        case let .bytes(bytes): return MemoryReader(bytes)
        case let .file(url, _): return try FileReader(url)
        }
    }
}

protocol PayloadReader: AnyObject {
    /// القطعةُ التالية، أو nil عند النهاية
    func next(max: Int) throws -> Data?
    func close()
}

private final class MemoryReader: PayloadReader {
    private let bytes: [UInt8]
    private var offset = 0

    init(_ bytes: [UInt8]) {
        self.bytes = bytes
    }

    func next(max: Int) throws -> Data? {
        guard offset < bytes.count else { return nil }
        let end = min(bytes.count, offset + max)
        defer { offset = end }
        return Data(bytes[offset..<end])
    }

    func close() {}
}

private final class FileReader: PayloadReader {
    private let handle: FileHandle

    init(_ url: URL) throws {
        handle = try FileHandle(forReadingFrom: url)
    }

    func next(max: Int) throws -> Data? {
        guard let data = try handle.read(upToCount: max), !data.isEmpty else { return nil }
        return data
    }

    func close() {
        try? handle.close()
    }
}

/// الإيصالُ بايتاتِ ESC/POS في ملفٍّ مؤقّت — يُحذف بعد الإرسال في كلّ حال
public final class ReceiptSpool: @unchecked Sendable {
    public let url: URL
    public let count: Int

    private init(url: URL, count: Int) {
        self.url = url
        self.count = count
    }

    public var payload: PrintPayload {
        .file(url, count: count)
    }

    /// يرسم الـPDF ويرمّزه إلى الملفّ شريطًا شريطًا. وأيُّ فشلٍ ← RECEIPT_RENDER_FAILED بلا ملفٍّ باقٍ
    public static func make(
        pdf: Data,
        widthDots: Int,
        directory: URL = FileManager.default.temporaryDirectory,
        onBand: () -> Void = {}
    ) throws -> ReceiptSpool {
        let url = directory.appendingPathComponent("abaad-receipt-\(UUID().uuidString).escpos")
        guard FileManager.default.createFile(atPath: url.path, contents: nil) else {
            throw PrintingError(.receiptRenderFailed)
        }
        do {
            let handle = try FileHandle(forWritingTo: url)
            defer { try? handle.close() }
            var count = 0
            let stream = try EscPosStream(bytesPerRow: (widthDots + 7) / 8) { bytes in
                try handle.write(contentsOf: bytes)
                count += bytes.count
            }
            try PDFRasterizer.encode(pdf, widthDots: widthDots, into: stream, onBand: onBand)
            return ReceiptSpool(url: url, count: count)
        } catch {
            try? FileManager.default.removeItem(at: url)
            throw PrintingError(.receiptRenderFailed)
        }
    }

    public func remove() {
        try? FileManager.default.removeItem(at: url)
    }
}
