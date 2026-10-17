package om.abaad.pos.printing

import android.app.Activity
import android.content.Context
import android.os.Bundle
import android.os.CancellationSignal
import android.os.Handler
import android.os.Looper
import android.os.ParcelFileDescriptor
import android.print.PageRange
import android.print.PrintAttributes
import android.print.PrintDocumentAdapter
import android.print.PrintDocumentInfo
import android.print.PrintManager
import java.io.FileOutputStream
import java.io.IOException
import java.util.concurrent.ExecutorService
import java.util.concurrent.Executors
import java.util.concurrent.RejectedExecutionException

/**
 * PDF ← حوارُ الطباعة/المعاينة في النظام (`PrintManager`).
 *
 * بديلُ «فتح PDF» داخل التطبيق: النوافذُ المنبثقة لا تعمل في الـWebView. فيستعمله
 * `openReceipt` في الجسر، ويستعمله التطبيقُ لكلّ `window.open` أو تنزيلٍ إلى أصلٍ
 * موثوق: يُنزَّل بالجلسة، فإن كان PDF فإلى الحوار، وإلّا فإلى `fallback`.
 */
class ReceiptOpener(
    private val activity: Activity,
    private val cookies: (String) -> String?,
    private val downloader: ReceiptDownloader = ReceiptDownloader(),
) {
    private val main = Handler(Looper.getMainLooper())
    private val worker: ExecutorService = Executors.newSingleThreadExecutor { runnable ->
        Thread(runnable, "abaad-document-open").apply { isDaemon = true }
    }

    /** على خيط الواجهة. false إن لم يُعرض الحوار */
    fun showPdf(pdf: ByteArray, jobName: String): Boolean {
        if (activity.isFinishing || activity.isDestroyed) return false
        val manager = activity.getSystemService(Context.PRINT_SERVICE) as? PrintManager ?: return false
        return try {
            val name = safeJobName(jobName)
            manager.print(name, PdfBytesAdapter(pdf, name), PrintAttributes.Builder().build())
            true
        } catch (e: RuntimeException) {
            PrintLog.warn("print dialog failed", e)
            false
        }
    }

    /**
     * رابطٌ موثوق فُتح في نافذةٍ جديدة أو كتنزيل: يُنزَّل بالجلسة على خيطٍ خلفيّ،
     * فإن كان PDF عُرض في حوار الطباعة، وإلّا سُلّم إلى `fallback` على خيط الواجهة.
     */
    fun openDocument(url: String, fallback: (String) -> Unit) {
        try {
            worker.execute {
                val result = downloader.download(url) { cookies(url) }
                main.post {
                    if (result is ReceiptDownloader.Result.Ok && showPdf(result.bytes, documentName(url))) {
                        PrintLog.info("document opened in print dialog path=${PrintLog.pathOf(url)}")
                    } else {
                        fallback(url)
                    }
                }
            }
        } catch (e: RejectedExecutionException) {
            fallback(url)
        }
    }

    fun shutdown() {
        worker.shutdownNow()
    }

    private fun documentName(url: String): String {
        val path = PrintLog.pathOf(url).trimEnd('/')
        val last = path.substringAfterLast('/')
        return if (last.isEmpty()) "abaad-document" else "abaad-$last"
    }

    /** يُعرض في قائمة مهامّ الطباعة — أحرفٌ آمنة فقط، وطولٌ محدود */
    private fun safeJobName(name: String): String {
        val cleaned = StringBuilder()
        for (c in name) {
            cleaned.append(if (c.isLetterOrDigit() || c == '-' || c == '_' || c == '.') c else '-')
            if (cleaned.length >= 80) break
        }
        return if (cleaned.isEmpty()) "abaad-receipt" else cleaned.toString()
    }

    /** ينسخ بايتاتِ الـPDF كما هي إلى ما يطلبه نظامُ الطباعة */
    private class PdfBytesAdapter(private val pdf: ByteArray, private val name: String) : PrintDocumentAdapter() {

        override fun onLayout(
            oldAttributes: PrintAttributes?,
            newAttributes: PrintAttributes?,
            cancellationSignal: CancellationSignal?,
            callback: PrintDocumentAdapter.LayoutResultCallback?,
            extras: Bundle?,
        ) {
            if (callback == null) return
            if (cancellationSignal?.isCanceled == true) {
                callback.onLayoutCancelled()
                return
            }
            val info = PrintDocumentInfo.Builder("$name.pdf")
                .setContentType(PrintDocumentInfo.CONTENT_TYPE_DOCUMENT)
                .setPageCount(PrintDocumentInfo.PAGE_COUNT_UNKNOWN)
                .build()
            callback.onLayoutFinished(info, oldAttributes != newAttributes)
        }

        override fun onWrite(
            pages: Array<out PageRange>?,
            destination: ParcelFileDescriptor?,
            cancellationSignal: CancellationSignal?,
            callback: PrintDocumentAdapter.WriteResultCallback?,
        ) {
            if (callback == null) return
            if (destination == null) {
                callback.onWriteFailed(null)
                return
            }
            if (cancellationSignal?.isCanceled == true) {
                callback.onWriteCancelled()
                return
            }
            try {
                FileOutputStream(destination.fileDescriptor).use { it.write(pdf) }
                callback.onWriteFinished(arrayOf(PageRange.ALL_PAGES))
            } catch (e: IOException) {
                callback.onWriteFailed(null)
            }
        }
    }
}
