<?php

namespace Tests\Feature;

use App\Models\Addon;
use App\Models\Branch;
use App\Models\BranchStock;
use App\Models\Business;
use App\Models\Currency;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Order;
use App\Models\OrderEdit;
use App\Models\OrderItem;
use App\Models\OrderItemAddon;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Support\Ledger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * فاتورةٌ أُلغيت لا تُصحَّح بنودُها ولا وسيلةُ دفعها.
 *
 * الإلغاءُ يردّ الرفَّ ويعكس قيدَ البيعة. وكان تصحيحُ الكمّيّة والإضافة
 * ووسيلة الدفع لا يسأل عنه، والشاشتان تعرضان الزرَّ للملغاة في يومها:
 *   • ثلاثُ وردٍ أُلغيت فعادت، ثمّ «اجعلها واحدة» — فعادت اثنتان ثانيةً
 *     (الرفّ ١٢ لا ١٠)، وكُتب للملغاة إجماليٌّ وقيد.
 *   • و«كانت بطاقة» على الملغاة يُعيد ترحيلَها كلَّها (`syncBooks`): تعود
 *     إيراداتُها وضريبتُها وتكلفتُها إلى الدفتر بعد أن عُكست.
 */
class ACancelledSaleIsNotCorrectedTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private Branch $branch;

    private User $owner;

    private Product $rose;

    private Product $vase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 10:00:00');
        app()->setLocale('ar');

        $this->shop = Business::create(['name' => 'ورد الصباح', 'status' => 'نشط']);
        Currency::create(['business_id' => $this->shop->id, 'code' => 'OMR', 'name' => 'ريال عماني', 'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);
        Ledger::seedChart($this->shop->id);
        Setting::updateOrCreate(['business_id' => $this->shop->id, 'key' => 'vat_enabled'], ['value' => '1']);
        Setting::updateOrCreate(['business_id' => $this->shop->id, 'key' => 'vat_rate'], ['value' => '5']);
        $this->branch = Branch::create(['business_id' => $this->shop->id, 'name' => 'مسقط']);
        $this->owner = User::create([
            'business_id' => $this->shop->id, 'name' => 'المالك', 'email' => 'o@cancelled.local',
            'password' => bcrypt('secret12345'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->rose = $this->product('وردة', 10);
        $this->vase = $this->product('مزهرية', 10);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function product(string $name, int $onShelf): Product
    {
        $p = Product::create([
            'business_id' => $this->shop->id, 'name' => $name, 'price' => 10, 'cost' => 4,
            'quantity' => $onShelf, 'alert_qty' => 1, 'active' => true,
        ]);
        BranchStock::adjust($this->shop->id, $this->branch->id, $p->id, $onShelf);

        return $p;
    }

    private function as()
    {
        return $this->actingAs($this->owner)->withSession(['current_branch' => $this->branch->id]);
    }

    private function sell(array $roseAddons = []): Order
    {
        $this->as()->postJson('/pos/checkout', [
            'items' => [
                ['id' => $this->rose->id, 'name' => 'وردة', 'qty' => 3, 'price' => 10, 'addons' => $roseAddons],
                ['id' => $this->vase->id, 'name' => 'مزهرية', 'qty' => 1, 'price' => 10],
            ],
            'payment_method' => 'نقدي', 'client_uuid' => uniqid('c', true),
        ])->assertOk();

        return Order::where('business_id', $this->shop->id)->latest('id')->firstOrFail();
    }

    private function cancel(Order $order): void
    {
        $this->as()->delete(route('admin.orders.destroy', $order->number))->assertSessionHasNoErrors();
        $this->assertSame(Order::CANCELLED, $order->fresh()->status);
    }

    private function roseLine(Order $order): OrderItem
    {
        return OrderItem::where('order_id', $order->id)->where('product_id', $this->rose->id)->firstOrFail();
    }

    /** كلُّ ما يمسّه تصحيح — ليُقال إنّ الرفضَ لم يمسّ منه شيئًا */
    private function written(Order $order): array
    {
        $o = $order->fresh();

        return [
            'order' => [(float) $o->subtotal, (float) $o->tax, (float) $o->total, $o->payment_method, $o->status],
            'lines' => OrderItem::where('order_id', $order->id)->orderBy('id')->pluck('quantity')->map(fn ($q) => (int) $q)->all(),
            'addons' => OrderItemAddon::whereIn('order_item_id', OrderItem::where('order_id', $order->id)->pluck('id'))->pluck('quantity')->map(fn ($q) => (int) $q)->all(),
            'shelf' => [(int) $this->rose->fresh()->quantity, (int) $this->vase->fresh()->quantity],
            'branch' => BranchStock::where('branch_id', $this->branch->id)->orderBy('product_id')->pluck('quantity')->map(fn ($q) => (int) $q)->all(),
            'journal' => JournalLine::whereIn('journal_entry_id', JournalEntry::where('business_id', $this->shop->id)->pluck('id'))
                ->orderBy('id')->get(['account_id', 'debit', 'credit'])->map(fn ($l) => [$l->account_id, (float) $l->debit, (float) $l->credit])->all(),
            'edits' => OrderEdit::where('order_id', $order->id)->count(),
        ];
    }

    /** صافي كلِّ حسابٍ في الدفتر — بيعةٌ ملغاة صافيها صفر */
    private function net(): array
    {
        $net = [];
        foreach (JournalLine::whereIn('journal_entry_id', JournalEntry::where('business_id', $this->shop->id)->pluck('id'))->get() as $l) {
            $net[$l->account_id] = round(($net[$l->account_id] ?? 0) + (float) $l->debit - (float) $l->credit, 3);
        }

        return array_filter($net);
    }

    public function test_a_line_of_a_cancelled_sale_is_not_corrected_back_onto_the_shelf(): void
    {
        $order = $this->sell();
        $this->cancel($order);
        $before = $this->written($order);
        $this->assertSame([10, 10], $before['shelf']);

        $this->as()->put(route('admin.orders.items.update', [$order->number, $this->roseLine($order)->id]), [
            'quantity' => 1, 'reason' => 'تصحيح بعد الإلغاء',
        ])->assertSessionHasErrors('quantity');

        $this->assertStringContainsString('ملغاة', (string) session('errors')->first('quantity'));
        $this->assertSame($before, $this->written($order));
    }

    public function test_an_addon_of_a_cancelled_sale_is_not_corrected(): void
    {
        $chocolate = Addon::create(['business_id' => $this->shop->id, 'name' => 'شوكولاتة', 'price' => 2, 'active' => true]);
        $order = $this->sell([['addon_id' => $chocolate->id, 'qty' => 2]]);
        $row = OrderItemAddon::where('order_item_id', $this->roseLine($order)->id)->firstOrFail();
        $this->cancel($order);
        $before = $this->written($order);

        $this->as()->put(route('admin.orders.items.addons.update', [$order->number, $this->roseLine($order)->id, $row->id]), [
            'quantity' => 1, 'reason' => 'تصحيح بعد الإلغاء',
        ])->assertSessionHasErrors('quantity');

        $this->assertSame($before, $this->written($order));
    }

    public function test_the_payment_method_of_a_cancelled_sale_does_not_post_it_again(): void
    {
        $order = $this->sell();
        $this->cancel($order);
        $before = $this->written($order);
        $this->assertSame([], $this->net());

        $this->as()->put(route('admin.orders.payment.update', $order->number), [
            'payment_method' => 'بطاقة', 'reason' => 'كانت بطاقة',
        ])->assertSessionHasErrors('payment_method');

        $this->assertSame($before, $this->written($order));
        $this->assertSame([], $this->net(), 'عادت بيعةٌ ملغاة إلى الدفتر');
    }

    public function test_neither_screen_offers_a_correction_on_a_cancelled_sale(): void
    {
        $order = $this->sell();

        $this->as()->get(route('admin.orders.show', $order->number))
            ->assertInertia(fn (Assert $page) => $page->where('invoiceEdit.can', true));
        $this->as()->get(route('pos.order-details', $order->number))
            ->assertInertia(fn (Assert $page) => $page->where('canEdit', true));

        $this->cancel($order);

        $this->as()->get(route('admin.orders.show', $order->number))
            ->assertInertia(fn (Assert $page) => $page->where('invoiceEdit.can', false)->where('invoiceEdit.reason', 'الفاتورة ملغاة — لا تُعدَّل.'));
        $this->as()->get(route('pos.order-details', $order->number))
            ->assertInertia(fn (Assert $page) => $page->where('canEdit', false));
    }

    public function test_a_sale_that_was_not_cancelled_is_still_corrected(): void
    {
        $order = $this->sell();

        $this->as()->put(route('admin.orders.items.update', [$order->number, $this->roseLine($order)->id]), [
            'quantity' => 1, 'reason' => 'أعاد الزبون وردتين',
        ])->assertSessionHasNoErrors();
        $this->as()->put(route('admin.orders.payment.update', $order->number), [
            'payment_method' => 'بطاقة', 'reason' => 'دفع بالبطاقة',
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, (int) $this->roseLine($order)->quantity);
        $this->assertSame([9, 9], $this->written($order)['shelf']);
        $this->assertSame('بطاقة', $order->fresh()->payment_method);
    }
}
