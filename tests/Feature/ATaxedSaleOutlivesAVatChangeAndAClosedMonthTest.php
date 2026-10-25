<?php

namespace Tests\Feature;

use App\Exceptions\ClosedPeriod;
use App\Models\Business;
use App\Models\Currency;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Support\Ledger;
use App\Support\Vat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * بيعةٌ بضريبتها تعيش بعد تغيّر الضريبة وبعد إقفال شهرها — #158 و#157 معًا.
 *
 * متجرٌ قائمٌ قبل `Vat::DEFAULT_OFF_FROM` لم يُجب عن الضريبة فيجبيها (٥٪)،
 * يبيع، ثمّ يُطفئها، ثمّ يُصحّح بندًا (يُردّ: لا تُعاد ضريبةُ ما لم يُصحَّح)،
 * ثمّ يُلغي البيعة كلَّها (تُعكس ضريبتُها كما جُبيت)، ثمّ يُقفل الشهر (لا قيدَ
 * فيه بعدها)، ثمّ يبيع اليوم بلا ضريبة. ومتجرٌ جديدٌ بجواره لا يصله شيء.
 */
class ATaxedSaleOutlivesAVatChangeAndAClosedMonthTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private User $owner;

    private Product $rose;

    private Product $vase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-10 10:00:00');
        app()->setLocale('ar');

        [$this->shop, $this->owner] = $this->open('ورد قائم', 'old@vat-and-period.local', Carbon::parse(Vat::DEFAULT_OFF_FROM)->subDays(20));
        $this->rose = $this->product($this->shop, 'وردة', 10);
        $this->vase = $this->product($this->shop, 'مزهرية', 20);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** متجرٌ بلا جوابٍ عن الضريبة — أُنشئ في لحظةٍ بعينها */
    private function open(string $name, string $email, Carbon $createdAt): array
    {
        $shop = Business::create(['name' => $name, 'status' => 'نشط']);
        $shop->forceFill(['created_at' => $createdAt])->save();
        Currency::create(['business_id' => $shop->id, 'code' => 'OMR', 'name' => 'ريال عماني', 'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);
        Ledger::seedChart($shop->id);
        $owner = User::create([
            'business_id' => $shop->id, 'name' => 'المالك', 'email' => $email,
            'password' => bcrypt('secret12345'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        return [$shop->fresh(), $owner];
    }

    private function product(Business $shop, string $name, float $price): Product
    {
        return Product::create([
            'business_id' => $shop->id, 'name' => $name, 'price' => $price, 'cost' => $price / 2,
            'quantity' => 50, 'alert_qty' => 1, 'active' => true,
        ]);
    }

    /** من الصندوق — بندان: المنتجُ الأوّل مرّتين والثاني مرّة */
    private function sell(User $by, Product $a, Product $b): Order
    {
        $this->actingAs($by)->postJson('/pos/checkout', [
            'items' => [
                ['id' => $a->id, 'name' => $a->name, 'qty' => 2, 'price' => (float) $a->price],
                ['id' => $b->id, 'name' => $b->name, 'qty' => 1, 'price' => (float) $b->price],
            ],
            'payment_method' => 'نقدي',
        ])->assertOk();

        return Order::where('business_id', $by->business_id)->latest('id')->firstOrFail();
    }

    private function money(Order $order): array
    {
        $o = $order->fresh();

        return [(float) $o->subtotal, (float) $o->tax, (float) $o->total];
    }

    private function taxPayable(Business $shop): float
    {
        $account = Ledger::account($shop->id, 'tax_payable');

        return round((float) JournalLine::where('account_id', $account->id)->sum('credit') - (float) JournalLine::where('account_id', $account->id)->sum('debit'), 3);
    }

    public function test_a_taxed_sale_survives_vat_turned_off_a_refused_correction_a_cancel_and_a_closed_month(): void
    {
        // ١ · متجرٌ قائمٌ لم يُجب: يجبي كما كان قبل تغيّر الافتراض
        $this->assertTrue(Vat::enabled($this->shop->id));
        $sale = $this->sell($this->owner, $this->rose, $this->vase);
        $this->assertSame([40.0, 2.0, 42.0], $this->money($sale));
        $this->assertSame(2.0, $this->taxPayable($this->shop));

        // ومتجرٌ جديدٌ بجواره لم يُجب: لا يجبي — ولا يستعير افتراضَ القائم
        Carbon::setTestNow('2026-11-10 11:00:00');
        [$new, $newOwner] = $this->open('ورد جديد', 'new@vat-and-period.local', Carbon::parse(Vat::DEFAULT_OFF_FROM)->addDays(10));
        $neighbour = $this->sell($newOwner, $this->product($new, 'وردة', 10), $this->product($new, 'مزهرية', 20));
        $this->assertSame([40.0, 0.0, 40.0], $this->money($neighbour));

        // ٢ · يُطفئها صاحبُها بعد البيع
        Setting::create(['business_id' => $this->shop->id, 'key' => 'vat_enabled', 'value' => '0']);
        $this->assertFalse(Vat::enabled($this->shop->id));

        // ٣ · تصحيحُ وردةٍ واحدة يُردّ — ضريبةُ المزهرية لا تسقط، ولا يُردّ ما لم يُدفع
        $roses = OrderItem::where('order_id', $sale->id)->where('product_id', $this->rose->id)->firstOrFail();
        $entries = JournalEntry::where('business_id', $this->shop->id)->count();
        $this->actingAs($this->owner)->put(route('admin.orders.items.update', [$sale->number, $roses->id]), ['quantity' => 1, 'reason' => 'أعاد وردة'])
            ->assertSessionHasErrors('quantity');
        $this->assertSame([40.0, 2.0, 42.0], $this->money($sale));
        $this->assertSame($entries, JournalEntry::where('business_id', $this->shop->id)->count());
        $this->assertSame(2.0, $this->taxPayable($this->shop));

        // ٤ · الإلغاءُ الكامل يعكس ما جُبي بمبالغه — والورقةُ تبقى بما بيعت به
        $this->actingAs($this->owner)->delete(route('admin.orders.destroy', $sale->number))->assertSessionHasNoErrors();
        $this->assertSame(Order::CANCELLED, $sale->fresh()->status);
        $this->assertSame([40.0, 2.0, 42.0], $this->money($sale));
        $this->assertSame(0.0, $this->taxPayable($this->shop));

        // ٥ · يُقفل نوفمبر حتى الخامس عشر: لا قيدَ فيه بعدها، ولا نصفَ عمليّة
        Carbon::setTestNow('2026-11-20 10:00:00');
        Setting::create(['business_id' => $this->shop->id, 'key' => 'books_closed_through', 'value' => '2026-11-15']);
        $before = JournalEntry::where('business_id', $this->shop->id)->count();
        try {
            Ledger::post($this->shop->id, 'قيدٌ في شهرٍ مُقفل', [
                ['account' => 'cash', 'debit' => 5], ['account' => 'sales', 'credit' => 5],
            ], Carbon::parse('2026-11-12'), 'manual', null, $this->owner->id);
            $this->fail('كُتب قيدٌ في فترةٍ مُقفلة');
        } catch (ClosedPeriod) {
        }
        $this->assertSame($before, JournalEntry::where('business_id', $this->shop->id)->count());

        // ٦ · وبيعُ اليوم يمضي — بلا ضريبةٍ كما اختار صاحبُه
        $today = $this->sell($this->owner, $this->rose, $this->vase);
        $this->assertSame([40.0, 0.0, 40.0], $this->money($today));
        $this->assertSame(0.0, $this->taxPayable($this->shop));

        // ٧ · والجارُ لم يمسّه شيء، وكلُّ دفترٍ متوازنٌ في متجره
        $this->assertSame([40.0, 0.0, 40.0], $this->money($neighbour));
        $this->assertNull(Setting::where('business_id', $new->id)->where('key', 'books_closed_through')->value('value'));
        $this->assertTrue(Ledger::trialBalance($this->shop->id)['balanced']);
        $this->assertTrue(Ledger::trialBalance($new->id)['balanced']);
        $this->assertSame(0, JournalLine::query()->join('journal_entries as e', 'e.id', '=', 'journal_lines.journal_entry_id')
            ->join('accounts as a', 'a.id', '=', 'journal_lines.account_id')->whereColumn('a.business_id', '<>', 'e.business_id')->count());
    }
}
