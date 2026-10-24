<?php

namespace Tests\Feature;

use App\Models\Addon;
use App\Models\Business;
use App\Models\Currency;
use App\Models\InventoryMovement;
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
use Tests\TestCase;

/**
 * تصحيحُ بندٍ لا يُعيد ضريبةَ الفاتورة كلِّها بإعدادٍ تغيّر بعد بيعها.
 *
 * `OrderCorrection::recompute` تحسب الضريبةَ من إعداد اليوم، والفاتورةُ لا
 * تحفظ إلّا مبلغَ ضريبتها — لا نسبةَ بنودها ولا أكانت الضريبة مفعّلة. فبيعةٌ
 * بضريبة ٥٪، ثمّ أُطفئت الضريبة، ثمّ أُنقصت وردةٌ واحدة: تسقط ضريبةُ
 * المزهرية التي لم يمسّها أحد، ويُردّ للزبون ما لم يدفعه زيادةً، ويُنقص
 * الإقرارُ ضريبةً جُبيت فعلًا. والعكسُ كذلك: بيعةٌ بلا ضريبة تُضاف إليها.
 *
 * فيُرفض التصحيحُ برسالةٍ ما دام إعدادُ اليوم لا يُعيد ضريبةَ الفاتورة كما
 * هي، ويبقى الإلغاءُ الكامل — وهو يعكس المبالغَ المحفوظة لا يحسبها.
 */
class ACorrectionKeepsTheTaxItWasSoldWithTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

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
        $this->setting('vat_enabled', '1');
        $this->setting('vat_rate', '5');

        $this->owner = User::create([
            'business_id' => $this->shop->id, 'name' => 'المالك', 'email' => 'o@sale-tax.local',
            'password' => bcrypt('secret12345'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->rose = $this->product('وردة', 10);
        $this->vase = $this->product('مزهرية', 20);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /* ═══════════ أدوات ═══════════ */

    private function setting(string $key, string $value): void
    {
        Setting::updateOrCreate(['business_id' => $this->shop->id, 'key' => $key], ['value' => $value]);
    }

    private function product(string $name, float $price, ?float $tax = null): Product
    {
        return Product::create([
            'business_id' => $this->shop->id, 'name' => $name, 'price' => $price, 'cost' => $price / 2,
            'quantity' => 50, 'alert_qty' => 1, 'active' => true, 'tax' => $tax,
        ]);
    }

    /** وردتان ومزهرية من الصندوق: ٤٠ قبل الضريبة */
    private function sell(): Order
    {
        $this->actingAs($this->owner)->postJson('/pos/checkout', [
            'items' => [
                ['id' => $this->rose->id, 'name' => 'وردة', 'qty' => 2, 'price' => 10],
                ['id' => $this->vase->id, 'name' => 'مزهرية', 'qty' => 1, 'price' => 20],
            ],
            'payment_method' => 'نقدي',
        ])->assertOk();

        return Order::where('business_id', $this->shop->id)->latest('id')->firstOrFail();
    }

    private function roseLine(Order $order): OrderItem
    {
        return OrderItem::where('order_id', $order->id)->where('product_id', $this->rose->id)->firstOrFail();
    }

    private function correctRoses(Order $order, int $qty)
    {
        return $this->actingAs($this->owner)->put(route('admin.orders.items.update', [$order->number, $this->roseLine($order)->id]), [
            'quantity' => $qty, 'reason' => 'أعاد الزبون وردة',
        ]);
    }

    /** كلُّ ما كتبته البيعة — ليُقال إنّ الرفضَ لم يمسّ منه شيئًا */
    private function written(Order $order): array
    {
        $o = $order->fresh();

        return [
            'order' => [(float) $o->subtotal, (float) $o->discount, (float) $o->tax, (float) $o->total],
            'lines' => OrderItem::where('order_id', $order->id)->orderBy('id')->pluck('quantity')->map(fn ($q) => (int) $q)->all(),
            'shelf' => [(int) $this->rose->fresh()->quantity, (int) $this->vase->fresh()->quantity],
            'entries' => JournalEntry::where('business_id', $this->shop->id)->count(),
            'journal' => JournalLine::whereIn('journal_entry_id', JournalEntry::where('business_id', $this->shop->id)->pluck('id'))
                ->orderBy('id')->get(['account_id', 'debit', 'credit'])->map(fn ($l) => [$l->account_id, (float) $l->debit, (float) $l->credit])->all(),
            'movements' => InventoryMovement::where('business_id', $this->shop->id)->count(),
            'edits' => OrderEdit::where('order_id', $order->id)->count(),
        ];
    }

    /** صافي ضريبة المخرجات في الدفتر */
    private function taxPayable(): float
    {
        $account = Ledger::account($this->shop->id, 'tax_payable');

        return round((float) JournalLine::where('account_id', $account->id)->sum('credit')
            - (float) JournalLine::where('account_id', $account->id)->sum('debit'), 3);
    }

    /* ═══════════ تغيّر الإعداد بعد البيع ═══════════ */

    public function test_turning_vat_off_after_the_sale_refuses_a_line_correction_instead_of_stripping_its_tax(): void
    {
        $order = $this->sell();
        $this->assertSame([40.0, 0.0, 2.0, 42.0], $this->written($order)['order']);
        $before = $this->written($order);

        $this->setting('vat_enabled', '0');

        $this->correctRoses($order, 1)->assertSessionHasErrors('quantity');

        $this->assertStringContainsString('إعداد الضريبة', (string) session('errors')->first('quantity'));
        $this->assertSame($before, $this->written($order), 'غيّر التصحيحُ المرفوض شيئًا');
        $this->assertSame(2.0, $this->taxPayable(), 'نقصت ضريبةٌ جُبيت');
    }

    public function test_turning_vat_on_after_an_untaxed_sale_refuses_a_line_correction_instead_of_adding_tax(): void
    {
        $this->setting('vat_enabled', '0');
        $order = $this->sell();
        $this->assertSame([40.0, 0.0, 0.0, 40.0], $this->written($order)['order']);
        $before = $this->written($order);

        $this->setting('vat_enabled', '1');

        $this->correctRoses($order, 1)->assertSessionHasErrors('quantity');
        $this->assertSame($before, $this->written($order), 'أُضيفت ضريبةٌ لم تُجبَ');
    }

    public function test_a_new_shop_rate_after_the_sale_refuses_a_line_correction(): void
    {
        $order = $this->sell();
        $before = $this->written($order);

        $this->setting('vat_rate', '10');

        $this->correctRoses($order, 1)->assertSessionHasErrors('quantity');
        $this->assertSame($before, $this->written($order));
    }

    public function test_switching_to_tax_inclusive_prices_after_the_sale_refuses_a_line_correction(): void
    {
        $order = $this->sell();
        $before = $this->written($order);

        $this->setting('tax_mode', 'inclusive');

        $this->correctRoses($order, 1)->assertSessionHasErrors('quantity');
        $this->assertSame($before, $this->written($order));
    }

    public function test_a_new_rate_on_the_untouched_product_refuses_a_line_correction(): void
    {
        $order = $this->sell();
        $before = $this->written($order);

        // المزهريةُ صارت صفريّة — وهي ليست البندَ المصحَّح
        $this->vase->update(['tax' => 0]);

        $this->correctRoses($order, 1)->assertSessionHasErrors('quantity');
        $this->assertSame($before, $this->written($order));
    }

    public function test_correcting_an_addon_after_vat_was_turned_off_is_refused_too(): void
    {
        $chocolate = Addon::create(['business_id' => $this->shop->id, 'name' => 'شوكولاتة', 'price' => 5, 'active' => true]);

        $this->actingAs($this->owner)->postJson('/pos/checkout', [
            'items' => [
                ['id' => $this->rose->id, 'name' => 'وردة', 'qty' => 2, 'price' => 10, 'addons' => [['addon_id' => $chocolate->id, 'qty' => 2]]],
                ['id' => $this->vase->id, 'name' => 'مزهرية', 'qty' => 1, 'price' => 20],
            ],
            'payment_method' => 'نقدي',
        ])->assertOk();
        $order = Order::where('business_id', $this->shop->id)->latest('id')->firstOrFail();
        $row = OrderItemAddon::where('order_item_id', $this->roseLine($order)->id)->firstOrFail();
        $this->assertSame(2.5, (float) $order->tax);
        $before = $this->written($order);

        $this->setting('vat_enabled', '0');

        $this->actingAs($this->owner)->put(route('admin.orders.items.addons.update', [$order->number, $this->roseLine($order)->id, $row->id]), [
            'quantity' => 1, 'reason' => 'أعاد الزبون قطعة شوكولاتة',
        ])->assertSessionHasErrors('quantity');

        $this->assertSame($before, $this->written($order));
        $this->assertSame(2, (int) $row->fresh()->quantity);
    }

    public function test_adding_a_line_after_vat_was_turned_off_is_refused_too(): void
    {
        $this->shop->forceFill(['order_notes_and_edits_enabled' => true])->save();
        $order = $this->sell();
        $before = $this->written($order);

        $this->setting('vat_enabled', '0');

        $this->actingAs($this->owner)->post(route('admin.orders.items.store', $order->number), [
            'product_id' => $this->rose->id, 'qty' => 1, 'reason' => 'أضاف الزبون وردة', 'settle' => 'due',
        ])->assertSessionHasErrors('line');

        $this->assertSame($before, $this->written($order));
    }

    /* ═══════════ والإعدادُ نفسُه: التصحيحُ كما كان ═══════════ */

    public function test_with_the_settings_it_was_sold_under_a_correction_recomputes_as_before(): void
    {
        $order = $this->sell();

        $this->correctRoses($order, 1)->assertSessionHasNoErrors();

        $this->assertSame([30.0, 0.0, 1.5, 31.5], $this->written($order)['order']);
        $this->assertSame(1, (int) $this->roseLine($order)->quantity);
    }

    public function test_tax_inclusive_prices_and_a_zero_rated_line_still_correct_under_the_same_settings(): void
    {
        $this->setting('tax_mode', 'inclusive');
        $bread = $this->product('خبز', 3, tax: 0);

        $this->actingAs($this->owner)->postJson('/pos/checkout', [
            'items' => [
                ['id' => $this->rose->id, 'name' => 'وردة', 'qty' => 3, 'price' => 10],
                ['id' => $bread->id, 'name' => 'خبز', 'qty' => 1, 'price' => 3],
            ],
            'payment_method' => 'نقدي',
        ])->assertOk();
        $order = Order::where('business_id', $this->shop->id)->latest('id')->firstOrFail();
        $taxAtSale = (float) $order->tax;
        $this->assertGreaterThan(0, $taxAtSale);

        $this->correctRoses($order, 2)->assertSessionHasNoErrors();

        // ٢٠ مشمولةً بنسبة ٥٪ — والخبزُ صفريّ
        $this->assertSame(round(20 * 5 / 105, 3), (float) $order->fresh()->tax);
        $this->assertSame(23.0, (float) $order->fresh()->total);
    }

    /* ═══════════ وما ليس مالًا لا يمسّ المال ═══════════ */

    public function test_editing_what_is_not_money_keeps_the_tax_after_vat_was_turned_off(): void
    {
        $this->shop->forceFill(['order_notes_and_edits_enabled' => true])->save();
        $order = $this->sell();
        $money = $this->written($order)['order'];

        $this->setting('vat_enabled', '0');

        $this->actingAs($this->owner)->put(route('admin.orders.details.update', $order->number), [
            'internal_notes' => 'اتصل قبل التوصيل',
        ])->assertSessionHasNoErrors();
        $this->actingAs($this->owner)->put(route('admin.orders.items.note', [$order->number, $this->roseLine($order)->id]), [
            'note' => 'بلا شوك', 'reason' => 'طلب الزبون',
        ])->assertSessionHasNoErrors();
        $this->actingAs($this->owner)->put(route('admin.orders.payment.update', $order->number), [
            'payment_method' => 'بطاقة', 'reason' => 'دفع بالبطاقة لا نقدًا',
        ])->assertSessionHasNoErrors();

        $this->assertSame($money, $this->written($order)['order']);
        $this->assertSame('بطاقة', $order->fresh()->payment_method);
        $this->assertSame(2.0, $this->taxPayable());
    }

    public function test_cancelling_after_vat_was_turned_off_reverses_the_tax_that_was_collected(): void
    {
        $order = $this->sell();
        $this->assertSame(2.0, $this->taxPayable());

        $this->setting('vat_enabled', '0');

        // بيعةُ صندوقٍ مكتملة تُلغى بـ«حذف البيعة» — وهو إلغاؤها ماليًّا (`OrderController::destroy`)
        $this->actingAs($this->owner)->delete(route('admin.orders.destroy', $order->number))
            ->assertSessionHasNoErrors();

        $this->assertSame(Order::CANCELLED, $order->fresh()->status);
        // الورقةُ تبقى بما بيعت به، والقيدُ يُعكس بمبالغه لا بإعداد اليوم
        $this->assertSame([40.0, 0.0, 2.0, 42.0], $this->written($order)['order']);
        $this->assertSame(0.0, $this->taxPayable());
    }
}
