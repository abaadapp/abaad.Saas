<?php

namespace App\Support\Archive;

use App\Models\Setting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * ما تسمح به المنصّة للأرشيف — بمفاتيحَ تُقرأ، لا بأرقامٍ مكتوبةٍ في الكود.
 *
 * ═══ ولمَ ليست في الباقات ═══
 *
 * في المستودع `PlanFeatures::CAPABILITIES` — قائمةٌ مغلقة بأربع قدرات،
 * ونصُّها صريح: «ولا يُضاف إليها مفتاحٌ لا حارسَ له». ومدّةُ الاحتفاظ ليست
 * قدرةً تُفتح أو تُغلق، بل رقمٌ يختلف. وربطُ الأرشيف بباقةٍ قرارٌ تجاريٌّ
 * لم يُتّخذ — وبندُ المواصفة نفسُه يقول: لا تُثبّت قيودَ باقاتٍ إلّا إذا
 * كان نظامُ الباقات يحتملها.
 *
 * فما بُني هنا هو المقابضُ وحدها، مضبوطةً بقيمٍ افتراضيّةٍ تفتح لا تُغلق.
 * ويومَ يُقرّر صاحبُ المنصّة أن يبيعها بالباقة، يُضاف المفتاحُ إلى
 * `CAPABILITIES` ويُسأل عنه هنا — في سطرٍ واحد.
 *
 * ═══ والافتراضُ «مفتوح» ═══
 *
 * كما في `PlanFeatures` و`PlanLimits`: متجرٌ منعه إعدادٌ لم يُملأ بعد
 * يتوقّف عملُه اليوم ويظنّ العطبَ في النظام. ومن فُتح له ما لم يُقصد
 * يُكتشف ويُصحَّح.
 */
final class Policy
{
    /** مفاتيحُ المنصّة — وتُقرأ من جدول `settings` بـ`business_id = null` */
    public const ENABLED = 'archive_enabled';

    public const RETENTION_MONTHS = 'archive_retention_months';

    public const MANUAL = 'archive_manual_enabled';

    public const MAX_MB = 'archive_max_mb';

    /** الأسبوعيُّ مقبضُه منفصلٌ عن الشهريّ — يُطفأ وحدَه ويبقى الشهريُّ */
    public const WEEKLY = 'archive_weekly_enabled';

    public const WEEKLY_RETENTION_WEEKS = 'archive_weekly_retention_weeks';

    public const REMOTE_DISK = 'archive_remote_disk';

    public const BACKUP_REMOTE = 'backup_remote_enabled';

    /**
     * اثنا عشرَ شهرًا — لا لأنّها حدٌّ تقنيّ، بل لأنّها سنةٌ ضريبيّة.
     *
     * وهي أكثرُ ما يُطلب من متجرٍ في عُمان: إقرارٌ سنويّ، أو مراجعةٌ تسأل عن
     * شهرٍ في السنة الماضية.
     */
    public const DEFAULT_RETENTION_MONTHS = 12;

    /**
     * اثنا عشرَ أسبوعًا — ربعُ سنةٍ لا سنة.
     *
     * ═══ ولمَ لا يُحتفظ به كالشهريّ ═══
     *
     * الشهريُّ اثنا عشرَ ملفًّا في السنة، والأسبوعيُّ اثنان وخمسون. ومدّةُ
     * سنةٍ لهما معًا تجعل أربعةً وستّين ملفًّا على القرص لكلّ متجر — وخمسون
     * متجرًا تملأ ثلاثةَ آلاف.
     *
     * والغايةُ من الأسبوعيّ غيرُ الغاية من الشهريّ: هذا يُطلب في مراجعةٍ
     * ضريبيّةٍ بعد سنة، وذاك يُفتح في الأسبوع التالي ليُرى ما باع المتجر.
     * فمن احتاج شهرًا مضى وجده في أرشيفه الشهريّ.
     */
    public const DEFAULT_WEEKLY_RETENTION_WEEKS = 12;

    /** خمسُ مئة ميجابايت — سقفٌ يمنع أرشيفًا واحدًا من ملء قرص الخادم */
    public const DEFAULT_MAX_MB = 500;

    /**
     * أصغرُ ما يُقبل ملفًّا.
     *
     * ZIP فارغٌ صحيحُ البنية اثنان وعشرون بايتًا. وملفٌّ بهذا الحجم مكتوبٌ
     * عليه «جاهز» هو الطمأنينةُ الكاذبة بعينها — فما دونه يسقط.
     */
    public const MIN_BYTES = 512;

    /**
     * أيصلح هذا الحجمُ أرشيفًا؟ — `null` يعني نعم، وإلّا سببٌ يُقرأ.
     *
     * ═══ ولمَ خرج الحكمُ من `Builder` إلى هنا ═══
     *
     * كان حدّان مكتوبين داخل دالّةٍ خاصّة، فلا يُقاس أحدهما إلّا ببناء
     * أرشيفٍ يبلغه. والأعلى يُبلَغ (ثمانيةَ عشرَ ألفَ صفّ)، والأدنى لا:
     * ZIP فيه ورقةُ تعريفٍ لا ينزل تحت نصف كيلوبايت بحال.
     *
     * فأثبتت الطفرةُ أنّ الحدَّ الأدنى **لا يحرسه شيء**: يُمحى ولا يسقط
     * اختبار. وما لا تقتله طفرةٌ لا يحرس — فإمّا أن يُحذف، وإمّا أن يُنقل
     * حيث يُقاس. وهو يستحقّ البقاء: `pack()` تُغلق الـZIP وتفحص إغلاقه،
     * لكنّ قرصًا امتلأ في اللحظة الأخيرة يُنتج ملفًا صحيحَ البنية فارغَ
     * المضمون — و«جاهز» فوقه هي الطمأنينةُ الكاذبة بعينها.
     *
     * فصار حكمًا واحدًا يُنادى من موضعٍ واحد، ويُسأل مباشرةً في الاختبار.
     */
    public static function sizeVerdict(int $bytes): ?string
    {
        if ($bytes < self::MIN_BYTES) {
            return __('ملفّ الأرشيف أصغر من أن يكون صحيحًا.');
        }

        if ($bytes > self::maxBytes()) {
            return __('حجم الأرشيف :size ميجابايت — تجاوز الحدّ المسموح :max.', [
                'size' => (int) round($bytes / 1048576),
                'max' => (int) round(self::maxBytes() / 1048576),
            ]);
        }

        return null;
    }

    public static function enabled(): bool
    {
        return self::flag(self::ENABLED, true);
    }

    public static function manualAllowed(): bool
    {
        return self::flag(self::MANUAL, true);
    }

    /** والأسبوعيُّ لا يعمل إن كان الأرشيفُ كلُّه مُطفأً — مقبضٌ داخل مقبض */
    public static function weeklyEnabled(): bool
    {
        return self::enabled() && self::flag(self::WEEKLY, true);
    }

    /** أيعمل هذا النوعُ الآن؟ — سؤالٌ واحد يجيب عنه موضعٌ واحد */
    public static function enabledFor(string $type): bool
    {
        return $type === Period::WEEKLY ? self::weeklyEnabled() : self::enabled();
    }

    /** صفرٌ يعني «بلا حدّ» — كما في مدّة الشهريّ */
    public static function retentionWeeks(): int
    {
        $value = self::raw(self::WEEKLY_RETENTION_WEEKS);

        if ($value === null || $value === '') {
            return self::DEFAULT_WEEKLY_RETENTION_WEEKS;
        }

        return max(0, (int) $value);
    }

    /**
     * متى تنتهي مدّةُ أرشيفٍ من هذا النوع — و`null` يعني «لا ينتهي».
     *
     * ═══ ولمَ يُحسب هنا لا في `Builder` ═══
     *
     * `Builder` يكتب `expires_at` مرّةً، و`Archives::prune` تحذف ما مضى.
     * فلو حَسَبَ كلٌّ منهما المدّةَ بنفسه لَبقي في القاعدة صفٌّ كُتب بمدّةٍ
     * وحُذف بأخرى. والمدّةُ سؤالٌ واحد: كم يعيش هذا الملفّ.
     *
     * و`NoOverflow` في الشهريّ: ٣١ يناير + شهر = ٣ مارس بلا هذا القيد،
     * فيعيش الملفُّ يومين زائدين. والأسبوعُ سبعةٌ دائمًا فلا يتدحرج.
     */
    public static function expiresAt(Period $period, ?Carbon $now = null): ?Carbon
    {
        $at = ($now ?? Carbon::now())->copy();

        if ($period->type === Period::WEEKLY) {
            $weeks = self::retentionWeeks();

            return $weeks > 0 ? $at->addWeeks($weeks) : null;
        }

        $months = self::retentionMonths();

        return $months > 0 ? $at->addMonthsNoOverflow($months) : null;
    }

    /** صفرٌ يعني «بلا حدّ» — ولا يُحذف شيء */
    public static function retentionMonths(): int
    {
        $value = self::raw(self::RETENTION_MONTHS);

        if ($value === null || $value === '') {
            return self::DEFAULT_RETENTION_MONTHS;
        }

        return max(0, (int) $value);
    }

    /**
     * سقفُ حجم الأرشيف بالبايت — و`0` يعني «بلا سقف» كما في مدّة الاحتفاظ.
     *
     * وكان `max(1, ...)` يرفع الصفرَ إلى ميجابايت: فلا هو بلا سقفٍ ولا هو
     * صفر، بل رقمٌ ثالثٌ لم يكتبه أحد. ومفتاحان في النظام يقبلان الصفر
     * (`archive_retention_months` وهذا) يجب أن يعنياه معنًى واحدًا — وإلّا
     * كتب المشغّل صفرًا في أحدهما بمعنى الآخر.
     *
     * و`PHP_INT_MAX` لا `null`: النادي يقارن عددًا بعدد، وفرعٌ ثانٍ لحالة
     * «بلا سقف» فرعٌ لا يمرّ عليه اختبار.
     */
    public static function maxBytes(): int
    {
        $raw = self::raw(self::MAX_MB);
        $mb = ($raw === null || $raw === '') ? self::DEFAULT_MAX_MB : max(0, (int) $raw);

        return $mb === 0 ? PHP_INT_MAX : $mb * 1024 * 1024;
    }

    /**
     * قرصُ النسخ البعيد — باسمه في `config/filesystems.php` لا بمزوّدٍ مثبَّت.
     *
     * ═══ ولا اعتمادَ يُخترع ولا مزوّدَ يُثبَّت ═══
     *
     * ما يُكتب هنا اسمُ قرصٍ مُعرَّفٍ في `filesystems.php` — `s3` أو أيُّ
     * قرصٍ يُضيفه المشغّل. ومفاتيحُه في `.env` عنده، لا في هذا الملفّ ولا
     * في القاعدة. فمن يقرأ هذا الملفّ لا يجد مفتاحًا، ومن يقرأ القاعدة لا
     * يجد مفتاحًا.
     *
     * ويُتحقّق أنّ القرصَ **معرَّفٌ فعلًا** قبل أن يُردّ اسمُه: اسمٌ لقرصٍ لا
     * وجود له يُسقط النسخَ كلَّه باستثناءٍ غامض — والنسخُ المحلّيُّ لا يجوز
     * أن يتوقّف لأنّ حرفًا كُتب خطأً في إعدادٍ اختياريّ.
     */
    public static function remoteDisk(): ?string
    {
        $name = trim((string) (self::raw(self::REMOTE_DISK) ?? ''));

        if ($name === '' || $name === 'local' || $name === 'public') {
            return null;
        }

        if (! is_array(config("filesystems.disks.{$name}"))) {
            return null;
        }

        return $name;
    }

    /**
     * أيُنسخ الاحتياطيُّ التقنيُّ إلى القرص البعيد كذلك؟
     *
     * ولا يُقرأ إلّا مع وجود قرصٍ صالح: مفتاحٌ مُشعَل بلا قرصٍ يجعل الشاشة
     * تقول «يُنسخ بعيدًا» ولا يُنسخ — وتقريرُ حالٍ كاذب أسوأ من غياب
     * التقرير.
     */
    public static function backupRemoteEnabled(): bool
    {
        return self::remoteDisk() !== null && self::flag(self::BACKUP_REMOTE, false);
    }

    /**
     * هل القرصُ البعيد مُعدٌّ فعلًا؟ — تُقرأ في تقرير المنصّة.
     *
     * والجوابُ «لا» حين لا اسمَ ولا مفاتيح، وهو ما يُعرض صريحًا:
     * REMOTE DISASTER BACKUP NOT CONFIGURED.
     */
    public static function remoteConfigured(): bool
    {
        $disk = self::remoteDisk();

        if ($disk === null) {
            return false;
        }

        /*
         * ولا يُسأل القرصُ عن حالته بنداءٍ شبكيّ هنا.
         *
         * هذه الدالّة تُقرأ في شاشةٍ وفي `preflight`، ونداءُ S3 في كليهما
         * يجعل صفحةً تتعلّق ثلاثين ثانية حين يكون المزوّد بطيئًا. والوجودُ
         * في الإعدادات هو ما يُقاس هنا؛ وصحّةُ المفاتيح تظهر في أوّل نسخ.
         */
        return Storage::build(config("filesystems.disks.{$disk}")) !== null;
    }

    private static function flag(string $key, bool $default): bool
    {
        $value = self::raw($key);

        if ($value === null || $value === '') {
            return $default;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    private static function raw(string $key): ?string
    {
        $value = Setting::whereNull('business_id')->where('key', $key)->value('value');

        return $value === null ? null : (string) $value;
    }
}
