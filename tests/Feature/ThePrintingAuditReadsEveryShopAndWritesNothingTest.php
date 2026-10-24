<?php

namespace Tests\Feature;

use App\Console\Commands\PrintingAudit;
use App\Console\Commands\PrintingWatch;
use App\Models\Branch;
use App\Models\Business;
use App\Models\PosDevice;
use App\Models\PosPeripheral;
use App\Models\PrintJob;
use App\Models\User;
use App\Support\TillHealth;
use Illuminate\Database\QueryException;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * تدقيقُ صناديق كلّ المتاجر (`pos:printing-audit`) — يقرأ ولا يكتب، ولا يُفشي.
 * والمراقبةُ اليوميّة (`pos:printing-watch`) أمرٌ آخر، مطفأٌ حتى يُشعَل.
 *
 * ثلاثةُ متاجر: واحدٌ فيه مشتبهٌ (طابعةٌ على صندوقٍ ملغى وخلفُه بلا طابعة) ومؤكَّدٌ
 * (عنوانٌ يرفضه التطبيق)، وواحدٌ سليم، وواحدٌ بلا طابعةٍ أصلًا — مشروعٌ لا يُعدّ
 * عطلًا.
 */
class ThePrintingAuditReadsEveryShopAndWritesNothingTest extends TestCase
{
    use RefreshDatabase;

    private Business $broken;

    private Business $healthy;

    private Business $paperless;

    private PosDevice $orphan;

    private PosDevice $odd;

    protected function setUp(): void
    {
        parent::setUp();

        $this->broken = Business::create(['name' => 'ورود سعود', 'type' => 'عام', 'status' => 'نشط']);
        $this->healthy = Business::create(['name' => 'متجر سليم', 'type' => 'عام', 'status' => 'نشط']);
        $this->paperless = Business::create(['name' => 'متجر بلا ورق', 'type' => 'عام', 'status' => 'نشط']);

        $old = $this->till($this->broken, PosDevice::REVOKED, '2026-10-01');
        $this->printer($old, ['address' => '192.168.100.235']);
        $this->orphan = $this->till($this->broken, PosDevice::ACTIVE, '2026-10-20');
        $this->odd = $this->till($this->broken, PosDevice::ACTIVE, '2026-10-21');
        $this->printer($this->odd, ['address' => '192.168.1.50:9100']);

        $this->printer($this->till($this->healthy, PosDevice::ACTIVE, '2026-10-01'), ['is_receipt_printer' => true]);
        $this->till($this->paperless, PosDevice::ACTIVE, '2026-10-01');
    }

    private function till(Business $shop, string $status, string $activated): PosDevice
    {
        $branch = Branch::firstOrCreate(['business_id' => $shop->id, 'name' => 'الرئيسي']);

        return PosDevice::create([
            'business_id' => $shop->id, 'branch_id' => $branch->id, 'name' => 'صندوق '.Str::random(4),
            'token_hash' => hash('sha256', Str::random(40)), 'status' => $status,
            'activated_at' => $activated.' 09:00:00', 'last_seen_at' => $activated.' 10:00:00',
        ]);
    }

    private function printer(PosDevice $till, array $over = []): PosPeripheral
    {
        return $till->peripherals()->create($over + [
            'business_id' => $till->business_id, 'name' => 'طابعة', 'type' => PosPeripheral::PRINTER,
            'connection' => 'network', 'address' => '10.0.0.7', 'port' => 9100, 'paper_width' => 80, 'active' => true,
        ]);
    }

    private function audit(array $options = []): array
    {
        Artisan::call('pos:printing-audit', $options + ['--json' => true]);

        return json_decode(Artisan::output(), true);
    }

    public function test_it_counts_what_is_broken_and_what_is_legitimate(): void
    {
        $report = $this->audit(['--detail' => true]);

        $this->assertSame(3, $report['summary']['businesses_with_tills']);
        $this->assertSame(['level' => TillHealth::SUSPECTED, 'tills' => 1, 'businesses' => 1], array_diff_key($report['issues'][TillHealth::PRINTER_ON_REVOKED_TILL], ['advice' => true]));
        $this->assertSame(['level' => TillHealth::CONFIRMED, 'tills' => 1, 'businesses' => 1], array_diff_key($report['issues'][TillHealth::INVALID_ADDRESS], ['advice' => true]));
        $this->assertSame(1, $report['legitimate']['businesses_without_any_printer'], 'متجرٌ بلا طابعة يُعدّ عطلًا');
        $this->assertSame([$this->orphan->id], array_keys(collect($report['detail'][$this->broken->id])->filter(
            fn ($codes) => in_array(TillHealth::PRINTER_ON_REVOKED_TILL, $codes, true))->all()));

        // مؤكَّدٌ · مشتبه · سليم — لكلّ صندوقٍ نشطٍ مرّةً، ولكلّ متجرٍ بأسوأ صناديقه
        $this->assertSame([TillHealth::CONFIRMED => 1, TillHealth::SUSPECTED => 1, 'healthy' => 2], $report['classification']['tills']);
        $this->assertSame([TillHealth::CONFIRMED => 1, TillHealth::SUSPECTED => 0, 'healthy' => 2], $report['classification']['businesses']);
        $this->assertSame($report['summary']['active_tills'], array_sum($report['classification']['tills']));
        $this->assertArrayNotHasKey($this->healthy->id, $report['detail']);
        $this->assertArrayNotHasKey($this->paperless->id, $report['detail']);
    }

    /** ولا يُخرج عنوانًا ولا اسمَ متجرٍ ولا اسمَ صندوق — معرّفاتٌ ورموزٌ وأعداد */
    public function test_it_reveals_no_address_or_name(): void
    {
        Artisan::call('pos:printing-audit', ['--json' => true, '--detail' => true]);
        $json = Artisan::output();
        Artisan::call('pos:printing-audit', ['--detail' => true]);
        $table = Artisan::output();

        foreach ([$json, $table] as $out) {
            $this->assertStringNotContainsString('192.168', $out);
            $this->assertStringNotContainsString('10.0.0.7', $out);
            $this->assertStringNotContainsString('ورود سعود', $out);

            foreach (PosDevice::pluck('name') as $till) {
                $this->assertStringNotContainsString($till, $out, 'اسمُ صندوقٍ في التقرير');
            }
        }
    }

    public function test_it_changes_nothing(): void
    {
        $before = $this->snapshot();
        $this->audit(['--detail' => true]);
        $this->assertSame($before, $this->snapshot());
    }

    private function snapshot(): array
    {
        return [
            DB::table('pos_devices')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
            DB::table('pos_peripherals')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
            DB::table('print_jobs')->count(),
        ];
    }

    /**
     * والقاعدةُ تحرس «قراءةً فقط» — لا حسنُ النيّة: سطرٌ يكتب يسقط.
     *
     * على SQLite وعلى PostgreSQL معًا — وفي PostgreSQL داخل معاملة الاختبار:
     * نقطةُ حفظٍ تُقلب للقراءة ثمّ يُتراجع إليها.
     */
    public function test_a_write_inside_the_audit_is_refused(): void
    {
        try {
            PrintingAudit::readOnly(fn () => DB::table('pos_devices')->update(['name' => 'كُتب']));
            $this->fail('كُتب داخل التدقيق');
        } catch (QueryException) {
            $this->assertSame(0, DB::table('pos_devices')->where('name', 'كُتب')->count());
        }

        // والقاعدةُ تعود قابلةً للكتابة بعده
        DB::table('pos_devices')->where('id', $this->orphan->id)->update(['name' => 'بعد التدقيق']);
        $this->assertSame('بعد التدقيق', $this->orphan->fresh()->name);
    }

    /**
     * وعلى PostgreSQL خارج أيّ معاملة — كما يُشغَّل على الخادم: معاملتُه هو
     * `READ ONLY`، وسطرٌ يكتب يسقط، والاتّصالُ يعود قابلًا للكتابة بعده.
     *
     * اتّصالٌ ثانٍ إلى القاعدة نفسِها: معاملةُ الاختبار على الأوّل لا تمسّه.
     */
    public function test_on_postgres_its_own_transaction_is_read_only(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL وحدَها — SQLite محروسةٌ بـquery_only أعلاه.');
        }

        $default = DB::getDefaultConnection();
        config(['database.connections.printing_audit_probe' => config("database.connections.{$default}")]);
        DB::setDefaultConnection('printing_audit_probe');

        try {
            $this->assertSame(0, DB::transactionLevel());

            try {
                PrintingAudit::readOnly(fn () => DB::table('pos_devices')->where('id', -1)->update(['name' => 'كُتب']));
                $this->fail('كُتب داخل التدقيق على PostgreSQL');
            } catch (QueryException $e) {
                $this->assertStringContainsString('read-only transaction', $e->getMessage());
            }

            $this->assertSame('off', DB::selectOne('SHOW transaction_read_only')->transaction_read_only);
            $this->assertSame(0, DB::transactionLevel());
        } finally {
            DB::purge('printing_audit_probe');
            DB::setDefaultConnection($default);
        }
    }

    /** ولا يكتب في الكاش ولا في السجلّ — ولو كان الكاشُ في القاعدة كما على الإنتاج */
    public function test_the_manual_audit_writes_no_cache_and_no_log(): void
    {
        config(['cache.default' => 'database']);
        Log::spy();

        $this->audit(['--detail' => true]);
        Artisan::call('pos:printing-audit');

        $this->assertSame(0, DB::table('cache')->count());
        $this->assertSame(0, DB::table('cache_locks')->count());
        Log::shouldNotHaveReceived('warning');
        Log::shouldNotHaveReceived('info');
        Log::shouldNotHaveReceived('error');
    }

    /** وعددُ استعلاماته لا يكبر بعدد المتاجر — مئةُ متجرٍ كثلاثة */
    public function test_its_queries_do_not_grow_with_the_number_of_shops(): void
    {
        $count = function (): int {
            $n = 0;
            DB::listen(function () use (&$n) {
                $n++;
            });
            PrintingAudit::collect();

            return $n;
        };

        $three = $count();

        foreach (range(1, 5) as $i) {
            $shop = Business::create(['name' => 'متجر '.$i, 'type' => 'عام', 'status' => 'نشط']);
            $this->printer($this->till($shop, PosDevice::ACTIVE, '2026-10-01'));
        }

        $this->assertSame($three, $count(), 'كلُّ متجرٍ يزيد استعلامًا');
    }

    /** وملحقٌ متجرُه غيرُ متجر صندوقه لا يُنسب إليه — لا خلطَ بين متجرين */
    public function test_a_peripheral_is_never_counted_on_another_shops_till(): void
    {
        DB::table('pos_peripherals')->insert([
            'business_id' => $this->healthy->id, 'pos_device_id' => $this->orphan->id, 'name' => 'دخيلة',
            'type' => PosPeripheral::PRINTER, 'connection' => 'usb', 'active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $report = $this->audit(['--detail' => true]);

        $this->assertContains(TillHealth::PRINTER_ON_REVOKED_TILL, $report['detail'][$this->broken->id][$this->orphan->id], 'نُسبت طابعةُ متجرٍ آخر إلى الصندوق');
        $this->assertArrayNotHasKey($this->healthy->id, $report['detail']);
    }

    /** وورقٌ عالق: حجزٌ فاتت مهلتُه، وورقةٌ لم يستلمها صندوقٌ في يوم */
    public function test_it_counts_stuck_papers_without_naming_them(): void
    {
        $base = ['business_id' => $this->healthy->id, 'kind' => 'customer_invoice_internal', 'subject_type' => 'customer_invoice_preparation'];
        PrintJob::create($base + ['subject_id' => 1, 'dedupe_key' => 'a', 'status' => PrintJob::CLAIMED, 'claim_expires_at' => now()->subMinute()]); // رقمٌ مقصود: موضوعٌ لا يُقرأ
        $stale = PrintJob::create($base + ['subject_id' => 2, 'dedupe_key' => 'b', 'status' => PrintJob::PENDING]); // رقمٌ مقصود: موضوعٌ لا يُقرأ
        DB::table('print_jobs')->where('id', $stale->id)->update(['updated_at' => now()->subHours(30)]);

        $report = $this->audit();

        $this->assertSame(1, $report['queue']['claim_expired']['jobs']);
        $this->assertSame(1, $report['queue']['pending_stale']['jobs']);
        $this->assertSame(1, $report['queue']['pending_stale']['businesses']);
    }

    /* ═══════════ المراقبةُ اليوميّة — أمرٌ آخر، خلف مفتاحٍ مطفأ ═══════════ */

    private function watching(bool $on = true): void
    {
        config(['pos.daily_printing_audit' => $on, 'pos.printing_watch_store' => 'array']);
    }

    /** مطفأةٌ افتراضًا: لا تقرأ ولا تكتب ولا تقول شيئًا */
    public function test_the_daily_watch_is_off_until_the_owner_turns_it_on(): void
    {
        $this->assertFalse(config('pos.daily_printing_audit'), 'المراقبةُ مشتعلةٌ بلا موافقة');
        config(['pos.printing_watch_store' => 'array']);
        Log::spy();

        $this->assertSame(0, Artisan::call('pos:printing-watch'));

        Log::shouldNotHaveReceived('warning');
        $this->assertNull(Cache::store('array')->get(PrintingWatch::KEY));
    }

    /** والجدولةُ تفحص المفتاح قبل أن تناديها — والتدقيقُ اليدويّ لا يُجدوَل أبدًا */
    public function test_the_schedule_skips_the_watch_while_it_is_off(): void
    {
        $events = collect(app(Schedule::class)->events());
        $watch = $events->filter(fn ($e) => str_contains((string) $e->command, 'pos:printing-watch'));

        $this->assertCount(1, $watch);
        $this->assertSame([], $events->filter(fn ($e) => str_contains((string) $e->command, 'pos:printing-audit'))->all(), 'التدقيقُ اليدويّ مجدوَل');

        $this->watching(false);
        $this->assertFalse($watch->first()->filtersPass(app()), 'تُنادى المراقبةُ وهي مطفأة');

        $this->watching();
        $this->assertTrue($watch->first()->filtersPass(app()));
    }

    /** وإطفاءُ المراقبة لا يُطفئ التدقيقَ اليدويّ المأذون */
    public function test_the_manual_audit_runs_with_the_watch_off(): void
    {
        $this->watching(false);

        $this->assertSame(1, $this->audit()['issues'][TillHealth::INVALID_ADDRESS]['tills']);
    }

    /** سطرٌ حين يتغيّر المؤكَّد، وصمتٌ ما دام كما كان — والمشتبهُ لا يحرّكها */
    public function test_the_daily_watch_speaks_only_when_something_confirmed_changes(): void
    {
        $this->watching();
        Log::spy();

        Artisan::call('pos:printing-watch');
        Artisan::call('pos:printing-watch');
        Log::shouldHaveReceived('warning')->once();

        // يزول المشتبه (طابعةُ الملغى تُعطَّل) — لا يتغيّر المؤكَّد، فلا يُقال شيء
        PosPeripheral::where('address', '192.168.100.235')->update(['active' => false]);
        Artisan::call('pos:printing-watch');
        Log::shouldHaveReceived('warning')->once();

        // يُصلَح العنوان — فيتغيّر المؤكَّد، فيُقال مرّةً أخرى
        PosPeripheral::where('address', '192.168.1.50:9100')->update(['address' => '192.168.1.50']);
        Artisan::call('pos:printing-watch');
        Log::shouldHaveReceived('warning')->twice();
    }

    /**
     * ولا تكتب في القاعدة — ولو كان الكاشُ الافتراضيّ فيها، كما على الإنتاج:
     * بصمتُها في مخزنها، ولا قفلَ في `cache_locks`.
     */
    public function test_the_daily_watch_writes_nothing_to_the_database(): void
    {
        config(['cache.default' => 'database']);
        $this->watching();
        $before = $this->snapshot();

        $this->assertSame(0, Artisan::call('pos:printing-watch'));

        $this->assertNotNull(Cache::store('array')->get(PrintingWatch::KEY));
        $this->assertSame(0, DB::table('cache')->count(), 'كُتبت البصمةُ في جدول cache');
        $this->assertSame(0, DB::table('cache_locks')->count());
        $this->assertSame($before, $this->snapshot());
    }

    /** ومخزنٌ في القاعدة يُرفض — ولا يُقرأ شيءٌ بعده */
    public function test_a_watch_store_in_the_database_is_refused(): void
    {
        config(['pos.daily_printing_audit' => true, 'pos.printing_watch_store' => 'database']);
        Log::spy();

        $this->assertSame(1, Artisan::call('pos:printing-watch'));

        $this->assertSame(0, DB::table('cache')->count());
        Log::shouldNotHaveReceived('warning');
    }

    /* ═══════════ جرسُ التاجر — خلف مفتاح، لمن يملك الإعدادات ═══════════ */

    private function owner(Business $shop, string $role = 'admin'): User
    {
        return User::create([
            'business_id' => $shop->id, 'name' => 'مستخدم', 'email' => Str::random(6).'@bell.om',
            'password' => bcrypt('x'), 'role' => $role, 'status' => 'نشط',
        ]);
    }

    private function bell(User $user): array
    {
        return collect($this->actingAs($user)->getJson(route('admin.notifications.feed'))->assertOk()->json('items'))
            ->pluck('key')->filter(fn ($k) => str_starts_with($k, 'till-printer-'))->values()->all();
    }

    public function test_the_bell_stays_silent_until_the_platform_turns_it_on(): void
    {
        $owner = $this->owner($this->broken);

        config(['pos.till_health_bell' => false]);
        $this->assertSame([], $this->bell($owner));

        config(['pos.till_health_bell' => true]);
        $this->assertSame(['till-printer-'.$this->odd->id], $this->bell($owner), 'صفٌّ لكلّ صندوقٍ فيه مؤكَّد — لا أكثر');
    }

    /** والمشتبهُ لا يدقّ الجرس: «طابعةٌ على صندوقٍ ملغى» قد تكون مقصودة */
    public function test_the_bell_never_rings_for_what_is_only_suspected(): void
    {
        config(['pos.till_health_bell' => true]);

        $this->assertNotContains('till-printer-'.$this->orphan->id, $this->bell($this->owner($this->broken)));
    }

    public function test_the_bell_rings_only_for_settings_and_stops_when_fixed(): void
    {
        config(['pos.till_health_bell' => true]);

        // موظفُ المبيعات يدخل اللوحة ولا يملك الإعدادات — لا يُنبَّه بما لا يُدير
        $this->assertSame([], $this->bell($this->owner($this->broken, 'sales')), 'من لا يُدير الأجهزة يُنبَّه بها');
        $this->assertSame([], $this->bell($this->owner($this->healthy)));

        $owner = $this->owner($this->broken);
        PosPeripheral::where('address', '192.168.1.50:9100')->update(['address' => '192.168.1.50']);

        $this->assertSame([], $this->bell($owner));
    }
}
