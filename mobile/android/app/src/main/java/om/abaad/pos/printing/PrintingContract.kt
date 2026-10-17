package om.abaad.pos.printing

import org.json.JSONObject

/**
 * عقدُ الجسر كما في `mobile/PROTOCOL.md` — الرموزُ ورسائلُها والمراحلُ وشكلُ الردّ.
 *
 * الرسائلُ العربيّة هنا حرفيًّا كما في جدول §8: الصفحةُ تعرضها كما هي.
 */
enum class ErrorCode(val message: String) {
    UNTRUSTED_ORIGIN("طلب طباعة مرفوض"),
    INVALID_JOB("طلب طباعة مرفوض"),
    RECEIPT_URL_REJECTED("طلب طباعة مرفوض"),
    UNSUPPORTED_CONNECTION("الطباعة المباشرة تدعم طابعات الشبكة فقط حاليًا"),
    INVALID_PRINTER_ADDRESS("عنوان الطابعة غير صالح — يجب أن يكون عنوانًا على الشبكة المحلية"),
    INVALID_PORT("رقم المنفذ غير صالح"),
    SESSION_EXPIRED("انتهت الجلسة — سجّل الدخول من جديد"),
    RECEIPT_DOWNLOAD_FAILED("تعذر تنزيل الإيصال"),
    RECEIPT_RENDER_FAILED("تعذر تجهيز الإيصال للطباعة"),
    PRINTER_UNREACHABLE("تعذر الوصول إلى عنوان الطابعة"),
    PORT_REFUSED("المنفذ لا يستجيب"),
    CONNECT_TIMEOUT("انتهت مهلة الاتصال"),
    SEND_FAILED("انقطع الاتصال أثناء الإرسال — تحقق من الورق قبل إعادة الطباعة"),
    QUEUE_FULL("الطابعة مشغولة — حاول بعد لحظات"),
    INTERNAL("تعذرت الطباعة"),
}

/** مراحلُ التقدّم بأسمائها على السلك */
enum class Stage(val wire: String) {
    DOWNLOADING_RECEIPT("downloading_receipt"),
    RENDERING("rendering"),
    CONNECTING("connecting"),
    SENDING("sending"),
}

enum class Method(val wire: String) {
    PRINT_RECEIPT("printReceipt"),
    TEST_PRINTER("testPrinter"),
    OPEN_RECEIPT("openReceipt"),
    ;

    companion object {
        fun fromWire(value: String?): Method? = Method.values().firstOrNull { it.wire == value }
    }
}

/**
 * `BridgeResult` — نجاحٌ أو فشل. لا يُبنى إلّا من `success` أو `failure`،
 * فلا يخرج فشلٌ بلا رمز ولا رسالة.
 */
class BridgeResult private constructor(
    val ok: Boolean,
    val jobId: String?,
    val code: ErrorCode?,
    val printed: Boolean?,
    val connected: Boolean?,
    val mayHavePrinted: Boolean?,
    val effectivePort: Int?,
) {
    fun toJson(): JSONObject {
        val json = JSONObject()
        json.put("ok", ok)
        if (jobId != null) json.put("jobId", jobId)
        code?.let {
            json.put("code", it.name)
            json.put("message", it.message)
        }
        printed?.let { json.put("printed", it) }
        connected?.let { json.put("connected", it) }
        mayHavePrinted?.let { json.put("mayHavePrinted", it) }
        effectivePort?.let { json.put("effectivePort", it) }
        return json
    }

    companion object {
        fun success(
            jobId: String,
            printed: Boolean? = null,
            connected: Boolean? = null,
            effectivePort: Int? = null,
        ): BridgeResult = BridgeResult(true, jobId, null, printed, connected, null, effectivePort)

        fun failure(
            jobId: String?,
            code: ErrorCode,
            connected: Boolean = false,
            mayHavePrinted: Boolean = false,
            effectivePort: Int? = null,
        ): BridgeResult = BridgeResult(false, jobId, code, null, connected, mayHavePrinted, effectivePort)
    }
}

/** غلافا الردّ على السلك: تقدّمٌ، ثم نتيجةٌ واحدة لكلّ `id` */
object BridgeMessages {
    fun progress(id: String, stage: Stage): String =
        JSONObject()
            .put("id", id)
            .put("type", "progress")
            .put("stage", stage.wire)
            .toString()

    fun result(id: String, result: BridgeResult): String =
        JSONObject()
            .put("id", id)
            .put("type", "result")
            .put("result", result.toJson())
            .toString()
}
