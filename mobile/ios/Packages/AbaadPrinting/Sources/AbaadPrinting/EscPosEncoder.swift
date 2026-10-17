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
    /// صورةٌ صغيرة في الذاكرة (صفحةُ الاختبار والمتّجهات) — بالتيّار نفسِه الذي يمرّ به الإيصال
    public static func encode(_ image: BitImage, options: EscPosOptions = .receipt) -> [UInt8] {
        var out: [UInt8] = []
        do {
            let stream = try EscPosStream(bytesPerRow: image.bytesPerRow, options: options) { out += $0 }
            let joiner = SeamJoiner(sink: stream, keepTrailingBlank: !options.trimTrailingBlank)
            joiner.beginPage()
            try joiner.append(image)
            try joiner.finish()
        } catch {
            preconditionFailure("writing to memory cannot fail")
        }
        return out
    }
}
