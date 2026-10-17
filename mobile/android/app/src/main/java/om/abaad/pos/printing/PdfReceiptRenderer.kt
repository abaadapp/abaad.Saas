package om.abaad.pos.printing

import android.graphics.Bitmap
import android.graphics.Color
import android.graphics.Matrix
import android.graphics.pdf.PdfRenderer
import android.os.ParcelFileDescriptor
import java.io.File
import kotlin.math.max
import kotlin.math.min
import kotlin.math.roundToInt

/**
 * PDF الإيصال ← بايتاتُ ESC/POS في ملفّ التخزين (PROTOCOL §5 الخطوتان 2 و3).
 *
 *   - كلُّ صفحةٍ بعرض `dots`، وارتفاعُها بالنسبة نفسها، والصفحاتُ موصولةٌ شريطًا واحدًا
 *     (`SeamJoiner`): بلا فراغٍ بين صفحتين ولا قصّ.
 *   - الخلفيّةُ تُملأ بالأبيض قبل الرسم: PdfRenderer يرسم على الشفاف.
 *
 * ═══ ولا حدَّ لطول الإيصال ═══
 *
 * كان الارتفاعُ الكلّيّ أقصاه 40000 صفّ (نحو خمسة أمتار ونصف على 80)، والصورةُ كلُّها
 * في الذاكرة. والآن تُرسم الصفحةُ شريطًا شريطًا (512 صفًّا) بمصفوفة إزاحة، ويُرمَّز كلُّ
 * شريطٍ ويُكتب إلى الملفّ قبل التالي: الذاكرةُ شريطٌ واحد مهما طال الإيصال.
 *
 * وما يُرفض صفحةٌ واحدةٌ أطولُ من حدّ مواصفة PDF (14400 نقطة = 200 بوصة) — والخادمُ
 * يقسم الإيصالَ الطويل صفحاتٍ لا تتجاوز مترين. وتُفحص الصفحاتُ كلُّها قبل أن يُكتب بايت.
 */
class PdfReceiptRenderer(private val cacheDir: File) {

    class RenderException(message: String, cause: Throwable? = null) : Exception(message, cause)

    /** يرسم الـPDF ويرمّزه إلى ملفٍّ في `cacheDir`. وأيُّ فشلٍ ← `RenderException` بلا ملفٍّ باقٍ */
    fun spool(pdf: ByteArray, dots: Int, onBand: () -> Unit = {}): ReceiptSpool {
        if (dots <= 0) throw RenderException("invalid width")
        // PdfRenderer يريد ملفًّا قابلًا للتنقّل — والملفُّ المؤقّت يُحذف في كلّ حال
        val source = temp(".pdf")
        val target = try {
            temp(".escpos")
        } catch (e: RenderException) {
            source.delete()
            throw e
        }
        try {
            source.writeBytes(pdf)
            render(source, dots, target, onBand)
            return ReceiptSpool(target, target.length())
        } catch (e: RenderException) {
            target.delete()
            throw e
        } catch (e: Exception) {
            target.delete()
            throw RenderException("render failed", e)
        } catch (e: OutOfMemoryError) {
            target.delete()
            throw RenderException("out of memory", e)
        } finally {
            source.delete()
        }
    }

    private fun temp(suffix: String): File =
        try {
            File.createTempFile("receipt-", suffix, cacheDir)
        } catch (e: Exception) {
            throw RenderException("no temp file", e)
        }

    private fun render(source: File, dots: Int, target: File, onBand: () -> Unit) {
        val descriptor = ParcelFileDescriptor.open(source, ParcelFileDescriptor.MODE_READ_ONLY)
        val renderer = try {
            PdfRenderer(descriptor)
        } catch (e: Exception) {
            descriptor.close()
            throw e
        }
        // PdfRenderer يملك الواصفَ بعد إنشائه، ويغلقه معه
        try {
            PdfRasterDocument(renderer, dots).use { document ->
                target.outputStream().buffered(64 * 1024).use { out ->
                    ReceiptRaster.encode(document, EscPosStream(out, MonochromeRaster.bytesPerRow(dots)), onBand = onBand)
                }
            }
        } finally {
            renderer.close()
        }
    }

    /** صفحاتُ PdfRenderer مفحوصةً كلُّها عند الإنشاء — ولوحُ شريطٍ واحد يتقاسمه الجميع */
    private class PdfRasterDocument(private val renderer: PdfRenderer, private val dots: Int) : RasterDocument, java.io.Closeable {
        private val heights: IntArray
        private val strip: Bitmap
        private val pixels: IntArray

        init {
            val count = renderer.pageCount
            if (count <= 0) throw RenderException("no pages")
            heights = IntArray(count) { index ->
                val page = renderer.openPage(index)
                try {
                    rowsOf(page.width, page.height, dots)
                } finally {
                    page.close()
                }
            }
            strip = Bitmap.createBitmap(dots, ReceiptRaster.BAND_ROWS, Bitmap.Config.ARGB_8888)
            pixels = IntArray(dots * ReceiptRaster.BAND_ROWS)
        }

        override val pageCount: Int get() = heights.size

        override fun open(index: Int): RasterPage {
            val page = renderer.openPage(index)
            val height = heights[index]
            val scaleX = dots.toFloat() / page.width
            val scaleY = height.toFloat() / page.height
            return object : RasterPage {
                override val rows: Int get() = height

                override fun render(top: Int, count: Int): BitImage {
                    val band = min(count, ReceiptRaster.BAND_ROWS)
                    val matrix = Matrix()
                    matrix.setScale(scaleX, scaleY)
                    matrix.postTranslate(0f, -top.toFloat())
                    strip.eraseColor(Color.WHITE)
                    try {
                        page.render(strip, null, matrix, PdfRenderer.Page.RENDER_MODE_FOR_PRINT)
                    } catch (e: RuntimeException) {
                        strip.eraseColor(Color.WHITE)
                        page.render(strip, null, matrix, PdfRenderer.Page.RENDER_MODE_FOR_DISPLAY)
                    }
                    strip.getPixels(pixels, 0, dots, 0, 0, dots, band)
                    return MonochromeRaster.fromArgb(pixels, dots, band)
                }

                override fun close() {
                    page.close()
                }
            }
        }

        override fun close() {
            strip.recycle()
        }
    }

    companion object {
        /** حدُّ مواصفة PDF لبُعد الصفحة: 14400 نقطة (200 بوصة) */
        const val MAX_PAGE_POINTS = 14_400

        /** صفوفُ صفحةٍ بأبعادها بالنقاط — أو `RenderException` لصفحةٍ لا تكون */
        fun rowsOf(widthPoints: Int, heightPoints: Int, dots: Int): Int {
            if (widthPoints <= 0 || heightPoints <= 0) throw RenderException("empty page")
            if (heightPoints > MAX_PAGE_POINTS || widthPoints > MAX_PAGE_POINTS) throw RenderException("page beyond the PDF limit")
            return max(1, (heightPoints.toDouble() * dots / widthPoints).roundToInt())
        }
    }
}
