package om.abaad.pos.printing

import org.json.JSONException
import org.json.JSONObject

/** طلبٌ اجتاز الفحصَ كلَّه — وما بعده لا يقرأ من الرسالة شيئًا */
sealed class ValidatedJob {
    abstract val method: Method

    class PrintReceipt(val invoice: String, val receiptUrl: String, val printer: ValidatedPrinter) : ValidatedJob() {
        override val method: Method get() = Method.PRINT_RECEIPT
    }

    class TestPrinter(val printer: ValidatedPrinter, val deviceName: String?, val printerName: String?) : ValidatedJob() {
        override val method: Method get() = Method.TEST_PRINTER
    }

    class OpenReceipt(val invoice: String, val receiptUrl: String) : ValidatedJob() {
        override val method: Method get() = Method.OPEN_RECEIPT
    }
}

/**
 * فحصُ الرسالة كلِّها قبل أن يدخل شيءٌ الطابور (PROTOCOL §3 و§4) — بالترتيب:
 *
 *   1. `id` نصٌّ من 1–64 حرفًا، وإلّا تُهمل الرسالة: لا ردَّ يُنسب إلى شيء.
 *   2. الأصل: الإطارُ رئيسيّ، وأصلُه موثوق، وأصلُ رابط الـWebView موثوق ← وإلّا `UNTRUSTED_ORIGIN`.
 *   3. `v` = 1، و`method` معروف، و`payload` كائن ← وإلّا `INVALID_JOB`.
 *   4. الحمولة: الاتّصال، ثم العنوان، ثم المنفذ، ثم رابط الإيصال، ثم عرض الورق.
 *
 * ولا شبكةَ هنا: اسمُ `.local` يُحلّ لاحقًا على خيط العمل، ويُفحص ما ينتج عنه.
 */
object JobValidator {
    const val MAX_ID_LENGTH = 64
    const val MAX_LABEL_LENGTH = 60
    const val MAX_INVOICE_LENGTH = 128

    sealed class Outcome {
        class Drop(val reason: String) : Outcome()

        class Rejected(
            val id: String,
            val method: Method?,
            val code: ErrorCode,
            val effectivePort: Int? = null,
        ) : Outcome()

        class Accepted(val id: String, val job: ValidatedJob, val pageOrigin: String) : Outcome()
    }

    fun validate(
        raw: String?,
        isMainFrame: Boolean,
        sourceOrigin: String?,
        viewUrl: String?,
        origins: TrustedOrigins,
    ): Outcome {
        if (raw == null) return Outcome.Drop("not a string message")
        val message = try {
            JSONObject(raw)
        } catch (e: JSONException) {
            return Outcome.Drop("not a JSON object")
        }

        val id = message.opt("id") as? String
        if (id == null || id.isEmpty() || id.length > MAX_ID_LENGTH) return Outcome.Drop("invalid id")
        val method = Method.fromWire(message.opt("method") as? String)

        val pageOrigin = TrustedOrigins.normalizeOrigin(sourceOrigin)
        if (!isMainFrame ||
            pageOrigin == null ||
            !origins.isTrusted(pageOrigin) ||
            !origins.isTrusted(viewUrl)
        ) {
            return Outcome.Rejected(id, method, ErrorCode.UNTRUSTED_ORIGIN)
        }

        if (JsonNumbers.integerOrNull(message.field("v")) != 1 || method == null) {
            return Outcome.Rejected(id, method, ErrorCode.INVALID_JOB)
        }
        val payload = message.opt("payload") as? JSONObject
            ?: return Outcome.Rejected(id, method, ErrorCode.INVALID_JOB)

        return when (method) {
            Method.PRINT_RECEIPT -> printReceipt(id, payload, pageOrigin, origins)
            Method.TEST_PRINTER -> testPrinter(id, payload, pageOrigin)
            Method.OPEN_RECEIPT -> openReceipt(id, payload, pageOrigin, origins)
        }
    }

    private fun printReceipt(id: String, payload: JSONObject, pageOrigin: String, origins: TrustedOrigins): Outcome {
        val method = Method.PRINT_RECEIPT
        val invoice = invoiceOf(payload) ?: return Outcome.Rejected(id, method, ErrorCode.INVALID_JOB)
        val printerJson = payload.opt("printer") as? JSONObject
            ?: return Outcome.Rejected(id, method, ErrorCode.INVALID_JOB)
        val printer = when (val check = checkPrinter(printerJson)) {
            is PrinterCheck.Valid -> check.printer
            is PrinterCheck.Invalid -> return Outcome.Rejected(id, method, check.code, check.effectivePort)
        }
        val receiptUrl = payload.opt("receiptUrl") as? String
        if (receiptUrl == null || !origins.isReceiptUrlAllowed(pageOrigin, receiptUrl)) {
            return Outcome.Rejected(id, method, ErrorCode.RECEIPT_URL_REJECTED, printer.port)
        }
        return Outcome.Accepted(id, ValidatedJob.PrintReceipt(invoice, receiptUrl, printer), pageOrigin)
    }

    private fun testPrinter(id: String, payload: JSONObject, pageOrigin: String): Outcome {
        val method = Method.TEST_PRINTER
        val printerJson = payload.opt("printer") as? JSONObject
            ?: return Outcome.Rejected(id, method, ErrorCode.INVALID_JOB)
        val printer = when (val check = checkPrinter(printerJson)) {
            is PrinterCheck.Valid -> check.printer
            is PrinterCheck.Invalid -> return Outcome.Rejected(id, method, check.code, check.effectivePort)
        }
        val job = ValidatedJob.TestPrinter(printer, labelOf(payload.opt("deviceName")), labelOf(payload.opt("printerName")))
        return Outcome.Accepted(id, job, pageOrigin)
    }

    private fun openReceipt(id: String, payload: JSONObject, pageOrigin: String, origins: TrustedOrigins): Outcome {
        val method = Method.OPEN_RECEIPT
        val invoice = invoiceOf(payload) ?: return Outcome.Rejected(id, method, ErrorCode.INVALID_JOB)
        val receiptUrl = payload.opt("receiptUrl") as? String
        if (receiptUrl == null || !origins.isReceiptUrlAllowed(pageOrigin, receiptUrl)) {
            return Outcome.Rejected(id, method, ErrorCode.RECEIPT_URL_REJECTED)
        }
        return Outcome.Accepted(id, ValidatedJob.OpenReceipt(invoice, receiptUrl), pageOrigin)
    }

    private fun checkPrinter(printer: JSONObject): PrinterCheck =
        PrinterTarget.validate(
            connection = printer.field("connection"),
            address = printer.field("address"),
            port = printer.field("port"),
            paperWidth = printer.field("paperWidth"),
        )

    private fun invoiceOf(payload: JSONObject): String? {
        val invoice = (payload.opt("invoice") as? String)?.trim() ?: return null
        return if (invoice.isNotEmpty() && invoice.length <= MAX_INVOICE_LENGTH) invoice else null
    }

    /** نصّان اختياريّان يُقصّان إلى 60 حرفًا؛ وما ليس نصًّا كأنّه لم يُرسَل */
    private fun labelOf(value: Any?): String? {
        val text = (value as? String)?.trim() ?: return null
        if (text.isEmpty()) return null
        return if (text.length > MAX_LABEL_LENGTH) text.substring(0, MAX_LABEL_LENGTH) else text
    }

    /** null الـJSON (`JSONObject.NULL`) والمفتاحُ الغائب كلاهما null هنا */
    private fun JSONObject.field(name: String): Any? {
        val value = opt(name)
        return if (value == null || JSONObject.NULL == value) null else value
    }
}
