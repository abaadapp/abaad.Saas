<?php

namespace Tests\Feature;

use App\Console\Commands\DeployStateCommand;
use App\Support\DeployState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * النشرُ يسأل التطبيقَ قبل أن يبدّل الكود: أتجري مهمّة؟ وأيُّ هجرةٍ لم تجرِ؟ — قراءةً وحدها.
 *
 * ═══ ما كان ═══
 *
 * بعد `queue:pause` كان النشرُ يُمهل المهمّةَ الجاريةَ خمسَ عشرةَ ثانيةً ثمّ يسحب
 * الكود. ومهمّةٌ أطولُ منها — فاتورةُ طلبٍ بصفحات، أو دفعةُ رسائل — يتبدّل الكودُ
 * تحتها: ما لم تحمّله من ملفّاتٍ بعدُ تحمّله جديدًا في منتصفها.
 *
 * ═══ ما صار ═══
 *
 * `abaad:deploy-state` (بحساب www-data، مثبَّتٌ في sudoers بلا وسائط) يقرأ صفوفَ
 * `jobs` المحجوزة على طابورَي النشر، والهجراتِ التي على القرص ولم تجرِ. فينتظر
 * النشرُ حتى لا يبقى محجوز، ويقول عند السقوط ما لم يجرِ. ولا يكتب شيئًا، ولا يطبع
 * من المهمّة إلّا اسمَها — سجلُّ النشر عامّ، والحمولةُ فيها رقمُ زبونٍ ونصُّ رسالته.
 */
class TheDeployAsksTheAppWhatStillRunsTest extends TestCase
{
    use RefreshDatabase;

    private string $pendingDir = '';

    protected function setUp(): void
    {
        parent::setUp();
        config(['queue.default' => 'database', 'queue.connections.database.retry_after' => 90]);
    }

    protected function tearDown(): void
    {
        if ($this->pendingDir !== '' && is_dir($this->pendingDir)) {
            exec('rm -rf '.escapeshellarg($this->pendingDir));
        }
        parent::tearDown();
    }

    /** مهمّةٌ في الطابور كما يكتبها Laravel — بحمولةٍ فيها ما لا يُطبع */
    private function job(string $queue, ?int $reservedSecondsAgo, string $name = 'App\\Jobs\\SendWhatsAppMessage'): int
    {
        return (int) DB::table('jobs')->insertGetId([
            'queue' => $queue,
            'payload' => json_encode(['displayName' => $name, 'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
                'data' => ['command' => 'O:28:"App\\Jobs\\SendWhatsAppMessage":1:{s:5:"phone";s:11:"96899112233";s:4:"text";s:9:"سرّ الزبون";}']]),
            'attempts' => $reservedSecondsAgo === null ? 0 : 1,
            'reserved_at' => $reservedSecondsAgo === null ? null : time() - $reservedSecondsAgo,
            'available_at' => time(),
            'created_at' => time(),
        ]);
    }

    /** @return array{0: int, 1: string} */
    private function ask(): array
    {
        $rc = Artisan::call('abaad:deploy-state');

        return [$rc, Artisan::output()];
    }

    public function test_a_job_the_worker_holds_now_is_running_and_named_by_its_class_alone(): void
    {
        $id = $this->job('whatsapp', 40);
        $before = DB::table('jobs')->get()->toArray();

        [$rc, $out] = $this->ask();

        $this->assertSame(DeployStateCommand::BUSY, $rc, $out);
        $this->assertStringContainsString('running: 1', $out);
        // في أوّل السطر كما يقرؤه السكربت (`grep '^job: '`) — لا بمسافاتٍ يطويها Laravel
        $this->assertMatchesRegularExpression('/^job: #'.$id.' whatsapp App\\\\Jobs\\\\SendWhatsAppMessage · 40s · attempt 1$/mu', $out);
        foreach (['scripts/deploy.sh', 'scripts/rollback-code.sh'] as $script) {
            $this->assertStringContainsString("grep '^job: ' \"\$STATE_OUT\"", (string) file_get_contents(base_path($script)), "$script لا يقرأ سطرَ المهمّة كما يُطبع");
        }
        // ولا شيءَ من حمولتها: لا رقمَ الزبون ولا نصَّ رسالته
        $this->assertStringNotContainsString('96899112233', $out);
        $this->assertStringNotContainsString('سرّ الزبون', $out);
        // ويقرأ ولا يكتب: الطابورُ كما كان
        $this->assertEquals($before, DB::table('jobs')->get()->toArray());
    }

    public function test_nothing_runs_when_jobs_only_wait_or_were_left_by_a_dead_worker_or_sit_on_another_queue(): void
    {
        $this->job('default', null);                 // تنتظر عاملًا — لا تجري
        $this->job('whatsapp', 600);                 // حجزٌ أقدمُ من retry_after: عاملٌ مات وتركها
        $this->job('emails', 5, 'App\\Jobs\\Other'); // طابورٌ لا يوقفه النشر

        [$rc, $out] = $this->ask();

        $this->assertSame(DeployStateCommand::IDLE, $rc, $out);
        $this->assertStringContainsString('running: 0', $out);
        $this->assertStringContainsString('abandoned: 1', $out);
    }

    public function test_the_two_queues_it_watches_are_the_two_the_deploy_pauses(): void
    {
        $this->assertSame(['whatsapp', 'default'], DeployState::QUEUES);
        foreach (['scripts/deploy.sh', 'scripts/rollback-code.sh'] as $script) {
            $code = (string) file_get_contents(base_path($script));
            foreach (DeployState::QUEUES as $queue) {
                $this->assertStringContainsString('art queue:pause '.$queue, $code, "$script لا يوقف $queue");
            }
            // والسكربتُ يقرأ رموزَ الخروج كما يخرج بها الأمر
            $this->assertStringContainsString('            '.DeployStateCommand::IDLE.')', $code, $script);
            $this->assertStringContainsString('            '.DeployStateCommand::BUSY.')', $code, $script);
        }
    }

    public function test_a_queue_outside_the_database_cannot_be_read_so_the_deploy_falls_back(): void
    {
        config(['queue.default' => 'sync']);

        [$rc, $out] = $this->ask();

        $this->assertSame(DeployStateCommand::UNKNOWN_QUEUE, $rc, $out);
        $this->assertStringContainsString('running: ?', $out);
    }

    public function test_it_names_the_migrations_on_disk_that_have_not_run(): void
    {
        [, $out] = $this->ask();
        $this->assertStringContainsString('pending-migrations: 0', $out);

        $this->pendingDir = sys_get_temp_dir().'/abaad-pending-'.bin2hex(random_bytes(4));
        mkdir($this->pendingDir);
        file_put_contents($this->pendingDir.'/2099_01_01_000000_not_yet.php', "<?php\n\nreturn new class extends Illuminate\\Database\\Migrations\\Migration {};\n");
        app('migrator')->path($this->pendingDir);

        [, $out] = $this->ask();
        $this->assertStringContainsString('pending-migrations: 1', $out);
        $this->assertMatchesRegularExpression('/^migration: 2099_01_01_000000_not_yet$/m', $out);
        $this->assertStringContainsString("awk '/^migration: /", (string) file_get_contents(base_path('scripts/deploy.sh')), 'deploy.sh لا يقرأ سطرَ الهجرة كما يُطبع');
        $this->assertSame(['2099_01_01_000000_not_yet'], DeployState::pendingMigrations());
    }

    public function test_when_the_database_cannot_say_what_ran_it_says_so_and_never_zero(): void
    {
        Schema::drop('migrations');

        [, $out] = $this->ask();

        $this->assertStringContainsString('pending-migrations: ?', $out);
        $this->assertNull(DeployState::pendingMigrations());
    }

    public function test_a_lock_held_by_a_running_deploy_is_seen_and_the_look_holds_nothing(): void
    {
        $file = sys_get_temp_dir().'/abaad-deploy-lock-'.bin2hex(random_bytes(4));
        config(['app.deploy_lock_file' => $file]);
        $this->assertNull(DeployState::deployRunning(), 'لا ملفَّ قفل: لا يُعرف');

        $deploy = fopen($file, 'c');
        $this->assertFalse(DeployState::deployRunning(), 'لا نشرَ يمسكه');
        $this->assertTrue(flock($deploy, LOCK_EX | LOCK_NB), 'السؤالُ أمسك القفلَ بعده فردّ النشر');
        $this->assertTrue(DeployState::deployRunning(), 'نشرٌ يمسكه ولم يُرَ');

        flock($deploy, LOCK_UN);
        fclose($deploy);
        unlink($file);
    }
}
