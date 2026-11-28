<?php

namespace App\Support;

use App\Models\Transaction;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * «الدخل المسجّل» ليس «المال المقبوض» — يُقالان رقمين، ولا يتغيّر أحدهما.
 *
 * ═══ ما الذي يجمعه «الدخل» ═══
 *
 * صفوفَ الحركة المالية بجهة «دخل». وكلُّ بيعةٍ تكتب صفَّها بإجماليها يومَ تُباع
 * (`WebCheckout`، `PosController`) — المدفوعةُ وغيرُ المدفوعة. فطلبُ الدفع عند
 * الاستلام والبيعُ الآجل يُقرآن «دخلًا» يومَ البيع، ويُقبض مالُهما بعدها على
 * فاتورة العميل بقيدٍ في الدفتر لا صفَّ له هنا. فالرقمُ صحيحٌ بمعناه — «ما سُجّل» —
 * ويُقرأ خطأً «ما وصل».
 *
 * ═══ وما يُضاف هنا، للعرض وحده ═══
 *
 *   - ما في ذلك «الدخل» من بيعٍ لم يُقبض عند بيعه (`uncollected`).
 *   - والمقبوضُ فعلًا من العملاء: من حسابات الصندوق والبنك وPaymob في الدفتر
 *     (`Receipts::between`) — ما تقرؤه «مقبوضات اليوم» في اللوحة.
 *
 * ولا يُغيَّر «الدخل» نفسُه ولا تعريفُه ولا قيدٌ ولا صفّ: نقلُه إلى «ما وصل» يغيّر
 * أرقامَ الأشهر الماضية في الشاشة، وذلك قرارُ المحاسب.
 */
final class RecordedSales
{
    /** من حركاتٍ مرشَّحة: «الدخل» الذي كتبته بيعاتٌ لم تُقبض عند بيعها */
    public static function uncollected(Builder $movements): float
    {
        return round((float) (clone $movements)->reorder()
            ->whereIn('kind', Transaction::SALE_KINDS)
            ->where('type', 'دخل')
            ->whereHas('order', fn ($q) => $q->where('payment_status', 'غير مدفوع'))
            ->sum('amount'), 3);
    }

    /** المقبوضُ من العملاء منذ `$start` — من الدفتر، كما تقرؤه اللوحة */
    public static function received(int $businessId, ?CarbonInterface $start): float
    {
        $from = $start ?? Carbon::create(2000, 1, 1, 0, 0, 0);

        return (float) Receipts::between($businessId, $from, now()->addYears(50))['in'];
    }
}
