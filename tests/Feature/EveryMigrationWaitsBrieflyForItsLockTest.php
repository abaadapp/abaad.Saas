<?php

namespace Tests\Feature;

use App\Support\MigrationLockTimeout;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * كلُّ هجرةٍ تنتظر قفلَها مهلةً قصيرة ثمّ تسقط — لا تقف خلف تقريرٍ طويلٍ ويقف الصندوقُ خلفها.
 *
 * ═══ كيف يُثبَت ═══
 *
 * اتّصالٌ آخر (عمليّةٌ مستقلّة) يمسك جدولًا بقفلِ قراءةٍ ثوانيَ، كتقريرٍ طويل. وهجرةٌ
 * حقيقيّةٌ عبر `artisan migrate` تضيف عمودًا إليه — فتطلب قفلًا يتعارض معه:
 *
 *   - قبل `MigrationLockTimeout`: تنتظر حتى يُفلت الجدول، ثمّ تنجح. أي أنّ كلَّ ما
 *     يصل بعدها من بيعٍ يقف طوالَ ذلك.
 *   - بعده: تسقط بـ55P03 في نحو مهلتها، والعمودُ لم يُضف، ولا صفَّ لها في جدول
 *     الهجرات. وحين يُفلت القفلُ تُعاد فتنجح — وهو ما يفعله `scripts/deploy.sh`.
 *
 * ولا يبقى أثرُ المهلة في الاتّصال بعد الهجرة: `SET LOCAL` داخل معاملتها وحدها.
 */
class EveryMigrationWaitsBrieflyForItsLockTest extends TestCase
{
    private const TABLE = 'deploy_lock_probe';

    private string $dir = '';

    protected function tearDown(): void
    {
        if ($this->dir !== '') {
            DB::table('migrations')->where('migration', 'like', '2099_01_01_000000_probe_%')->delete();
            Schema::dropIfExists(self::TABLE);
            exec('rm -rf '.escapeshellarg($this->dir));
        }
        parent::tearDown();
    }

    /** هجرةٌ حقيقيّةٌ في مجلّدٍ مؤقّت، يجريها `artisan migrate` كما يجريها النشر */
    private function migration(string $name, string $up): string
    {
        $this->dir = $this->dir !== '' ? $this->dir : sys_get_temp_dir().'/abaad-lock-probe-'.bin2hex(random_bytes(4));
        is_dir($this->dir) || mkdir($this->dir, 0700, true);
        file_put_contents($this->dir.'/2099_01_01_000000_probe_'.$name.'.php', "<?php\n\nuse Illuminate\\Database\\Migrations\\Migration;\nuse Illuminate\\Database\\Schema\\Blueprint;\nuse Illuminate\\Support\\Facades\\DB;\nuse Illuminate\\Support\\Facades\\Schema;\n\nreturn new class extends Migration\n{\n    public function up(): void\n    {\n        ".$up."\n    }\n};\n");

        return $this->dir;
    }

    private function migrate(): void
    {
        Artisan::call('migrate', ['--path' => $this->dir, '--realpath' => true, '--force' => true]);
    }

    private function pgsql(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('يُثبَت على PostgreSQL وحدها — قاعدةُ الإنتاج، و`lock_timeout` منها');
        }
    }

    /** اتّصالٌ آخر يمسك الجدولَ بقفلِ قراءةٍ ثوانيَ — كتقريرٍ طويل */
    private function hold(float $seconds): array
    {
        $c = DB::connection()->getConfig();
        $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', $c['host'], $c['port'] ?? 5432, $c['database']);
        $code = sprintf(
            '$p = new PDO(%s, %s, %s); $p->beginTransaction(); $p->exec("LOCK TABLE %s IN ACCESS SHARE MODE"); echo "locked\n"; fflush(STDOUT); $until = microtime(true) + %F; while (microtime(true) < $until) { usleep(20000); } $p->commit();',
            var_export($dsn, true), var_export((string) $c['username'], true), var_export((string) ($c['password'] ?? ''), true),
            self::TABLE, $seconds,
        );
        $proc = proc_open([PHP_BINARY, '-r', $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($proc);
        if (trim((string) fgets($pipes[1])) !== 'locked') {
            $this->fail('لم يُمسك الاتّصالُ الآخرُ الجدول: '.stream_get_contents($pipes[2]));
        }

        return [$proc, $pipes];
    }

    private function release(array $held): void
    {
        [$proc, $pipes] = $held;
        stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($proc), $err);
    }

    public function test_on_postgresql_a_migration_behind_a_long_reader_gives_up_in_its_timeout_and_runs_once_the_lock_frees(): void
    {
        $this->pgsql();
        config(['database.migrations.lock_timeout' => '1s']);
        Schema::create(self::TABLE, fn ($t) => $t->id());
        $this->migration('adds_a_column', "Schema::table('".self::TABLE."', fn (Blueprint \$t) => \$t->string('note')->nullable());");

        $held = $this->hold(8);
        $started = microtime(true);
        $error = null;
        try {
            $this->migrate();
        } catch (QueryException $e) {
            $error = $e;
        }
        $waited = microtime(true) - $started;
        $this->release($held);

        $this->assertNotNull($error, sprintf('الهجرةُ انتظرت القفلَ %.1f ثانيةً ونجحت — بلا مهلة', $waited));
        $this->assertSame('55P03', (string) ($error->errorInfo[0] ?? $error->getCode()), $error->getMessage());
        $this->assertLessThan(5.0, $waited, 'سقطت، لكن بعد انتظارٍ طويل');
        $this->assertFalse(Schema::hasColumn(self::TABLE, 'note'), 'أُضيف العمودُ مع سقوط الهجرة');
        $this->assertSame(0, DB::table('migrations')->where('migration', '2099_01_01_000000_probe_adds_a_column')->count(), 'سُجّلت هجرةٌ سقطت');

        // وحين يُفلت القفلُ تُعاد — فتنجح، وتُسجَّل مرّةً واحدة
        $this->migrate();

        $this->assertTrue(Schema::hasColumn(self::TABLE, 'note'));
        $this->assertSame(1, DB::table('migrations')->where('migration', '2099_01_01_000000_probe_adds_a_column')->count());
        // والمهلةُ لم تبقَ في الاتّصال بعد الهجرة
        $this->assertSame('0', (string) DB::selectOne('show lock_timeout')->lock_timeout);
    }

    public function test_on_postgresql_the_timeout_is_set_inside_the_migration_and_a_self_managed_one_is_left_alone(): void
    {
        $this->pgsql();
        config(['database.migrations.lock_timeout' => '2500ms']);
        Schema::create(self::TABLE, function ($t) {
            $t->id();
            $t->string('seen')->nullable();
        });
        // هجرةٌ عاديّة تقرأ مهلتَها من داخلها، وأخرى تدير معاملتَها بنفسها (`$withinTransaction = false`)
        $this->migration('reads', "DB::table('".self::TABLE."')->insert(['seen' => 'in:'.DB::selectOne('show lock_timeout')->lock_timeout]);");
        file_put_contents($this->dir.'/2099_01_01_000000_probe_self.php', "<?php\n\nuse Illuminate\\Database\\Migrations\\Migration;\nuse Illuminate\\Support\\Facades\\DB;\n\nreturn new class extends Migration\n{\n    public \$withinTransaction = false;\n\n    public function up(): void\n    {\n        DB::table('".self::TABLE."')->insert(['seen' => 'self:'.DB::selectOne('show lock_timeout')->lock_timeout]);\n    }\n};\n");

        $this->migrate();

        $seen = DB::table(self::TABLE)->orderBy('id')->pluck('seen')->all();
        $this->assertContains('in:2500ms', $seen, 'المهلةُ لم تُضبط داخل الهجرة');
        $this->assertContains('self:0', $seen, 'مُسّت هجرةٌ تدير معاملتَها بنفسها');
    }

    public function test_an_unreadable_timeout_is_refused_before_any_migration_runs(): void
    {
        foreach (['forever', '3', '-1s', '1.5s', ''] as $bad) {
            config(['database.migrations.lock_timeout' => $bad]);
            try {
                MigrationLockTimeout::value();
                $this->fail("قُبلت مهلةٌ غيرُ مقروءة: «{$bad}»");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('lock_timeout', $e->getMessage());
            }
        }
        foreach (['0', '3s', '500ms'] as $good) {
            config(['database.migrations.lock_timeout' => $good]);
            $this->assertSame($good, MigrationLockTimeout::value());
        }
    }

    public function test_every_migration_run_asks_for_the_timeout(): void
    {
        // على أيّ قاعدة: الهجرةُ تمرّ بالمهلة قبل أن تجري — فإعدادٌ خاطئٌ يوقفها لا يُقرأ «بلا مهلة»
        config(['database.migrations.lock_timeout' => 'forever']);
        Schema::dropIfExists(self::TABLE);
        $this->migration('creates', "Schema::create('".self::TABLE."', fn (Blueprint \$t) => \$t->id());");

        try {
            $this->migrate();
            $this->fail('جرت هجرةٌ بمهلةٍ غيرِ مقروءة');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('lock_timeout', $e->getMessage());
        }
        $this->assertFalse(Schema::hasTable(self::TABLE));

        config(['database.migrations.lock_timeout' => '3s']);
        $this->migrate();
        $this->assertTrue(Schema::hasTable(self::TABLE));
    }
}
