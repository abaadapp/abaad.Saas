<?php

namespace Tests\Feature;

use App\Exceptions\ClosedPeriod;
use App\Models\JournalEntry;
use App\Models\PaymobSettlement;
use App\Models\PaymobSettlementItem;
use App\Models\Setting;
use App\Support\PaymobSettlements;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\PaymobPayouts;
use Tests\TestCase;

/**
 * تحويلُ Paymob بتاريخٍ في فترةٍ مُقفلة يُردّ كلُّه — والبيعُ بالبطاقة اليوم يمضي.
 *
 * التسويةُ (#168) تكتب صفَّها وبنودَ مدفوعاتها ثمّ قيدَها في معاملةٍ واحدة،
 * و`Ledger::post` يرفض الفترةَ المقفلة (`ClosedPeriod`). فلا يبقى صفٌّ «معلّق»
 * ولا بندٌ يحجز مدفوعةً عن تحويلها الصحيح. ويُسجَّل التحويلُ نفسُه بتاريخٍ مفتوح.
 */
class APaymobPayoutIntoAClosedPeriodIsRefusedWholeTest extends TestCase
{
    use PaymobPayouts;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2027-02-20 10:00:00');
        config([
            'storefront.ribbon_english_checkout_businesses' => [],
            'storefront.ribbon_gift_card_product_businesses' => [],
            'storefront.ribbon_free_gift_card_message_businesses' => [],
        ]);
        [$this->shop, $this->owner, $this->rose] = $this->openShop('closedpayout', 'closed-payout@abaadapp.om');
        $this->actingAs($this->owner);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function payout(string $on, array $payments): PaymobSettlement
    {
        return PaymobSettlements::record($this->shop->id, [
            'items' => array_map(fn ($p) => $this->key($p), $payments), 'fee' => 0.6, 'net' => 19.4, 'settled_on' => $on,
        ], $this->owner->id);
    }

    public function test_a_payout_dated_in_a_closed_period_leaves_nothing_behind_and_its_payment_still_waits(): void
    {
        Setting::create(['business_id' => $this->shop->id, 'key' => 'books_closed_through', 'value' => '2027-02-15']);
        // البيعُ بالبطاقة اليوم في الفترة المفتوحة — والإقفالُ لا يوقف الموقع
        $sale = $this->newSale();
        $this->assertSame(20.0, $this->clearing());
        $entries = JournalEntry::count();

        try {
            $this->payout('2027-02-13', [$sale]);
            $this->fail('كُتب تحويلٌ في فترةٍ مُقفلة');
        } catch (ClosedPeriod) {
        }

        $this->assertSame(0, PaymobSettlement::count(), 'بقي صفُّ تحويلٍ من محاولةٍ مردودة');
        $this->assertSame(0, PaymobSettlementItem::count(), 'بقي بندٌ يحجز المدفوعة');
        $this->assertSame($entries, JournalEntry::count());
        $this->assertSame([20.0, 0.0, 0.0], [$this->clearing(), $this->bank(), $this->fees()]);
        $this->assertSame([$this->key($sale)], collect(PaymobSettlements::candidates($this->shop->id))->pluck('key')->all());

        // والتحويلُ نفسُه بتاريخٍ مفتوح يُرحَّل مرّةً واحدة
        $this->assertSame(PaymobSettlements::POSTED, $this->payout('2027-02-16', [$sale])->status);
        $this->assertSame([0.0, 19.4, 0.6], [$this->clearing(), $this->bank(), $this->fees()]);
    }

    public function test_a_filed_vat_return_closes_the_payout_date_too(): void
    {
        Setting::create(['business_id' => $this->shop->id, 'key' => 'vat_filed_through', 'value' => '2027-02-15']);
        $sale = $this->newSale();

        $this->expectException(ClosedPeriod::class);
        $this->payout('2027-02-01', [$sale]);
    }
}
