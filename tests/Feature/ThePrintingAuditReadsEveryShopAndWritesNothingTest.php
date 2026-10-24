<?php

namespace Tests\Feature;

use App\Console\Commands\PrintingAudit;
use App\Models\Branch;
use App\Models\Business;
use App\Models\PosDevice;
use App\Models\PosPeripheral;
use App\Models\PrintJob;
use App\Models\User;
use App\Support\TillHealth;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * تدقيقُ صناديق كلّ المتاجر (`pos:printing-audit`) — يقرأ ولا يكتب، ولا يُفشي.
 *
 * ثلاثةُ متاجر: واحدٌ وقع فيه الأثرُ المبحوثُ عنه (طابعةٌ على صندوقٍ ملغى وخلفُه
 * بلا طابعة، وعنوانٌ يرفضه التطبيق)، وواحدٌ سليم، وواحدٌ بلا طابعةٍ أصلًا —
 * مشروعٌ لا يُعدّ عطلًا.
 */
class ThePrintingAuditReadsEveryShopAndWritesNothingTest extends TestCase
{
    use RefreshDatabase;

    private Business $broken;

    private Business $healthy;

    private Business $paperless;

    private PosDevice $orphan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->broken = Business::create(['name' => 'ورود سعود', 'type' => 'عام', 'status' => 'نشط']);
        $this->healthy = Business::create(['name' => 'متجر سليم', 'type' => 'عام', 'status' => 'نشط']);
        $this->paperless = Business::create(['name' => 'متجر بلا ورق', 'type' => 'عام', 'status' => 'نشط']);

        $old = $this->till($this->broken, PosDevice::REVOKED, '2026-10-01');
        $this->printer($old, ['address' => '192.168.100.235']);
        $this->orphan = $this->till($this->broken, PosDevice::ACTIVE, '2026-10-20');
        $odd = $this->till($this->broken, PosDevice::ACTIVE, '2026-10-21');
        $this->printer($odd, ['address' => '192.168.1.50:9100']);

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
        $this->assertSame(['level' => TillHealth::CONFIRMED, 'tills' => 1, 'businesses' => 1], array_diff_key($report['issues'][TillHealth::ORPHANED_PRINTER], ['advice' => true]));
        $this->assertSame(1, $report['issues'][TillHealth::INVALID_ADDRESS]['tills']);
        $this->assertSame(1, $report['legitimate']['businesses_without_any_printer'], 'متجرٌ بلا طابعة يُعدّ عطلًا');
        $this->assertSame([$this->orphan->id], array_keys(collect($report['detail'][$this->broken->id])->filter(
            fn ($codes) => in_array(TillHealth::ORPHANED_PRINTER, $codes, true))->all()));
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

    /** والقاعدةُ تحرس «قراءةً فقط» — لا حسنُ النيّة: سطرٌ يكتب يسقط */
    public function test_a_write_inside_the_audit_is_refused(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('على PostgreSQL تُفرض المعاملةُ READ ONLY خارج معاملة الاختبار وحدها.');
        }

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

    /** والفحصُ الدوريّ: سطرٌ حين يتغيّر المؤكَّد، وصمتٌ ما دام كما كان */
    public function test_the_daily_watch_speaks_only_when_something_confirmed_changes(): void
    {
        Log::spy();

        Artisan::call('pos:printing-audit', ['--watch' => true]);
        Artisan::call('pos:printing-audit', ['--watch' => true]);
        Log::shouldHaveReceived('warning')->once();

        // يُصلَح العنوان — فيتغيّر المؤكَّد، فيُقال مرّةً أخرى
        PosPeripheral::where('address', '192.168.1.50:9100')->update(['address' => '192.168.1.50']);
        Artisan::call('pos:printing-audit', ['--watch' => true]);
        Log::shouldHaveReceived('warning')->twice();
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
        $keys = $this->bell($owner);
        $this->assertContains('till-printer-'.$this->orphan->id, $keys);
        $this->assertCount(2, $keys, 'صفٌّ لكلّ صندوقٍ فيه مؤكَّد — لا أكثر');
    }

    public function test_the_bell_rings_only_for_settings_and_stops_when_fixed(): void
    {
        config(['pos.till_health_bell' => true]);

        // موظفُ المبيعات يدخل اللوحة ولا يملك الإعدادات — لا يُنبَّه بما لا يُدير
        $this->assertSame([], $this->bell($this->owner($this->broken, 'sales')), 'من لا يُدير الأجهزة يُنبَّه بها');
        $this->assertSame([], $this->bell($this->owner($this->healthy)));

        $owner = $this->owner($this->broken);
        PosPeripheral::where('pos_device_id', '!=', $this->orphan->id)->where('business_id', $this->broken->id)
            ->where('address', '192.168.100.235')->update(['pos_device_id' => $this->orphan->id]);
        PosPeripheral::where('address', '192.168.1.50:9100')->update(['address' => '192.168.1.50']);

        $this->assertSame([], $this->bell($owner));
    }
}
