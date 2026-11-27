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

    /** خطأُ الملفّ القديم يُسجَّل مرّةً في كلّ تشغيل، لا مرّةً لكلّ مهمّة */
    private static bool $staleLogged = false;

    public static function register(): void
    {
        self::$staleLogged = false;

        Event::listen(CommandStarting::class, function (CommandStarting $starting) {
            if ($starting->command !== 'schedule:run') {
                return;
            }
            $schedule = app(Schedule::class);

            // النشرُ قائمٌ والتشغيلُ يبدأ: يأخذ `schedule:run` جدولًا فارغًا — فلا تجري
            // مهمّة، ولا يُقرأ حتى شرطُها (`when`): بعضُ الشروط يسأل القاعدة، والقاعدةُ
            // لم تُهاجَر بعد. وتُسمّى ما حان وقتُه منها مرّةً، فتُعرف اليوميّةُ التي فاتت
            if (self::holds()) {
                Log::warning('Scheduled tasks skipped: a deploy is swapping the code', [
                    'tasks' => $schedule->dueEvents(app())->map(fn (ScheduledEvent $e) => self::name($e))->values()->all(),
                    'hold' => self::describe(),
                ]);
                app()->instance(Schedule::class, new Schedule);

                return;
            }

            // وإن بدأ النشرُ أثناء هذا التشغيل — خلف مهمّةٍ طويلة — تقف كلُّ مهمّةٍ بعدها.
            // كلُّ مهمّة: ما كُتب اليوم في routes/console.php وما يُضاف غدًا
            foreach ($schedule->events() as $event) {
                $event->skip(function () use ($event) {
                    if (! self::holds()) {
                        return false;
                    }
                    Log::warning('Scheduled task skipped: a deploy started during this run', [
                        'task' => self::name($event),
                        'hold' => self::describe(),
                    ]);

                    return true;
                });
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

    /** أيمسك النشرُ المهامَّ الآن؟ — وملفٌّ أقدمُ من نصف ساعة لا يمسك، ويُقال ذلك */
    public static function holds(): bool
    {
        $age = self::age();
        if ($age === null) {
            return false;
        }

        if ($age > self::STALE_AFTER) {
            if (! self::$staleLogged) {
                self::$staleLogged = true;
                Log::error('Scheduled tasks ran despite a stale deploy hold — a deploy left '.self::FILE.' behind; delete it once no deploy is running', [
                    'hold' => self::describe(),
                    'age_seconds' => $age,
                ]);
            }

            return false;
        }

        return true;
    }

    /** عمرُ الملفّ بالثواني، أو null إن لم يكن */
    private static function age(): ?int
    {
        $path = self::path();
        clearstatcache(true, $path);
        $since = @filemtime($path);

        return $since === false ? null : time() - $since;
    }

    /** اسمُ المهمّة كما في routes/console.php — `whatsapp:run-campaigns` لا سطرُ الأمر كلُّه */
    private static function name(ScheduledEvent $event): string
    {
        $summary = $event->description ?: (string) ($event->command ?? $event->getSummaryForDisplay());

        return trim((string) preg_replace("/^.*'artisan'\\s*/", '', $summary));
    }

    /** ما كتبه السكربتُ فيه: أيُّ نشرٍ ومنذ متى */
    private static function describe(): string
    {
        return trim((string) @file_get_contents(self::path()));
    }
}
