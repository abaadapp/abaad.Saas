import Foundation

/*
 * بايتاتُ ESC/POS — §7، والمتّجهاتُ `escpos` تُثبتها بايتًا بايتًا.
 *
 *   ESC @                    1B 40                      تهيئة
 *   GS v 0 m xL xH yL yH d…  1D 76 30 00 xL xH yL yH …  لكلّ شريطٍ من bandHeight صفًّا
 *   ESC d n                  1B 64 n                    تغذيةُ n أسطر (إن n > 0)
 *   GS V 66 0                1D 56 42 00                تغذيةٌ ثمّ قصٌّ جزئيّ (إن cut)
 *
 * ولا شيءَ خاصًّا بطرازٍ أو مصنّع: أوامرُ الصورة النقطيّة هذه يفهمها كلُّ طابعةِ
 * إيصالاتٍ حراريّة، والطابعةُ بلا قاطعٍ تتجاهل `GS V`.
 */
public struct EscPosOptions: Equatable, Sendable {
    public var bandHeight: Int
    public var feedLines: Int
    public var cut: Bool
    public var trimTrailingBlank: Bool

    public init(bandHeight: Int = 128, feedLines: Int = 4, cut: Bool = true, trimTrailingBlank: Bool = true) {
        self.bandHeight = bandHeight
        self.feedLines = feedLines
        self.cut = cut
        self.trimTrailingBlank = trimTrailingBlank
    }

    /// القيمُ الافتراضيّة للإيصال وصفحة الاختبار
    public static let receipt = EscPosOptions()
}

public enum EscPosEncoder {
    public static func encode(_ image: BitImage, options: EscPosOptions = .receipt) -> [UInt8] {
        var out: [UInt8] = [0x1B, 0x40]

        let raster = options.trimTrailingBlank ? image.trimmingTrailingBlank() : image
        // لم يبقَ صفّ ← لا أمرَ صورةٍ أصلًا
        if raster.height > 0, raster.bytesPerRow > 0 {
            let band = max(1, options.bandHeight)
            let x = raster.bytesPerRow
            var top = 0
            while top < raster.height {
                let rows = min(band, raster.height - top)
                out += [0x1D, 0x76, 0x30, 0x00, UInt8(x & 0xFF), UInt8((x >> 8) & 0xFF), UInt8(rows & 0xFF), UInt8((rows >> 8) & 0xFF)]
                out += raster.data[(top * x)..<((top + rows) * x)]
                top += rows
            }
        }

        if options.feedLines > 0 {
            out += [0x1B, 0x64, UInt8(min(options.feedLines, 255))]
        }
        if options.cut {
            out += [0x1D, 0x56, 0x42, 0x00]
        }
        return out
    }
}
