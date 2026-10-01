<?php

namespace App\Support;

use App\Models\Customer;
use Illuminate\Validation\ValidationException;

/**
 * البيعُ الآجل من نقطة البيع — شرطُه على مستوى العميل في موضعٍ واحد.
 *
 * والشرطُ في الخادم لا في الشاشة: زرٌّ مخفيٌّ يُتخطّى بطلبٍ مباشر.
 *
 * والقاعدةُ: **الذمّةُ تُكتب باسم عميل** — فلا آجلَ بلا عميلٍ مختار، وأيُّ
 * عميلٍ مسجَّلٍ في المتجر يُباع له آجلًا مباشرة. ولا إذنَ للعميل ولا حدَّ
 * ائتمان، ولا مقبضَ في إعدادات المتجر يُطفئه — هذا الشرطُ وحده.
 */
final class CreditSales
{
    /** هل يجوز هذا البيع الآجل؟ — وإلّا رُدّ بخطأ تحقّقٍ يُقرأ */
    public static function assertAllowed(?Customer $customer): void
    {
        if (! $customer) {
            throw ValidationException::withMessages([
                'customer_id' => __('اختر العميل لاستخدام البيع الآجل.'),
            ]);
        }
    }
}
