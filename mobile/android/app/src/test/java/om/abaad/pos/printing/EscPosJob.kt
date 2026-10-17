package om.abaad.pos.printing

/**
 * مهمّةُ ESC/POS كما تقرؤها الطابعة (PROTOCOL §7): تهيئة، ثمّ صور، ثمّ تغذيةٌ وقصّ.
 * فيُعدّ القصُّ أمرًا لا نمطَ بايتات — صورةٌ قد تحوي 1D 56 42 00 في بياناتها.
 */
class EscPosJob(val bytesPerRow: Int, val rows: List<ByteArray>, val commands: List<String>) {
    val cuts: Int get() = commands.count { it == "cut" }

    companion object {
        fun parse(bytes: ByteArray): EscPosJob {
            var i = 0
            var width = 0
            val rows = ArrayList<ByteArray>()
            val commands = ArrayList<String>()
            fun u(k: Int): Int = bytes[k].toInt() and 0xFF
            fun need(n: Int) {
                if (i + n > bytes.size) throw AssertionError("truncated command at $i")
            }
            while (i < bytes.size) {
                need(2)
                when {
                    u(i) == 0x1B && u(i + 1) == 0x40 -> {
                        commands.add("init")
                        i += 2
                    }
                    u(i) == 0x1B && u(i + 1) == 0x64 -> {
                        need(3)
                        commands.add("feed ${u(i + 2)}")
                        i += 3
                    }
                    u(i) == 0x1D && u(i + 1) == 0x56 -> {
                        need(4)
                        if (u(i + 2) != 0x42 || u(i + 3) != 0) throw AssertionError("unknown cut at $i")
                        commands.add("cut")
                        i += 4
                    }
                    u(i) == 0x1D && u(i + 1) == 0x76 -> {
                        need(8)
                        if (u(i + 2) != 0x30 || u(i + 3) != 0) throw AssertionError("unknown image at $i")
                        val x = u(i + 4) or (u(i + 5) shl 8)
                        val y = u(i + 6) or (u(i + 7) shl 8)
                        i += 8
                        need(x * y)
                        if (width == 0) width = x
                        if (x != width) throw AssertionError("image width changed at $i")
                        for (r in 0 until y) rows.add(bytes.copyOfRange(i + r * x, i + (r + 1) * x))
                        i += x * y
                        commands.add("image")
                    }
                    else -> throw AssertionError("unknown command at $i")
                }
            }
            return EscPosJob(width, rows, commands)
        }
    }
}
