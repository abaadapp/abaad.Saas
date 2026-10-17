import Foundation

/*
 * صورةٌ أحاديّةُ اللون كما تأخذها الطابعة — §5 الخطوة 3 و§7.
 *
 * كلُّ صفٍّ ⌈width / 8⌉ بايتًا، والبتُّ الأعلى أقصى اليسار، و1 = نقطةٌ سوداء،
 * والبتّاتُ الزائدة في آخر بايت بيضاء (0). فيُرسل الصفُّ إلى `GS v 0` كما هو.
 */
public struct BitImage: Equatable, Sendable {
    public let width: Int
    public let height: Int
    public let bytesPerRow: Int
    public let data: [UInt8]

    public init(width: Int, height: Int, data: [UInt8]) {
        precondition(width >= 0 && height >= 0)
        let bytesPerRow = (width + 7) / 8
        precondition(data.count == bytesPerRow * height)
        self.width = width
        self.height = height
        self.bytesPerRow = bytesPerRow
        self.data = data
    }

    public static func blank(width: Int, height: Int) -> BitImage {
        BitImage(width: width, height: height, data: [UInt8](repeating: 0, count: ((width + 7) / 8) * height))
    }

    public func isBlack(x: Int, y: Int) -> Bool {
        guard x >= 0, x < width, y >= 0, y < height else { return false }
        return data[y * bytesPerRow + x / 8] & (0x80 >> UInt8(x % 8)) != 0
    }

    public func isRowBlank(_ y: Int) -> Bool {
        let start = y * bytesPerRow
        return data[start..<(start + bytesPerRow)].allSatisfy { $0 == 0 }
    }

    /// تُحذف الصفوفُ البيضاء من الآخر — §7 `trimTrailingBlank`
    public func trimmingTrailingBlank() -> BitImage {
        var rows = height
        while rows > 0, isRowBlank(rows - 1) {
            rows -= 1
        }
        guard rows != height else { return self }
        return BitImage(width: width, height: rows, data: Array(data[0..<(rows * bytesPerRow)]))
    }

    /// صفحاتٌ بعرضٍ واحد فوق بعضها، بالترتيب
    public static func stacked(_ pages: [BitImage], width: Int) -> BitImage {
        var data: [UInt8] = []
        var height = 0
        for page in pages where page.width == width {
            data.append(contentsOf: page.data)
            height += page.height
        }
        return BitImage(width: width, height: height, data: data)
    }

    /// للاختبارات: صفٌّ لكلّ نصّ، و«1» نقطةٌ سوداء
    public static func fromRows(_ rows: [String], width: Int) -> BitImage {
        let bytesPerRow = (width + 7) / 8
        var data = [UInt8](repeating: 0, count: bytesPerRow * rows.count)
        for (y, row) in rows.enumerated() {
            for (x, character) in row.enumerated() where x < width && character == "1" {
                data[y * bytesPerRow + x / 8] |= 0x80 >> UInt8(x % 8)
            }
        }
        return BitImage(width: width, height: rows.count, data: data)
    }

    /// من بكسلات RGBA (أو RGBX) بثمانية بتّات — كما يرسمها CGContext
    public static func fromRGBA(width: Int, height: Int, bytesPerRow sourceStride: Int, pixels: UnsafeRawBufferPointer) -> BitImage {
        let bytesPerRow = (width + 7) / 8
        var data = [UInt8](repeating: 0, count: bytesPerRow * height)
        guard width > 0, height > 0, pixels.count >= sourceStride * (height - 1) + width * 4 else {
            return BitImage(width: width, height: height, data: data)
        }
        data.withUnsafeMutableBufferPointer { out in
            for y in 0..<height {
                let row = y * sourceStride
                let outRow = y * bytesPerRow
                for x in 0..<width {
                    let p = row + x * 4
                    let luminance = Monochrome.luminance(r: Int(pixels[p]), g: Int(pixels[p + 1]), b: Int(pixels[p + 2]))
                    if Monochrome.isBlack(luminance: luminance) {
                        out[outRow + x / 8] |= 0x80 >> UInt8(x % 8)
                    }
                }
            }
        }
        return BitImage(width: width, height: height, data: data)
    }

    /// من بكسلاتٍ رماديّة بثمانية بتّات: القيمةُ نفسُها هي السطوع
    public static func fromGrayscale(width: Int, height: Int, bytesPerRow sourceStride: Int, pixels: UnsafeRawBufferPointer) -> BitImage {
        let bytesPerRow = (width + 7) / 8
        var data = [UInt8](repeating: 0, count: bytesPerRow * height)
        guard width > 0, height > 0, pixels.count >= sourceStride * (height - 1) + width else {
            return BitImage(width: width, height: height, data: data)
        }
        for y in 0..<height {
            for x in 0..<width where Monochrome.isBlack(luminance: Int(pixels[y * sourceStride + x])) {
                data[y * bytesPerRow + x / 8] |= 0x80 >> UInt8(x % 8)
            }
        }
        return BitImage(width: width, height: height, data: data)
    }
}

/// العتبة — بلا تنعيمٍ ولا تشويش: النصُّ ورمزُ QR أوضحُ بالعتبة
public enum Monochrome {
    public static let threshold = 160

    /// ⌊(299R + 587G + 114B) / 1000⌋ — بالأعداد الصحيحة، كما في Android حرفًا
    public static func luminance(r: Int, g: Int, b: Int) -> Int {
        (299 * r + 587 * g + 114 * b) / 1000
    }

    public static func isBlack(luminance: Int) -> Bool {
        luminance < threshold
    }
}
