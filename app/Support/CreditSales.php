<?php

namespace App\Support;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * البيعُ الآجل من نقطة البيع — شروطُه في موضعٍ واحد.
 *
 * والشروطُ في الخادم لا في الشاشة: زرٌّ مخفيٌّ يُتخطّى بطلبٍ مباشر، فتقع
 * ذمّةٌ على عميلٍ لم يُؤذن له بالدَّين.
 *
 * والقاعدةُ الأصل: **لا آجلَ إلّا بإذن**. زبونُ المارّة لا يُشترى منه
 * بالدَّين، وفتحُه للجميع يجعل أوّلَ ضغطةٍ بالخطأ ذمّةً على من لن يعود.
 */
final class CreditSales
{
    /** فعلُ تجاوز حدّ الائتمان — يُمنح بالاسم لا يُشتقّ من الدور */
    public const OVERRIDE = 'credit.override';

    /**
     * هل يجوز هذا البيع الآجل؟ — وإلّا رُدّ بخطأ تحقّقٍ يُقرأ.
     *
     * ولا يُقال «غير مسموح» وحدَها: الكاشير الواقف والزبون أمامه يحتاج أن
     * يعرف ما العمل — هل يطلب المدير، أم يأخذ نقدًا، أم العميلُ أصلًا لا
     * يُباع له آجلًا.
     */
    public static function assertAllowed(
        ?Customer $customer,
        float $creditAmount,
        ?User $actor = null,
        ?string $overrideReason = null,
    ): void {
        if (! $customer) {
            throw ValidationException::withMessages([
                'customer_id' => __('اختر العميل لاستخدام البيع الآجل.'),
            ]);
        }

        if (! $customer->allow_credit_sales) {
            throw ValidationException::withMessages([
                'credit' => __('البيع الآجل غير مفعّل لهذا العميل — يُفعَّل من صفحة العميل.'),
            ]);
        }

        if ($creditAmount <= 0) {
            return;
        }

        $headroom = Receivables::creditHeadroom($customer);

        if ($headroom === null || $creditAmount <= $headroom) {
            return;
        }

        $over = round($creditAmount - $headroom, 3);

        /*
         * والتجاوزُ يُؤذن به ولا يُمنع منعًا مطلقًا.
         *
         * حدُّ الائتمان تقديرٌ لا قانون: شركةٌ تشتري لفعاليةٍ كبيرة تتجاوزه
         * مرّةً بعلم صاحب المتجر. والمنعُ المطلق يجعل الكاشير يبيع نقدًا
         * صوريًّا أو يسجّل البيعة خارج النظام — فيصير الحدُّ سببًا في اختفاء
         * الذمّة بدل ضبطها.
         */
        if ($actor && $actor->may(self::OVERRIDE) && trim((string) $overrideReason) !== '') {
            Activity::log('updated', 'تجاوز حدَّ ائتمان «'.$customer->name.'» بمقدار '.$over.' — '.$overrideReason, [
                'subject_id' => $customer->id, 'subject_type' => 'customer',
            ]);

            return;
        }

        throw ValidationException::withMessages([
            'credit' => $actor && $actor->may(self::OVERRIDE)
                ? __('سيتجاوز العميل حدَّ الائتمان بمقدار :n — اكتب سببَ التجاوز للمتابعة.', ['n' => $over])
                : __('سيتجاوز العميل حدَّ الائتمان بمقدار :n — يلزم إذنُ صاحب المتجر.', ['n' => $over]),
        ]);
    }
}
