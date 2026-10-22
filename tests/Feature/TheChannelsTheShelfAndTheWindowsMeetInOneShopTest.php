<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Category;
use App\Models\Currency;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Support\Ledger;
use App\Support\MarketingSettings;
use App\Support\SalesChannel;
use App\Support\Store\DeliverySchedule;
use App\Support\Store\ShelfOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Tests\Support\RibbonShopAtCheckout;
use Tests\TestCase;

/**
 * قنواتُ البيع (#138) ورفُّ سعود المرتّب بيده (#139) وآخرُ حجزٍ لكلّ فترة (#137)
 * — في متجرٍ واحدٍ معًا، لا كلٌّ في ملفّه.
 *
 * ═══ ما يُحرس ═══
 *
 *   - كلُّ حالةٍ من حالات الصنف الخمس في مكانها: على الرفّ المرتّب بيده بترتيبه،
 *     وعلى الصندوق، وفي الإتمام. والمخفيُّ يحفظ مكانَه حتّى يعود.
 *   - الرفُّ واحدٌ للبابين: كلُّ بيعةٍ تُخرج مرّةً، ولا يُتجاوز ما على الرفّ.
 *   - صنفُ «الموقع فقط» يُحجز بفترته حتّى آخر حجزها، والصندوقُ يردّه في الأثناء.
 *   - حفظُ الصنف بلا قناتيه، ونقلُه بين الفئات، واستيرادُ ملفٍّ يُغلق قناتَه —
 *     لا يمسّ قناتيه ولا مكانَه على الرفّ إلّا بما قيل.
 *   - ومتجرٌ آخر لا يبلغ شيئًا من هذا.
 *
 * وما يحرسه كلُّ طلبٍ وحده في ملفّاته: `AProductSellsOnlyWhereItsOwnerChoosesTest`
 * و`ARibbonShopOrdersItsShelfByHandTest` و`EachDeliveryWindowClosesItsBookingOnTimeTest`.
 */
class TheChannelsTheShelfAndTheWindowsMeetInOneShopTest extends TestCase
{
    use RefreshDatabase;
    use RibbonShopAtCheckout;

    private Branch $branch;

    private Category $flowers;

    private Category $gifts;

    /** @var array<string, Product> */
    private array $p = [];

    private const WED = '2027-02-10';

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');
        // الأربعاء ١٠ فبراير، السابعة صباحًا بتوقيت مسقط
        Carbon::setTestNow('2027-02-10 07:00:00');
        $this->openRibbon();
        $this->branch = Branch::where('business_id', $this->shop->id)->firstOrFail();

        // قوائمُ الإذن كلُّها فارغةٌ إلّا رفَّ هذا المتجر — لا يصير خامسُ متجرٍ «سعود» بالصدفة
        config([
            'storefront.ribbon_shelf_order_businesses' => [$this->shop->id],
            'storefront.ribbon_catalog_editor_businesses' => [],
            'storefront.ribbon_curated_new_arrivals_businesses' => [],
            'storefront.ribbon_picks_businesses' => [],
        ]);

        $this->flowers = Category::create(['business_id' => $this->shop->id, 'name' => 'ورد']);
        $this->gifts = Category::create(['business_id' => $this->shop->id, 'name' => 'هدايا']);

        // أسماءٌ لاتينيّة: الأبجديُّ العربيُّ يختلف بين SQLite وPostgreSQL
        $this->p['both'] = $this->product('Aster both', true, true);
        $this->p['online'] = $this->product('Begonia online', false, true);
        $this->p['till'] = $this->product('Camellia till', true, false);
        $this->p['none'] = $this->product('Dahlia none', false, false);
        $this->p['off'] = $this->product('Erica off', true, true, ['active' => false]);
        // وصنفُ السمة (`openRibbon`) بلا فئة — جديدٌ على الرفّ المرتّب، فيأتي آخرًا
        $this->rose->update(['name' => 'Zinnia new']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function product(string $name, bool $pos, bool $online, array $over = []): Product
    {
        return Product::create($over + [
            'business_id' => $this->shop->id, 'name' => $name, 'price' => 10, 'cost' => 4, 'category_id' => $this->flowers->id,
            'quantity' => 20, 'alert_qty' => 0, 'active' => true, 'pos_enabled' => $pos, 'published' => $online,
        ]);
    }

    private function ids(string ...$keys): array
    {
        return array_map(fn ($k) => $k === 'new' ? $this->rose->id : $this->p[$k]->id, $keys);
    }

    private function orderShelf(array $all, array $categories = []): void
    {
        $this->actingAs($this->owner)->from(route('admin.website.editor'))
            ->post(route('admin.marketing.store.save'), [ShelfOrder::KEY => json_encode(['all' => $all, 'categories' => $categories])])
            ->assertSessionHasNoErrors();
    }

    /** @return list<int> الرفُّ كما يراه الزائر */
    private function shelf(array $query = []): array
    {
        auth()->logout();

        return array_column($this->get('/s/ribbon/shop'.($query ? '?'.http_build_query($query) : ''))->assertOk()->viewData('products'), 'id');
    }

    private function atTheTill(Product $product, int $qty = 1)
    {
        return $this->actingAs($this->owner)->withSession(['current_branch' => $this->branch->id])
            ->postJson(route('pos.checkout'), [
                'items' => [['id' => $product->id, 'name' => $product->name, 'qty' => $qty]],
                'payment_method' => 'نقدي',
                'client_uuid' => uniqid('c', true),
            ]);
    }

    private function online(Product $product, int $qty = 1, array $over = [])
    {
        auth()->logout();

        return $this->checkout($over + ['items' => [['id' => $product->id, 'qty' => $qty]]]);
    }

    private function useWindows(): void
    {
        $week = array_fill(0, 7, [
            ['id' => 'am01', 'from' => '09:00', 'to' => '12:00', 'ar' => 'الصباح', 'en' => 'Morning', 'cutoff' => ['time' => '08:00', 'day' => 'same']],
            ['id' => 'pm01', 'from' => '16:00', 'to' => '21:00', 'ar' => 'المساء', 'en' => 'Evening', 'cutoff' => ['time' => '14:00', 'day' => 'same']],
        ]);
        $this->settings([
            'store_max_days' => '7',
            DeliverySchedule::ON => '1',
            DeliverySchedule::KEY => DeliverySchedule::validated(['delivery' => $week, 'pickup' => null, 'closed' => [], 'lead' => 0]),
        ]);
    }

    private function deliver(Product $product, string $slot)
    {
        return $this->online($product, 1, ['fulfil' => 'delivery', 'address' => 'الخوير، شارع ٣٣', 'date' => self::WED, 'slot' => $slot]);
    }

    /* ═════════════ الحالاتُ الخمس على الرفّ والصندوق والإتمام ═════════════ */

    public function test_each_state_sits_on_the_hand_ordered_shelf_and_sells_where_its_channels_say(): void
    {
        $this->assertSame($this->ids('both', 'online', 'new'), $this->shelf(), 'الرفُّ بلا ترتيبٍ يدويّ ليس أبجديًّا');

        $this->orderShelf($this->ids('till', 'online', 'both', 'none', 'off'));

        // ما يُعرض في الموقع وحده، بترتيب صاحبه، والجديدُ آخرًا
        $this->assertSame($this->ids('online', 'both', 'new'), $this->shelf());
        $this->assertSame($this->ids('online', 'both', 'new'), $this->shelf(['lang' => 'en']), 'الصفحةُ الإنجليزيّة بترتيبٍ آخر');

        // والصندوقُ لا يقرأ ترتيبَ الموقع، ويبيع ما فُتحت له نقطةُ البيع وحده
        $this->atTheTill($this->p['till'])->assertOk();
        $this->atTheTill($this->p['both'])->assertOk();
        foreach (['online', 'none', 'off'] as $k) {
            $this->atTheTill($this->p[$k])->assertStatus(422);
        }

        // والإتمامُ يبيع ما على الرفّ وحده
        $this->online($this->p['online'])->assertOk();
        $this->online($this->p['both'])->assertOk();
        foreach (['till', 'none', 'off'] as $k) {
            $refused = $this->online($this->p[$k])->assertStatus(422)->json('errors');
            $this->assertNotEmpty(array_filter(array_keys($refused), fn ($key) => str_starts_with($key, 'items')), $k.' رُدّ بغير سبب الصنف');
        }

        $this->assertSame(2, Order::where('channel', SalesChannel::POS)->count());
        $this->assertSame(2, Order::where('channel', SalesChannel::WEBSITE)->count());

        // وصنفُ الصندوق يُفتح للموقع فيعود إلى مكانه المحفوظ — لا إلى آخر الرفّ
        $this->p['till']->fresh()->update(['published' => true]);
        $this->assertSame($this->ids('till', 'online', 'both', 'new'), $this->shelf());
    }

    /* ═════════════ رفٌّ واحدٌ لبابين ═════════════ */

    public function test_one_shelf_two_doors_and_each_sale_leaves_it_once(): void
    {
        $this->p['both']->update(['quantity' => 3]);

        $this->atTheTill($this->p['both'])->assertOk();
        $this->assertSame(2, (int) $this->p['both']->fresh()->quantity);

        $this->online($this->p['both'])->assertOk();
        $this->assertSame(1, (int) $this->p['both']->fresh()->quantity);

        // ولا يُتجاوز ما بقي من أيّ باب
        $this->online($this->p['both'], 2)->assertStatus(422);
        $this->assertSame(1, (int) $this->p['both']->fresh()->quantity);

        $this->atTheTill($this->p['both'])->assertOk();
        $this->assertSame(0, (int) $this->p['both']->fresh()->quantity);
        $this->atTheTill($this->p['both'])->assertStatus(422);

        $this->assertSame(3, Order::count());
        $this->assertSame(0, (int) $this->p['both']->fresh()->quantity);
    }

    /* ═════════════ «الموقع فقط» بفترته وآخر حجزها ═════════════ */

    public function test_an_online_only_product_is_booked_through_its_window_until_the_cutoff(): void
    {
        $this->useWindows();

        Carbon::setTestNow('2027-02-10 07:59:00');
        $this->deliver($this->p['online'], 'am01')->assertOk();

        // الثامنة: انتهى حجزُ الصباح، وبقي المساء، والصندوقُ يردّه كما كان
        Carbon::setTestNow('2027-02-10 08:00:00');
        $this->deliver($this->p['online'], 'am01')->assertStatus(422)->assertJsonValidationErrors('slot');
        $this->deliver($this->p['online'], 'pm01')->assertOk();
        $this->atTheTill($this->p['online'])->assertStatus(422);

        $orders = Order::orderBy('id')->get();
        $this->assertSame(['am01', 'pm01'], $orders->pluck('delivery_slot')->all());
        $this->assertSame(['2027-02-10 09:00:00', '2027-02-10 16:00:00'], $orders->map(fn ($o) => $o->scheduled_for->toDateTimeString())->all());
        $this->assertSame(18, (int) $this->p['online']->fresh()->quantity, 'خرج من الرفّ غيرُ ما بِيع');
    }

    /* ═════════════ الحفظُ والنقلُ والاستيراد لا تمسّ إلّا ما قيل ═════════════ */

    public function test_saving_moving_and_importing_a_product_keep_its_channels_and_its_place(): void
    {
        $this->orderShelf(
            $this->ids('online', 'both', 'till'),
            [(string) $this->flowers->id => $this->ids('online', 'both', 'till'), (string) $this->gifts->id => []],
        );

        // حفظٌ لا يرسل القناتين — سعرٌ جديدٌ وحسب
        $this->actingAs($this->owner)->put(route('admin.products.update', $this->p['online']->id), [
            'name' => 'Begonia online', 'price' => 12, 'cost' => 4, 'quantity' => 20, 'alert_qty' => 0,
            'category_id' => $this->flowers->id,
        ])->assertSessionHasNoErrors();
        $fresh = $this->p['online']->fresh();
        $this->assertSame([true, false, true], [$fresh->active, $fresh->pos_enabled, $fresh->published], 'حفظُ السعر فتح نقطةَ البيع');
        $this->assertSame($this->flowers->id, (int) $fresh->category_id);
        $this->assertSame($this->ids('online', 'both', 'new'), $this->shelf());

        // ونقلُه إلى الهدايا: يغيب عن رفّ الورد، ويأتي آخرَ رفّ الهدايا، و«كلُّ المنتجات» كما هو
        $this->actingAs($this->owner)->put(route('admin.products.update', $this->p['online']->id), [
            'name' => 'Begonia online', 'price' => 12, 'cost' => 4, 'quantity' => 20, 'alert_qty' => 0,
            'category_id' => $this->gifts->id,
        ])->assertSessionHasNoErrors();
        $this->assertSame($this->ids('both'), $this->shelf(['cat' => $this->flowers->id]));
        $this->assertSame($this->ids('online'), $this->shelf(['cat' => $this->gifts->id]));
        $this->assertSame($this->ids('online', 'both', 'new'), $this->shelf());
        $this->assertFalse($this->p['online']->fresh()->pos_enabled);

        // واستيرادٌ يُغلق الموقعَ عن صنف القناتين: يغيب عن الرفّ ولا يفقد مكانه
        $path = tempnam(sys_get_temp_dir(), 'imp').'.csv';
        file_put_contents($path, "\xEF\xBB\xBF"."الاسم,السعر,المتجر الإلكتروني\nAster both,10,لا\n");
        $this->actingAs($this->owner)->post(route('admin.products.import.upload'), [
            'file' => new UploadedFile($path, 'products.csv', 'text/csv', null, true),
            'branch_id' => $this->branch->id,
        ])->assertRedirect(route('admin.products.import.preview'));
        $this->actingAs($this->owner)->post(route('admin.products.import.confirm'));

        $both = $this->p['both']->fresh();
        $this->assertSame([true, false], [$both->pos_enabled, $both->published], 'الاستيرادُ لم يغلق الموقع أو أغلق الصندوق');
        $this->assertSame(20, (int) $both->quantity, 'الاستيرادُ حرّك الرفّ ولم يذكره');
        $this->assertSame($this->ids('online', 'new'), $this->shelf());
        $this->atTheTill($this->p['both'])->assertOk();

        $this->p['both']->fresh()->update(['published' => true]);
        $this->assertSame($this->ids('online', 'both', 'new'), $this->shelf(), 'عاد الصنفُ إلى غير مكانه');
    }

    /* ═════════════ ومتجرٌ آخر لا يبلغ شيئًا ═════════════ */

    public function test_another_shop_reaches_none_of_it(): void
    {
        $this->orderShelf($this->ids('online', 'both'));

        $other = Business::create([
            'name' => 'OTHER', 'type' => 'محل ورد', 'status' => 'نشط',
            'site_slug' => 'other', 'tier' => 'gold', 'storefront_theme' => 'ribbon',
        ]);
        Currency::create(['business_id' => $other->id, 'code' => 'OMR', 'name' => 'ريال', 'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);
        Ledger::seedChart($other->id);
        $otherBranch = Branch::create(['business_id' => $other->id, 'name' => 'صحار']);
        Setting::create(['business_id' => $other->id, 'key' => 'vat_enabled', 'value' => '0']);
        MarketingSettings::save($other->id, 'website', ['store_on' => '1', 'store_pay_cod' => '1']);
        $neighbour = User::create(['business_id' => $other->id, 'name' => 'الجار', 'email' => 'n@meet.local',
            'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط']);
        $b = Product::create(['business_id' => $other->id, 'name' => 'Beta', 'price' => 5, 'cost' => 1, 'quantity' => 5,
            'alert_qty' => 0, 'active' => true, 'pos_enabled' => false, 'published' => true]);
        $a = Product::create(['business_id' => $other->id, 'name' => 'Alpha', 'price' => 5, 'cost' => 1, 'quantity' => 5,
            'alert_qty' => 0, 'active' => true, 'pos_enabled' => true, 'published' => true]);

        // رفُّه أبجديٌّ ولو كُتب له ترتيب، ولا يحفظ ترتيبًا، ولا يرى أصنافَ سعود
        MarketingSettings::save($other->id, 'website', [ShelfOrder::KEY => json_encode(['all' => [$b->id, $a->id]])]);
        auth()->logout();
        $this->assertSame([$a->id, $b->id], array_column($this->get('/s/other/shop')->assertOk()->viewData('products'), 'id'));
        $this->actingAs($neighbour)->from(route('admin.website.editor'))
            ->post(route('admin.marketing.store.save'), [ShelfOrder::KEY => json_encode(['all' => [$b->id, $a->id]])])
            ->assertForbidden();

        // صندوقُه لا يبيع صنفَ سعود، ولا إتمامُه
        $this->actingAs($neighbour)->withSession(['current_branch' => $otherBranch->id])->postJson(route('pos.checkout'), [
            'items' => [['id' => $this->p['both']->id, 'name' => 'Aster both', 'qty' => 1]],
            'payment_method' => 'نقدي', 'client_uuid' => uniqid('n', true),
        ])->assertStatus(422);
        auth()->logout();
        $this->postJson('/s/other/checkout', $this->order(['items' => [['id' => $this->p['online']->id, 'qty' => 1]]]))->assertStatus(422);

        $this->assertSame(20, (int) $this->p['both']->fresh()->quantity);
        $this->assertSame(20, (int) $this->p['online']->fresh()->quantity);
        $this->assertSame($this->ids('online', 'both', 'new'), $this->shelf(), 'تغيّر رفُّ سعود بفعل جاره');
    }
}
