<?php

namespace App\Support;

use App\Models\Business;
use App\Models\Customer;
use App\Models\PointTransaction;
use App\Models\Setting;
use Brick\Math\BigInteger;
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
        /*
         * والسقفان اختياريّان يحدّدهما صاحبُ النشاط — وفراغُ أحدهما «بلا حدّ».
         *
         * نسبةُ الخمسين افتراضُ من لم يحفظ شيئًا (وهي ما كان يعمل به كلُّ متجرٍ)
         * لا قيدٌ تفرضه المنصّة: يمحوها فلا سقفَ بالنسبة. والمبلغُ بلا حدٍّ ما لم
         * يكتبه. وفي الحالين لا يتجاوز الخصمُ الفاتورةَ المؤهّلة — ذلك حسابٌ لا سياسة.
         */
        'loyalty_redeem_max_pct' => '50',
        'loyalty_redeem_max_amount' => '',
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
     * والسقفان (`max_pct` و`max_amount` — اختياريّان) والحدّان الأدنيان قيودٌ
     * مستقلّةٌ تُطبَّق على الطريقتين بعد الحساب — لا طريقةُ حساب.
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
     * @return array{on: bool, redeem: bool, earn_rate: float, min_points: int, mode: string, points: int, value: float, percent: float, min_invoice: float, max_pct: ?float, max_amount: ?float}
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
            // فراغٌ محفوظ = «بلا حدّ» (`null`)، وغيابُ الصفّ = الافتراض
            'max_pct' => self::optional($v('loyalty_redeem_max_pct'), 100.0),
            'max_amount' => self::optional($v('loyalty_redeem_max_amount')),
        ];
    }

    /** حدٌّ اختياريّ: فراغٌ = بلا حدّ، ورقمٌ يُحصر بين الصفر وأعلاه */
    private static function optional(string $raw, ?float $ceiling = null): ?float
    {
        if (trim($raw) === '') {
            return null;
        }

        $value = max(0.0, round((float) $raw, 3));

        return $ceiling === null ? $value : min($ceiling, $value);
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
     *   2. السقف (`cap`): ما بقي بعد `$other` — فلا تُغطّي النقاطُ أكثرَ ممّا
     *      بقي — ومعه ما حدّده صاحبُ النشاط إن حدّده: نسبةُ `max_pct` من
     *      `$base`، ومبلغُ `max_amount`. والأصغرُ هو السقف.
     *   3. كم نقطةً يبلغها السقفُ بقيمة النقطة في طريقة المتجر (`unit`)،
     *      ولا أكثرَ من الرصيد.
     *   4. قيمتُها تُقصّ إلى البيسة (`worth`)، ثمّ يُصرف أقلُّ عددٍ يبلغ تلك
     *      القيمة (`fewest`) — فلا تُصرف نقطةٌ لم يقابلها خصم.
     *
     * و`$requested`: `null` يعني «أقصى ما يجوز» (عرضُ التنبيه)، ورقمٌ يعني ما
     * اختاره الكاشير — ويُحصر بالأقصى ولا يتجاوزه، ثمّ يمرّ بالخطوة الرابعة.
     *
     * @param  array{on: bool, redeem: bool, min_points: int, mode?: string, points: int, value: float, percent?: float, min_invoice: float, max_pct: ?float, max_amount?: ?float}  $rules
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

        /*
         * ═══ والحسابُ كلُّه بأعدادٍ صحيحة: بيساتٌ ونسبٌ مضبوطة ═══
         *
         * كان بكسورٍ عائمة يُرمَّم ضجيجُها بـ`round(…, 6)` قبل كلّ قصّ. وهذا
         * يصيب ما دامت الأرقام معتادة، ويخطئ حين تقع القيمةُ الحقيقيّة على
         * جزءٍ من مليونٍ من حدّ البيسة: سقفٌ ٨٩٩٩٫٩٩٩ أخرج خصمًا ٩٠٠٠. فصار
         * المالُ بيساتٍ صحيحة، وقيمةُ النقطة نسبةً مضبوطة (بسطٌ ومقام)، والقصُّ
         * والتدويرُ قسمةً صحيحة — فلا ترميم ولا حالةَ حدٍّ تُترك للحظّ.
         * ومرآتُه في الشاشة بـ`BigInt` بالخطوات نفسِها.
         */
        $baseB = self::milli(max(0.0, $base));
        $remB = self::remainder($baseB, max(0.0, $other));

        if ($balance <= 0 || $balance < $rules['min_points']) {
            return $none(self::BELOW_MIN_POINTS);
        }
        if ($remB->isZero()) {
            return $none(self::NOTHING_TO_DISCOUNT);
        }
        [$minN, $minD] = self::exact(max(0.0, (float) $rules['min_invoice']));
        if ($remB->multipliedBy($minD)->isLessThan($minN->multipliedBy(1000))) {
            return $none(self::BELOW_MIN_INVOICE);
        }

        $capB = self::capBaisa($rules, $baseB, $remB);
        [$vn, $vd] = self::perPoint($rules, $remB);

        if (! $vn->isPositive()) {
            return $none(self::NOTHING_TO_DISCOUNT);
        }

        // كم نقطةً يبلغها السقف: أكبرُ عددٍ لا تتجاوز قيمتُه السقفَ — ولا كسرَ نقطة
        $byCap = $capB->multipliedBy($vd)->quotient($vn);
        $max = self::fewest($rules, $byCap->isLessThan($balance) ? $byCap->toInt() : $balance, $remB);

        if ($max <= 0) {
            return $none(self::NOTHING_TO_DISCOUNT);
        }

        $points = $requested === null ? $max : self::fewest($rules, max(0, min($requested, $max)), $remB);

        return [
            'eligible' => true,
            'reason' => null,
            'balance' => $balance,
            'max_points' => $max,
            'max_discount' => self::worthBaisa($rules, $max, $remB) / 1000.0,
            'points' => $points,
            'discount' => self::worthBaisa($rules, $points, $remB) / 1000.0,
        ];
    }

    /**
     * العددُ كما يُكتب بأقصر صورةٍ تعيده هو نفسَه ← بسطٌ ومقامٌ صحيحان.
     *
     * ٠٫١ العائمةُ ليست عُشرًا، لكنّ أقصرَ ما يُكتب لها «0.1» — وهو ما
     * كتبه التاجر. فتُقرأ عُشرًا تامًّا، والشاشةُ تقرؤها كذلك (`String(x)`).
     *
     * @return array{0: BigInteger, 1: BigInteger}
     */
    private static function exact(float|int $x): array
    {
        $s = is_finite((float) $x) ? json_encode((float) $x) : '0';

        if (! is_string($s) || ! preg_match('/^(-?)(\d+)(?:\.(\d+))?(?:e([+-]?\d+))?$/i', $s, $m)) {
            return [BigInteger::zero(), BigInteger::one()];
        }

        $fraction = $m[3] ?? '';
        $num = BigInteger::of($m[2].$fraction);
        $scale = strlen($fraction) - (int) ($m[4] ?? 0);
        $den = BigInteger::one();

        if ($scale >= 0) {
            $den = BigInteger::ten()->power($scale);
        } else {
            $num = $num->multipliedBy(BigInteger::ten()->power(-$scale));
        }

        return [$m[1] === '-' ? $num->negated() : $num, $den];
    }

    /** `a ÷ b` مدوَّرًا نصفًا إلى أعلى — `round()` في PHP لما ليس سالبًا */
    private static function halfUp(BigInteger $a, BigInteger $b): BigInteger
    {
        return $a->multipliedBy(2)->plus($b)->quotient($b->multipliedBy(2));
    }

    /** مبلغٌ بالبيسات — `round($x, 3) × 1000` بلا كسرٍ عائم */
    private static function milli(float $x): BigInteger
    {
        [$n, $d] = self::exact(max(0.0, $x));

        return self::halfUp($n->multipliedBy(1000), $d);
    }

    /** ما بقي بعد الخصومات الأخرى بالبيسات — `round($base − $other, 3)`، ولا سالب */
    private static function remainder(BigInteger $baseB, float $other): BigInteger
    {
        [$on, $od] = self::exact($other);
        $left = $baseB->multipliedBy($od)->minus($on->multipliedBy(1000));

        return $left->isPositive() ? self::halfUp($left, $od) : BigInteger::zero();
    }

    /**
     * قيمةُ النقطة الواحدة بالبيسات — نسبةٌ مضبوطة (بسط، مقام).
     *
     * مبلغٌ ثابت: `value × 1000 ÷ points`. ونسبة: `المؤهّلة × percent ÷ 100 ÷ points`.
     *
     * @return array{0: BigInteger, 1: BigInteger}
     */
    private static function perPoint(array $rules, BigInteger $eligibleB): array
    {
        $per = BigInteger::of(max(1, (int) $rules['points']));

        if (self::isPercent($rules)) {
            [$pn, $pd] = self::exact(max(0.0, (float) ($rules['percent'] ?? 0)));

            return [$eligibleB->multipliedBy($pn), $pd->multipliedBy(100)->multipliedBy($per)];
        }

        [$vn, $vd] = self::exact(max(0.0, (float) $rules['value']));

        return [$vn->multipliedBy(1000), $vd->multipliedBy($per)];
    }

    /** قيمةُ عددٍ من النقاط بالبيسات — مقصوصةً لا مدوَّرة */
    private static function worthBaisa(array $rules, int $points, BigInteger $eligibleB): int
    {
        if ($points <= 0 || $rules['points'] <= 0) {
            return 0;
        }

        [$vn, $vd] = self::perPoint($rules, $eligibleB);

        return BigInteger::of($points)->multipliedBy($vn)->quotient($vd)->toInt();
    }

    /**
     * السقفُ بالبيسات — `cap` بلا كسرٍ عائم.
     *
     * ═══ وحدُّ صاحب النشاط يُقصّ ولا يُدوَّر ═══
     *
     * خمسون بالمئة من ٢٫٩١٩ هي ١٫٤٥٩٥ — نصفُ بيسةٍ تمامًا. التدويرُ إلى أعلى
     * يجعل الحدَّ ١٫٤٦٠ فيتجاوز ما كتبه صاحبُ النشاط بنصف بيسة. وكانت
     * الكسورُ العائمة تقصّها هنا وتدوّرها هناك بحسب تمثيل الرقم، لا بقاعدة.
     * فالحدّان يُقصّان دائمًا — كما تُقصّ قيمةُ النقاط — فلا يتجاوزهما خصم.
     */
    private static function capBaisa(array $rules, BigInteger $baseB, BigInteger $remB): BigInteger
    {
        $cap = $remB;

        if (($rules['max_pct'] ?? null) !== null) {
            [$mn, $md] = self::exact(max(0.0, (float) $rules['max_pct']));
            $cap = BigInteger::min($cap, $baseB->multipliedBy($mn)->quotient($md->multipliedBy(100)));
        }
        if (($rules['max_amount'] ?? null) !== null) {
            [$an, $ad] = self::exact(max(0.0, (float) $rules['max_amount']));
            $cap = BigInteger::min($cap, $an->multipliedBy(1000)->quotient($ad));
        }

        return $cap->isNegative() ? BigInteger::zero() : $cap;
    }

    /**
     * أقصى ما يُخصم بالنقاط من هذه الفاتورة — بابٌ واحد يقرؤه الحسابُ والشاشة.
     *
     * ما بقي بعد الخصومات الأخرى حدٌّ لا يُرفع: لا يُخصم أكثرُ من المؤهّلة، ولا
     * يصير المستحقُّ سالبًا. وفوقه ما حدّده صاحبُ النشاط وحده — نسبةٌ من
     * المجموع ومبلغٌ — و`null` في أيّهما «بلا حدّ».
     *
     * @param  array{max_pct?: ?float, max_amount?: ?float}  $rules
     */
    public static function cap(array $rules, float $base, float $remaining): float
    {
        return self::capBaisa($rules, self::milli(max(0.0, $base)), self::milli(max(0.0, $remaining)))->toInt() / 1000.0;
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
        return self::worthBaisa($rules, $points, self::milli(max(0.0, $eligible))) / 1000.0;
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
    private static function fewest(array $rules, int $points, BigInteger $eligibleB): int
    {
        $worth = self::worthBaisa($rules, $points, $eligibleB);
        if ($points <= 0 || $worth <= 0) {
            return 0;
        }

        // أصغرُ عددٍ تبلغ قيمتُه `worth` بيسةً: سقفُ `worth ÷ قيمة النقطة` — قسمةٌ صحيحة لا تقدير
        [$vn, $vd] = self::perPoint($rules, $eligibleB);
        $fewest = BigInteger::of($worth)->multipliedBy($vd)->plus($vn)->minus(1)->quotient($vn)->toInt();

        return max(1, min($points, $fewest));
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

        [$pn, $pd] = self::exact(max(0.0, (float) ($rules['percent'] ?? 0)));
        $hundredths = BigInteger::of($points)->multipliedBy($pn)->multipliedBy(100)->quotient($pd->multipliedBy($rules['points']));

        return $hundredths->isGreaterThan(10000) ? 100.0 : $hundredths->toInt() / 100.0;
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
     * @return array{loyaltyEnabled: bool, redeemEnabled: bool, redeemMaxPct: ?float, redeemMaxAmount: ?float, earnRate: float, redeemMin: int, redeemMode: string, redeemPoints: int, redeemValue: float, redeemPercent: float, redeemMinInvoice: float}
     */
    public static function tillSettings(int $businessId): array
    {
        $rules = self::rules($businessId);

        return [
            'loyaltyEnabled' => $rules['on'],
            'redeemEnabled' => $rules['redeem'],
            // و`null` في السقفين «بلا حدّ» — لا صفر
            'redeemMaxPct' => $rules['max_pct'],
            'redeemMaxAmount' => $rules['max_amount'],
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
