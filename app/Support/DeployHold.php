<?php

namespace App\Support;

use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

/**
 * المهامُّ المجدولة تنتظر ما دام النشرُ يبدّل الكود.
 *
 * ═══ لماذا ═══
 *
 * cron يشغّل `schedule:run` كلَّ دقيقة بحساب www-data، وكلُّ تشغيلٍ عمليّةٌ جديدة
 * تقرأ القرصَ كما هو. فمن لحظة السحب حتى تنتهي الهجراتُ والذاكرة تجد المهامُّ
 * كودًا جديدًا على قاعدةٍ قديمة، وذاكرةَ إعداداتٍ قديمة، وvendor قد يكون نصفَ
 * مثبَّت: حملةُ واتساب، ومطابقةُ فواتير، وقيدُ مبيعاتٍ ناقص — بكودٍ لم تُبنَ له
 * القاعدة بعد.
 *
 * ═══ كيف ═══
 *
 * `scripts/deploy.sh` (وrollback-code.sh) يكتب `.deploy-hold` في جذر الشجرة قبل
 * السحب، ويحذفه بعد الهجرات والذاكرة وإعادة تحميل PHP — أو عند السقوط إن كان
 * الكودُ على القرص يجري على قاعدته. وما دام الملفُّ قائمًا تتخطّى كلُّ مهمّةٍ حان
 * وقتها (`skip`)، ويُسجَّل اسمُها، فتُعرف المهمّةُ اليوميّة التي فاتها موعدُها.
 *
 * والملفُّ في جذر الشجرة لأنّه المكانُ الوحيد الذي يكتبه حسابُ النشر (يملك الشجرة)
 * ويقرؤه www-data حتمًا (يقرأ منه الكود). فلا sudo فيه، ولا تعديلَ لـcron،
 * ولا يعتمد على قاعدةٍ أو ذاكرةٍ قد تكون في منتصف التبديل.
 *
 * ═══ ولا يوقف المهامَّ أبدًا ═══
 *
 * نشرٌ قُتل قبل أن يحذف الملفَّ لا يترك المنصّةَ بلا مهامّ: ملفٌّ أقدمُ من نصف
 * ساعة يُتجاهل، ويُسجَّل خطأً في كلّ تشغيل حتى يُحذف. والنشرُ العاديّ دقائق.
 *
 * ═══ ونشرُ هذا الطلب نفسه ═══
 *
 * الكودُ القديم لا يعرف الملفّ، ونشرُ هذا الطلب يجري بسكربت main القديم (مفتاحُ
 * النشر يشغّل ما على القرص قبل السحب) — فلا ملفَّ ولا فحص، كما اليوم. ولا ضررَ
 * فيه: هذا الطلبُ بلا هجرة. ومن النشر الذي بعده يقف كلُّ شيء.
 */
final class DeployHold
{
    /** اسمُ الملفّ في جذر الشجرة — السكربتان يكتبانه بالاسم نفسه (حارسٌ في الاختبار) */
    public const FILE = '.deploy-hold';

    /** ملفٌّ أقدمُ من هذا (ثوانٍ) تركه نشرٌ قُتل — يُتجاهل */
    public const STALE_AFTER = 1800;

    public static function register(): void
    {
        // عند بدء `schedule:run` وحده: كلُّ مهمّةٍ في الجدول — ما كُتب اليوم وما يُضاف
        // غدًا في routes/console.php — تُسأل قبل أن تجري. فلا قائمةَ تُنسى
        Event::listen(CommandStarting::class, function (CommandStarting $starting) {
            if ($starting->command !== 'schedule:run') {
                return;
            }
            foreach (app(Schedule::class)->events() as $event) {
                $event->skip(fn () => self::holds($event));
            }
        });
    }

    /**
     * مكانُ الملفّ. وأثناء النشر نفسِه ذاكرةُ الإعدادات قديمةٌ بلا هذا المفتاح —
     * فيُقرأ من مكانه المعروف، وهو ما يكتبه السكربت.
     */
    public static function path(): string
    {
        return (string) (config('app.deploy_hold_file') ?: base_path(self::FILE));
    }

    /** أيمسك النشرُ المهامَّ الآن؟ */
    public static function holds(?ScheduledEvent $event = null): bool
    {
        $path = self::path();
        clearstatcache(true, $path);
        $since = @filemtime($path);
        if ($since === false) {
            return false;
        }

        $age = time() - $since;
        $context = [
            'task' => $event?->getSummaryForDisplay(),
            'hold' => trim((string) @file_get_contents($path)),
            'age_seconds' => $age,
        ];

        if ($age > self::STALE_AFTER) {
            Log::error('Scheduled task ran despite a stale deploy hold — a deploy left '.self::FILE.' behind; delete it once no deploy is running', $context);

            return false;
        }

        Log::warning('Scheduled task skipped: a deploy is swapping the code', $context);

        return true;
    }
}
