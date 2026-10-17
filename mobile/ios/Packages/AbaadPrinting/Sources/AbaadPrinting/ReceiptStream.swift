import Foundation

/*
 * الإيصالُ تيّارًا لا صورةً واحدة — §5 و§7.
 *
 * إيصالٌ بألف صنفٍ نحو ثلاثة أمتار: 21000 صفٍّ على 576 نقطة. ولو جُمع صورةً
 * واحدةً ثمّ بايتاتٍ واحدة لكان طولُه حدًّا يرفض عنده (كان 40000 صفّ)، وذاكرةً
 * تكبر بطوله. فالصفوفُ تمرّ هنا شريطًا شريطًا: `SeamJoiner` يصل صفحاتِ الـPDF،
 * و`EscPosStream` يرزمها أوامرَ `GS v 0` ويكتبها حيث يُراد (ملفُّ التخزين).
 * ولا يبقى في الذاكرة إلّا شريطٌ واحد.
 */

/// يرزم الصفوفَ أوامرَ ESC/POS ويكتبها بالترتيب: تهيئة، ثمّ الصورُ، ثمّ تغذيةٌ وقصٌّ واحد
public final class EscPosStream {
    public let bytesPerRow: Int
    public let options: EscPosOptions
    /// الصفوفُ المكتوبة حتى الآن
    public private(set) var rows = 0

    private let write: ([UInt8]) throws -> Void
    private let bandHeight: Int
    private var band: [UInt8]
    private var bandRows = 0
    private var finished = false

    public init(bytesPerRow: Int, options: EscPosOptions = .receipt, write: @escaping ([UInt8]) throws -> Void) throws {
        precondition(bytesPerRow >= 0)
        self.bytesPerRow = bytesPerRow
        self.options = options
        self.write = write
        self.bandHeight = max(1, min(options.bandHeight, 0xFFFF))
        self.band = [UInt8](repeating: 0, count: bandHeight * bytesPerRow)
        try write([0x1B, 0x40])
    }

    /// صفٌّ مرزوم بطول `bytesPerRow`
    public func append<Row: Collection>(row: Row) throws where Row.Element == UInt8 {
        precondition(row.count == bytesPerRow, "row width mismatch")
        guard bytesPerRow > 0 else { return }
        let start = bandRows * bytesPerRow
        band.replaceSubrange(start..<(start + bytesPerRow), with: row)
        try advance()
    }

    /// صفوفٌ بيضاء
    public func appendBlank(_ count: Int) throws {
        guard bytesPerRow > 0 else { return }
        for _ in 0..<max(0, count) {
            let start = bandRows * bytesPerRow
            band.replaceSubrange(start..<(start + bytesPerRow), with: repeatElement(0, count: bytesPerRow))
            try advance()
        }
    }

    /// آخرُ شريط، ثمّ التغذيةُ والقصّ — مرّةً واحدةً بالضبط
    public func finish() throws {
        guard !finished else { return }
        finished = true
        try flush()
        var tail: [UInt8] = []
        if options.feedLines > 0 {
            tail += [0x1B, 0x64, UInt8(min(options.feedLines, 255))]
        }
        if options.cut {
            tail += [0x1D, 0x56, 0x42, 0x00]
        }
        if !tail.isEmpty {
            try write(tail)
        }
    }

    private func advance() throws {
        bandRows += 1
        rows += 1
        if bandRows == bandHeight {
            try flush()
        }
    }

    private func flush() throws {
        guard bandRows > 0 else { return }
        let x = bytesPerRow
        var out: [UInt8] = [0x1D, 0x76, 0x30, 0x00, UInt8(x & 0xFF), UInt8((x >> 8) & 0xFF), UInt8(bandRows & 0xFF), UInt8((bandRows >> 8) & 0xFF)]
        out += band[0..<(bandRows * x)]
        bandRows = 0
        try write(out)
    }
}

/*
 * وصلُ صفحات الـPDF شريطًا واحدًا على الورق.
 *
 * الخادمُ يقسم الإيصالَ الطويل صفحاتٍ لا تتجاوز مترين (`MpdfDriver::strip`).
 * ولكلّ صفحةٍ هامشاها، وفي آخرها ما بقي تحت آخر صنفٍ اتّسعت له. فلو كُدّست كما
 * هي لخرج بين كلّ صفحتين فراغٌ يبلغ سنتيمترات — والطابعةُ لا تعرف الصفحات.
 *
 *   - الفراغُ الذي يلمس وصلةً (آخرُ صفحةٍ وأوّلُ التالية) يُطوى إلى `maxSeamBlank` صفًّا.
 *   - والفراغُ داخل الصفحة يبقى كما هو: هو تباعدُ السطور الذي رسمه القالب.
 *   - وفراغُ أوّل الصفحة الأولى يبقى (هامشُ الرأس)، وفراغُ الآخر يُحذف (§7 `trimTrailingBlank`).
 *
 * والصفُّ الأبيض لا يحمل شيئًا من الإيصال، فطيُّه لا يُسقط سطرًا أبدًا.
 */
public final class SeamJoiner {
    /// نحو مليمترين على ورق 80 — قريبٌ من تباعد صنفين متتاليين
    public static let maxSeamBlankRows = 16

    public let maxSeamBlank: Int
    private let sink: EscPosStream
    private let keepTrailingBlank: Bool
    private var pendingBlank = 0
    private var atSeam = false
    private var pages = 0

    public init(sink: EscPosStream, maxSeamBlank: Int = SeamJoiner.maxSeamBlankRows, keepTrailingBlank: Bool = false) {
        self.sink = sink
        self.maxSeamBlank = max(0, maxSeamBlank)
        self.keepTrailingBlank = keepTrailingBlank
    }

    public func beginPage() {
        if pages > 0 {
            atSeam = true
        }
        pages += 1
    }

    /// صفوفُ الصفحة الحاليّة بالترتيب — شريطًا بعد شريط
    public func append(_ image: BitImage) throws {
        precondition(image.bytesPerRow == sink.bytesPerRow, "band width mismatch")
        let stride = image.bytesPerRow
        guard stride > 0 else { return }
        for y in 0..<image.height {
            if image.isRowBlank(y) {
                pendingBlank += 1
            } else {
                try flushBlank()
                try sink.append(row: image.data[(y * stride)..<((y + 1) * stride)])
            }
        }
    }

    public func finish() throws {
        if keepTrailingBlank {
            try flushBlank()
        }
        pendingBlank = 0
        try sink.finish()
    }

    private func flushBlank() throws {
        let rows = atSeam ? min(pendingBlank, maxSeamBlank) : pendingBlank
        pendingBlank = 0
        atSeam = false
        try sink.appendBlank(rows)
    }
}
