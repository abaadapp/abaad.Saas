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
import java.util.concurrent.CountDownLatch
import java.util.concurrent.Executors
import java.util.concurrent.ScheduledExecutorService
import java.util.concurrent.ScheduledFuture
import java.util.concurrent.TimeUnit
import java.util.concurrent.atomic.AtomicLong

/**
 * TCP خام إلى طابعة ESC/POS على الشبكة المحليّة (PROTOCOL §5 الخطوتان 4 و5).
 *
 * ═══ ولا إعادةَ أبدًا ═══
 *
 * ما إن تبدأ الكتابةُ فقد يكون جزءٌ بلغ الطابعة. فكلُّ فشلٍ بعدها `SEND_FAILED`
 * مع `mayHavePrinted`، والكاشيرُ يقرّر الإعادة بنفسه بعد أن يرى الورق.
 *
 * ═══ والإرسالُ يُحرس بالتقدّم لا بالمدّة ═══
 *
 * كانت للإرسال كلِّه 30 ثانية ثمّ يُغلق المقبس. وإيصالٌ طويل على طابعةٍ تطبع الصورةَ
 * ببطء يحتاج أكثر — فكان يخرج إلى منتصفه، والكاشيرُ يرى «انقطع الاتصال».
 *
 * والآن مخزنُ الإرسال صغير (`SEND_BUFFER_BYTES`)، فلا يقبل النظامُ بايتًا جديدًا إلّا
 * بعد أن تأخذ الطابعةُ ما قبله: كلُّ كتابةٍ تتمّ تقدّمٌ حقيقيّ عند الطابعة، ولا يتكدّس
 * الإيصالُ في الجهاز أسرعَ ممّا تطبع (ضغطُ TCP الراجع). وما دامت كتابةٌ تتمّ فالإيصالُ
 * يتمّ مهما طال؛ وإن لم تتمّ كتابةٌ في `STALL_TIMEOUT_MS` فالطابعةُ متوقّفة — ورقٌ نفد
 * أو غطاءٌ مفتوح — فيُغلق المقبسُ من خارج، فتسقط الكتابةُ العالقة: `SEND_FAILED`.
 *
 * ═══ وما تردّه الطابعة يُقرأ ═══
 *
 * بعضُ الطابعات تردّ بايتاتِ حالة. وإغلاقُ مقبسٍ فيه ردٌّ لم يُقرأ يُرسل RST، فيُسقط
 * النظامُ ما بقي من الإيصال في مخزنه. فخيطٌ يقرأ الردودَ ويهملها طوال الإرسال، وبعد
 * آخر بايتٍ يُغلق طرفُ الكتابة (FIN بعد آخر بايت) وتُنتظر الطابعةُ لتُغلق، أو `TAIL_MS`.
 *
 * والعنوانُ هنا مفحوصٌ سلفًا (`PrinterAddress.resolve`) — فلا يقبل اسمًا ولا يحلّه.
 */
class NetworkPrinterDriver(
    private val chunkSize: Int = 4096,
    private val sendBufferBytes: Int = SEND_BUFFER_BYTES,
    // مقبسٌ غيرُ متّصل. التطبيقُ يأخذ الافتراضيَّ دائمًا؛ والاختبارُ يمرّر مقبسًا حقيقيًّا
    // ينقطع خرجُه عند بايتٍ يختاره — بدل RST قد يسبق عودةَ connect() أو يتأخّر عنها
    private val openSocket: () -> Socket = { Socket() },
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

    /** بايتاتٌ في الذاكرة — صفحةُ الاختبار */
    fun send(
        address: InetAddress,
        port: Int,
        bytes: ByteArray,
        connectTimeoutMs: Int = CONNECT_TIMEOUT_MS,
        stallTimeoutMs: Long = STALL_TIMEOUT_MS,
        onStage: (Stage) -> Unit = {},
        onProgress: (sent: Long, total: Long) -> Unit = { _, _ -> },
    ): Result = send(address, port, BytesPayload(bytes), connectTimeoutMs, stallTimeoutMs, onStage, onProgress)

    fun send(
        address: InetAddress,
        port: Int,
        payload: PrintPayload,
        connectTimeoutMs: Int = CONNECT_TIMEOUT_MS,
        stallTimeoutMs: Long = STALL_TIMEOUT_MS,
        onStage: (Stage) -> Unit = {},
        onProgress: (sent: Long, total: Long) -> Unit = { _, _ -> },
    ): Result {
        val socket = openSocket()
        var watchdog: ScheduledFuture<*>? = null
        var written = 0L
        try {
            onStage(Stage.CONNECTING)
            try {
                socket.tcpNoDelay = true
                socket.sendBufferSize = sendBufferBytes
                socket.connect(InetSocketAddress(address, port), connectTimeoutMs)
            } catch (e: IOException) {
                return Result.Failed(classifyConnectFailure(e), connected = false, bytesWritten = 0, mayHavePrinted = false)
            } catch (e: SecurityException) {
                return Result.Failed(ErrorCode.PRINTER_UNREACHABLE, connected = false, bytesWritten = 0, mayHavePrinted = false)
            }

            onStage(Stage.SENDING)
            val replies = Replies(socket)
            replies.start()
            // حارسُ التوقّف: لا كتابةَ تمّت في `stallTimeoutMs` ← يُغلق المقبسُ من خارج
            val lastProgress = AtomicLong(System.nanoTime())
            val stallNanos = stallTimeoutMs * 1_000_000L
            watchdog = WATCHDOG.scheduleWithFixedDelay(
                {
                    if (System.nanoTime() - lastProgress.get() > stallNanos) closeQuietly(socket)
                },
                WATCHDOG_TICK_MS,
                WATCHDOG_TICK_MS,
                TimeUnit.MILLISECONDS,
            )
            try {
                payload.open().use { input ->
                    val output = socket.getOutputStream()
                    val buffer = ByteArray(chunkSize)
                    while (true) {
                        val length = input.read(buffer)
                        if (length < 0) break
                        if (length == 0) continue
                        output.write(buffer, 0, length)
                        written += length
                        lastProgress.set(System.nanoTime())
                        onProgress(written, payload.length)
                    }
                    output.flush()
                }
                // الملفُّ أقصرُ ممّا قيل إنّه فيه: لم يخرج الإيصالُ كلُّه
                if (written != payload.length) {
                    return failed(written)
                }
                socket.shutdownOutput()
            } catch (e: IOException) {
                return failed(written)
            } catch (e: RuntimeException) {
                return failed(written)
            }
            watchdog?.cancel(false)
            replies.awaitClose(TAIL_MS)
            return Result.Sent(clamp(written))
        } finally {
            watchdog?.cancel(false)
            closeQuietly(socket)
        }
    }

    private fun failed(written: Long): Result =
        Result.Failed(ErrorCode.SEND_FAILED, connected = true, bytesWritten = clamp(written), mayHavePrinted = true)

    /** ما تردّه الطابعة يُقرأ ويُهمل حتى تُغلق هي أو نُغلق نحن */
    private class Replies(private val socket: Socket) : Thread("abaad-printer-replies") {
        private val closed = CountDownLatch(1)

        init {
            isDaemon = true
        }

        override fun run() {
            try {
                val input = socket.getInputStream()
                val sink = ByteArray(256)
                while (input.read(sink) >= 0) {
                    // حالةُ الطابعة — لا يُبنى عليها شيء
                }
            } catch (e: IOException) {
                // أُغلق المقبس — انتهى ما يُقرأ
            } finally {
                closed.countDown()
            }
        }

        /** حتى تُغلق الطابعةُ طرفَها، أو `ms` */
        fun awaitClose(ms: Long) {
            try {
                closed.await(ms, TimeUnit.MILLISECONDS)
            } catch (e: InterruptedException) {
                Thread.currentThread().interrupt()
            }
        }
    }

    companion object {
        const val CONNECT_TIMEOUT_MS = 5_000

        /** أطولُ مدّةٍ بلا كتابةٍ تتمّ — لا مدّةُ الإرسال كلِّه */
        const val STALL_TIMEOUT_MS = 15_000L

        /** بعد آخر بايت: أطولُ انتظارٍ لإغلاق الطابعة قبل أن نُغلق نحن */
        const val TAIL_MS = 1_500L

        /** مخزنُ الإرسال في النظام — صغيرٌ ليكون تمامُ الكتابة تقدّمًا عند الطابعة */
        const val SEND_BUFFER_BYTES = 16 * 1024

        private const val WATCHDOG_TICK_MS = 250L

        private val WATCHDOG: ScheduledExecutorService = Executors.newSingleThreadScheduledExecutor { runnable ->
            Thread(runnable, "abaad-printer-watchdog").apply { isDaemon = true }
        }

        private fun clamp(value: Long): Int = value.coerceAtMost(Int.MAX_VALUE.toLong()).toInt()

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
