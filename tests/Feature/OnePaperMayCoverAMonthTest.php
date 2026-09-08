<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\CustomerCreditNote;
use App\Models\CustomerInvoice;
use App\Models\JournalEntry;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Models\WhatsAppMessage;
use App\Support\CustomerInvoices;
use App\Support\CustomerPayments;
use App\Support\Ledger;
use App\Support\OrderCorrection;
use App\Support\Receivables;
use App\Support\WhatsAppEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * ورقةٌ واحدة تغطّي شهرًا · وإلغاءُ الطلب يصل إليها · والتذكيرُ يقع وحدَه.
 */
class OnePaperMayCoverAMonthTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private Customer $company;

    private Product $product;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'زهور مسقط', 'type' => 'عام', 'status' => 'نشط']);
        Currency::create([
            'business_id' => $this->business->id, 'code' => 'OMR', 'name' => 'ريال عماني',
            'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true,
        ]);
        Ledger::seedChart($this->business->id);
        Setting::create(['business_id' => $this->business->id, 'key' => 'vat_enabled', 'value' => '0']);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        $this->product = Product::create([
            'business_id' => $this->business->id, 'name' => 'باقة ورد',
            'price' => 10, 'cost' => 4, 'quantity' => 500, 'active' => true,
        ]);
        $this->company = Customer::create([
            'business_id' => $this->business->id, 'name' => 'شركة ABC',
            'allow_credit_sales' => true, 'monthly_billing' => true, 'payment_terms_days' => 30,
        ]);
        $this->actingAs($this->owner);
    }

    private function sell(int $qty): Order
    {
        $this->postJson('/pos/checkout', [
            'items' => [['id' => $this->product->id, 'name' => 'باقة ورد', 'qty' => $qty, 'price' => 10]],
            'payment_method' => 'نقدي',
            'credit' => true,
            'customer_id' => $this->company->id,
        ])->assertOk();

        return Order::latest('id')->first();
    }

    // ————— السيناريو (ج): ثلاثةُ طلباتٍ وورقةٌ واحدة —————

    public function test_a_monthly_billing_customer_gets_no_paper_at_the_counter(): void
    {
        $this->sell(8);

        // شركةٌ تشتري ثلاث مرّاتٍ في الشهر لا تريد ثلاثَ فواتير
        $this->assertSame(0, CustomerInvoice::count());
    }

    public function test_an_unbilled_credit_sale_is_still_a_debt_from_its_first_moment(): void
    {
        $this->sell(8);

        // ولا يختفي دَينٌ من الشاشة لأنّ ورقتَه لم تُطبع بعد
        $this->assertSame(80.0, Receivables::customerOutstanding($this->business->id, $this->company->id));
        $this->assertSame(80.0, Ledger::balance($this->business->id, 'receivable'));
        $this->assertTrue(Receivables::reconcile($this->business->id)['balanced']);
    }

    public function test_three_orders_become_one_paper(): void
    {
        $a = $this->sell(8);
        $b = $this->sell(12);
        $c = $this->sell(5);

        $invoice = CustomerInvoices::consolidate(
            $this->company, [$a->id, $b->id, $c->id], [], $this->owner->id
        );

        $this->assertSame(250.0, (float) $invoice->total);
        $this->assertSame(3, $invoice->orders()->count());
        $this->assertSame(CustomerInvoice::ISSUED, $invoice->status);
        // والبندُ يحمل رقمَ طلبه: الشركةُ تُطابق ورقتَها بطلباتها
        $this->assertStringContainsString($a->number, $invoice->items->first()->description);
    }

    public function test_the_paper_lists_its_orders_in_the_order_they_happened(): void
    {
        $a = $this->sell(8);
        $b = $this->sell(12);
        $c = $this->sell(5);

        // وتُمرَّر مبعثرةً: الورقةُ ترتّب بتاريخها لا بترتيب ما وصلها
        $invoice = CustomerInvoices::consolidate(
            $this->company, [$c->id, $a->id, $b->id], [], $this->owner->id
        );

        $this->assertSame(
            ['باقة ورد — '.$a->number, 'باقة ورد — '.$b->number, 'باقة ورد — '.$c->number],
            $invoice->items->pluck('description')->all(),
        );
    }

    public function test_the_monthly_paper_posts_no_second_revenue(): void
    {
        $a = $this->sell(8);
        $b = $this->sell(12);
        CustomerInvoices::consolidate($this->company, [$a->id, $b->id], [], $this->owner->id);

        // كلُّ طلبٍ رُحّل لحظةَ وقوعه — والورقةُ مستندٌ فوق ذلك لا حدثٌ جديد
        $this->assertSame(200.0, Ledger::balance($this->business->id, 'sales'));
        $this->assertSame(200.0, Ledger::balance($this->business->id, 'receivable'));
        $this->assertSame(0, JournalEntry::where('source', 'فاتورة عميل')->count());
    }

    public function test_the_debt_is_not_counted_twice_once_billed(): void
    {
        $a = $this->sell(8);
        $b = $this->sell(12);
        CustomerInvoices::consolidate($this->company, [$a->id, $b->id], [], $this->owner->id);

        // ٢٠٠ لا ٤٠٠: الطلبُ المفوتَر يخرج من «ما لم يُفوتَر»
        $this->assertSame(200.0, Receivables::customerOutstanding($this->business->id, $this->company->id));
        $this->assertTrue(Receivables::reconcile($this->business->id)['balanced']);
    }

    public function test_an_order_is_never_billed_twice(): void
    {
        $a = $this->sell(8);
        CustomerInvoices::consolidate($this->company, [$a->id], [], $this->owner->id);

        // ولا تُطالَب شركةٌ بمبلغٍ مرّتين
        $this->expectException(RuntimeException::class);

        try {
            CustomerInvoices::consolidate($this->company, [$a->id], [], $this->owner->id);
        } finally {
            $this->assertSame(1, CustomerInvoice::count());
        }
    }

    public function test_a_paid_order_is_not_billed(): void
    {
        $this->company->update(['monthly_billing' => false, 'allow_credit_sales' => false]);
        $this->postJson('/pos/checkout', [
            'items' => [['id' => $this->product->id, 'name' => 'باقة ورد', 'qty' => 3, 'price' => 10]],
            'payment_method' => 'نقدي',
            'customer_id' => $this->company->id,
        ])->assertOk();

        /*
         * بيعةٌ نقديّةٌ قُبض ثمنُها ولا ذمّةَ لها. ووضعُها في فاتورةٍ يُنشئ
         * التزامًا لا وجود له في الدفتر — فتفترق الذمّةُ عن رصيد `receivable`.
         */
        $this->expectException(RuntimeException::class);
        CustomerInvoices::consolidate($this->company, [Order::latest('id')->first()->id], [], $this->owner->id);
    }

    public function test_the_billing_door_works_from_the_screen(): void
    {
        $a = $this->sell(8);
        $b = $this->sell(12);

        // بالاسم لا بعنوانٍ مكتوبٍ بيد: عنوانٌ منسوخٌ لا يتبع بابَه إن انتقل
        $this->post(route('admin.finance.customerBill', $this->company->id), [
            'order_ids' => [$a->id, $b->id],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(200.0, (float) CustomerInvoice::first()->total);
    }

    public function test_another_shops_order_cannot_be_billed_here(): void
    {
        $other = Business::create(['name' => 'الجار', 'type' => 'عام', 'status' => 'نشط']);
        $theirOrder = Order::create([
            'business_id' => $other->id, 'number' => 'X-1', 'customer_name' => 'س',
            'subtotal' => 90, 'discount' => 0, 'tax' => 0, 'total' => 90,
            'status' => 'مكتمل', 'payment_method' => 'نقدي', 'payment_status' => 'غير مدفوع',
        ]);

        $this->expectException(RuntimeException::class);

        try {
            CustomerInvoices::consolidate($this->company, [$theirOrder->id], [], $this->owner->id);
        } finally {
            $this->assertSame(0, CustomerInvoice::count());
        }
    }

    // ————— إلغاءُ طلبٍ مفوتَر —————

    public function test_cancelling_a_billed_order_reduces_the_paper(): void
    {
        $a = $this->sell(8);
        $b = $this->sell(12);
        $invoice = CustomerInvoices::consolidate($this->company, [$a->id, $b->id], [], $this->owner->id);

        OrderCorrection::cancel($a->fresh(), 'ألغت الشركة الطلب');

        // ٢٥٠ → ٢٠٠ ناقصَ ٨٠ = ١٢٠
        $this->assertSame(120.0, $invoice->fresh()->outstanding());
        // ولا تُعاد كتابة الورقة: ما في يد الشركة كما استلمته
        $this->assertSame(200.0, (float) $invoice->fresh()->total);
    }

    public function test_the_automatic_credit_note_posts_nothing(): void
    {
        $a = $this->sell(8);
        $b = $this->sell(12);
        CustomerInvoices::consolidate($this->company, [$a->id, $b->id], [], $this->owner->id);

        OrderCorrection::cancel($a->fresh(), 'ألغت الشركة الطلب');

        /*
         * `unpostSale` عكست قيدَ الطلب لحظةَ إلغائه. وقيدٌ ثانٍ هنا يُنقص
         * الذمّة مرّتين ويجعل الدفتر يقول غيرَ ما تقوله الفواتير.
         */
        $this->assertSame(120.0, Ledger::balance($this->business->id, 'receivable'));
        $this->assertSame(0.0, Ledger::balance($this->business->id, 'sales_returns'));
        $this->assertTrue(Receivables::reconcile($this->business->id)['balanced']);
    }

    public function test_the_note_says_it_came_from_a_cancellation(): void
    {
        $a = $this->sell(8);
        CustomerInvoices::consolidate($this->company, [$a->id], [], $this->owner->id);
        OrderCorrection::cancel($a->fresh(), 'خطأ');

        $note = CustomerCreditNote::first();
        $this->assertSame(CustomerInvoices::NOTE_ORDER_CANCELLED, $note->source);
        $this->assertSame((int) $a->id, (int) $note->order_id);
    }

    public function test_a_second_cancellation_writes_no_second_note(): void
    {
        /*
         * وفاتورةُ شهرٍ لا فاتورةُ طلبٍ واحد.
         *
         * على ورقةٍ بطلبٍ واحد يحجب سقفُ المبلغ العطبَ: بعد أوّل إشعارٍ لا
         * يبقى فيها ما يُنقَص، فلا يُكتب ثانٍ ولو غاب الحارس. وعلى ورقةٍ
         * بطلبين يبقى فيها متّسع — فيُكتب إشعارٌ ثانٍ بطلبٍ أُلغي مرّةً،
         * وتُنقص الذمّةُ مرّتين لإلغاءٍ واحد.
         */
        $a = $this->sell(8);
        $b = $this->sell(12);
        CustomerInvoices::consolidate($this->company, [$a->id, $b->id], [], $this->owner->id);

        OrderCorrection::cancel($a->fresh(), 'خطأ');
        OrderCorrection::cancel($a->fresh(), 'خطأ ثانٍ');

        $this->assertSame(1, CustomerCreditNote::count());

        // والدالّةُ نفسُها تُسأل مباشرةً: الحارسُ الخارجيّ يمنع النداء الثاني
        // فلا يصلها شيء — وحارسٌ لا يصله شيءٌ لا يُثبت نفسه
        CustomerInvoices::onOrderCancelled($a->fresh(), $this->owner->id, 'ثالث');

        $this->assertSame(1, CustomerCreditNote::count());
        $this->assertSame(120.0, CustomerInvoice::first()->outstanding());
    }

    public function test_a_cancelled_order_leaves_the_unbilled_debt_too(): void
    {
        $a = $this->sell(8);
        $this->sell(12);

        OrderCorrection::cancel($a->fresh(), 'ألغت الشركة');

        // ولم تُفوتَر بعد — فتُستبعد من الطلبات لا من الفواتير
        $this->assertSame(120.0, Receivables::customerOutstanding($this->business->id, $this->company->id));
        $this->assertTrue(Receivables::reconcile($this->business->id)['balanced']);
    }

    public function test_a_partly_paid_month_and_a_cancelled_order_add_up(): void
    {
        $a = $this->sell(8);
        $b = $this->sell(12);
        $invoice = CustomerInvoices::consolidate($this->company, [$a->id, $b->id], [], $this->owner->id);

        CustomerPayments::record($this->business->id, $this->company, 50, ['method' => 'نقدي'],
            [$invoice->id => 50], $this->owner->id);
        OrderCorrection::cancel($a->fresh(), 'مرتجع');

        // ٢٠٠ − ٥٠ مسدَّدًا − ٨٠ ملغًى = ٧٠
        $this->assertSame(70.0, $invoice->fresh()->outstanding());
    }

    // ————— التذكيرُ المجدول —————

    public function test_the_reminder_runs_by_itself(): void
    {
        $a = $this->sell(8);
        $invoice = CustomerInvoices::consolidate($this->company, [$a->id], [], $this->owner->id);
        $invoice->update(['due_at' => now()->subDays(5)->toDateString()]);
        $this->company->update(['phone' => '99887766']);
        Setting::create(['business_id' => $this->business->id, 'key' => 'wa_on_invoice_overdue', 'value' => '1']);

        $this->artisan('invoices:remind')->assertSuccessful();

        // متجرٌ لم يربط واتساب لا يُقيَّد له صفٌّ ولا تُستهلك حصّة
        $this->assertSame(0, WhatsAppMessage::count());
    }

    public function test_an_invoice_with_no_due_date_is_never_called_late(): void
    {
        $a = $this->sell(8);
        $invoice = CustomerInvoices::consolidate($this->company, [$a->id], [], $this->owner->id);
        $invoice->update(['due_at' => null]);

        // بلا موعدٍ متّفقٍ عليه لا يُقال إنّه تأخّر
        $this->assertSame(0, $invoice->fresh()->daysOverdue());
        $this->artisan('invoices:remind')->assertSuccessful();
    }

    public function test_the_reminder_events_are_switchable_by_the_merchant(): void
    {
        // مفتاحان في «إشعارات واتساب» — قرارُ التاجر لا قرارُنا
        $this->assertArrayHasKey(WhatsAppEvent::INVOICE_OVERDUE, WhatsAppEvent::SETTING_KEYS);
        $this->assertArrayHasKey(WhatsAppEvent::INVOICE_DUE_SOON, WhatsAppEvent::SETTING_KEYS);
        $this->assertContains(WhatsAppEvent::INVOICE_OVERDUE, WhatsAppEvent::ALL);
    }
}
