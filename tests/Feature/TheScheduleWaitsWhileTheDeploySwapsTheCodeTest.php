<?php

namespace Tests\Feature;

use App\Support\DeployHold;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\WithConsoleEvents;
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
 *   - وملفٌّ أقدمُ من نصف ساعة تركه نشرٌ قُتل لا يوقف المهامّ: يُتجاهل ويُسجَّل خطأً.
 *   - ولا sudo فيه ولا تعديلَ لـcron: يعمل من أوّل نشرٍ بعد هذا الطلب.
 */
class TheScheduleWaitsWhileTheDeploySwapsTheCodeTest extends TestCase
{
    // أحداثُ الأوامر تصل التطبيقَ في الإنتاج عبر أحداث Symfony، وفي الاختبار بهذه السمة وحدها
    use WithConsoleEvents;

    private string $hold = '';

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

    public function test_a_hold_left_by_a_killed_deploy_never_stops_the_schedule_for_good(): void
    {
        Log::spy();
        $ran = $this->oneTask();
        $this->holdSince(DeployHold::STALE_AFTER + 60);

        $this->artisan('schedule:run')->assertSuccessful();

        $this->assertCount(1, $ran, 'ملفٌّ قديمٌ تركه نشرٌ قُتل أوقف المهامّ');
        Log::shouldHaveReceived('error')->withArgs(fn ($message) => str_contains($message, 'stale'))->atLeast()->once();
    }

    public function test_a_hold_just_under_the_limit_still_holds(): void
    {
        $ran = $this->oneTask();
        $this->holdSince(DeployHold::STALE_AFTER - 60);

        $this->artisan('schedule:run')->assertSuccessful();

        $this->assertCount(0, $ran);
    }

    /** كلُّ مهمّةٍ مجدولةٍ في المنصّة — اليومَ وما يُضاف غدًا — تقف، لا قائمةٌ مكتوبةٌ باليد */
    public function test_every_real_scheduled_task_is_held(): void
    {
        $events = app(Schedule::class)->events();
        $this->assertGreaterThan(20, count($events), 'لم تُقرأ مهامُّ routes/console.php');
        $this->holdSince(30);

        event(new CommandStarting('schedule:run', new ArrayInput([]), new NullOutput));

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
