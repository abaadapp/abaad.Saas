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

    @Test(timeout = 30_000)
    fun theWriteWatchdogEndsAStalledSend() {
        ServerSocket(0, 1, loopback).use { server ->
            val accepted = ArrayList<Socket>()
            // يقبل ولا يقرأ أبدًا: الكتابةُ تعلق حين تمتلئ المخازن
            val acceptor = Thread {
                val client = server.accept()
                synchronized(accepted) { accepted.add(client) }
            }
            acceptor.start()

            val started = System.nanoTime()
            val result = NetworkPrinterDriver().send(
                loopback,
                server.localPort,
                ByteArray(64 * 1024 * 1024),
                writeTimeoutMs = 500,
            )
            val elapsedMs = (System.nanoTime() - started) / 1_000_000
            acceptor.join(5_000)
            synchronized(accepted) { accepted.forEach { it.close() } }

            val failed = result as NetworkPrinterDriver.Result.Failed
            assertEquals(ErrorCode.SEND_FAILED, failed.code)
            assertTrue(failed.connected)
            assertTrue(failed.mayHavePrinted)
            assertTrue("took $elapsedMs ms", elapsedMs < 15_000)
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
