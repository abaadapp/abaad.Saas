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

    fun encode(image: BitImage, options: Options = Options()): ByteArray {
        require(options.bandHeight in 1..0xFFFF) { "bandHeight out of range" }
        require(options.feedLines in 0..255) { "feedLines out of range" }
        require(image.bytesPerRow <= 0xFFFF) { "image too wide" }

        val raster = if (options.trimTrailingBlank) image.trimTrailingBlank() else image
        val out = ByteArrayOutputStream(16 + raster.data.size + (raster.height / options.bandHeight + 1) * 8)

        out.write(0x1B)
        out.write(0x40)

        // ولا أمرَ صورةٍ أصلًا إن لم يبقَ صفّ
        if (raster.height > 0 && raster.bytesPerRow > 0) {
            var top = 0
            while (top < raster.height) {
                val rows = minOf(options.bandHeight, raster.height - top)
                out.write(0x1D)
                out.write(0x76)
                out.write(0x30)
                out.write(0x00)
                out.write(raster.bytesPerRow and 0xFF)
                out.write((raster.bytesPerRow shr 8) and 0xFF)
                out.write(rows and 0xFF)
                out.write((rows shr 8) and 0xFF)
                out.write(raster.data, top * raster.bytesPerRow, rows * raster.bytesPerRow)
                top += rows
            }
        }

        if (options.feedLines > 0) {
            out.write(0x1B)
            out.write(0x64)
            out.write(options.feedLines)
        }

        if (options.cut) {
            out.write(0x1D)
            out.write(0x56)
            out.write(0x42)
            out.write(0x00)
        }

        return out.toByteArray()
    }
}
