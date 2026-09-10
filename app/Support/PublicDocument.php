<?php

namespace App\Support;

use App\Models\CustomerInvoice;
use App\Models\DocumentLink;
use App\Models\Order;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * الوجهُ العامّ للورقة — رمزُها في العنوان، ونسختُها الحيّة.
 *
 * ولا يُمنح هذا الوجه إلا لما يصل يدَ الزبون: فاتورةُ البيع، والإيصال
 * الحراريّ، وسندُ التسليم. وأمرُ الشراء وسندُ الاستلام يبقيان بلا رمز —
 * فيهما تكلفةُ البضاعة، ورابطٌ عامٌّ لا يحرسه إلا كونُه غير مخمَّن يضع
 * هامشَ ربح التاجر خلف قصاصةِ ورقٍ تُصوَّر بهاتف. وقرارُ المالك صريح:
 * أوراق الزبون وحدها.
 *
 * والرابط دائم: زبونٌ يعود بضمانٍ بعد سنةٍ يجب أن يجد فاتورته، والورقةُ
 * المطبوعة في يده لا تُحدَّث إن انتهت صلاحية رمزها.
 */
class PublicDocument
{
    /** طولُ الرمز — ٢٢ حرفًا من ٦٦ احتمالًا، أي ما لا يُخمَّن ولا يُعَدّ */
    private const LENGTH = 22;

    /**
     * ما يُمنح وجهًا عامًّا — سياسةٌ في موضعٍ واحد لا شرطٌ في كلّ متحكّم.
     *
     * ═══ وأوراقُ الزبون وحدها ═══
     *
     * فاتورةُ البيع، وإيصالُها الحراريّ، وسندُ التسليم، وفاتورةُ العميل:
     * أربعُها تصل يدَ الزبون على ورق، فرمزٌ يفتح نسختَها الحيّة يخدمه —
     * إيصالٌ يبهت في جيبٍ خلال أشهر، وزبونٌ يعود بضمانٍ بعد سنة.
     *
     * ═══ وأمرُ الشراء وسندُ الاستلام لا ═══
     *
     * يمضيان إلى المورّد وفيهما **تكلفةُ البضاعة**. ورابطٌ عامٌّ لا يحرسه
     * إلّا كونُه غير مخمَّن يضع هامشَ ربح التاجر خلف قصاصةِ ورقٍ تُصوَّر
     * بهاتف. وقرارُ المالك صريحٌ ومُعادٌ تأكيدُه: أوراق الزبون وحدها.
     *
     * وتُمنع من **مصدرها** لا بشرطٍ في القالب: `token()` لا يُنشئ لها رمزًا
     * أصلًا، فلو نسي قالبٌ شرطَه لم يجد ما يرسمه. ومقبضٌ يُخفي رابطًا
     * موجودًا يُنسى فيُشعَل، ورابطٌ لا يُبنى لا يُسرَّب أبدًا.
     *
     * @var list<class-string>
     */
    private const PUBLIC = [Order::class, CustomerInvoice::class];

    /** أيُمنح هذا النوعُ وجهًا عامًّا؟ */
    public static function allows(?Model $document): bool
    {
        return $document !== null && in_array($document::class, self::PUBLIC, true);
    }

    /**
     * رابطُ الورقة العامّ — يُنشأ عند أوّل طباعة ويبقى.
     *
     * وnull لما لا يُحفظ: معاينةُ المحرّر ترسم طلبًا مُخترعًا لا وجود له في
     * القاعدة، ورمزٌ له يقود إلى ٤٠٤ في يد التاجر — أو أسوأ: يصنع صفًّا
     * يتيمًا في الجدول عند كلّ فتح للمحرّر.
     */
    public static function url(?Model $document): ?string
    {
        $token = self::token($document);

        return $token === null ? null : route('paper.show', $token);
    }

    /** رمزُ الورقة — يُقرأ إن وُجد ويُكتب إن لم يوجد */
    public static function token(?Model $document): ?string
    {
        if ($document === null || ! $document->exists || ! self::allows($document)) {
            return null;
        }

        $businessId = (int) ($document->business_id ?? 0);

        if ($businessId === 0) {
            return null;
        }

        /*
         * ولا يُصنع رمزان لورقةٍ واحدة.
         *
         * صندوقان يطبعان الفاتورة نفسها في اللحظة نفسها يمرّان معًا على
         * «هل لها رمز؟» فيجيب كلاهما «لا»، فيُكتب صفّان — والقيد الفريد
         * يردّ الثاني باستثناء يُسقط الطباعة. فيُلتقط الاصطدام ويُقرأ ما
         * كتبه السابق: الرمزُ رمزُه، والورقتان تقودان إلى المكان نفسه.
         *
         * وفي نقطة حفظ: الالتقاطُ وحدَه لا يُنقذ على PostgreSQL — المعاملةُ
         * تكون قد أُجهضت، فتسقط القراءةُ التالية معها. انظر `Contention`.
         */
        $link = Contention::attempt(fn () => DocumentLink::firstOrCreate(
            ['linkable_type' => $document->getMorphClass(), 'linkable_id' => $document->getKey()],
            ['business_id' => $businessId, 'token' => Str::random(self::LENGTH)],
        ));

        if ($link !== null) {
            return $link->token;
        }

        return DocumentLink::where('linkable_type', $document->getMorphClass())
            ->where('linkable_id', $document->getKey())
            ->value('token');
    }

    /** الورقةُ التي يشير إليها رمزٌ — أو null فلا شيء يُعرض */
    public static function find(string $token): ?DocumentLink
    {
        return DocumentLink::with('linkable', 'business')->where('token', $token)->first();
    }
}
