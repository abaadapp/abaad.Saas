<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\CustomerCreditNote;
use App\Models\CustomerCreditNoteOrder;
use App\Models\CustomerInvoice;
use App\Models\CustomerPayment;
use App\Models\JournalEntry;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Support\BilledOrderCancel;
use App\Support\CreditNoteOrderRefused;
use App\Support\CreditNoteOrders;
use App\Support\CustomerInvoices;
use App\Support\Ledger;
use App\Support\OrderCorrection;
use App\Support\Receivables;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * الإشعارُ الدائنُ اليدويّ يسمّي الطلبَ الذي كان عنه (`CreditNoteOrders`).
 *
 * ═══ ما يُحرس ═══
 *
 *   - إشعارُ الإرجاع يسمّي طلبَه عند الإصدار: فلا يمنع إلغاءَ طلبات الفاتورة
 *     الأخرى، ويمنع إلغاءَ طلبه هو — إلغاؤه يعكس إيرادَه وضريبتَه فوق ما ردّه
 *     الإشعار. والدفترُ والذممُ والفاتورةُ رقمٌ واحد بعد كلّ خطوة.
 *   - الإرجاعُ الجزئيُّ والكامل: إشعاراتٌ تُسمّى عن الطلب حتى مبلغه وضريبته، ولا أكثر.
 *   - غيرُ الإرجاع لا يسمّي طلبًا عند الإصدار؛ وطلبُ فاتورةٍ أخرى أو متجرٍ آخر أو
 *     طلبٌ ملغى لا يُسمّى — ولا يُكتب إشعارٌ ولا قيد.
 *   - إشعارٌ صدر بلا طلب يُسمّى بعده بتحقّقٍ وسبب: سطرٌ في سجلّ التدقيق بمن
 *     سمّى ومتى ولماذا، وسطرٌ في سجلّ النشاط — ولا يتحرّك مبلغٌ ولا قيد.
 *   - مرّةً واحدة: لا يُسمّى ثانيةً، ولا يُسمّى إشعارُ إلغاء الطلب.
 *   - لمن يملك إصدارَ الإشعار وحده، وفي متجره وحده.
 *   - والتزامن: تسميةٌ وإلغاءٌ معًا يقعان واحدًا بعد آخر تحت قفل المتجر — فلا
 *     ينقص البيعُ مرّتين أيًّا سبق.
 */
class ACreditNoteNamesTheOrderItWasForTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private Customer $company;

    private Product $roses;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->shop, $this->owner, $this->roses, $this->company] = $this->shopWith('زهور الشهر', 'owner@named.om');
        $this->actingAs($this->owner);
    }

    /* ═══ أدوات ═══ */

    /** @return array{0: Business, 1: User, 2: Product, 3: Customer} */
    private function shopWith(string $name, string $email): array
    {
        $shop = Business::create(['name' => $name, 'type' => 'عام', 'status' => 'نشط']);
        Currency::create([
            'business_id' => $shop->id, 'code' => 'OMR', 'name' => 'ريال عماني',
            'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true,
        ]);
        Ledger::seedChart($shop->id);
        Setting::create(['business_id' => $shop->id, 'key' => 'vat_enabled', 'value' => '0']);

        $owner = User::create([
            'business_id' => $shop->id, 'name' => 'المالك '.$name, 'email' => $email,
            'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        $roses = Product::create([
            'business_id' => $shop->id, 'name' => 'باقة ورد',
            'price' => 10, 'cost' => 4, 'quantity' => 500, 'active' => true,
        ]);
        $company = Customer::create([
            'language' => 'ar', 'business_id' => $shop->id, 'name' => 'شركة الشهر',
            'allow_credit_sales' => true, 'monthly_billing' => true, 'payment_terms_days' => 30,
        ]);

        return [$shop, $owner, $roses, $company];
    }

    private function sell(int $qty, ?Product $product = null, ?Customer $customer = null): Order
    {
        $product ??= $this->roses;
        $customer ??= $this->company;

        $this->postJson('/pos/checkout', [
            'items' => [['id' => $product->id, 'name' => 'باقة ورد', 'qty' => $qty, 'price' => 10]],
            'payment_method' => 'نقدي',
            'credit' => true,
            'customer_id' => $customer->id,
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

    /** إشعارٌ من شاشة الفاتورة — برمزٍ جديد كلَّ مرّة */
    private function issueNote(CustomerInvoice $invoice, array $data)
    {
        static $n = 0;

        return $this->post('/admin/customer-invoices/'.$invoice->id.'/credit-note', $data + [
            'reason' => 'رُدّت باقات', 'basis' => CustomerCreditNote::RETURN, 'tax_amount' => 0, 'token' => 'tok-'.(++$n),
        ]);
    }

    private function nameOrder(CustomerCreditNote $note, Order $order, string $reason = 'تحقّقتُ من سند الاستلام')
    {
        return $this->post('/admin/customer-invoices/credit-notes/'.$note->id.'/order', ['order_id' => $order->id, 'reason' => $reason]);
    }

    private function cancel(Order $order): ?string
    {
        try {
            OrderCorrection::cancel($order->fresh(), 'ألغت الشركة الطلب');

            return null;
        } catch (RuntimeException $e) {
            return $e->getMessage();
        }
    }

    /** المالُ كلُّه — والتسميةُ لا تحرّك منه شيئًا */
    private function money(CustomerInvoice $invoice, Order ...$orders): array
    {
        return [
            array_map(fn (Order $o) => $o->fresh()->status, $orders),
            (int) $this->roses->fresh()->quantity,
            JournalEntry::where('business_id', $this->shop->id)->count(),
            CustomerCreditNote::where('customer_invoice_id', $invoice->id)->orderBy('id')->get(['number', 'amount', 'tax_amount', 'source'])->toArray(),
            CustomerPayment::where('business_id', $this->shop->id)->whereNull('cancelled_at')->sum('amount'),
            [$invoice->fresh()->status, (float) $invoice->fresh()->total, $invoice->fresh()->outstanding()],
            round(Ledger::balance($this->shop->id, 'receivable'), 3),
            round(Ledger::balance($this->shop->id, 'sales'), 3),
            round(Ledger::balance($this->shop->id, 'sales_returns'), 3),
            round(Ledger::balance($this->shop->id, 'tax_payable'), 3),
        ];
    }

    private function assertBooksAgree(CustomerInvoice $invoice, float $owed): void
    {
        $this->assertEquals($owed, $invoice->fresh()->outstanding(), 'ما على الفاتورة');
        $this->assertTrue(Receivables::reconcile($this->shop->id)['balanced'], 'الذمم تطابق الدفتر');
    }

    private function lastNote(): CustomerCreditNote
    {
        return CustomerCreditNote::latest('id')->first();
    }

    /* ═══ ١ · عند الإصدار ═══ */

    public function test_a_return_note_names_its_order_so_the_other_orders_still_cancel_and_its_own_does_not(): void
    {
        [$invoice, $a, $b] = $this->month(8, 12); // ٨٠ + ١٢٠

        $this->issueNote($invoice, ['amount' => 50, 'order_id' => $a->id])->assertSessionHasNoErrors();
        $note = $this->lastNote();
        $link = CustomerCreditNoteOrder::sole();
        $this->assertSame([$note->id, $a->id, CustomerCreditNoteOrder::AT_ISSUE, 50.0, 'رُدّت باقات', $this->owner->id],
            [(int) $link->customer_credit_note_id, (int) $link->order_id, $link->mode, $link->amount, $link->reason, (int) $link->linked_by]);
        $this->assertSame($this->owner->name, $link->linked_by_name);
        $this->assertBooksAgree($invoice, owed: 150);

        // الطلبُ الآخر يُلغى — الإشعارُ لم يكن عنه
        $this->assertNull($this->cancel($b));
        $this->assertBooksAgree($invoice, owed: 30);

        // وطلبُه لا يُلغى: يُقال لماذا ولا يتحرّك شيء
        $before = $this->money($invoice, $a, $b);
        $why = $this->cancel($a);
        $this->assertNotNull($why, 'أُلغي طلبٌ رُدّ منه بإشعار — نقص البيعُ مرّتين');
        $this->assertStringContainsString($note->number, $why);
        $this->assertStringContainsString('سُمّي عنه', $why);
        $this->assertSame($before, $this->money($invoice, $a, $b));
    }

    public function test_partial_returns_add_up_to_the_whole_order_and_never_past_it(): void
    {
        [$invoice, $a] = $this->month(8, 12);

        $this->issueNote($invoice, ['amount' => 50, 'order_id' => $a->id])->assertSessionHasNoErrors();
        $this->issueNote($invoice, ['amount' => 30, 'order_id' => $a->id])->assertSessionHasNoErrors();
        $this->assertSame(['amount' => 0.0, 'tax' => 0.0], CreditNoteOrders::room($a->fresh()));
        $this->assertNotContains($a->id, array_column(CreditNoteOrders::choices($invoice->fresh()), 'id'), 'رُدّ كلُّه — لا يُعرض');

        $count = CustomerCreditNote::count();
        $this->issueNote($invoice, ['amount' => 1, 'order_id' => $a->id])->assertSessionHasErrors('order_id');
        $this->assertSame($count, CustomerCreditNote::count(), 'كُتب إشعارٌ فوق ما بقي من الطلب');
        $this->assertSame(2, CustomerCreditNoteOrder::where('order_id', $a->id)->count());
    }

    public function test_the_tax_of_a_named_order_is_not_returned_twice(): void
    {
        [$invoice, $a] = $this->month(8, 12); // متجرٌ بلا ضريبة: ضريبةُ الطلب صفر

        // حدُّ الفاتورة يسبق في الإصدار (`ManualInvoiceAdjustments`) — فيُسأل حدُّ الطلب نفسُه هنا
        $this->assertSame($a->id, CreditNoteOrders::assertFits($invoice->fresh(), $a->id, 10, 0)->id);
        $this->expectException(CreditNoteOrderRefused::class);
        $this->expectExceptionMessage('ضريبة الطلب');
        CreditNoteOrders::assertFits($invoice->fresh(), $a->id, 10, 0.01);
    }

    public function test_only_a_return_names_an_order(): void
    {
        [$invoice, $a] = $this->month(8, 12);

        foreach ([CustomerCreditNote::DISCOUNT, CustomerCreditNote::ERROR] as $basis) {
            $this->issueNote($invoice, ['amount' => 10, 'order_id' => $a->id, 'basis' => $basis])->assertSessionHasErrors('order_id');
        }
        $this->assertSame(0, CustomerCreditNote::count());

        // والخدمةُ تسأل كذلك — لا الشاشةُ وحدها
        $this->expectException(CreditNoteOrderRefused::class);
        CustomerInvoices::creditNote($invoice->fresh(), 10, 0, 'خصم', $this->owner->id, basis: CustomerCreditNote::DISCOUNT, forOrderId: $a->id);
    }

    public function test_an_order_of_another_invoice_or_another_shop_or_a_cancelled_order_is_not_named_and_nothing_is_written(): void
    {
        [$invoice, $a, $b, $c] = $this->month(8, 12, 5);
        [, $elsewhere] = $this->month(3);
        $this->assertNull($this->cancel($c));

        [$other, $otherOwner, $otherRoses, $otherCompany] = $this->shopWith('ورد الجيران', 'owner@neighbour.om');
        $this->actingAs($otherOwner);
        $foreign = $this->sell(2, $otherRoses, $otherCompany);
        $this->actingAs($this->owner);

        $before = $this->money($invoice, $a, $b, $c);
        foreach ([$elsewhere, $foreign, $c] as $order) {
            $this->issueNote($invoice, ['amount' => 10, 'order_id' => $order->id])->assertSessionHasErrors('order_id');
        }
        $this->assertSame($before, $this->money($invoice, $a, $b, $c));
        $this->assertSame(0, CustomerCreditNoteOrder::count());
        $this->assertNotSame($other->id, $this->shop->id);
    }

    /* ═══ ٢ · بعد الإصدار ═══ */

    public function test_a_note_issued_without_an_order_is_named_later_with_who_when_and_why_and_no_money_moves(): void
    {
        [$invoice, $a, $b] = $this->month(8, 12);
        $this->issueNote($invoice, ['amount' => 50])->assertSessionHasNoErrors();
        $note = $this->lastNote();

        // بلا تسمية: لا يُلغى طلبٌ من الفاتورة
        $this->assertNotNull(BilledOrderCancel::refusal($b->fresh()));

        $before = $this->money($invoice, $a, $b);
        $this->nameOrder($note, $a)->assertSessionHasNoErrors();
        $this->assertSame($before, $this->money($invoice, $a, $b), 'التسميةُ حرّكت مالًا أو قيدًا');

        $link = CustomerCreditNoteOrder::sole();
        $this->assertSame([CustomerCreditNoteOrder::LATER, 'تحقّقتُ من سند الاستلام', $this->owner->id, $this->owner->name, (int) $a->id],
            [$link->mode, $link->reason, (int) $link->linked_by, $link->linked_by_name, (int) $link->order_id]);
        $this->assertNotNull($link->created_at);
        $this->assertTrue(ActivityLog::where('subject_type', 'customer_credit_note')->where('subject_id', $note->id)
            ->where('description', 'like', '%'.$a->number.'%تحقّقتُ من سند الاستلام%')->exists(), 'لا سطرَ في سجلّ النشاط');

        // والآن يُلغى الآخر، ولا يُلغى المسمّى
        $this->assertNull($this->cancel($b));
        $this->assertBooksAgree($invoice, owed: 30);
        $this->assertNotNull($this->cancel($a));
    }

    public function test_the_reason_is_required(): void
    {
        [$invoice, $a] = $this->month(8, 12);
        $this->issueNote($invoice, ['amount' => 50])->assertSessionHasNoErrors();

        $this->nameOrder($this->lastNote(), $a, '')->assertSessionHasErrors('reason');
        $this->assertSame(0, CustomerCreditNoteOrder::count());
    }

    public function test_a_note_is_named_once_and_an_order_cancel_note_is_never_named(): void
    {
        [$invoice, $a, $b, $c] = $this->month(8, 12, 5);
        $this->issueNote($invoice, ['amount' => 50, 'order_id' => $a->id])->assertSessionHasNoErrors();
        $named = $this->lastNote();

        $this->nameOrder($named, $b)->assertSessionHasErrors('order_id');
        $this->assertSame([(int) $a->id], CustomerCreditNoteOrder::pluck('order_id')->map(fn ($id) => (int) $id)->all());

        $this->assertNull($this->cancel($c));
        $byCancel = CustomerCreditNote::where('order_id', $c->id)->sole();
        $this->nameOrder($byCancel, $b)->assertSessionHasErrors('order_id');
        $this->assertSame(1, CustomerCreditNoteOrder::count());
    }

    public function test_a_note_too_big_for_the_order_is_not_named_to_it(): void
    {
        [$invoice, $a] = $this->month(8, 12);
        $this->issueNote($invoice, ['amount' => 100])->assertSessionHasNoErrors();

        $this->nameOrder($this->lastNote(), $a)->assertSessionHasErrors('order_id'); // ١٠٠ أكبر من طلبٍ بثمانين
        $this->assertSame(0, CustomerCreditNoteOrder::count());
    }

    /* ═══ ٣ · لمن، وأين ═══ */

    public function test_naming_needs_the_credit_note_permission_and_an_accountant_has_it(): void
    {
        [$invoice, $a] = $this->month(8, 12);
        $this->issueNote($invoice, ['amount' => 50])->assertSessionHasNoErrors();
        $note = $this->lastNote();

        $withheld = User::create([
            'business_id' => $this->shop->id, 'name' => 'محاسبٌ بلا إشعار', 'email' => 'withheld@named.om',
            'password' => bcrypt('x'), 'role' => 'accountant', 'status' => 'نشط', 'permissions' => [],
        ]);
        $this->actingAs($withheld);
        $this->nameOrder($note, $a)->assertForbidden();
        $this->assertSame(0, CustomerCreditNoteOrder::count());

        $accountant = User::create([
            'business_id' => $this->shop->id, 'name' => 'المحاسب', 'email' => 'accountant@named.om',
            'password' => bcrypt('x'), 'role' => 'accountant', 'status' => 'نشط',
        ]);
        $this->actingAs($accountant);
        $this->nameOrder($note, $a)->assertSessionHasNoErrors();
        $this->assertSame('المحاسب', CustomerCreditNoteOrder::sole()->linked_by_name);
    }

    public function test_a_shop_cannot_name_another_shops_note(): void
    {
        [$invoice, $a] = $this->month(8, 12);
        $this->issueNote($invoice, ['amount' => 50])->assertSessionHasNoErrors();
        $note = $this->lastNote();

        [, $otherOwner] = $this->shopWith('ورد الجيران', 'owner@neighbour.om');
        $this->actingAs($otherOwner);
        $this->nameOrder($note, $a)->assertNotFound();
        $this->assertSame(0, CustomerCreditNoteOrder::count());
    }

    /* ═══ ٤ · التزامن ═══ */

    public function test_a_return_note_naming_the_order_that_lands_while_it_is_cancelled_is_seen_under_the_lock_and_everything_rolls_back(): void
    {
        [$invoice, $a] = $this->month(8, 12);
        $before = $this->money($invoice, $a);

        // السؤالُ الأوّل يمرّ: لا إشعار. ثمّ يصدر إشعارُ إرجاعٍ يسمّي الطلب لحظةَ يعود الرفّ — قبل أوّل قيد
        $written = false;
        DB::listen(function ($q) use (&$written, $invoice, $a) {
            if (! $written && str_starts_with(strtolower($q->sql), 'update "products"')) {
                $written = true;
                $note = CustomerCreditNote::create([
                    'business_id' => $this->shop->id, 'customer_invoice_id' => $invoice->id, 'number' => 'CN-RACE',
                    'amount' => 50, 'tax_amount' => 0, 'issued_at' => now()->toDateString(),
                    'reason' => 'رُدّ والإلغاءُ جارٍ', 'source' => CustomerInvoices::NOTE_MANUAL, 'basis' => CustomerCreditNote::RETURN,
                ]);
                CreditNoteOrders::record($note, $a->fresh(), CustomerCreditNoteOrder::AT_ISSUE, 'رُدّ والإلغاءُ جارٍ', $this->owner->id);
            }
        });

        $why = $this->cancel($a);

        $this->assertTrue($written, 'لم يتحرّك الرفّ قبل القيد — الاختبارُ لم يقع في موضعه');
        $this->assertNotNull($why, 'لم يُسأل تحت القفل — أُلغي طلبٌ رُدّ منه بإشعار');
        $this->assertStringContainsString('CN-RACE', $why);
        $this->assertStringContainsString('سُمّي عنه', $why);
        // والرفضُ أرجع المعاملةَ كلَّها — الرفَّ والإشعارَ الذي كُتب داخلها معها
        $this->assertSame($before, $this->money($invoice, $a));
    }

    public function test_an_order_cancelled_while_its_naming_waits_is_seen_under_the_lock(): void
    {
        [$invoice, $a] = $this->month(8, 12);
        $this->issueNote($invoice, ['amount' => 50])->assertSessionHasNoErrors();
        $note = $this->lastNote();

        // التسميةُ تأخذ قفلَ المتجر أوّلًا ثمّ تقرأ الطلب — والإلغاءُ وصل قبلها
        $armed = true;
        $lockSql = null;
        DB::listen(function ($q) use (&$armed, &$lockSql, $a) {
            $sql = strtolower($q->sql);
            if ($armed && str_contains($sql, 'from "businesses"')) {
                $armed = false;
                $lockSql = $sql;
                DB::table('orders')->where('id', $a->id)->update(['status' => Order::CANCELLED]);
            }
        });

        try {
            CreditNoteOrders::link($note, $a->id, 'سبب', $this->owner->id);
            $this->fail('سُمّي طلبٌ ملغى');
        } catch (CreditNoteOrderRefused $e) {
            $this->assertStringContainsString('ملغى', $e->getMessage());
        }
        $this->assertSame(0, CustomerCreditNoteOrder::count());

        $this->assertNotNull($lockSql, 'لم تبدأ التسميةُ بقفل المتجر');
        if (DB::connection()->getDriverName() === 'pgsql') {
            $this->assertStringContainsString('for update', $lockSql, 'قرأت التسميةُ المتجرَ بلا قفل');
        }
    }

    public function test_a_stale_copy_of_a_named_note_is_not_named_again(): void
    {
        [$invoice, $a, $b] = $this->month(8, 12);
        $this->issueNote($invoice, ['amount' => 50])->assertSessionHasNoErrors();
        $stale = $this->lastNote();

        CreditNoteOrders::link($stale, $a->id, 'الأولى', $this->owner->id);

        $this->expectException(CreditNoteOrderRefused::class);
        CreditNoteOrders::link($stale, $b->id, 'الثانية', $this->owner->id);
    }
}
