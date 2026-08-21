<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Setting;
use App\Models\User;
use App\Support\Demo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * لوحة النشاط: تنبيهٌ يُطلق حين يجب، وقالبٌ يحكم الأوراق الثلاث كما يَعِد.
 */
class AdminPanelAuditTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        // الأوراق تُختبر بالعربية: التسميات هي ما يُبحث عنه في الناتج
        app()->setLocale('ar');

        $this->business = Business::create(['name' => 'متجري', 'status' => 'نشط']);
        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'owner@abaadapp.om',
            'password' => bcrypt('secret12345'), 'role' => 'admin', 'status' => 'نشط',
        ]);
    }

    private function order(Customer $c, string $status, string $when, int $n): Order
    {
        return Order::create([
            'business_id' => $this->business->id, 'customer_id' => $c->id, 'number' => $n,
            'customer_name' => $c->name, 'subtotal' => 10, 'tax' => 0, 'discount' => 0, 'total' => 10,
            'payment_method' => 'نقدي', 'status' => $status, 'is_held' => false, 'ordered_at' => $when,
        ]);
    }

    /* --------------------- تنبيه العميل المتعثّر --------------------- */

    public function test_a_cancelled_order_does_not_make_a_lapsed_customer_look_active(): void
    {
        /*
         * التنبيه الذي لا يُطلق أسوأ من غيابه: صاحبه يقرأ سكوت الشاشة
         * «لا متعثّر عندي».
         */
        $c = Customer::create(['business_id' => $this->business->id, 'name' => 'سالم', 'phone' => '90000001']);
        $this->order($c, 'مكتمل', now()->subDays(120)->toDateTimeString(), 1);
        $this->order($c, Order::CANCELLED, now()->toDateTimeString(), 2);

        $alerts = collect(Demo::smartAlertsFor($this->business->id));

        $this->assertTrue($alerts->contains(fn ($a) => str_contains($a['text'], 'سالم')));
    }

    public function test_a_customer_who_really_bought_recently_is_not_flagged(): void
    {
        $c = Customer::create(['business_id' => $this->business->id, 'name' => 'ناصر', 'phone' => '90000002']);
        $this->order($c, 'مكتمل', now()->subDays(120)->toDateTimeString(), 1);
        $this->order($c, 'مكتمل', now()->subDay()->toDateTimeString(), 2);

        $alerts = collect(Demo::smartAlertsFor($this->business->id));

        $this->assertFalse($alerts->contains(fn ($a) => str_contains($a['text'], 'ناصر')));
    }

    public function test_a_customer_who_never_bought_is_not_called_lapsed(): void
    {
        // «متعثّر» وصفٌ لمن كان يشتري فتوقّف — لا لمن لم يبدأ
        Customer::create(['business_id' => $this->business->id, 'name' => 'جديد', 'phone' => '90000003']);

        $alerts = collect(Demo::smartAlertsFor($this->business->id));

        $this->assertFalse($alerts->contains(fn ($a) => str_contains($a['text'], 'جديد')));
    }

    public function test_the_lapsed_scan_is_one_query_not_one_per_customer(): void
    {
        foreach (range(1, 15) as $i) {
            $c = Customer::create([
                'business_id' => $this->business->id, 'name' => "عميل {$i}", 'phone' => '9000'.str_pad((string) $i, 4, '0', STR_PAD_LEFT),
            ]);
            $this->order($c, 'مكتمل', now()->subDays(120)->toDateTimeString(), $i);
        }

        \Illuminate\Support\Facades\DB::enableQueryLog();
        Demo::smartAlertsFor($this->business->id);
        $reads = collect(\Illuminate\Support\Facades\DB::getQueryLog())
            ->filter(fn ($q) => str_contains($q['query'], 'from "orders"'))->count();
        \Illuminate\Support\Facades\DB::disableQueryLog();

        $this->assertLessThanOrEqual(4, $reads, 'صفُّ كل عميلٍ يُقرأ على حدة');
    }

    /* ------------------ القالب يحكم الأوراق الثلاث ------------------ */

    /* ------------------ القالب يحكم الأوراق الثلاث ------------------ */

    /** فاتورة A4 كما تُرسَم فعلًا — القالب هو ما يُختبر لا ملفّ الـPDF */
    private int $paper = 100;

    private function a4(array $tpl = []): string
    {
        $n = ++$this->paper;
        $c = Customer::create(['business_id' => $this->business->id, 'name' => 'شركة النور', 'phone' => '9000'.$n]);
        $order = $this->order($c, 'مكتمل', now()->toDateTimeString(), $n);
        $order->items()->create(['name' => 'صنف', 'quantity' => 1, 'price' => 10, 'total' => 10]);

        return view('pdf.invoice', [
            'order' => $order->fresh('items'),
            'qr' => '',
            'tpl' => $tpl,
            'customerTax' => null,
        ])->render();
    }

    public function test_the_a4_invoice_prints_the_header_line_the_card_promises_it_obeys(): void
    {
        // البطاقة تقول: «يحكم الإيصال المطبوع وفاتورة A4 والفاتورة الضريبية معًا»
        $this->assertStringContainsString('سطرٌ تحت الاسم', $this->a4(['tpl_header' => 'سطرٌ تحت الاسم']));
    }

    public function test_hiding_the_customer_reaches_the_a4_invoice(): void
    {
        $this->assertStringContainsString('شركة النور', $this->a4());
        $this->assertStringNotContainsString('شركة النور', $this->a4(['tpl_show_customer' => '0']));
    }

    public function test_the_items_count_toggle_reaches_the_a4_invoice(): void
    {
        $this->assertStringContainsString('عدد الأصناف', $this->a4(['tpl_show_items_count' => '1']));
        $this->assertStringNotContainsString('عدد الأصناف', $this->a4(['tpl_show_items_count' => '0']));
    }

    public function test_the_seller_tax_number_is_hideable_on_a_plain_invoice(): void
    {
        $with = $this->a4(['tpl_show_vat_no' => '1', 'vat_number' => 'OM1234567']);
        $without = $this->a4(['tpl_show_vat_no' => '0', 'vat_number' => 'OM1234567']);

        $this->assertStringContainsString('OM1234567', $with);
        $this->assertStringNotContainsString('OM1234567', $without);
    }

    public function test_the_font_size_setting_reaches_the_a4_invoice(): void
    {
        $this->assertStringContainsString('font-size: 14px', $this->a4(['tpl_font' => 'كبير']));
        $this->assertStringContainsString('font-size: 11px', $this->a4(['tpl_font' => 'صغير']));
    }
}
