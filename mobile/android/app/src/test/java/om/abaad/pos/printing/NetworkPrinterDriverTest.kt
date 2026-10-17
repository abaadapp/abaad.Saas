package om.abaad.pos.printing

import org.junit.Assert.assertArrayEquals
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test
import java.io.ByteArrayOutputStream
import java.net.ConnectException
import java.net.InetAddress
import java.net.NoRouteToHostException
import java.net.ServerSocket
import java.net.Socket
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

    @Test(timeout = 30_000)
    fun aFailureAfterWritingStartsIsSendFailedAndMayHavePrinted() {
        ServerSocket(0, 1, loopback).use { server ->
            val resetter = Thread {
                val client = server.accept()
                client.setSoLinger(true, 0) // إغلاقٌ بـRST
                client.close()
            }
            resetter.start()

            val result = NetworkPrinterDriver().send(
                loopback,
                server.localPort,
                ByteArray(32 * 1024 * 1024),
                writeTimeoutMs = 5_000,
            )
            resetter.join(5_000)

            val failed = result as NetworkPrinterDriver.Result.Failed
            assertEquals(ErrorCode.SEND_FAILED, failed.code)
            assertTrue(failed.connected)
            assertTrue(failed.mayHavePrinted)
        }
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
    }
}
