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
        'loyalty_redeem_mode' => self::FIXED,
        'loyalty_redeem_points' => '100',
        'loyalty_redeem_value' => '1',
        'loyalty_redeem_percent' => '10',
        'loyalty_redeem_min_invoice' => '0',
        'loyalty_redeem_max_pct' => '50',
    ];

    /**
     * طريقتا الاستبدال.
     *
     * `fixed`: كلُّ `points` نقطةً تساوي مبلغًا ثابتًا (`value`) بعملة المتجر —
     * وهو ما كان يعمل به كلُّ متجرٍ قبل أن يصير الاختيارُ له، فهو الافتراض.
     *
     * `percent`: كلُّ `points` نقطةً تساوي نسبةً (`percent`) من الفاتورة
     * المؤهّلة — ما بقي منها بعد الخصومات الأخرى. فقيمةُ النقطة تتبع الفاتورة:
     * مئةُ نقطةٍ بعشرةٍ بالمئة تخصم ريالًا من عشرة، وعشرةً من مئة.
     *
     * والسقفُ (`max_pct`) والحدّان الأدنيان قيودٌ مستقلّةٌ تُطبَّق على الطريقتين
     * بعد الحساب — لا طريقةُ حساب.
     */
    public const FIXED = 'fixed';

    public const PERCENT = 'percent';

    /** أسبابُ عدم الأهليّة — رموزٌ تقرؤها الشاشة وتترجمها */
    public const OFF = 'off';

    public const REDEEM_OFF = 'redeem_off';

    public const BELOW_MIN_POINTS = 'below_min_points';

    public const BELOW_MIN_INVOICE = 'below_min_invoice';

    public const NOTHING_TO_DISCOUNT = 'nothing_to_discount';

    /**
     * قواعدُ المتجر كما تُطبَّق الآن — مفتاحُه وباقتُه وأرقامُه.
     *
     * @return array{on: bool, redeem: bool, earn_rate: float, min_points: int, mode: string, points: int, value: float, percent: float, min_invoice: float, max_pct: float}
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
        $percent = max(0.0, min(100.0, (float) $v('loyalty_redeem_percent')));
        // وقيمةٌ لا تُعرف طريقتُها تُقرأ بالطريقة القديمة — لا تُفتح على التاجر طريقةٌ لم يخترها
        $mode = $v('loyalty_redeem_mode') === self::PERCENT ? self::PERCENT : self::FIXED;

        return [
            'on' => $on,
            // وقيمةٌ صفريّة للنقاط لا تُصرف: لا خصمَ يُعرض بصفر ولا رصيدَ يُنقص بلا مقابل
            'redeem' => $on && $v('loyalty_redeem_enabled') !== '0'
                && ($mode === self::PERCENT ? $percent > 0 : $value > 0),
            'earn_rate' => max(0.0, (float) $v('loyalty_earn_rate')),
            'min_points' => max(0, (int) $v('loyalty_redeem_min')),
            'mode' => $mode,
            'points' => max(1, (int) $v('loyalty_redeem_points')),
            'value' => $value,
            'percent' => $percent,
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
     * ما خُصم منه قبل النقاط (كوبونٌ أو خصمُ بند). وما بقي بينهما هو الفاتورةُ
     * المؤهّلة: ما سيدفعه الزبونُ لولا النقاط.
     *
     *   1. الحدّان الأدنيان: رصيدٌ دون `min_points`، أو فاتورةٌ مؤهّلةٌ دون
     *      `min_invoice`، لا يُستبدل عليهما.
     *   2. السقف: أصغرُ اثنين — نسبةُ `max_pct` من `$base`، وما بقي بعد
     *      `$other`. فلا تُغطّي النقاطُ أكثرَ ممّا بقي.
     *   3. كم نقطةً يبلغها السقفُ بقيمة النقطة في طريقة المتجر (`unit`)،
     *      ولا أكثرَ من الرصيد.
     *   4. قيمتُها تُقصّ إلى البيسة (`worth`)، ثمّ يُصرف أقلُّ عددٍ يبلغ تلك
     *      القيمة (`fewest`) — فلا تُصرف نقطةٌ لم يقابلها خصم.
     *
     * و`$requested`: `null` يعني «أقصى ما يجوز» (عرضُ التنبيه)، ورقمٌ يعني ما
     * اختاره الكاشير — ويُحصر بالأقصى ولا يتجاوزه، ثمّ يمرّ بالخطوة الرابعة.
     *
     * @param  array{on: bool, redeem: bool, min_points: int, mode?: string, points: int, value: float, percent?: float, min_invoice: float, max_pct: float}  $rules
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
        $byCap = self::isPercent($rules)
            ? (int) floor(round($cap * $rules['points'] * 100 / ($remaining * $rules['percent']), 6))
            : (int) floor(round($cap * $rules['points'] / $rules['value'], 6));
        $max = self::fewest($rules, min($balance, $byCap), $remaining);

        if ($max <= 0) {
            return $none(self::NOTHING_TO_DISCOUNT);
        }

        $points = $requested === null ? $max : self::fewest($rules, max(0, min($requested, $max)), $remaining);

        return [
            'eligible' => true,
            'reason' => null,
            'balance' => $balance,
            'max_points' => $max,
            'max_discount' => self::worth($rules, $max, $remaining),
            'points' => $points,
            'discount' => self::worth($rules, $points, $remaining),
        ];
    }

    /** هل يستبدل المتجرُ نسبةً من الفاتورة — وقواعدُ بلا طريقةٍ (حالاتٌ قديمة) مبلغٌ ثابت */
    private static function isPercent(array $rules): bool
    {
        return ($rules['mode'] ?? self::FIXED) === self::PERCENT;
    }

    /**
     * قيمةُ عددٍ من النقاط بعملة المتجر — تُقصّ لا تُدوَّر.
     *
     * التدويرُ إلى أعلى يجعل خصمَ ثلاثمئةٍ وثلاثٍ وثلاثين نقطةً أكبرَ من
     * السقف بجزءٍ من ألف، فيخرج من الصندوق ما لم تغطِّه نقطة. والقصُّ يُبقي
     * الخصمَ دون ما تستحقّه النقاطُ بأقلَّ من بيسة — لصالح المتجر لا عليه،
     * والنقاطُ التي لم يقابلها ذلك الكسرُ لا تُصرف (`fewest`).
     *
     * و`$eligible` الفاتورةُ المؤهّلة — لا تُقرأ إلّا في طريقة النسبة، حيث
     * النقاطُ نسبةٌ منها: `points × eligible × percent ÷ 100 ÷ rules.points`.
     *
     * @param  array{mode?: string, points: int, value: float, percent?: float}  $rules
     */
    public static function worth(array $rules, int $points, float $eligible = 0.0): float
    {
        if ($points <= 0 || $rules['points'] <= 0) {
            return 0.0;
        }

        if (self::isPercent($rules)) {
            $eligible = max(0.0, round($eligible, 3));

            return floor(round($points * $eligible * (float) $rules['percent'] / 100 / $rules['points'] * 1000, 6)) / 1000;
        }

        return floor(round($points * $rules['value'] / $rules['points'] * 1000, 6)) / 1000;
    }

    /**
     * أقلُّ عددٍ من النقاط يبلغ قيمةَ `$points` نفسَها — ولا يزيد عليه.
     *
     * القيمةُ تُقصّ إلى البيسة، فقد تساوي ألفُ نقطةٍ ما تساويه تسعُمئةٍ وتسعٌ
     * وتسعون (ثلاثةُ آلافِ نقطةٍ بريال: ٠٫٣٣٣ للعددين)، وتساوي نقطةٌ واحدةٌ
     * صفرًا حين تكون قيمتُها نصفَ بيسة. فصرفُ العدد كما طُلب يأخذ من رصيد
     * الزبون نقاطًا لم يقابلها خصم. فيُصرف أقلُّ عددٍ يبلغ القيمةَ نفسَها،
     * وما لا يبلغ بيسةً لا يُصرف منه شيء.
     *
     * وفي الصرف القديم (مئةُ نقطةٍ بريال: النقطةُ عشرُ بيسات) العددُ هو هو.
     */
    private static function fewest(array $rules, int $points, float $eligible): int
    {
        $worth = self::worth($rules, $points, $eligible);
        if ($points <= 0 || $worth <= 0) {
            return 0;
        }

        $unit = self::isPercent($rules)
            ? max(0.0, round($eligible, 3)) * (float) $rules['percent'] / 100 / $rules['points']
            : $rules['value'] / $rules['points'];

        // التقديرُ من القسمة، ثمّ يُصحَّح بالدالّة نفسِها — فلا يُعتمد على كسرٍ عشريٍّ وحده
        $fewest = (int) max(1, min($points, ceil(round($worth / $unit, 6))));
        while ($fewest < $points && self::worth($rules, $fewest, $eligible) < $worth) {
            $fewest++;
        }
        while ($fewest > 1 && self::worth($rules, $fewest - 1, $eligible) >= $worth) {
            $fewest--;
        }

        return $fewest;
    }

    /**
     * كم بالمئة من الفاتورة المؤهّلة يخصمها عددٌ من النقاط — في طريقة النسبة.
     *
     * للعرض («٥٠٠ نقطة = ٥٠٪»)، لا للحساب — الحسابُ `quote`. ومرآتُه
     * `loyaltyPercentOf` في الشاشة. و`null` في طريقة المبلغ الثابت.
     */
    public static function percentOf(array $rules, int $points): ?float
    {
        if (! self::isPercent($rules)) {
            return null;
        }
        if ($points <= 0 || $rules['points'] <= 0) {
            return 0.0;
        }

        return min(100.0, floor(round($points * (float) $rules['percent'] / $rules['points'] * 100, 6)) / 100);
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
     * @return array{loyaltyEnabled: bool, redeemEnabled: bool, redeemMaxPct: float, earnRate: float, redeemMin: int, redeemMode: string, redeemPoints: int, redeemValue: float, redeemPercent: float, redeemMinInvoice: float}
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
            'redeemMode' => $rules['mode'],
            'redeemPoints' => $rules['points'],
            'redeemValue' => $rules['value'],
            'redeemPercent' => $rules['percent'],
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
