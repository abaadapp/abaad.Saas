package om.abaad.pos.printing

import org.json.JSONObject
import java.io.File

/**
 * المتّجهاتُ المشتركة بين Android وiOS: `mobile/shared/fixtures/printing-vectors.json`.
 * اختباراتُ Gradle تعمل من `mobile/android/app`، ومن غيره يُصعد حتى يوجد `mobile/shared`.
 */
object Fixtures {

    val vectors: JSONObject by lazy {
        JSONObject(locate("fixtures/printing-vectors.json").readText(Charsets.UTF_8))
    }

    fun locate(sharedRelative: String): File {
        val direct = File("../../shared/$sharedRelative")
        if (direct.isFile) return direct
        var dir: File? = File("").absoluteFile
        while (dir != null) {
            val candidate = File(dir, "mobile/shared/$sharedRelative")
            if (candidate.isFile) return candidate
            dir = dir.parentFile
        }
        throw AssertionError("mobile/shared/$sharedRelative not found above ${File("").absolutePath}")
    }

    /** null الـJSON يصل null — كما يمرّره `JobValidator` */
    fun plain(value: Any?): Any? = if (value == null || JSONObject.NULL == value) null else value

    fun hex(bytes: ByteArray): String {
        val out = StringBuilder(bytes.size * 2)
        for (b in bytes) {
            val v = b.toInt() and 0xFF
            out.append(HEX[v ushr 4]).append(HEX[v and 0x0F])
        }
        return out.toString()
    }

    private const val HEX = "0123456789abcdef"
}
