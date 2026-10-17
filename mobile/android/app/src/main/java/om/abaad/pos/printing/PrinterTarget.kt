package om.abaad.pos.printing

import java.math.BigDecimal
import java.math.BigInteger
import java.net.Inet4Address
import java.net.InetAddress
import java.net.UnknownHostException
import java.util.Locale

/**
 * هدفُ الطباعة كما يصل في الطلب، ويُفحص هنا كلُّه قبل أيّ تنزيلٍ أو اتّصال
 * (PROTOCOL §4.2–4.4 و§4.6).
 *
 * ═══ وما لا يفعله ═══
 *
 * لا يسأل DNS عن شيءٍ أثناء الفحص: IPv4 وIPv6 يُحلَّلان نصًّا بمحلّلٍ صارمٍ هنا،
 * واسمُ `.local` يُفحص شكلُه فقط. والحلُّ الفعليّ في `PrinterAddress.resolve`،
 * على خيط العمل، ثمّ يُفحص كلُّ عنوانٍ ناتج.
 */
enum class AddressKind { IPV4, IPV6, MDNS, INVALID }

/** هدفٌ اجتاز الفحص — والاتّصالُ بعده بهذه القيم وحدها */
class ValidatedPrinter(
    /** العنوانُ بعد القصّ — وبأحرفٍ صغيرة لـIPv6 وmDNS */
    val host: String,
    val kind: AddressKind,
    /** المنفذُ الفعليّ (9100 إن جاء null) — يُعاد في كلّ نتيجة */
    val port: Int,
    /** عرضُ الطباعة بالنقاط من `PaperProfile` */
    val dots: Int,
    /** بايتاتُ العنوان الحرفيّ (4 أو 16)، وnull لاسم mDNS */
    internal val literal: ByteArray?,
) {
    val endpoint: String get() = if (kind == AddressKind.IPV6) "[$host]:$port" else "$host:$port"
}

sealed class PrinterCheck {
    class Valid(val printer: ValidatedPrinter) : PrinterCheck()
    class Invalid(val code: ErrorCode, val effectivePort: Int?) : PrinterCheck()
}

object PrinterTarget {
    const val CONNECTION_NETWORK = "network"

    /**
     * الترتيبُ كما في §4: الاتّصال، ثم العنوان، ثم المنفذ. والقيمُ كما جاءت من
     * JSON — وnull الـJSON يصل هنا null.
     */
    fun validate(connection: Any?, address: Any?, port: Any?, paperWidth: Any?): PrinterCheck {
        val effectivePort = PrinterPort.effective(port)
        if (connection != CONNECTION_NETWORK) {
            return PrinterCheck.Invalid(ErrorCode.UNSUPPORTED_CONNECTION, effectivePort)
        }
        val text = (address as? String)?.trim()
            ?: return PrinterCheck.Invalid(ErrorCode.INVALID_PRINTER_ADDRESS, effectivePort)
        val kind = PrinterAddress.classify(text)
        if (kind == AddressKind.INVALID) {
            return PrinterCheck.Invalid(ErrorCode.INVALID_PRINTER_ADDRESS, effectivePort)
        }
        if (effectivePort == null) {
            return PrinterCheck.Invalid(ErrorCode.INVALID_PORT, null)
        }
        val host = if (kind == AddressKind.IPV4) text else text.lowercase(Locale.ROOT)
        val literal = when (kind) {
            AddressKind.IPV4 -> PrinterAddress.parseIpv4(host)
            AddressKind.IPV6 -> PrinterAddress.parseIpv6(host)
            else -> null
        }
        return PrinterCheck.Valid(
            ValidatedPrinter(host, kind, effectivePort, PaperProfile.dotsFor(paperWidth), literal),
        )
    }
}

object PrinterPort {
    /** المنفذُ الموثَّق للطباعة الخام حين لا يُذكر منفذ */
    const val DEFAULT = 9100

    /** null ← 9100؛ وإلّا عددٌ صحيح 1–65535. النصّ والمنطقيّ والكسريّ ← null (INVALID_PORT) */
    fun effective(value: Any?): Int? {
        if (value == null) return DEFAULT
        val port = JsonNumbers.integerOrNull(value) ?: return null
        return if (port in 1..65535) port else null
    }
}

/** أعدادُ JSON تصل بأنواعٍ مختلفة بين org.json في Android وفي JVM — والمعتبَرُ القيمة */
object JsonNumbers {
    fun integerOrNull(value: Any?): Int? = when (value) {
        is Int -> value
        is Long -> if (value >= Int.MIN_VALUE.toLong() && value <= Int.MAX_VALUE.toLong()) value.toInt() else null
        is Short -> value.toInt()
        is Byte -> value.toInt()
        is BigInteger -> if (value.bitLength() < 32) value.toInt() else null
        is BigDecimal -> try {
            value.intValueExact()
        } catch (e: ArithmeticException) {
            null
        }
        is Double -> integralDouble(value)
        is Float -> integralDouble(value.toDouble())
        else -> null
    }

    private fun integralDouble(value: Double): Int? {
        if (value.isNaN() || value.isInfinite()) return null
        if (value != Math.floor(value)) return null
        if (value < Int.MIN_VALUE.toDouble() || value > Int.MAX_VALUE.toDouble()) return null
        return value.toInt()
    }
}

object PrinterAddress {

    fun classify(raw: String?): AddressKind {
        val text = raw?.trim() ?: return AddressKind.INVALID
        if (text.isEmpty()) return AddressKind.INVALID

        val v4 = parseIpv4(text)
        if (v4 != null) return if (isPrivateIpv4(v4)) AddressKind.IPV4 else AddressKind.INVALID

        if (text.contains(':')) {
            val v6 = parseIpv6(text)
            return if (v6 != null && isPrivateIpv6(v6)) AddressKind.IPV6 else AddressKind.INVALID
        }

        return if (isMdnsName(text.lowercase(Locale.ROOT))) AddressKind.MDNS else AddressKind.INVALID
    }

    /** أربعةُ أجزاءٍ عشريّة، بلا أصفارٍ بادئة إلّا "0" نفسه، كلٌّ منها 0–255 */
    fun parseIpv4(text: String): ByteArray? {
        val parts = text.split('.')
        if (parts.size != 4) return null
        val out = ByteArray(4)
        for (i in 0 until 4) {
            val part = parts[i]
            if (part.isEmpty() || part.length > 3) return null
            for (c in part) {
                if (c < '0' || c > '9') return null
            }
            if (part.length > 1 && part[0] == '0') return null
            val value = part.toInt()
            if (value > 255) return null
            out[i] = value.toByte()
        }
        return out
    }

    /**
     * IPv6 حرفيّ بلا نطاق (`%`) ولا أقواس — محلّلٌ نصّيّ هنا، لأنّ
     * `InetAddress.getByName` على Android يسأل DNS عمّا لا يفهمه حرفيًّا.
     */
    fun parseIpv6(text: String): ByteArray? {
        if (text.isEmpty() || text.length > 45 || !text.contains(':')) return null
        for (c in text) {
            if (!isHex(c) && c != ':' && c != '.') return null
        }
        val gap = text.indexOf("::")
        if (gap != -1 && text.indexOf("::", gap + 1) != -1) return null

        val head: List<String>
        val tail: List<String>
        if (gap >= 0) {
            val before = text.substring(0, gap)
            val after = text.substring(gap + 2)
            head = if (before.isEmpty()) emptyList() else before.split(':')
            tail = if (after.isEmpty()) emptyList() else after.split(':')
        } else {
            head = text.split(':')
            tail = emptyList()
        }

        // IPv4 المضمَّن لا يكون إلّا آخرَ مجموعة في العنوان كلّه
        val headWords = words(head, ipv4Last = gap < 0) ?: return null
        val tailWords = words(tail, ipv4Last = true) ?: return null
        val count = headWords.size + tailWords.size

        val all = ArrayList<Int>(8)
        if (gap >= 0) {
            if (count > 7) return null
            all.addAll(headWords)
            repeat(8 - count) { all.add(0) }
            all.addAll(tailWords)
        } else {
            if (count != 8) return null
            all.addAll(headWords)
        }

        val bytes = ByteArray(16)
        for (i in 0 until 8) {
            bytes[2 * i] = (all[i] shr 8).toByte()
            bytes[2 * i + 1] = (all[i] and 0xFF).toByte()
        }
        return bytes
    }

    private fun words(groups: List<String>, ipv4Last: Boolean): List<Int>? {
        val out = ArrayList<Int>(8)
        for (i in groups.indices) {
            val group = groups[i]
            if (ipv4Last && i == groups.size - 1 && group.contains('.')) {
                val v4 = parseIpv4(group) ?: return null
                out.add(((v4[0].toInt() and 0xFF) shl 8) or (v4[1].toInt() and 0xFF))
                out.add(((v4[2].toInt() and 0xFF) shl 8) or (v4[3].toInt() and 0xFF))
                continue
            }
            if (group.isEmpty() || group.length > 4) return null
            for (c in group) {
                if (!isHex(c)) return null
            }
            out.add(group.toInt(16))
        }
        return out
    }

    private fun isHex(c: Char): Boolean =
        (c in '0'..'9') || (c in 'a'..'f') || (c in 'A'..'F')

    /** 10/8 و172.16/12 و192.168/16 و169.254/16 */
    fun isPrivateIpv4(bytes: ByteArray): Boolean {
        if (bytes.size != 4) return false
        val a = bytes[0].toInt() and 0xFF
        val b = bytes[1].toInt() and 0xFF
        return a == 10 ||
            (a == 172 && (b and 0xF0) == 16) ||
            (a == 192 && b == 168) ||
            (a == 169 && b == 254)
    }

    /** ULA ‏fc00::/7 أو link-local ‏fe80::/10 */
    fun isPrivateIpv6(bytes: ByteArray): Boolean {
        if (bytes.size != 16) return false
        val a = bytes[0].toInt() and 0xFF
        val b = bytes[1].toInt() and 0xFF
        return (a and 0xFE) == 0xFC || (a == 0xFE && (b and 0xC0) == 0x80)
    }

    fun isPrivate(address: InetAddress): Boolean {
        val bytes = address.address
        return when (bytes.size) {
            4 -> isPrivateIpv4(bytes)
            16 -> isPrivateIpv6(bytes)
            else -> false
        }
    }

    /** تسمياتٌ من `[a-z0-9-]`، غيرُ فارغة، لا تبدأ ولا تنتهي بـ`-`، وآخرُها `local` */
    fun isMdnsName(name: String): Boolean {
        if (name.length > 253 || !name.endsWith(".local")) return false
        val labels = name.split('.')
        if (labels.size < 2) return false
        for (label in labels) {
            if (label.isEmpty() || label.length > 63) return false
            if (label.startsWith("-") || label.endsWith("-")) return false
            for (c in label) {
                if (!((c in 'a'..'z') || (c in '0'..'9') || c == '-')) return false
            }
        }
        return true
    }

    sealed class Resolution {
        class Ok(val address: InetAddress) : Resolution()
        class Failed(val code: ErrorCode) : Resolution()
    }

    /**
     * العنوانُ الذي يُتّصل به. الحرفيّ يُبنى من بايتاته (لا DNS)، واسمُ mDNS
     * يُحلّ — ويجب أن يمرّ **كلُّ** عنوانٍ ناتج بالفحص الخاصّ، ثمّ يُتّصل
     * بالعنوان المفحوص لا بالاسم من جديد. يجري على خيط العمل.
     */
    fun resolve(
        printer: ValidatedPrinter,
        lookup: (String) -> Array<InetAddress> = { InetAddress.getAllByName(it) },
    ): Resolution {
        val literal = printer.literal
        if (literal != null) {
            return try {
                val address = InetAddress.getByAddress(literal)
                if (isPrivate(address)) Resolution.Ok(address) else Resolution.Failed(ErrorCode.INVALID_PRINTER_ADDRESS)
            } catch (e: UnknownHostException) {
                Resolution.Failed(ErrorCode.INVALID_PRINTER_ADDRESS)
            }
        }
        if (printer.kind != AddressKind.MDNS) return Resolution.Failed(ErrorCode.INVALID_PRINTER_ADDRESS)

        val found: Array<InetAddress> = try {
            lookup(printer.host)
        } catch (e: UnknownHostException) {
            return Resolution.Failed(ErrorCode.PRINTER_UNREACHABLE)
        } catch (e: SecurityException) {
            return Resolution.Failed(ErrorCode.PRINTER_UNREACHABLE)
        }
        if (found.isEmpty()) return Resolution.Failed(ErrorCode.PRINTER_UNREACHABLE)
        for (address in found) {
            if (!isPrivate(address)) return Resolution.Failed(ErrorCode.INVALID_PRINTER_ADDRESS)
        }
        val preferred = found.firstOrNull { it is Inet4Address } ?: found[0]
        return Resolution.Ok(preferred)
    }
}
