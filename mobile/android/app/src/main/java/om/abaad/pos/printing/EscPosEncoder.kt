package om.abaad.pos.printing

import java.io.ByteArrayOutputStream

/**
 * بايتاتُ ESC/POS للمهمّة كلّها (PROTOCOL §7) — والمتّجهاتُ `escpos` تُثبتها بايتًا بايتًا:
 *
 *   ESC @                      1B 40                      تهيئة
 *   GS v 0 m xL xH yL yH d…    1D 76 30 00 xL xH yL yH …  لكلّ شريطٍ من bandHeight صفًّا
 *   ESC d n                    1B 64 n                    تغذية n أسطر (إن n > 0)
 *   GS V 66 0                  1D 56 42 00                تغذيةٌ ثم قصٌّ جزئيّ (إن cut)
 *
 * ولا شيءَ خاصٌّ بطرازٍ أو صانع: الطابعةُ بلا قاطع تتجاهل `GS V`.
 */
object EscPosEncoder {

    class Options(
        val bandHeight: Int = 128,
        val feedLines: Int = 4,
        val cut: Boolean = true,
        val trimTrailingBlank: Boolean = true,
    )

    /** صورةٌ صغيرة في الذاكرة (صفحةُ الاختبار والمتّجهات) — بالتيّار نفسِه الذي يمرّ به الإيصال */
    fun encode(image: BitImage, options: Options = Options()): ByteArray {
        val out = ByteArrayOutputStream(16 + image.data.size + (image.height / maxOf(1, options.bandHeight) + 1) * 8)
        val stream = EscPosStream(out, image.bytesPerRow, options)
        val joiner = SeamJoiner(stream, keepTrailingBlank = !options.trimTrailingBlank)
        joiner.beginPage()
        joiner.append(image)
        joiner.finish()
        return out.toByteArray()
    }
}
