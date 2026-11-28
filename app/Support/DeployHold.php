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
 * ═══ ولا يُطلقها الوقتُ وحده ═══
 *
 * ملفٌّ فيه `phase: swapping` (كتبه السكربتُ قبل أن يلمس الكود) لا يُطلقه وقتٌ ولا سؤال: يُطلقه
 * نشرٌ يكتمل أو رجوعٌ يكتمل. وغيرُه أحدثُ من نصف ساعة يُمسك دائمًا: النشرُ العاديّ دقائق. وما بعدها لا يُطلق
 * المهامَّ لمجرّد أنّ الوقت مرّ — يُسأل التطبيقُ عن حاله (`DeployState`):
 *
 *   - **كودٌ على القرص ينتظر هجراتٍ لم تجرِ** (نشرٌ سقط بعد السحب وقبل الهجرات):
 *     تبقى موقوفة، ويُسجَّل خطأً في كلّ تشغيلٍ باسم الهجرات، حتى يكتمل نشرٌ أو
 *     يُرجَع بالكود. تشغيلُها الآن يُجري الكودَ الجديد على القاعدة القديمة.
 *   - **نشرٌ أو رجوعٌ ما زال يجري** (قفلُه ممسوك): تبقى موقوفة.
 *   - **وإن تعذّر السؤال** (القاعدةُ لا تردّ): تبقى موقوفة — لا يُفترض التوافق.
 *   - **وإلّا** فالكودُ على القرص يجري على قاعدته، والملفُّ تركه نشرٌ قُتل: يُتجاهل،
 *     ويُسجَّل خطأً حتى يُحذف. فلا يترك المنصّةَ بلا مهامّ ملفٌّ نسيه نشرٌ مات.
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

    /** ملفٌّ أحدثُ من هذا (ثوانٍ) يُمسك دائمًا. وما أقدمُ منه يُسأل عنه التطبيق: أيجري كودُ القرص على قاعدته؟ */
    public const STALE_AFTER = 1800;

    /** جوابُ الملفّ القديم يُحسب ويُسجَّل مرّةً في كلّ تشغيل، لا مرّةً لكلّ مهمّة */
    private static ?bool $staleVerdict = null;

    public static function register(): void
    {
        self::$staleVerdict = null;

        Event::listen(CommandStarting::class, function (CommandStarting $starting) {
            if ($starting->command !== 'schedule:run') {
                return;
            }
            // كلُّ تشغيلٍ يسأل من جديد: ما كان قبل دقيقةٍ قد تغيّر
            self::$staleVerdict = null;
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

    /** أيمسك النشرُ المهامَّ الآن؟ — ملفٌّ حديثٌ يُمسك، والقديمُ يُمسك ما دام الكودُ لا يجري على قاعدته */
    public static function holds(): bool
    {
        $age = self::age();
        if ($age === null) {
            return false;
        }

        if ($age <= self::STALE_AFTER) {
            return true;
        }

        return self::$staleVerdict ??= self::staleHolds($age);
    }

    /** ملفٌّ أقدمُ من نصف ساعة: يُمسك إن كان الكودُ ينتظر هجراتٍ أو نشرٌ يجري أو لم يُعرف — ويُقال ذلك */
    private static function staleHolds(int $age): bool
    {
        $running = DeployState::deployRunning();
        $pending = DeployState::pendingMigrations();
        $context = ['hold' => self::describe(), 'age_seconds' => $age, 'pending_migrations' => $pending, 'deploy_running' => $running];

        // كتبه نشرٌ وهو يبدّل الكود ثمّ لم يكتمل: الكودُ على القرص نصفُ إصدار — لا ذاكرتُه
        // بُنيت ولا عاملُه أُعيد — ولو جرت هجراتُه كلُّها. فلا يُطلقه وقتٌ ولا سؤال: يُطلقه
        // نشرٌ يكتمل أو رجوعٌ يكتمل (السكربتان يحذفانه)
        if (self::swapping()) {
            Log::error('Scheduled tasks stay held: a deploy or a code rollback stopped while swapping the code — finish it (re-run Deploy Main) or roll the code back (scripts/rollback-code.sh). '.self::FILE.' is not released by time alone', $context);

            return true;
        }

        if ($running === true) {
            Log::warning('Scheduled tasks stay held: a deploy or a code rollback is still running', $context);

            return true;
        }

        if ($pending === null || $pending !== []) {
            Log::error('Scheduled tasks stay held: the code on disk waits for migrations that have not run — finish the deploy (re-run Deploy Main) or roll the code back (scripts/rollback-code.sh). '.self::FILE.' is not released by time alone', $context);

            return true;
        }

        Log::error('Scheduled tasks ran despite a stale deploy hold — a deploy left '.self::FILE.' behind and the code on disk runs on its database; delete it once no deploy is running', $context);

        return false;
    }

    /** السطرُ الذي يكتبه السكربتان قبل أن يلمسا الكود (`phase: swapping`) — من هنا لا يُطلق إلّا الاكتمال */
    public const SWAPPING = 'phase: swapping';

    private static function swapping(): bool
    {
        return preg_match('/^'.preg_quote(self::SWAPPING, '/').'\s*$/mu', self::describe()) === 1;
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
