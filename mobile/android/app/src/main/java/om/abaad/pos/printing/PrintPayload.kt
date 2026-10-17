package om.abaad.pos.printing

import java.io.ByteArrayInputStream
import java.io.File
import java.io.FileInputStream
import java.io.InputStream

/**
 * ما يُرسل إلى الطابعة — PROTOCOL §5.
 *
 * صفحةُ الاختبار بايتاتٌ قليلة في الذاكرة. والإيصالُ يُكتب أوّلًا إلى ملفٍّ مؤقّت
 * (`ReceiptSpool`) ثمّ يُقرأ منه قطعةً قطعة: فلا يُرسل منه بايتٌ قبل أن يُرسم كلُّه —
 * رسمٌ يفشل في منتصفه لا يترك نصفَ إيصالٍ على الورق — ولا يُحمل كلُّه في الذاكرة.
 */
interface PrintPayload {
    val length: Long

    fun open(): InputStream
}

class BytesPayload(private val bytes: ByteArray) : PrintPayload {
    override val length: Long get() = bytes.size.toLong()

    override fun open(): InputStream = ByteArrayInputStream(bytes)
}

/** الإيصالُ بايتاتِ ESC/POS في ملفٍّ مؤقّت — يُحذف بعد الإرسال في كلّ حال */
class ReceiptSpool(val file: File, override val length: Long) : PrintPayload {
    override fun open(): InputStream = FileInputStream(file)

    fun delete() {
        file.delete()
    }
}
