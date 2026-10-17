package om.abaad.pos.printing

import android.content.Context
import android.net.Uri
import android.os.Handler
import android.os.Looper
import android.os.SystemClock
import android.webkit.CookieManager
import android.webkit.WebView
import androidx.webkit.JavaScriptReplyProxy
import androidx.webkit.WebMessageCompat
import androidx.webkit.WebViewCompat
import androidx.webkit.WebViewFeature
import org.json.JSONArray

/**
 * الجسرُ بين `window.AbaadPrinter` (الـshim) والطابعة — PROTOCOL كاملًا.
 *
 *   - يُحقن الـshim عند بدء المستند في الأصول الموثوقة وحدها
 *     (`addDocumentStartJavaScript`)، ومعه سطرٌ يضع `__abaadPrinterConfig`.
 *   - الطلباتُ تصل عبر `addWebMessageListener("AbaadPrinterNative")` على خيط الواجهة،
 *     وتُفحص كلُّها (`JobValidator`) قبل أن يدخل شيءٌ الطابور.
 *   - العملُ (تنزيل، رسم، اتّصال، إرسال) على خيط الطابور، وكلُّ ردٍّ يعود على خيط الواجهة.
 *   - لكلّ `id` نتيجةٌ واحدة بالضبط.
 *
 * ═══ وما لا يفعله ═══
 *
 * لا يقبل بايتاتٍ ولا أوامرَ ولا عنوانَ مقبسٍ خامًا من الصفحة: الطلبُ مُهيكل،
 * والتحقّقُ هنا من جديد مهما فحص الـshim قبله.
 */
class AbaadPrinterBridge(
    context: Context,
    private val webView: WebView,
    private val origins: TrustedOrigins,
    private val opener: ReceiptOpener,
) {
    private val appContext: Context = context.applicationContext
    private val main = Handler(Looper.getMainLooper())
    private val printQueue = PrintJobQueue("abaad-printer")
    private val openQueue = PrintJobQueue("abaad-receipt-open")
    private val downloader = ReceiptDownloader()
    private val driver = NetworkPrinterDriver()
    private val renderer = PdfReceiptRenderer(appContext.cacheDir)

    @Volatile
    private var destroyed = false

    /** على خيط الواجهة، قبل `loadUrl`. false = بلا جسر، والصفحةُ تستعمل بديلَ المتصفّح */
    fun install(): Boolean {
        if (!isSupported()) return false
        val rules = origins.allowedOriginRules()
        if (rules.isEmpty()) return false
        val shim = loadShim() ?: return false
        return try {
            WebViewCompat.addWebMessageListener(
                webView,
                NATIVE_OBJECT,
                rules,
                WebViewCompat.WebMessageListener { view, message, sourceOrigin, isMainFrame, replyProxy ->
                    onMessage(view, message, sourceOrigin, isMainFrame, replyProxy)
                },
            )
            WebViewCompat.addDocumentStartJavaScript(webView, prelude() + shim, rules)
            PrintLog.info("bridge installed for ${rules.size} origin(s)")
            true
        } catch (e: RuntimeException) {
            PrintLog.warn("bridge not installed", e)
            false
        }
    }

    fun shutdown() {
        destroyed = true
        printQueue.shutdown()
        openQueue.shutdown()
    }

    /**
     * يُعرَّف `post` و`listen` على الكائن كما وُجد عند بدء المستند — فلا تبدّله
     * الصفحةُ بعدها. وإن غاب الكائن لا يوضع إعداد، فلا يظهر `window.AbaadPrinter`.
     */
    private fun prelude(): String {
        val trusted = JSONArray(origins.webOrigins).toString()
        return "(function () {\n" +
            "  if (typeof $NATIVE_OBJECT === 'undefined') return;\n" +
            "  var channel = $NATIVE_OBJECT;\n" +
            "  window.__abaadPrinterConfig = {\n" +
            "    platform: 'android',\n" +
            "    trustedOrigins: $trusted,\n" +
            "    post: function (s) { channel.postMessage(s); },\n" +
            "    listen: function (r) { channel.onmessage = function (e) { r(e.data); }; }\n" +
            "  };\n" +
            "})();\n"
    }

    private fun loadShim(): String? =
        try {
            appContext.assets.open(SHIM_ASSET).bufferedReader(Charsets.UTF_8).use { it.readText() }
        } catch (e: Exception) {
            PrintLog.warn("shim asset missing", e)
            null
        }

    // ─── الطلب: على خيط الواجهة ───

    private fun onMessage(
        view: WebView,
        message: WebMessageCompat,
        sourceOrigin: Uri,
        isMainFrame: Boolean,
        replyProxy: JavaScriptReplyProxy,
    ) {
        val startedAt = SystemClock.elapsedRealtime()
        val raw: String? = try {
            message.data
        } catch (e: RuntimeException) {
            null // ليست رسالةً نصّيّة
        }

        when (val outcome = JobValidator.validate(raw, isMainFrame, sourceOrigin.toString(), view.url, origins)) {
            is JobValidator.Outcome.Drop -> PrintLog.info("message dropped: ${outcome.reason}")
            is JobValidator.Outcome.Rejected -> finish(
                replyProxy,
                outcome.id,
                outcome.method,
                BridgeResult.failure(outcome.id, outcome.code, effectivePort = outcome.effectivePort),
                startedAt,
            )
            is JobValidator.Outcome.Accepted -> dispatch(outcome.id, outcome.job, replyProxy, startedAt)
        }
    }

    private fun dispatch(id: String, job: ValidatedJob, proxy: JavaScriptReplyProxy, startedAt: Long) {
        val accepted = when (job) {
            is ValidatedJob.PrintReceipt -> printQueue.submit { runSafely(id, job, proxy, startedAt) { printReceipt(id, job, proxy) } }
            is ValidatedJob.TestPrinter -> printQueue.submit { runSafely(id, job, proxy, startedAt) { testPrinter(id, job, proxy) } }
            is ValidatedJob.OpenReceipt -> openQueue.submit { openReceipt(id, job, proxy, startedAt) }
        }
        if (!accepted) {
            finish(proxy, id, job.method, BridgeResult.failure(id, ErrorCode.QUEUE_FULL, effectivePort = portOf(job)), startedAt)
        }
    }

    // ─── العمل: على خيط الطابور ───

    /** ما أفلت من المهمّة يصير `INTERNAL` — فلا يبقى طلبٌ بلا نتيجة */
    private fun runSafely(
        id: String,
        job: ValidatedJob,
        proxy: JavaScriptReplyProxy,
        startedAt: Long,
        work: () -> Done,
    ) {
        val done: Done = try {
            work()
        } catch (e: Throwable) {
            PrintLog.warn("job=$id failed unexpectedly", e)
            Done(BridgeResult.failure(id, ErrorCode.INTERNAL, effectivePort = portOf(job)))
        }
        finish(proxy, id, job.method, done.result, startedAt, done.endpoint, done.bytes, done.status)
    }

    /** نتيجةُ المهمّة ومعها ما يُسجَّل عنها */
    private class Done(
        val result: BridgeResult,
        val endpoint: String? = null,
        val bytes: Int? = null,
        val status: Int? = null,
    )

    private fun printReceipt(id: String, job: ValidatedJob.PrintReceipt, proxy: JavaScriptReplyProxy): Done {
        val printer = job.printer
        val endpoint = printer.endpoint

        // اسمُ `.local` يُحلّ ويُفحص قبل أيّ تنزيل (§4.3)
        val address = when (val resolved = PrinterAddress.resolve(printer)) {
            is PrinterAddress.Resolution.Ok -> resolved.address
            is PrinterAddress.Resolution.Failed ->
                return Done(BridgeResult.failure(id, resolved.code, effectivePort = printer.port), endpoint)
        }

        progress(proxy, id, job.method, Stage.DOWNLOADING_RECEIPT)
        PrintLog.download(id, job.receiptUrl)
        val pdf = when (val download = downloader.download(job.receiptUrl) { cookieFor(job.receiptUrl) }) {
            is ReceiptDownloader.Result.Ok -> download.bytes
            is ReceiptDownloader.Result.Failed -> return Done(
                BridgeResult.failure(id, download.code, effectivePort = printer.port),
                endpoint,
                status = download.status,
            )
        }

        progress(proxy, id, job.method, Stage.RENDERING)
        val image = try {
            renderer.render(pdf, printer.dots)
        } catch (e: Exception) {
            PrintLog.warn("job=$id render failed", e)
            return Done(BridgeResult.failure(id, ErrorCode.RECEIPT_RENDER_FAILED, effectivePort = printer.port), endpoint)
        }

        return send(id, job.method, proxy, address, printer, EscPosEncoder.encode(image))
    }

    private fun testPrinter(id: String, job: ValidatedJob.TestPrinter, proxy: JavaScriptReplyProxy): Done {
        val printer = job.printer
        val endpoint = printer.endpoint

        val address = when (val resolved = PrinterAddress.resolve(printer)) {
            is PrinterAddress.Resolution.Ok -> resolved.address
            is PrinterAddress.Resolution.Failed ->
                return Done(BridgeResult.failure(id, resolved.code, effectivePort = printer.port), endpoint)
        }

        // لا مرحلةَ «rendering» هنا (§6): فحصٌ، ثم اتّصال، ثم إرسال
        val image = try {
            TestReceiptRenderer.render(printer.dots, job.deviceName, job.printerName, printer.host, printer.port)
        } catch (e: Exception) {
            PrintLog.warn("job=$id test page failed", e)
            return Done(BridgeResult.failure(id, ErrorCode.RECEIPT_RENDER_FAILED, effectivePort = printer.port), endpoint)
        }

        return send(id, job.method, proxy, address, printer, EscPosEncoder.encode(image))
    }

    private fun send(
        id: String,
        method: Method,
        proxy: JavaScriptReplyProxy,
        address: java.net.InetAddress,
        printer: ValidatedPrinter,
        bytes: ByteArray,
    ): Done {
        val result = driver.send(address, printer.port, bytes, onStage = { stage -> progress(proxy, id, method, stage) })
        val bridgeResult = when (result) {
            is NetworkPrinterDriver.Result.Sent ->
                BridgeResult.success(id, printed = true, connected = true, effectivePort = printer.port)
            is NetworkPrinterDriver.Result.Failed ->
                BridgeResult.failure(
                    id,
                    result.code,
                    connected = result.connected,
                    mayHavePrinted = result.mayHavePrinted,
                    effectivePort = printer.port,
                )
        }
        return Done(bridgeResult, printer.endpoint, result.bytesWritten)
    }

    private fun openReceipt(id: String, job: ValidatedJob.OpenReceipt, proxy: JavaScriptReplyProxy, startedAt: Long) {
        try {
            PrintLog.download(id, job.receiptUrl)
            when (val download = downloader.download(job.receiptUrl) { cookieFor(job.receiptUrl) }) {
                is ReceiptDownloader.Result.Failed -> finish(
                    proxy,
                    id,
                    job.method,
                    BridgeResult.failure(id, download.code),
                    startedAt,
                    status = download.status,
                )
                is ReceiptDownloader.Result.Ok -> main.post {
                    // الحوارُ على خيط الواجهة، والنتيجةُ بعد عرضه
                    val shown = !destroyed && opener.showPdf(download.bytes, "receipt-${job.invoice}")
                    val result = if (shown) BridgeResult.success(id) else BridgeResult.failure(id, ErrorCode.INTERNAL)
                    finish(proxy, id, job.method, result, startedAt)
                }
            }
        } catch (e: Throwable) {
            PrintLog.warn("job=$id open failed unexpectedly", e)
            finish(proxy, id, job.method, BridgeResult.failure(id, ErrorCode.INTERNAL), startedAt)
        }
    }

    // ─── الردود: على خيط الواجهة ───

    private fun progress(proxy: JavaScriptReplyProxy, id: String, method: Method, stage: Stage) {
        PrintLog.stage(id, method, stage)
        deliver(proxy, BridgeMessages.progress(id, stage))
    }

    private fun finish(
        proxy: JavaScriptReplyProxy,
        id: String,
        method: Method?,
        result: BridgeResult,
        startedAt: Long,
        endpoint: String? = null,
        bytes: Int? = null,
        status: Int? = null,
    ) {
        PrintLog.result(id, method, result.code, SystemClock.elapsedRealtime() - startedAt, endpoint, bytes, status)
        deliver(proxy, BridgeMessages.result(id, result))
    }

    private fun deliver(proxy: JavaScriptReplyProxy, json: String) {
        main.post {
            if (!destroyed) {
                try {
                    proxy.postMessage(json)
                } catch (e: RuntimeException) {
                    // الصفحةُ رحلت — لا أحدَ ينتظر الردّ
                    PrintLog.warn("reply not delivered", e)
                }
            }
        }
    }

    private fun cookieFor(url: String): String? =
        try {
            CookieManager.getInstance().getCookie(url)
        } catch (e: RuntimeException) {
            null
        }

    private fun portOf(job: ValidatedJob): Int? = when (job) {
        is ValidatedJob.PrintReceipt -> job.printer.port
        is ValidatedJob.TestPrinter -> job.printer.port
        is ValidatedJob.OpenReceipt -> null
    }

    companion object {
        const val NATIVE_OBJECT = "AbaadPrinterNative"
        const val SHIM_ASSET = "abaad-printer-shim.js"

        fun isSupported(): Boolean =
            WebViewFeature.isFeatureSupported(WebViewFeature.WEB_MESSAGE_LISTENER) &&
                WebViewFeature.isFeatureSupported(WebViewFeature.DOCUMENT_START_SCRIPT)
    }
}
