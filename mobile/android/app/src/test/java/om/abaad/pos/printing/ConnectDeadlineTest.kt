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

    /*
     * لا صمتَ أطولَ من انتظار الـshim — مقروءًا من ملفّه نفسِه.
     *
     * الصفحةُ تنتظر ما دامت تسمع. فكلُّ مدّةٍ لا يُقال فيها لها شيء أقصرُ من مهلتها:
     * الطابورُ ثمّ الاتّصال، والتنزيل، وتوقّفُ الطابعة ثمّ ذيلُ الإغلاق، وما بين نبضتين.
     */
    @Test
    fun noSilenceTheAppAllowsIsAsLongAsTheShimWait() {
        val shim = Fixtures.locate("js/abaad-printer-shim.js").readText(Charsets.UTF_8)
        fun wait(method: String): Long {
            val match = Regex("\\b$method: (\\d+)").find(shim)
            assertNotNull("no timeout for $method in the shim", match)
            return match!!.groupValues[1].toLong()
        }
        val connect = NetworkPrinterDriver.CONNECT_TIMEOUT_MS.toLong()
        val stallThenTail = NetworkPrinterDriver.STALL_TIMEOUT_MS + NetworkPrinterDriver.TAIL_MS

        for ((method, deadline) in listOf("printReceipt" to ConnectDeadline.PRINT_RECEIPT_MS, "testPrinter" to ConnectDeadline.TEST_PRINTER_MS)) {
            assertTrue("$method: queue + connect", deadline + connect < wait(method))
            assertTrue("$method: stall + tail", stallThenTail < wait(method))
            assertTrue("$method: between beats", PageContact.BEAT_INTERVAL_MS < wait(method))
        }
        assertTrue("download", ReceiptDownloader.TIMEOUT_MS < wait("printReceipt"))
        assertEquals(120_000L, wait("printReceipt"))
    }

    /*
     * والمهلةُ تُحسب من آخر ما سمعته الصفحة لا من قبول الطلب: مهمّةٌ تقول مرحلتها كلَّ
     * دقيقة لا تنتهي مهلتُها مهما طالت، ومهمّةٌ صمتت 76 ثانيةً انتهت.
     */
    @Test
    fun theDeadlineCountsSilenceNotAge() {
        var now = 0L
        val sent = ArrayList<Pair<Stage, Boolean>>()
        val contact = PageContact(acceptedAtMs = 0L, clock = { now }) { stage, changed -> sent.add(stage to changed) }

        now = 60_000
        contact.tell(Stage.DOWNLOADING_RECEIPT)
        now = 120_000
        contact.tell(Stage.RENDERING)
        now = 180_000
        assertFalse("heard a minute ago", contact.silentTooLong(Method.PRINT_RECEIPT))

        now = 120_000 + ConnectDeadline.PRINT_RECEIPT_MS + 1
        assertTrue("silent past the deadline", contact.silentTooLong(Method.PRINT_RECEIPT))
        assertEquals(listOf(Stage.DOWNLOADING_RECEIPT to true, Stage.RENDERING to true), sent)
    }

    /** النبضةُ تُقال إن مضت ثانيةٌ على آخر ما قيل — لا أكثر، وتُعلَّم أنّها المرحلةُ نفسُها */
    @Test
    fun aBeatIsSentOnlyAfterAQuietSecond() {
        var now = 0L
        val sent = ArrayList<Pair<Stage, Boolean>>()
        val contact = PageContact(acceptedAtMs = 0L, clock = { now }) { stage, changed -> sent.add(stage to changed) }

        contact.tell(Stage.SENDING)
        now = PageContact.BEAT_INTERVAL_MS - 1
        contact.beat(Stage.SENDING)
        assertEquals(1, sent.size)

        now = PageContact.BEAT_INTERVAL_MS
        contact.beat(Stage.SENDING)
        assertEquals(listOf(Stage.SENDING to true, Stage.SENDING to false), sent)
        assertEquals(PageContact.BEAT_INTERVAL_MS, contact.lastHeardMs)
    }
}
