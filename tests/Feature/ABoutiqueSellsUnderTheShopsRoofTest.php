<?php

namespace Tests\Feature;

use App\Models\Boutique;
use App\Models\BoutiqueSettlement;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\JournalEntry;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Support\Boutiques;
use App\Support\Ledger;
use App\Support\MarketingSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * بوتيكٌ يبيع تحت سقف المحلّ — والمحلُّ يأخذ نسبتَه ويدفع الباقي.
 *
 * ═══ ما يحرسه هذا الملفّ ═══
 *
 *  · البيعُ يقع كما يقع اليوم: الصندوقُ والموقعُ والمخزونُ والدفتر بلا تغيير.
 *  · والبندُ يذكر صاحبَه **ونسبتَه ساعةَ بيعه** — فرفعُ النسبة غدًا لا
 *    يُعيد حسبةَ ما بِيع أمس. وهو المال الذي اتُّفق عليه.
 *  · والبضاعةُ أمانة: لا تكلفةَ بضاعةٍ مباعة لها — وإلّا حُسب ثمنُها مرّتين.
 *  · والتسويةُ تدخل «المبالغ المستحقة» من بابها، ولا تُصدَر مرّتين لشهر.
 *  · ومن لا يُؤوي بوتيكاتٍ لا يرى الشاشةَ ولا يُكتب على بنوده شيء.
 */
class ABoutiqueSellsUnderTheShopsRoofTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private User $owner;

    private Boutique $boutique;

    private Product $theirs;

    private Product $ours;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2027-02-10 10:00:00');
        $this->app->setLocale('ar');

        $this->shop = Business::create([
            'name' => 'RIBBON', 'type' => 'محل ورد', 'status' => 'نشط', 'phone' => '96895259066',
            'city' => 'مسقط', 'site_slug' => 'ribbon', 'tier' => 'gold', 'storefront_theme' => 'ribbon',
            'boutiques_enabled' => true,
        ]);
        Currency::create(['business_id' => $this->shop->id, 'code' => 'OMR', 'name' => 'ريال عماني', 'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);
        Ledger::seedChart($this->shop->id);
        Branch::create(['business_id' => $this->shop->id, 'name' => 'الخوير']);
        Setting::create(['business_id' => $this->shop->id, 'key' => 'vat_enabled', 'value' => '0']);
        Setting::create(['business_id' => $this->shop->id, 'key' => 'pay_credit', 'value' => '1']);

        $this->owner = User::create(['business_id' => $this->shop->id, 'name' => 'سعود', 'email' => 'saud@abaad.om', 'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط']);

        MarketingSettings::save($this->shop->id, 'website', [
            'store_on' => '1', 'store_pay_cod' => '1', 'store_delivery_fee' => '0',
            'store_delivery_areas' => 'الخوير', 'store_delivery_slots' => "9 ص – 12 م",
        ]);

        $this->boutique = Boutique::create([
            'business_id' => $this->shop->id, 'name' => 'بوتيك لمى',
            'commission_rate' => 20, 'active' => true,
        ]);

        // صنفُ البوتيك — تكلفتُه مكتوبةٌ في بطاقته ولا تُكتب على بنده
        $this->theirs = $this->product('عطر لمى', 50, cost: 30, boutique: $this->boutique->id);
        $this->ours = $this->product('باقة ورد', 20, cost: 8);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function product(string $name, float $price, float $cost, ?int $boutique = null): Product
    {
        return Product::create([
            'business_id' => $this->shop->id, 'name' => $name, 'price' => $price, 'cost' => $cost,
            'quantity' => 100, 'alert_qty' => 1, 'active' => true, 'published' => true,
            'boutique_id' => $boutique,
        ]);
    }

    /**
     * يُطوى الشهرُ فيصير يُسوَّى.
     *
     * البيعُ كلُّه يقع في العاشر من شباط، والتسويةُ لا تُصدَر إلّا بعد
     * آخر يومٍ فيه (`Boutiques::isClosed`) — فتُقدَّم الساعةُ إلى آذار.
     */
    private function monthEnds(): void
    {
        Carbon::setTestNow('2027-03-02 09:00:00');
    }

    private function sellAtTill(Product $p, int $qty = 1): Order
    {
        $this->actingAs($this->owner)->postJson('/pos/checkout', [
            'items' => [['id' => $p->id, 'name' => $p->name, 'qty' => $qty, 'price' => (float) $p->price]],
            'payment_method' => 'نقدي',
        ])->assertOk();

        return Order::where('business_id', $this->shop->id)->orderByDesc('id')->firstOrFail();
    }

    /* ═══════════ اللقطةُ على البند ═══════════ */

    /** البندُ يذكر صاحبَه ونسبتَه — وصنفُ المحلّ لا يذكر شيئًا */
    public function test_a_sold_line_remembers_its_boutique_and_its_rate(): void
    {
        $order = $this->sellAtTill($this->theirs, 2);
        $item = OrderItem::where('order_id', $order->id)->firstOrFail();

        $this->assertSame($this->boutique->id, (int) $item->boutique_id);
        $this->assertSame('بوتيك لمى', $item->boutique_name);
        $this->assertSame('20.00', (string) $item->boutique_rate);

        // وصنفُ المحلّ يبقى كما كان — لا عمودَ يُملأ ولا تكلفةَ تُمحى
        $mine = OrderItem::where('order_id', $this->sellAtTill($this->ours)->id)->firstOrFail();
        $this->assertNull($mine->boutique_id);
        $this->assertNull($mine->boutique_rate);
        $this->assertSame(8.0, (float) $mine->cost);
    }

    /**
     * ورفعُ النسبة اليوم لا يُعيد حسبةَ ما بِيع أمس.
     *
     * وهو أخطرُ ما في الباب: بوتيكٌ تُرفع نسبتُه يُطالَب بمالٍ لم يتّفق
     * عليه، وكشفُ حسابٍ صدر يصير كذبًا بلا أن يمسّه أحد.
     */
    public function test_raising_the_rate_does_not_rewrite_what_was_already_sold(): void
    {
        $this->sellAtTill($this->theirs, 1);

        $before = Boutiques::statement($this->shop->id, $this->boutique->id, '2027-02');
        $this->assertSame(10.0, $before['commission']);

        $this->boutique->update(['commission_rate' => 50]);

        $after = Boutiques::statement($this->shop->id, $this->boutique->id, '2027-02');
        $this->assertSame(10.0, $after['commission'], 'حسبةُ ما مضى تغيّرت');

        // وما يُباع بعدها يأخذ الجديدة — والكشفُ يفصلهما سطرين
        $this->sellAtTill($this->theirs, 1);
        $now = Boutiques::statement($this->shop->id, $this->boutique->id, '2027-02');

        $this->assertSame(35.0, $now['commission'], '١٠ بالقديمة و٢٥ بالجديدة');
        $this->assertSame(2, $now['lines_count'], 'سطران لا سطر: النسبتان لا تُجمعان');
    }

    /* ═══════════ الأمانة لا تُكلّف ═══════════ */

    /**
     * بضاعةُ البوتيك لا تُكتب لها تكلفةُ بضاعةٍ مباعة.
     *
     * وإلّا حُسب ثمنُها مرّتين: تكلفةً يومَ البيع ومصروفًا يومَ التسوية —
     * فيُقرأ الربحُ أقلَّ ممّا هو بكلّ قطعةٍ تُباع.
     */
    public function test_consigned_goods_carry_no_cost_of_sales(): void
    {
        $order = $this->sellAtTill($this->theirs, 2);

        $this->assertSame(0.0, (float) OrderItem::where('order_id', $order->id)->value('cost'));

        // وقيدُ التكلفة لا يُكتب أصلًا — قيدُ البيع وحده
        $entries = JournalEntry::where('sourceable_type', Order::class)
            ->where('sourceable_id', $order->id)->pluck('source')->all();

        $this->assertSame(['مبيعات'], $entries, 'كُتب قيدُ تكلفةٍ لبضاعةٍ ليست له');

        // والرفُّ يُخصم كالمعتاد — ليُعرف ما نفد
        $this->assertSame(98, (int) $this->theirs->fresh()->quantity);
    }

    /**
     * ولا تُقرأ تكلفتُها من بطاقتها ولو كانت لقطةُ البند صفرًا.
     *
     * و`Books::costOf` تسقط إلى البطاقة حين تكون اللقطةُ صفرًا — قاعدةٌ
     * وُضعت لطلباتٍ قديمة لم تُكتب لقطتُها. وهي التي كانت تُكلّف الأمانة:
     * فيُفحص جوابُها مباشرةً لا أثرُه في القيد وحده.
     */
    public function test_the_card_cost_is_not_borrowed_for_consigned_goods(): void
    {
        $order = $this->sellAtTill($this->theirs, 2);

        // بطاقةُ الصنف تقول ٣٠ — والطلبُ لا يُكلّف شيئًا
        $this->assertSame(30.0, (float) $this->theirs->fresh()->cost);
        $this->assertSame(0.0, \App\Support\Books::costOf($order->fresh()));

        // وصنفُ المحلّ يستعير من بطاقته كما كان: ٨ × ٣
        $mine = $this->sellAtTill($this->ours, 3);
        \App\Models\OrderItem::where('order_id', $mine->id)->update(['cost' => 0]);
        $this->assertSame(24.0, \App\Support\Books::costOf($mine->fresh()));
    }

    /** وصنفُ المحلّ يبقى له قيدُ تكلفته — فلا يُعمَّم الاستثناء */
    public function test_the_shops_own_goods_keep_their_cost_entry(): void
    {
        $order = $this->sellAtTill($this->ours, 1);

        $sources = JournalEntry::where('sourceable_type', Order::class)
            ->where('sourceable_id', $order->id)->pluck('source')->all();

        $this->assertContains('تكلفة مبيعات', $sources);
    }

    /* ═══════════ الموقعُ يكتب ما يكتبه الصندوق ═══════════ */

    /** والبيعُ من موقع سعود يُنسب كما يُنسب بيعُ المنضدة */
    public function test_the_website_attributes_the_sale_exactly_as_the_till_does(): void
    {
        $this->postJson('/s/ribbon/checkout', [
            'items' => [['id' => $this->theirs->id, 'qty' => 3]],
            'fulfil' => 'pickup', 'pay' => 'cod', 'name' => 'مريم', 'phone' => '96899110001',
            'date' => '2027-02-12', 'slot' => '9 ص – 12 م',
        ])->assertOk();

        $item = OrderItem::whereIn('order_id', Order::where('channel', 'website')->select('id'))->firstOrFail();

        $this->assertSame($this->boutique->id, (int) $item->boutique_id);
        $this->assertSame('20.00', (string) $item->boutique_rate);
        $this->assertSame(0.0, (float) $item->cost, 'الأمانةُ لا تُكلّف على الموقع أيضًا');
    }

    /* ═══════════ كشفُ الحساب ═══════════ */

    /** الكشفُ يجمع الكميّات والمبيعات والعمولة والصافي — والملغى يخرج */
    public function test_the_statement_counts_what_was_sold_and_not_what_was_cancelled(): void
    {
        $this->sellAtTill($this->theirs, 2);   // ١٠٠
        $this->sellAtTill($this->ours, 5);     // ليست له
        $cancelled = $this->sellAtTill($this->theirs, 4);
        $cancelled->update(['status' => Order::CANCELLED]);

        $s = Boutiques::statement($this->shop->id, $this->boutique->id, '2027-02');

        $this->assertSame(100.0, $s['gross'], 'الملغاةُ دخلت المجموع');
        $this->assertSame(20.0, $s['commission']);
        $this->assertSame(80.0, $s['net']);
        $this->assertSame(2.0, $s['quantity']);
        $this->assertSame(1, $s['lines_count'], 'صنفُ المحلّ دخل كشفَ البوتيك');

        // والشهرُ الآخر فارغ — لا تتسرّب بيعةٌ بين الشهور
        $this->assertSame(0.0, Boutiques::statement($this->shop->id, $this->boutique->id, '2027-01')['gross']);
    }

    /* ═══════════ التسوية ═══════════ */

    /** التسويةُ تُصدَر مستندًا ومصروفًا يدخل «المبالغ المستحقة» */
    public function test_a_settlement_becomes_a_due_the_shop_owes(): void
    {
        $this->sellAtTill($this->theirs, 2);
        $this->monthEnds();

        $this->actingAs($this->owner)
            ->post(route('admin.boutiques.settle', $this->boutique->id), ['period' => '2027-02'])
            ->assertSessionHasNoErrors();

        $settlement = BoutiqueSettlement::firstOrFail();
        $this->assertSame('BQ-2027-02-1', $settlement->number);
        $this->assertSame('100.000', (string) $settlement->gross);
        $this->assertSame('20.000', (string) $settlement->commission);
        $this->assertSame('80.000', (string) $settlement->net);

        // والمالُ يخرج من بابه القائم: مصروفٌ غيرُ مدفوع بصافي ما له
        $expense = Expense::findOrFail($settlement->expense_id);
        $this->assertSame(80.0, (float) $expense->amount);
        $this->assertSame(Expense::UNPAID, $expense->status);
        $this->assertSame(Boutiques::EXPENSE_TYPE, $expense->type);
        $this->assertSame('BQ-2027-02-1', $expense->reference);

        // فيُقرأ في «المبالغ المستحقة» — لا في شاشةٍ ثانية تُبنى له
        $dues = $this->actingAs($this->owner)->get(route('admin.finance.dues'))
            ->assertOk()->viewData('page')['props']['expenses'];

        $this->assertContains('BQ-2027-02-1', array_column($dues, 'reference'));
    }

    /**
     * ولا تُصدَر مرّتين لشهرٍ واحد.
     *
     * ضغطتان على «أصدِر» تُخرجان تسويتين، فيُطالَب المحلُّ بالمبلغ مرّتين —
     * وهو أكثرُ ما يقع في شاشةٍ تُفتح آخرَ الشهر.
     */
    public function test_a_month_is_settled_once(): void
    {
        $this->sellAtTill($this->theirs, 1);
        $this->monthEnds();

        $settle = fn () => $this->actingAs($this->owner)
            ->post(route('admin.boutiques.settle', $this->boutique->id), ['period' => '2027-02']);

        $settle()->assertSessionHasNoErrors();

        /*
         * والرسالةُ تُقرأ لا يُكتفى بوجود خطأ.
         *
         * `QueryException` ترث `RuntimeException`، فمتحكّمٌ يلتقط الثانية
         * يلتقط الأولى معها — فيمرّ الفحصُ «فيه خطأ» ويقرأ التاجرُ
         * «SQLSTATE[23000]…» في موضع الجواب. ولهذا يُوازَن النصّ.
         */
        $settle()->assertSessionHasErrors([
            'settlement' => 'تسويةُ هذا الشهر صدرت من قبل — تُفتح ولا تُصدَر ثانيةً.',
        ]);

        $this->assertSame(1, BoutiqueSettlement::count());
        $this->assertSame(1, Expense::where('type', Boutiques::EXPENSE_TYPE)->count());
    }

    /**
     * وعطبٌ في القاعدة ليس جوابًا — يصعد ولا يُعرض للتاجر.
     *
     * فرعُ الترجمة يقرأ اسمَ جدول التسويات في نصّ الخطأ ليقول كلمةً
     * مفهومة. ولو ترجم كلَّ خطأٍ لَصار عطبٌ في جدولٍ آخر «تسويةُ هذا الشهر
     * صدرت من قبل» — فيبحث التاجر عن ورقةٍ لا وجود لها، ويُخفى العطبُ عن
     * السجلّ لأنّه عُولج كأنّه جواب.
     */
    public function test_a_failure_elsewhere_in_the_books_is_not_dressed_as_a_refusal(): void
    {
        $this->sellAtTill($this->theirs, 1);
        $this->monthEnds();

        // مصروفٌ يحمل المرجعَ نفسَه، وفهرسٌ يمنع تكرارَه — فيسقط إنشاءُ المصروف
        \Illuminate\Support\Facades\Schema::table('expenses', fn ($t) => $t->unique('reference'));
        Expense::create([
            'business_id' => $this->shop->id, 'type' => 'إيجار', 'amount' => 1,
            'reference' => 'BQ-2027-02-1', 'status' => Expense::UNPAID, 'spent_at' => '2027-02-01',
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        try {
            Boutiques::settle($this->shop, $this->boutique, '2027-02');
        } finally {
            \Illuminate\Support\Facades\Schema::table('expenses', fn ($t) => $t->dropUnique(['reference']));
        }
    }

    /**
     * وشهرٌ ما زال يبيع لا يُسوَّى.
     *
     * ═══ وهو أخطرُ ما في التسوية ═══
     *
     * الورقةُ تُجمّد الأرقام، والفهرسُ الفريد يمنع ثانيةً لشهرٍ واحد. فمن
     * ضغط «أصدِر» في العاشر أخذ عشرةَ أيّام وأغلق البابَ على عشرين: ما
     * يُباع في بقيّة الشهر يبقى في الكشف حيًّا ولا تحمله ورقةٌ أبدًا — ولا
     * يختلّ ميزانٌ ولا تصرخ شاشة. يضيع مالُ البوتيك صامتًا.
     */
    public function test_a_month_still_selling_is_not_settled(): void
    {
        $this->sellAtTill($this->theirs, 1);

        // العاشرُ من شباط — والشهرُ هو شباط
        $this->actingAs($this->owner)
            ->post(route('admin.boutiques.settle', $this->boutique->id), ['period' => '2027-02'])
            ->assertSessionHasErrors([
                'settlement' => 'الشهرُ لم ينتهِ بعد — تُصدَر تسويتُه بعد آخر يومٍ فيه، وإلّا ضاع ما يُباع في بقيّته.',
            ]);

        $this->assertSame(0, BoutiqueSettlement::count(), 'صدرت ورقةٌ لشهرٍ ما زال يبيع');
        $this->assertSame(0, Expense::where('type', Boutiques::EXPENSE_TYPE)->count());

        // ولا شهرٌ لم يأتِ بعد
        $this->actingAs($this->owner)
            ->post(route('admin.boutiques.settle', $this->boutique->id), ['period' => '2027-09'])
            ->assertSessionHasErrors('settlement');

        // فإذا طُوي الشهرُ صدرت — وحملت ما بِيع فيه كلَّه
        $this->monthEnds();
        $this->actingAs($this->owner)
            ->post(route('admin.boutiques.settle', $this->boutique->id), ['period' => '2027-02'])
            ->assertSessionHasNoErrors();

        $this->assertSame('50.000', (string) BoutiqueSettlement::firstOrFail()->gross);
    }

    /** والشاشةُ تقول ذلك قبل الضغط — لا يُعرض زرٌّ حيٌّ يُردّ */
    public function test_the_screen_says_the_month_is_still_open(): void
    {
        $this->sellAtTill($this->theirs, 1);

        $props = fn (string $period) => $this->actingAs($this->owner)
            ->get(route('admin.boutiques.show', $this->boutique->id).'?period='.$period)
            ->assertOk()->viewData('page')['props'];

        $this->assertFalse($props('2027-02')['closed'], 'الشهرُ الجاري قيل عنه إنّه أُغلق');
        $this->assertTrue($props('2027-01')['closed'], 'شهرٌ مضى قيل عنه إنّه مفتوح');
    }

    /** وشهرٌ بلا بيعٍ لا تُصدَر له ورقة */
    public function test_a_month_without_sales_has_nothing_to_settle(): void
    {
        $this->actingAs($this->owner)
            ->post(route('admin.boutiques.settle', $this->boutique->id), ['period' => '2027-01'])
            ->assertSessionHasErrors('settlement');

        $this->assertSame(0, BoutiqueSettlement::count());
    }

    /* ═══════════ البابُ مغلقٌ على من لم يُفتح له ═══════════ */

    /** ومن لا يُؤوي بوتيكاتٍ لا يرى الشاشة — ولا يُكتب على بنوده شيء */
    public function test_a_shop_that_hosts_none_sees_none(): void
    {
        $this->shop->update(['boutiques_enabled' => false]);

        $this->actingAs($this->owner)->get(route('admin.boutiques.index'))->assertNotFound();
        $this->actingAs($this->owner)->get(route('admin.boutiques.show', $this->boutique->id))->assertNotFound();
        $this->actingAs($this->owner)
            ->post(route('admin.boutiques.settle', $this->boutique->id), ['period' => '2027-02'])
            ->assertNotFound();
    }

    /** والنسبةُ لا تتجاوز المئة — وإلّا صار الصافي دَينًا على البوتيك */
    public function test_a_rate_above_a_hundred_is_refused(): void
    {
        $this->actingAs($this->owner)
            ->post(route('admin.boutiques.store'), ['name' => 'بوتيك', 'commission_rate' => 120])
            ->assertSessionHasErrors('commission_rate');

        $this->assertSame(1, Boutique::count());
    }

    /** وبوتيكٌ عليه تسويةٌ لم تُسدَّد لا يُحذف — ولا تُمحى أصنافُه بحذفه */
    public function test_deleting_leaves_the_goods_on_the_shelf(): void
    {
        $this->sellAtTill($this->theirs, 1);
        $this->monthEnds();
        $this->actingAs($this->owner)->post(route('admin.boutiques.settle', $this->boutique->id), ['period' => '2027-02']);

        $this->actingAs($this->owner)->delete(route('admin.boutiques.destroy', $this->boutique->id))
            ->assertSessionHasErrors('boutique');

        Expense::query()->update(['status' => Expense::PAID]);

        $this->actingAs($this->owner)->delete(route('admin.boutiques.destroy', $this->boutique->id))
            ->assertSessionHasNoErrors();

        $this->assertNull($this->theirs->fresh()->boutique_id, 'الصنفُ صار للمحلّ');
        $this->assertNotNull($this->theirs->fresh(), 'مُحي صنفٌ على الرفّ');
        // والبيعةُ تحمل اسمَه لقطةً فلا تفقد معناها
        $this->assertSame('بوتيك لمى', OrderItem::whereNotNull('boutique_name')->value('boutique_name'));
    }
}
