<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Business;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Subscription;
use App\Support\Billing;
use DomainException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * سدادان لفاتورةٍ واحدة في اللحظة نفسها يمدّان الاشتراك مرّةً واحدة —
 * وإصداران معًا يتركان تجديدًا معلّقًا واحدًا.
 *
 * ═══ والإثبات سباقٌ حقيقيّ ═══
 *
 * على PostgreSQL وحدها كـ`AnAddonLinkedTwiceAtOnceIsLinkedOnceTest`: عمليّةٌ
 * أخرى باتّصالها تفعل ما يفعله الطلبُ الأوّل وتُبقي معاملتها مفتوحةً ثمّ
 * تُودعها، وفي أثناء ذلك يصل الطلبُ الثاني. ولا `RefreshDatabase`: معاملتُه
 * تُخفي الصفوف عن الاتّصال الآخر، فتُودَع وتُكنَس بعد كلّ اختبار.
 */
class TwoPaymentsOfOneRenewalExtendItOnceTest extends TestCase
{
    use DatabaseTruncation;

    private Business $shop;

    protected function tearDown(): void
    {
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

        $plan = Plan::create(['name' => 'الاحترافية', 'monthly_price' => 29.9, 'yearly_price' => 299]);
        $this->shop = Business::create([
            'name' => 'متجر السباق', 'type' => 'عام', 'status' => 'نشط', 'plan_id' => $plan->id,
            'starts_at' => now()->subMonth()->toDateString(), 'ends_at' => now()->addDays(10)->toDateString(),
        ]);
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

    public function test_a_payment_arriving_while_another_records_it_extends_once(): void
    {
        ['invoice' => $invoice, 'subscription' => $renewal] = Billing::renew($this->shop->fresh(), 'semiannual');
        $from = $this->shop->fresh()->ends_at->toDateString();
        $ends = Billing::extend($this->shop->fresh()->ends_at, 'semiannual')->toDateString();

        // السدادُ الأوّل كما يكتبه `markPaid`: يقفل الفاتورة، ويبدأ التجديد، ويُعلّمها مدفوعة
        $other = $this->anotherRequest(sprintf(
            'SELECT id FROM invoices WHERE id = %d FOR UPDATE;'
            ." UPDATE subscriptions SET status = 'نشط', payment_status = 'مدفوع', starts_at = '%s', ends_at = '%s' WHERE id = %d;"
            ." UPDATE businesses SET ends_at = '%s' WHERE id = %d;"
            ." UPDATE invoices SET status = 'مدفوعة' WHERE id = %d;",
            $invoice->id, $from, $ends, $renewal->id, $ends, $this->shop->id, $invoice->id,
        ));

        // والثاني يصل وهو يُمسك القفل
        Billing::markPaid($invoice);
        $this->finish($other);

        $this->assertSame($ends, $this->shop->fresh()->ends_at->toDateString(), 'مُدّ الاشتراك مرّتين');
        $this->assertSame(Billing::PAID, $invoice->fresh()->status);
        $this->assertSame(1, Subscription::where('business_id', $this->shop->id)->count());

        /*
         * والثاني لم يفعل شيئًا: لو قرأ الفاتورةَ قبل أن يُودع الأوّل لَرآها
         * غيرَ مدفوعة ففعّل التجديد ثانيةً وسجّل سدادًا ثانيًا — والتاريخُ وحده
         * لا يكشفه، فكلاهما حسب من النهاية نفسِها.
         */
        $this->assertSame(0, ActivityLog::where('description', 'like', 'فُعّل تجديد%')->count(), 'فُعّل التجديد مرّة ثانية');
        $this->assertSame(0, ActivityLog::where('description', 'سجّل سداد الفاتورة: '.$invoice->number)->count(), 'سُجّل السداد مرّة ثانية');
    }

    public function test_two_renewals_issued_at_once_leave_one_waiting(): void
    {
        $planId = (int) $this->shop->plan_id;

        // الإصدارُ الأوّل كما يكتبه `renew`: يقفل المتجر، ويكتب الفاتورة وتجديدَها المعلّق
        $other = $this->anotherRequest(sprintf(
            'SELECT id FROM businesses WHERE id = %d FOR UPDATE;'
            .' WITH i AS (INSERT INTO invoices (number, business_id, plan_id, amount, issued_at, status, kind, created_at, updated_at)'
            ." VALUES ('INV-P-9001', %d, %d, 29.9, CURRENT_DATE, 'غير مدفوعة', 'اشتراك', NOW(), NOW()) RETURNING id)"
            .' INSERT INTO subscriptions (business_id, plan_id, amount, payment_status, status, invoice_id, cycle, created_at, updated_at)'
            ." SELECT %d, %d, 29.9, 'غير مدفوع', 'بانتظار السداد', id, 'monthly', NOW(), NOW() FROM i;",
            $this->shop->id, $this->shop->id, $planId, $this->shop->id, $planId,
        ));

        try {
            Billing::renew($this->shop->fresh(), 'yearly');
            $refused = false;
        } catch (DomainException) {
            $refused = true;
        }
        $this->finish($other);

        $this->assertTrue($refused, 'صدر تجديدٌ ثانٍ والأوّل ينتظر');
        $this->assertSame(1, Subscription::where('business_id', $this->shop->id)->where('status', Billing::SUB_PENDING)->count());
        $this->assertSame(1, Invoice::where('business_id', $this->shop->id)->count());
    }
}
