<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\CustomerCreditNote;
use App\Models\CustomerInvoice;
use App\Models\CustomerPayment;
use App\Models\JournalEntry;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Support\CustomerInvoices;
use App\Support\CustomerPayments;
use App\Support\Ledger;
use App\Support\OrderCorrection;
use App\Support\OrderStatus;
use App\Support\Receivables;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * طلبٌ في فاتورةِ شهرٍ عليها إشعارٌ دائنٌ يدويّ — لا يُلغى فيُنقص البيعَ مرّتين.
 *
 * ═══ ما يُحرس ═══
 *
 *   - إلغاءُ الطلب يعكس قيدَه، والإشعارُ اليدويّ قيّد مثلَه — والإشعارُ لا يقول
 *     أيَّ طلبٍ يخصّ. فلا يُلغى طلبٌ من فاتورةٍ عليها إشعارٌ يدويّ، ولو كان خصمًا
 *     جزئيًّا: لا يُفترض أنّه عن طلبٍ آخر.
 *   - والرفضُ لا يحرّك شيئًا: الطلبُ والرفّ والفاتورةُ وإشعاراتُها والدفعاتُ
 *     والقيود — ولو صدر الإشعارُ والإلغاءُ جارٍ.
 *   - والمشروعُ باقٍ: فاتورةٌ بطلباتٍ تُلغى كلُّها واحدًا بعد آخر، والفاتورةُ
 *     والذممُ والدفترُ رقمٌ واحد بعد كلّ إلغاء — مدفوعةً وغيرَ مدفوعة.
 *   - وما عاد من البضاعة يُسجَّل بتعديل مخزونٍ قائم: مخزونٌ لا إيرادٌ ولا ضريبة.
 */
class ABilledOrderIsNotReducedTwiceTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private Customer $company;

    private Product $roses;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Business::create(['name' => 'زهور الشهر', 'type' => 'عام', 'status' => 'نشط']);
        Currency::create([
            'business_id' => $this->shop->id, 'code' => 'OMR', 'name' => 'ريال عماني',
            'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true,
        ]);
        Ledger::seedChart($this->shop->id);
        Setting::create(['business_id' => $this->shop->id, 'key' => 'vat_enabled', 'value' => '0']);

        $this->owner = User::create([
            'business_id' => $this->shop->id, 'name' => 'المالك', 'email' => 'owner@billed.om',
            'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        $this->roses = Product::create([
            'business_id' => $this->shop->id, 'name' => 'باقة ورد',
            'price' => 10, 'cost' => 4, 'quantity' => 500, 'active' => true,
        ]);
        $this->company = Customer::create([
            'language' => 'ar', 'business_id' => $this->shop->id, 'name' => 'شركة الشهر',
            'allow_credit_sales' => true, 'monthly_billing' => true, 'payment_terms_days' => 30,
        ]);
        $this->actingAs($this->owner);
    }

    /* ═══ أدوات ═══ */

    private function sell(int $qty): Order
    {
        $this->postJson('/pos/checkout', [
            'items' => [['id' => $this->roses->id, 'name' => 'باقة ورد', 'qty' => $qty, 'price' => 10]],
            'payment_method' => 'نقدي',
            'credit' => true,
            'customer_id' => $this->company->id,
        ])->assertOk();

        return Order::latest('id')->first();
    }

    /** فاتورةُ شهرٍ بطلباتٍ بهذه الكمّيّات — ٨ و١٢ = ٨٠ و١٢٠ */
    private function month(int ...$quantities): array
    {
        $orders = array_map(fn (int $q) => $this->sell($q), $quantities);
        $invoice = CustomerInvoices::consolidate($this->company, array_map(fn ($o) => $o->id, $orders), [], $this->owner->id);

        return [$invoice, ...$orders];
    }

    private function pay(CustomerInvoice $invoice, float $amount): void
    {
        CustomerPayments::record($this->shop->id, $this->company, $amount, ['method' => 'نقدي'], [$invoice->id => $amount], $this->owner->id);
    }

    private function manual(CustomerInvoice $invoice, float $amount, float $tax = 0, string $basis = CustomerCreditNote::RETURN): CustomerCreditNote
    {
        return CustomerInvoices::creditNote($invoice->fresh(), $amount, $tax, 'مرتجع الشركة', $this->owner->id, basis: $basis);
    }

    /** يُلغى الطلب — أو يُقال لماذا لا */
    private function cancel(Order $order): ?string
    {
        try {
            OrderCorrection::cancel($order->fresh(), 'ألغت الشركة الطلب');

            return null;
        } catch (RuntimeException $e) {
            return $e->getMessage();
        }
    }

    /** كلُّ ما يحرّكه الإلغاء — والرفضُ لا يحرّك منه شيئًا */
    private function snapshot(CustomerInvoice $invoice, Order ...$orders): array
    {
        return [
            array_map(fn (Order $o) => $o->fresh()->status, $orders),
            (int) $this->roses->fresh()->quantity,
            JournalEntry::where('business_id', $this->shop->id)->count(),
            CustomerCreditNote::where('customer_invoice_id', $invoice->id)->orderBy('id')->get(['number', 'amount', 'source'])->toArray(),
            CustomerPayment::where('business_id', $this->shop->id)->whereNull('cancelled_at')->sum('amount'),
            [$invoice->fresh()->status, (float) $invoice->fresh()->total],
            round(Ledger::balance($this->shop->id, 'receivable'), 3),
            round(Ledger::balance($this->shop->id, 'sales'), 3),
            round(Ledger::balance($this->shop->id, 'tax_payable'), 3),
        ];
    }

    /** الفاتورةُ والذممُ والدفترُ رقمٌ واحد */
    private function assertBooksAgree(CustomerInvoice $invoice, float $owed, float $credit): void
    {
        $this->assertEquals($owed, $invoice->fresh()->outstanding(), 'ما على الفاتورة');
        $this->assertEquals($credit, Receivables::credit($this->shop->id, $this->company->id), 'رصيد الشركة الدائن');
        $this->assertTrue(Receivables::reconcile($this->shop->id)['balanced'], 'الذمم تطابق الدفتر');
    }

    /* ═══ ١ · المشروعُ باقٍ ═══ */

    public function test_a_month_of_several_orders_cancels_them_one_by_one_and_the_books_agree_after_each(): void
    {
        [$invoice, $a, $b, $c] = $this->month(8, 12, 5); // ٨٠ + ١٢٠ + ٥٠ = ٢٥٠

        $this->assertNull($this->cancel($b));
        $this->assertBooksAgree($invoice, owed: 130, credit: 0);
        $this->assertNull($this->cancel($a));
        $this->assertBooksAgree($invoice, owed: 50, credit: 0);
        $this->assertNull($this->cancel($c));
        $this->assertBooksAgree($invoice, owed: 0, credit: 0);

        // كلُّ إشعارٍ بطلبه وبمبلغه كاملًا — مرآةُ القيد الذي عُكس
        $notes = CustomerCreditNote::where('customer_invoice_id', $invoice->id)->orderBy('id')->get();
        $this->assertSame([$b->id, $a->id, $c->id], $notes->pluck('order_id')->map(fn ($id) => (int) $id)->all());
        $this->assertEquals([120.0, 80.0, 50.0], $notes->map(fn ($n) => (float) $n->amount)->all());
        $this->assertSame(500, (int) $this->roses->fresh()->quantity, 'عاد الرفّ كما كان');
    }

    public function test_a_paid_or_part_paid_month_still_cancels_and_the_paid_part_becomes_credit(): void
    {
        [$paid, $a, $b] = $this->month(8, 12);
        $this->pay($paid, 200);
        $this->assertNull($this->cancel($a));
        $this->assertBooksAgree($paid, owed: 0, credit: 80);

        [$part, $c, $d] = $this->month(8, 12);
        $this->pay($part, 50);
        $this->assertNull($this->cancel($c));
        $this->assertBooksAgree($part, owed: 70, credit: 80);
        $this->assertNull($this->cancel($d));
        $this->assertBooksAgree($part, owed: 0, credit: 130);
    }

    public function test_an_order_cancelled_twice_moves_nothing_the_second_time(): void
    {
        [$invoice, $a] = $this->month(8, 12);
        $stale = $a->fresh();

        $this->assertNull($this->cancel($a));
        $after = $this->snapshot($invoice, $a);

        // نسخةٌ قُرئت قبل الإلغاء: القفلُ يقرأ «ملغى» فينصرف
        $this->assertNull($this->cancel($stale));
        $this->assertNull($this->cancel($a));
        $this->assertSame($after, $this->snapshot($invoice, $a));
        $this->assertSame(1, CustomerCreditNote::where('order_id', $a->id)->count());
    }

    /* ═══ ٢ · إشعارٌ يدويٌّ على الفاتورة: لا إلغاءَ تلقائيًّا ═══ */

    public function test_a_manual_note_that_covers_an_order_stops_every_cancellation_on_its_invoice_and_nothing_moves(): void
    {
        [$invoice, $a, $b] = $this->month(8, 12);
        $note = $this->manual($invoice, 120);
        $before = $this->snapshot($invoice, $a, $b);

        foreach ([$b, $a] as $order) {
            $why = $this->cancel($order);
            $this->assertNotNull($why, 'أُلغي طلبٌ وعلى فاتورته إشعارٌ لا يُعرف طلبُه');
            $this->assertStringContainsString($invoice->number, $why);
            $this->assertStringContainsString($note->number, $why);
            $this->assertStringContainsString('راجع التسوية مع المحاسب', $why);
        }

        $this->assertSame($before, $this->snapshot($invoice, $a, $b));
        $this->assertBooksAgree($invoice, owed: 80, credit: 0);
    }

    public function test_a_partial_manual_discount_is_not_assumed_to_belong_to_another_order(): void
    {
        [$invoice, $a, $b] = $this->month(8, 12);
        $this->manual($invoice, 10, basis: CustomerCreditNote::DISCOUNT);
        $before = $this->snapshot($invoice, $a, $b);

        // ما بقي (١٩٠) يغطي كلَّ طلبٍ وحده — والإشعارُ لا يقول طلبَه، فلا يُلغى
        $this->assertNotNull($this->cancel($b));
        $this->assertNotNull($this->cancel($a));
        $this->assertSame($before, $this->snapshot($invoice, $a, $b));
    }

    public function test_paid_in_full_or_in_part_the_refusal_keeps_what_the_customer_is_owed(): void
    {
        // مدفوعة: كان الإلغاءُ يجعل للشركة ٣٢٠ وقد دفعت ٢٠٠
        [$paid, $a, $b] = $this->month(8, 12);
        $this->pay($paid, 200);
        $this->manual($paid, 120);
        $this->assertNotNull($this->cancel($a));
        $this->assertNotNull($this->cancel($b));
        $this->assertBooksAgree($paid, owed: 0, credit: 120);

        [$part, $c, $d] = $this->month(8, 12);
        $this->pay($part, 50);
        $this->manual($part, 120);
        $this->assertNotNull($this->cancel($d));
        $this->assertBooksAgree($part, owed: 30, credit: 120);
    }

    public function test_the_tax_is_not_reduced_twice(): void
    {
        Setting::where('business_id', $this->shop->id)->where('key', 'vat_enabled')->update(['value' => '1']);
        [$invoice, $a, $b] = $this->month(8, 12); // ٨٤ + ١٢٦ بضريبة ٤ + ٦
        $this->assertEquals(10.0, (float) $invoice->tax_total);

        $this->manual($invoice, 126, 6);
        $this->assertEquals(4.0, round(Ledger::balance($this->shop->id, 'tax_payable'), 3));

        $this->assertNotNull($this->cancel($b));
        $this->assertNotNull($this->cancel($a));
        $this->assertEquals(4.0, round(Ledger::balance($this->shop->id, 'tax_payable'), 3), 'ضريبةُ الطلب نقصت بالإشعار وحده');
        $this->assertBooksAgree($invoice, owed: 84, credit: 0);
    }

    public function test_the_board_and_the_delete_button_say_why_and_change_nothing(): void
    {
        [$invoice, $a] = $this->month(8, 12);
        $this->manual($invoice, 120);
        // طلبٌ آجلٌ فُوتر وهو قيد التجهيز — اللوحةُ تُلغي من هذه الحال، لا من «مكتمل»
        DB::table('orders')->where('id', $a->id)->update(['status' => OrderStatus::PREPARING]);
        $before = $this->snapshot($invoice, $a);

        $this->post(route('admin.orders.status', $a->number), ['status' => OrderStatus::CANCELLED])
            ->assertRedirect()->assertSessionHasErrors('status');
        $this->assertStringContainsString('راجع التسوية مع المحاسب', session('errors')->first('status'));

        $this->delete(route('admin.orders.destroy', $a->number))->assertRedirect();
        $this->assertStringContainsString('راجع التسوية مع المحاسب', (string) session('toast.msg'));

        $this->assertSame($before, $this->snapshot($invoice, $a));
    }

    /* ═══ ٣ · إشعارٌ يصدر والإلغاءُ جارٍ ═══ */

    public function test_a_manual_note_written_while_the_cancellation_runs_is_seen_under_the_lock_and_everything_rolls_back(): void
    {
        [$invoice, $a] = $this->month(8, 12);
        $before = $this->snapshot($invoice, $a);

        // السؤالُ الأوّل يمرّ: لا إشعار. ثمّ يُكتب إشعارٌ لحظةَ يعود الرفّ — قبل أوّل قيد
        $written = false;
        DB::listen(function ($q) use (&$written, $invoice) {
            if (! $written && str_starts_with(strtolower($q->sql), 'update "products"')) {
                $written = true;
                CustomerCreditNote::create([
                    'business_id' => $this->shop->id, 'customer_invoice_id' => $invoice->id, 'number' => 'CN-RACE',
                    'amount' => 80, 'tax_amount' => 0, 'issued_at' => now()->toDateString(),
                    'reason' => 'إشعارٌ وصل والإلغاءُ جارٍ', 'source' => CustomerInvoices::NOTE_MANUAL,
                ]);
            }
        });

        $why = $this->cancel($a);

        $this->assertTrue($written, 'لم يتحرّك الرفّ قبل القيد — الاختبارُ لم يقع في موضعه');
        $this->assertNotNull($why, 'لم يُسأل تحت القفل — أُلغي الطلبُ فوق إشعارٍ صدر');
        $this->assertStringContainsString('CN-RACE', $why);
        // والرفضُ أرجع المعاملةَ كلَّها — الرفَّ معها
        $this->assertSame($before, $this->snapshot($invoice, $a));
    }

    public function test_the_second_question_waits_for_the_shop_lock_after_the_shelf_and_before_the_books(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('القفلُ يُكتب `FOR UPDATE` على PostgreSQL وحدها — SQLite تُسلسل الكتابة بنفسها.');
        }

        [, $a] = $this->month(8, 12);
        $seen = [];
        DB::listen(function ($q) use (&$seen) {
            $seen[] = strtolower($q->sql);
        });

        $this->assertNull($this->cancel($a));

        $find = fn (callable $is, int $from = 0) => collect($seen)->search(fn ($s, $i) => $i >= $from && $is($s));
        $shelf = $find(fn ($s) => str_contains($s, 'from "products"') && str_contains($s, 'for update'));
        $lock = $find(fn ($s) => str_contains($s, 'from "businesses"') && str_contains($s, 'for update'), (int) $shelf);
        $read = $find(fn ($s) => str_contains($s, 'from "customer_credit_notes"'), (int) $lock);
        $post = $find(fn ($s) => str_contains($s, 'insert into "journal_entries"'), (int) $lock);

        $this->assertNotFalse($shelf, 'لا قفلَ على الرفّ');
        $this->assertNotFalse($lock, 'لا قفلَ على المتجر بعد الرفّ');
        $this->assertNotFalse($read, 'لم يُقرأ ما على الفاتورة تحت القفل');
        $this->assertLessThan($post, $read, 'قُيّد العكسُ قبل أن يُسأل تحت القفل');
    }

    /* ═══ ٤ · وما عاد من البضاعة: تعديلُ مخزونٍ قائم ═══ */

    public function test_goods_that_came_back_go_on_the_shelf_by_a_stock_adjustment_without_touching_revenue_or_tax(): void
    {
        $branch = Branch::create(['business_id' => $this->shop->id, 'name' => 'مسقط']);
        [$invoice, , $b] = $this->month(8, 12);
        $this->manual($invoice, 120);
        $this->assertNotNull($this->cancel($b));

        $sales = round(Ledger::balance($this->shop->id, 'sales'), 3);
        $tax = round(Ledger::balance($this->shop->id, 'tax_payable'), 3);
        $owed = round(Ledger::balance($this->shop->id, 'receivable'), 3);
        $stock = (int) $this->roses->fresh()->quantity;

        $this->post(route('admin.inventory.adjustments.store'), [
            'branch_id' => $branch->id, 'product_id' => $this->roses->id, 'quantity_delta' => 12,
            'reason' => 'تصحيح', 'notes' => 'عادت باقات الطلب '.$b->number.' — الإشعار اليدوي غطّى مبلغها', 'adjusted_at' => now()->toDateString(),
        ])->assertSessionHasNoErrors();

        $this->assertSame($stock + 12, (int) $this->roses->fresh()->quantity);
        $this->assertSame($sales, round(Ledger::balance($this->shop->id, 'sales'), 3), 'لا إيرادَ ثانٍ');
        $this->assertSame($tax, round(Ledger::balance($this->shop->id, 'tax_payable'), 3), 'ولا ضريبة');
        $this->assertSame($owed, round(Ledger::balance($this->shop->id, 'receivable'), 3), 'ولا ذمّة');
        $this->assertNotSame(OrderStatus::CANCELLED, $b->fresh()->status, 'والطلبُ باقٍ — لم يُلغَ');
    }
}
