<?php

namespace Tests\Feature;

use App\Exceptions\SettlementRefused;
use App\Models\Boutique;
use App\Models\BoutiqueComponentDue;
use App\Models\BoutiqueSettlement;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Expense;
use App\Models\JournalEntry;
use App\Models\Order;
use App\Models\PaymentGateway;
use App\Models\Product;
use App\Models\RecipeItem;
use App\Models\Setting;
use App\Models\StorePaymentIntent;
use App\Models\User;
use App\Support\BoutiqueDues;
use App\Support\Boutiques;
use App\Support\Demo;
use App\Support\Ledger;
use App\Support\MarketingSettings;
use App\Support\OrderCorrection;
use App\Support\Store\WebCheckout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * كشفُ البوتيك وتسويتُه وسدادُه وإلغاؤه — للمنفرد القديم ولمكوّنات الباقات معًا.
 *
 * ═══ ما يُحرس ═══
 *
 *   - الكشفُ يجمع النوعين ويفصل طريقتيهما: المنفردُ إجماليٌّ وعمولةٌ وصافٍ،
 *     والمكوّناتُ ثمنُ قطعٍ بلا عمولة. والمطلوبُ مجموعُ الصافيين.
 *   - إصدارُ الورقة لا يكتب قيدًا. وسدادُ المكوّنات يُدين 2160 وحدَه،
 *     ومصروفُ المنفرد يبقى على طريقه — ولا مصروفَ ثانٍ عن المكوّنات.
 *   - كشوفٌ جزئيّةٌ ومتعدّدة في شهرٍ واحد، ولا يُحمل صفٌّ في ورقتين.
 *   - الإلغاءُ قبل التسوية: تعود القطعةُ فيُعكس، أو لا تعود فيبقى المستحقّ
 *     موثَّقًا وخسارتُه على المتجر، ثمّ يُعكس إن عادت بعدُ.
 *   - الإلغاءُ بعد السداد: الورقةُ القديمة كما هي، والعكسُ رصيدٌ للمتجر في
 *     1370 لا التزامٌ سالب، يُقاصّ بما بعده أو يُستردّ — ولا ربحَ مرّتين.
 *   - سعرٌ تغيّر والزبونُ عند البنك: المستحقُّ ما ثُبِّت قبل الدفع، والدفعةُ
 *     المقبوضة طلبٌ دائمًا. وسعرٌ يرسله المتصفّح لا يُقرأ.
 *
 * الأرقامُ بلا ضريبة ولا توصيل. باقةٌ بعشرين فيها فضّةُ «لمى» بسبعة، ووشاحُ
 * «لمى» يُباع منفردًا بخمسةٍ وعشرين وعمولتُها ٢٠٪.
 */
class ABoutiqueStatementSettlesWhatWentIntoBouquetsTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private User $owner;

    private Boutique $lama;

    private Product $silver;

    private Product $scarf;

    private Product $pin;

    private Product $bouquet;

    private Product $mini;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2027-02-10 10:00:00');
        $this->app->setLocale('ar');

        foreach (array_keys((array) config('storefront')) as $key) {
            if (str_starts_with($key, 'ribbon_') && str_ends_with($key, '_businesses')) {
                config(['storefront.'.$key => []]);
            }
        }

        $this->shop = Business::create([
            'name' => 'RIBBON', 'type' => 'محل ورد', 'status' => 'نشط', 'phone' => '96895259066',
            'city' => 'مسقط', 'site_slug' => 'ribbon', 'tier' => 'gold', 'storefront_theme' => 'ribbon',
            'boutiques_enabled' => true, 'boutique_components_enabled' => true,
        ]);
        Currency::create(['business_id' => $this->shop->id, 'code' => 'OMR', 'name' => 'ريال عماني', 'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);
        Ledger::seedChart($this->shop->id);
        Branch::create(['business_id' => $this->shop->id, 'name' => 'الخوير']);
        Setting::create(['business_id' => $this->shop->id, 'key' => 'vat_enabled', 'value' => '0']);
        $this->owner = User::create(['business_id' => $this->shop->id, 'name' => 'سعود', 'email' => 'saud@abaad.om', 'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط']);
        MarketingSettings::save($this->shop->id, 'website', [
            'store_on' => '1', 'store_pay_cod' => '1', 'store_delivery_fee' => '0',
            'store_delivery_areas' => 'الخوير', 'store_delivery_slots' => '9 ص – 12 م',
        ]);

        $this->lama = Boutique::create(['business_id' => $this->shop->id, 'name' => 'بوتيك لمى', 'commission_rate' => 20, 'active' => true]);

        $this->silver = $this->product('قطعة فضّة', 7, boutique: $this->lama->id, qty: 10);
        $this->scarf = $this->product('وشاح لمى', 25, boutique: $this->lama->id, qty: 10);
        $this->pin = $this->product('دبّوس لمى', 3, boutique: $this->lama->id, qty: 10);

        $this->bouquet = $this->product('باقة الفضّة', 20);
        RecipeItem::create(['business_id' => $this->shop->id, 'product_id' => $this->bouquet->id, 'component_product_id' => $this->silver->id, 'quantity' => 1, 'wastage_percent' => 0]);

        $this->mini = $this->product('باقة صغيرة', 10);
        RecipeItem::create(['business_id' => $this->shop->id, 'product_id' => $this->mini->id, 'component_product_id' => $this->pin->id, 'quantity' => 1, 'wastage_percent' => 0]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function product(string $name, float $price, ?int $boutique = null, int $qty = 100): Product
    {
        return Product::create([
            'business_id' => $this->shop->id, 'name' => $name, 'price' => $price, 'cost' => 0,
            'quantity' => $qty, 'alert_qty' => 0, 'active' => true, 'published' => true,
            'boutique_id' => $boutique,
        ]);
    }

    private function sell(Product $p, int $qty = 1): Order
    {
        $this->actingAs($this->owner)->postJson('/pos/checkout', [
            'items' => [['id' => $p->id, 'name' => $p->name, 'qty' => $qty, 'price' => (float) $p->price]],
            'payment_method' => 'نقدي',
        ])->assertOk();

        return Order::where('business_id', $this->shop->id)->orderByDesc('id')->firstOrFail();
    }

    private function balance(string $key): float
    {
        return Ledger::balance($this->shop->id, $key);
    }

    /** الأرصدةُ التي يقرؤها التقرير — بلا ضريبة ولا توصيل */
    private function books(): array
    {
        $sales = Ledger::netRevenue($this->shop->id);
        $cogs = $this->balance('cogs');
        $other = $this->balance('other_expenses');

        return [
            'cash' => $this->balance('cash'),
            'sales' => $sales,
            'cogs' => $cogs,
            'other' => $other,
            'profit' => round($sales - $cogs - $other, 3),
            'payable' => $this->balance('boutique_payable'),
            'receivable' => $this->balance('boutique_receivable'),
        ];
    }

    private function settle(string $period = '2027-02'): BoutiqueSettlement
    {
        return Boutiques::settle($this->shop, $this->lama, $period);
    }

    private function payStandalone(BoutiqueSettlement $s): void
    {
        $this->actingAs($this->owner)->post(route('admin.expenses.paid', $s->expense_id))->assertRedirect();
    }

    /* ═══════════ البيعُ فالكشفُ فالتسويةُ فالسداد ═══════════ */

    public function test_the_balances_from_sale_to_statement_to_settlement_to_payment(): void
    {
        $this->assertSame(['cash' => 0.0, 'sales' => 0.0, 'cogs' => 0.0, 'other' => 0.0, 'profit' => 0.0, 'payable' => 0.0, 'receivable' => 0.0], $this->books(), 'قبل البيع');

        $this->sell($this->bouquet);   // ٢٠، للمى ٧ عن الفضّة
        $this->sell($this->scarf);     // ٢٥ منفردًا، للمى ٢٠ بعد عمولة ٥

        $this->assertSame(['cash' => 45.0, 'sales' => 45.0, 'cogs' => 7.0, 'other' => 0.0, 'profit' => 38.0, 'payable' => 7.0, 'receivable' => 0.0], $this->books(), 'بعد البيع');
        $this->assertSame(7.0, round(Demo::cogsFor($this->shop->id, Carbon::parse('2027-02-01')), 3), 'التقريرُ والدفترُ رقمٌ واحد');

        // الكشفُ: كلُّ نوعٍ بطريقته، والمطلوبُ مجموعُهما
        $st = Boutiques::statement($this->shop->id, $this->lama->id, '2027-02');
        $this->assertSame([25.0, 5.0, 20.0], [$st['gross'], $st['commission'], $st['net']]);
        $this->assertSame([7.0, 0.0, 7.0], [$st['components']['due'], $st['components']['credit'], $st['components']['net']]);
        $this->assertSame('قطعة فضّة', $st['components']['lines'][0]['name']);
        $this->assertSame(27.0, $st['payable']);

        // الإصدارُ لا يكتب قيدًا — التكلفةُ كُتبت يوم البيع
        $entries = JournalEntry::where('business_id', $this->shop->id)->count();
        $s = $this->settle();
        $this->assertSame($entries, JournalEntry::where('business_id', $this->shop->id)->count(), 'كُتب قيدٌ عند الإصدار');
        $this->assertSame([20.0, 7.0, 0.0, 7.0], [(float) $s->net, (float) $s->components_due, (float) $s->components_credit, (float) $s->components_net]);
        $this->assertSame(20.0, (float) Expense::findOrFail($s->expense_id)->amount, 'مصروفُ المنفرد بصافيه وحده — لا المكوّنات');
        $this->assertSame((int) $s->id, (int) BoutiqueComponentDue::query()->value('boutique_settlement_id'), 'صفُّ الفضّة محجوزٌ بالورقة');
        $this->assertSame(['cash' => 45.0, 'sales' => 45.0, 'cogs' => 7.0, 'other' => 0.0, 'profit' => 38.0, 'payable' => 7.0, 'receivable' => 0.0], $this->books(), 'بعد الإصدار');
        $this->assertFalse($s->fresh()->paid());

        // سدادُ المكوّنات: 2160 وحده — لا تكلفة ولا مصروف
        BoutiqueDues::pay($s, 'نقدي');
        BoutiqueDues::pay($s->fresh(), 'نقدي');
        $this->assertSame(['cash' => 38.0, 'sales' => 45.0, 'cogs' => 7.0, 'other' => 0.0, 'profit' => 38.0, 'payable' => 0.0, 'receivable' => 0.0], $this->books(), 'بعد سداد المكوّنات — ومرّةً واحدة');
        $this->assertFalse($s->fresh()->paid(), 'والمنفردُ لم يُسدَّد بعد');

        // والمنفردُ على طريقه القائم: مصروفٌ يومَ سداده
        $this->payStandalone($s);
        $this->assertSame(['cash' => 18.0, 'sales' => 45.0, 'cogs' => 7.0, 'other' => 20.0, 'profit' => 18.0, 'payable' => 0.0, 'receivable' => 0.0], $this->books(), 'بعد السداد كلِّه: ١٣ + ٥');
        $this->assertTrue($s->fresh()->paid());

        // ولا شيءَ يبقى لورقةٍ ثانية
        $this->expectException(SettlementRefused::class);
        $this->settle();
    }

    public function test_partial_and_multiple_statements_never_carry_a_row_twice(): void
    {
        $this->sell($this->bouquet);
        $first = $this->settle();

        Carbon::setTestNow('2027-02-15 10:00:00');
        $this->sell($this->bouquet, 2);
        $this->sell($this->scarf);

        $st = Boutiques::statement($this->shop->id, $this->lama->id, '2027-02');
        $this->assertSame(14.0, $st['components']['due'], 'الثانيةُ لا تحمل ما حملته الأولى');
        $this->assertSame(20.0, $st['net']);

        $second = $this->settle();
        $this->assertSame([7.0, 14.0], [(float) $first->components_due, (float) $second->components_due]);
        $this->assertSame(0.0, (float) $first->net, 'الأولى بلا منفرد');
        $this->assertNull($first->expense_id, 'ولا مصروفَ لورقةٍ بلا منفرد');
        $this->assertSame(21.0, $this->balance('boutique_payable'));

        BoutiqueDues::pay($first, 'نقدي');
        BoutiqueDues::pay($second, 'تحويل');
        $this->assertSame(0.0, $this->balance('boutique_payable'));
        $this->assertSame(-14.0, $this->balance('bank'), 'الثانيةُ من البنك');
        $this->assertTrue($first->fresh()->paid());
    }

    /* ═══════════ الإلغاء قبل التسوية ═══════════ */

    public function test_cancelling_when_the_piece_returns_reverses_its_due(): void
    {
        $order = $this->sell($this->bouquet);
        $this->actingAs($this->owner);
        OrderCorrection::cancel($order->fresh(), 'الزبون ألغى قبل التجهيز', boutiqueReturned: true);

        $st = Boutiques::statement($this->shop->id, $this->lama->id, '2027-02');
        $this->assertSame(0.0, $st['components']['due'], 'المستحقُّ وعكسُه في كشفٍ واحد');
        $this->assertSame(10, (int) $this->silver->fresh()->quantity);
        $this->assertSame(['cash' => 0.0, 'sales' => 0.0, 'cogs' => 0.0, 'other' => 0.0, 'profit' => 0.0, 'payable' => 0.0, 'receivable' => 0.0], $this->books());
    }

    /** بيعةُ فبراير أُلغيت في مارس قبل ورقة فبراير: عكسُها في كشف فبراير معها — لا في مارس */
    public function test_a_reversal_of_an_unsettled_due_sits_in_the_month_of_its_sale(): void
    {
        Carbon::setTestNow('2027-02-25 10:00:00');
        $order = $this->sell($this->bouquet);

        Carbon::setTestNow('2027-03-02 10:00:00');
        $this->actingAs($this->owner);
        OrderCorrection::cancel($order->fresh(), 'أُلغي', boutiqueReturned: true);

        $feb = Boutiques::statement($this->shop->id, $this->lama->id, '2027-02')['components'];
        $mar = Boutiques::statement($this->shop->id, $this->lama->id, '2027-03')['components'];
        $this->assertSame([2, 0.0], [$feb['count'], $feb['due']], 'المستحقُّ وعكسُه معًا في فبراير');
        $this->assertSame([0, 0.0, 0.0], [$mar['count'], $mar['due'], $mar['credit_available']], 'ولا شيءَ يُنسب إلى مارس');
    }

    public function test_cancelling_when_the_piece_did_not_return_keeps_its_due_until_it_does(): void
    {
        $delivered = $this->sell($this->bouquet);
        $unknown = $this->sell($this->bouquet);
        $this->actingAs($this->owner);

        OrderCorrection::cancel($delivered->fresh(), 'سُلّمت ثمّ رُدّت', boutiqueReturned: false);
        OrderCorrection::cancel($unknown->fresh(), 'أُلغي ولم يُقل شيء');

        // المستحقُّ باقٍ ويُقال لماذا، والقطعتان ليستا على الرفّ، وثمنُهما خسارةٌ على المتجر
        $kept = BoutiqueComponentDue::whereNotNull('kept_reason')->orderBy('id')->get();
        $this->assertSame([__('أُلغي الطلب ولم تعد القطعة إلى الرفّ'), __('أُلغي الطلب ولم يُؤكَّد رجوعُ القطعة إلى الرفّ')], $kept->pluck('kept_reason')->all());
        $this->assertSame(8, (int) $this->silver->fresh()->quantity);
        $this->assertSame(['cash' => 0.0, 'sales' => 0.0, 'cogs' => 0.0, 'other' => 14.0, 'profit' => -14.0, 'payable' => 14.0, 'receivable' => 0.0], $this->books());
        $this->assertSame(14.0, Boutiques::statement($this->shop->id, $this->lama->id, '2027-02')['components']['due']);

        // ثمّ وُجدت إحداهما — يُعكس مستحقُّها وخسارتُها، وتعود إلى الرفّ
        BoutiqueDues::returned($unknown, 'وُجدت القطعة سليمة');
        $this->assertSame(9, (int) $this->silver->fresh()->quantity);
        $this->assertSame(['cash' => 0.0, 'sales' => 0.0, 'cogs' => 0.0, 'other' => 7.0, 'profit' => -7.0, 'payable' => 7.0, 'receivable' => 0.0], $this->books());
        $this->assertSame(7.0, Boutiques::statement($this->shop->id, $this->lama->id, '2027-02')['components']['due']);

        // ولا يُعكس مرّتين
        BoutiqueDues::returned($unknown, 'مرّة ثانية');
        $this->assertSame(9, (int) $this->silver->fresh()->quantity);
        $this->assertSame(7.0, $this->balance('boutique_payable'));
    }

    /* ═══════════ الإلغاء بعد السداد ═══════════ */

    public function test_cancelling_after_payment_leaves_a_credit_that_the_next_statement_offsets(): void
    {
        $order = $this->sell($this->bouquet);
        $feb = $this->settle();
        BoutiqueDues::pay($feb, 'نقدي');
        $this->assertSame(['cash' => 13.0, 'sales' => 20.0, 'cogs' => 7.0, 'other' => 0.0, 'profit' => 13.0, 'payable' => 0.0, 'receivable' => 0.0], $this->books(), 'فبراير مسدَّد');

        Carbon::setTestNow('2027-03-05 10:00:00');
        $this->actingAs($this->owner);
        OrderCorrection::cancel($order->fresh(), 'أُعيدت الباقة سليمة', boutiqueReturned: true);

        // الورقةُ القديمة كما هي، والعكسُ رصيدٌ للمتجر في 1370 — لا التزامٌ سالب
        $this->assertSame([7.0, 7.0], [(float) $feb->fresh()->components_due, (float) $feb->fresh()->components_net]);
        $this->assertNotNull($feb->fresh()->components_paid_at);
        $this->assertSame(['cash' => -7.0, 'sales' => 0.0, 'cogs' => 0.0, 'other' => 0.0, 'profit' => 0.0, 'payable' => 0.0, 'receivable' => 7.0], $this->books(), 'بعد الإلغاء');
        $credit = BoutiqueComponentDue::where('kind', BoutiqueComponentDue::CREDIT)->firstOrFail();
        $this->assertSame(-7.0, (float) $credit->amount);

        // مارس: باقتان (١٤) — والرصيدُ يُقاصّ فيُدفع ٧
        $this->sell($this->bouquet, 2);
        $st = Boutiques::statement($this->shop->id, $this->lama->id, '2027-03');
        $this->assertSame([14.0, 7.0, 7.0], [$st['components']['due'], $st['components']['credit'], $st['components']['net']]);

        $mar = $this->settle('2027-03');
        BoutiqueDues::pay($mar, 'نقدي');
        $this->assertSame(['cash' => 26.0, 'sales' => 40.0, 'cogs' => 14.0, 'other' => 0.0, 'profit' => 26.0, 'payable' => 0.0, 'receivable' => 0.0], $this->books(), 'بعد المقاصّة: ربحُ الباقتين وحدهما');
        $this->assertSame((int) $mar->id, (int) $credit->fresh()->boutique_settlement_id);

        // للمى سبعةٌ في فبراير وسبعةٌ في مارس — ثمنُ الباقتين اللتين بقيتا
        $this->assertSame(14.0, round((float) BoutiqueSettlement::where('boutique_id', $this->lama->id)->sum('components_net'), 3));
    }

    public function test_a_credit_larger_than_what_follows_waits_or_is_refunded_once(): void
    {
        $order = $this->sell($this->bouquet);
        BoutiqueDues::pay($this->settle(), 'نقدي');

        Carbon::setTestNow('2027-03-05 10:00:00');
        $this->actingAs($this->owner);
        OrderCorrection::cancel($order->fresh(), 'أُعيدت', boutiqueReturned: true);

        // مارس: دبّوسٌ بثلاثة — والرصيدُ (٧) لا يسعه، فيبقى ولا يصير الصافي سالبًا
        $this->sell($this->mini);
        $st = Boutiques::statement($this->shop->id, $this->lama->id, '2027-03');
        $this->assertSame([3.0, 0.0, 7.0, 3.0], [$st['components']['due'], $st['components']['credit'], $st['components']['credit_available'], $st['components']['net']]);

        // والبوتيكُ ردّ السبعة نقدًا: سندُ استرداد — لا ربحَ ولا إيراد
        $before = $this->books();
        $doc = BoutiqueDues::refund($this->shop, $this->lama, 'نقدي');
        $after = $this->books();
        $this->assertSame(BoutiqueSettlement::REFUND, $doc->kind);
        $this->assertSame(7.0, (float) $doc->components_credit);
        $this->assertSame(round($before['cash'] + 7, 3), $after['cash']);
        $this->assertSame(0.0, $after['receivable']);
        $this->assertSame($before['profit'], $after['profit'], 'الاستردادُ لا يُضاعف الربح');

        $this->assertSame(0.0, Boutiques::statement($this->shop->id, $this->lama->id, '2027-03')['components']['credit_available']);
        $this->expectException(SettlementRefused::class);
        BoutiqueDues::refund($this->shop, $this->lama, 'نقدي');
    }

    /* ═══════════ سعرٌ تغيّر والزبونُ عند البنك ═══════════ */

    private function cardOn(): void
    {
        PaymentGateway::create([
            'business_id' => $this->shop->id, 'provider' => PaymentGateway::PAYMOB,
            'public_key' => 'pk_test_x', 'secret_key' => 'sk_test_x',
            'hmac_secret' => 'hmac_secret_value', 'card_integration_id' => '4569876',
            'active' => true,
        ]);
        Http::fake(['oman.paymob.com/v1/intention/' => Http::response(['client_secret' => 'csk_x', 'intention_order_id' => '777'], 201)]);
    }

    private function form(string $pay, array $extra = []): array
    {
        return [
            'items' => [['id' => $this->bouquet->id, 'qty' => 1]],
            'fulfil' => 'pickup', 'pay' => $pay, 'name' => 'مريم', 'phone' => '96899110001',
            'date' => '2027-02-12', 'slot' => '9 ص – 12 م', ...$extra,
        ];
    }

    public function test_a_price_changed_while_the_customer_pays_keeps_the_due_that_was_checked(): void
    {
        $this->cardOn();
        $this->postJson('/s/ribbon/checkout', $this->form('card'))->assertOk();

        $intent = StorePaymentIntent::where('business_id', $this->shop->id)->firstOrFail();
        $this->assertSame([$this->silver->id => ['price' => 7.0, 'boutique_id' => $this->lama->id]],
            array_map(fn ($p) => ['price' => (float) $p['price'], 'boutique_id' => (int) $p['boutique_id']], (array) $intent->payload[WebCheckout::BOUTIQUE_PRICES]));

        // والزبونُ عند البنك: الفضّةُ صارت بخمسةٍ وعشرين — أكثرَ من الباقة كلِّها
        $this->silver->update(['price' => 25]);
        $intent->update(['status' => StorePaymentIntent::PAID, 'provider_transaction_id' => 'tx-1']);

        $order = WebCheckout::place($this->shop, (array) $intent->payload, 'ar', paid: true, intent: $intent->fresh());

        $this->assertSame(20.0, (float) $order->total);
        $this->assertSame(7.0, BoutiqueDues::owedFor($order), 'ما فُحص قبل البنك هو ما كُتب بعده');
        $this->assertSame(7.0, $this->balance('boutique_payable'));
    }

    public function test_a_paid_intent_without_a_pinned_price_still_becomes_an_order(): void
    {
        // نيّةٌ فُتحت قبل التثبيت — وسعرٌ لا يغطّيه الطلب: لا يُردّ مالٌ قُبض
        $this->silver->update(['price' => 25]);
        $intent = StorePaymentIntent::create([
            'business_id' => $this->shop->id, 'reference' => 'WEB-old', 'status' => StorePaymentIntent::PAID,
            'provider_transaction_id' => 'tx-old', 'amount' => 20, 'currency' => 'OMR',
            'payload' => $this->form('card'), 'lang' => 'ar',
        ]);

        $order = WebCheckout::place($this->shop, (array) $intent->payload, 'ar', paid: true, intent: $intent);

        $this->assertSame(20.0, (float) $order->total);
        $this->assertSame(25.0, BoutiqueDues::owedFor($order), 'يُكتب بسعره ويُرى في الكشف — ولا يُترك المالُ بلا طلب');
    }

    public function test_a_price_sent_by_the_browser_is_never_read(): void
    {
        $this->postJson('/s/ribbon/checkout', $this->form('cod', [
            WebCheckout::BOUTIQUE_PRICES => [$this->silver->id => ['price' => 1, 'boutique_id' => $this->lama->id]],
        ]))->assertOk();

        $order = Order::where('business_id', $this->shop->id)->where('channel', 'website')->firstOrFail();
        $this->assertSame(7.0, BoutiqueDues::owedFor($order));
    }
}
