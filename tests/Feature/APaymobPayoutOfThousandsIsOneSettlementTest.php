<?php

namespace Tests\Feature;

use App\Exceptions\ClosedPeriod;
use App\Models\JournalEntry;
use App\Models\PaymobSettlement;
use App\Models\PaymobSettlementItem;
use App\Models\Setting;
use App\Models\User;
use App\Support\Ledger;
use App\Support\PaymobSettlements;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Support\PaymobPayouts;
use Tests\TestCase;

/**
 * تحويلُ Paymob بآلاف المدفوعات تسويةٌ واحدة وقيدٌ واحد — بلا سقفٍ على عددها.
 *
 * ═══ ما يُحرس ═══
 *
 * لا يُرسَل التحويلُ الكبير في طلبٍ واحد: يُفتح بأرقام كشفه (`openDraft`)،
 * وتُضاف مدفوعاتُه دفعاتٍ (`CHUNK`) كلٌّ في معاملةٍ قصيرة، ثمّ يُرحَّل مرّةً
 * (`postDraft`) بمجاميعَ من القاعدة:
 *
 *   - ٢١٠١ مدفوعةٍ حقيقيّة: تسويةٌ واحدة، قيدٌ واحد، وكلُّ مدفوعةٍ فيها مرّة —
 *     والترحيلُ باستعلاماتٍ معدودة لا بحلقةٍ على آلافها.
 *   - المدفوعةُ في تحويلٍ لا تدخل غيرَه، والدفعةُ التي تسمّي إحداها تُردّ كلُّها.
 *   - ما تغيّر بعد اختياره، أو مجموعٌ لا يساوي الصافيَ والرسوم: لا ترحيلَ لجزء —
 *     يبقى مسوّدةً بسببه. والفترةُ المقفلة تردّه كلَّه.
 *   - التسويةُ القديمة (`legacy`) تبقى تُعلّق ما ربما حملته.
 *   - لا يبلغ متجرٌ مسوّدةَ غيره ولا مدفوعاتِه، ولا يفتحها من لا يملك «المالية».
 */
class APaymobPayoutOfThousandsIsOneSettlementTest extends TestCase
{
    use PaymobPayouts;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2027-02-10 10:00:00');
        app()->setLocale('ar');
        config([
            'storefront.ribbon_english_checkout_businesses' => [],
            'storefront.ribbon_gift_card_product_businesses' => [],
            'storefront.ribbon_free_gift_card_message_businesses' => [],
        ]);
        [$this->shop, $this->owner, $this->rose] = $this->openShop('thousands', 'thousands@abaadapp.om');
        $this->actingAs($this->owner);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** تحويلٌ يُفتح بعد ساعةٍ من آخر دفع، ويُضاف إليه كلُّ ما ينتظر دفعةً بعد دفعة */
    private function payout(float $fee, float $net): array
    {
        Carbon::setTestNow(now()->addHour());
        $draft = PaymobSettlements::openDraft($this->shop->id, ['fee' => $fee, 'net' => $net, 'settled_on' => now()->toDateString()], $this->owner->id);
        $rounds = 0;
        do {
            $step = PaymobSettlements::addWaitingToDraft($this->shop->id, $draft->id, now()->toDateString());
            $rounds++;
        } while ($step['remaining'] > 0 && $rounds < 50);

        return [$step['draft'], $rounds];
    }

    private function entries(): int
    {
        return JournalEntry::where('business_id', $this->shop->id)->count();
    }

    public function test_more_than_300_waiting_are_all_reached_batch_after_batch_without_loss_or_repeat(): void
    {
        $made = $this->manyPaidLinks(650);

        [$keys, $after, $pages] = [[], null, 0];
        do {
            $page = PaymobSettlements::candidatesPage($this->shop->id, $after);
            $keys = [...$keys, ...array_column($page['rows'], 'key')];
            $after = $page['next'];
            $pages++;
        } while ($after !== null && $pages < 20);

        $this->assertSame(3, $pages);
        $this->assertCount(650, array_unique($keys), 'مدفوعةٌ عُرضت مرّتين أو لم تُعرض');
        $this->assertEqualsCanonicalizing($made, $keys);
        $this->assertSame(650, PaymobSettlements::waiting($this->shop->id));

        // والشاشةُ تعرض الأولى وتقول كم ينتظر، والتاليةُ من مؤشّرها
        $props = $this->get(route('admin.finance.paymentLinks'))->assertOk()->viewData('page')['props'];
        $this->assertCount(PaymobSettlements::BATCH, $props['payouts']);
        $this->assertSame(650, $props['payouts_waiting']);
        $next = $this->getJson(route('admin.finance.paymobSettlements.candidates', ['after' => $props['payouts_next']]))->assertOk()->json();
        $this->assertCount(PaymobSettlements::BATCH, $next['payouts']);
        $this->assertSame([], array_intersect(array_column($props['payouts'], 'key'), array_column($next['payouts'], 'key')));
    }

    public function test_a_payout_of_2101_payments_is_one_settlement_one_entry_and_posts_once(): void
    {
        $this->manyPaidLinks(2101);
        $before = $this->entries();
        $this->assertSame(2101.0, $this->clearing());

        [$draft, $rounds] = $this->payout(fee: 52.525, net: 2048.475);
        $this->assertSame(5, $rounds, 'أربعُ دفعاتٍ من ٥٠٠ وخامسةٌ بما بقي');
        $this->assertSame(2101, $draft->items()->count());
        $this->assertSame(2101.0, (float) $draft->gross);
        $this->assertSame(0, PaymobSettlements::waiting($this->shop->id));
        $this->assertSame($before, $this->entries(), 'كُتب قيدٌ قبل الترحيل');

        // الترحيلُ يقرأ مجاميعَه من القاعدة: استعلاماتٌ معدودة مهما بلغت المدفوعات
        DB::flushQueryLog();
        DB::enableQueryLog();
        $settlement = PaymobSettlements::postDraft($this->shop->id, $draft->id, $this->owner->id);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(PaymobSettlements::POSTED, $settlement->status);
        $this->assertLessThan(60, $queries, 'الترحيلُ يدور على المدفوعات واحدةً واحدة');
        $this->assertSame($before + 1, $this->entries(), 'تحويلٌ واحد = قيدٌ واحد');
        $this->assertSame([0.0, 2048.475, 52.525], [$this->clearing(), $this->bank(), $this->fees()]);
        $this->assertTrue(Ledger::trialBalance($this->shop->id)['balanced']);
        $this->assertSame(1, PaymobSettlement::count());
        $this->assertSame(2101, PaymobSettlementItem::where('paymob_settlement_id', $settlement->id)->distinct()->count('customer_payment_link_id'));

        // ويُرحَّل مرّةً: الثاني يُردّ ولا يكتب
        try {
            PaymobSettlements::postDraft($this->shop->id, $draft->id, $this->owner->id);
            $this->fail('رُحّل التحويلُ مرّتين');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('ليس مسوّدة', $e->getMessage());
        }
        $this->assertSame($before + 1, $this->entries());
    }

    public function test_the_screen_way_opens_adds_in_batches_and_posts_once(): void
    {
        $made = $this->manyPaidLinks(1200, 0.5);
        Carbon::setTestNow(now()->addHour());

        $id = $this->postJson(route('admin.finance.paymobSettlements.drafts.store'), [
            'fee' => 15, 'net' => 585, 'settled_on' => now()->toDateString(), 'reference' => 'PMB-1200',
        ])->assertCreated()->json('id');
        foreach (array_chunk($made, PaymobSettlements::CHUNK) as $batch) {
            $this->postJson(route('admin.finance.paymobSettlements.items.store', $id), ['items' => $batch])->assertOk();
        }
        // دفعةٌ أُعيد إرسالُها بعد انقطاع لا تكرّر شيئًا
        $this->postJson(route('admin.finance.paymobSettlements.items.store', $id), ['items' => array_slice($made, 0, 500)])
            ->assertOk()->assertJsonPath('count', 1200)->assertJsonPath('gross', 600);
        // والطلبُ الواحد دفعةٌ واحدة — لا آلافَ في طلب
        $this->postJson(route('admin.finance.paymobSettlements.items.store', $id), ['items' => array_map(fn ($i) => 'link:'.$i, range(1, PaymobSettlements::CHUNK + 1))])
            ->assertUnprocessable();

        $before = $this->entries();
        $this->post(route('admin.finance.paymobSettlements.post', $id))->assertSessionHasNoErrors()->assertSessionHas('toast.type', 'success');

        $this->assertSame(PaymobSettlements::POSTED, PaymobSettlement::findOrFail($id)->status);
        $this->assertSame($before + 1, $this->entries());
        $this->assertSame([0.0, 585.0, 15.0], [$this->clearing(), $this->bank(), $this->fees()]);
    }

    public function test_a_payment_in_one_payout_cannot_enter_another(): void
    {
        $keys = $this->manyPaidLinks(5);
        [$first] = $this->payout(fee: 0, net: 5);
        $second = PaymobSettlements::openDraft($this->shop->id, ['fee' => 0, 'net' => 1, 'settled_on' => now()->toDateString()], $this->owner->id);

        // دفعةٌ تسمّي مدفوعةً محجوزة تُردّ كلُّها — لا يبقى منها شيء
        try {
            PaymobSettlements::addToDraft($this->shop->id, $second->id, [$keys[0]]);
            $this->fail('دخلت مدفوعةٌ تحويلين');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('سبقت تسويتُها', $e->getMessage());
        }
        $this->assertSame(0, $second->items()->count());
        $this->assertSame(0, PaymobSettlements::addWaitingToDraft($this->shop->id, $second->id, now()->toDateString())['added']);

        // ولا بالطريق المباشر
        try {
            PaymobSettlements::record($this->shop->id, ['items' => [$keys[0]], 'fee' => 0, 'net' => 1, 'settled_on' => now()->toDateString()], null);
            $this->fail('سُجّلت مدفوعةٌ محجوزة في تسويةٍ ثانية');
        } catch (RuntimeException) {
        }
        $this->assertSame(1, PaymobSettlementItem::where('customer_payment_link_id', (int) explode(':', $keys[0])[1])->count());

        // وإلغاءُ الأوّل قبل ترحيله يُعيدها لتُختار
        PaymobSettlements::cancel($this->shop->id, $first->id);
        $this->assertSame(1, PaymobSettlements::addToDraft($this->shop->id, $second->id, [$keys[0]])->items()->count());
    }

    public function test_a_payment_that_changes_after_it_was_picked_keeps_the_whole_payout_a_draft(): void
    {
        $keys = $this->manyPaidLinks(600);
        [$draft] = $this->payout(fee: 0, net: 600);
        $before = $this->entries();

        // بعد أن اختيرت: أُلغي تحصيلُ رابطٍ منها
        $payment = DB::table('customer_payment_links')->where('id', (int) explode(':', $keys[10])[1])->value('customer_payment_id');
        DB::table('customer_payments')->where('id', $payment)->update(['cancelled_at' => now()]);

        $held = PaymobSettlements::postDraft($this->shop->id, $draft->id, $this->owner->id);
        $this->assertSame(PaymobSettlements::DRAFT, $held->status);
        $this->assertStringContainsString('التحصيل ملغى', (string) $held->hold_reason);
        $this->assertSame($before, $this->entries(), 'رُحّل جزءٌ من تحويلٍ فيه ما لم يتأكّد');
        $this->assertSame(600.0, $this->clearing());

        // يُخرج صاحبُه ما لم يتأكّد: الباقي لا يساوي كشفَه — فلا ترحيلَ أيضًا
        $this->assertSame(599, PaymobSettlements::dropUnsureFromDraft($this->shop->id, $draft->id)->items()->count());
        $still = PaymobSettlements::postDraft($this->shop->id, $draft->id, $this->owner->id);
        $this->assertSame(PaymobSettlements::DRAFT, $still->status);
        $this->assertStringContainsString('لا يساوي', (string) $still->hold_reason);
        $this->assertSame($before, $this->entries());
    }

    public function test_a_wrong_fee_or_net_posts_nothing_and_cancelling_releases_everything(): void
    {
        $this->manyPaidLinks(700);
        [$draft] = $this->payout(fee: 10, net: 600);
        $before = $this->entries();

        $held = PaymobSettlements::postDraft($this->shop->id, $draft->id, $this->owner->id);
        $this->assertSame(PaymobSettlements::DRAFT, $held->status);
        $this->assertStringContainsString('لا يساوي', (string) $held->hold_reason);
        $this->assertSame([$before, 700.0, 0.0], [$this->entries(), $this->clearing(), $this->fees()]);

        PaymobSettlements::cancel($this->shop->id, $draft->id);
        $this->assertSame(PaymobSettlements::CANCELLED, $draft->fresh()->status);
        $this->assertSame(700, PaymobSettlements::waiting($this->shop->id), 'لم تعد المدفوعاتُ لتنتظر');
        $this->assertSame($before, $this->entries());
    }

    public function test_a_failing_batch_leaves_nothing_and_a_closed_period_posts_nothing(): void
    {
        $keys = $this->manyPaidLinks(900);
        Carbon::setTestNow(now()->addHour());
        $draft = PaymobSettlements::openDraft($this->shop->id, ['fee' => 0, 'net' => 900, 'settled_on' => now()->toDateString()], $this->owner->id);
        PaymobSettlements::addToDraft($this->shop->id, $draft->id, array_slice($keys, 0, 500));

        // دفعةٌ فيها اسمٌ ليس من مدفوعات المتجر تُردّ كلُّها
        try {
            PaymobSettlements::addToDraft($this->shop->id, $draft->id, [...array_slice($keys, 500, 399), 'link:999999']);
            $this->fail('قُبلت دفعةٌ فيها ما ليس مدفوعة');
        } catch (RuntimeException) {
        }
        $this->assertSame(500, $draft->items()->count(), 'بقي من الدفعة المردودة شيء');
        $this->assertSame(900, PaymobSettlements::addToDraft($this->shop->id, $draft->id, array_slice($keys, 500))->items()->count());

        // ثمّ أُقفلت الدفاترُ حتّى يوم التحويل قبل ترحيله: لا يُرحَّل شيء، وتبقى المسوّدةُ كاملة
        Setting::create(['business_id' => $this->shop->id, 'key' => 'books_closed_through', 'value' => now()->toDateString()]);
        $before = $this->entries();
        try {
            PaymobSettlements::postDraft($this->shop->id, $draft->id, $this->owner->id);
            $this->fail('رُحّل تحويلٌ في فترةٍ مقفلة');
        } catch (ClosedPeriod) {
        }
        $this->assertSame([PaymobSettlements::DRAFT, 900], [$draft->fresh()->status, $draft->items()->count()]);
        $this->assertSame([$before, 900.0], [$this->entries(), $this->clearing()]);

        // ولا يُفتح تحويلٌ بتاريخٍ مقفل أصلًا
        $this->expectException(ClosedPeriod::class);
        PaymobSettlements::openDraft($this->shop->id, ['fee' => 0, 'net' => 1, 'settled_on' => now()->toDateString()], $this->owner->id);
    }

    public function test_an_old_payout_that_names_nothing_still_holds_what_it_may_have_carried(): void
    {
        $this->manyPaidLinks(3);
        Carbon::setTestNow(now()->addMinute());
        $legacy = $this->legacyPayout(gross: 3, fee: 0);

        [$draft] = $this->payout(fee: 0, net: 3);
        $held = PaymobSettlements::postDraft($this->shop->id, $draft->id, $this->owner->id);

        $this->assertSame(PaymobSettlements::DRAFT, $held->status);
        $this->assertStringContainsString('ربما حملها تحويلٌ سُجّل قبل التحديث', (string) $held->hold_reason);
        $this->assertSame(PaymobSettlements::LEGACY, $legacy->fresh()->status);
        $this->assertSame(3.0, PaymobSettlements::legacyPanel($legacy->fresh())['open']);
    }

    public function test_shops_never_reach_each_others_payouts_and_finance_is_needed(): void
    {
        $mine = $this->manyPaidLinks(3);
        $draft = PaymobSettlements::openDraft($this->shop->id, ['fee' => 0, 'net' => 3, 'settled_on' => now()->toDateString()], $this->owner->id);

        [$other, $otherOwner] = $this->openShop('thousands-other', 'thousands-other@abaadapp.om');
        $this->actingAs($otherOwner);
        $this->postJson(route('admin.finance.paymobSettlements.items.store', $draft->id), ['items' => [$mine[0]]])->assertNotFound();
        $this->postJson(route('admin.finance.paymobSettlements.waiting', $draft->id), ['through' => now()->toDateString()])->assertNotFound();
        $this->post(route('admin.finance.paymobSettlements.post', $draft->id))->assertNotFound();

        // ومسوّدتُه لا تبلغ مدفوعاتي — لا باسمها ولا بـ«كلِّ ما ينتظر»
        $theirs = PaymobSettlements::openDraft($other->id, ['fee' => 0, 'net' => 1, 'settled_on' => now()->toDateString()], $otherOwner->id);
        try {
            PaymobSettlements::addToDraft($other->id, $theirs->id, [$mine[0]]);
            $this->fail('حجز متجرٌ مدفوعةَ غيره');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('ليست من مدفوعات Paymob المقبوضة في متجرك', $e->getMessage());
        }
        $this->assertSame(0, PaymobSettlements::addWaitingToDraft($other->id, $theirs->id, now()->toDateString())['added']);
        $this->assertSame(0, $draft->items()->count());

        $seller = User::create([
            'business_id' => $this->shop->id, 'name' => 'بائع', 'email' => 'seller@thousands.om', 'password' => bcrypt('x'),
            'role' => 'sales', 'status' => 'نشط', 'permissions' => ['dashboard', 'orders', 'customers'],
        ]);
        $this->actingAs($seller)->postJson(route('admin.finance.paymobSettlements.drafts.store'), ['fee' => 0, 'net' => 1, 'settled_on' => now()->toDateString()])
            ->assertForbidden();
    }
}
