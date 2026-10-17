package om.abaad.pos.printing

/**
 * صورةٌ أحاديّةُ اللون مرزومةٌ صفًّا صفًّا كما تريدها `GS v 0`:
 * كلُّ صفٍّ ⌈width / 8⌉ بايتًا، والبتُّ الأعلى أقصى اليسار، و1 = نقطة سوداء،
 * والبتّاتُ الزائدة في آخر بايت بيضاء (0).
 */
class BitImage(val width: Int, val height: Int, val bytesPerRow: Int, val data: ByteArray) {
    init {
        require(width >= 0 && height >= 0) { "negative size" }
        require(bytesPerRow == MonochromeRaster.bytesPerRow(width)) { "bytesPerRow must be ceil(width / 8)" }
        require(data.size == bytesPerRow * height) { "data size mismatch" }
    }

    fun isRowBlank(row: Int): Boolean {
        val start = row * bytesPerRow
        for (i in start until start + bytesPerRow) {
            if (data[i].toInt() != 0) return false
        }
        return true
    }

    /** تُحذف الصفوفُ البيضاء من الآخر — فلا يُغذّى ورقٌ أبيض بلا داعٍ */
    fun trimTrailingBlank(): BitImage {
        var rows = height
        while (rows > 0 && isRowBlank(rows - 1)) rows--
        if (rows == height) return this
        return BitImage(width, rows, bytesPerRow, data.copyOf(rows * bytesPerRow))
    }

    fun isBlack(x: Int, y: Int): Boolean {
        val byte = data[y * bytesPerRow + (x ushr 3)].toInt()
        return (byte and (0x80 ushr (x and 7))) != 0
    }
}

/**
 * من بكسلات ARGB إلى `BitImage` بالعتبة (PROTOCOL §5):
 *
 *   السطوع = ⌊(299R + 587G + 114B) / 1000⌋، والنقطةُ سوداء إن كان أقلَّ من 160.
 *
 * لا تنعيم ولا تشويش: النصُّ ورمزُ QR أوضحُ بالعتبة. والشفافيّةُ تُهمل — من
 * يرسم يملأ الخلفيّةَ بالأبيض قبل الرسم.
 */
object MonochromeRaster {
    const val THRESHOLD = 160

    fun bytesPerRow(width: Int): Int = (width + 7) / 8

    fun luminance(r: Int, g: Int, b: Int): Int = (299 * r + 587 * g + 114 * b) / 1000

    fun isBlackLuminance(luminance: Int): Boolean = luminance < THRESHOLD

    fun isBlack(argb: Int): Boolean =
        isBlackLuminance(luminance((argb shr 16) and 0xFF, (argb shr 8) and 0xFF, argb and 0xFF))

    /** `pixels` صفًّا صفًّا بعرض `width` — وقد تكون أطولَ من `width × height` */
    fun fromArgb(pixels: IntArray, width: Int, height: Int): BitImage {
        require(width >= 0 && height >= 0) { "negative size" }
        require(pixels.size >= width * height) { "not enough pixels" }
        val stride = bytesPerRow(width)
        val data = ByteArray(stride * height)
        for (y in 0 until height) {
            val rowStart = y * width
            val outStart = y * stride
            for (x in 0 until width) {
                if (isBlack(pixels[rowStart + x])) {
                    val index = outStart + (x ushr 3)
                    data[index] = (data[index].toInt() or (0x80 ushr (x and 7))).toByte()
                }
            }
        }
        return BitImage(width, height, stride, data)
    }

    /** للاختبارات والمتّجهات: صفٌّ نصّيّ، '1' = أسود و'0' = أبيض */
    fun fromRows(rows: List<String>, width: Int): BitImage {
        val stride = bytesPerRow(width)
        val data = ByteArray(stride * rows.size)
        for (y in rows.indices) {
            val row = rows[y]
            require(row.length == width) { "row $y is ${row.length} wide, expected $width" }
            for (x in 0 until width) {
                when (row[x]) {
                    '1' -> {
                        val index = y * stride + (x ushr 3)
                        data[index] = (data[index].toInt() or (0x80 ushr (x and 7))).toByte()
                    }
                    '0' -> Unit
                    else -> throw IllegalArgumentException("row $y has '${row[x]}' at $x")
                }
            }
        }
        return BitImage(width, rows.size, stride, data)
    }
}
