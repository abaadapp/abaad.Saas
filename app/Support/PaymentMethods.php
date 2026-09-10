<?php

namespace App\Support;

use App\Models\Setting;

/**
 * وسائل الدفع المأذونة في هذا المتجر — سؤالٌ واحد وجوابٌ واحد.
 *
 * وكان الجواب في متحكّم نقطة البيع: `PosController::enabledPaymentMethods`.
 * فصار كلُّ من يحتاجه ينادي متحكّم HTTP — تذييلُ الموقع المنشور، وشاشةُ
 * إعدادات المتجر، وتصحيحُ الطلبات، ولوحةُ الديمو. وذلك انقلابٌ في الطبقات:
 * المتحكّم يترجم طلبًا إلى ردّ، فإن صار مكتبةً تُنادى من كلّ مكانٍ تعلّق
 * منطقُ المتجر بمسارٍ في الويب — ولا يُنقل ولا يُختبر ولا يُنادى من طابور
 * ولا من أمرٍ في الطرفية إلّا بحيلة.
 *
 * فالقاعدتان اللتان تسكنان هنا وحدهما:
 *
 * ١) **الغياب إذنٌ لا منع.** متجرٌ لم يُطفئ شيئًا يقبل الثلاث. ولو كان
 *    الغياب منعًا لَما قبِل متجرٌ جديد قرشًا حتى يفتح الإعدادات.
 * ٢) **ومن أطفأ الثلاث يبقى له النقد.** قائمةٌ فارغة تعني كاشيرًا لا يستطيع
 *    إتمام بيعةٍ واحدة، وهو عطبٌ يوقف المحلّ. والنقدُ آخرُ ما يُطفأ.
 *
 * وهاتان القاعدتان لو نُسختا في موضعين لافترقتا يومًا — فيعرض الموقعُ في
 * تذييله وسيلةً لا تقبلها نقطة البيع، ويحوّل زبونٌ مبلغًا لا يُقبل تحويلُه.
 */
final class PaymentMethods
{
    public const CASH = 'نقدي';

    public const CARD = 'بطاقة';

    public const TRANSFER = 'تحويل بنكي';

    /** الوسيلة ← مفتاح إعدادها */
    public const KEYS = [
        self::CASH => 'pay_cash',
        self::CARD => 'pay_card',
        self::TRANSFER => 'pay_transfer',
    ];

    /** الثلاث كلُّها بترتيب عرضها */
    public const ALL = [self::CASH, self::CARD, self::TRANSFER];

    /**
     * ما هو مأذونٌ به من إعداداتٍ مقروءةٍ سلفًا.
     *
     * @param  array<string, mixed>  $settings  مفتاح ← قيمة، كما تُقرأ من `settings`
     * @return array<int, string>
     */
    public static function enabled(array $settings): array
    {
        $on = [];

        foreach (self::KEYS as $label => $key) {
            // الغياب إذنٌ لا منع — انظر أعلاه
            if (($settings[$key] ?? '1') !== '0') {
                $on[] = $label;
            }
        }

        return $on ?: [self::CASH];
    }

    /**
     * وما هو مأذونٌ به في متجرٍ بعينه — لمن لا إعدادات في يده.
     *
     * @return array<int, string>
     */
    public static function enabledFor(int $businessId): array
    {
        $settings = Setting::where('business_id', $businessId)
            ->whereIn('key', array_values(self::KEYS))
            ->pluck('value', 'key')->all();

        return self::enabled($settings);
    }

    /**
     * الثلاثُ ومعها أيُّها مفتوح — لشاشةٍ تعرض ولا تضبط.
     *
     * @return array<int, array{label: string, on: bool}>
     */
    public static function state(int $businessId): array
    {
        $on = self::enabledFor($businessId);

        return array_map(
            fn (string $label) => ['label' => __($label), 'on' => in_array($label, $on, true)],
            self::ALL,
        );
    }
}
