<?php

namespace App\Support;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;

/**
 * شكل الترقيم الذي يقرأه DataTable في وضعه الخادمي.
 *
 * القوائم الكبيرة (المنتجات، العملاء، الطلبات، سجل النشاط) تبقى مرقّمة على
 * الخادم: إرسالها كاملة إلى المتصفح ليرشّحها محليًا لا يحتمله متجر بآلاف السجلات.
 */
class Pagination
{
    /** أكثرُ ما يُقبل في صفحةٍ واحدة مهما طُلب — انظر `perPage` */
    public const MAX = 100;

    /**
     * حجمُ الصفحة كما يُطلب — محبوسًا بين واحدٍ والحدّ الأعلى.
     *
     * ═══ ثلاثةُ أعطابٍ في سطرٍ واحد ═══
     *
     * `paginate((int) $request->query('per_page', 20))` كانت مكتوبةً في
     * **سبعة** متحكّمات، والرقمُ يأتي من شريط العنوان بلا فحص. وقِسنا
     * الأعطاب الثلاثة على `admin.expenses.index`:
     *
     * ١) **`per_page=-5` → خمسمئة.** `LengthAwarePaginator` يقسم على العدد،
     *    والسالبُ يكسر الحساب. صفحةُ عطبٍ يبلغها أيُّ موظّفٍ يملك القسم.
     *    وجوابُه اليوم افتراضُ الشاشة لا صفحةٌ بصفٍّ واحد.
     * ٢) **`per_page=0` → خمسةَ عشرَ صفًّا.** الصفرُ زائفٌ في PHP، فتسقط
     *    القيمة إلى `$model->getPerPage()` — لا إلى ما كتبه المتحكّم. حجمٌ
     *    لم يطلبه أحد ولا يُفسَّر بشيء.
     * ٣) **`per_page=999999` → الجدولُ كلُّه في الذاكرة.** لا شاشةَ ترسله
     *    أصلًا في هذه السبعة — يُكتب باليد — فمتجرٌ بعشرة آلاف طلبٍ يُحمَّل
     *    دفعةً واحدة بطلبٍ واحد، ويُعاد كلَّما ضُغط.
     *
     * وشاشةُ فواتير العملاء تحرس نفسها بقائمةٍ مغلقة (٢٠/٥٠/١٠٠) لأنّها
     * الوحيدة التي تعرض مُنتقيًا — وتلك أضيق، فتبقى كما هي. وهذه للباقي:
     * ما لا يُطلب من شاشةٍ يُحبَس عند حدٍّ لا يُؤذي.
     */
    public static function perPage(Request $request, int $default, int $max = self::MAX): int
    {
        $raw = $request->query('per_page');

        if ($raw === null || $raw === '' || ! is_numeric($raw)) {
            return $default;
        }

        $asked = (int) $raw;

        /*
         * وما لا معنى له يعود إلى ما قصده المتحكّم لا إلى واحد.
         *
         * حبسُ الصفر والسالب عند `1` يُنتج صفحةً بصفٍّ واحد — لا عطبٌ يُرى
         * ولا حجمٌ طلبه أحد، فيظنّ قارئُها أنّ المتجر بلا بيانات. والصفرُ
         * والسالبُ سؤالٌ لا جواب له، وجوابُه الافتراضُ المكتوب في الشاشة.
         */
        if ($asked < 1) {
            return $default;
        }

        return min($max, $asked);
    }

    public static function meta(LengthAwarePaginator $p): array
    {
        return [
            'current_page' => $p->currentPage(),
            'last_page' => $p->lastPage(),
            'from' => $p->firstItem(),
            'to' => $p->lastItem(),
            'total' => $p->total(),
            'prev_page_url' => $p->previousPageUrl(),
            'next_page_url' => $p->nextPageUrl(),
        ];
    }
}
