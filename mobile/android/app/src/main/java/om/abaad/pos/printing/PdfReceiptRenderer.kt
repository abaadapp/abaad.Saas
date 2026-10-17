package om.abaad.pos.printing

import android.graphics.Bitmap
import android.graphics.Color
import android.graphics.Matrix
import android.graphics.pdf.PdfRenderer
import android.os.ParcelFileDescriptor
import java.io.ByteArrayOutputStream
import java.io.File
import kotlin.math.max
import kotlin.math.min
import kotlin.math.roundToInt

/**
 * PDF الإيصال ← `BitImage` بعرض الطباعة (PROTOCOL §5 الخطوتان 2 و3).
 *
 *   - كلُّ صفحةٍ بعرض `dots`، وارتفاعُها بالنسبة نفسها، والصفحاتُ تُكدَّس رأسيًّا.
 *   - الخلفيّةُ تُملأ بالأبيض قبل الرسم: PdfRenderer يرسم على الشفاف.
 *   - الارتفاعُ الكليّ أقصاه 40000 صفّ، وما زاد ← `RECEIPT_RENDER_FAILED`.
 *
 * ═══ والرسمُ شرائح ═══
 *
 * تُرسم الصفحةُ شريحةً شريحة (512 صفًّا) بمصفوفة إزاحة، لا في بتمابٍ واحدٍ
 * بطولها: صفحةٌ بطول 40000 صفّ تحتاج 92MB دفعةً واحدة، والشريحةُ 1.2MB.
 * والتحجيمُ هو نفسُه تحجيمُ الرسم بلا مصفوفة: (dots / عرض الصفحة) أفقيًّا،
 * و(الارتفاع المحسوب / ارتفاع الصفحة) رأسيًّا.
 */
class PdfReceiptRenderer(private val cacheDir: File) {

    class RenderException(message: String, cause: Throwable? = null) : Exception(message, cause)

    fun render(pdf: ByteArray, dots: Int): BitImage {
        if (dots <= 0) throw RenderException("invalid width")
        // PdfRenderer يريد ملفًّا قابلًا للتنقّل — والملفُّ المؤقّت يُحذف في كلّ حال
        val file = try {
            File.createTempFile("receipt-", ".pdf", cacheDir)
        } catch (e: Exception) {
            throw RenderException("no temp file", e)
        }
        try {
            file.writeBytes(pdf)
            return renderFile(file, dots)
        } catch (e: RenderException) {
            throw e
        } catch (e: Exception) {
            throw RenderException("render failed", e)
        } catch (e: OutOfMemoryError) {
            throw RenderException("out of memory", e)
        } finally {
            file.delete()
        }
    }

    private fun renderFile(file: File, dots: Int): BitImage {
        val descriptor = ParcelFileDescriptor.open(file, ParcelFileDescriptor.MODE_READ_ONLY)
        val renderer = try {
            PdfRenderer(descriptor)
        } catch (e: Exception) {
            descriptor.close()
            throw e
        }
        // PdfRenderer يملك الواصفَ بعد إنشائه، ويغلقه معه
        try {
            return renderPages(renderer, dots)
        } finally {
            renderer.close()
        }
    }

    private fun renderPages(renderer: PdfRenderer, dots: Int): BitImage {
        val pageCount = renderer.pageCount
        if (pageCount <= 0) throw RenderException("no pages")

        val stride = MonochromeRaster.bytesPerRow(dots)
        val out = ByteArrayOutputStream()
        var totalRows = 0
        val strip = Bitmap.createBitmap(dots, STRIP_ROWS, Bitmap.Config.ARGB_8888)
        val pixels = IntArray(dots * STRIP_ROWS)
        try {
            for (index in 0 until pageCount) {
                val page = renderer.openPage(index)
                try {
                    val pageWidth = page.width
                    val pageHeight = page.height
                    if (pageWidth <= 0 || pageHeight <= 0) throw RenderException("empty page")
                    val height = max(1, (pageHeight.toDouble() * dots / pageWidth).roundToInt())
                    if (totalRows + height > MAX_ROWS) throw RenderException("receipt too long")

                    val scaleX = dots.toFloat() / pageWidth
                    val scaleY = height.toFloat() / pageHeight
                    var top = 0
                    while (top < height) {
                        val rows = min(STRIP_ROWS, height - top)
                        val matrix = Matrix()
                        matrix.setScale(scaleX, scaleY)
                        matrix.postTranslate(0f, -top.toFloat())
                        renderStrip(page, strip, matrix)
                        strip.getPixels(pixels, 0, dots, 0, 0, dots, rows)
                        val band = MonochromeRaster.fromArgb(pixels, dots, rows)
                        out.write(band.data, 0, band.data.size)
                        top += rows
                    }
                    totalRows += height
                } finally {
                    page.close()
                }
            }
        } finally {
            strip.recycle()
        }
        return BitImage(dots, totalRows, stride, out.toByteArray())
    }

    private fun renderStrip(page: PdfRenderer.Page, bitmap: Bitmap, matrix: Matrix) {
        bitmap.eraseColor(Color.WHITE)
        try {
            page.render(bitmap, null, matrix, PdfRenderer.Page.RENDER_MODE_FOR_PRINT)
        } catch (e: RuntimeException) {
            bitmap.eraseColor(Color.WHITE)
            page.render(bitmap, null, matrix, PdfRenderer.Page.RENDER_MODE_FOR_DISPLAY)
        }
    }

    companion object {
        const val MAX_ROWS = 40_000
        private const val STRIP_ROWS = 512
    }
}
