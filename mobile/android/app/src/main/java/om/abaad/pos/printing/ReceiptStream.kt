package om.abaad.pos.printing

import java.io.Closeable
import java.io.OutputStream
import java.util.Arrays

/*
 * الإيصالُ تيّارًا لا صورةً واحدة — PROTOCOL §5 و§7.
 *
 * إيصالٌ بألف صنفٍ نحو ثلاثة أمتار: 21000 صفٍّ على 576 نقطة. ولو جُمع صورةً واحدةً
 * ثمّ بايتاتٍ واحدة لكان طولُه حدًّا يرفض عنده (كان 40000 صفّ)، وذاكرةً تكبر بطوله.
 * فالصفوفُ تمرّ هنا شريطًا شريطًا: `SeamJoiner` يصل صفحاتِ الـPDF، و`EscPosStream`
 * يرزمها أوامرَ `GS v 0` ويكتبها حيث يُراد (ملفُّ التخزين). ولا يبقى في الذاكرة إلّا شريط.
 */

/** يرزم الصفوفَ أوامرَ ESC/POS ويكتبها بالترتيب: تهيئة، ثمّ الصورُ، ثمّ تغذيةٌ وقصٌّ واحد */
class EscPosStream(
    private val out: OutputStream,
    val bytesPerRow: Int,
    val options: EscPosEncoder.Options = EscPosEncoder.Options(),
) {
    init {
        require(options.bandHeight in 1..0xFFFF) { "bandHeight out of range" }
        require(options.feedLines in 0..255) { "feedLines out of range" }
        require(bytesPerRow in 0..0xFFFF) { "image too wide" }
    }

    private val band = ByteArray(options.bandHeight * bytesPerRow)
    private var bandRows = 0
    private var finished = false

    /** الصفوفُ المكتوبة حتى الآن */
    var rows = 0L
        private set

    init {
        out.write(0x1B)
        out.write(0x40)
    }

    /** صفٌّ مرزوم يبدأ عند `offset` في `source` */
    fun row(source: ByteArray, offset: Int) {
        if (bytesPerRow == 0) return
        System.arraycopy(source, offset, band, bandRows * bytesPerRow, bytesPerRow)
        advance()
    }

    /** صفوفٌ بيضاء */
    fun blank(count: Int) {
        if (bytesPerRow == 0) return
        for (i in 0 until count) {
            Arrays.fill(band, bandRows * bytesPerRow, (bandRows + 1) * bytesPerRow, 0.toByte())
            advance()
        }
    }

    /** آخرُ شريط، ثمّ التغذيةُ والقصّ — مرّةً واحدةً بالضبط */
    fun finish() {
        if (finished) return
        finished = true
        flush()
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
    }

    private fun advance() {
        bandRows++
        rows++
        if (bandRows == options.bandHeight) flush()
    }

    private fun flush() {
        if (bandRows == 0) return
        out.write(0x1D)
        out.write(0x76)
        out.write(0x30)
        out.write(0x00)
        out.write(bytesPerRow and 0xFF)
        out.write((bytesPerRow shr 8) and 0xFF)
        out.write(bandRows and 0xFF)
        out.write((bandRows shr 8) and 0xFF)
        out.write(band, 0, bandRows * bytesPerRow)
        bandRows = 0
    }
}

/**
 * وصلُ صفحات الـPDF شريطًا واحدًا على الورق.
 *
 * الخادمُ يقسم الإيصالَ الطويل صفحاتٍ لا تتجاوز مترين (`MpdfDriver::strip`). ولكلّ
 * صفحةٍ هامشاها، وفي آخرها ما بقي تحت آخر صنفٍ اتّسعت له. فلو كُدّست كما هي لخرج بين
 * كلّ صفحتين فراغٌ يبلغ سنتيمترات — والطابعةُ لا تعرف الصفحات.
 *
 *   - الفراغُ الذي يلمس وصلةً (آخرُ صفحةٍ وأوّلُ التالية) يُطوى إلى `maxSeamBlank` صفًّا.
 *   - والفراغُ داخل الصفحة يبقى كما هو: هو تباعدُ السطور الذي رسمه القالب.
 *   - وفراغُ أوّل الصفحة الأولى يبقى (هامشُ الرأس)، وفراغُ الآخر يُحذف (§7 `trimTrailingBlank`).
 *
 * والصفُّ الأبيض لا يحمل شيئًا من الإيصال، فطيُّه لا يُسقط سطرًا أبدًا.
 */
class SeamJoiner(
    private val sink: EscPosStream,
    maxSeamBlank: Int = MAX_SEAM_BLANK_ROWS,
    private val keepTrailingBlank: Boolean = false,
) {
    private val maxSeamBlank = maxOf(0, maxSeamBlank)
    private var pendingBlank = 0
    private var atSeam = false
    private var pages = 0

    fun beginPage() {
        if (pages > 0) atSeam = true
        pages++
    }

    /** صفوفُ الصفحة الحاليّة بالترتيب — شريطًا بعد شريط */
    fun append(image: BitImage) {
        require(image.bytesPerRow == sink.bytesPerRow) { "band width mismatch" }
        if (image.bytesPerRow == 0) return
        for (y in 0 until image.height) {
            if (image.isRowBlank(y)) {
                pendingBlank++
            } else {
                flushBlank()
                sink.row(image.data, y * image.bytesPerRow)
            }
        }
    }

    fun finish() {
        if (keepTrailingBlank) flushBlank()
        pendingBlank = 0
        sink.finish()
    }

    private fun flushBlank() {
        val rows = if (atSeam) minOf(pendingBlank, maxSeamBlank) else pendingBlank
        pendingBlank = 0
        atSeam = false
        sink.blank(rows)
    }

    companion object {
        /** نحو مليمترين على ورق 80 — قريبٌ من تباعد صنفين متتاليين */
        const val MAX_SEAM_BLANK_ROWS = 16
    }
}

/** صفحاتُ الإيصال بعرض الطباعة — PdfRenderer في التطبيق، وصفحاتٌ مصنوعة في الاختبار */
interface RasterDocument {
    val pageCount: Int

    /** الصفحةُ مفتوحةً، ويُغلقها المستدعي */
    fun open(index: Int): RasterPage
}

interface RasterPage : Closeable {
    /** ارتفاعُ الصفحة بالصفوف على عرض الطباعة */
    val rows: Int

    /** الصفوفُ [top, top + count) أحاديّةَ اللون */
    fun render(top: Int, count: Int): BitImage
}

object ReceiptRaster {
    /** صفوفُ الشريط الواحد: 576 × 512 × 4 بايت ≈ 1.2MB، مهما طال الإيصال */
    const val BAND_ROWS = 512

    /**
     * الإيصالُ كلُّه إلى `stream`: صفحةً صفحة، وكلُّ صفحةٍ شرائط، والصفحاتُ موصولة.
     * و`onBand` بعد كلّ شريط — منه تخرج نبضاتُ «rendering» إلى الصفحة.
     */
    fun encode(document: RasterDocument, stream: EscPosStream, bandRows: Int = BAND_ROWS, onBand: () -> Unit = {}) {
        require(bandRows > 0) { "bandRows must be positive" }
        val joiner = SeamJoiner(stream, keepTrailingBlank = !stream.options.trimTrailingBlank)
        for (index in 0 until document.pageCount) {
            document.open(index).use { page ->
                joiner.beginPage()
                var top = 0
                while (top < page.rows) {
                    val count = minOf(bandRows, page.rows - top)
                    joiner.append(page.render(top, count))
                    onBand()
                    top += count
                }
            }
        }
        joiner.finish()
    }
}
