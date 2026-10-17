package om.abaad.pos.printing

import org.junit.Assert.assertArrayEquals
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test
import java.io.ByteArrayOutputStream
import java.io.OutputStream
import java.net.ConnectException
import java.net.InetAddress
import java.net.InetSocketAddress
import java.net.NoRouteToHostException
import java.net.ServerSocket
import java.net.Socket
import java.net.SocketAddress
import java.net.SocketException
import java.net.SocketTimeoutException
import java.net.UnknownHostException

/**
 * المشغّلُ على مقبسٍ حقيقيّ على 127.0.0.1 — الفحصُ الخاصّ طبقةٌ أخرى، فيقبل هنا loopback.
 */
class NetworkPrinterDriverTest {

    private val loopback: InetAddress = InetAddress.getByAddress(byteArrayOf(127, 0, 0, 1))

    @Test(timeout = 20_000)
    fun everyByteReachesThePrinter() {
        val payload = ByteArray(100_000) { (it * 31 + 7).toByte() }
        ServerSocket(0, 1, loopback).use { server ->
            val received = ByteArrayOutputStream()
            val reader = Thread {
                server.accept().use { client -> client.getInputStream().copyTo(received) }
            }
            reader.start()

            val stages = ArrayList<Stage>()
            val result = NetworkPrinterDriver().send(loopback, server.localPort, payload, onStage = { stages.add(it) })
            reader.join(10_000)

            assertTrue("expected Sent", result is NetworkPrinterDriver.Result.Sent)
            assertTrue(result.connected)
            assertEquals(payload.size, result.bytesWritten)
            assertArrayEquals(payload, received.toByteArray())
            assertEquals(listOf(Stage.CONNECTING, Stage.SENDING), stages)
        }
    }

    @Test(timeout = 20_000)
    fun aClosedPortIsPortRefused() {
        val port = ServerSocket(0, 1, loopback).use { it.localPort }

        val result = NetworkPrinterDriver().send(loopback, port, byteArrayOf(1, 2, 3))

        val failed = result as NetworkPrinterDriver.Result.Failed
        assertEquals(ErrorCode.PORT_REFUSED, failed.code)
        assertFalse(failed.connected)
        assertFalse(failed.mayHavePrinted)
        assertEquals(0, failed.bytesWritten)
    }

    @Test(timeout = 20_000)
    fun anUnroutableAddressTimesOut() {
        val unroutable = InetAddress.getByAddress(byteArrayOf(10, 255.toByte(), 255.toByte(), 1))

        val result = NetworkPrinterDriver().send(unroutable, 9100, byteArrayOf(1), connectTimeoutMs = 300)

        val failed = result as NetworkPrinterDriver.Result.Failed
        // شبكاتُ CI تختلف: بعضها يُسقط الحزمة صامتًا (مهلة)، وبعضها يردّ «لا طريق»
        assertTrue(
            "got ${failed.code}",
            failed.code == ErrorCode.CONNECT_TIMEOUT || failed.code == ErrorCode.PRINTER_UNREACHABLE,
        )
        assertFalse(failed.connected)
        assertFalse(failed.mayHavePrinted)
    }

    /**
     * انقطاعٌ بعد أن بلغت الطابعةَ بايتاتٌ فعلًا — عند البايت نفسِه في كلّ تشغيل.
     *
     * كان الطرفُ الآخر يُغلق بـRST فور القبول، فيسبق أحيانًا عودةَ connect():
     * فيصير فشلَ اتّصال لا فشلَ إرسال. الآن المقبسُ حقيقيّ والطابعةُ تقرأ كلَّ ما يصلها،
     * والانقطاعُ من خرج المقبس بعد ثلاث قطع — لا سباقَ ولا حجمَ مخزن.
     */
    @Test(timeout = 20_000)
    fun aFailureAfterWritingStartsIsSendFailedAndMayHavePrinted() {
        val chunk = 4096
        val cutAt = 3 * chunk
        ServerSocket(0, 1, loopback).use { server ->
            val received = ByteArrayOutputStream()
            val reader = Thread {
                server.accept().use { client -> client.getInputStream().copyTo(received) }
            }
            reader.start()

            val driver = NetworkPrinterDriver(chunkSize = chunk, openSocket = { SocketCutAfter(cutAt) })
            val result = driver.send(loopback, server.localPort, ByteArray(10 * chunk) { it.toByte() })
            reader.join(10_000)

            val failed = result as NetworkPrinterDriver.Result.Failed
            assertEquals(ErrorCode.SEND_FAILED, failed.code)
            assertTrue(failed.connected)
            assertTrue(failed.mayHavePrinted)
            assertEquals(cutAt, failed.bytesWritten)
            // و«ربما طُبع» ليست تحوّطًا نظريًّا: هذه البايتاتُ بلغت الطابعة
            assertFalse(reader.isAlive)
            assertEquals(cutAt, received.size())
        }
    }

    /**
     * ما حدث مرّةً على CI: الطرفُ أغلق بـRST قبل أن تعود connect(). لم يُعطَ المقبسُ
     * بايتًا واحدًا، فلا شيءَ طُبع — فشلُ اتّصال، وإعادةُ الطباعة بعده آمنة.
     */
    @Test(timeout = 20_000)
    fun aResetBeforeConnectReturnsPrintedNothing() {
        val driver = NetworkPrinterDriver(openSocket = { SocketResetOnConnect() })

        val result = driver.send(loopback, 9100, byteArrayOf(1, 2, 3))

        val failed = result as NetworkPrinterDriver.Result.Failed
        assertEquals(ErrorCode.PRINTER_UNREACHABLE, failed.code)
        assertFalse(failed.connected)
        assertFalse(failed.mayHavePrinted)
        assertEquals(0, failed.bytesWritten)
    }

    /*
     * طابعةٌ بطيئة تأخذ أكثر من 30 ثانية — ويصلها الإيصالُ كلُّه.
     *
     * كانت للإرسال كلِّه 30 ثانية ثمّ يُغلق المقبس: إيصالٌ طويل على طابعةٍ تطبع الصورةَ
     * ببطء كان يخرج إلى منتصفه. والطابعةُ هنا تقرأ 1600 بايت كلَّ عُشر ثانية (16KB/ث)
     * ومخزنُ استقبالها صغير، ومخزنُ إرسالنا 16KB: فـ800KB تقرؤه في نحو 50 ثانية، ويبقى
     * الإرسالُ نفسُه أكثر من 30 ثانية ولو بقي في مخازن النظام ما لم تقرأه بعد — ولا يقف
     * التقدّمُ فيها طويلًا.
     */
    @Test(timeout = 120_000)
    fun aSlowPrinterThatNeedsMoreThanThirtySecondsReceivesEveryByte() {
        val payload = ByteArray(800_000) { (it * 131 + 17).toByte() }
        PacedPrinter(loopback, bytesPerRead = 1600, everyMs = 100).use { printer ->
            val progress = ArrayList<Long>()
            val started = System.nanoTime()
            val result = NetworkPrinterDriver().send(
                loopback,
                printer.port,
                payload,
                onProgress = { _, _ -> synchronized(progress) { progress.add(System.nanoTime()) } },
            )
            val elapsedMs = (System.nanoTime() - started) / 1_000_000
            printer.join(30_000)

            assertTrue("expected Sent", result is NetworkPrinterDriver.Result.Sent)
            assertEquals(payload.size, result.bytesWritten)
            assertTrue("the send itself lasted $elapsedMs ms", elapsedMs > 30_000)
            assertArrayEquals(payload, printer.received())

            // وتقدّمٌ لا يقف: لا فجوةَ بين كتابتين تقترب من حدّ التوقّف (15 ث)
            val times = synchronized(progress) { progress.toList() }
            val widestMs = times.zipWithNext { a, b -> (b - a) / 1_000_000 }.maxOrNull() ?: 0L
            assertTrue("widest gap between writes: $widestMs ms", widestMs < 7_500)
        }
    }

    /*
     * وطابعةٌ توقّفت (ورقٌ نفد، غطاءٌ مفتوح) تُقطع بعد مهلة التوقّف بلا تقدّم —
     * SEND_FAILED وربما طُبع جزء، ولا إعادة.
     */
    @Test(timeout = 60_000)
    fun aPrinterThatStopsReadingFailsAfterTheStallTimeout() {
        PacedPrinter(loopback, bytesPerRead = 4096, everyMs = 0, stopAfter = 50_000).use { printer ->
            val started = System.nanoTime()
            val result = NetworkPrinterDriver().send(
                loopback,
                printer.port,
                ByteArray(64 * 1024 * 1024),
                stallTimeoutMs = 1_000,
            )
            val elapsedMs = (System.nanoTime() - started) / 1_000_000

            val failed = result as NetworkPrinterDriver.Result.Failed
            assertEquals(ErrorCode.SEND_FAILED, failed.code)
            assertTrue(failed.connected)
            assertTrue(failed.mayHavePrinted)
            assertTrue("wrote ${failed.bytesWritten}", failed.bytesWritten >= 50_000)
            assertTrue("took $elapsedMs ms", elapsedMs < 10_000)
            // ولا يتكدّس الإيصالُ في الجهاز: ما قُبل ولم تأخذه الطابعة قليل (مخزنٌ صغير)
            val inDevice = failed.bytesWritten - printer.received().size
            assertTrue("$inDevice bytes accepted but never read by the printer", inDevice < 512 * 1024)
        }
    }

    /** طابعةٌ تردّ بايتاتِ حالةٍ أثناء الطباعة: تُقرأ وتُهمل، والإيصالُ يصل كلُّه */
    @Test(timeout = 60_000)
    fun statusBytesThePrinterSendsBackDoNotCostTheEndOfTheReceipt() {
        val payload = ByteArray(1_000_000) { (it * 7 + 3).toByte() }
        PacedPrinter(loopback, bytesPerRead = 64 * 1024, everyMs = 0, replyEvery = 8 * 1024).use { printer ->
            val result = NetworkPrinterDriver().send(loopback, printer.port, payload)
            printer.join(10_000)

            assertTrue("expected Sent", result is NetworkPrinterDriver.Result.Sent)
            assertArrayEquals(payload, printer.received())
        }
    }

    /** الإيصالُ من ملفّ التخزين قطعةً قطعة — كما يرسله التطبيق */
    @Test(timeout = 30_000)
    fun aSpooledReceiptIsSentFromItsFileByteForByte() {
        val payload = ByteArray(300_000) { (it * 29 + 5).toByte() }
        val file = java.io.File.createTempFile("spool-", ".escpos")
        try {
            file.writeBytes(payload)
            PacedPrinter(loopback, bytesPerRead = 64 * 1024, everyMs = 0).use { printer ->
                val sent = ArrayList<Long>()
                val result = NetworkPrinterDriver().send(
                    loopback,
                    printer.port,
                    ReceiptSpool(file, file.length()),
                    onProgress = { done, total ->
                        assertEquals(payload.size.toLong(), total)
                        sent.add(done)
                    },
                )
                printer.join(10_000)

                assertTrue("expected Sent", result is NetworkPrinterDriver.Result.Sent)
                assertArrayEquals(payload, printer.received())
                assertEquals(payload.size.toLong(), sent.last())
                assertEquals(sent.sorted(), sent)
            }
        } finally {
            file.delete()
        }
    }

    /** ملفٌّ أقصرُ ممّا قيل إنّه فيه: لم يخرج الإيصالُ كلُّه — لا «طُبع» */
    @Test(timeout = 30_000)
    fun aSpoolShorterThanItsLengthIsNotReportedPrinted() {
        val file = java.io.File.createTempFile("spool-", ".escpos")
        try {
            file.writeBytes(ByteArray(10_000))
            PacedPrinter(loopback, bytesPerRead = 64 * 1024, everyMs = 0).use { printer ->
                val result = NetworkPrinterDriver().send(loopback, printer.port, ReceiptSpool(file, 20_000))

                val failed = result as NetworkPrinterDriver.Result.Failed
                assertEquals(ErrorCode.SEND_FAILED, failed.code)
                assertTrue(failed.mayHavePrinted)
            }
        } finally {
            file.delete()
        }
    }

    @Test
    fun connectFailuresMapToProtocolCodes() {
        fun code(error: Throwable) = NetworkPrinterDriver.classifyConnectFailure(error)

        assertEquals(ErrorCode.CONNECT_TIMEOUT, code(SocketTimeoutException("connect timed out")))
        assertEquals(
            ErrorCode.PORT_REFUSED,
            code(ConnectException("failed to connect to /192.168.1.5 (port 9100) after 5000ms: isConnected failed: ECONNREFUSED (Connection refused)")),
        )
        assertEquals(ErrorCode.PORT_REFUSED, code(ConnectException("Connection refused")))
        assertEquals(ErrorCode.PRINTER_UNREACHABLE, code(NoRouteToHostException("No route to host")))
        assertEquals(ErrorCode.PRINTER_UNREACHABLE, code(ConnectException("failed to connect: EHOSTUNREACH (No route to host)")))
        assertEquals(ErrorCode.PRINTER_UNREACHABLE, code(ConnectException("failed to connect: ENETUNREACH (Network is unreachable)")))
        assertEquals(ErrorCode.PRINTER_UNREACHABLE, code(UnknownHostException("printer.local")))
        assertEquals(ErrorCode.CONNECT_TIMEOUT, code(ConnectException("failed to connect: ETIMEDOUT (Connection timed out)")))
        // ما ترميه JDK حين يصل RST قبل أن تعود connect()
        assertEquals(ErrorCode.PRINTER_UNREACHABLE, code(SocketException("Connection reset")))
    }
}

/** مقبسٌ حقيقيّ ينقطع خرجُه عند بايتٍ ثابت: ما قبله يبلغ الطرفَ الآخر، وما بعده يُرمى */
private class SocketCutAfter(private val limit: Int) : Socket() {
    private var sent = 0

    override fun getOutputStream(): OutputStream {
        val real = super.getOutputStream()
        return object : OutputStream() {
            override fun write(b: Int) = write(byteArrayOf(b.toByte()), 0, 1)

            override fun write(b: ByteArray, off: Int, len: Int) {
                if (sent + len > limit) throw SocketException("Connection reset")
                real.write(b, off, len)
                sent += len
            }

            override fun flush() = real.flush()
        }
    }
}

/** الطرفُ أغلق بـRST قبل أن تعود connect() */
private class SocketResetOnConnect : Socket() {
    override fun connect(endpoint: SocketAddress?, timeout: Int) {
        throw SocketException("Connection reset")
    }
}

/*
 * طابعةٌ على 127.0.0.1: مخزنُ استقبالٍ صغير كطابعةٍ حقيقيّة، وقراءةٌ بالإيقاع المطلوب —
 * `bytesPerRead` كلَّ `everyMs`، وقد تتوقّف بعد `stopAfter` (ورقٌ نفد) أو تردّ بايتَ
 * حالةٍ كلَّ `replyEvery`.
 */
private class PacedPrinter(
    address: InetAddress,
    private val bytesPerRead: Int,
    private val everyMs: Long,
    private val stopAfter: Int = Int.MAX_VALUE,
    private val replyEvery: Int = Int.MAX_VALUE,
) : AutoCloseable {
    private val server = ServerSocket().apply {
        receiveBufferSize = 4096
        bind(InetSocketAddress(address, 0), 1)
    }
    private val received = ByteArrayOutputStream()
    private val accepted = ArrayList<Socket>()

    val port: Int get() = server.localPort

    private val thread = Thread {
        try {
            val client = server.accept()
            synchronized(accepted) { accepted.add(client) }
            val input = client.getInputStream()
            val output = client.getOutputStream()
            val buffer = ByteArray(bytesPerRead)
            var total = 0
            var nextReply = replyEvery
            while (total < stopAfter) {
                if (everyMs > 0) Thread.sleep(everyMs)
                val length = input.read(buffer, 0, minOf(buffer.size, stopAfter - total))
                if (length < 0) break
                synchronized(received) { received.write(buffer, 0, length) }
                total += length
                if (total >= nextReply) {
                    output.write(0x12)
                    output.flush()
                    nextReply += replyEvery
                }
            }
            // ورقٌ نفد: لا يقرأ بعدها ولا يُغلق — حتى يُغلقه الاختبار
            if (total < stopAfter) client.close()
        } catch (e: Exception) {
            // أُغلق الخادم
        }
    }.apply {
        isDaemon = true
        start()
    }

    fun received(): ByteArray = synchronized(received) { received.toByteArray() }

    fun join(ms: Long) {
        thread.join(ms)
    }

    override fun close() {
        synchronized(accepted) { accepted.forEach { it.close() } }
        server.close()
    }
}
