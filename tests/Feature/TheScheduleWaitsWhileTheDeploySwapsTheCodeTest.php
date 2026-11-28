<?php

namespace Tests\Feature;

use App\Support\DeployHold;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithConsoleEvents;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Tests\TestCase;

/**
 * المهامُّ المجدولة تنتظر ما دام النشرُ يبدّل الكود — بملفٍّ يكتبه حسابُ النشر.
 *
 * ═══ ما كان ═══
 *
 * cron يشغّل `schedule:run` كلَّ دقيقة، وكلُّ تشغيلٍ عمليّةٌ جديدة تقرأ القرصَ
 * كما هو. فمن لحظة السحب حتى تنتهي الهجراتُ والذاكرة تجري المهامُّ — حملاتُ
 * واتساب، ومطابقةُ الفواتير، والنسخ — بكودٍ جديدٍ على قاعدةٍ قديمة.
 *
 * ═══ ما صار ═══
 *
 *   - `scripts/deploy.sh` يكتب `.deploy-hold` في جذر الشجرة (يملكها) قبل السحب،
 *     ويحذفه بعد الهجرات والذاكرة وإعادة تحميل PHP، أو عند السقوط.
 *   - والتطبيقُ (`DeployHold`) يتخطّى بوجوده كلَّ مهمّةٍ حان وقتها، ويسجّل اسمها.
 *   - وملفٌّ أقدمُ من نصف ساعة لا يُطلقها الوقتُ وحده: تبقى موقوفةً ما دامت على القرص
 *     هجراتٌ لم تجرِ، أو نشرٌ يجري، أو تعذّر السؤال. وإلّا فقد تركه نشرٌ قُتل والكودُ على
 *     قاعدته: يُتجاهل ويُسجَّل خطأً — فلا يترك المنصّةَ بلا مهامّ.
 *   - ولا sudo فيه ولا تعديلَ لـcron: يعمل من أوّل نشرٍ بعد هذا الطلب.
 */
class TheScheduleWaitsWhileTheDeploySwapsTheCodeTest extends TestCase
{
    // أحداثُ الأوامر تصل التطبيقَ في الإنتاج عبر أحداث Symfony، وفي الاختبار بهذه السمة وحدها
    use RefreshDatabase;
    use WithConsoleEvents;

    private string $hold = '';

    /** مجلّدُ هجرةٍ على «القرص» لم تجرِ — يُسجَّل للمُهاجِر كما تُسجَّل مجلّداتُ الحزم */
    private string $pendingDir = '';

    /** قفلُ نشرٍ يجري — يمسكه الاختبارُ كما يمسكه السكربت */
    private $lock = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hold = sys_get_temp_dir().'/abaad-deploy-hold-'.bin2hex(random_bytes(4));
        config(['app.deploy_hold_file' => $this->hold]);
    }

    protected function tearDown(): void
    {
        if ($this->hold !== '' && is_file($this->hold)) {
            unlink($this->hold);
        }
        if (is_resource($this->lock)) {
            flock($this->lock, LOCK_UN);
            fclose($this->lock);
        }
        if ($this->pendingDir !== '' && is_dir($this->pendingDir)) {
            exec('rm -rf '.escapeshellarg($this->pendingDir));
        }
        parent::tearDown();
    }

    /** جدولٌ بمهمّةٍ واحدةٍ تُعَدّ — لا مهامُّ المنصّة الحقيقيّة، فلا يجري منها شيء في الاختبار */
    private function oneTask(): \ArrayObject
    {
        $ran = new \ArrayObject;
        $schedule = new Schedule;
        $schedule->call(fn () => $ran->append('ran'))->everyMinute()->name('test-task');
        $this->app->instance(Schedule::class, $schedule);

        return $ran;
    }

    private function holdSince(int $secondsAgo): void
    {
        file_put_contents($this->hold, "نشر abc1234 · pid 1 · منذ الآن\n");
        touch($this->hold, time() - $secondsAgo);
    }

    public function test_while_the_deploy_holds_the_schedule_no_task_runs_and_each_is_named_in_the_log(): void
    {
        Log::spy();
        $ran = $this->oneTask();
        $this->holdSince(60);

        $this->artisan('schedule:run')->assertSuccessful();

        $this->assertCount(0, $ran, 'جرت مهمّةٌ والنشرُ يبدّل الكود');
        Log::shouldHaveReceived('warning')->withArgs(
            fn ($message, $context = []) => str_contains($message, 'deploy') && str_contains(json_encode($context, JSON_UNESCAPED_UNICODE), 'test-task')
        )->once();
    }

    public function test_without_a_hold_the_schedule_runs_as_before(): void
    {
        $ran = $this->oneTask();

        $this->artisan('schedule:run')->assertSuccessful();

        $this->assertCount(1, $ran);
    }

    /** هجرةٌ على القرص لم تجرِ على القاعدة — كما يتركها نشرٌ سقط بعد السحب وقبل الهجرات */
    private function migrationOnDiskNotRun(): string
    {
        $this->pendingDir = sys_get_temp_dir().'/abaad-pending-migrations-'.bin2hex(random_bytes(4));
        mkdir($this->pendingDir);
        file_put_contents($this->pendingDir.'/2099_01_01_000000_left_by_a_failed_deploy.php', "<?php\n\nreturn new class extends Illuminate\\Database\\Migrations\\Migration {};\n");
        app('migrator')->path($this->pendingDir);

        return '2099_01_01_000000_left_by_a_failed_deploy';
    }

    public function test_a_hold_left_by_a_killed_deploy_never_stops_the_schedule_for_good_once_the_code_runs_on_its_database(): void
    {
        Log::spy();
        $ran = $this->oneTask();
        $this->holdSince(DeployHold::STALE_AFTER + 60);

        $this->artisan('schedule:run')->assertSuccessful();

        $this->assertCount(1, $ran, 'ملفٌّ قديمٌ تركه نشرٌ قُتل أوقف المهامّ والكودُ على قاعدته');
        Log::shouldHaveReceived('error')->withArgs(fn ($message) => str_contains($message, 'stale'))->atLeast()->once();
    }

    public function test_a_hold_written_while_the_code_was_swapping_is_never_freed_by_time_even_with_nothing_pending(): void
    {
        Log::spy();
        $ran = $this->oneTask();
        // نشرٌ سقط بعد السحب وقبل الذاكرة (أو قُتل): لا هجرةَ معلّقة، لكنّ الكودَ نصفُ مبدَّل
        file_put_contents($this->hold, "أُبقي بعد سقوط النشر عند «بناء الواجهة» · نشرٌ من abc1234 · pid 1\n".DeployHold::SWAPPING."\n");
        touch($this->hold, time() - DeployHold::STALE_AFTER * 4);

        $this->artisan('schedule:run')->assertSuccessful();

        $this->assertCount(0, $ran, 'أطلق الوقتُ مهامَّ على كودٍ نصفِ مبدَّل');
        Log::shouldHaveReceived('error')->withArgs(fn ($message) => str_contains($message, 'swapping'))->atLeast()->once();
        // والسكربتان يكتبان السطرَ نفسَه حرفًا
        foreach (['scripts/deploy.sh', 'scripts/rollback-code.sh'] as $script) {
            $this->assertStringContainsString("phase: %s", (string) file_get_contents(base_path($script)), $script);
            $this->assertStringContainsString("grep -qx 'phase: swapping'", (string) file_get_contents(base_path($script)), $script);
        }
    }

    public function test_time_alone_never_frees_the_schedule_while_the_code_on_disk_waits_for_unrun_migrations(): void
    {
        Log::spy();
        $ran = $this->oneTask();
        $pending = $this->migrationOnDiskNotRun();
        // ساعتان بعد نشرٍ سقط بعد السحب وقبل الهجرات — لا نصفُ ساعة
        $this->holdSince(DeployHold::STALE_AFTER * 4);

        $this->artisan('schedule:run')->assertSuccessful();

        $this->assertCount(0, $ran, 'أطلق الوقتُ وحده المهامَّ على كودٍ ينتظر هجراتٍ لم تجرِ');
        Log::shouldHaveReceived('error')->withArgs(
            fn ($message, $context = []) => str_contains($message, 'migrations that have not run') && in_array($pending, (array) ($context['pending_migrations'] ?? []), true)
        )->once();
    }

    public function test_a_deploy_still_running_keeps_an_old_hold(): void
    {
        $ran = $this->oneTask();
        $lockFile = sys_get_temp_dir().'/abaad-deploy-lock-'.bin2hex(random_bytes(4));
        config(['app.deploy_lock_file' => $lockFile]);
        $this->lock = fopen($lockFile, 'c');
        $this->assertTrue(flock($this->lock, LOCK_EX | LOCK_NB));
        $this->holdSince(DeployHold::STALE_AFTER + 60);

        $this->artisan('schedule:run')->assertSuccessful();
        $this->assertCount(0, $ran, 'أُطلقت المهامّ ونشرٌ يمسك قفلَه');

        // ويُفلت القفلُ بانتهاء النشر — فيجد التطبيقُ الكودَ على قاعدته (والتشغيلُ الموقوف أخذ جدولًا فارغًا، فيُعاد)
        flock($this->lock, LOCK_UN);
        $after = $this->oneTask();
        $this->artisan('schedule:run')->assertSuccessful();
        $this->assertCount(1, $after);
        unlink($lockFile);
    }

    public function test_an_old_hold_stays_when_the_database_cannot_say_what_ran(): void
    {
        $ran = $this->oneTask();
        $this->holdSince(DeployHold::STALE_AFTER + 60);
        Schema::drop('migrations');

        $this->artisan('schedule:run')->assertSuccessful();

        $this->assertCount(0, $ran, 'افتُرض التوافقُ والقاعدةُ لا تجيب');
    }

    public function test_a_hold_just_under_the_limit_still_holds(): void
    {
        $ran = $this->oneTask();
        $this->holdSince(DeployHold::STALE_AFTER - 60);

        $this->artisan('schedule:run')->assertSuccessful();

        $this->assertCount(0, $ran);
    }

    /** وشروطُ المهامّ (`when`) لا تُقرأ أصلًا: بعضُها يسأل قاعدةً لم تُهاجَر بعد */
    public function test_no_task_condition_is_even_evaluated_while_held(): void
    {
        $asked = new \ArrayObject;
        $schedule = new Schedule;
        $schedule->call(fn () => null)->everyMinute()->name('conditional')->when(function () use ($asked) {
            $asked->append('asked');

            return true;
        });
        $this->app->instance(Schedule::class, $schedule);
        $this->holdSince(30);

        $this->artisan('schedule:run')->assertSuccessful();

        $this->assertCount(0, $asked, 'قُرئ شرطُ مهمّةٍ والنشرُ يبدّل الكود');
    }

    /** كلُّ مهمّةٍ مجدولةٍ في المنصّة — اليومَ وما يُضاف غدًا — تقف، لا قائمةٌ مكتوبةٌ باليد */
    public function test_a_run_that_starts_during_a_deploy_gets_no_task_at_all(): void
    {
        $this->assertGreaterThan(20, count(app(Schedule::class)->events()), 'لم تُقرأ مهامُّ routes/console.php');
        $this->holdSince(30);

        event(new CommandStarting('schedule:run', new ArrayInput([]), new NullOutput));

        $this->assertSame([], app(Schedule::class)->events(), 'جدولٌ فيه مهامّ والنشرُ يبدّل الكود');
    }

    public function test_every_real_scheduled_task_stops_when_a_deploy_starts_mid_run(): void
    {
        $events = app(Schedule::class)->events();
        $this->assertGreaterThan(20, count($events));

        event(new CommandStarting('schedule:run', new ArrayInput([]), new NullOutput));
        // التشغيلُ بدأ قبل النشر (مهمّةٌ طويلة) — ثمّ بدأ النشر
        $this->holdSince(5);

        foreach ($events as $event) {
            $this->assertFalse($event->filtersPass($this->app), 'مهمّةٌ تجري والنشرُ يبدّل الكود: '.$event->getSummaryForDisplay());
        }
    }

    /** وأثناء النشر تكون ذاكرةُ الإعدادات قديمةً بلا هذا المفتاح — فيُقرأ الملفُّ من مكانه المعروف */
    public function test_an_old_cached_config_without_the_key_still_finds_the_file_the_deploy_writes(): void
    {
        config(['app.deploy_hold_file' => null]);

        $this->assertSame(base_path(DeployHold::FILE), DeployHold::path());
    }

    /** حارسُ الانحراف: السكربتان يكتبان الملفَّ نفسَه الذي يقرؤه التطبيق، وgit لا يراه */
    public function test_the_scripts_write_the_very_file_the_app_reads_and_git_ignores_it(): void
    {
        foreach (['scripts/deploy.sh', 'scripts/rollback-code.sh'] as $script) {
            $this->assertStringContainsString('HOLD_FILE="$APP_DIR/'.DeployHold::FILE.'"', (string) file_get_contents(base_path($script)), $script);
        }
        $this->assertContains('/'.DeployHold::FILE, file(base_path('.gitignore'), FILE_IGNORE_NEW_LINES));
    }
}
