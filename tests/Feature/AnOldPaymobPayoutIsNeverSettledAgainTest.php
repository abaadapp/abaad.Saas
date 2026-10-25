<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\JournalEntry;
use App\Models\PaymobSettlement;
use App\Models\PaymobSettlementItem;
use App\Support\PaymobSettlements;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use RuntimeException;
use Tests\Support\PaymobPayouts;
use Tests\TestCase;

/**
 * تسويةُ Paymob التي سُجّلت قبل #168 لا تسمّي مدفوعاتِها — وغيابُ اسمِ مدفوعةٍ
 * منها لا يعني أنّها لم تُسوَّ.
 *
 * ═══ الخطرُ الذي يُحرس ═══
 *
 * كتب الإصدارُ السابق التسويةَ ثلاثةَ أرقام: إجماليًّا ورسومًا وصافيًا، وقيدًا
 * يُدين البنكَ من التسوية (1360). فرابطُ دفعٍ حمله ذاك التحويل خرج مالُه من
 * التسوية — ولا سطرَ في `paymob_settlement_items` يقول ذلك. فإن عُرض «ينتظر»
 * وسُوّي ثانيةً، خرج من التسوية مرّتين: مالُ مدفوعةٍ أخرى ما زالت تنتظر يُستهلك
 * عنه، وتلك تبقى معلّقةً بلا سبب ظاهر.
 *
 * ═══ والدليلُ لا التخمين ═══
 *
 * لا يُوزَّع إجماليُّ التسوية القديمة على مدفوعاتٍ بتشابه المبلغ أو التاريخ.
 * الدليلُ الوحيدُ الذي لا يخطئ هو الزمن: تسويةٌ سُجّلت في لحظةٍ لا تحمل مدفوعةً
 * دُفعت بعدها — الحدُّ يومها كان رصيدَ التسوية، ولا يحوي إلّا ما دُفع قبلها.
 */
class AnOldPaymobPayoutIsNeverSettledAgainTest extends TestCase
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

        [$this->shop, $this->owner, $this->rose] = $this->openShop('wardshop', 'owner@ward.om');
        $this->actingAs($this->owner);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** رابطٌ دُفع، ثمّ تسويةٌ قديمةٌ حملته، ثمّ رابطٌ وبيعةٌ بعدها */
    private function history(): array
    {
        $carried = $this->paidLink(30);
        Carbon::setTestNow('2027-02-11 09:00:00');
        $legacy = $this->legacyPayout(30, 0.9);
        Carbon::setTestNow('2027-02-12 09:00:00');
        $later = $this->paidLink(20);
        $sale = $this->newSale();

        return [$carried, $legacy, $later, $sale];
    }

    public function test_a_payment_an_old_payout_may_have_carried_is_not_offered_as_waiting(): void
    {
        [$carried, , $later, $sale] = $this->history();
        $this->assertSame(40.0, $this->clearing(), 'تهيئة: خرج مالُ الأوّل بالتسوية القديمة');

        $booked = collect(PaymobSettlements::candidates($this->shop->id))->pluck('booked', 'key');

        $this->assertSame(PaymobSettlements::UNSURE, $booked[$this->key($carried)], 'عُرضت مدفوعةٌ ربما سُوّيت قبل التحديث كأنّها تنتظر');
        $this->assertSame(PaymobSettlements::IN_CLEARING, $booked[$this->key($later)], 'دُفعت بعد التسوية القديمة — لا يمكن أن تكون فيها');
        $this->assertSame(PaymobSettlements::IN_CLEARING, $booked[$this->key($sale)]);
    }

    /**
     * والخطرُ الصامت: وحدها تمرّ تحت حدّ «ما ينتظر» (٣٠ من ٤٠)، فيخرج مالُها من
     * التسوية ثانيةً، وتبقى المدفوعتان اللتان تنتظران فعلًا (٤٠) على عشرة.
     */
    public function test_settling_it_again_is_held_and_writes_nothing(): void
    {
        [$carried] = $this->history();
        $state = [$this->bank(), $this->clearing(), $this->fees(), JournalEntry::count()];

        $settlement = $this->settle([$carried], 0.9, 29.1);

        $this->assertSame(PaymobSettlements::HELD, $settlement->status, 'رُحّلت مدفوعةٌ ربما سُوّيت قبل التحديث');
        $this->assertNull($settlement->journal_entry_id);
        $this->assertSame($state, [$this->bank(), $this->clearing(), $this->fees(), JournalEntry::count()]);
    }

    /** رابطان قبل تسويةٍ قديمةٍ بثلاثين حملت أحدَهما — والدفترُ لا يقول أيَّهما */
    private function twoBefore(): array
    {
        $a = $this->paidLink(30);
        $b = $this->paidLink(20);
        Carbon::setTestNow('2027-02-11 09:00:00');
        $legacy = $this->legacyPayout(30, 0.9);
        Carbon::setTestNow('2027-02-12 09:00:00');

        return [$a, $b, $legacy];
    }

    private function booked(): array
    {
        return collect(PaymobSettlements::candidates($this->shop->id))->pluck('booked', 'key')->all();
    }

    /* ═══════════ ١ · ما كتبه كودٌ لا يسمّي المدفوعات ═══════════ */

    public function test_a_row_that_does_not_say_its_status_reads_as_legacy_and_new_code_always_says_it(): void
    {
        [$a, , $legacy] = $this->twoBefore();
        $this->assertSame(PaymobSettlements::LEGACY, $legacy->status, 'صفٌّ كتبه الإصدارُ السابق قُرئ كأنّه يسمّي مدفوعاتِه');

        $sale = $this->newSale();
        $this->assertSame(PaymobSettlements::POSTED, $this->settle([$sale], 0.6, 19.4)->status);
        $this->assertSame(PaymobSettlements::HELD, $this->settle([$a], 0.9, 29.1)->status);
        $this->assertSame(1, PaymobSettlement::where('status', PaymobSettlements::LEGACY)->count());
    }

    public function test_an_old_bank_sale_paid_before_an_old_payout_is_reviewed_too(): void
    {
        $old = $this->oldSale(20);
        Carbon::setTestNow('2027-02-11 09:00:00');
        $this->paidLink(30);
        $this->legacyPayout(30, 0.9);

        $this->assertSame(PaymobSettlements::UNSURE, $this->booked()[$this->key($old)], 'بيعةٌ في البنك ربما حملها تحويلٌ قديم عُرضت كأنّ مالها في البنك بلا شكّ');
    }

    /* ═══════════ ٢ · صاحبُ المتجر يحدّد ما حمله — بدليل، ولا قيد ═══════════ */

    public function test_the_owner_names_what_the_old_payout_carried_and_arithmetic_releases_the_rest(): void
    {
        [$a, $b, $legacy] = $this->twoBefore();
        $this->assertSame([PaymobSettlements::UNSURE, PaymobSettlements::UNSURE], [$this->booked()[$this->key($a)], $this->booked()[$this->key($b)]]);
        $state = [$this->bank(), $this->clearing(), $this->fees(), JournalEntry::count()];

        $this->post(route('admin.finance.paymobSettlements.attribute', $legacy->id), ['items' => [$this->key($a)], 'note' => 'كشف Paymob 2027-02-11'])
            ->assertSessionHasNoErrors()->assertSessionHas('toast.type', 'success');

        // لا قيد، ولا رصيدٌ يتحرّك
        $this->assertSame($state, [$this->bank(), $this->clearing(), $this->fees(), JournalEntry::count()]);
        $item = PaymobSettlementItem::sole();
        $this->assertSame([PaymobSettlements::IN_LEGACY, $this->owner->id, 'كشف Paymob 2027-02-11', $legacy->id], [$item->booked, $item->decided_by, $item->reason, $item->paymob_settlement_id]);
        $this->assertTrue(ActivityLog::where('subject_type', 'paymob_settlement')->where('subject_id', $legacy->id)->exists(), 'لا أثرَ للقرار في السجلّ');

        // حُدّد إجماليُّه كلُّه: لم يبق منه ما يحمل الثاني — يعود ينتظر، والأوّلُ محجوز
        $this->assertSame([$this->key($b) => PaymobSettlements::IN_CLEARING], $this->booked());
        $this->assertSame(PaymobSettlements::POSTED, $this->settle([$b], 0.6, 19.4)->status);
        // البنكُ = صافي التحويل القديم (٢٩٫١) + صافي هذا (١٩٫٤) — كلُّ ريالٍ مرّةً واحدة
        $this->assertSame([0.0, 48.5], [$this->clearing(), $this->bank()]);
    }

    public function test_naming_needs_its_evidence_and_never_overreaches(): void
    {
        [$a, $b, $legacy] = $this->twoBefore();
        $after = $this->paidLink(10);
        $sale = $this->newSale();
        $held = $this->settle([$a], 0.9, 29.1);   // معلّقةٌ تحجز الأوّل
        [$other] = $this->openShop('neighbour', 'owner@neighbour.om');
        $this->actingAs($this->owner);
        $theirs = $this->newSale(1, $other);
        $posted = $this->settle([$sale], 0.6, 19.4);

        $refused = [
            'بلا مستند' => [$legacy->id, [$b], 'ok'],
            'دُفعت بعده' => [$legacy->id, [$after], 'كشف Paymob'],
            'أكثرُ ممّا بقي' => [$legacy->id, [$b, $this->paidLinkBefore($legacy)], 'كشف Paymob'],
            'حجزتها معلّقة' => [$legacy->id, [$a], 'كشف Paymob'],
            'من متجرٍ آخر' => [$legacy->id, [$theirs], 'كشف Paymob'],
            'ليست قديمة' => [$posted->id, [$b], 'كشف Paymob'],
        ];
        $items = PaymobSettlementItem::count();
        foreach ($refused as $why => [$target, $payments, $note]) {
            try {
                PaymobSettlements::attribute($this->shop->id, $target, array_map(fn ($p) => $this->key($p), $payments), $note, $this->owner->id);
                $this->fail('قُبل تحديدٌ: '.$why);
            } catch (RuntimeException) {
            }
        }

        $this->assertSame($items, PaymobSettlementItem::count(), 'كُتب سطرٌ من تحديدٍ مردود');
        $this->assertSame(PaymobSettlements::HELD, $held->fresh()->status);
    }

    /** رابطٌ دفعُه قبل تلك التسوية — يُكتب بتاريخٍ قبلها */
    private function paidLinkBefore(PaymobSettlement $legacy)
    {
        $now = Carbon::now();
        Carbon::setTestNow(Carbon::parse($legacy->created_at)->subHour());
        $link = $this->paidLink(15);
        Carbon::setTestNow($now);

        return $link;
    }

    public function test_a_wrong_naming_is_undone_and_both_payments_are_reviewed_again(): void
    {
        [$a, $b, $legacy] = $this->twoBefore();
        PaymobSettlements::attribute($this->shop->id, $legacy->id, [$this->key($a)], 'كشف Paymob 2027-02-11', $this->owner->id);
        $this->assertSame(PaymobSettlements::IN_CLEARING, $this->booked()[$this->key($b)]);

        $this->post(route('admin.finance.paymobSettlementItems.remove', PaymobSettlementItem::sole()->id))->assertSessionHas('toast.type', 'success');

        $this->assertSame(0, PaymobSettlementItem::count());
        $this->assertSame([PaymobSettlements::UNSURE, PaymobSettlements::UNSURE], [$this->booked()[$this->key($a)], $this->booked()[$this->key($b)]]);
        $this->assertTrue(ActivityLog::where('subject_id', $legacy->id)->where('description', 'like', '%تراجع%')->exists());

        // وسطرُ تسويةٍ جديدة لا يُترك من هنا
        $sale = $this->newSale();
        $this->settle([$sale], 0.6, 19.4);
        $line = PaymobSettlementItem::where('booked', PaymobSettlements::IN_CLEARING)->sole();
        $this->post(route('admin.finance.paymobSettlementItems.remove', $line->id))->assertSessionHas('toast.type', 'danger');
        $this->assertTrue(PaymobSettlementItem::whereKey($line->id)->exists());
    }

    /* ═══════════ ٣ · بعد تراجعٍ إلى كودٍ أقدم ═══════════ */

    /**
     * رُجع إلى إصدارٍ يكتب التسويةَ بمبلغٍ واحد، فسجّل تحويلًا، ثمّ عاد #168: ما
     * كتبه ذاك الإصدارُ قديمٌ (`legacy`) — وما قبله للمراجعة، وما سوّاه #168 محجوزٌ كما كان.
     */
    public function test_a_payout_written_by_older_code_after_a_rollback_is_legacy_too(): void
    {
        $settled = $this->newSale();
        $this->settle([$settled], 0.6, 19.4);
        $before = $this->paidLink(30);
        Carbon::setTestNow('2027-02-11 09:00:00');
        $this->legacyPayout(30, 0.9);   // كتبه الإصدارُ الأقدم في نافذة التراجع
        Carbon::setTestNow('2027-02-12 09:00:00');
        $later = $this->newSale();

        $this->assertEquals([$this->key($before) => PaymobSettlements::UNSURE, $this->key($later) => PaymobSettlements::IN_CLEARING], $this->booked());
    }

    public function test_another_shops_old_payout_casts_no_doubt_here(): void
    {
        $ours = $this->paidLink(30);
        [$other] = $this->openShop('neighbour', 'owner@neighbour.om');
        $this->actingAs($this->owner);
        Carbon::setTestNow('2027-02-11 09:00:00');
        DB::table('paymob_settlements')->insert(['business_id' => $other->id, 'gross' => 30, 'fee' => 0, 'net' => 30, 'settled_on' => now()->toDateString(), 'created_at' => now(), 'updated_at' => now()]);

        $this->assertSame(PaymobSettlements::IN_CLEARING, $this->booked()[$this->key($ours)]);
    }

    /**
     * وكودٌ لا يقول حالَ التسوية لا يكتبها بالنموذج — الحارسُ نفسُه الذي يُبقي
     * الإصدارَ الأقدم (#170) عن التسوية بمبلغٍ واحد إن رُجع إليه. والاستعادةُ تكتب
     * بلا نموذج فتمرّ، ويُقرأ صفُّها قديمًا.
     */
    public function test_code_that_does_not_say_a_settlements_status_cannot_write_one(): void
    {
        try {
            PaymobSettlement::create(['business_id' => $this->shop->id, 'gross' => 1, 'fee' => 0, 'net' => 1, 'settled_on' => now()->toDateString()]);
            $this->fail('كُتبت تسويةٌ لا تقول حالَها');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('متوقّف في هذا الإصدار', $e->getMessage());
        }
        $this->assertSame(0, PaymobSettlement::count());

        DB::table('paymob_settlements')->insert(['business_id' => $this->shop->id, 'gross' => 1, 'fee' => 0, 'net' => 1, 'settled_on' => now()->toDateString(), 'created_at' => now(), 'updated_at' => now()]);
        $this->assertSame(PaymobSettlements::LEGACY, PaymobSettlement::sole()->status);
    }

    /* ═══════════ ٤ · الإيقافُ المؤقّت ═══════════ */

    public function test_while_paused_nothing_is_settled_rechecked_or_named_but_a_hold_can_be_cancelled(): void
    {
        [$a, $b, $legacy] = $this->twoBefore();
        $held = $this->settle([$a], 0.9, 29.1);
        config(['services.paymob.settlements_paused' => true]);
        $state = [$this->bank(), $this->clearing(), JournalEntry::count(), PaymobSettlementItem::count(), PaymobSettlement::count()];

        foreach ([
            fn () => $this->settle([$b], 0.6, 19.4),
            fn () => PaymobSettlements::retry($this->shop->id, $held->id),
            fn () => PaymobSettlements::attribute($this->shop->id, $legacy->id, [$this->key($b)], 'كشف Paymob', $this->owner->id),
        ] as $act) {
            try {
                $act();
                $this->fail('سُجّل شيءٌ والتسجيلُ موقوف');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('موقوف', $e->getMessage());
            }
        }
        $this->assertSame($state, [$this->bank(), $this->clearing(), JournalEntry::count(), PaymobSettlementItem::count(), PaymobSettlement::count()]);

        $this->get(route('admin.finance.paymentLinks'))->assertInertia(fn (AssertableInertia $page) => $page->where('payouts_paused', true));
        PaymobSettlements::cancel($this->shop->id, $held->id);
        $this->assertSame(PaymobSettlements::CANCELLED, $held->fresh()->status);
    }

    /* ═══════════ ٥ · الشاشة ═══════════ */

    public function test_the_screen_shows_the_old_payout_and_what_it_may_have_carried(): void
    {
        [$a, $b, $legacy] = $this->twoBefore();
        PaymobSettlements::attribute($this->shop->id, $legacy->id, [$this->key($b)], 'كشف Paymob 11 فبراير', $this->owner->id);

        $this->get(route('admin.finance.paymentLinks'))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('settlements.0.status', 'legacy')
            ->where('settlements.0.open', 10)
            ->where('settlements.0.attributed.0.reason', 'كشف Paymob 11 فبراير')
            ->where('settlements.0.attributed.0.by', $this->owner->name)
            ->where('payouts', fn ($rows) => collect($rows)->firstWhere('key', $this->key($a))['legacy'] === [$legacy->id]
                && collect($rows)->firstWhere('key', $this->key($a))['booked'] === 'unknown'));
    }
}
