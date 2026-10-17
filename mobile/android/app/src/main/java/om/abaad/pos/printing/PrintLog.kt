package om.abaad.pos.printing

import android.util.Log

/**
 * سجلُّ الطباعة (PROTOCOL §9) — الوسم `AbaadPrinter`.
 *
 *   - يُسجَّل: `jobId`، والطريقة، والمراحل، والمدد، والرمز، و`host:port`، وعددُ البايتات،
 *     و**مسارُ** الرابط بلا استعلام.
 *   - لا يُسجَّل أبدًا: الكوكيز، ورؤوسُ الطلب، والاستعلام (`?…`)، ومحتوى الإيصال
 *     أو بايتاته — ولا رسائلُ الاستثناءات، لأنّ بعضها يحمل الرابطَ كاملًا. اسمُ صنفها وحده.
 */
object PrintLog {
    const val TAG = "AbaadPrinter"

    fun stage(jobId: String, method: Method, stage: Stage) {
        Log.i(TAG, "job=$jobId method=${method.wire} stage=${stage.wire}")
    }

    fun download(jobId: String, url: String) {
        Log.i(TAG, "job=$jobId download path=${pathOf(url)}")
    }

    fun result(
        jobId: String,
        method: Method?,
        code: ErrorCode?,
        durationMs: Long,
        endpoint: String? = null,
        bytes: Int? = null,
        status: Int? = null,
    ) {
        val parts = StringBuilder()
        parts.append("job=").append(jobId)
        parts.append(" method=").append(method?.wire ?: "?")
        parts.append(" result=").append(code?.name ?: "OK")
        parts.append(" ms=").append(durationMs)
        if (endpoint != null) parts.append(" printer=").append(endpoint)
        if (bytes != null) parts.append(" bytes=").append(bytes)
        if (status != null && status > 0) parts.append(" http=").append(status)
        Log.i(TAG, parts.toString())
    }

    fun info(message: String) {
        Log.i(TAG, message)
    }

    fun warn(message: String, error: Throwable? = null) {
        val kind = if (error != null) " (${error.javaClass.simpleName})" else ""
        Log.w(TAG, message + kind)
    }

    /** المسارُ وحده: لا مخطّط ولا مضيف ولا `user@` ولا استعلام ولا مرساة */
    fun pathOf(url: String?): String {
        if (url.isNullOrEmpty()) return "-"
        var rest: String = url
        val cut = rest.indexOfFirst { it == '?' || it == '#' }
        if (cut >= 0) rest = rest.substring(0, cut)
        val scheme = rest.indexOf("://")
        if (scheme >= 0) {
            rest = rest.substring(scheme + 3)
            val slash = rest.indexOf('/')
            rest = if (slash >= 0) rest.substring(slash) else "/"
        }
        return if (rest.isEmpty()) "/" else rest
    }
}
