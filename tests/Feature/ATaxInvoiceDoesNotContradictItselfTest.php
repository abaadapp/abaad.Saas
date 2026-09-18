<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Support\CustomerInvoices;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ورقةٌ ضريبيّة لا تناقض نفسها.
 *
 * ═══ العطب ═══
 *
 * البيعُ الآجل يكتب للعميل فاتورةً (`CustomerInvoices::fromOrder`). ورأسُها
 * يُنسخ من الطلب — إجماليُّه وضريبتُه — أمّا **سطورُها** فكانت تُحسب من جديد
 * بنسبة المتجر، لأنّ `compute` تسقط إلى `Vat::rate` حين لا تُعطى نسبةَ بند.
 *
 * فخبزٌ صفريُّ الضريبة يُباع آجلًا بمئة، فتخرج ورقتُه تقول:
 *
 *   السطر : «خبز · نسبة ٥٪ · ضريبة ٥ · المبلغ ١٠٥»
 *   الذيل : «الضريبة صفر · الإجمالي ١٠٠»
 *
 * وليست شاشةً: `customer-invoice.blade.php` تطبع عمودَ ضريبة السطر. وهي
 * ورقةٌ ضريبيّة يقدّمها العميلُ في إقراره هو، فيخصم ضريبةً لم تُدفع.
 *
 * وخصمُ الطلب مثلُها: كان يُمرَّر صفرًا لكلّ سطر، فتُحسب ضريبةُ السطر على
 * مبلغٍ لم يدفعه أحد.
 *
 * والقاعدة: ما يُكتب في السطر يُقرأ بالقارئ الذي قرأه لحظةَ البيع —
 * `Vat::rateFor` نفسُها التي تقرؤها نقطةُ البيع وتصحيحُ الطلب.
 */
class ATaxInvoiceDoesNotContradictItselfTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private User $owner;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->setLocale('ar');

        $this->shop = Business::create(['name' => 'متجر', 'type' => 'عام', 'status' => 'نشط']);
        Setting::create(['business_id' => $this->shop->id, 'key' => 'vat_rate', 'value' => '5']);

        $this->owner = User::create([
            'business_id' => $this->shop->id, 'name' => 'مالك', 'email' => 'o@abaadapp.om',
            'password' => bcrypt('password12345'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->customer = Customer::create([
            'business_id' => $this->shop->id, 'name' => 'زبون', 'phone' => '90000000',
            'allow_credit_sales' => true, 'credit_limit' => 10000,
        ]);
    }

    private function product(array $over = []): Product
    {
        return Product::create(array_merge([
            'business_id' => $this->shop->id, 'name' => 'صنف', 'sku' => 'S'.rand(1, 99999),
            'price' => 100, 'cost' => 40, 'quantity' => 50, 'active' => true,
        ], $over));
    }

    private function sellOnCredit(array $items, array $extra = []): Order
    {
        $this->actingAs($this->owner)->postJson('/pos/checkout', array_merge([
            'items' => $items,
            'payment_method' => 'نقدي',
            'client_uuid' => uniqid('c', true),
            'customer_id' => $this->customer->id,
            'customer' => 'زبون',
            'credit' => true,
            'paid_now' => 0,
        ], $extra))->assertOk();

        return Order::where('business_id', $this->shop->id)->latest('id')->firstOrFail();
    }

    private function invoice(): CustomerInvoice
    {
        return CustomerInvoice::where('business_id', $this->shop->id)->latest('id')->firstOrFail();
    }

    /* ══════════ ١ · السطرُ يحمل نسبة صنفه ══════════ */

    public function test_a_zero_rated_line_is_written_zero_rated(): void
    {
        $bread = $this->product(['name' => 'خبز', 'tax' => 0]);

        $order = $this->sellOnCredit([['id' => $bread->id, 'name' => 'خبز', 'qty' => 1]]);
        $line = $this->invoice()->items->first();

        $this->assertSame(0.0, (float) $order->tax, 'المقدّمةُ خاطئة — الطلبُ نفسُه حُسبت له ضريبة');
        $this->assertSame(0.0, (float) $line->tax_rate, 'سطرُ الورقة يحمل نسبةَ المتجر لا نسبةَ صنفه');
        $this->assertSame(0.0, (float) $line->tax_amount, 'ضريبةٌ في سطرٍ لم تُجبَ من أحد');
        $this->assertSame(100.0, (float) $line->line_total, 'مبلغُ السطر أكبرُ ممّا دُفع');
    }

    /** والخاضعُ يبقى خاضعًا — القيدُ على القراءة لا على النسبة */
    public function test_a_taxed_line_keeps_its_tax(): void
    {
        $shirt = $this->product(['name' => 'قميص']);

        $this->sellOnCredit([['id' => $shirt->id, 'name' => 'قميص', 'qty' => 1]]);
        $line = $this->invoice()->items->first();

        $this->assertSame(5.0, (float) $line->tax_rate);
        $this->assertSame(5.0, (float) $line->tax_amount);
    }

    /**
     * والسطرُ يطابق الذيل: مجموعُ ضرائب السطور هو ضريبةُ الورقة.
     *
     * وهي الخاصّيّةُ التي يُراجَع بها ورقٌ رسميّ — ولا تتحقّق بنسخ الرأس
     * وحده: الرأسُ يُنسخ من الطلب، والسطورُ تُحسب. فلو افترق القارئان قال
     * أحدُهما غيرَ ما يقول الآخر على الورقة نفسِها.
     */
    public function test_the_lines_add_up_to_the_foot(): void
    {
        $bread = $this->product(['name' => 'خبز', 'tax' => 0]);
        $shirt = $this->product(['name' => 'قميص']);

        $order = $this->sellOnCredit([
            ['id' => $bread->id, 'name' => 'خبز', 'qty' => 1],
            ['id' => $shirt->id, 'name' => 'قميص', 'qty' => 1],
        ]);

        $invoice = $this->invoice();
        $lineTax = round($invoice->items->sum(fn ($i) => (float) $i->tax_amount), 3);

        $this->assertSame(5.0, (float) $order->tax, 'المقدّمةُ خاطئة');
        $this->assertSame((float) $order->tax, $lineTax, 'مجموعُ ضرائب السطور يخالف ضريبةَ الورقة');
        $this->assertSame((float) $invoice->tax_total, $lineTax);
    }

    /* ══════════ ٢ · وخصمُ الطلب يُوزَّع على السطور ══════════ */

    /**
     * خصمٌ على مستوى الطلب — كوبونًا كان أو نقاطًا — كان يُمرَّر صفرًا لكلّ
     * سطر، فتُحسب ضريبةُ السطر على مبلغٍ لم يدفعه أحد، ويقول السطرُ غيرَ ما
     * يقول الذيل.
     *
     * والتوزيعُ بالحصّة هو توزيعُ `PosController::taxFor` نفسُه: حسمُه من
     * وعاءٍ واحد يُنقص ضريبةَ الصنف الصفريّ ويُبقيها كاملةً على الخاضع.
     */
    public function test_an_order_discount_is_spread_over_the_lines(): void
    {
        $shirt = $this->product(['name' => 'قميص']);

        $order = Order::create([
            'business_id' => $this->shop->id, 'number' => 'INV-000900',
            'customer_id' => $this->customer->id, 'customer_name' => 'زبون',
            'subtotal' => 200, 'discount' => 20, 'tax' => 9, 'delivery_fee' => 0,
            'total' => 189, 'payment_method' => 'نقدي', 'status' => 'مكتمل',
            'payment_status' => 'غير مدفوع', 'is_held' => false, 'ordered_at' => now(),
        ]);
        $order->items()->create([
            'product_id' => $shirt->id, 'name' => 'قميص',
            'price' => 100, 'cost' => 40, 'quantity' => 2, 'total' => 200,
        ]);

        $invoice = CustomerInvoices::fromOrder($order->fresh('items'), [], $this->owner->id);
        $line = $invoice->items->first();

        $this->assertSame(20.0, (float) $line->discount, 'خصمُ الطلب لم يصل سطرَ الورقة');
        $this->assertSame(9.0, round((float) $line->tax_amount, 3), 'ضريبةُ السطر حُسبت على مبلغٍ قبل الخصم');
        $this->assertSame((float) $invoice->tax_total, round((float) $line->tax_amount, 3));
    }

    /** وبندان يقتسمانه بحصّتهما لا بالتساوي */
    public function test_two_lines_share_it_by_weight(): void
    {
        $big = $this->product(['name' => 'كبير', 'price' => 300]);
        $small = $this->product(['name' => 'صغير', 'price' => 100]);

        $order = Order::create([
            'business_id' => $this->shop->id, 'number' => 'INV-000901',
            'customer_id' => $this->customer->id, 'customer_name' => 'زبون',
            'subtotal' => 400, 'discount' => 40, 'tax' => 18, 'delivery_fee' => 0,
            'total' => 378, 'payment_method' => 'نقدي', 'status' => 'مكتمل',
            'payment_status' => 'غير مدفوع', 'is_held' => false, 'ordered_at' => now(),
        ]);
        $order->items()->create(['product_id' => $big->id, 'name' => 'كبير',
            'price' => 300, 'cost' => 100, 'quantity' => 1, 'total' => 300]);
        $order->items()->create(['product_id' => $small->id, 'name' => 'صغير',
            'price' => 100, 'cost' => 40, 'quantity' => 1, 'total' => 100]);

        $invoice = CustomerInvoices::fromOrder($order->fresh('items'), [], $this->owner->id);
        $by = $invoice->items->keyBy('description');

        $this->assertSame(30.0, (float) $by['كبير']->discount, 'الكبيرُ لم يأخذ ثلاثةَ أرباع الخصم');
        $this->assertSame(10.0, (float) $by['صغير']->discount);
        $this->assertSame(40.0, round($invoice->items->sum(fn ($i) => (float) $i->discount), 3));
    }
}
