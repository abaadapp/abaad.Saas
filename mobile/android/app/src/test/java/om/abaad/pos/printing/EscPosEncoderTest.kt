package om.abaad.pos.printing

import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test

class EscPosEncoderTest {

    @Test
    fun everyEscposVectorMatchesByteForByte() {
        val cases = Fixtures.vectors.getJSONObject("escpos").getJSONArray("cases")
        assertTrue("no escpos vectors", cases.length() > 0)
        for (i in 0 until cases.length()) {
            val vector = cases.getJSONObject(i)
            val name = vector.getString("name")
            val width = vector.getInt("width")
            val rowsJson = vector.getJSONArray("rows")
            val rows = ArrayList<String>()
            for (r in 0 until rowsJson.length()) rows.add(rowsJson.getString(r))
            val o = vector.getJSONObject("options")
            val options = EscPosEncoder.Options(
                bandHeight = o.getInt("bandHeight"),
                feedLines = o.getInt("feedLines"),
                cut = o.getBoolean("cut"),
                trimTrailingBlank = o.getBoolean("trimTrailingBlank"),
            )

            val bytes = EscPosEncoder.encode(MonochromeRaster.fromRows(rows, width), options)

            assertEquals(name, vector.getString("hex"), Fixtures.hex(bytes))
        }
    }

    @Test
    fun receiptDefaultsAreTheProtocolDefaults() {
        val options = EscPosEncoder.Options()
        assertEquals(128, options.bandHeight)
        assertEquals(4, options.feedLines)
        assertTrue(options.cut)
        assertTrue(options.trimTrailingBlank)
    }

    @Test
    fun thresholdAndLuminanceVectors() {
        val t = Fixtures.vectors.getJSONObject("threshold")
        assertEquals(t.getInt("threshold"), MonochromeRaster.THRESHOLD)

        val luminance = t.getJSONArray("luminance")
        val luminanceBlack = t.getJSONArray("luminanceBlack")
        for (i in 0 until luminance.length()) {
            val value = luminance.getInt(i)
            assertEquals("luminance $value", luminanceBlack.getInt(i) == 1, MonochromeRaster.isBlackLuminance(value))
        }

        val rgb = t.getJSONArray("rgb")
        val rgbLuminance = t.getJSONArray("rgbLuminance")
        val rgbBlack = t.getJSONArray("rgbBlack")
        for (i in 0 until rgb.length()) {
            val color = rgb.getJSONArray(i)
            val r = color.getInt(0)
            val g = color.getInt(1)
            val b = color.getInt(2)
            val label = "rgb($r,$g,$b)"
            assertEquals(label, rgbLuminance.getInt(i), MonochromeRaster.luminance(r, g, b))

            val black = rgbBlack.getInt(i) == 1
            val argb = (0xFF shl 24) or (r shl 16) or (g shl 8) or b
            assertEquals(label, black, MonochromeRaster.isBlack(argb))
            // والطريقُ نفسه الذي يسلكه الرسم: بكسل ARGB ← بتّ
            assertEquals(label, black, MonochromeRaster.fromArgb(intArrayOf(argb), 1, 1).isBlack(0, 0))
        }
    }

    @Test
    fun argbRowsPackMostSignificantBitFirstWithWhitePadding() {
        val black = 0xFF000000.toInt()
        val white = 0xFFFFFFFF.toInt()
        // عرض 10: البكسل 0 و9 أسودان ← 80 40
        val pixels = IntArray(10) { if (it == 0 || it == 9) black else white }
        val image = MonochromeRaster.fromArgb(pixels, 10, 1)
        assertEquals(2, image.bytesPerRow)
        assertEquals("8040", Fixtures.hex(image.data))
    }

    @Test
    fun paperVectors() {
        val paper = Fixtures.vectors.getJSONObject("paper")
        assertEquals(paper.getInt("default"), PaperProfile.DEFAULT_DOTS)
        val cases = paper.getJSONArray("cases")
        for (i in 0 until cases.length()) {
            val vector = cases.getJSONArray(i)
            val input = Fixtures.plain(vector.get(0))
            assertEquals("paperWidth $input", vector.getInt(1), PaperProfile.dotsFor(input))
        }
        assertEquals(58, PaperProfile.mmForDots(384))
        assertEquals(80, PaperProfile.mmForDots(576))
    }
}
