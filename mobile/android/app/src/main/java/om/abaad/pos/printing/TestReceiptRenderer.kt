package om.abaad.pos.printing

import android.graphics.Bitmap
import android.graphics.Canvas
import android.graphics.Color
import android.graphics.Paint
import android.graphics.Typeface
import android.text.Layout
import android.text.StaticLayout
import android.text.TextDirectionHeuristics
import android.text.TextPaint
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale

/**
 * صفحةُ الاختبار (PROTOCOL §6) — يرسمها التطبيقُ بنفسه بخطّ النظام وباتّجاه
 * RTL على عرض الطباعة، ثم تمرّ بالعتبة والترميز كالإيصال تمامًا.
 *
 *               أبعاد
 *           اختبار الطابعة
 *   اسم الجهاز: <deviceName>
 *   الطابعة: <printerName>
 *   IP: <address>
 *   Port: <effectivePort>
 *   الورق: 80mm (576 نقطة)
 *   التاريخ والوقت: yyyy-MM-dd HH:mm:ss   ← وقت الجهاز
 *   إن قرأت هذا فالطابعة جاهزة.
 */
object TestReceiptRenderer {

    fun render(
        dots: Int,
        deviceName: String?,
        printerName: String?,
        address: String,
        port: Int,
        now: Date = Date(),
    ): BitImage {
        val margin = maxOf(4, dots / 48)
        val width = dots - margin * 2

        val title = paint(dots / 9f, bold = true)
        val subtitle = paint(dots / 15f, bold = true)
        val body = paint(dots / 19f, bold = false)

        val time = SimpleDateFormat("yyyy-MM-dd HH:mm:ss", Locale.US).format(now)
        val mm = PaperProfile.mmForDots(dots)

        val blocks = listOf(
            layout("أبعاد", title, width, Layout.Alignment.ALIGN_CENTER),
            layout("اختبار الطابعة", subtitle, width, Layout.Alignment.ALIGN_CENTER),
            layout("اسم الجهاز: ${orDash(deviceName)}", body, width, Layout.Alignment.ALIGN_NORMAL),
            layout("الطابعة: ${orDash(printerName)}", body, width, Layout.Alignment.ALIGN_NORMAL),
            layout("IP: $address", body, width, Layout.Alignment.ALIGN_NORMAL),
            layout("Port: $port", body, width, Layout.Alignment.ALIGN_NORMAL),
            layout("الورق: ${mm}mm ($dots نقطة)", body, width, Layout.Alignment.ALIGN_NORMAL),
            layout("التاريخ والوقت: $time", body, width, Layout.Alignment.ALIGN_NORMAL),
            layout("إن قرأت هذا فالطابعة جاهزة.", body, width, Layout.Alignment.ALIGN_NORMAL),
        )

        val gap = maxOf(2, (body.textSize * 0.35f).toInt())
        var height = margin * 2
        for (block in blocks) height += block.height
        height += gap * (blocks.size - 1)

        val bitmap = Bitmap.createBitmap(dots, height, Bitmap.Config.ARGB_8888)
        try {
            bitmap.eraseColor(Color.WHITE)
            val canvas = Canvas(bitmap)
            var y = margin.toFloat()
            for (block in blocks) {
                canvas.save()
                canvas.translate(margin.toFloat(), y)
                block.draw(canvas)
                canvas.restore()
                y += block.height + gap
            }
            val pixels = IntArray(dots * height)
            bitmap.getPixels(pixels, 0, dots, 0, 0, dots, height)
            return MonochromeRaster.fromArgb(pixels, dots, height)
        } finally {
            bitmap.recycle()
        }
    }

    private fun orDash(value: String?): String = if (value.isNullOrBlank()) "—" else value

    private fun paint(size: Float, bold: Boolean): TextPaint {
        val paint = TextPaint(Paint.ANTI_ALIAS_FLAG)
        paint.color = Color.BLACK
        paint.textSize = size
        paint.typeface = if (bold) Typeface.DEFAULT_BOLD else Typeface.DEFAULT
        return paint
    }

    private fun layout(text: String, paint: TextPaint, width: Int, alignment: Layout.Alignment): StaticLayout =
        StaticLayout.Builder.obtain(text, 0, text.length, paint, width)
            .setAlignment(alignment)
            .setTextDirection(TextDirectionHeuristics.RTL)
            .setIncludePad(false)
            .build()
}
