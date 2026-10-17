package om.abaad.pos

import android.annotation.SuppressLint
import android.app.DownloadManager
import android.content.ActivityNotFoundException
import android.content.Context
import android.content.Intent
import android.content.res.Configuration
import android.graphics.Bitmap
import android.net.Uri
import android.os.Build
import android.os.Bundle
import android.os.Environment
import android.os.Handler
import android.os.Looper
import android.os.Message
import android.view.Gravity
import android.view.ViewGroup
import android.webkit.CookieManager
import android.webkit.URLUtil
import android.webkit.ValueCallback
import android.webkit.WebChromeClient
import android.webkit.WebResourceRequest
import android.webkit.WebSettings
import android.webkit.WebView
import android.webkit.WebViewClient
import android.widget.FrameLayout
import android.widget.TextView
import android.widget.Toast
import androidx.activity.OnBackPressedCallback
import androidx.activity.result.ActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.appcompat.app.AppCompatActivity
import androidx.core.view.ViewCompat
import androidx.core.view.WindowCompat
import androidx.core.view.WindowInsetsCompat
import om.abaad.pos.printing.AbaadPrinterBridge
import om.abaad.pos.printing.PrintLog
import om.abaad.pos.printing.ReceiptOpener
import java.util.Locale

/**
 * صدفةُ أبعاد: WebView واحدة على `abaad.baseUrl`، وجسرُ الطابعة فيها (PROTOCOL §10).
 *
 *   - **أصلٌ موثوق** يُحمَّل داخل الـWebView.
 *   - **أيُّ رابطٍ آخر** يُفتح في المتصفّح الخارجيّ، ولا يُحمَّل في الـWebView أبدًا.
 *   - **`window.open` أو `target=_blank`** إلى أصلٍ موثوق: يُنزَّل بالجلسة نفسها،
 *     فإن كان PDF فإلى حوار طباعة النظام كما في `openReceipt`، وإلّا يُحمَّل في الـWebView.
 */
class MainActivity : AppCompatActivity() {

    private val main = Handler(Looper.getMainLooper())
    private var webView: WebView? = null
    private var bridge: AbaadPrinterBridge? = null
    private var opener: ReceiptOpener? = null
    private val popups = ArrayList<WebView>()
    private var fileCallback: ValueCallback<Array<Uri>>? = null

    private val fileChooser = registerForActivityResult(ActivityResultContracts.StartActivityForResult()) { result: ActivityResult ->
        val callback = fileCallback
        fileCallback = null
        callback?.onReceiveValue(WebChromeClient.FileChooserParams.parseResult(result.resultCode, result.data))
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)

        // targetSdk 35 يفرض الرسم تحت الأشرطة — فتُزاح الـWebView عنها وعن لوحة المفاتيح بالحواشي
        WindowCompat.setDecorFitsSystemWindows(window, false)
        applySystemBarAppearance(resources.configuration)
        val container = FrameLayout(this)
        ViewCompat.setOnApplyWindowInsetsListener(container) { v, insets ->
            val bars = insets.getInsets(
                WindowInsetsCompat.Type.systemBars() or
                    WindowInsetsCompat.Type.displayCutout() or
                    WindowInsetsCompat.Type.ime(),
            )
            v.setPadding(bars.left, bars.top, bars.right, bars.bottom)
            WindowInsetsCompat.CONSUMED
        }
        setContentView(container)

        onBackPressedDispatcher.addCallback(
            this,
            object : OnBackPressedCallback(true) {
                override fun handleOnBackPressed() {
                    val current = webView
                    if (current != null && current.canGoBack()) current.goBack() else this@MainActivity.finish()
                }
            },
        )

        if (!AppConfig.isBaseUrlTrusted) {
            val text = if (AppConfig.hasBaseUrl) R.string.config_untrusted else R.string.config_missing
            container.addView(messageView(getString(text)), matchParent())
            return
        }

        val view = createWebView()
        container.addView(view, matchParent())
        webView = view

        val documentOpener = ReceiptOpener(this, cookies = { url -> cookieFor(url) })
        opener = documentOpener

        // الجسرُ قبل loadUrl، ليُحقن الـshim في المستند الأوّل نفسه. وبلا الميزتين
        // لا جسر — والصفحةُ تعود إلى طباعة المتصفّح وحدها.
        if (AbaadPrinterBridge.isSupported()) {
            val printerBridge = AbaadPrinterBridge(this, view, AppConfig.trustedOrigins, documentOpener)
            if (printerBridge.install()) {
                bridge = printerBridge
            } else {
                printerBridge.shutdown()
            }
        } else {
            PrintLog.info("WebView lacks WEB_MESSAGE_LISTENER or DOCUMENT_START_SCRIPT: no printer bridge")
        }

        view.loadUrl(AppConfig.baseUrl)
    }

    override fun onResume() {
        super.onResume()
        webView?.onResume()
    }

    override fun onPause() {
        if (webView != null) CookieManager.getInstance().flush()
        webView?.onPause()
        super.onPause()
    }

    override fun onConfigurationChanged(newConfig: Configuration) {
        super.onConfigurationChanged(newConfig)
        applySystemBarAppearance(newConfig)
    }

    override fun onDestroy() {
        bridge?.shutdown()
        bridge = null
        opener?.shutdown()
        opener = null
        fileCallback?.onReceiveValue(null)
        fileCallback = null
        for (popup in ArrayList(popups)) popup.destroy()
        popups.clear()
        webView?.let { view ->
            (view.parent as? ViewGroup)?.removeView(view)
            view.destroy()
        }
        webView = null
        super.onDestroy()
    }

    // ─── الـWebView ───

    @SuppressLint("SetJavaScriptEnabled")
    private fun createWebView(): WebView {
        WebView.setWebContentsDebuggingEnabled(BuildConfig.DEBUG)
        val view = WebView(this)
        view.settings.apply {
            javaScriptEnabled = true
            domStorageEnabled = true
            allowFileAccess = false
            allowContentAccess = false
            setSupportMultipleWindows(true)
            javaScriptCanOpenWindowsAutomatically = false
            mixedContentMode = WebSettings.MIXED_CONTENT_NEVER_ALLOW
        }
        val cookies = CookieManager.getInstance()
        cookies.setAcceptCookie(true)
        cookies.setAcceptThirdPartyCookies(view, false)

        view.webViewClient = ShellClient()
        view.webChromeClient = ShellChromeClient()
        view.setDownloadListener { url, userAgent, contentDisposition, mimeType, _ ->
            onDownload(url, userAgent, contentDisposition, mimeType)
        }
        return view
    }

    private inner class ShellClient : WebViewClient() {
        override fun shouldOverrideUrlLoading(view: WebView?, request: WebResourceRequest?): Boolean {
            if (request == null || !request.isForMainFrame) return false
            return !keepInside(request.url.toString())
        }

        override fun onPageStarted(view: WebView?, url: String?, favicon: Bitmap?) {
            super.onPageStarted(view, url, favicon)
            // ما لا يمرّ بـshouldOverrideUrlLoading (نموذجُ POST مثلًا) يُوقَف هنا
            if (view == null || url == null || !isWebUrl(url) || AppConfig.trustedOrigins.isTrusted(url)) return
            view.stopLoading()
            openExternally(url)
            if (view.canGoBack()) view.goBack() else view.loadUrl(AppConfig.baseUrl)
        }
    }

    private inner class ShellChromeClient : WebChromeClient() {
        /** `window.open` و`target=_blank`: نافذةٌ عابرة تلتقط أوّلَ رابطٍ ثم تُهدم */
        override fun onCreateWindow(view: WebView?, isDialog: Boolean, isUserGesture: Boolean, resultMsg: Message?): Boolean {
            val message = resultMsg ?: return false
            val transport = message.obj as? WebView.WebViewTransport ?: return false
            transport.webView = createPopup()
            message.sendToTarget()
            return true
        }

        override fun onShowFileChooser(
            view: WebView?,
            filePathCallback: ValueCallback<Array<Uri>>?,
            fileChooserParams: WebChromeClient.FileChooserParams?,
        ): Boolean {
            if (filePathCallback == null || fileChooserParams == null) return false
            fileCallback?.onReceiveValue(null)
            fileCallback = filePathCallback
            return try {
                fileChooser.launch(fileChooserParams.createIntent())
                true
            } catch (e: ActivityNotFoundException) {
                fileCallback = null
                false
            }
        }
    }

    /**
     * النافذةُ الجديدة لا تُعرض: تنتظر أوّلَ رابطٍ حقيقيّ — وقد يأتي بعد فتحها فارغةً
     * ثم توجيهِها من الصفحة — فتلتقطه وتُهدم، ويُفتح الرابطُ بقواعد الصدفة.
     */
    private fun createPopup(): WebView {
        val popup = WebView(this)
        popups.add(popup)
        popup.webViewClient = object : WebViewClient() {
            private var captured = false

            override fun shouldOverrideUrlLoading(view: WebView?, request: WebResourceRequest?): Boolean {
                val url = request?.url?.toString() ?: return true
                if (url == BLANK) return false
                capture(url)
                return true
            }

            override fun onPageStarted(view: WebView?, url: String?, favicon: Bitmap?) {
                if (url == null || url == BLANK) return
                view?.stopLoading()
                capture(url)
            }

            private fun capture(url: String) {
                if (captured) return
                captured = true
                closePopup(popup)
                openFromNewWindow(url)
            }
        }
        popup.webChromeClient = object : WebChromeClient() {
            override fun onCloseWindow(window: WebView?) {
                closePopup(popup)
            }
        }
        return popup
    }

    private fun closePopup(popup: WebView) {
        if (!popups.remove(popup)) return
        // لا تُهدم الـWebView من داخل نداءٍ منها
        main.post { popup.destroy() }
    }

    private fun openFromNewWindow(url: String) {
        if (!isWebUrl(url) || !AppConfig.trustedOrigins.isTrusted(url)) {
            sendOutside(url)
            return
        }
        val documentOpener = opener
        if (documentOpener == null) {
            webView?.loadUrl(url)
            return
        }
        documentOpener.openDocument(url) { notPdf -> webView?.loadUrl(notPdf) }
    }

    // ─── التنزيلات ───

    private fun onDownload(url: String?, userAgent: String?, contentDisposition: String?, mimeType: String?) {
        if (url.isNullOrEmpty()) return
        if (!isWebUrl(url)) {
            // blob: وdata: لا يصل إليهما مديرُ التنزيلات
            toast(R.string.download_failed)
            return
        }
        if (!AppConfig.trustedOrigins.isTrusted(url)) {
            openExternally(url)
            return
        }
        val fileName = URLUtil.guessFileName(url, contentDisposition, mimeType)
        val isPdf = mimeType?.contains("pdf", ignoreCase = true) == true || fileName.endsWith(".pdf", ignoreCase = true)
        val documentOpener = opener
        if (isPdf && documentOpener != null) {
            documentOpener.openDocument(url) { notPdf -> enqueueDownload(notPdf, userAgent, mimeType, fileName) }
            return
        }
        enqueueDownload(url, userAgent, mimeType, fileName)
    }

    private fun enqueueDownload(url: String, userAgent: String?, mimeType: String?, fileName: String) {
        try {
            val request = DownloadManager.Request(Uri.parse(url))
            val cookie = cookieFor(url)
            if (!cookie.isNullOrEmpty()) request.addRequestHeader("Cookie", cookie)
            if (!userAgent.isNullOrEmpty()) request.addRequestHeader("User-Agent", userAgent)
            if (!mimeType.isNullOrEmpty()) request.setMimeType(mimeType)
            request.setTitle(fileName)
            request.setNotificationVisibility(DownloadManager.Request.VISIBILITY_VISIBLE_NOTIFY_COMPLETED)
            // قبل Android 10 يحتاج المجلّدُ العامّ إذنَ تخزين — فيبقى في مكان مديرِ التنزيلات
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q) {
                request.setDestinationInExternalPublicDir(Environment.DIRECTORY_DOWNLOADS, fileName)
            }
            val manager = getSystemService(Context.DOWNLOAD_SERVICE) as DownloadManager
            manager.enqueue(request)
            toast(R.string.download_started)
        } catch (e: RuntimeException) {
            PrintLog.warn("download not started", e)
            toast(R.string.download_failed)
        }
    }

    // ─── التنقّل ───

    /** true = يبقى في الـWebView. وما سواه يُفتح خارجها أو يُهمل */
    private fun keepInside(url: String): Boolean {
        if (isWebUrl(url) && AppConfig.trustedOrigins.isTrusted(url)) return true
        sendOutside(url)
        return false
    }

    private fun sendOutside(url: String) {
        val scheme = schemeOf(url)
        if (scheme == null || scheme in NEVER_EXTERNAL) return
        openExternally(url)
    }

    private fun openExternally(url: String) {
        try {
            val intent = Intent(Intent.ACTION_VIEW, Uri.parse(url))
            intent.addCategory(Intent.CATEGORY_BROWSABLE)
            startActivity(intent)
        } catch (e: ActivityNotFoundException) {
            toast(R.string.link_unsupported)
        } catch (e: SecurityException) {
            toast(R.string.link_unsupported)
        }
    }

    private fun cookieFor(url: String): String? =
        try {
            CookieManager.getInstance().getCookie(url)
        } catch (e: RuntimeException) {
            null
        }

    // ─── الشاشة ───

    private fun applySystemBarAppearance(configuration: Configuration) {
        val night = (configuration.uiMode and Configuration.UI_MODE_NIGHT_MASK) == Configuration.UI_MODE_NIGHT_YES
        val controller = WindowCompat.getInsetsController(window, window.decorView)
        controller.isAppearanceLightStatusBars = !night
        controller.isAppearanceLightNavigationBars = !night
    }

    private fun messageView(text: String): TextView {
        val view = TextView(this)
        view.text = text
        view.gravity = Gravity.CENTER
        view.textSize = 18f
        val padding = (24 * resources.displayMetrics.density).toInt()
        view.setPadding(padding, padding, padding, padding)
        return view
    }

    private fun matchParent(): FrameLayout.LayoutParams =
        FrameLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.MATCH_PARENT)

    private fun toast(message: Int) {
        Toast.makeText(this, message, Toast.LENGTH_SHORT).show()
    }

    private companion object {
        const val BLANK = "about:blank"

        /** مخطّطاتٌ لا تُسلَّم لتطبيقٍ آخر ولا تُحمَّل — تُهمل */
        val NEVER_EXTERNAL = setOf("javascript", "file", "content", "data", "blob", "about")

        fun schemeOf(url: String): String? = Uri.parse(url).scheme?.lowercase(Locale.ROOT)

        fun isWebUrl(url: String): Boolean {
            val scheme = schemeOf(url)
            return scheme == "https" || scheme == "http"
        }
    }
}
