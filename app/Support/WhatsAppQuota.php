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
 */
class WhatsAppQuota
{
    /** إعداد المنصّة — الحدّ الافتراضي لمن لا تخصيص له */
    public const DEFAULT_KEY = 'whatsapp_shared_default_monthly_limit';

    /** القيمة التي تعني بلا حدّ */
    public const UNLIMITED = -1;

    /** الافتراضيّ حين لا يضبط مدير المنصّة شيئًا */
    public const FALLBACK_DEFAULT = 100;

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
     * صورة الاستهلاك — تُقرأ في الشاشات ولا تُحسب في كلٍّ منها على حدة.
     *
     * @return array{used:int,limit:int,unlimited:bool,remaining:int|null,percentage:int|null,is_exhausted:bool}
     */
    public static function snapshot(Business $business): array
    {
        $limit = self::effectiveLimit($business);
        $used = self::used($business);
        $unlimited = $limit === self::UNLIMITED;

        return [
            'used' => $used,
            'limit' => $limit,
            'unlimited' => $unlimited,
            'remaining' => $unlimited ? null : max(0, $limit - $used),
            // النسبة تُقصّ عند مئة: «١٢٠٪» رقمٌ لا يُقرأ في شريط
            'percentage' => $unlimited || $limit <= 0 ? null : min(100, (int) round(($used / $limit) * 100)),
            'is_exhausted' => ! $unlimited && $used >= $limit,
        ];
    }

    /**
     * حجزُ رسالةٍ من حصّة الشهر — ذرّةً واحدة.
     *
     * وهذا موضع العطب الذي لا يُرى في الاختبار اليدويّ: رسالتان تخرجان معًا
     * من الطابور، تقرآن «بقيت واحدة» في اللحظة نفسها، فتمرّان معًا ويُرسَل
     * ١٠١ من حدٍّ مئة. والقراءة ثمّ الكتابة لا تُصلحها مهما ضُيّقت — بينهما
     * نافذة مهما صغرت.
     *
     * فالشرط داخل جملة التحديث نفسها: المحرّك يقفل الصفّ ويقارن ويزيد في
     * عمليةٍ واحدة، والثانية تجد `used = limit` فلا تُصيب صفًّا وتُردّ بصفر.
     * وهذا يعمل على PostgreSQL وSQLite معًا بلا قفلٍ صريح.
     *
     * @return bool هل حُجزت
     */
    public static function reserve(Business $business): bool
    {
        $limit = self::effectiveLimit($business);

        if ($limit === self::UNLIMITED) {
            self::ensureRow($business->id);
            self::bump($business->id, null);

            return true;
        }

        if ($limit <= 0) {
            return false;
        }

        self::ensureRow($business->id);

        return self::bump($business->id, $limit) === 1;
    }

    /**
     * ردُّ الحجز حين يرفض المزوّد الرسالة.
     *
     * الحجز يسبق النداء لأنّه وحده يمنع السباق، لكنّ الرسالة التي لم تُقبل
     * لا تُحسب على التاجر — فتُردّ. و`used > 0` شرطٌ لا زينة: خصمٌ من صفرٍ
     * على عمودٍ بلا إشارة يلتفّ إلى رقمٍ هائل.
     */
    public static function release(Business $business): void
    {
        [$year, $month] = self::period();

        DB::table('whatsapp_usage_periods')
            ->where('business_id', $business->id)
            ->where('period_year', $year)->where('period_month', $month)
            ->where('used', '>', 0)
            ->update(['used' => DB::raw('used - 1'), 'updated_at' => now()]);
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
}
