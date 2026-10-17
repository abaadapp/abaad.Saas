package om.abaad.pos.printing

import java.io.ByteArrayOutputStream
import java.io.IOException
import java.net.HttpURLConnection
import java.net.URL

/**
 * تنزيلُ الإيصال بجلسة الـWebView (PROTOCOL §5 الخطوة 1). يجري على خيط العمل وحده.
 *
 *   - `Accept: application/pdf`، ومهلة 20 ثانية، وسقف 10MB، و**بلا اتّباع للتحويلات**:
 *     تحويلٌ يعني أنّ الجلسة انتهت (صفحةُ الدخول) أو أنّ الرابطَ يقود إلى أصلٍ آخر.
 *   - 401 أو 419 أو 3xx ← `SESSION_EXPIRED`.
 *   - أيُّ رمزٍ آخر غير 2xx، أو جسمٌ لا يبدأ بـ`%PDF` ← `RECEIPT_DOWNLOAD_FAILED`.
 *
 * والكوكيز تُقرأ عند التنزيل من `cookies` ولا تُسجَّل.
 */
class ReceiptDownloader(
    private val timeoutMs: Int = TIMEOUT_MS,
    private val maxBytes: Int = 10 * 1024 * 1024,
) {

    sealed class Result {
        class Ok(val bytes: ByteArray) : Result()

        /** `status` لرمز HTTP إن وُجد (للسجلّ)، و0 لفشل الشبكة */
        class Failed(val code: ErrorCode, val status: Int) : Result()
    }

    fun download(url: String, cookies: () -> String?): Result {
        var connection: HttpURLConnection? = null
        try {
            val opened = URL(url).openConnection() as? HttpURLConnection
                ?: return Result.Failed(ErrorCode.RECEIPT_DOWNLOAD_FAILED, 0)
            connection = opened
            opened.instanceFollowRedirects = false
            opened.requestMethod = "GET"
            opened.connectTimeout = timeoutMs
            opened.readTimeout = timeoutMs
            opened.useCaches = false
            opened.setRequestProperty("Accept", "application/pdf")
            val cookie = cookies()
            if (!cookie.isNullOrBlank()) opened.setRequestProperty("Cookie", cookie)

            val status = opened.responseCode
            if (status == 401 || status == 419 || status in 300..399) {
                return Result.Failed(ErrorCode.SESSION_EXPIRED, status)
            }
            if (status !in 200..299) return Result.Failed(ErrorCode.RECEIPT_DOWNLOAD_FAILED, status)

            val declared = opened.contentLengthLong
            if (declared > maxBytes) return Result.Failed(ErrorCode.RECEIPT_DOWNLOAD_FAILED, status)

            val body = ByteArrayOutputStream(if (declared in 1..maxBytes.toLong()) declared.toInt() else 64 * 1024)
            opened.inputStream.use { input ->
                val chunk = ByteArray(16 * 1024)
                var total = 0
                while (true) {
                    val read = input.read(chunk)
                    if (read < 0) break
                    total += read
                    if (total > maxBytes) return Result.Failed(ErrorCode.RECEIPT_DOWNLOAD_FAILED, status)
                    body.write(chunk, 0, read)
                }
            }

            val bytes = body.toByteArray()
            if (!isPdf(bytes)) return Result.Failed(ErrorCode.RECEIPT_DOWNLOAD_FAILED, status)
            return Result.Ok(bytes)
        } catch (e: IOException) {
            return Result.Failed(ErrorCode.RECEIPT_DOWNLOAD_FAILED, 0)
        } catch (e: RuntimeException) {
            // رابطٌ مشوَّه، أو حظرُ النصّ الصريح في سياسة الشبكة
            return Result.Failed(ErrorCode.RECEIPT_DOWNLOAD_FAILED, 0)
        } finally {
            connection?.disconnect()
        }
    }

    companion object {
        /** للاتّصال ولكلّ قراءة — والصفحةُ لا تسمع شيئًا أثناءه، فهو أقصرُ من انتظارها */
        const val TIMEOUT_MS = 20_000

        fun isPdf(bytes: ByteArray): Boolean =
            bytes.size >= 4 &&
                bytes[0] == '%'.code.toByte() &&
                bytes[1] == 'P'.code.toByte() &&
                bytes[2] == 'D'.code.toByte() &&
                bytes[3] == 'F'.code.toByte()
    }
}
