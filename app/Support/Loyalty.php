<?php

namespace App\Support;

use App\Models\Business;
use App\Models\Customer;
use App\Models\PointTransaction;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * قواعدُ نقاط الولاء — بابٌ واحد يقرؤه الصندوقُ وفاتورةُ العميل والشاشة.
 *
 * كان الصرفُ ثابتًا (مئةُ نقطةٍ للوحدة) مكتوبًا في ثلاثة مواضع، والسقفُ
 * والحدُّ الأدنى يُقرآن في نقطة البيع وحدها. وصار التاجرُ يحدّد قيمةَ النقاط
 * بنفسه، والفاتورةُ تستبدل كما يستبدل الصندوق — فلو حسب كلُّ بابٍ بيده
 * لقرأ الزبونُ على الشاشة رقمًا ودفع في الفاتورة غيرَه.
 *
 * فالحسابُ هنا وحده (`quote`)، والخصمُ من الرصيد هنا وحده (`spend`). وما
 * في `resources/js/lib/loyalty.ts` مرآةٌ لـ`quote` حرفًا بحرف — تُحرس
 * بحالاتٍ مشتركة يقرؤها الاختباران (`tests/fixtures/loyalty-quote-cases.json`).
 */
class Loyalty
{
    /**
     * الصرفُ القديم: مئةُ نقطةٍ تساوي وحدةَ عملةٍ واحدة.
     *
     * وهو افتراضُ الإعداد لا قاعدتُه: متجرٌ لم يحفظ شيئًا يستبدل كما كان
     * يستبدل قبل أن يصير الصرفُ إعدادًا — فلا يتغيّر على تاجرٍ قائمٍ شيء.
     */
    public const POINTS_PER_UNIT = 100;

    /** مفاتيحُ الإعداد وافتراضاتُها — وهي ما كان يعمل به كلُّ متجرٍ قبلها */
    public const DEFAULTS = [
        'loyalty_enabled' => '1',
        'loyalty_earn_rate' => '5',
        'loyalty_redeem_enabled' => '1',
        'loyalty_redeem_min' => '100',
        'loyalty_redeem_points' => '100',
        'loyalty_redeem_value' => '1',
        'loyalty_redeem_min_invoice' => '0',
        'loyalty_redeem_max_pct' => '50',
    ];

    /** أسبابُ عدم الأهليّة — رموزٌ تقرؤها الشاشة وتترجمها */
    public const OFF = 'off';

    public const REDEEM_OFF = 'redeem_off';

    public const BELOW_MIN_POINTS = 'below_min_points';

    public const BELOW_MIN_INVOICE = 'below_min_invoice';

    public const NOTHING_TO_DISCOUNT = 'nothing_to_discount';

    /**
     * قواعدُ المتجر كما تُطبَّق الآن — مفتاحُه وباقتُه وأرقامُه.
     *
     * @return array{on: bool, redeem: bool, earn_rate: float, min_points: int, points: int, value: float, min_invoice: float, max_pct: float}
     */
    public static function rules(int $businessId): array
    {
        $saved = Setting::where('business_id', $businessId)
            ->whereIn('key', array_keys(self::DEFAULTS))
            ->pluck('value', 'key')->all();

        $v = fn (string $key) => (string) ($saved[$key] ?? self::DEFAULTS[$key]);

        /*
         * والباقةُ تُسأل هنا لا في الشاشة وحدها: النقاطُ تُكتسب وتُصرف عند
         * إتمام البيعة وكتابة الفاتورة — وقفلٌ في اللوحة وحدها يترك الرصيدَ
         * يُصرف لمن لم يشترِ البرنامج.
         */
        $on = $v('loyalty_enabled') !== '0'
            && PlanFeatures::allows(Business::find($businessId), 'loyalty');

        $value = max(0.0, (float) $v('loyalty_redeem_value'));

        return [
            'on' => $on,
            // وقيمةٌ صفريّة للنقاط لا تُصرف: لا خصمَ يُعرض بصفر ولا رصيدَ يُنقص بلا مقابل
            'redeem' => $on && $v('loyalty_redeem_enabled') !== '0' && $value > 0,
            'earn_rate' => max(0.0, (float) $v('loyalty_earn_rate')),
            'min_points' => max(0, (int) $v('loyalty_redeem_min')),
            'points' => max(1, (int) $v('loyalty_redeem_points')),
            'value' => $value,
            'min_invoice' => max(0.0, (float) $v('loyalty_redeem_min_invoice')),
            'max_pct' => max(0.0, min(100.0, (float) $v('loyalty_redeem_max_pct'))),
        ];
    }

    /** هل البرنامجُ عاملٌ في المتجر — اكتسابًا واستبدالًا */
    public static function on(int $businessId): bool
    {
        return self::rules($businessId)['on'];
    }

    /**
     * كم يُستبدل وكم يساوي — الحسابُ الوحيد للخصم.
     *
     * `$base` ما قبل أيّ خصم (المجموعُ الفرعيّ كما يقرؤه الصندوق)، و`$other`
     * ما خُصم منه قبل النقاط (كوبونٌ أو خصمُ بند). والسقفُ أصغرُ اثنين: نسبةٌ
     * من `$base`، وما بقي بعد `$other` — فلا تُغطّي النقاطُ أكثرَ ممّا بقي.
     * والحدُّ الأدنى للفاتورة يُقاس على ما بقي بعد `$other`: ما سيدفعه الزبون
     * لولا النقاط.
     *
     * و`$requested`: `null` يعني «أقصى ما يجوز» (عرضُ التنبيه)، ورقمٌ يعني ما
     * اختاره الكاشير — ويُحصر بالأقصى ولا يتجاوزه.
     *
     * @param  array{on: bool, redeem: bool, min_points: int, points: int, value: float, min_invoice: float, max_pct: float}  $rules
     * @return array{eligible: bool, reason: ?string, balance: int, max_points: int, max_discount: float, points: int, discount: float}
     */
    public static function quote(array $rules, ?int $balance, float $base, float $other = 0.0, ?int $requested = null): array
    {
        $balance = max(0, (int) $balance);
        $none = fn (string $why) => [
            'eligible' => false, 'reason' => $why, 'balance' => $balance,
            'max_points' => 0, 'max_discount' => 0.0, 'points' => 0, 'discount' => 0.0,
        ];

        if (! $rules['on']) {
            return $none(self::OFF);
        }
        if (! $rules['redeem']) {
            return $none(self::REDEEM_OFF);
        }

        $base = max(0.0, round($base, 3));
        $remaining = max(0.0, round($base - max(0.0, $other), 3));

        if ($balance <= 0 || $balance < $rules['min_points']) {
            return $none(self::BELOW_MIN_POINTS);
        }
        if ($remaining <= 0) {
            return $none(self::NOTHING_TO_DISCOUNT);
        }
        if ($remaining < $rules['min_invoice']) {
            return $none(self::BELOW_MIN_INVOICE);
        }

        $cap = min(round($base * $rules['max_pct'] / 100, 3), $remaining);

        // كم نقطةً تغطّي السقف — ولا كسرَ نقطة: ما لا يبلغ نقطةً كاملة لا يُصرف
        $byCap = (int) floor(round($cap * $rules['points'] / $rules['value'], 6));
        $max = min($balance, $byCap);

        if ($max <= 0) {
            return $none(self::NOTHING_TO_DISCOUNT);
        }

        $points = $requested === null ? $max : max(0, min($requested, $max));

        return [
            'eligible' => true,
            'reason' => null,
            'balance' => $balance,
            'max_points' => $max,
            'max_discount' => self::worth($rules, $max),
            'points' => $points,
            'discount' => self::worth($rules, $points),
        ];
    }

    /**
     * قيمةُ عددٍ من النقاط بعملة المتجر — تُقصّ لا تُدوَّر.
     *
     * التدويرُ إلى أعلى يجعل خصمَ ثلاثمئةٍ وثلاثٍ وثلاثين نقطةً أكبرَ من
     * السقف بجزءٍ من ألف، فيخرج من الصندوق ما لم تغطِّه نقطة. والقصُّ يُبقي
     * الخصمَ دون ما تستحقّه النقاطُ بأقلَّ من بيسة — لصالح المتجر لا عليه.
     *
     * @param  array{points: int, value: float}  $rules
     */
    public static function worth(array $rules, int $points): float
    {
        if ($points <= 0 || $rules['points'] <= 0) {
            return 0.0;
        }

        return floor(round($points * $rules['value'] / $rules['points'] * 1000, 6)) / 1000;
    }

    /**
     * أقصى نسبة اكتسابٍ يجوز أن تُحفظ لقيمة نقاطٍ بعينها.
     *
     * نسبةُ الاكتساب نقاطٌ لكلّ وحدة شراء، والاستبدالُ يحوّلها إلى مال. فما
     * يجعل الزبونَ يستردّ فاتورتَه كاملةً حلقةٌ لا تُغلق: يشتري بنقاطٍ يكسب
     * منها نقاطًا. فالحدُّ ما يعيد ٩٩٫٩٪ لا أكثر — وهو ٩٩٫٩ نقطةً للوحدة
     * عند الصرف القديم (مئةُ نقطةٍ بوحدة)، كما كان.
     */
    public static function maxEarnRate(int $points = self::POINTS_PER_UNIT, float $value = 1.0): float
    {
        if ($value <= 0 || $points <= 0) {
            return 0.0;
        }

        return floor(round($points / $value * 0.999 * 1000, 6)) / 1000;
    }

    /** كم بالمئة من فاتورته تعود إلى الزبون عند هذه النسبة */
    public static function cashbackPercent(float $rate, int $points = self::POINTS_PER_UNIT, float $value = 1.0): float
    {
        if ($points <= 0) {
            return 0.0;
        }

        return round($rate * $value / $points * 100, 1);
    }

    /**
     * قواعدُ الاستبدال كما تقرؤها شاشةُ الصندوق — الأرقامُ التي يحسب بها الخادم.
     *
     * بابٌ واحد لصفحة الصندوق (`Pos\PageController`) ولردّ «تغيّر العرض»
     * (`PosController::redemptionChanged`): قواعدُ تبدّلت بعد فتح الشاشة تُردّ
     * إليها بالشكل نفسِه، فتحسب مرآتُها (`lib/loyalty.ts`) ما يحسبه الخادم.
     *
     * @return array{loyaltyEnabled: bool, redeemEnabled: bool, redeemMaxPct: float, earnRate: float, redeemMin: int, redeemPoints: int, redeemValue: float, redeemMinInvoice: float}
     */
    public static function tillSettings(int $businessId): array
    {
        $rules = self::rules($businessId);

        return [
            'loyaltyEnabled' => $rules['on'],
            'redeemEnabled' => $rules['redeem'],
            'redeemMaxPct' => $rules['max_pct'],
            'earnRate' => $rules['earn_rate'],
            'redeemMin' => $rules['min_points'],
            'redeemPoints' => $rules['points'],
            'redeemValue' => $rules['value'],
            'redeemMinInvoice' => $rules['min_invoice'],
        ];
    }

    /**
     * يصرف ما وُجد من الرصيد حتّى `$points` — ولا ينزل به تحت الصفر.
     *
     * لبيعةٍ اكتملت عند الصندوق ثمّ وصلت بعد انقطاع: الزبونُ دفع وانصرف،
     * فلا تُردّ ولا يُغيَّر مبلغُها. فيُصرف من رصيده ما بقي فيه لا أكثر،
     * والفرقُ يُكتب للمراجعة (`PosController::recordLoyalty`).
     *
     * وبلا قفلٍ على صفّ العميل: الشرطُ في جملة التحديث، فإن سبقنا صرفٌ آخر
     * بين القراءة والتحديث أُعيدت القراءة. وقفلُ `FOR UPDATE` هنا يعاكس قفلَ
     * المفتاح الأجنبيّ الذي تأخذه البيعةُ نفسُها على صفّ العميل.
     *
     * @return int ما صُرف فعلًا
     */
    public static function spendUpTo(Customer $customer, int $points, string $note, array $columns = []): int
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $have = (int) Customer::whereKey($customer->id)->where('business_id', $customer->business_id)->value('points');
            $take = max(0, min($points, $have));

            if ($take === 0) {
                return 0;
            }

            $taken = Customer::whereKey($customer->id)
                ->where('business_id', $customer->business_id)
                ->where('points', '>=', $take)
                ->update(['points' => DB::raw('points - '.$take), 'updated_at' => now()]);

            if ($taken) {
                $customer->refresh();

                PointTransaction::create([
                    'business_id' => (int) $customer->business_id,
                    'customer_id' => $customer->id,
                    'type' => 'redeem',
                    'points' => -$take,
                    'balance_after' => (int) $customer->points,
                    'note' => mb_substr($note, 0, 250),
                ] + $columns);

                return $take;
            }
        }

        return 0;
    }

    /**
     * يُخصم من الرصيد بشرطه لا بطرحٍ أعمى — ومعه حركتُه.
     *
     * القراءةُ قبل الخصم، وبينهما بيعةٌ أو فاتورةٌ أخرى للزبون نفسه: كلتاهما
     * ترى خمسمئة نقطة وتطرح خمسمئة. فالشرطُ في جملة التحديث نفسِها — من سبق
     * أخذ، ومن تأخّر يُردّ بلا خصمٍ لم تغطِّه نقاط.
     *
     * و`source_key` فريدٌ في القاعدة: محاولةٌ ثانيةٌ بالمفتاح نفسه تُردّ
     * هناك ولو أفلتت من كلّ فحصٍ قبلها.
     *
     * @param  array<string, mixed>  $columns  أعمدةٌ إضافيّة للحركة (order_id، customer_invoice_id، source_key)
     */
    public static function spend(Customer $customer, int $points, string $note, array $columns = [], string $field = 'redeem_points'): PointTransaction
    {
        $taken = Customer::whereKey($customer->id)
            ->where('business_id', $customer->business_id)
            ->where('points', '>=', $points)
            ->update(['points' => DB::raw('points - '.(int) $points), 'updated_at' => now()]);

        if (! $taken) {
            throw ValidationException::withMessages([
                $field => __('تغيّر رصيد نقاط العميل — أعد احتساب الاستبدال.'),
            ]);
        }

        $customer->refresh();

        return PointTransaction::create([
            'business_id' => (int) $customer->business_id,
            'customer_id' => $customer->id,
            'type' => 'redeem',
            'points' => -abs($points),
            'balance_after' => (int) $customer->points,
            'note' => mb_substr($note, 0, 250),
        ] + $columns);
    }
}
