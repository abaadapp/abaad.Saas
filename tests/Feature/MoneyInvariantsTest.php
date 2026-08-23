<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\JobTitle;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ثوابت المال: ما يُجمع على الشاشة هو ما يُكتب في الفاتورة وفي الدفتر.
 *
 * كلّ عطبٍ في هذا الباب صامت: الفاتورة تُطبع، والزبون يدفع، والفرق يظهر بعد
 * شهورٍ في إقرارٍ ضريبيّ أو في صندوقٍ لا يوازن.
 */
class MoneyInvariantsTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;
    private User $owner;
    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');

        $this->business = Business::create(['name' => 'متجري', 'type' => 'عام', 'status' => 'نشط']);
        $this->branch = Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);
        JobTitle::create(['business_id' => $this->business->id, 'name' => 'مدير', 'role' => 'admin']);
        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@abaadapp.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
    }

    private function set(string $key, string $value): void
    {
        Setting::updateOrCreate(['business_id' => $this->business->id, 'key' => $key], ['value' => $value]);
    }

    private function product(float $price, ?float $tax = null): Product
    {
        return Product::create([
            'business_id' => $this->business->id, 'name' => 'صنف'.uniqid(),
            'price' => $price, 'cost' => 1, 'quantity' => 1000, 'alert_qty' => 1,
            'active' => true, 'tax' => $tax,
        ]);
    }

    private function sell(array $items, array $extra = []): Order
    {
        $this->actingAs($this->owner)->withSession(['current_branch' => $this->branch->id])
            ->postJson(route('pos.checkout'), array_merge([
                'items' => $items, 'payment_method' => 'نقدي',
                'client_uuid' => uniqid('t', true),
            ], $extra))->assertOk();

        return Order::latest('id')->firstOrFail();
    }

    /** الفاتورة تتوازن: الفرعيّ − الخصم + الضريبة + التوصيل = الإجمالي */
    public function test_every_invoice_adds_up(): void
    {
        $this->set('vat_enabled', '1');
        $this->set('vat_rate', '5');
        $this->set('tax_mode', 'exclusive');

        $a = $this->product(3.750);
        $b = $this->product(12.333);

        foreach ([[], ['discount' => 5]] as $extra) {
            $order = $this->sell([
                ['id' => $a->id, 'name' => $a->name, 'qty' => 3],
                ['id' => $b->id, 'name' => $b->name, 'qty' => 7],
            ], $extra);

            $sum = round((float) $order->subtotal - (float) $order->discount
                + (float) $order->tax + (float) $order->delivery_fee, 3);

            $this->assertSame(round((float) $order->total, 3), $sum,
                'الفاتورة '.$order->number.' لا تتوازن');
        }
    }

    /** وبنودُها تجمع فرعيَّها — لا سطرٌ يسقط ولا يُحسب مرّتين */
    public function test_the_lines_add_up_to_the_subtotal(): void
    {
        $a = $this->product(2.500);
        $order = $this->sell([['id' => $a->id, 'name' => $a->name, 'qty' => 4]]);

        $this->assertSame(round((float) $order->subtotal, 3),
            round((float) $order->items()->sum('total'), 3));
    }

    /** الضريبة مُطفأة: لا تُحسب ولا تُكتب — ولو كان للصنف نسبةٌ خاصّة */
    public function test_vat_off_means_no_tax_anywhere(): void
    {
        $this->set('vat_enabled', '0');
        $this->set('vat_rate', '5');

        $p = $this->product(10, 15);   // نسبةٌ خاصّة على الصنف
        $order = $this->sell([['id' => $p->id, 'name' => $p->name, 'qty' => 2]]);

        $this->assertSame(0.0, round((float) $order->tax, 3), 'ضريبةٌ حُصّلت والمفتاح مُطفأ');
        $this->assertSame(20.0, round((float) $order->total, 3));
    }

    /** الضريبة المضمَّنة لا تزيد الإجمالي — تُستخرج منه */
    public function test_inclusive_tax_is_taken_out_not_added_on(): void
    {
        $this->set('vat_enabled', '1');
        $this->set('vat_rate', '5');
        $this->set('tax_mode', 'inclusive');

        $p = $this->product(10.500);
        $order = $this->sell([['id' => $p->id, 'name' => $p->name, 'qty' => 2]]);

        $this->assertSame(21.0, round((float) $order->total, 3), 'المضمَّنة زادت الإجمالي');
        $this->assertSame(1.0, round((float) $order->tax, 3), '21 × 5 ÷ 105 = 1.000');
    }

    /** ولا فاتورةَ بإجماليٍّ سالب مهما بلغ الخصم */
    public function test_a_discount_never_drives_the_total_below_zero(): void
    {
        $p = $this->product(5);
        $order = $this->sell([['id' => $p->id, 'name' => $p->name, 'qty' => 1]], ['discount' => 999]);

        $this->assertGreaterThanOrEqual(0, (float) $order->total, 'فاتورةٌ بإجماليٍّ سالب');
        $this->assertGreaterThanOrEqual(0, (float) $order->tax, 'ضريبةٌ سالبة');
    }
}
