<?php

namespace App\Support;

use App\Models\Business;
use App\Models\Setting;
use App\Models\WhatsAppUsagePeriod;
use Illuminate\Support\Facades\DB;

/**
 * حصّة الشهر — كم رسالةً يملك هذا المتجر من رقم أبعاد.
 *
 * الرقم مشترك والتكلفة على أبعاد، فالحدُّ هو ما يمنع متجرًا واحدًا من أن
 * يستهلك ما دُفع لمئة. ولا يملك المتجر تغييره — يُقرأ من صفّه ولا يكتبه
 * إلا مدير المنصّة (انظر حرّاس المسارات).
 *
 * ورقم المحلّ الخاص لا يمرّ من هنا: الإرسال على حسابه هو، فلا حصّة تُخصم.
 *
 * -------------------------------------------------------------------------
 *
 * الحدّ الفعّال يُحلّ في موضعٍ واحد بثلاث درجات:
 *
 *   ١) `businesses.whatsapp_monthly_limit` إن كُتب — تخصيصُ هذا المتجر.
 *   ٢) وإلّا إعداد المنصّة `whatsapp_shared_default_monthly_limit`.
 *   ٣) وإلّا صفر — لا حدَّ افتراضيّ مفتوح: من نسي ضبط الرقم لا يُفاجأ بفاتورة.
 *
 * و‎-1 تعني بلا حدّ، وهي القيمة الوحيدة التي تعني ذلك. و`null` لا تعنيه
 * أبدًا: `null` في عمود المتجر تعني «خذ الافتراضيّ»، ولو عنت أيضًا «بلا
 * حدّ» لَاختلط أوسعُ إذنٍ بأقلّ ضبط.
 *
 * -------------------------------------------------------------------------
 *
 * ═══ وجيبان لا جيب: عطيّةُ الشهر، والرصيدُ المشترى ═══
 *
 * الحصّةُ أعلاه تُجدَّد أوّلَ كلّ شهر وتسقط بقيّتُها. والرصيدُ
 * (`businesses.whatsapp_message_credits`) رسائلُ دُفع ثمنُها، فلا تسقط بشهر
 * ولا تُجدَّد به — تُستهلَك وتنتهي.
 *
 * والترتيبُ ثابت: **العطيّة أوّلًا ثمّ المال**. ولو عُكس لَخسر من اشترى
 * رصيدَه في شهرٍ ما استهلك فيه عطيّتَه أصلًا.
 *
 * ولهذا `reserve` تردّ **من أيّ جيبٍ حُجزت** لا `true` مجرّدة: الرسالةُ
 * التي يرفضها المزوّد تُردّ إلى جيبها هو. ولو رُدّت دائمًا إلى عدّاد الشهر
 * لَربح التاجر رسالةً مجّانيّةً عن كلّ رسالةٍ مشتراةٍ فشلت — وخسر ريالَها.
 */
class WhatsAppQuota
{
    /** إعداد المنصّة — الحدّ الافتراضي لمن لا تخصيص له */
    public const DEFAULT_KEY = 'whatsapp_shared_default_monthly_limit';

    /** القيمة التي تعني بلا حدّ */
    public const UNLIMITED = -1;

    /** الافتراضيّ حين لا يضبط مدير المنصّة شيئًا */
    public const FALLBACK_DEFAULT = 100;

    /** حُجزت من عطيّة الشهر */
    public const SOURCE_MONTHLY = 'monthly';

    /** حُجزت من الرصيد المشترى */
    public const SOURCE_CREDIT = 'credit';

    /** الحدّ الافتراضي للمنصّة كما ضُبط */
    public static function platformDefault(): int
    {
        $raw = Setting::whereNull('business_id')->where('key', self::DEFAULT_KEY)->value('value');

        return $raw === null || $raw === '' ? self::FALLBACK_DEFAULT : (int) $raw;
    }

    /**
     * حدُّ هذا المتجر فعليًّا.
     *
     * @return int عددٌ موجب، أو صفرٌ (ممنوع)، أو `UNLIMITED`
     */
    public static function effectiveLimit(Business $business): int
    {
        $own = $business->whatsapp_monthly_limit;

        $limit = $own === null ? self::platformDefault() : (int) $own;

        // ما دون الصفر كلّه «بلا حدّ» — قيمةٌ سالبة أخرى لا معنى لها
        return $limit < 0 ? self::UNLIMITED : $limit;
    }

    /** الشهر الجاري — سنةً وشهرًا */
    private static function period(): array
    {
        $now = now();

        return [(int) $now->year, (int) $now->month];
    }

    /** كم استُهلك هذا الشهر */
    public static function used(Business $business): int
    {
        [$year, $month] = self::period();

        return (int) WhatsAppUsagePeriod::where('business_id', $business->id)
            ->where('period_year', $year)->where('period_month', $month)->value('used');
    }

    /**
     * الرصيدُ المشترى المتبقّي — يُقرأ من القاعدة لا من النموذج المحمَّل.
     *
     * صفُّ المتجر في الذاكرة قد يكون قديمًا بحجزٍ وقع في هذه العمليّة نفسها
     * أو في عاملٍ آخر؛ والرقمُ الذي يُعرض للتاجر لا يجوز أن يسبق الواقع.
     */
    public static function credits(Business $business): int
    {
        return (int) DB::table('businesses')->where('id', $business->id)->value('whatsapp_message_credits');
    }

    /**
     * صورة الاستهلاك — تُقرأ في الشاشات ولا تُحسب في كلٍّ منها على حدة.
     *
     * و`is_exhausted` تعني «لا تخرج رسالةٌ الآن» لا «نفدت عطيّةُ الشهر»:
     * من نفدت عطيّتُه وعنده رصيدٌ مشترًى يُرسل. وهي القيمةُ التي ترسم
     * الشاشةُ عليها لونَ الإنذار، فلو قالت «نفدت» لمن يُرسل لَكانت كذبًا
     * يدفع التاجر إلى شراء ما لا يحتاجه.
     *
     * @return array{used:int,limit:int,unlimited:bool,remaining:int|null,percentage:int|null,credits:int,monthly_exhausted:bool,is_exhausted:bool}
     */
    public static function snapshot(Business $business): array
    {
        $limit = self::effectiveLimit($business);
        $used = self::used($business);
        $unlimited = $limit === self::UNLIMITED;
        $credits = self::credits($business);
        $monthlyGone = ! $unlimited && $used >= $limit;

        return [
            'used' => $used,
            'limit' => $limit,
            'unlimited' => $unlimited,
            'remaining' => $unlimited ? null : max(0, $limit - $used),
            // النسبة تُقصّ عند مئة: «١٢٠٪» رقمٌ لا يُقرأ في شريط
            'percentage' => $unlimited || $limit <= 0 ? null : min(100, (int) round(($used / $limit) * 100)),
            'credits' => $credits,
            'monthly_exhausted' => $monthlyGone,
            'is_exhausted' => $monthlyGone && $credits <= 0,
        ];
    }

    /**
     * حجزُ رسالةٍ — ذرّةً واحدة، ومن الجيب الصحيح.
     *
     * وهذا موضع العطب الذي لا يُرى في الاختبار اليدويّ: رسالتان تخرجان معًا
     * من الطابور، تقرآن «بقيت واحدة» في اللحظة نفسها، فتمرّان معًا ويُرسَل
     * ١٠١ من حدٍّ مئة. والقراءة ثمّ الكتابة لا تُصلحها مهما ضُيّقت — بينهما
     * نافذة مهما صغرت.
     *
     * فالشرط داخل جملة التحديث نفسها: المحرّك يقفل الصفّ ويقارن ويزيد في
     * عمليةٍ واحدة، والثانية تجد `used = limit` فلا تُصيب صفًّا وتُردّ بصفر.
     * وهذا يعمل على PostgreSQL وSQLite معًا بلا قفلٍ صريح. والرصيدُ المشترى
     * يُخصم بالشرط نفسه (`> 0` داخل الجملة).
     *
     * @return string|null `SOURCE_MONTHLY` أو `SOURCE_CREDIT`، أو null إن لم يبقَ شيء
     */
    public static function reserve(Business $business): ?string
    {
        $limit = self::effectiveLimit($business);

        if ($limit === self::UNLIMITED) {
            self::ensureRow($business->id);
            self::bump($business->id, null);

            return self::SOURCE_MONTHLY;
        }

        /*
         * وحدٌّ صفرٌ لا يمنع من اشترى.
         *
         * العدّادُ يُنشأ على كلّ حال ليبقى للشهر صفٌّ يُقرأ: «صفرٌ من صفر»
         * غيرُ «لا صفّ» في شاشةٍ تعرض الاستهلاك.
         */
        self::ensureRow($business->id);

        if ($limit > 0 && self::bump($business->id, $limit) === 1) {
            return self::SOURCE_MONTHLY;
        }

        return self::takeCredit($business->id) ? self::SOURCE_CREDIT : null;
    }

    /**
     * ردُّ الحجز حين يرفض المزوّد الرسالة — إلى الجيب الذي خرجت منه.
     *
     * الحجز يسبق النداء لأنّه وحده يمنع السباق، لكنّ الرسالة التي لم تُقبل
     * لا تُحسب على التاجر — فتُردّ. و`used > 0` شرطٌ لا زينة: خصمٌ من صفرٍ
     * على عمودٍ بلا إشارة يلتفّ إلى رقمٍ هائل.
     *
     * والافتراضُ `SOURCE_MONTHLY` لأنّه ما كانت عليه الدالّة قبل الرصيد:
     * نداءٌ قديمٌ بلا مصدرٍ يعني عطيّةَ الشهر، وهو الصواب لكلّ ما سبقه.
     */
    public static function release(Business $business, ?string $source = self::SOURCE_MONTHLY): void
    {
        if ($source === self::SOURCE_CREDIT) {
            self::grant($business, 1);

            return;
        }

        [$year, $month] = self::period();

        DB::table('whatsapp_usage_periods')
            ->where('business_id', $business->id)
            ->where('period_year', $year)->where('period_month', $month)
            ->where('used', '>', 0)
            ->update(['used' => DB::raw('used - 1'), 'updated_at' => now()]);
    }

    /**
     * إضافةُ رصيدٍ مشترًى — تُنادى حين يُسجَّل سدادُ الفاتورة لا قبله.
     *
     * والزيادةُ بجملةٍ واحدة لا بقراءةٍ ثمّ كتابة: حزمتان تُسجَّل سدادُهما
     * معًا كانتا ستقرآن الرصيد نفسه فتُكتب إحداهما فوق الأخرى — ويضيع ما
     * دفعه التاجر مرّة.
     */
    public static function grant(Business $business, int $messages): void
    {
        if ($messages <= 0) {
            return;
        }

        DB::table('businesses')->where('id', $business->id)->update([
            'whatsapp_message_credits' => DB::raw('whatsapp_message_credits + '.(int) $messages),
        ]);
    }

    /** صفُّ الشهر موجودٌ قبل الزيادة — والتزاحم على إنشائه يردّه الفهرس الفريد */
    private static function ensureRow(int $businessId): void
    {
        [$year, $month] = self::period();

        DB::table('whatsapp_usage_periods')->insertOrIgnore([
            'business_id' => $businessId,
            'period_year' => $year,
            'period_month' => $month,
            'used' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** الزيادة الشرطية — تُعيد عدد الصفوف المتأثّرة */
    private static function bump(int $businessId, ?int $limit): int
    {
        [$year, $month] = self::period();

        $q = DB::table('whatsapp_usage_periods')
            ->where('business_id', $businessId)
            ->where('period_year', $year)->where('period_month', $month);

        if ($limit !== null) {
            $q->where('used', '<', $limit);
        }

        return $q->update(['used' => DB::raw('used + 1'), 'updated_at' => now()]);
    }

    /** خصمُ رسالةٍ من الرصيد المشترى — شرطًا داخل الجملة، فلا ينزل تحت الصفر */
    private static function takeCredit(int $businessId): bool
    {
        return DB::table('businesses')
            ->where('id', $businessId)
            ->where('whatsapp_message_credits', '>', 0)
            ->update(['whatsapp_message_credits' => DB::raw('whatsapp_message_credits - 1')]) === 1;
    }
}
