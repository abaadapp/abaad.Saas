<?php

namespace App\Support;

use App\Models\Order;
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
     * أسماءُ الوسائل التي يمرّ مالُها بالبنك — كما تكتبها شاشاتُ النظام كلُّها.
     *
     * التحويلُ اسمُه «تحويل بنكي» في نقطة البيع والموقع وشاشة المصروفات، و«تحويل»
     * في تحصيل العميل وسداد البوتيك. وكان المصروفُ يسأل قائمةً فيها الثاني وحده،
     * فمصروفٌ دُفع بـ«تحويل بنكي» يُرحَّل من الصندوق: يقرأ الدرجُ نقصًا لم يقع
     * والبنكُ مالًا خرج منه. فالقائمةُ هنا واحدةٌ لكلّ من يسأل.
     */
    public const BANK_METHODS = [self::CARD, self::TRANSFER, 'تحويل', 'شيك', 'بنك'];

    /** هل مرّ مالُ هذه الوسيلة بالبنك؟ وما لا يُعرف فنقد — كما كان */
    public static function isBank(?string $method): bool
    {
        return in_array(trim((string) $method), self::BANK_METHODS, true);
    }

    /**
     * البيعُ الآجل — مقبضٌ في «طرق الدفع» وليس وسيلةً منها.
     *
     * الوسيلةُ تقول بمَ دُخل المالُ إلى الدرج، والآجلُ يقول إنّ بعضَه لم
     * يدخل بعد. فلا يُضاف إلى `ALL` — لا يُطبع على إيصالٍ «وسيلة: آجل» ولا
     * يُعرض في تذييل الموقع — لكنّه يُضبط من الشاشة نفسِها التي تُطفئ
     * البطاقةَ والتحويل، لأنّ التاجر يبحث عنه هناك. والغيابُ إذنٌ كأخواته.
     */
    public const CREDIT_KEY = 'pay_credit';

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

    /** هل يُسمح بالبيع الآجل في هذا المتجر — من إعداداتٍ مقروءةٍ سلفًا */
    public static function creditAllowed(array $settings): bool
    {
        return ($settings[self::CREDIT_KEY] ?? '1') !== '0';
    }

    /**
     * وسيلةُ الدفع كما تُطبع على الإيصال الحراريّ.
     *
     * ═══ العطب الذي وُضعت له ═══
     *
     * طلبُ الموقع «عند الاستلام» يُكتب `نقدي` و«غير مدفوع» (`WebCheckout::payments`):
     * الوسيلةُ ما سيدخل به المالُ الدرجَ، والحالُ أنّه لم يدخل بعد. والإيصالُ
     * يطبع الوسيلةَ وحدها — وحالُ الدفع سطرٌ مطفأٌ افتراضًا — فكان موظّفُ
     * التجهيز يقرأ «نقدي» على طلبٍ لم يُقبض منه فلسٌ، فيسلّمه بلا تحصيل.
     *
     * فيُطبع ما اختاره الزبونُ بلفظه في الموقع: «الدفع عند الاستلام».
     *
     * ═══ وما لا يمسّه ═══
     *
     * البيعُ الآجل في الصندوق نقديٌّ «غير مدفوع» هو الآخر، ولا استلامَ فيه —
     * فالقناةُ شرط. والوسيلةُ المخزّنة لا تتغيّر: الدفترُ والتقاريرُ ودرجُ
     * الصندوق تقرأ `نقدي` كما كانت. والتحويلُ والبطاقةُ كما كانا.
     */
    public static function onReceipt(Order $order): string
    {
        $method = (string) $order->payment_method;

        if ($method === self::CARD) {
            return __('فيزا');
        }

        if ($method === self::CASH
            && $order->channel === SalesChannel::WEBSITE
            && (string) $order->payment_status === 'غير مدفوع') {
            return __('الدفع عند الاستلام');
        }

        return __($method);
    }

    public static function creditAllowedFor(int $businessId): bool
    {
        return self::creditAllowed(
            Setting::where('business_id', $businessId)->where('key', self::CREDIT_KEY)->pluck('value', 'key')->all(),
        );
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
