<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\CustomerCreditNote;
use App\Models\CustomerCreditNoteOrder;
use App\Models\CustomerInvoice;
use App\Models\JournalEntry;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Support\CreditNoteOrderRefused;
use App\Support\CreditNoteOrders;
use App\Support\CustomerInvoices;
use App\Support\Ledger;
use App\Support\Receivables;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * يدان على تسمية إشعارٍ دائن في اللحظة نفسها — على PostgreSQL باتّصالين حقيقيّين.
 *
 * ═══ لمَ ═══
 *
 * التسميةُ تقرأ ما سُمّي من قبل عن الطلب، وحالَه، وهل سُمّي الإشعارُ نفسه — ثمّ
 * تكتب. ولو قرأت قبل أن تُغلق يدٌ أخرى ما بدأته لمرّ إشعاران مجموعُهما فوق
 * الطلب، أو سُمّي طلبٌ أُلغي للتوّ فعُكس إيرادُه مرّتين. فالتسميةُ تأخذ قفلَ
 * المتجر أوّلًا — القفلَ نفسَه الذي يأخذه التحصيل والإلغاء — ثمّ تقرأ.
 *
 * ═══ كيف ═══
 *
 * اتّصالٌ آخر (عمليّة PHP مستقلّة) يأخذ القفلَ ويكتب ويمسكه ثانيةً ونصفًا. والتسميةُ
 * هنا تبدأ في أثنائه: يجب أن تنتظر، ثمّ ترى ما كتبه. وSQLite لا تُبقي معاملتين
 * مفتوحتين معًا، فيُتخطّى عليها — وتبقى حالاتُ التداخل في الاتّصال الواحد في
 * `ACreditNoteNamesTheOrderItWasForTest`.
 */
class TwoHandsOnOneCreditNoteNamingNameItOnceTest extends TestCase
{
    use DatabaseTruncation;

    private Business $shop;

    private User $owner;

    private Product $roses;

    private Customer $company;

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

        app()->setLocale('ar');
        $this->shop = Business::create(['name' => 'زهور السباق', 'type' => 'عام', 'status' => 'نشط']);
        Currency::create([
            'business_id' => $this->shop->id, 'code' => 'OMR', 'name' => 'ريال عماني',
            'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true,
        ]);
        Ledger::seedChart($this->shop->id);
        Setting::create(['business_id' => $this->shop->id, 'key' => 'vat_enabled', 'value' => '0']);
        $this->owner = User::create([
            'business_id' => $this->shop->id, 'name' => 'مالك السباق', 'email' => 'owner@race-named.om',
            'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        $this->roses = Product::create([
            'business_id' => $this->shop->id, 'name' => 'باقة ورد', 'price' => 10, 'cost' => 4, 'quantity' => 500, 'active' => true,
        ]);
        $this->company = Customer::create([
            'language' => 'ar', 'business_id' => $this->shop->id, 'name' => 'شركة السباق',
            'allow_credit_sales' => true, 'monthly_billing' => true, 'payment_terms_days' => 30,
        ]);
        $this->actingAs($this->owner);
    }

    /* ═══ أدوات ═══ */

    private function sell(int $qty): Order
    {
        $this->postJson('/pos/checkout', [
            'items' => [['id' => $this->roses->id, 'name' => 'باقة ورد', 'qty' => $qty, 'price' => 10]],
            'payment_method' => 'نقدي', 'credit' => true, 'customer_id' => $this->company->id,
        ])->assertOk();

        return Order::latest('id')->first();
    }

    /** فاتورةُ شهرٍ تجمع طلباتٍ بالكمّيّات المعطاة — كلُّ باقةٍ بعشرة */
    private function month(int ...$quantities): array
    {
        $orders = array_map(fn (int $q) => $this->sell($q), $quantities);

        return [CustomerInvoices::consolidate($this->company, array_map(fn ($o) => $o->id, $orders), [], $this->owner->id), ...$orders];
    }

    /** إشعارُ إرجاعٍ بلا طلبٍ مسمّى — يُسمّى لاحقًا */
    private function unnamedNote(CustomerInvoice $invoice, float $amount): CustomerCreditNote
    {
        static $n = 0;
        $this->post('/admin/customer-invoices/'.$invoice->id.'/credit-note', [
            'amount' => $amount, 'tax_amount' => 0, 'reason' => 'رُدّت باقات', 'basis' => CustomerCreditNote::RETURN, 'token' => 'race-'.(++$n),
        ])->assertSessionHasNoErrors();

        return CustomerCreditNote::latest('id')->firstOrFail();
    }

    /**
     * يدٌ أخرى: عمليّةٌ مستقلّة تنفّذ `$sql` في معاملةٍ وتمسكها ثمّ تُثبتها.
     *
     * @return resource
     */
    private function elsewhere(string $sql, float $holdSeconds = 1.5)
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
        $this->assertIsResource($proc, 'تعذّر تشغيل اليد الأخرى');
        usleep(600_000); // تُمهَل حتى تقفل فعلًا

        return $proc;
    }

    private function lockShop(): string
    {
        return sprintf('SELECT id FROM businesses WHERE id = %d FOR UPDATE;', $this->shop->id);
    }

    private function namingRow(CustomerCreditNote $note, CustomerInvoice $invoice, Order $order, float $amount): string
    {
        return sprintf(
            'INSERT INTO customer_credit_note_orders (business_id, customer_credit_note_id, customer_invoice_id, order_id, mode, amount, tax_amount, reason, linked_by, linked_by_name, created_at, updated_at)'
            ." VALUES (%d, %d, %d, %d, '%s', %s, 0, 'اليد الأخرى', %d, 'المالك', now(), now());",
            $this->shop->id, $note->id, $invoice->id, $order->id, CustomerCreditNoteOrder::LATER, number_format($amount, 3, '.', ''), $this->owner->id,
        );
    }

    /** ما لا يحرّكه الربط: القيود والذمم والإيراد والمردودات والضريبة وما على الفاتورة */
    private function money(CustomerInvoice $invoice): array
    {
        return [
            JournalEntry::where('business_id', $this->shop->id)->count(),
            CustomerCreditNote::where('customer_invoice_id', $invoice->id)->orderBy('id')->get(['number', 'amount', 'tax_amount'])->toArray(),
            $invoice->fresh()->outstanding(),
            round(Ledger::balance($this->shop->id, 'receivable'), 3),
            round(Ledger::balance($this->shop->id, 'sales'), 3),
            round(Ledger::balance($this->shop->id, 'sales_returns'), 3),
            round(Ledger::balance($this->shop->id, 'tax_payable'), 3),
            (int) $this->roses->fresh()->quantity,
        ];
    }

    private function nameNow(CustomerCreditNote $note, Order $order): array
    {
        $started = microtime(true);
        try {
            CreditNoteOrders::link($note->fresh(), $order->id, 'تحقّقتُ من سند الاستلام', $this->owner->id);
            $refused = null;
        } catch (CreditNoteOrderRefused $e) {
            $refused = $e->getMessage();
        }

        return [$refused, microtime(true) - $started];
    }

    /* ═══ الحالات ═══ */

    public function test_two_notes_named_to_one_order_at_once_never_pass_its_total(): void
    {
        // طلبٌ بثمانين في فاتورةٍ بمئتين، وإشعاران بخمسين وأربعين: كلٌّ يسع الطلبَ وحده، ومعًا يتجاوزانه
        [$invoice, $a] = $this->month(8, 12);
        $first = $this->unnamedNote($invoice, 50);
        $second = $this->unnamedNote($invoice, 40);

        $other = $this->elsewhere($this->lockShop().' '.$this->namingRow($second, $invoice, $a, 40));
        [$refused, $waited] = $this->nameNow($first, $a);
        $this->assertSame(0, proc_close($other), 'سقطت اليدُ الأخرى');

        $this->assertGreaterThan(0.5, $waited, 'لم تنتظر التسميةُ قفلَ المتجر');
        $this->assertNotNull($refused, 'سُمّي عن الطلب أكثرُ من ثمنه — قُرئ ما سُمّي قبل القفل');
        $this->assertSame([$second->id], CustomerCreditNoteOrder::pluck('customer_credit_note_id')->all());
        $this->assertSame(40.0, round((float) CustomerCreditNoteOrder::where('order_id', $a->id)->sum('amount'), 3));
    }

    public function test_a_naming_waits_for_a_cancel_in_progress_and_refuses_the_cancelled_order(): void
    {
        [$invoice, $a] = $this->month(8);
        $note = $this->unnamedNote($invoice, 50);

        // الإلغاءُ يقفل الطلب ثمّ المتجر — ويُثبت الطلبَ ملغًى
        $other = $this->elsewhere(sprintf('SELECT id FROM orders WHERE id = %d FOR UPDATE; ', $a->id).$this->lockShop()
            .sprintf(" UPDATE orders SET status = '%s' WHERE id = %d;", Order::CANCELLED, $a->id));
        [$refused, $waited] = $this->nameNow($note, $a);
        $this->assertSame(0, proc_close($other), 'سقطت اليدُ الأخرى');

        $this->assertGreaterThan(0.5, $waited, 'لم تنتظر التسميةُ الإلغاء');
        $this->assertNotNull($refused, 'سُمّي طلبٌ أُلغي في اللحظة نفسها');
        $this->assertStringContainsString('ملغى', $refused);
        $this->assertSame(0, CustomerCreditNoteOrder::count());
    }

    public function test_a_collection_in_progress_holds_the_naming_and_the_naming_moves_no_money(): void
    {
        [$invoice, $a] = $this->month(8, 12);
        $note = $this->unnamedNote($invoice, 50);
        $before = $this->money($invoice);

        // التحصيلُ يمسك قفلَ المتجر نفسه (`CustomerPayments`) — فالتسميةُ تنتظره ولا تتداخل معه
        $other = $this->elsewhere($this->lockShop());
        [$refused, $waited] = $this->nameNow($note, $a);
        $this->assertSame(0, proc_close($other), 'سقطت اليدُ الأخرى');

        $this->assertNull($refused, $refused ?? '');
        $this->assertGreaterThan(0.5, $waited, 'لم تنتظر التسميةُ التحصيلَ الجاري');
        $this->assertSame(1, CustomerCreditNoteOrder::count());
        $this->assertSame($before, $this->money($invoice), 'حرّكت التسميةُ مالًا');
        $this->assertTrue(Receivables::reconcile($this->shop->id)['balanced'], 'الذمم تطابق الدفتر');
    }

    public function test_one_note_named_by_two_hands_at_once_is_named_once(): void
    {
        [$invoice, $a, $b] = $this->month(8, 12);
        $note = $this->unnamedNote($invoice, 50);

        $other = $this->elsewhere($this->lockShop().' '.$this->namingRow($note, $invoice, $b, 50));
        [$refused, $waited] = $this->nameNow($note, $a);
        $this->assertSame(0, proc_close($other), 'سقطت اليدُ الأخرى');

        $this->assertGreaterThan(0.5, $waited);
        $this->assertNotNull($refused, 'سُمّي الإشعارُ مرّتين');
        $this->assertStringContainsString('من قبل', $refused);
        $this->assertSame([[$note->id, $b->id]], CustomerCreditNoteOrder::get(['customer_credit_note_id', 'order_id'])
            ->map(fn ($r) => [(int) $r->customer_credit_note_id, (int) $r->order_id])->all());
    }
}
