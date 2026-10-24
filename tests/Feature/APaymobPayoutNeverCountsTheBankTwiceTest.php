<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\CustomerPaymentLink;
use App\Models\JournalEntry;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentGateway;
use App\Models\PaymobSettlement;
use App\Models\Product;
use App\Models\Setting;
use App\Models\StorePaymentIntent;
use App\Models\User;
use App\Support\Bank;
use App\Support\Books;
use App\Support\Ledger;
use App\Support\MarketingSettings;
use App\Support\OrderCorrection;
use App\Support\PaymentLinks;
use App\Support\PaymobSettlements;
use App\Support\Store\Paymob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * تحويلُ Paymob الأوّلُ بعد #168 لا يُدخل البنكَ مالًا مرّتين، ولا يُضيع تحصيلًا.
 *
 * قبل #168 قُيِّدت بيعةُ الموقع بالبطاقة في البنك يومَ البيع؛ وبعده تنتظر في
 * «Paymob تحت التسوية». وتحويلٌ واحدٌ من Paymob يحمل الاثنين معًا، ومعهما
 * روابطُ الدفع. فالتسويةُ تسمّي مدفوعاتِها، ويُسأل الدفترُ عن كلٍّ منها أين
 * مالُه (`PaymobSettlements::classify`): ما في التسوية يخرج منها، وما في البنك
 * منذ يوم البيع لا يُضاف ثانيةً، وما لا يُعرف تُحفظ تسويتُه ولا تُرحَّل.
 *
 * وكلُّ اختبارٍ يقيس مالَ البنك الحقيقيّ: بعد التسوية يساوي ما وصل البنكَ
 * فعلًا (الصافي) — لا الصافيَ فوق إجماليٍّ قُيِّد يومَ البيع.
 */
class APaymobPayoutNeverCountsTheBankTwiceTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private User $owner;

    private Product $rose;

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

    /** متجرٌ بموقعٍ وPaymob وحسابٍ بنكيّ رئيسيّ */
    private function openShop(string $slug, string $email): array
    {
        $shop = Business::create([
            'name' => 'متجر '.$slug, 'type' => 'محل ورد', 'status' => 'نشط', 'phone' => '96890000001',
            'city' => 'مسقط', 'site_slug' => $slug, 'tier' => 'gold', 'storefront_theme' => 'ribbon',
        ]);
        Currency::create(['business_id' => $shop->id, 'code' => 'OMR', 'name' => 'ريال عماني', 'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);
        Ledger::seedChart($shop->id);
        Branch::create(['business_id' => $shop->id, 'name' => 'الرئيسي']);
        Setting::create(['business_id' => $shop->id, 'key' => 'vat_enabled', 'value' => '0']);
        $owner = User::create(['business_id' => $shop->id, 'name' => 'المالك', 'email' => $email, 'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط']);
        MarketingSettings::save($shop->id, 'website', ['store_on' => '1', 'store_pay_cod' => '1']);
        $rose = Product::create([
            'business_id' => $shop->id, 'name' => 'باقة ورد', 'price' => 20,
            'cost' => 0, 'quantity' => 50, 'alert_qty' => 1, 'active' => true, 'published' => true,
        ]);
        PaymentGateway::create([
            'business_id' => $shop->id, 'provider' => PaymentGateway::PAYMOB,
            'public_key' => 'pk_test_abc', 'secret_key' => 'sk_test_abc', 'hmac_secret' => 'hmac_secret_value',
            'card_integration_id' => '4569876', 'active' => true,
        ]);
        $this->actingAs($owner)->post(route('admin.finance.banks.store'), [
            'bank_name' => 'بنك مسقط', 'opening_balance' => 0, 'opening_date' => '2027-01-01',
        ])->assertSessionHasNoErrors();

        return [$shop, $owner, $rose];
    }

    /** بيعةُ موقعٍ بالبطاقة اليوم (#168): زبونٌ يدفع ويصل إشعارُ Paymob الموقَّع ⇒ في التسوية */
    private function newSale(int $qty = 1, ?Business $shop = null): StorePaymentIntent
    {
        $shop ??= $this->shop;
        Http::fake(['oman.paymob.com/*' => Http::response(['client_secret' => 'csk_x'], 201)]);
        $this->postJson('/s/'.$shop->site_slug.'/checkout', [
            'items' => [['id' => Product::where('business_id', $shop->id)->value('id'), 'qty' => $qty]],
            'name' => 'زبون', 'phone' => '95000001', 'fulfil' => 'pickup', 'date' => '2027-02-12', 'pay' => 'card',
        ])->assertOk();

        $intent = StorePaymentIntent::where('business_id', $shop->id)->latest('id')->firstOrFail();
        $obj = [
            'amount_cents' => 20000 * $qty, 'created_at' => '2027-02-10T10:00:10.100000', 'currency' => 'OMR',
            'error_occured' => false, 'has_parent_transaction' => false, 'id' => 990000 + $intent->id,
            'integration_id' => 4569876, 'is_3d_secure' => true, 'is_auth' => false, 'is_capture' => false,
            'is_refunded' => false, 'is_standalone_payment' => true, 'is_voided' => false,
            'order' => ['id' => 770000 + $intent->id, 'merchant_order_id' => $intent->reference], 'owner' => 4705,
            'pending' => false, 'source_data' => ['pan' => '2346', 'sub_type' => 'Visa', 'type' => 'card'], 'success' => true,
        ];
        $this->postJson('/webhooks/paymob?hmac='.Paymob::signature($obj, 'hmac_secret_value'), ['obj' => $obj])->assertOk();

        return $intent->fresh();
    }

    /**
     * بيعةُ موقعٍ بالبطاقة قبل #168 — كما رحّلها الإصدارُ السابق حرفًا: يُدان
     * البنكُ بإجماليّها يومَ البيع (`Bank::leaf` وإلّا «البنك»)، والنيّةُ مدفوعة.
     */
    private function oldSale(float $amount = 20, ?int $bankAccountId = null): StorePaymentIntent
    {
        $branch = Branch::where('business_id', $this->shop->id)->value('id');
        $order = Order::forceCreate([
            'business_id' => $this->shop->id, 'number' => 'OLD-'.Order::count(), 'channel' => 'website', 'payment_method' => 'بطاقة',
            'payment_status' => 'مدفوع', 'status' => 'مكتمل', 'subtotal' => $amount, 'discount' => 0, 'tax' => 0, 'delivery_fee' => 0,
            'total' => $amount, 'branch_id' => $branch, 'ordered_at' => now()->subDays(3), 'customer_name' => 'زبون', 'balance_due' => 0, 'paid_after_sale' => 0,
        ]);
        OrderItem::forceCreate(['order_id' => $order->id, 'product_id' => $this->rose->id, 'name' => 'باقة ورد', 'price' => $amount, 'quantity' => 1, 'total' => $amount, 'cost' => 0]);
        Ledger::post($this->shop->id, 'مبيعات '.$order->number, [
            ['account' => Bank::leaf($this->shop->id, $bankAccountId) ?? 'bank', 'debit' => $amount],
            ['account' => 'sales_website', 'credit' => $amount],
        ], now()->subDays(3), Books::SALE, $branch, $this->owner->id, $order);

        return StorePaymentIntent::forceCreate([
            'business_id' => $this->shop->id, 'reference' => 'OLD-INTENT-'.$order->id, 'payload' => '{}', 'amount' => $amount,
            'status' => StorePaymentIntent::PAID, 'provider_transaction_id' => 'OLD-TX-'.$order->id, 'order_id' => $order->id, 'paid_at' => now()->subDays(3),
        ]);
    }

    /** رابطُ دفعٍ دُفع — يُكتب تحصيلُه في التسوية كما يكتبه إشعارُ Paymob */
    private function paidLink(float $amount = 30): CustomerPaymentLink
    {
        $customer = Customer::create(['business_id' => $this->shop->id, 'name' => 'عميل', 'phone' => '9500'.random_int(1000, 9999)]);
        $link = CustomerPaymentLink::create([
            'business_id' => $this->shop->id, 'customer_id' => $customer->id, 'reference' => 'pay-'.uniqid(),
            'amount' => $amount, 'currency' => 'OMR', 'status' => CustomerPaymentLink::PENDING, 'provider_link_id' => '1',
        ]);
        PaymentLinks::settle($link, 'TX-'.$link->id);

        return $link->fresh();
    }

    private function key(StorePaymentIntent|CustomerPaymentLink $p): string
    {
        return ($p instanceof StorePaymentIntent ? 'intent:' : 'link:').$p->id;
    }

    private function settle(array $payments, float $fee, float $net, array $extra = []): PaymobSettlement
    {
        return PaymobSettlements::record($this->shop->id, [
            'items' => array_map(fn ($p) => $this->key($p), $payments), 'fee' => $fee, 'net' => $net, 'settled_on' => '2027-02-13',
        ] + $extra, $this->owner->id);
    }

    /** مالُ البنك كلُّه: كلُّ أوراقه */
    private function bank(?int $shop = null): float
    {
        return round(Bank::total($shop ?? $this->shop->id), 3);
    }

    private function clearing(?int $shop = null): float
    {
        return round(Ledger::balance($shop ?? $this->shop->id, 'paymob_clearing'), 3);
    }

    private function fees(): float
    {
        return round(Ledger::balance($this->shop->id, 'payment_gateway_fees'), 3);
    }

    /** بصمةُ كلِّ قيدٍ وسطرٍ حتى رقمٍ — والعكسُ فيها: التسويةُ لا تعكس شيئًا */
    private function history(int $upto): string
    {
        return md5(json_encode([
            DB::table('journal_entries')->where('id', '<=', $upto)->orderBy('id')
                ->get(['id', 'business_id', 'number', 'entry_date', 'source', 'sourceable_type', 'sourceable_id', 'posted', 'reverses_id', 'reversed_at']),
            DB::table('journal_lines')->where('journal_entry_id', '<=', $upto)->orderBy('id')->get(['id', 'journal_entry_id', 'account_id', 'debit', 'credit']),
        ]));
    }

    /* ═══════════ ١ · أين مالُ كلِّ مدفوعة ═══════════ */

    public function test_each_payment_says_where_its_money_is(): void
    {
        $new = $this->newSale();
        $old = $this->oldSale();
        $link = $this->paidLink();

        $booked = collect(PaymobSettlements::candidates($this->shop->id))->pluck('booked', 'key')->all();

        // مصفوفةٌ بمفاتيح: assertEquals لا يسأل عن ترتيبها
        $this->assertEquals([
            $this->key($new) => PaymobSettlements::IN_CLEARING,
            $this->key($old) => PaymobSettlements::IN_BANK,
            $this->key($link) => PaymobSettlements::IN_CLEARING,
        ], array_intersect_key($booked, array_flip([$this->key($new), $this->key($old), $this->key($link)])));
        $this->assertCount(3, $booked);

        // والشاشةُ تعرضها كما هي
        $this->get(route('admin.finance.paymentLinks'))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->has('payouts', 3)
                ->where('payouts', fn ($rows) => collect($rows)->pluck('booked')->sort()->values()->all() === ['bank', 'clearing', 'clearing']));
    }

    /* ═══════════ ٢ · لا يدخل البنكَ مالٌ مرّتين ═══════════ */

    public function test_an_old_sale_already_in_the_bank_is_not_added_again_only_its_fee_is_booked(): void
    {
        $old = $this->oldSale(20);
        $saleEntry = JournalEntry::where('source', Books::SALE)->latest('id')->firstOrFail();
        $before = $saleEntry->lines()->orderBy('id')->get(['account_id', 'debit', 'credit'])->toArray();
        $this->assertSame(20.0, $this->bank(), 'تهيئة: قُيِّد إجماليُّها في البنك يومَ البيع');

        $settlement = $this->settle([$old], 0.6, 19.4);

        $this->assertSame(PaymobSettlements::POSTED, $settlement->status);
        $this->assertSame(19.4, $this->bank(), 'دخل البنكَ الصافي فوق إجماليٍّ قُيِّد يومَ البيع');
        $this->assertSame(0.6, $this->fees());
        $this->assertSame(0.0, $this->clearing(), 'سُحب من التسوية مالٌ لم يكن فيها');
        // وقيدُ البيع القديم كما هو: لا عكسٌ ولا تعديل
        $this->assertSame($before, $saleEntry->fresh()->lines()->orderBy('id')->get(['account_id', 'debit', 'credit'])->toArray());
        $this->assertNull($saleEntry->fresh()->reversed_at);
    }

    public function test_a_mixed_payout_counts_every_riyal_once(): void
    {
        $old = $this->oldSale(20);
        $new = $this->newSale(2);
        $link = $this->paidLink(30);
        $this->assertSame([20.0, 70.0], [$this->bank(), $this->clearing()], 'تهيئة');
        $entries = JournalEntry::count();
        $history = $this->history((int) JournalEntry::max('id'));

        // تحويلٌ واحدٌ يحمل الثلاث: ٢٠ + ٤٠ + ٣٠ = ٩٠، ورسومٌ ٢٫٧، والصافي ٨٧٫٣
        $settlement = $this->settle([$old, $new, $link], 2.7, 87.3);

        $this->assertSame(PaymobSettlements::POSTED, $settlement->status);
        $this->assertSame(87.3, $this->bank(), 'البنكُ يساوي ما وصله فعلًا');
        $this->assertSame(0.0, $this->clearing());
        $this->assertSame(2.7, $this->fees());
        $this->assertSame($entries + 1, JournalEntry::count(), 'قيدٌ واحدٌ جديد لا غير');
        $this->assertSame($history, $this->history((int) JournalEntry::max('id') - 1), 'تغيّر قيدٌ قائم');

        $entry = JournalEntry::findOrFail($settlement->journal_entry_id);
        $this->assertEquals(round((float) $entry->lines()->sum('debit'), 3), round((float) $entry->lines()->sum('credit'), 3));
        $this->assertSame(3, $settlement->items()->count());
    }

    public function test_the_old_sale_leaves_the_bank_it_was_booked_in_and_the_net_enters_the_payout_bank(): void
    {
        $this->post(route('admin.finance.banks.store'), ['bank_name' => 'بنك ظفار', 'opening_balance' => 0, 'opening_date' => '2027-01-01'])->assertSessionHasNoErrors();
        [$muscat, $dhofar] = BankAccount::where('business_id', $this->shop->id)->orderBy('id')->get()->all();

        $old = $this->oldSale(20, $muscat->id);
        $this->settle([$old], 0.6, 19.4, ['bank_account_id' => $dhofar->id]);

        $this->assertSame(0.0, round($muscat->fresh()->account->balance(), 3), 'بقي في بنك يوم البيع مالٌ لم يصله');
        $this->assertSame(19.4, round($dhofar->fresh()->account->balance(), 3));
        $this->assertSame(19.4, $this->bank());
    }

    public function test_an_old_payout_without_fee_moves_nothing_and_writes_no_entry(): void
    {
        $old = $this->oldSale(20);
        $entries = JournalEntry::count();

        $settlement = $this->settle([$old], 0, 20);

        $this->assertSame(PaymobSettlements::POSTED, $settlement->status);
        $this->assertNull($settlement->journal_entry_id);
        $this->assertSame($entries, JournalEntry::count());
        $this->assertSame(20.0, $this->bank());
    }

    /**
     * بيعةٌ رحّلها #168 في التسوية، ثمّ صحّحها كودٌ أقدم بعد تراجع فنقلها إلى
     * البنك (`rollback-gate.sql` A2). الدفترُ يقول أين مالُها اليوم: في البنك.
     */
    public function test_a_sale_moved_to_the_bank_by_older_code_is_not_counted_twice(): void
    {
        $intent = $this->newSale();
        $order = Order::findOrFail($intent->order_id);
        Books::unpostSale($order);
        Ledger::post($this->shop->id, 'مبيعات '.$order->number, [
            ['account' => Bank::leaf($this->shop->id) ?? 'bank', 'debit' => 20],
            ['account' => 'sales_website', 'credit' => 20],
        ], now(), Books::SALE, $order->branch_id, $this->owner->id, $order);
        $this->assertSame([20.0, 0.0], [$this->bank(), $this->clearing()], 'تهيئة');

        $this->assertSame(PaymobSettlements::IN_BANK, PaymobSettlements::classify($this->shop->id, 'intent', $intent->id)['booked']);
        $this->settle([$intent], 0.6, 19.4);

        $this->assertSame([19.4, 0.0], [$this->bank(), $this->clearing()]);
    }

    /** فرقٌ رُدّ بالبطاقة من بيعةٍ في التسوية: ينتظر منها ما بقي، ويُسوّى به */
    public function test_a_card_refund_out_of_clearing_leaves_the_rest_to_be_settled(): void
    {
        $this->shop->forceFill(['order_notes_and_edits_enabled' => true])->save();
        $intent = $this->newSale(2);
        $order = Order::findOrFail($intent->order_id);
        OrderCorrection::replaceLine($order->fresh(), $order->items()->firstOrFail(), ['product_id' => $this->rose->id, 'qty' => 1], 'أعاد باقة', OrderCorrection::SETTLE_REFUNDED, 'بطاقة');
        $this->assertSame(20.0, $this->clearing(), 'تهيئة');

        $row = PaymobSettlements::classify($this->shop->id, 'intent', $intent->id);
        $this->assertSame([PaymobSettlements::IN_CLEARING, 20.0], [$row['booked'], $row['amount']]);

        $this->settle([$intent], 0.6, 19.4);
        $this->assertSame([0.0, 19.4], [$this->clearing(), $this->bank()]);
    }

    /* ═══════════ ٣ · التسويةُ الجزئيّة والتكرار ═══════════ */

    public function test_a_payout_in_two_parts_settles_each_payment_once_and_the_rest_waits(): void
    {
        $a = $this->newSale();
        $b = $this->newSale();
        $link = $this->paidLink(30);

        $this->settle([$a, $link], 1.5, 48.5);
        $this->assertSame([$this->key($b)], collect(PaymobSettlements::candidates($this->shop->id))->pluck('key')->all(), 'ما بقي ينتظر');
        $this->assertSame([20.0, 48.5], [$this->clearing(), $this->bank()]);

        $this->settle([$b], 0.6, 19.4);
        $this->assertSame([0.0, 67.9, 2.1], [$this->clearing(), $this->bank(), $this->fees()]);
        $this->assertSame([], PaymobSettlements::candidates($this->shop->id));
    }

    public function test_a_payment_is_never_settled_twice(): void
    {
        $a = $this->newSale();
        $b = $this->newSale();
        $this->settle([$a], 0.6, 19.4);
        $state = [$this->bank(), $this->clearing(), $this->fees(), JournalEntry::count(), PaymobSettlement::count()];

        foreach ([[[$a], 0.6, 19.4], [[$a, $b], 1.2, 38.8]] as [$payments, $fee, $net]) {
            try {
                $this->settle($payments, $fee, $net);
                $this->fail('سُوّيت مدفوعةٌ مرّتين');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('سبقت تسويتُها', $e->getMessage());
            }
        }
        $this->assertSame($state, [$this->bank(), $this->clearing(), $this->fees(), JournalEntry::count(), PaymobSettlement::count()]);

        // والضغطةُ الثانية على الزرّ نفسه بالحمولة نفسها تُردّ ولا تكتب شيئًا
        $payload = ['items' => [$this->key($b)], 'fee' => 0.6, 'net' => 19.4, 'settled_on' => '2027-02-13'];
        $this->post(route('admin.finance.paymobSettlements.store'), $payload)->assertSessionHasNoErrors();
        $this->post(route('admin.finance.paymobSettlements.store'), $payload)->assertSessionHasErrors('items');
        $this->assertSame(2, PaymobSettlement::count());
        $this->assertSame([0.0, 38.8], [$this->clearing(), $this->bank()]);
    }

    public function test_the_payout_must_add_up_to_the_payments_chosen(): void
    {
        $a = $this->newSale();

        try {
            $this->settle([$a], 0.6, 25);
            $this->fail('قُبل تحويلٌ لا يساوي مدفوعاته');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('لا يساوي', $e->getMessage());
        }

        $this->post(route('admin.finance.paymobSettlements.store'), ['items' => [], 'fee' => 0, 'net' => 20, 'settled_on' => '2027-02-13'])->assertSessionHasErrors('items');
        $this->assertSame([0, 20.0, 0.0], [PaymobSettlement::count(), $this->clearing(), $this->bank()]);
    }

    /* ═══════════ ٤ · غيرُ المؤكَّد يُحفظ ولا يُرحَّل ═══════════ */

    public function test_an_uncertain_payment_holds_the_whole_payout_unposted_and_keeps_it(): void
    {
        $new = $this->newSale();
        // نفد الصنفُ بين الدفع والتصديق: مالٌ مقبوضٌ بلا طلب
        $stray = StorePaymentIntent::forceCreate([
            'business_id' => $this->shop->id, 'reference' => 'STRAY-1', 'payload' => '{}', 'amount' => 15,
            'status' => StorePaymentIntent::PAID, 'provider_transaction_id' => 'TX-STRAY', 'order_id' => null, 'paid_at' => now(),
        ]);
        $state = [$this->bank(), $this->clearing(), $this->fees(), JournalEntry::count()];

        $this->post(route('admin.finance.paymobSettlements.store'), [
            'items' => [$this->key($new), $this->key($stray)], 'fee' => 1.05, 'net' => 33.95, 'settled_on' => '2027-02-13', 'reference' => 'PMB-9',
        ])->assertSessionHasNoErrors()->assertSessionHas('toast.type', 'warning');

        $settlement = PaymobSettlement::sole();
        $this->assertSame(PaymobSettlements::HELD, $settlement->status);
        $this->assertNull($settlement->journal_entry_id);
        $this->assertStringContainsString('لا طلبَ له', (string) $settlement->hold_reason);
        // لا شيءَ في الدفتر، والتحويلُ محفوظٌ بأرقامه ومدفوعاته
        $this->assertSame($state, [$this->bank(), $this->clearing(), $this->fees(), JournalEntry::count()]);
        $this->assertSame([35.0, 1.05, 33.95, 'PMB-9'], [(float) $settlement->gross, (float) $settlement->fee, (float) $settlement->net, $settlement->reference]);
        $this->assertSame(['clearing', 'unknown'], $settlement->items()->orderBy('id')->pluck('booked')->all());
        // والمعلّقةُ تحجز مدفوعاتِها
        $this->assertSame([], PaymobSettlements::candidates($this->shop->id));
    }

    /** @return array<string, array{0: callable}> */
    public static function uncertain(): array
    {
        return [
            'طلبٌ ملغى' => [fn (Order $o, StorePaymentIntent $i) => DB::table('orders')->where('id', $o->id)->update(['status' => 'ملغي'])],
            'ردٌّ طُلب عند Paymob' => [fn (Order $o, StorePaymentIntent $i) => DB::table('store_payment_intents')->where('id', $i->id)->update(['refund_status' => 'sent'])],
            'بيعةٌ لم تُرحَّل' => [fn (Order $o, StorePaymentIntent $i) => Books::unpostSale($o)],
        ];
    }

    #[DataProvider('uncertain')]
    public function test_a_payment_whose_money_cannot_be_placed_is_uncertain(callable $make): void
    {
        $intent = $this->newSale();
        $make(Order::findOrFail($intent->order_id), $intent);

        $this->assertSame(PaymobSettlements::UNSURE, PaymobSettlements::classify($this->shop->id, 'intent', $intent->id)['booked']);
    }

    public function test_a_held_payout_is_posted_when_rechecked_after_its_cause_is_resolved(): void
    {
        $intent = $this->newSale();
        $order = Order::findOrFail($intent->order_id);
        Books::unpostSale($order);   // بيعةٌ بلا قيدٍ حيّ — كما يتركها عطلٌ تُصلحه finance:post-missing-sales

        $held = $this->settle([$intent], 0.6, 19.4);
        $this->assertSame(PaymobSettlements::HELD, $held->status);

        // إعادةُ الفحص قبل الإصلاح: ما زالت معلّقة، ولا قيد
        $this->post(route('admin.finance.paymobSettlements.retry', $held->id))->assertSessionHas('toast.type', 'warning');
        $this->assertSame([PaymobSettlements::HELD, null], [$held->fresh()->status, $held->fresh()->journal_entry_id]);

        Books::recordSale($order->fresh(['items.addons']));
        $this->post(route('admin.finance.paymobSettlements.retry', $held->id))->assertSessionHas('toast.type', 'success');

        $this->assertSame(PaymobSettlements::POSTED, $held->fresh()->status);
        $this->assertNull($held->fresh()->hold_reason);
        $this->assertSame([0.0, 19.4, 0.6], [$this->clearing(), $this->bank(), $this->fees()]);
    }

    public function test_a_held_payout_cancelled_before_posting_frees_its_payments_and_keeps_its_record(): void
    {
        $intent = $this->newSale();
        Books::unpostSale(Order::findOrFail($intent->order_id));
        $held = $this->settle([$intent], 0.6, 19.4);

        $this->post(route('admin.finance.paymobSettlements.cancel', $held->id))->assertSessionHas('toast.type', 'success');

        $held->refresh();
        $this->assertSame(PaymobSettlements::CANCELLED, $held->status);
        $this->assertSame(['intent:'.$intent->id], $held->items()->pluck('source')->all(), 'ضاع أيُّ مدفوعةٍ ادّعتها');
        $this->assertSame([$this->key($intent)], collect(PaymobSettlements::candidates($this->shop->id))->pluck('key')->all());

        // والمرحّلةُ لا تُلغى من هنا
        Books::recordSale(Order::findOrFail($intent->order_id)->fresh(['items.addons']));
        $posted = $this->settle([$intent], 0.6, 19.4);
        $this->post(route('admin.finance.paymobSettlements.cancel', $posted->id))->assertSessionHas('toast.type', 'error');
        $this->assertSame(PaymobSettlements::POSTED, $posted->fresh()->status);
    }

    /* ═══════════ ٥ · لا يمسّ متجرًا آخر ═══════════ */

    public function test_another_shops_payments_cannot_be_named_and_its_books_never_move(): void
    {
        [$other, $otherOwner] = $this->openShop('neighbour', 'owner@neighbour.om');
        $theirs = $this->newSale(1, $other);
        $this->actingAs($this->owner);
        $ours = $this->newSale();
        $theirBooks = [$this->bank($other->id), $this->clearing($other->id), JournalEntry::where('business_id', $other->id)->count()];

        try {
            $this->settle([$ours, $theirs], 1.2, 38.8);
            $this->fail('سُوّيت مدفوعةُ متجرٍ آخر');
        } catch (RuntimeException) {
        }
        $this->settle([$ours], 0.6, 19.4);

        $this->assertSame($theirBooks, [$this->bank($other->id), $this->clearing($other->id), JournalEntry::where('business_id', $other->id)->count()]);
        $this->assertSame([$this->key($theirs)], collect(PaymobSettlements::candidates($other->id))->pluck('key')->all());
        $this->assertSame([], PaymobSettlements::candidates($this->shop->id));

        // وتسويةُ الجار المعلّقة لا تُفحص ولا تُلغى من هنا
        $this->actingAs($otherOwner);
        Books::unpostSale(Order::findOrFail($theirs->order_id));
        $theirHeld = $this->settleFor($other->id, [$theirs], 0.6, 19.4);
        $this->actingAs($this->owner);
        $this->post(route('admin.finance.paymobSettlements.retry', $theirHeld->id))->assertNotFound();
        $this->post(route('admin.finance.paymobSettlements.cancel', $theirHeld->id))->assertNotFound();
        $this->assertSame(PaymobSettlements::HELD, $theirHeld->fresh()->status);
    }

    private function settleFor(int $shop, array $payments, float $fee, float $net): PaymobSettlement
    {
        return PaymobSettlements::record($shop, [
            'items' => array_map(fn ($p) => $this->key($p), $payments), 'fee' => $fee, 'net' => $net, 'settled_on' => '2027-02-13',
        ], null);
    }
}
