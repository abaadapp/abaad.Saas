<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Product;
use App\Support\ImageVariants;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * جولةُ الصور القديمة تقف قبل أن تأخذ من البيع وواتساب والدفع ما يحتاجونه.
 *
 * قِيس على الإنتاج: جردٌ بعيّنةٍ أنزل الذاكرةَ المتاحة إلى ٣٢٧ ميغابايت —
 * تحت الحدّ الذي طلبه المالك (٣٥٠) — ومرّ، لأنّ الحارسَ الوحيد كان هامشَ فكّ
 * صورةٍ واحدة. فصار يُسأل قبل كلّ صورةٍ في الجولة (`ImageGate`): الذاكرةُ
 * المتاحة، وحِملُ الدقيقة الأخيرة، والقرص، وطابورُ العامل. وكلُّ قراءةٍ هنا
 * ملفٌّ مُحاكًى أو صفٌّ مزروع — لا يُقرأ جهازُ الاختبار نفسُه.
 */
class AnOldImageWaitsForAQuietServerTest extends TestCase
{
    use RefreshDatabase;

    private string $meminfo;

    private string $loadavg;

    private string $log;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');
        Storage::fake('public');
        Storage::fake('local');

        $this->meminfo = tempnam(sys_get_temp_dir(), 'mem');
        $this->loadavg = tempnam(sys_get_temp_dir(), 'load');
        $this->log = tempnam(sys_get_temp_dir(), 'imglog');
        $this->server(memory: 900, load: '0.10');

        config([
            'images.pause_ms' => 0,
            'images.batch_gate' => true,
            'images.meminfo' => $this->meminfo,
            'images.loadavg' => $this->loadavg,
            'images.gate_min_disk_mb' => 1,
            'logging.channels.images' => ['driver' => 'single', 'path' => $this->log, 'level' => 'warning', 'replace_placeholders' => true],
        ]);

        $shop = Business::create(['name' => 'ورد مسقط', 'type' => 'عام', 'status' => 'نشط']);
        $path = 'products/'.Str::random(40).'.jpg';
        $im = imagecreatetruecolor(1800, 1200);
        mt_srand(3);

        for ($y = 0; $y < 1200; $y += 4) {
            for ($x = 0; $x < 1800; $x += 4) {
                imagefilledrectangle($im, $x, $y, $x + 3, $y + 3, imagecolorallocate($im, mt_rand(60, 220), mt_rand(60, 220), (int) (255 * $x / 1800)));
            }
        }

        ob_start();
        imagejpeg($im, null, 95);
        Storage::disk('public')->put($path, (string) ob_get_clean());
        Product::create(['business_id' => $shop->id, 'name' => 'باقة', 'price' => 5, 'cost' => 1, 'quantity' => 3, 'alert_qty' => 1, 'active' => true, 'image' => $path]);
    }

    protected function tearDown(): void
    {
        @unlink($this->meminfo);
        @unlink($this->loadavg);
        @unlink($this->log);
        parent::tearDown();
    }

    /** خادمٌ مُحاكًى — ذاكرتُه المتاحة بالميغابايت، وسطرُ `/proc/loadavg` */
    private function server(int $memory, string $load): void
    {
        file_put_contents($this->meminfo, "MemTotal:        984064 kB\nMemFree:          90000 kB\nMemAvailable:   ".($memory * 1024)." kB\n");
        file_put_contents($this->loadavg, $load.' 0.20 0.15 1/180 4242'."\n");
    }

    private function original(): string
    {
        return (string) DB::table('products')->value('image');
    }

    /** @return list<string> */
    private function copies(): array
    {
        return array_values(array_filter(Storage::disk('public')->allFiles(), fn ($f) => ImageVariants::isVariant($f)));
    }

    private function assertNothingWasDecoded(): void
    {
        $this->assertSame([], $this->copies(), 'صُنعت نسخةٌ وقد وقفت الدفعة');
    }

    public function test_a_quiet_server_processes_the_batch(): void
    {
        $this->artisan('images:optimize', ['--apply' => true])->expectsOutputToContain('made=1')->assertSuccessful();

        $this->assertNotSame([], $this->copies());
        Storage::disk('public')->assertExists($this->original());
    }

    public function test_less_than_350_mb_available_stops_before_the_first_image(): void
    {
        $this->server(memory: 340, load: '0.10');
        $before = md5((string) Storage::disk('public')->get($this->original()));

        $this->artisan('images:optimize', ['--apply' => true])
            ->expectsOutputToContain('low_memory — الآن 340 والحدّ 350')
            ->assertSuccessful();

        $this->assertNothingWasDecoded();
        $this->assertSame($before, md5((string) Storage::disk('public')->get($this->original())), 'الأصلُ تغيّر');
        $this->assertStringContainsString('images.batch_paused', (string) file_get_contents($this->log));
        $this->assertStringContainsString('"reason":"low_memory"', (string) file_get_contents($this->log));

        // ‏وحين تعود الذاكرة تُكمل الجولةُ التالية ما بقي
        $this->server(memory: 360, load: '0.10');
        $this->artisan('images:optimize', ['--apply' => true])->expectsOutputToContain('made=1')->assertSuccessful();
    }

    public function test_a_load_above_0_8_or_an_unreadable_one_stops_the_batch(): void
    {
        $this->server(memory: 900, load: '0.81');
        $this->artisan('images:optimize', ['--apply' => true])->expectsOutputToContain('busy_cpu')->assertSuccessful();
        $this->assertNothingWasDecoded();

        // ‏و٠٫٨ نفسُها تمرّ — الحدُّ «لا يتجاوز»
        $this->server(memory: 900, load: '0.80');
        $this->artisan('images:optimize', ['--apply' => true])->expectsOutputToContain('made=1')->assertSuccessful();

        foreach ($this->copies() as $f) {
            Storage::disk('public')->delete($f);
        }

        // ‏وما لا يُقرأ يوقف ولا يأذن
        config(['images.loadavg' => sys_get_temp_dir().'/no-such-loadavg-'.Str::random(8)]);
        $this->artisan('images:optimize', ['--apply' => true])->expectsOutputToContain('busy_cpu — الآن لا يُقرأ')->assertSuccessful();
        $this->assertNothingWasDecoded();

        config(['images.meminfo' => sys_get_temp_dir().'/no-such-meminfo-'.Str::random(8), 'images.loadavg' => $this->loadavg]);
        $this->artisan('images:optimize', ['--apply' => true])->expectsOutputToContain('low_memory')->assertSuccessful();
        $this->assertNothingWasDecoded();
    }

    public function test_a_disk_without_room_stops_the_batch(): void
    {
        config(['images.gate_min_disk_mb' => PHP_INT_MAX >> 21]);

        $this->artisan('images:optimize', ['--apply' => true])->expectsOutputToContain('low_disk')->assertSuccessful();

        $this->assertNothingWasDecoded();
    }

    public function test_a_queue_with_whatsapp_and_payment_work_waiting_stops_the_batch(): void
    {
        config(['queue.default' => 'database', 'images.gate_max_queue' => 3]);
        $job = fn (int $available, ?int $reserved = null) => [
            'queue' => 'default', 'payload' => '{}', 'attempts' => 0,
            'reserved_at' => $reserved, 'available_at' => $available, 'created_at' => time(),
        ];

        // ‏ثلاثةٌ جاهزة — الحدّ نفسُه — ومعها ما أخذه العامل وما لم يحن وقتُه: لا يُعدّان
        DB::table('jobs')->insert([$job(time() - 5), $job(time() - 5), $job(time() - 5), $job(time() - 5, time()), $job(time() + 600)]);
        $this->artisan('images:optimize', ['--apply' => true])->expectsOutputToContain('made=1')->assertSuccessful();

        foreach ($this->copies() as $f) {
            Storage::disk('public')->delete($f);
        }

        DB::table('jobs')->insert($job(time() - 1));
        $this->artisan('images:optimize', ['--apply' => true])->expectsOutputToContain('queue_busy')->assertSuccessful();
        $this->assertNothingWasDecoded();
    }

    public function test_two_batches_never_run_at_once(): void
    {
        $other = Cache::lock('images:optimize', 3600);
        $this->assertTrue($other->get());

        $this->artisan('images:optimize', ['--apply' => true])->expectsOutputToContain('جولةٌ أخرى تعمل الآن')->assertFailed();
        $this->assertNothingWasDecoded();

        $other->release();
    }

    public function test_an_upload_does_not_wait_for_the_batch_limits(): void
    {
        $this->server(memory: 900, load: '3.50');
        $path = $this->original();

        // ‏الرفعُ يصنع نسخَه بحارس الفكّ وحده — التاجرُ لا ينتظر هدوءَ الخادم
        ImageVariants::makeQuietly($path);

        Storage::disk('public')->assertExists($path.'.w640.webp');
    }
}
