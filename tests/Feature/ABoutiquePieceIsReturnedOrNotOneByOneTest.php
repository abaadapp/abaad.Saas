<?php

namespace Tests\Feature;

use App\Models\Boutique;
use App\Models\BoutiqueComponentDue;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Order;
use App\Models\Product;
use App\Models\RecipeItem;
use App\Models\Setting;
use App\Models\User;
use App\Support\BoutiqueDues;
use App\Support\Boutiques;
use App\Support\Ledger;
use App\Support\OrderCorrection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * إلغاءُ باقةٍ فيها قطعُ بوتيك — حالُ كلّ قطعةٍ وكمّيّتها على حدة.
 *
 * ═══ ما يُحرس ═══
 *
 *   - فضّةُ «لمى» عادت وعطرُ «ريم» لم يعد: لا يُفترض أنّهما حالٌ واحدة.
 *   - قطعتان من صنفٍ واحد: إحداهما عادت والأخرى لم يُتحقَّق منها.
 *   - ما عاد يعود إلى الرفّ مرّةً واحدة ويُعكس مستحقُّه. وما لم يعد يبقى
 *     مستحقُّه للبوتيك وخسارتُه على المتجر بقيدٍ واحد.
 *   - الإرجاعُ المتأخّر، وتأكيدُ أنّ ما لم يُتحقَّق منه لن يعود.
 *   - الإلغاءُ بعد سداد التسوية بحالين معًا: الورقةُ كما هي، ورصيدٌ لسعود
 *     في 1370 بما عاد، ومستحقٌّ للبوتيك بما لم يعد — يتقاصّان جزئيًّا.
 *
 * الأرقامُ بلا ضريبة ولا توصيل.
 */
class ABoutiquePieceIsReturnedOrNotOneByOneTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private User $owner;

    private Boutique $lama;

    private Boutique $reem;

    private Product $silver;

    private Product $perfume;

    private Product $duo;

    private Product $pair;

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

        $this->lama = Boutique::create(['business_id' => $this->shop->id, 'name' => 'بوتيك لمى', 'commission_rate' => 20, 'active' => true]);
        $this->reem = Boutique::create(['business_id' => $this->shop->id, 'name' => 'بوتيك ريم', 'commission_rate' => 10, 'active' => true]);

        $this->silver = $this->product('قطعة فضّة', 7, $this->lama->id, 10);
        $this->perfume = $this->product('عطر صغير', 12, $this->reem->id, 10);

        $this->duo = $this->product('باقة البوتيكين', 30);
        $this->recipe($this->duo, $this->silver, 1);
        $this->recipe($this->duo, $this->perfume, 1);

        $this->pair = $this->product('باقة الفضّتين', 25);
        $this->recipe($this->pair, $this->silver, 2);
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
            'quantity' => $qty, 'alert_qty' => 0, 'active' => true, 'published' => true, 'boutique_id' => $boutique,
        ]);
    }

    private function recipe(Product $of, Product $component, float $qty): void
    {
        RecipeItem::create(['business_id' => $this->shop->id, 'product_id' => $of->id, 'component_product_id' => $component->id, 'quantity' => $qty, 'wastage_percent' => 0]);
    }

    private function sell(Product $p): Order
    {
        $this->actingAs($this->owner)->postJson('/pos/checkout', [
            'items' => [['id' => $p->id, 'name' => $p->name, 'qty' => 1, 'price' => (float) $p->price]],
            'payment_method' => 'نقدي',
        ])->assertOk();

        return Order::where('business_id', $this->shop->id)->orderByDesc('id')->firstOrFail();
    }

    private function piece(Order $order, Product $p): int
    {
        return collect(BoutiqueDues::piecesOf($order))->firstWhere('product', $p->name)['id'];
    }

    private function books(): array
    {
        $b = fn (string $k) => Ledger::balance($this->shop->id, $k);
        $sales = Ledger::netRevenue($this->shop->id);

        return [
            'sales' => $sales, 'cogs' => $b('cogs'), 'other' => $b('other_expenses'),
            'profit' => round($sales - $b('cogs') - $b('other_expenses'), 3),
            'payable' => $b('boutique_payable'), 'receivable' => $b('boutique_receivable'),
        ];
    }

    private function due(Boutique $b, string $period = '2027-02'): float
    {
        return Boutiques::statement($this->shop->id, $b->id, $period)['components']['due'];
    }

    public function test_each_boutique_piece_in_a_bouquet_keeps_its_own_state(): void
    {
        $order = $this->sell($this->duo);
        $this->actingAs($this->owner);

        OrderCorrection::cancel($order->fresh(), 'أُعيدت الباقة', [
            $this->piece($order, $this->silver) => ['returned' => 1],
            $this->piece($order, $this->perfume) => ['lost' => 1],
        ]);

        // الفضّةُ على رفّها ومستحقُّها معكوس، والعطرُ غائبٌ ومستحقُّه باقٍ لريم
        $this->assertSame(10, (int) $this->silver->fresh()->quantity);
        $this->assertSame(9, (int) $this->perfume->fresh()->quantity);
        $this->assertSame([0.0, 12.0], [$this->due($this->lama), $this->due($this->reem)]);
        $this->assertSame(BoutiqueDues::LOST, BoutiqueComponentDue::whereNotNull('live_key')->value('kept_state'));
        $this->assertSame(['sales' => 0.0, 'cogs' => 0.0, 'other' => 12.0, 'profit' => -12.0, 'payable' => 12.0, 'receivable' => 0.0], $this->books());
    }

    public function test_two_pieces_of_one_item_split_and_one_comes_back_late(): void
    {
        $order = $this->sell($this->pair);   // ٢٥، فضّتان بـ١٤
        $this->actingAs($this->owner);

        // عادت واحدة، والأخرى لم يُتحقَّق منها بعد
        OrderCorrection::cancel($order->fresh(), 'أُعيدت ناقصة', [$this->piece($order, $this->silver) => ['returned' => 1]]);

        $open = BoutiqueComponentDue::whereNotNull('live_key')->firstOrFail();
        $this->assertSame([1.0, 7.0, BoutiqueDues::UNVERIFIED], [(float) $open->quantity, (float) $open->amount, $open->kept_state]);
        $this->assertSame(9, (int) $this->silver->fresh()->quantity, 'عادت واحدةٌ فقط إلى الرفّ');
        $this->assertSame(7.0, $this->due($this->lama));
        $this->assertSame(['sales' => 0.0, 'cogs' => 0.0, 'other' => 7.0, 'profit' => -7.0, 'payable' => 7.0, 'receivable' => 0.0], $this->books());

        // ثمّ وُجدت — تعود إلى الرفّ مرّةً، ويُعكس مستحقُّها وخسارتُها
        BoutiqueDues::returned($order, 'وُجدت في المشغل', [$open->id => 1]);
        BoutiqueDues::returned($order, 'مرّة ثانية', [$open->id => 1]);

        $this->assertSame(10, (int) $this->silver->fresh()->quantity);
        $this->assertSame(0.0, $this->due($this->lama));
        $this->assertSame(['sales' => 0.0, 'cogs' => 0.0, 'other' => 0.0, 'profit' => 0.0, 'payable' => 0.0, 'receivable' => 0.0], $this->books());
    }

    public function test_an_unverified_piece_confirmed_lost_changes_its_state_only(): void
    {
        $order = $this->sell($this->duo);
        $this->actingAs($this->owner);
        OrderCorrection::cancel($order->fresh(), 'أُلغي');

        $before = $this->books();
        $silver = $this->piece($order, $this->silver);
        BoutiqueDues::confirmLost($order, [$silver]);

        $this->assertSame(BoutiqueDues::LOST, BoutiqueComponentDue::find($silver)->kept_state);
        $this->assertSame(BoutiqueDues::UNVERIFIED, BoutiqueComponentDue::find($this->piece($order, $this->perfume))->kept_state);
        $this->assertSame($before, $this->books(), 'التأكيدُ لا يحرّك مالًا');
    }

    public function test_more_pieces_than_were_sold_is_refused_and_nothing_moves(): void
    {
        $order = $this->sell($this->pair);
        $this->actingAs($this->owner);

        try {
            OrderCorrection::cancel($order->fresh(), 'خطأ', [$this->piece($order, $this->silver) => ['returned' => 2, 'lost' => 1]]);
            $this->fail('قُبل ثلاثٌ من اثنتين');
        } catch (ValidationException) {
        }

        $this->assertNotSame('ملغي', $order->fresh()->status);
        $this->assertSame(8, (int) $this->silver->fresh()->quantity);
        $this->assertSame(14.0, $this->books()['payable']);
    }

    public function test_cancelling_after_a_paid_settlement_with_both_states_offsets_in_part(): void
    {
        $order = $this->sell($this->pair);
        $feb = Boutiques::settle($this->shop, $this->lama, '2027-02');
        BoutiqueDues::pay($feb, 'نقدي');

        Carbon::setTestNow('2027-03-05 10:00:00');
        $this->actingAs($this->owner);
        OrderCorrection::cancel($order->fresh(), 'أُعيدت ناقصة', [$this->piece($order, $this->silver) => ['returned' => 1, 'lost' => 1]]);

        // ورقةُ فبراير كما هي. وفي مارس: لسعود ١٤ (ما سُدِّد)، وللمى ٧ (ما لم يعد)
        $this->assertSame(14.0, (float) $feb->fresh()->components_due);
        $this->assertSame(['sales' => 0.0, 'cogs' => 0.0, 'other' => 7.0, 'profit' => -7.0, 'payable' => 7.0, 'receivable' => 14.0], $this->books());

        $st = Boutiques::statement($this->shop->id, $this->lama->id, '2027-03')['components'];
        $this->assertSame([7.0, 7.0, 14.0, 0.0], [$st['due'], $st['credit'], $st['credit_available'], $st['net']]);

        BoutiqueDues::pay(Boutiques::settle($this->shop, $this->lama, '2027-03'), 'نقدي');

        // دُفع للمى ١٤ عن قطعتين، وعادت واحدة: لسعود عليها ٧ — والدفترُ يقولها
        $this->assertSame(['sales' => 0.0, 'cogs' => 0.0, 'other' => 7.0, 'profit' => -7.0, 'payable' => 0.0, 'receivable' => 7.0], $this->books());
        $this->assertSame([7.0], array_values(BoutiqueDues::credits($this->shop->id, $this->lama->id)));
        $this->assertSame(9, (int) $this->silver->fresh()->quantity);
    }
}
