<?php

namespace Tests\Feature;

use App\Exceptions\ClosedPeriod;
use App\Models\Branch;
use App\Models\Business;
use App\Models\JournalEntry;
use App\Models\User;
use App\Support\BooksPeriod;
use App\Support\Ledger;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * قيدٌ يُكتب وتاريخُ الإقفال يُحفظ في اللحظة نفسها — أحدُهما بعد الآخر.
 *
 * ═══ والخطرُ الذي يُحرس ═══
 *
 * `Ledger::post` يسأل الإقفالَ ثمّ يكتب. فلو سأل قبل أن يأخذ صفَّ المتجر
 * لقرأ «مفتوحة» والإقفالُ يُحفظ في معاملةٍ لم تلتزم بعد، ثمّ انتظر القفلَ
 * وكتب — في فترةٍ أُقفلت قبله بلحظة. ولو حُفظ الإقفالُ بلا القفل نفسه لما
 * انتظر قيدًا جاريًا.
 *
 * ═══ والإثبات سباقٌ حقيقيّ ═══
 *
 * على PostgreSQL وحدها كـ`TwoPaymentsOfOneRenewalExtendItOnceTest`: عمليّةٌ
 * أخرى باتّصالها تُمسك صفَّ المتجر ومعاملتَها مفتوحة، وفي أثناء ذلك يصل
 * الطلبُ الثاني. ولا `RefreshDatabase`: معاملتُه تُخفي الصفوف عن الاتّصال
 * الآخر، فتُودَع وتُكنَس بعد كلّ اختبار.
 */
class AClosingDateAndAnEntryWrittenAtOnceTest extends TestCase
{
    use DatabaseTruncation;

    private Business $shop;

    private User $owner;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        if (DB::connection()->getDriverName() === 'pgsql') {
            $this->truncateTablesForAllConnections();
        }

        parent::tearDown();
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('يُثبَت على PostgreSQL وحدها — SQLite لا تُبقي معاملتين مفتوحتين معًا');
        }

        Carbon::setTestNow('2026-10-20 10:00:00');

        $this->shop = Business::create(['name' => 'متجر السباق', 'type' => 'عام', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->shop->id, 'name' => 'الرئيسي']);
        $this->owner = User::create([
            'business_id' => $this->shop->id, 'name' => 'المالك', 'email' => 'race@abaadapp.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        Ledger::seedChart($this->shop->id);
    }

    /**
     * طلبٌ آخر باتّصاله: يكتب ما يكتبه ويُمسك معاملتَه ثمّ يُودعها.
     *
     * @return resource
     */
    private function anotherRequest(string $sql, float $holdSeconds = 1.5)
    {
        $c = DB::connection()->getConfig();
        $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', $c['host'], $c['port'] ?? 5432, $c['database']);
        $code = sprintf(
            '$p = new PDO(%s, %s, %s); $p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);'
            .' $p->beginTransaction(); $p->exec(%s); usleep(%d); $p->commit();',
            var_export($dsn, true), var_export((string) $c['username'], true), var_export((string) ($c['password'] ?? ''), true),
            var_export($sql, true), (int) ($holdSeconds * 1_000_000),
        );

        $proc = proc_open([PHP_BINARY, '-r', $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($proc, 'تعذّر تشغيل الطلب الآخر');

        // يُمهَل حتى يكتب فعلًا قبل أن يصل طلبُنا
        usleep(600_000);

        return $proc;
    }

    private function finish($proc): void
    {
        $this->assertSame(0, proc_close($proc), 'سقط الطلب الآخر');
    }

    public function test_an_entry_arriving_while_the_books_are_being_closed_is_refused(): void
    {
        // الإقفالُ كما يحفظه `SettingController::update`: يقفل المتجر ثمّ يكتب التاريخ
        $other = $this->anotherRequest(sprintf(
            'SELECT id FROM businesses WHERE id = %d FOR UPDATE;'
            ." INSERT INTO settings (business_id, key, value, created_at, updated_at) VALUES (%d, '%s', '2026-09-30', NOW(), NOW());",
            $this->shop->id, $this->shop->id, BooksPeriod::SETTING,
        ));

        try {
            Ledger::post($this->shop->id, 'قيدٌ وصل والإقفالُ يُحفظ', [
                ['account' => 'cash', 'debit' => 10],
                ['account' => 'sales', 'credit' => 10],
            ], Carbon::parse('2026-09-15'));
            $refused = false;
        } catch (ClosedPeriod) {
            $refused = true;
        }
        $this->finish($other);

        $this->assertTrue($refused, 'قرأ القيدُ «مفتوحة» قبل أن يلتزم الإقفال، ثمّ كتب في فترةٍ أُقفلت');
        $this->assertSame(0, JournalEntry::where('business_id', $this->shop->id)->count());
    }

    public function test_saving_a_closing_date_waits_for_an_entry_being_written(): void
    {
        // قيدٌ جارٍ كما يبدأه `Ledger::post`: يأخذ صفَّ المتجر ويُمسك معاملتَه
        $other = $this->anotherRequest(sprintf('SELECT id FROM businesses WHERE id = %d FOR UPDATE;', $this->shop->id));

        $started = microtime(true);
        $this->actingAs($this->owner)
            ->post(route('admin.settings.update'), ['books_closed_through' => '2026-09-30'])
            ->assertSessionHasNoErrors();
        $waited = microtime(true) - $started;
        $this->finish($other);

        $this->assertGreaterThan(0.5, $waited, 'حُفظ الإقفالُ وقيدٌ يُكتب لم يلتزم بعد — بلا قفل المتجر');
        $this->assertSame('2026-09-30', BooksPeriod::booksClosedThrough($this->shop->id)?->toDateString());
    }
}
