package om.abaad.pos.printing

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNotNull
import org.junit.Assert.assertTrue
import org.junit.Test

/**
 * لا اتّصالَ بالطابعة بعد أن تكفّ الصفحةُ عن الانتظار (PROTOCOL §5).
 *
 * إيصالٌ يخرج بعد أن قيل للكاشير «لم يصل ردّ» إيصالٌ لا يعلم به أحد — وإعادتُه
 * فوقه إيصالٌ ثانٍ للبيعة نفسِها.
 */
class ConnectDeadlineTest {

    @Test
    fun aReceiptMayConnectUntilItsDeadlineAndNotAfter() {
        val accepted = 1_000L
        assertFalse(ConnectDeadline.expired(Method.PRINT_RECEIPT, accepted, accepted + ConnectDeadline.PRINT_RECEIPT_MS))
        assertTrue(ConnectDeadline.expired(Method.PRINT_RECEIPT, accepted, accepted + ConnectDeadline.PRINT_RECEIPT_MS + 1))
    }

    @Test
    fun aTestPageHasTheShorterDeadline() {
        val accepted = 1_000L
        assertFalse(ConnectDeadline.expired(Method.TEST_PRINTER, accepted, accepted + ConnectDeadline.TEST_PRINTER_MS))
        assertTrue(ConnectDeadline.expired(Method.TEST_PRINTER, accepted, accepted + ConnectDeadline.TEST_PRINTER_MS + 1))
    }

    @Test
    fun openingTheFileNeverTouchesThePrinterSoItHasNoDeadline() {
        assertFalse(ConnectDeadline.expired(Method.OPEN_RECEIPT, 0L, Long.MAX_VALUE / 2))
    }

    /** المهلةُ والاتّصالُ والإرسالُ تنتهي قبل أن يكفّ الـshim عن الانتظار — مقروءًا من الملفّ نفسِه */
    @Test
    fun theDeadlinePlusConnectAndWriteFitInsideTheShimWait() {
        val shim = Fixtures.locate("js/abaad-printer-shim.js").readText(Charsets.UTF_8)
        fun wait(method: String): Long {
            val match = Regex("\\b$method: (\\d+)").find(shim)
            assertNotNull("no timeout for $method in the shim", match)
            return match!!.groupValues[1].toLong()
        }
        val sendBudget = NetworkPrinterDriver.CONNECT_TIMEOUT_MS + NetworkPrinterDriver.WRITE_TIMEOUT_MS

        assertTrue(ConnectDeadline.PRINT_RECEIPT_MS + sendBudget < wait("printReceipt"))
        assertTrue(ConnectDeadline.TEST_PRINTER_MS + sendBudget < wait("testPrinter"))
        assertEquals(120_000L, wait("printReceipt"))
    }
}
