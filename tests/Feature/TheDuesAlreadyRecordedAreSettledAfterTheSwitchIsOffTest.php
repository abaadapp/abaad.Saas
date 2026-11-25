<?php

namespace Tests\Feature;

use App\Models\Boutique;
use App\Models\BoutiqueComponentDue;
use App\Models\BoutiqueSettlement;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Order;
use App\Models\OrderItem;
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
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * إطفاءُ حساب قطع الباقات يوقف الجديد — ولا يُقفل على القديم.
 *
 * ما سُجّل مستحقًّا قبل الإطفاء يُقرأ ويُسوّى ويُسدَّد ويُستردّ رصيدُه، وقطعُه
 * التي أُلغي طلبُها يُقال أعادت أم لم تعد. وما يُباع بعد الإطفاء لا يكتب
 * مستحقًّا، ولو زِيدت كمّيّةُ بندٍ كان قبله. ولا يُسدَّد شيءٌ مرّتين.
 */
class TheDuesAlreadyRecordedAreSettledAfterTheSwitchIsOffTest extends TestCase
{
    use RefreshDatabase;

    private const PERIOD = '2027-02';

    private Business $shop;

    private User $owner;

    private Boutique $lama;

    private Product $silver;

    private Product $set;

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
        $this->shop = Business::create(['name' => 'متجر', 'type' => 'عام', 'status' => 'نشط', 'boutiques_enabled' => true, 'boutique_components_enabled' => true]);
        Currency::create(['business_id' => $this->shop->id, 'code' => 'OMR', 'name' => 'ريال عماني', 'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);
        Ledger::seedChart($this->shop->id);
        Branch::create(['business_id' => $this->shop->id, 'name' => 'الرئيسي']);
        Setting::create(['business_id' => $this->shop->id, 'key' => 'vat_enabled', 'value' => '0']);
        $this->owner = User::create(['business_id' => $this->shop->id, 'name' => 'المالك', 'email' => 'o@abaad.om', 'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط']);
        $this->lama = Boutique::create(['business_id' => $this->shop->id, 'name' => 'بوتيك لمى', 'commission_rate' => 20, 'active' => true]);
        $this->silver = $this->product('قطعة فضّة', 7, $this->lama->id);
        $this->set = $this->product('باقة', 20);
        RecipeItem::create(['business_id' => $this->shop->id, 'product_id' => $this->set->id, 'component_product_id' => $this->silver->id, 'quantity' => 1, 'wastage_percent' => 0]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function product(string $name, float $price, ?int $boutique = null): Product
    {
        return Product::create(['business_id' => $this->shop->id, 'name' => $name, 'price' => $price, 'cost' => 1, 'quantity' => 100,
            'alert_qty' => 0, 'active' => true, 'published' => true, 'boutique_id' => $boutique]);
    }

    private function sell(int $qty = 1, ?Product $p = null): Order
    {
        $p ??= $this->set;
        $this->actingAs($this->owner)->postJson('/pos/checkout', [
            'items' => [['id' => $p->id, 'name' => $p->name, 'qty' => $qty, 'price' => (float) $p->price]],
            'payment_method' => 'نقدي', 'client_uuid' => uniqid('d', true),
        ])->assertOk();

        return Order::where('business_id', $this->shop->id)->orderByDesc('id')->firstOrFail();
    }

    /** @return array<string, array{bool, bool}> */
    public static function offs(): array
    {
        return [
            'حسابُ القطع مطفأ' => [true, false],
            'الإيواءُ مطفأ' => [false, true],
            'كلاهما مطفأ' => [false, false],
        ];
    }

    private function switchTo(bool $hosting, bool $components): void
    {
        Business::whereKey($this->shop->id)->update(['boutiques_enabled' => $hosting, 'boutique_components_enabled' => $components]);
        $this->owner = $this->owner->fresh();
    }

    private function journals(): int
    {
        return DB::table('journal_entries')->where('business_id', $this->shop->id)->count();
    }

    /* ═══════════ يُسدَّد ما صدر ═══════════ */

    #[DataProvider('offs')]
    public function test_an_issued_sheet_is_paid_once_after_the_switch_is_off(bool $hosting, bool $components): void
    {
        $this->sell();
        $sheet = Boutiques::settle($this->shop, $this->lama, self::PERIOD);
        $this->assertEquals(7, $sheet->components_due);

        $this->switchTo($hosting, $components);
        $before = $this->journals();
        $cash = Ledger::balance($this->shop->id, 'cash');

        $this->actingAs($this->owner)->post(route('admin.boutiques.components.pay', [$this->lama->id, $sheet->id]), ['method' => 'نقدي'])
            ->assertSessionHasNoErrors()->assertRedirect();
        $this->assertNotNull($sheet->fresh()->components_paid_at, 'الورقةُ الصادرة لا تُسدَّد لأنّ مفتاحًا أُطفئ');
        $this->assertSame($before + 1, $this->journals());
        $this->assertEquals($cash - 7, Ledger::balance($this->shop->id, 'cash'));

        // ضغطةٌ ثانية: لا قيدَ ثانٍ ولا نقدَ يخرج مرّتين
        $this->actingAs($this->owner)->post(route('admin.boutiques.components.pay', [$this->lama->id, $sheet->id]), ['method' => 'نقدي']);
        $this->assertSame($before + 1, $this->journals(), 'سُدِّدت الورقةُ مرّتين');
        $this->assertEquals($cash - 7, Ledger::balance($this->shop->id, 'cash'));
        $this->assertSame(0.0, Ledger::balance($this->shop->id, 'boutique_payable'), 'بقي على المتجر دينٌ سُدِّد');
    }

    #[DataProvider('offs')]
    public function test_dues_not_yet_on_a_sheet_are_settled_and_paid_and_seen_after_the_switch_is_off(bool $hosting, bool $components): void
    {
        $this->sell(2);
        $this->switchTo($hosting, $components);
        Carbon::setTestNow('2027-03-01 10:00:00');

        // تُقرأ: دفترُ البوتيك ومكوّناتُه في الشاشة
        $page = $this->actingAs($this->owner)->get(route('admin.boutiques.show', [$this->lama->id, 'period' => self::PERIOD]))->assertOk()->viewData('page')['props'];
        $this->assertNotNull($page['ledger'], 'اختفى دفترُ البوتيك مع أنّ عليه التزامًا');
        $this->assertEquals(14, $page['ledger']['owed']);

        // وتُسوّى وتُسدَّد
        $this->actingAs($this->owner)->post(route('admin.boutiques.settle', $this->lama->id), ['period' => self::PERIOD])->assertSessionHasNoErrors();
        $sheet = BoutiqueSettlement::firstOrFail();
        $this->assertEquals(14, $sheet->components_due);
        $this->actingAs($this->owner)->post(route('admin.boutiques.components.pay', [$this->lama->id, $sheet->id]), ['method' => 'نقدي'])->assertSessionHasNoErrors();
        $this->assertNotNull($sheet->fresh()->components_paid_at);
    }

    #[DataProvider('offs')]
    public function test_a_credit_is_refunded_after_the_switch_is_off(bool $hosting, bool $components): void
    {
        $order = $this->sell();
        $sheet = Boutiques::settle($this->shop, $this->lama, self::PERIOD);
        $this->actingAs($this->owner);
        BoutiqueDues::pay($sheet->fresh(), 'نقدي');
        OrderCorrection::cancel($order->fresh(), 'رُدّ بعد السداد', boutiqueReturned: true);
        $this->assertEquals(7, array_sum(BoutiqueDues::credits($this->shop->id, $this->lama->id)), 'رصيدٌ للمتجر');

        $this->switchTo($hosting, $components);
        $this->actingAs($this->owner)->post(route('admin.boutiques.refund', $this->lama->id), ['method' => 'نقدي'])->assertSessionHasNoErrors();
        $this->assertSame(1, BoutiqueSettlement::where('kind', BoutiqueSettlement::REFUND)->count());
        $this->assertEquals(0, array_sum(BoutiqueDues::credits($this->shop->id, $this->lama->id)), 'الرصيدُ لم يُستردّ');
        // ضغطةٌ ثانية لا تستردّ مرّتين
        $this->actingAs($this->owner)->post(route('admin.boutiques.refund', $this->lama->id), ['method' => 'نقدي'])->assertSessionHasErrors('refund');
        $this->assertSame(1, BoutiqueSettlement::where('kind', BoutiqueSettlement::REFUND)->count());
    }

    /** قطعُ طلبٍ أُلغي والمفتاحُ مشغَّل: أعادت أم لم تعد — تُقال بعد الإطفاء */
    #[DataProvider('offs')]
    public function test_a_kept_piece_of_a_cancelled_order_is_decided_after_the_switch_is_off(bool $hosting, bool $components): void
    {
        $order = $this->sell();
        $this->actingAs($this->owner);
        OrderCorrection::cancel($order->fresh(), 'أُلغي ولم تُتحقَّق القطعة', boutiqueReturned: false);
        $this->assertEquals(7, BoutiqueDues::owedFor($order), 'بقي مستحقُّها حتّى يُقال');
        $this->switchTo($hosting, $components);

        $props = $this->actingAs($this->owner)->get(route('admin.orders.show', $order->number))->assertOk()->viewData('page')['props'];
        $this->assertNotEmpty($props['boutiquePieces'], 'قطعٌ معلّقةٌ لا تُرى');
        $this->actingAs($this->owner)->post(route('admin.orders.boutique-pieces', $order->number), [
            'action' => 'returned', 'pieces' => [$props['boutiquePieces'][0]['id'] => 1],
        ])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertEquals(0, BoutiqueDues::owedFor($order), 'القطعةُ عادت وبقي مستحقُّها على المتجر');
    }

    /* ═══════════ ولا جديدَ يُحتسب ═══════════ */

    #[DataProvider('offs')]
    public function test_nothing_new_is_owed_for_what_happens_while_it_is_off(bool $hosting, bool $components): void
    {
        $order = $this->sell();
        $item = OrderItem::where('order_id', $order->id)->firstOrFail();
        $this->assertEquals(7, BoutiqueDues::owedFor($order));
        $this->switchTo($hosting, $components);

        // بيعةٌ جديدة: لا مستحقّ
        $later = $this->sell();
        $this->assertEquals(0, BoutiqueDues::owedFor($later));

        // وزيادةُ كمّيّة بندٍ قبلَه لا تكتب مستحقًّا عن الزيادة — وخصمُها يُنقص
        $this->actingAs($this->owner);
        OrderCorrection::setQuantity($order->fresh(), $item->fresh(), 3, 'زيد اثنان والمفتاحُ مطفأ');
        $this->assertEquals(7, BoutiqueDues::owedFor($order->fresh()), 'زيادةٌ والمفتاحُ مطفأ كتبت مستحقًّا جديدًا');
        $this->assertSame(1, BoutiqueComponentDue::where('order_id', $order->id)->whereNotNull('live_key')->count());
        OrderCorrection::setQuantity($order->fresh(), $item->fresh(), 2, 'رُدّ واحد من الثلاثة');
        $this->assertEquals(7, BoutiqueDues::owedFor($order->fresh()), 'ما بقي من القطع ثلاثٌ بعدها اثنتان، ومستحقُّ الأولى وحدها قائم');
        $after = $this->sell();
        $this->assertEquals(0, BoutiqueDues::owedFor($after));
    }

    /** وإعادةُ الكمّيّة تحت ما كان تُنقص المستحقّ كما كانت — ولو والمفتاحُ مطفأ */
    public function test_a_decrease_while_off_still_lowers_what_was_owed(): void
    {
        $order = $this->sell(3);
        $item = OrderItem::where('order_id', $order->id)->firstOrFail();
        $this->assertEquals(21, BoutiqueDues::owedFor($order));
        $this->switchTo(true, false);
        $this->actingAs($this->owner);
        OrderCorrection::setQuantity($order->fresh(), $item->fresh(), 1, 'رُدّ اثنان');
        $this->assertEquals(7, BoutiqueDues::owedFor($order->fresh()));
    }

    public function test_the_increase_is_owed_as_before_while_it_is_on(): void
    {
        $order = $this->sell();
        $this->actingAs($this->owner);
        OrderCorrection::setQuantity($order->fresh(), OrderItem::where('order_id', $order->id)->firstOrFail(), 3, 'زيد اثنان');
        $this->assertEquals(21, BoutiqueDues::owedFor($order->fresh()), 'المشغَّلُ تغيّر سلوكُه');
    }

    /* ═══════════ لا يُعاد بناءُ ما مضى ═══════════ */

    public function test_what_was_recorded_is_paid_at_its_snapshot_after_prices_and_recipes_change(): void
    {
        $this->sell();
        $this->switchTo(false, false);
        $this->silver->update(['price' => 99]);
        RecipeItem::where('product_id', $this->set->id)->update(['quantity' => 5]);
        RecipeItem::where('product_id', $this->set->id)->delete();
        $this->silver->update(['boutique_id' => null]);

        $sheet = Boutiques::settle($this->shop->fresh(), $this->lama, self::PERIOD);
        $this->assertEquals(7, $sheet->components_due, 'المستحقُّ يومَ البيع لا يُعاد بناؤه من وصفةٍ أو سعرٍ لاحق');
        $this->assertSame(1, BoutiqueComponentDue::count());
    }

    /* ═══════════ ومن لم يُفتح له قطّ ═══════════ */

    /* ═══════════ مع صلاحيّات مال البوتيكات ═══════════ */

    /** الإطفاءُ لا يفتح بابًا لمن لا يقرأ المال ولا لمن لا يسدّد — والمسدِّدُ يسدّد ما سُجّل */
    #[DataProvider('offs')]
    public function test_switching_off_opens_no_door_the_permissions_keep_closed(bool $hosting, bool $components): void
    {
        $this->sell();
        $sheet = Boutiques::settle($this->shop, $this->lama, self::PERIOD);
        $this->switchTo($hosting, $components);
        $user = fn (string $mail, array $keys) => User::create(['business_id' => $this->shop->id, 'name' => $mail, 'email' => $mail.'@abaad.om', 'password' => bcrypt('x'), 'role' => 'sales', 'status' => 'نشط', 'permissions' => $keys]);
        $page = fn (User $u) => $this->actingAs($u)->get(route('admin.boutiques.show', [$this->lama->id, 'period' => self::PERIOD]))->assertOk()->viewData('page')['props'];
        $pay = fn (User $u) => $this->actingAs($u)->post(route('admin.boutiques.components.pay', [$this->lama->id, $sheet->id]), ['method' => 'نقدي']);

        $goods = $user('goods', ['dashboard', 'products']);
        $this->assertNull($page($goods)['ledger'], 'من لا يقرأ المال لا رصيدَ له');
        $pay($goods)->assertForbidden();
        $this->actingAs($goods)->post(route('admin.boutiques.refund', $this->lama->id), ['method' => 'نقدي'])->assertForbidden();

        $reader = $user('reads', ['dashboard', 'products', 'expenses']);
        $this->assertEquals(7.0, $page($reader)['ledger']['owed']);
        $pay($reader)->assertForbidden();
        $this->assertNull($sheet->fresh()->components_paid_at);

        $payer = $user('pays', ['dashboard', 'products', 'expenses', \App\Support\Permissions::INVOICE_PAY]);
        $pay($payer)->assertSessionHasNoErrors();
        $this->assertNotNull($sheet->fresh()->components_paid_at);
        $this->assertEquals(0.0, Ledger::balance($this->shop->id, 'boutique_payable'));
    }

    /** قرارُ المالك: الصنفُ المنفردُ ملكُ بوتيكه ولو أُطفئ الإيواء — منطقُه كما كان، ولا مستحقَّ قطعٍ جديد */
    #[DataProvider('offs')]
    public function test_a_piece_sold_on_its_own_is_counted_as_before_and_no_piece_due_is_written(bool $hosting, bool $components): void
    {
        $this->switchTo($hosting, $components);
        $this->sell(2, $this->silver);
        $this->sell(3);
        $s = Boutiques::statement($this->shop->id, $this->lama->id, self::PERIOD);
        $this->assertEquals([14.0, 2.8, 11.2], [$s['gross'], $s['commission'], $s['net']], 'منطقُ المنفرد تغيّر');
        $this->assertSame(0, DB::table('boutique_component_dues')->where('business_id', $this->shop->id)->count(), 'مستحقُّ قطعٍ كُتب ومفتاحُه مطفأ');
    }

    public function test_a_shop_that_never_recorded_any_still_meets_a_closed_door(): void
    {
        $this->switchTo(true, false);
        $other = Boutique::create(['business_id' => $this->shop->id, 'name' => 'آخر', 'commission_rate' => 5, 'active' => true]);
        $sheet = BoutiqueSettlement::create(['business_id' => $this->shop->id, 'boutique_id' => $other->id, 'number' => 'X', 'period' => self::PERIOD,
            'covered_from' => now(), 'covered_to' => now(), 'gross' => 0, 'commission' => 0, 'net' => 0, 'lines_count' => 0, 'kind' => BoutiqueSettlement::SETTLEMENT,
            'components_due' => 0, 'components_credit' => 0, 'components_net' => 0, 'issued_at' => now()]);
        $this->actingAs($this->owner)->post(route('admin.boutiques.components.pay', [$other->id, $sheet->id]), ['method' => 'نقدي'])->assertNotFound();
        $this->actingAs($this->owner)->post(route('admin.boutiques.refund', $other->id), ['method' => 'نقدي'])->assertNotFound();
        $this->assertNull($this->actingAs($this->owner)->get(route('admin.boutiques.show', $other->id))->assertOk()->viewData('page')['props']['ledger']);
    }

    public function test_another_shops_sheet_is_not_paid_from_here(): void
    {
        $this->sell();
        $sheet = Boutiques::settle($this->shop, $this->lama, self::PERIOD);
        $this->switchTo(true, false);
        $other = Business::create(['name' => 'آخر', 'type' => 'عام', 'status' => 'نشط', 'boutiques_enabled' => true]);
        $theirs = User::create(['business_id' => $other->id, 'name' => 'آخر', 'email' => 'x@abaad.om', 'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط']);
        $mine = Boutique::create(['business_id' => $other->id, 'name' => 'عندهم', 'commission_rate' => 5, 'active' => true]);
        $this->actingAs($theirs)->post(route('admin.boutiques.components.pay', [$mine->id, $sheet->id]), ['method' => 'نقدي'])->assertNotFound();
        $this->actingAs($theirs)->post(route('admin.boutiques.components.pay', [$this->lama->id, $sheet->id]), ['method' => 'نقدي'])->assertNotFound();
        $this->assertNull($sheet->fresh()->components_paid_at);
    }
}
