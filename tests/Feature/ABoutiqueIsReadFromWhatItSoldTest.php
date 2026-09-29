<?php

namespace Tests\Feature;

use App\Models\Boutique;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Expense;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Support\Boutiques;
use App\Support\Demo;
use App\Support\Document\Pdf\Driver as PdfDriver;
use App\Support\Ledger;
use App\Support\Pdf;
use App\Support\Reports;
use App\Support\SalesChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * البوتيكُ يُقرأ ممّا بِيع له — لا ممّا يملكه اليوم.
 *
 * ═══ ما يحرسه هذا الملفّ ═══
 *
 *  · الصنفُ يُسند إلى بوتيكٍ من شاشته، ولا يُقبل بوتيكُ متجرٍ آخر.
 *  · وفكُّ الربط يمسّ `boutique_id` وحده — لا سعرًا ولا كميّةً ولا بيعةً قديمة.
 *  · وقائمةُ المنتجات تقول لمن الصنف، وتُرشِّح به في الخادم.
 *  · وتقريرُ البوتيك من **بنوده** ولقطتها ساعةَ البيع: فاتورةٌ تجمع صنفَ
 *    المحلّ وصنفَ بوتيكين لا يُنسب مجموعُها إلى أحدهم، ونقلُ الصنف أو رفعُ
 *    النسبة غدًا لا يُغيّر رقمًا من الماضي.
 *  · وما على الطلب كاملًا (الضريبة، الدفع، الربح) لا يُعرض بنطاق بوتيك.
 *  · والشاشةُ والتغذيةُ والملفّاتُ الثلاثة من مصدرٍ واحد، بالنطاق نفسه.
 *  · و«كل المبيعات» — ومتجرٌ لا بوتيكَ عنده — كما كانا رقمًا برقم.
 *  · ومستحقُّ التسوية يُسمّي بوتيكه من العلاقة.
 */
class ABoutiqueIsReadFromWhatItSoldTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private User $owner;

    private Branch $branch;

    private Boutique $lama;

    private Boutique $noor;

    private Product $ours;

    private Product $lamaPerfume;

    private Product $noorScarf;

    /** متجرٌ آخر وبوتيكُه — لا يُرى من هنا */
    private Business $neighbour;

    private Boutique $foreign;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2027-02-10 10:00:00');
        $this->app->setLocale('ar');

        $this->shop = Business::create([
            'name' => 'محلّ الاختبار', 'type' => 'محل ورد', 'status' => 'نشط',
            'boutiques_enabled' => true,
        ]);
        Currency::create(['business_id' => $this->shop->id, 'code' => 'OMR', 'name' => 'ريال عماني', 'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);
        Ledger::seedChart($this->shop->id);
        $this->branch = Branch::create(['business_id' => $this->shop->id, 'name' => 'الخوير']);
        Setting::create(['business_id' => $this->shop->id, 'key' => 'vat_enabled', 'value' => '0']);

        $this->owner = User::create([
            'business_id' => $this->shop->id, 'name' => 'المالك', 'email' => 'owner@test.local',
            'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->lama = Boutique::create(['business_id' => $this->shop->id, 'name' => 'بوتيك لمى', 'commission_rate' => 20, 'active' => true]);
        $this->noor = Boutique::create(['business_id' => $this->shop->id, 'name' => 'بوتيك نور', 'commission_rate' => 10, 'active' => true]);

        $this->ours = $this->product($this->shop, 'باقة ورد', 20, cost: 8);
        $this->lamaPerfume = $this->product($this->shop, 'عطر لمى', 50, cost: 30, boutique: $this->lama->id);
        $this->noorScarf = $this->product($this->shop, 'وشاح نور', 30, cost: 12, boutique: $this->noor->id);

        $this->neighbour = Business::create(['name' => 'الجار', 'type' => 'عام', 'status' => 'نشط', 'boutiques_enabled' => true]);
        $this->foreign = Boutique::create(['business_id' => $this->neighbour->id, 'name' => 'بوتيك الجار السرّي', 'commission_rate' => 30, 'active' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function product(Business $b, string $name, float $price, float $cost, ?int $boutique = null): Product
    {
        return Product::create([
            'business_id' => $b->id, 'name' => $name, 'price' => $price, 'cost' => $cost,
            'quantity' => 100, 'alert_qty' => 1, 'active' => true, 'published' => true,
            'boutique_id' => $boutique,
        ]);
    }

    /** فاتورةٌ واحدة على الصندوق — بالأصناف والكميّات المعطاة */
    private function sellAtTill(array $lines): Order
    {
        $this->actingAs($this->owner)->postJson('/pos/checkout', [
            'items' => array_map(fn ($l) => [
                'id' => $l[0]->id, 'name' => $l[0]->name, 'qty' => $l[1], 'price' => (float) $l[0]->price,
            ], $lines),
            'payment_method' => 'نقدي',
        ])->assertOk();

        return Order::where('business_id', $this->shop->id)->orderByDesc('id')->firstOrFail();
    }

    /**
     * بيعةٌ مكتوبةٌ مباشرةً بفرعها وقناتها ووقتها — لما لا يبلغه الصندوق.
     *
     * والبندُ يحمل لقطتَه كما يكتبها البائعان (`Boutiques::itemColumns`).
     */
    private function sale(Product $p, float $total, ?Branch $branch = null, ?string $channel = SalesChannel::POS, ?Carbon $at = null): Order
    {
        $order = Order::create([
            'business_id' => $this->shop->id, 'branch_id' => ($branch ?? $this->branch)->id,
            'customer_name' => 'زبون', 'employee_name' => 'المالك', 'user_id' => $this->owner->id,
            'number' => 'INV-'.(Order::count() + 1), 'status' => 'مكتمل',
            'payment_status' => 'مدفوع', 'is_held' => false, 'payment_method' => 'نقدي',
            'channel' => $channel, 'subtotal' => $total, 'discount' => 0, 'tax' => 0, 'total' => $total,
            'ordered_at' => $at ?? now(),
        ]);

        $at = Boutiques::attribute($this->shop->id, [['product' => $p]])[0];

        OrderItem::create([
            'order_id' => $order->id, 'product_id' => $p->id, 'name' => $p->name,
            'price' => $total, 'quantity' => 1, 'total' => $total,
        ] + Boutiques::itemColumns($at, (float) $p->cost));

        return $order;
    }

    private function report(mixed $boutique = null, ?string $channel = null, string $range = 'month'): array
    {
        $this->actingAs($this->owner);

        return Reports::salesReport($range, $channel, $boutique);
    }

    /** ما يُرسل في حفظة المنتج — كما يرسله النموذج */
    private function form(Product $p, array $over = []): array
    {
        return array_merge([
            'name' => $p->name, 'price' => (float) $p->price, 'cost' => (float) $p->cost,
            'quantity' => (int) $p->quantity, 'alert_qty' => (int) $p->alert_qty,
        ], $over);
    }

    /* ═══════════════ متجرٌ بلا بوتيكات ═══════════════ */

    /** لا حقلَ ولا عمودَ ولا مُرشِّح — والتقريرُ كما كان ولو كُتب المُرشِّحُ بيده */
    public function test_a_shop_without_boutiques_sees_nothing_change(): void
    {
        $plain = Business::create(['name' => 'متجر عادي', 'type' => 'عام', 'status' => 'نشط']);
        Branch::create(['business_id' => $plain->id, 'name' => 'الرئيسي']);
        $user = User::create(['business_id' => $plain->id, 'name' => 'صاحبه', 'email' => 'plain@test.local', 'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط']);
        $item = $this->product($plain, 'قلم', 5, cost: 1);

        $this->actingAs($user)->get(route('admin.products.create'))
            ->assertInertia(fn (Assert $p) => $p->where('boutiques', []));
        $this->actingAs($user)->get(route('admin.products.edit', $item->id))
            ->assertInertia(fn (Assert $p) => $p->where('boutiques', [])->where('boutiqueId', null));
        $this->actingAs($user)->get(route('admin.products.index', ['boutique' => 'own']))
            ->assertInertia(fn (Assert $p) => $p->where('boutiques', [])
                ->has('products', 1)
                ->missing('products.0.boutique'));

        $this->actingAs($user);
        $all = Reports::salesReport('month', null);
        $forged = Reports::salesReport('month', null, 'own');

        $this->assertNull($forged['boutique']);
        $this->assertNull($forged['boutiqueTotals']);
        $this->assertSame($all['summary'], $forged['summary']);
        $this->assertSame([], $forged['boutiques']);

        // وملفُّه بلا سطر نطاق — يبقى كما كان
        $csv = $this->actingAs($user)->get(route('admin.export.reports', ['boutique' => 'own']))->streamedContent();
        $this->assertStringNotContainsString('نطاق التقرير', $csv);
    }

    /* ═══════════════ الصنفُ وبوتيكُه ═══════════════ */

    /** الحقلُ يعرض بوتيكات هذا المتجر وحده — والتعديلُ يُظهر الحاليّ محدَّدًا */
    public function test_the_form_offers_only_this_shops_boutiques(): void
    {
        $this->actingAs($this->owner)->get(route('admin.products.create'))
            ->assertInertia(fn (Assert $p) => $p
                ->where('boutiques', fn ($list) => collect($list)->pluck('value')->sort()->values()->all()
                    === collect([$this->lama->id, $this->noor->id])->sort()->values()->all()));

        $this->actingAs($this->owner)->get(route('admin.products.edit', $this->lamaPerfume->id))
            ->assertInertia(fn (Assert $p) => $p->where('boutiqueId', $this->lama->id));

        $this->actingAs($this->owner)->get(route('admin.products.edit', $this->ours->id))
            ->assertInertia(fn (Assert $p) => $p->where('boutiqueId', null));
    }

    public function test_a_new_product_is_created_under_a_boutique(): void
    {
        $this->actingAs($this->owner)->post(route('admin.products.store'), [
            'name' => 'حقيبة لمى', 'price' => 40, 'boutique_id' => $this->lama->id,
        ])->assertSessionHasNoErrors();

        $this->assertSame($this->lama->id, (int) Product::where('name', 'حقيبة لمى')->value('boutique_id'));
    }

    public function test_an_edit_moves_the_product_to_another_boutique(): void
    {
        $this->actingAs($this->owner)
            ->put(route('admin.products.update', $this->lamaPerfume->id), $this->form($this->lamaPerfume, ['boutique_id' => $this->noor->id]))
            ->assertSessionHasNoErrors();

        $this->assertSame($this->noor->id, (int) $this->lamaPerfume->fresh()->boutique_id);
    }

    /** بوتيكُ متجرٍ آخر يُردّ في الإنشاء وفي التعديل — ولا يُكتب شيء */
    public function test_a_boutique_of_another_shop_is_refused(): void
    {
        $this->actingAs($this->owner)->post(route('admin.products.store'), [
            'name' => 'مزوَّر', 'price' => 1, 'boutique_id' => $this->foreign->id,
        ])->assertSessionHasErrors('boutique_id');
        $this->assertFalse(Product::where('name', 'مزوَّر')->exists());

        $this->actingAs($this->owner)
            ->put(route('admin.products.update', $this->ours->id), $this->form($this->ours, ['boutique_id' => $this->foreign->id]))
            ->assertSessionHasErrors('boutique_id');
        $this->assertNull($this->ours->fresh()->boutique_id);
    }

    /**
     * فكُّ الربط يمسّ `boutique_id` وحده.
     *
     * لا اسمَ ولا سعرَ ولا كميّةَ ولا صورةَ ولا حذف — ولا بيعةً قديمة: البندُ
     * يبقى لبوتيكه ساعةَ بيعه.
     */
    public function test_detaching_nulls_the_boutique_and_nothing_else(): void
    {
        $this->lamaPerfume->update(['image' => 'products/lama.jpg']);
        $sold = $this->sellAtTill([[$this->lamaPerfume, 2]]);
        $before = Product::withTrashed()->findOrFail($this->lamaPerfume->id)->getAttributes();

        $this->actingAs($this->owner)
            ->put(route('admin.products.update', $this->lamaPerfume->id), $this->form($this->lamaPerfume->fresh(), [
                'boutique_id' => null,
            ]))
            ->assertSessionHasNoErrors();

        $after = Product::withTrashed()->findOrFail($this->lamaPerfume->id)->getAttributes();

        $this->assertNull($after['boutique_id']);
        foreach (['name', 'price', 'cost', 'quantity', 'image', 'active', 'published', 'deleted_at', 'category_id', 'sku', 'barcode'] as $col) {
            $this->assertEquals($before[$col], $after[$col], "تغيّر «{$col}» بفكّ الربط");
        }

        $line = OrderItem::where('order_id', $sold->id)->firstOrFail();
        $this->assertSame($this->lama->id, (int) $line->boutique_id, 'فكُّ الربط غيّر بيعةً قديمة');
        $this->assertSame('20.00', (string) $line->boutique_rate);
    }

    /** وحفظةٌ لا تحمل الحقلَ لا تفكّ شيئًا — شاشةٌ لا حقلَ فيها لا ترسله */
    public function test_a_save_without_the_field_keeps_the_boutique(): void
    {
        $this->actingAs($this->owner)
            ->put(route('admin.products.update', $this->lamaPerfume->id), $this->form($this->lamaPerfume, ['price' => 55]))
            ->assertSessionHasNoErrors();

        $this->assertSame($this->lama->id, (int) $this->lamaPerfume->fresh()->boutique_id);
    }

    /* ═══════════════ قائمةُ المنتجات ═══════════════ */

    public function test_the_list_names_each_products_owner(): void
    {
        $this->actingAs($this->owner)->get(route('admin.products.index'))
            ->assertInertia(fn (Assert $p) => $p
                ->where('products', fn ($rows) => collect($rows)->pluck('boutique', 'name')->all() === [
                    'وشاح نور' => 'بوتيك نور',
                    'عطر لمى' => 'بوتيك لمى',
                    'باقة ورد' => null,
                ]));
    }

    public function test_the_shop_filter_lists_only_unowned_products(): void
    {
        $this->actingAs($this->owner)->get(route('admin.products.index', ['boutique' => 'own']))
            ->assertInertia(fn (Assert $p) => $p
                ->where('products', fn ($rows) => collect($rows)->pluck('name')->all() === ['باقة ورد'])
                ->where('filters.boutique', 'own'));
    }

    /** ومُرشِّحُ بوتيكٍ يعمل مع البحث — ويبقى في الرابط */
    public function test_a_boutique_filter_lists_its_products_and_keeps_the_search(): void
    {
        $this->product($this->shop, 'عطر ليلي', 45, cost: 20, boutique: $this->lama->id);

        $this->actingAs($this->owner)
            ->get(route('admin.products.index', ['boutique' => (string) $this->lama->id, 'q' => 'ليلي']))
            ->assertInertia(fn (Assert $p) => $p
                ->where('products', fn ($rows) => collect($rows)->pluck('name')->all() === ['عطر ليلي'])
                ->where('filters.boutique', (string) $this->lama->id)
                ->where('filters.q', 'ليلي'));

        $this->actingAs($this->owner)->get(route('admin.products.index', ['boutique' => (string) $this->lama->id]))
            ->assertInertia(fn (Assert $p) => $p
                ->where('products', fn ($rows) => collect($rows)->pluck('boutique')->unique()->values()->all() === ['بوتيك لمى']));
    }

    /** وبوتيكُ الجار لا يُرشِّح شيئًا: يُقرأ «الكلّ» */
    public function test_a_foreign_boutique_filters_nothing_in_the_list(): void
    {
        $this->actingAs($this->owner)->get(route('admin.products.index', ['boutique' => (string) $this->foreign->id]))
            ->assertInertia(fn (Assert $p) => $p->has('products', 3));
    }

    /* ═══════════════ التقرير من البنود ═══════════════ */

    /**
     * فاتورةٌ واحدة: صنفُ المحلّ وصنفُ لمى وصنفُ نور.
     *
     * تقريرُ لمى يحسب سطرَها وحده — لا مجموعَ الفاتورة (١٠٠).
     */
    public function test_a_mixed_invoice_gives_each_boutique_its_own_line(): void
    {
        $order = $this->sellAtTill([[$this->ours, 1], [$this->lamaPerfume, 1], [$this->noorScarf, 1]]);
        $this->assertSame(100.0, (float) $order->total);

        $lama = $this->report((string) $this->lama->id)['boutiqueTotals'];
        $this->assertSame(50.0, $lama['gross']);
        $this->assertSame(10.0, $lama['commission']);
        $this->assertSame(40.0, $lama['net']);
        $this->assertSame(1.0, $lama['quantity']);
        $this->assertSame(1, $lama['orders']);

        $noor = $this->report((string) $this->noor->id)['boutiqueTotals'];
        $this->assertSame(30.0, $noor['gross']);
        $this->assertSame(3.0, $noor['commission']);
        $this->assertSame(27.0, $noor['net']);

        $own = $this->report('own')['boutiqueTotals'];
        $this->assertSame(20.0, $own['gross']);
        $this->assertSame(0.0, $own['commission']);

        // والمنحنى والأكثرُ مبيعًا من البنود كذلك
        $r = $this->report((string) $this->lama->id);
        $this->assertSame(50.0, round(array_sum(array_map(fn ($v) => (float) $v, $r['salesSeries']['data'])), 3));
        $this->assertSame(['عطر لمى'], array_column($r['topSellingProducts'], 'name'));
    }

    /** نقلُ الصنف بعد بيعه لا ينقل بيعتَه */
    public function test_moving_a_product_after_the_sale_does_not_move_the_sale(): void
    {
        $this->sellAtTill([[$this->lamaPerfume, 1]]);
        $this->lamaPerfume->update(['boutique_id' => $this->noor->id]);

        $this->assertSame(50.0, $this->report((string) $this->lama->id)['boutiqueTotals']['gross']);
        $this->assertSame(0.0, $this->report((string) $this->noor->id)['boutiqueTotals']['gross']);
    }

    /** رفعُ النسبة بعد البيع لا يُعيد حسبةَ ما بِيع */
    public function test_a_new_rate_does_not_reprice_past_commission(): void
    {
        $this->sellAtTill([[$this->lamaPerfume, 1]]);
        $this->lama->update(['commission_rate' => 50]);

        $t = $this->report((string) $this->lama->id)['boutiqueTotals'];
        $this->assertSame(10.0, $t['commission']);
        $this->assertSame(40.0, $t['net']);
    }

    /** الملغى خارجٌ بقاعدة النظام نفسها (`Order::scopeSold`) */
    public function test_a_cancelled_order_is_left_out(): void
    {
        $this->sellAtTill([[$this->lamaPerfume, 1]]);
        $this->sale($this->lamaPerfume, 50)->update(['status' => Order::CANCELLED]);

        $this->assertSame(50.0, $this->report((string) $this->lama->id)['boutiqueTotals']['gross']);
    }

    /** الفترةُ والقناةُ والفرعُ والبوتيكُ تتقاطع ولا يلغي أحدُها الآخر */
    public function test_range_channel_branch_and_boutique_narrow_together(): void
    {
        $salalah = Branch::create(['business_id' => $this->shop->id, 'name' => 'صلالة']);

        $this->sale($this->lamaPerfume, 50);                                                // ✓
        $this->sale($this->lamaPerfume, 7, channel: SalesChannel::WEBSITE);                 // قناةٌ أخرى
        $this->sale($this->lamaPerfume, 11, branch: $salalah);                              // فرعٌ آخر
        $this->sale($this->lamaPerfume, 13, at: now()->subMonthNoOverflow());               // شهرٌ آخر
        $this->sale($this->noorScarf, 17);                                                  // بوتيكٌ آخر
        $this->sale($this->ours, 19);                                                       // صنفُ المحلّ

        session(['current_branch' => $this->branch->id]);
        $t = $this->report((string) $this->lama->id, SalesChannel::POS)['boutiqueTotals'];
        $this->assertSame(50.0, $t['gross']);

        // وبالسنة يدخل الشهرُ الماضي — الفترةُ وحدها تغيّرت
        $this->assertSame(63.0, $this->report((string) $this->lama->id, SalesChannel::POS, 'year')['boutiqueTotals']['gross']);

        // وبلا قناةٍ يدخل الموقع
        $this->assertSame(57.0, $this->report((string) $this->lama->id)['boutiqueTotals']['gross']);

        // وبلا فرعٍ يدخل فرعُ صلالة
        session()->forget('current_branch');
        $this->assertSame(68.0, $this->report((string) $this->lama->id)['boutiqueTotals']['gross']);
    }

    /** بنطاق بوتيكٍ لا ضريبةَ ولا دفعَ ولا ربح — لا يُنسب منها شيءٌ بالظنّ */
    public function test_order_level_numbers_are_not_attributed_to_a_boutique(): void
    {
        $this->sellAtTill([[$this->ours, 1], [$this->lamaPerfume, 1]]);

        $scoped = $this->report((string) $this->lama->id);
        $this->assertNull($scoped['summary']);
        $this->assertNull($scoped['paymentDistribution']);
        $this->assertSame(['kind' => 'boutique', 'id' => $this->lama->id, 'name' => 'بوتيك لمى'], $scoped['boutique']);
        $this->assertSame(['إجمالي مبيعات البوتيك', 'عمولة المتجر', 'المستحق للبوتيك', 'الكمية المباعة', 'طلبات فيها منتجات البوتيك'],
            array_column(Reports::rowsFor($scoped), 'label'));
    }

    /** و«كل المبيعات» كما كانت رقمًا برقم — من الدوالّ نفسها بلا نطاق */
    public function test_all_sales_stay_exactly_as_before(): void
    {
        $this->sellAtTill([[$this->ours, 1], [$this->lamaPerfume, 1], [$this->noorScarf, 1]]);

        $r = $this->report();
        $this->assertNull($r['boutique']);
        $this->assertNull($r['boutiqueTotals']);
        $this->assertSame(Demo::reportSummary('month', null, null), $r['summary']);
        $this->assertSame(Demo::salesTrend('month', null, null), $r['salesSeries']);
        $this->assertSame(Demo::paymentDistribution('month', null, null), $r['paymentDistribution']);
        $this->assertSame(Demo::topSellingProducts(5, 'month', null, null), $r['topSellingProducts']);
        $this->assertSame(100.0, (float) $r['summary']['sales']);
        $this->assertSame(Reports::summaryRows($r['summary']), Reports::rowsFor($r));
    }

    /** بوتيكُ الجار لا يُطبَّق ولا يُسمّى — يُقرأ «كل المبيعات» */
    public function test_a_foreign_boutique_reveals_nothing(): void
    {
        $this->sellAtTill([[$this->lamaPerfume, 1]]);

        $r = $this->report((string) $this->foreign->id);
        $this->assertNull($r['boutique']);
        $this->assertNull($r['boutiqueTotals']);
        $this->assertSame($this->report()['summary'], $r['summary']);
        $this->assertStringNotContainsString('الجار', json_encode($r, JSON_UNESCAPED_UNICODE));

        $csv = $this->actingAs($this->owner)->get(route('admin.export.reports', ['boutique' => $this->foreign->id]))->streamedContent();
        $this->assertStringNotContainsString('الجار', $csv);
        $this->assertStringContainsString('كل المبيعات', $csv);
    }

    /* ═══════════════ الشاشةُ والتغذيةُ والملفّات ═══════════════ */

    public function test_the_page_and_the_live_feed_keep_the_boutique(): void
    {
        $this->sellAtTill([[$this->ours, 1], [$this->lamaPerfume, 1]]);

        $this->actingAs($this->owner)
            ->get(route('admin.reports.sales', ['range' => 'week', 'channel' => SalesChannel::POS, 'boutique' => $this->lama->id]))
            ->assertInertia(fn (Assert $p) => $p
                ->component('Admin/Reports/Sales')
                ->where('range', 'week')
                ->where('channel', SalesChannel::POS)
                ->where('boutique.id', $this->lama->id)
                ->where('boutiqueTotals.gross', 50));

        $this->actingAs($this->owner)
            ->getJson(route('admin.reports.feed', ['boutique' => $this->lama->id]))
            ->assertOk()
            ->assertJsonPath('boutique.id', $this->lama->id)
            ->assertJsonPath('boutiqueTotals.gross', 50)
            ->assertJsonPath('summary', null);
    }

    public function test_the_csv_carries_the_scope_and_its_numbers(): void
    {
        $this->sellAtTill([[$this->ours, 1], [$this->lamaPerfume, 1], [$this->noorScarf, 1]]);

        $csv = str_replace("\xEF\xBB\xBF", '', $this->actingAs($this->owner)
            ->get(route('admin.export.reports', ['boutique' => $this->lama->id]))->streamedContent());

        $this->assertStringContainsString('نطاق التقرير', $csv);
        $this->assertStringContainsString('بوتيك: بوتيك لمى', $csv);
        $this->assertStringContainsString('50.000', $csv);
        $this->assertStringContainsString('10.000', $csv);
        $this->assertStringContainsString('40.000', $csv);
        $this->assertStringNotContainsString('100.000', $csv, 'دخل مجموعُ الفاتورة كلِّها ملفَّ البوتيك');
        $this->assertStringContainsString('لا يُنسب إلى بوتيك', $csv);
    }

    public function test_the_xlsx_carries_the_scope_and_its_numbers(): void
    {
        $this->sellAtTill([[$this->ours, 1], [$this->lamaPerfume, 1], [$this->noorScarf, 1]]);

        $res = $this->actingAs($this->owner)->get(route('admin.reports.xlsx', ['boutique' => $this->lama->id]));
        $res->assertOk();
        $path = tempnam(sys_get_temp_dir(), 'xlsx').'.xlsx';
        file_put_contents($path, $res->streamedContent());
        $rows = IOFactory::load($path)->getActiveSheet()->toArray();
        @unlink($path);

        $text = implode("\n", array_map(fn ($r) => implode('|', array_map(fn ($c) => (string) $c, $r)), $rows));

        $this->assertStringContainsString('نطاق التقرير: بوتيك: بوتيك لمى', $text);
        $this->assertStringContainsString('إجمالي مبيعات البوتيك|50', $text);
        $this->assertStringContainsString('عمولة المتجر|10', $text);
        $this->assertStringContainsString('المستحق للبوتيك|40', $text);
        $this->assertStringNotContainsString('|100', $text, 'دخل مجموعُ الفاتورة كلِّها ورقةَ البوتيك');
        $this->assertStringNotContainsString('نقدي', $text, 'وسائلُ الدفع نُسبت إلى بوتيك');
    }

    public function test_the_pdf_carries_the_scope_and_no_payment_table(): void
    {
        $this->sellAtTill([[$this->ours, 1], [$this->lamaPerfume, 1]]);

        $fake = new class implements PdfDriver
        {
            public string $html = '';

            public function sheet(string $html, string $name, array $preset, bool $landscape = false, ?string $runningHeader = null, ?string $context = null): Response
            {
                $this->html = $html;

                return response('PDF');
            }

            public function strip(string $html, string $name, int $widthMm): Response
            {
                return response('PDF');
            }

            public function stripHeight(string $html, int $widthMm): float
            {
                return 1.0;
            }
        };

        $was = Pdf::swap($fake);
        try {
            $this->actingAs($this->owner)->get(route('admin.reports.pdf', ['boutique' => $this->lama->id]))->assertOk();
        } finally {
            Pdf::swap($was);
        }

        $this->assertStringContainsString('بوتيك: بوتيك لمى', $fake->html);
        $this->assertStringContainsString('إجمالي مبيعات البوتيك', $fake->html);
        $this->assertStringContainsString('لا يُنسب إلى بوتيك', $fake->html);
        $this->assertStringNotContainsString('صافي الربح', $fake->html);
    }

    /* ═══════════════ المستحقّات ═══════════════ */

    /** مستحقُّ التسوية يُسمّي بوتيكه — من العلاقة لا من الوصف */
    public function test_a_settlement_due_names_its_boutique(): void
    {
        $this->sellAtTill([[$this->lamaPerfume, 1]]);
        Carbon::setTestNow('2027-03-02 09:00:00');
        $settlement = Boutiques::settle($this->shop, $this->lama, '2027-02');

        // والوصفُ يُمحى — الاسمُ لا يُقرأ منه
        Expense::whereKey($settlement->expense_id)->update(['description' => 'نصٌّ لا اسمَ فيه']);
        $other = Expense::create([
            'business_id' => $this->shop->id, 'type' => 'إيجار', 'amount' => 100,
            'spent_at' => now()->toDateString(), 'status' => Expense::UNPAID,
        ]);

        $this->actingAs($this->owner)->get(route('admin.finance.dues'))
            ->assertInertia(fn (Assert $p) => $p
                ->where('expenses', fn ($rows) => collect($rows)->pluck('boutique', 'id')->all() === [
                    $settlement->expense_id => 'بوتيك لمى',
                    $other->id => null,
                ] || collect($rows)->pluck('boutique', 'id')->all() === [
                    $other->id => null,
                    $settlement->expense_id => 'بوتيك لمى',
                ]));
    }
}
