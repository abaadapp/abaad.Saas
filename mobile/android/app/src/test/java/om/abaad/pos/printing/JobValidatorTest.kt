package om.abaad.pos.printing

import org.json.JSONObject
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test
import java.io.File

class JobValidatorTest {

    private val origins = TrustedOrigins.parse("https://pos.example.test")
    private val page = "https://pos.example.test"
    private val viewUrl = "https://pos.example.test/pos/sale"
    private val receiptUrl = "https://pos.example.test/pos/receipt/INV-000123/pdf?expected_business_id=3"

    private fun printer(
        address: Any = "192.168.1.50",
        port: Any = JSONObject.NULL,
        connection: Any = "network",
        paperWidth: Any = 80,
    ): JSONObject =
        JSONObject()
            .put("id", 7)
            .put("name", "طابعة الكاشير")
            .put("model", JSONObject.NULL)
            .put("connection", connection)
            .put("address", address)
            .put("port", port)
            .put("paperWidth", paperWidth)

    private fun receiptJob(target: JSONObject = printer(), url: Any = receiptUrl): JSONObject =
        JSONObject().put("invoice", "INV-000123").put("receiptUrl", url).put("printer", target)

    private fun message(method: String, payload: JSONObject, id: Any = "jabc-1", v: Any = 1): String =
        JSONObject().put("v", v).put("id", id).put("method", method).put("payload", payload).toString()

    private fun validate(
        raw: String?,
        isMainFrame: Boolean = true,
        source: String? = page,
        view: String? = viewUrl,
    ): JobValidator.Outcome = JobValidator.validate(raw, isMainFrame, source, view, origins)

    private fun rejected(outcome: JobValidator.Outcome): JobValidator.Outcome.Rejected {
        assertTrue("expected Rejected", outcome is JobValidator.Outcome.Rejected)
        return outcome as JobValidator.Outcome.Rejected
    }

    private fun accepted(outcome: JobValidator.Outcome): JobValidator.Outcome.Accepted {
        assertTrue("expected Accepted", outcome is JobValidator.Outcome.Accepted)
        return outcome as JobValidator.Outcome.Accepted
    }

    @Test
    fun aValidReceiptJobIsAcceptedWithTheEffectivePort() {
        val outcome = accepted(validate(message("printReceipt", receiptJob())))
        val job = outcome.job as ValidatedJob.PrintReceipt
        assertEquals("jabc-1", outcome.id)
        assertEquals("https://pos.example.test:443", outcome.pageOrigin)
        assertEquals("INV-000123", job.invoice)
        assertEquals(receiptUrl, job.receiptUrl)
        assertEquals(9100, job.printer.port)
        assertEquals(576, job.printer.dots)
        assertEquals(AddressKind.IPV4, job.printer.kind)
    }

    @Test
    fun originIsCheckedFirst() {
        val raw = message("printReceipt", receiptJob(printer(connection = "usb")))
        assertEquals(ErrorCode.UNTRUSTED_ORIGIN, rejected(validate(raw, isMainFrame = false)).code)
        assertEquals(ErrorCode.UNTRUSTED_ORIGIN, rejected(validate(raw, source = "https://evil.test")).code)
        assertEquals(ErrorCode.UNTRUSTED_ORIGIN, rejected(validate(raw, source = "null")).code)
        assertEquals(ErrorCode.UNTRUSTED_ORIGIN, rejected(validate(raw, view = "https://evil.test/x")).code)
        assertEquals(ErrorCode.UNTRUSTED_ORIGIN, rejected(validate(raw, view = null)).code)
    }

    @Test
    fun malformedMessagesAreInvalidOrDropped() {
        assertEquals(ErrorCode.INVALID_JOB, rejected(validate(message("printReceipt", receiptJob(), v = 2))).code)
        assertEquals(ErrorCode.INVALID_JOB, rejected(validate(message("printReceipt", receiptJob(), v = "1"))).code)
        assertEquals(ErrorCode.INVALID_JOB, rejected(validate(message("printBytes", receiptJob()))).code)
        assertEquals(
            ErrorCode.INVALID_JOB,
            rejected(validate(JSONObject().put("v", 1).put("id", "j1").put("method", "printReceipt").toString())).code,
        )
        assertEquals(ErrorCode.INVALID_JOB, rejected(validate(message("printReceipt", receiptJob().put("invoice", 123)))).code)
        assertEquals(ErrorCode.INVALID_JOB, rejected(validate(message("printReceipt", JSONObject().put("invoice", "INV-1").put("receiptUrl", receiptUrl)))).code)

        assertTrue(validate(message("printReceipt", receiptJob(), id = "x".repeat(65))) is JobValidator.Outcome.Drop)
        assertTrue(validate(message("printReceipt", receiptJob(), id = 42)) is JobValidator.Outcome.Drop)
        assertTrue(validate(message("printReceipt", receiptJob(), id = "")) is JobValidator.Outcome.Drop)
        assertTrue(validate("not json") is JobValidator.Outcome.Drop)
        assertTrue(validate(null) is JobValidator.Outcome.Drop)
        assertTrue(validate(message("printReceipt", receiptJob(), id = "x".repeat(64))) is JobValidator.Outcome.Accepted)
    }

    @Test
    fun printerChecksRunInProtocolOrder() {
        fun code(target: JSONObject) = rejected(validate(message("printReceipt", receiptJob(target)))).code

        assertEquals(ErrorCode.UNSUPPORTED_CONNECTION, code(printer(connection = "usb", address = "8.8.8.8")))
        assertEquals(ErrorCode.INVALID_PRINTER_ADDRESS, code(printer(address = "8.8.8.8", port = "x")))
        assertEquals(ErrorCode.INVALID_PRINTER_ADDRESS, code(printer(address = "localhost")))
        assertEquals(ErrorCode.INVALID_PORT, code(printer(port = "9100")))
        assertEquals(ErrorCode.INVALID_PORT, code(printer(port = true)))
        assertEquals(ErrorCode.INVALID_PORT, code(printer(port = 70000)))
    }

    @Test
    fun receiptUrlMustStayOnThePageOrigin() {
        val outcome = rejected(validate(message("printReceipt", receiptJob(url = "https://evil.test/receipt.pdf"))))
        assertEquals(ErrorCode.RECEIPT_URL_REJECTED, outcome.code)
        assertEquals(9100, outcome.effectivePort)

        assertEquals(
            ErrorCode.RECEIPT_URL_REJECTED,
            rejected(validate(message("openReceipt", JSONObject().put("invoice", "INV-1").put("receiptUrl", "http://pos.example.test/x")))).code,
        )
        assertEquals(
            ErrorCode.RECEIPT_URL_REJECTED,
            rejected(validate(message("printReceipt", receiptJob(url = 5)))).code,
        )
    }

    @Test
    fun testPrinterLabelsAreOptionalAndCut() {
        val payload = JSONObject()
            .put("printer", printer(address = "printer.local", port = 9101, paperWidth = 58))
            .put("deviceName", "  " + "ص".repeat(70) + "  ")
            .put("printerName", 12)
        val job = accepted(validate(message("testPrinter", payload))).job as ValidatedJob.TestPrinter
        assertEquals(60, job.deviceName?.length)
        assertNull(job.printerName)
        assertEquals(9101, job.printer.port)
        assertEquals(384, job.printer.dots)
        assertEquals(AddressKind.MDNS, job.printer.kind)
    }

    @Test
    fun openReceiptNeedsOnlyInvoiceAndUrl() {
        val payload = JSONObject().put("invoice", "INV-000123").put("receiptUrl", receiptUrl)
        val job = accepted(validate(message("openReceipt", payload))).job as ValidatedJob.OpenReceipt
        assertEquals("INV-000123", job.invoice)
        assertEquals(Method.OPEN_RECEIPT, job.method)
    }

    @Test
    fun resultsAndProgressHaveTheProtocolShape() {
        val failure = JSONObject(
            BridgeMessages.result(
                "j1",
                BridgeResult.failure("j1", ErrorCode.SEND_FAILED, connected = true, mayHavePrinted = true, effectivePort = 9100),
            ),
        )
        assertEquals("j1", failure.getString("id"))
        assertEquals("result", failure.getString("type"))
        val result = failure.getJSONObject("result")
        assertFalse(result.getBoolean("ok"))
        assertEquals("SEND_FAILED", result.getString("code"))
        assertEquals(ErrorCode.SEND_FAILED.message, result.getString("message"))
        assertTrue(result.getBoolean("connected"))
        assertTrue(result.getBoolean("mayHavePrinted"))
        assertEquals(9100, result.getInt("effectivePort"))
        assertEquals("j1", result.getString("jobId"))

        val success = JSONObject(
            BridgeMessages.result("j2", BridgeResult.success("j2", printed = true, connected = true, effectivePort = 9101)),
        ).getJSONObject("result")
        assertTrue(success.getBoolean("ok"))
        assertTrue(success.getBoolean("printed"))
        assertEquals(9101, success.getInt("effectivePort"))
        assertFalse(success.has("code"))

        val progress = JSONObject(BridgeMessages.progress("j3", Stage.DOWNLOADING_RECEIPT))
        assertEquals("progress", progress.getString("type"))
        assertEquals("downloading_receipt", progress.getString("stage"))
    }

    @Test
    fun theSharedShimShipsAsAnAsset() {
        // app/build.gradle.kts: assets.srcDir("../../shared/js")
        val fromModule = File("../../shared/js/abaad-printer-shim.js")
        val shim = if (fromModule.isFile) fromModule else Fixtures.locate("js/abaad-printer-shim.js")
        assertTrue(shim.isFile)
        val source = shim.readText(Charsets.UTF_8)
        assertTrue(source.contains("__abaadPrinterConfig"))
        assertTrue(source.contains("AbaadPrinter"))
    }
}
