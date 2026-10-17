package om.abaad.pos.printing

import org.junit.Assert.assertArrayEquals
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test
import java.io.ByteArrayOutputStream

/**
 * الإيصالُ الطويل صفحاتٍ موصولةً شريطًا واحدًا (PROTOCOL §5 و§7) — بلا PdfRenderer.
 *
 * صفحاتٌ مصنوعة كما يرسمها الخادمُ للإيصال الطويل: هامشٌ أبيض أعلاها، ثمّ صفوفُ
 * المحتوى، ثمّ فراغٌ آخرَها (ما بقي تحت آخر صنفٍ اتّسعت له). وكلُّ صفّ محتوى يحمل
 * رقمَ صفحته ورقمَه في أوّل بايتاته — فيُقرأ من الورق أيُّ صفٍّ خرج، وبأيّ ترتيب.
 */
class ReceiptRasterTest {

    private class FakePage(
        private val page: Int,
        private val dots: Int,
        private val margin: Int,
        private val content: Int,
        private val tail: Int,
        private val requested: MutableList<Int>,
    ) : RasterPage {
        override val rows: Int get() = margin + content + tail

        override fun render(top: Int, count: Int): BitImage {
            requested.add(count)
            val stride = MonochromeRaster.bytesPerRow(dots)
            val data = ByteArray(stride * count)
            for (r in 0 until count) {
                val index = top + r - margin
                if (index in 0 until content) stamp(data, r * stride, page, index)
            }
            return BitImage(dots, count, stride, data)
        }

        override fun close() {}
    }

    private class FakeDocument(private val pages: List<(MutableList<Int>) -> FakePage>) : RasterDocument {
        val opened = ArrayList<Int>()
        val requested = ArrayList<Int>()

        override val pageCount: Int get() = pages.size

        override fun open(index: Int): RasterPage {
            opened.add(index)
            return pages[index](requested)
        }
    }

    private fun document(dots: Int, pages: Int, margin: Int, content: Int, tail: Int): FakeDocument =
        FakeDocument((0 until pages).map { page -> { requested: MutableList<Int> -> FakePage(page, dots, margin, content, tail, requested) } })

    private fun encode(document: RasterDocument, dots: Int): EscPosJob {
        val out = ByteArrayOutputStream()
        ReceiptRaster.encode(document, EscPosStream(out, MonochromeRaster.bytesPerRow(dots)))
        return EscPosJob.parse(out.toByteArray())
    }

    /*
     * أربعُ صفحاتٍ في كلٍّ 15000 صفّ محتوى: 60000 صفٍّ على الورق — والحدُّ القديم 40000.
     * فيخرج كلُّه: هامشُ الأولى، ثمّ صفوفُ الصفحات بترتيبها بلا صفٍّ ناقص، وبين كلّ صفحتين
     * فراغُ الوصلة مطويًّا إلى 16 صفًّا، ولا فراغَ في الآخر، وقصٌّ واحدٌ أخيرًا.
     */
    private fun printsWhole(dots: Int) {
        val margin = 24
        val content = 15_000
        val tail = 300
        val doc = document(dots, pages = 4, margin = margin, content = content, tail = tail)
        val job = encode(doc, dots)

        assertEquals(listOf(0, 1, 2, 3), doc.opened)
        assertEquals(MonochromeRaster.bytesPerRow(dots), job.bytesPerRow)
        assertEquals(listOf("init"), job.commands.filter { it == "init" })
        assertEquals(1, job.cuts)
        assertEquals(listOf("feed 4", "cut"), job.commands.takeLast(2))
        assertTrue("rendered band by band", doc.requested.all { it <= ReceiptRaster.BAND_ROWS })

        val seam = SeamJoiner.MAX_SEAM_BLANK_ROWS
        assertEquals(margin + 4 * content + 3 * seam, job.rows.size)
        assertTrue(job.rows.size > 40_000)

        var row = 0
        repeat(margin) { assertTrue("top margin row $row", blank(job.rows[row++])) }
        for (page in 0 until 4) {
            if (page > 0) repeat(seam) { assertTrue("seam before page $page", blank(job.rows[row++])) }
            for (index in 0 until content) {
                val printed = job.rows[row++]
                assertEquals("page of row $row", page, printed[0].toInt() and 0x7F)
                assertEquals("index of row $row", index, decode(printed))
            }
        }
        assertEquals(job.rows.size, row)
    }

    @Test(timeout = 60_000)
    fun aReceiptLongerThanTheOldLimitPrintsWholeOn80mm() = printsWhole(576)

    @Test(timeout = 60_000)
    fun aReceiptLongerThanTheOldLimitPrintsWholeOn58mm() = printsWhole(384)

    /*
     * وإيصالٌ بصفحةٍ واحدة يخرج كما كان بايتًا ببايت: المرمِّزُ قبل التيّار (منسوخًا هنا)
     * على الصورة نفسِها كاملةً، ثمّ صفوفُها البيضاء الأخيرة محذوفة.
     */
    @Test
    fun aOnePageReceiptIsByteForByteWhatItWasBefore() {
        val dots = 576
        val doc = document(dots, pages = 1, margin = 30, content = 700, tail = 90)
        val out = ByteArrayOutputStream()
        ReceiptRaster.encode(doc, EscPosStream(out, MonochromeRaster.bytesPerRow(dots)))

        val whole = FakePage(0, dots, 30, 700, 90, ArrayList()).render(0, 820)
        assertArrayEquals(before(whole), out.toByteArray())
    }

    /** المرمِّزُ كما كان قبل أن يصير تيّارًا — صورةٌ كاملة، حذفُ الأبيض من الآخر، ثمّ أشرطة */
    private fun before(image: BitImage): ByteArray {
        var height = image.height
        while (height > 0 && image.isRowBlank(height - 1)) height--
        val out = ByteArrayOutputStream()
        out.write(0x1B)
        out.write(0x40)
        var top = 0
        while (top < height) {
            val rows = minOf(128, height - top)
            out.write(byteArrayOf(0x1D, 0x76, 0x30, 0x00, image.bytesPerRow.toByte(), (image.bytesPerRow shr 8).toByte(), rows.toByte(), (rows shr 8).toByte()))
            out.write(image.data, top * image.bytesPerRow, rows * image.bytesPerRow)
            top += rows
        }
        out.write(byteArrayOf(0x1B, 0x64, 4, 0x1D, 0x56, 0x42, 0x00))
        return out.toByteArray()
    }

    companion object {
        /** البتُّ الأعلى أسود دائمًا فلا يكون الصفُّ أبيض، ثمّ رقمُ الصفحة، ثمّ رقمُ الصفّ */
        fun stamp(data: ByteArray, offset: Int, page: Int, index: Int) {
            data[offset] = (0x80 or page).toByte()
            data[offset + 1] = (index shr 16).toByte()
            data[offset + 2] = (index shr 8).toByte()
            data[offset + 3] = index.toByte()
        }

        fun decode(row: ByteArray): Int =
            ((row[1].toInt() and 0xFF) shl 16) or ((row[2].toInt() and 0xFF) shl 8) or (row[3].toInt() and 0xFF)

        fun blank(row: ByteArray): Boolean = row.all { it.toInt() == 0 }
    }
}
