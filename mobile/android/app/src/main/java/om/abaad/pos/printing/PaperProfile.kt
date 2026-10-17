package om.abaad.pos.printing

/**
 * عرضُ الورق (مم) ← عرضُ الطباعة بالنقاط على 203 نقطة/بوصة. الجدولُ الوحيد في
 * Android (PROTOCOL §4.6): 58 ← 384، و80 ← 576، وأيُّ قيمةٍ أخرى ← 576.
 */
object PaperProfile {
    const val DEFAULT_MM = 80
    const val DEFAULT_DOTS = 576

    private val DOTS_BY_MM: Map<Int, Int> = mapOf(
        58 to 384,
        80 to 576,
    )

    fun dotsForMm(mm: Int?): Int {
        if (mm == null) return DEFAULT_DOTS
        return DOTS_BY_MM[mm] ?: DEFAULT_DOTS
    }

    /** القيمةُ كما جاءت من JSON — عددٌ أو null أو غيرهما */
    fun dotsFor(paperWidth: Any?): Int = dotsForMm(JsonNumbers.integerOrNull(paperWidth))

    /** للسطر «الورق: 80mm (576 نقطة)» في صفحة الاختبار */
    fun mmForDots(dots: Int): Int =
        DOTS_BY_MM.entries.firstOrNull { it.value == dots }?.key ?: DEFAULT_MM
}
