<?php

namespace Tests\Feature;

use App\Models\PaymobSettlement;
use App\Models\User;
use App\Support\PaymobSettlements;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\PaymobPayouts;
use Tests\TestCase;

/**
 * كلُّ مدفوعةٍ تنتظر التسويةَ تُبلغ — دفعةً بعد دفعة، لا خلف أقدمِ ثلاثمئة.
 *
 * كان `candidates()` يقطع عند 300 لكلّ نوعٍ بلا تتمّة، والتحويلُ الواحد لا
 * يسمّي أكثرَ من 300: متجرٌ تراكم عنده أكثرُ لا يرى الأحدثَ ولا يسوّيه. فصار
 * العرضُ دفعاتٍ بمؤشّرٍ (`candidatesPage`) والتحويلُ يسمّي حتّى `MAX_ITEMS`.
 * ويبقى كلُّ حارس: لا مدفوعةَ في تسويتين، ولا متجرَ يرى مدفوعاتِ غيره.
 */
class EveryWaitingPaymobPaymentCanBeReachedTest extends TestCase
{
    use PaymobPayouts;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2027-02-10 10:00:00');
        config([
            'storefront.ribbon_english_checkout_businesses' => [],
            'storefront.ribbon_gift_card_product_businesses' => [],
            'storefront.ribbon_free_gift_card_message_businesses' => [],
        ]);
        [$this->shop, $this->owner, $this->rose] = $this->openShop('batches', 'batches@abaadapp.om');
        $this->actingAs($this->owner);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** كلُّ ما ينتظر — بالمرور على الدفعات حتّى آخرها */
    private function walk(int $size): array
    {
        [$keys, $after, $pages] = [[], null, 0];
        do {
            $page = PaymobSettlements::candidatesPage($this->shop->id, $after, $size);
            $keys = [...$keys, ...array_column($page['rows'], 'key')];
            $after = $page['next'];
            $pages++;
        } while ($after !== null && $pages < 50);

        return [$keys, $pages];
    }

    /** روابطُ مدفوعة — كلُّ رابطٍ في دقيقته، وكلُّ ثالثٍ في دقيقة سابقه (تعادلٌ يُفصل بالنوع والرقم) */
    private function links(int $n): array
    {
        $made = [];
        for ($i = 0; $i < $n; $i++) {
            if ($i % 3 !== 2) {
                Carbon::setTestNow(now()->addMinute());
            }
            $made[] = $this->paidLink(10 + $i);
        }

        return $made;
    }

    public function test_every_waiting_payment_is_reached_once_batch_after_batch(): void
    {
        $made = array_map(fn ($p) => $this->key($p), $this->links(7));
        $made[] = $this->key($this->newSale());

        [$keys, $pages] = $this->walk(3);

        $this->assertCount(8, $keys);
        $this->assertSame(count($keys), count(array_unique($keys)), 'مدفوعةٌ عُرضت في دفعتين');
        $this->assertEqualsCanonicalizing($made, $keys, 'مدفوعةٌ لم تبلغها أيُّ دفعة');
        $this->assertSame(3, $pages);
        $this->assertSame(8, PaymobSettlements::waiting($this->shop->id));
        // والدفعةُ الأولى هي الأقدم
        $this->assertSame(array_slice($made, 0, 3), array_column(PaymobSettlements::candidates($this->shop->id, 3), 'key'));
    }

    public function test_a_payment_settled_between_batches_pushes_none_out_of_view(): void
    {
        $links = $this->links(6);
        $first = PaymobSettlements::candidatesPage($this->shop->id, null, 3);
        $this->assertNotNull($first['next']);

        // يسوّي التاجرُ مدفوعةً من الدفعة الأولى قبل أن يطلب الثانية
        $this->assertSame(PaymobSettlements::POSTED, $this->settle([$links[0]], 0, (float) $links[0]->amount)->status);

        $second = PaymobSettlements::candidatesPage($this->shop->id, $first['next'], 3);
        $this->assertSame(array_map(fn ($l) => $this->key($l), array_slice($links, 3)), array_column($second['rows'], 'key'));
        $this->assertNull($second['next']);
        $this->assertSame(5, PaymobSettlements::waiting($this->shop->id));
        // والمسوّاةُ لا تعود إلى أيّ دفعة، ولا تُسوّى مرّةً ثانية
        $this->assertNotContains($this->key($links[0]), $this->walk(2)[0]);
        $this->expectException(\RuntimeException::class);
        $this->settle([$links[0]], 0, (float) $links[0]->amount);
    }

    public function test_the_next_batch_is_served_to_its_own_shop_and_its_finance_alone(): void
    {
        $mine = array_map(fn ($l) => $this->key($l), $this->links(4));
        $cursor = PaymobSettlements::candidatesPage($this->shop->id, null, 2)['next'];

        // الشاشةُ تعرض الأولى وتقول كم ينتظر
        $props = $this->get(route('admin.finance.paymentLinks'))->assertOk()->viewData('page')['props'];
        $this->assertSame($mine, array_column($props['payouts'], 'key'));
        $this->assertNull($props['payouts_next']);
        $this->assertSame(4, $props['payouts_waiting']);

        // والتالية من المؤشّر
        $this->getJson(route('admin.finance.paymobSettlements.candidates', ['after' => $cursor]))->assertOk()
            ->assertJsonPath('next', null)
            ->assertJsonPath('payouts.*.key', array_slice($mine, 2));

        // ومتجرٌ آخر بالمؤشّر نفسه لا يرى منها شيئًا
        [$other, $otherOwner] = $this->openShop('batches-other', 'batches-other@abaadapp.om');
        $this->actingAs($otherOwner)->getJson(route('admin.finance.paymobSettlements.candidates', ['after' => $cursor]))->assertOk()
            ->assertJsonPath('payouts', [])->assertJsonPath('next', null);

        // ومن لا يملك «المالية» لا يطلبها
        $seller = User::create([
            'business_id' => $this->shop->id, 'name' => 'بائع', 'email' => 'seller@batches.om', 'password' => bcrypt('x'),
            'role' => 'sales', 'status' => 'نشط', 'permissions' => ['dashboard', 'orders', 'customers'],
        ]);
        $this->actingAs($seller)->getJson(route('admin.finance.paymobSettlements.candidates'))->assertForbidden();
    }

    public function test_one_payout_may_name_more_than_three_hundred_payments(): void
    {
        $keys = fn (int $n) => array_map(fn ($i) => 'link:'.(900000 + $i), range(1, $n));
        $error = fn (int $n) => $this->post(route('admin.finance.paymobSettlements.store'), [
            'items' => $keys($n), 'fee' => 0, 'net' => 1, 'settled_on' => '2027-02-10',
        ])->assertSessionHasErrors('items')->getSession()->get('errors')->first('items');

        // ٣٠١ اسمًا تبلغ الفحصَ نفسه (فيُردّ لأنّها ليست مدفوعات) — لا سقفَ العدد
        $this->assertStringNotContainsString('300', $error(301));
        // وما فوق MAX_ITEMS حارسُ طلبٍ يُردّ قبل أيّ قراءة
        $this->assertStringContainsString((string) PaymobSettlements::MAX_ITEMS, $error(PaymobSettlements::MAX_ITEMS + 1));
        $this->assertSame(0, PaymobSettlement::count());
    }
}
