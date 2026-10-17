package om.abaad.pos.printing

import java.io.IOException
import java.net.ConnectException
import java.net.InetAddress
import java.net.InetSocketAddress
import java.net.NoRouteToHostException
import java.net.Socket
import java.net.SocketTimeoutException
import java.net.UnknownHostException
import java.util.Locale
import java.util.concurrent.Executors
import java.util.concurrent.ScheduledExecutorService
import java.util.concurrent.ScheduledFuture
import java.util.concurrent.TimeUnit

/**
 * TCP خام إلى طابعة ESC/POS على الشبكة المحليّة (PROTOCOL §5 الخطوتان 4 و5).
 *
 * ═══ ولا إعادةَ أبدًا ═══
 *
 * ما إن تبدأ الكتابةُ فقد يكون جزءٌ بلغ الطابعة. فكلُّ فشلٍ بعدها `SEND_FAILED`
 * مع `mayHavePrinted`، والكاشيرُ يقرّر الإعادة بنفسه بعد أن يرى الورق.
 *
 * والعنوانُ هنا مفحوصٌ سلفًا (`PrinterAddress.resolve`) — فلا يقبل اسمًا ولا يحلّه.
 */
class NetworkPrinterDriver(
    private val chunkSize: Int = 4096,
    private val drainMs: Int = 300,
) {

    sealed class Result {
        abstract val connected: Boolean
        abstract val bytesWritten: Int

        class Sent(override val bytesWritten: Int) : Result() {
            override val connected: Boolean get() = true
        }

        class Failed(
            val code: ErrorCode,
            override val connected: Boolean,
            override val bytesWritten: Int,
            val mayHavePrinted: Boolean,
        ) : Result()
    }

    fun send(
        address: InetAddress,
        port: Int,
        bytes: ByteArray,
        connectTimeoutMs: Int = CONNECT_TIMEOUT_MS,
        writeTimeoutMs: Long = WRITE_TIMEOUT_MS,
        onStage: (Stage) -> Unit = {},
    ): Result {
        val socket = Socket()
        var watchdog: ScheduledFuture<*>? = null
        var written = 0
        try {
            onStage(Stage.CONNECTING)
            try {
                socket.tcpNoDelay = true
                socket.connect(InetSocketAddress(address, port), connectTimeoutMs)
            } catch (e: IOException) {
                return Result.Failed(classifyConnectFailure(e), connected = false, bytesWritten = 0, mayHavePrinted = false)
            } catch (e: SecurityException) {
                return Result.Failed(ErrorCode.PRINTER_UNREACHABLE, connected = false, bytesWritten = 0, mayHavePrinted = false)
            }

            onStage(Stage.SENDING)
            // مهلةُ الكتابة كلِّها لا كلِّ نداء: يُغلَق المقبسُ من خارج، فتسقط الكتابةُ العالقة
            watchdog = WATCHDOG.schedule(Runnable { closeQuietly(socket) }, writeTimeoutMs, TimeUnit.MILLISECONDS)
            try {
                val output = socket.getOutputStream()
                var offset = 0
                while (offset < bytes.size) {
                    val length = minOf(chunkSize, bytes.size - offset)
                    output.write(bytes, offset, length)
                    offset += length
                    written = offset
                }
                output.flush()
                socket.shutdownOutput()
            } catch (e: IOException) {
                return Result.Failed(ErrorCode.SEND_FAILED, connected = true, bytesWritten = written, mayHavePrinted = true)
            } catch (e: RuntimeException) {
                return Result.Failed(ErrorCode.SEND_FAILED, connected = true, bytesWritten = written, mayHavePrinted = true)
            }
            watchdog?.cancel(false)
            drain(socket)
            return Result.Sent(written)
        } finally {
            watchdog?.cancel(false)
            closeQuietly(socket)
        }
    }

    /**
     * بعد FIN: يُقرأ ما قد تردّه الطابعة (حالةٌ مثلًا) حتى تُغلق أو تنقضي مهلةٌ قصيرة.
     * إغلاقُ مقبسٍ فيه بايتاتٌ لم تُقرأ يُرسل RST، وقد يُسقط آخرَ ما لم يصل.
     */
    private fun drain(socket: Socket) {
        try {
            socket.soTimeout = drainMs
            val input = socket.getInputStream()
            val sink = ByteArray(256)
            val deadline = System.nanoTime() + drainMs * 1_000_000L
            while (System.nanoTime() < deadline) {
                if (input.read(sink) < 0) return
            }
        } catch (e: IOException) {
            // انقضت المهلة أو أغلقت الطابعة — البايتاتُ كلُّها سُلّمت قبلها
        }
    }

    companion object {
        const val CONNECT_TIMEOUT_MS = 5_000
        const val WRITE_TIMEOUT_MS = 30_000L

        private val WATCHDOG: ScheduledExecutorService = Executors.newSingleThreadScheduledExecutor { runnable ->
            Thread(runnable, "abaad-printer-watchdog").apply { isDaemon = true }
        }

        /**
         * فشلُ الاتّصال ← رمزٌ من §8. رسائلُ Android تحمل اسمَ errno
         * (`ECONNREFUSED (Connection refused)`)، ورسائلُ JVM نصَّه وحده — فيُقرأ الاثنان.
         */
        fun classifyConnectFailure(error: Throwable): ErrorCode {
            if (error is SocketTimeoutException) return ErrorCode.CONNECT_TIMEOUT
            val message = (error.message ?: "").lowercase(Locale.ROOT)
            if (message.contains("econnrefused") || message.contains("connection refused")) {
                return ErrorCode.PORT_REFUSED
            }
            if (error is NoRouteToHostException || error is UnknownHostException) return ErrorCode.PRINTER_UNREACHABLE
            if (message.contains("ehostunreach") ||
                message.contains("enetunreach") ||
                message.contains("network is unreachable") ||
                message.contains("no route")
            ) {
                return ErrorCode.PRINTER_UNREACHABLE
            }
            if (message.contains("etimedout") || message.contains("timed out")) return ErrorCode.CONNECT_TIMEOUT
            if (error is ConnectException) return ErrorCode.PRINTER_UNREACHABLE
            // EPERM/EACCES: الشبكةُ المحليّة ممنوعة — وهي في §8 «تعذر الوصول»
            return ErrorCode.PRINTER_UNREACHABLE
        }

        private fun closeQuietly(socket: Socket) {
            try {
                socket.close()
            } catch (e: IOException) {
                // لا شيء يُفعل
            }
        }
    }
}
