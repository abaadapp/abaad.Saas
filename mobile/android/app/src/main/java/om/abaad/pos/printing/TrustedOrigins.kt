package om.abaad.pos.printing

import java.net.URI
import java.net.URISyntaxException
import java.util.Locale

/**
 * الأصولُ الموثوقة — من إعداد البناء وحده، لا من الصفحة ولا من الخادم.
 *
 * ═══ صيغتان للأصل ═══
 *
 *   - **المقارنة:** `scheme://host:port` بأحرفٍ صغيرة والمنفذُ الافتراضيّ صريح،
 *     فـ`https://POS.example.test` و`https://pos.example.test:443` أصلٌ واحد.
 *   - **المتصفّح:** `scheme://host[:port]` بلا المنفذ الافتراضيّ — كما يكتبه
 *     `window.location.origin`، وكما تريده قواعدُ `allowedOriginRules`.
 *
 * ولا شيءَ هنا يلمس الشبكة: `java.net.URI` تحليلٌ نصّيّ فقط.
 */
class TrustedOrigins private constructor(private val list: List<Origin>) {

    private val canonical: Set<String> = list.map { it.canonical }.toSet()

    /** بصيغة المتصفّح — تُحقن في الـshim كما هي */
    val webOrigins: List<String> = list.map { it.web }.distinct()

    val isEmpty: Boolean get() = list.isEmpty()

    /** يقبل أصلًا أو رابطًا كاملًا */
    fun isTrusted(originOrUrl: String?): Boolean {
        val origin = originOf(originOrUrl, lenient = true) ?: return false
        return origin.canonical in canonical
    }

    /**
     * PROTOCOL §4.5: مطلق، بلا `user:pass@`، وأصلُه بعد التطبيع يساوي أصلَ
     * الصفحة، وأصلُ الصفحة موثوق. فـhttp لا يمرّ إلّا إن كان أصلُه هو نفسُه موثوقًا.
     */
    fun isReceiptUrlAllowed(pageOrigin: String?, receiptUrl: String?): Boolean {
        val page = originOf(pageOrigin, lenient = false) ?: return false
        if (page.canonical !in canonical) return false
        val receipt = originOf(receiptUrl, lenient = false) ?: return false
        return receipt.canonical == page.canonical
    }

    /** لـ`addWebMessageListener` و`addDocumentStartJavaScript`: بلا شَرطةٍ أخيرة ولا محارف بدل */
    fun allowedOriginRules(): Set<String> = webOrigins.toSet()

    internal class Origin(val scheme: String, val host: String, val port: Int) {
        val canonical: String get() = "$scheme://$host:$port"
        val web: String get() = if (port == TrustedOrigins.defaultPort(scheme)) "$scheme://$host" else "$scheme://$host:$port"
    }

    companion object {
        /** قائمةٌ مفصولةٌ بفواصل؛ وما لا يُحلَّل أصلًا يُهمل */
        fun parse(configured: String?): TrustedOrigins {
            val origins = (configured ?: "")
                .split(',')
                .map { it.trim() }
                .filter { it.isNotEmpty() }
                .mapNotNull { originOf(it, lenient = false) }
            return TrustedOrigins(origins)
        }

        /**
         * إن تُركت القائمةُ فارغةً في الإعداد فأصلُ `baseUrl` وحده.
         * أمّا قائمةٌ مكتوبةٌ لا يصحّ منها شيء فتبقى فارغة — فلا يُوسَّع خطأُ إعدادٍ بصمت.
         */
        fun fromConfig(configured: String?, baseUrl: String?): TrustedOrigins {
            if (!configured.isNullOrBlank()) return parse(configured)
            return parse(baseUrl)
        }

        /** `scheme://host:port` للمقارنة، أو null لما ليس http(s) أو فيه `user@` */
        fun normalizeOrigin(url: String?): String? = originOf(url, lenient = true)?.canonical

        internal fun originOf(url: String?, lenient: Boolean): Origin? {
            if (url.isNullOrEmpty()) return null
            val uri = toUri(url, lenient) ?: return null
            val scheme = uri.scheme?.lowercase(Locale.ROOT) ?: return null
            if (scheme != "https" && scheme != "http") return null
            if (uri.rawUserInfo != null) return null
            val host = uri.host?.lowercase(Locale.ROOT)
            if (host.isNullOrEmpty()) return null
            val port = if (uri.port == -1) defaultPort(scheme) else uri.port
            if (port < 1 || port > 65535) return null
            return Origin(scheme, host, port)
        }

        internal fun defaultPort(scheme: String): Int = if (scheme == "https") 443 else 80

        private fun toUri(url: String, lenient: Boolean): URI? {
            try {
                return URI(url)
            } catch (e: URISyntaxException) {
                if (!lenient) return null
            }
            // للتنقّل وحده: رابطٌ موثوقٌ في مساره محرفٌ لا تقبله URI يبقى موثوقًا،
            // فيُحلَّل `scheme://authority` وحده. ورابطُ الإيصال لا يمرّ من هنا.
            val separator = url.indexOf("://")
            if (separator <= 0) return null
            var end = url.length
            for (i in separator + 3 until url.length) {
                val c = url[i]
                if (c == '/' || c == '?' || c == '#') {
                    end = i
                    break
                }
            }
            return try {
                URI(url.substring(0, end))
            } catch (e: URISyntaxException) {
                null
            }
        }
    }
}
