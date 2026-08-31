<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Transaction;
use App\Support\DemoStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * كلّ فاتورةٍ بيعت لها قيدُ دخلها — وإلّا كذبت المالية.
 *
 * المالية كلُّها تقرأ `transactions`: إجمالي المبيعات، وصافي الإيراد،
 * والضريبة المحصّلة، ووسائل الدفع. والربحية تقرأ منها الإيراد وتقرأ التكلفة
 * من بنود الطلبات — من مصدرين لا من واحد.
 *
 * فما دخل من بابٍ لا يكتب القيد يظهر تكلفةً بلا إيراد. ورأيتُها على المتجر
 * التجريبيّ: ألفٌ وثمانٍ وخمسون فاتورة بلا قيد، فقالت الشاشة «خسارةٌ صافية
 * بمليون ريال» على متجرٍ باع مليونين وسبعمئة ألف — وهو أوّل ما يراه من
 * يُعرض عليه النظام.
 */
class EverySaleCarriesItsIncomeTest extends TestCase
{
    use RefreshDatabase;

    private function order(array $attributes = []): Order
    {
        $business = Business::firstOrCreate(
            ['email' => 'inc@test.local'],
            ['name' => 'محل الدخل', 'status' => 'نشط'],
        );
        Branch::firstOrCreate(['business_id' => $business->id, 'name' => 'الفرع الرئيسي']);

        return Order::create(array_merge([
            'business_id' => $business->id,
            'number' => 'INV-'.str_pad((string) (Order::count() + 1), 6, '0', STR_PAD_LEFT),
            'customer_name' => 'زبون',
            'employee_name' => 'كاشير',
            'status' => \App\Support\OrderStatus::COMPLETED,
            'payment_method' => 'بطاقة',
            'subtotal' => 100, 'tax' => 5, 'total' => 105,
            'is_held' => false,
            'ordered_at' => now()->subMonths(3),
        ], $attributes));
    }

    /* ------------------------------ الإصلاح ------------------------------ */

    public function test_a_sale_with_no_entry_gets_one(): void
    {
        $order = $this->order();

        $this->artisan('finance:repair-order-income')->assertSuccessful();

        $tx = Transaction::where('order_id', $order->id)->firstOrFail();

        $this->assertSame('دخل', $tx->type);
        $this->assertEqualsWithDelta(105.0, (float) $tx->amount, 0.0005);
        $this->assertEqualsWithDelta(5.0, (float) $tx->tax_amount, 0.0005);
        $this->assertSame('بطاقة', $tx->method);
        $this->assertSame($order->number, $tx->reference);
    }

    public function test_the_entry_carries_the_day_of_the_sale_not_the_day_of_the_repair(): void
    {
        // قيدٌ بتاريخ اليوم يجعل مبيعات العام كلّها تظهر في شهرٍ واحد
        $order = $this->order(['ordered_at' => now()->subMonths(7)]);

        $this->artisan('finance:repair-order-income')->assertSuccessful();

        $this->assertSame(
            $order->ordered_at->toDateString(),
            Transaction::where('order_id', $order->id)->firstOrFail()->occurred_at->toDateString(),
        );
    }

    public function test_running_it_twice_writes_one_entry(): void
    {
        $this->order();

        $this->artisan('finance:repair-order-income')->assertSuccessful();
        $this->artisan('finance:repair-order-income')->assertSuccessful();

        $this->assertSame(1, Transaction::count());
    }

    public function test_a_dry_run_writes_nothing(): void
    {
        $this->order();

        $this->artisan('finance:repair-order-income', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame(0, Transaction::count());
    }

    public function test_a_cancelled_sale_gets_no_entry(): void
    {
        $this->order(['status' => \App\Support\OrderStatus::CANCELLED]);

        $this->artisan('finance:repair-order-income')->assertSuccessful();

        $this->assertSame(0, Transaction::count());
    }

    public function test_a_held_ticket_gets_no_entry(): void
    {
        // المعلّق لم يُبَع بعد — وقيدُ دخلٍ عليه دخلٌ لم يقع
        $this->order(['is_held' => true]);

        $this->artisan('finance:repair-order-income')->assertSuccessful();

        $this->assertSame(0, Transaction::count());
    }

    public function test_a_sale_that_already_has_its_entry_is_left_alone(): void
    {
        $order = $this->order();
        Transaction::create([
            'business_id' => $order->business_id, 'order_id' => $order->id,
            'reference' => $order->number, 'description' => 'قيدٌ قائم',
            'method' => 'نقدي', 'type' => 'دخل', 'amount' => 105,
            'occurred_at' => $order->ordered_at,
        ]);

        $this->artisan('finance:repair-order-income')->assertSuccessful();

        $this->assertSame(1, Transaction::count());
        $this->assertSame('قيدٌ قائم', Transaction::first()->description);
    }

    public function test_one_shop_is_repaired_without_touching_another(): void
    {
        $mine = $this->order();
        $other = Business::create(['name' => 'محل آخر', 'email' => 'inc2@test.local', 'status' => 'نشط']);
        Order::create([
            'business_id' => $other->id, 'number' => 'INV-900001',
            'status' => \App\Support\OrderStatus::COMPLETED, 'is_held' => false,
            'subtotal' => 10, 'tax' => 0, 'total' => 10, 'ordered_at' => now(),
        ]);

        $this->artisan('finance:repair-order-income', ['--business' => $mine->business_id])->assertSuccessful();

        $this->assertSame(1, Transaction::count());
        $this->assertSame((int) $mine->business_id, (int) Transaction::first()->business_id);
    }

    /* ------------------------------ البذور ------------------------------ */

    public function test_the_demo_store_is_born_with_its_books_in_balance(): void
    {
        Plan::updateOrCreate(['name' => 'الباقة الاحترافية'], [
            'monthly_price' => 30, 'yearly_price' => 300,
            'max_branches' => 3, 'max_employees' => 15, 'max_products' => 100000,
        ]);

        $business = DemoStore::create('متجر الميزان', 'صغير');

        $sold = Order::where('business_id', $business->id)->sold()->count();
        $entries = Transaction::where('business_id', $business->id)
            ->where('type', 'دخل')->whereNotNull('order_id')->count();

        $this->assertGreaterThan(0, $sold);
        $this->assertSame($sold, $entries, 'كلّ فاتورةٍ مباعة لها قيدُ دخلها');

        // والإيراد المقروء من القيود يطابق ما بيع فعلًا — لا كسرًا منه
        $this->assertEqualsWithDelta(
            (float) Order::where('business_id', $business->id)->sold()->sum('total'),
            (float) Transaction::where('business_id', $business->id)->where('type', 'دخل')
                ->whereNotNull('order_id')->sum('amount'),
            0.05,
        );
    }

    public function test_the_demo_items_carry_their_cost_at_the_moment_of_sale(): void
    {
        Plan::updateOrCreate(['name' => 'الباقة الاحترافية'], [
            'monthly_price' => 30, 'yearly_price' => 300,
            'max_branches' => 3, 'max_employees' => 15, 'max_products' => 100000,
        ]);

        $business = DemoStore::create('متجر اللقطة', 'صغير');

        $withoutCost = \App\Models\OrderItem::whereIn(
            'order_id', Order::where('business_id', $business->id)->select('id'),
        )->where(fn ($q) => $q->whereNull('cost')->orWhere('cost', 0))->count();

        $this->assertSame(0, $withoutCost, 'بندٌ بلا لقطة تكلفة يجعل الربح يُقرأ من بطاقة المنتج اليوم');
    }
}
