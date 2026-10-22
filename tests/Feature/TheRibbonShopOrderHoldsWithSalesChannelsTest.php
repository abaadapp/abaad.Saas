<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Category;
use App\Models\Currency;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Support\Demo;
use App\Support\Ledger;
use App\Support\MarketingSettings;
use App\Support\Store\ShelfOrder;
use App\Support\Store\StorePage;
use App\Support\Store\ThemePublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ترتيبُ متجر RIBBON مع قنوات البيع — #139 و#138 معًا.
 *
 * كلٌّ منهما اختُبر وحده: ترتيبُ الرفّ اليدويّ (`ShelfOrder`) يعرف «المعروض»
 * و«المخفيّ»، وقنواتُ البيع (`pos_enabled` بجانب `published`) جعلت «المخفيَّ عن
 * الموقع» صنفًا قد يُباع في الصندوق. وهذا ما يحرسه اجتماعُهما:
 *
 *   - صنفٌ «للموقع فقط» يأخذ موضعَه في الرفّ المرتّب، و«للصندوق فقط» لا يُرى
 *     فيه ولا يُفسد ترتيبَه، ويعود إلى موضعه حين يُفتح للموقع.
 *   - صنفٌ أُغلقت قناتاه أو أُوقف ثمّ عاد يرجع إلى موضعه.
 *   - قائمةُ الصندوق لا يمسّها ترتيبُ الموقع.
 *   - الصفحةُ العربيّة والإنجليزيّة تقرآن ترتيبًا واحدًا — للرفّ وللأقسام.
 *   - ترتيبُ الأقسام (بمطفئها في موضعه) وترتيبُ الرفّ مسوّدةٌ واحدةٌ تُنشر معًا.
 *   - متجرٌ خارج القائمة يبقى رفُّه بالاسم بقناتيه، ولا يمسّه ترتيبُ غيره.
 */
class TheRibbonShopOrderHoldsWithSalesChannelsTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private Business $other;

    private User $owner;

    private User $otherOwner;

    private Category $roses;

    /** @var array<string, int> */
    private array $p = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->setLocale('ar');

        [$this->shop, $this->owner] = $this->ribbon('ribbon', 'saud@abaad.om');
        [$this->other, $this->otherOwner] = $this->ribbon('other', 'other@abaad.om');

        $this->roses = Category::create(['business_id' => $this->shop->id, 'name' => 'ورد']);
        $gifts = Category::create(['business_id' => $this->shop->id, 'name' => 'هدايا']);

        // بأسماءٍ لاتينيّة: الأبجديّةُ العربيّة تختلف بين SQLite وPostgreSQL
        $this->p['white'] = $this->product('Aster white bouquet', $this->roses)->id;
        $this->p['red'] = $this->product('Begonia red bouquet', $this->roses)->id;
        $this->p['pink'] = $this->product('Camellia pink bouquet', $this->roses)->id;
        $this->p['card'] = $this->product('Daisy gift card', $gifts)->id;

        // وكلُّ قوائم الإذن تبدأ فارغة — فلا يصير خامسُ متجرٍ يُنشأ «سعود» بالصدفة
        config([
            'storefront.ribbon_shelf_order_businesses' => [$this->shop->id],
            'storefront.ribbon_catalog_editor_businesses' => [],
            'storefront.ribbon_curated_new_arrivals_businesses' => [],
            'storefront.ribbon_picks_businesses' => [],
        ]);
    }

    /** @return array{0: Business, 1: User} */
    private function ribbon(string $slug, string $email): array
    {
        $shop = Business::create([
            'name' => 'متجر '.$slug, 'type' => 'محل ورد', 'status' => 'نشط',
            'site_slug' => $slug, 'tier' => 'gold', 'storefront_theme' => 'ribbon',
        ]);
        Currency::create(['business_id' => $shop->id, 'code' => 'OMR', 'name' => 'ريال عماني', 'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);
        Ledger::seedChart($shop->id);
        Branch::create(['business_id' => $shop->id, 'name' => 'الرئيسي']);
        Setting::create(['business_id' => $shop->id, 'key' => 'vat_enabled', 'value' => '0']);
        MarketingSettings::save($shop->id, 'website', ['store_on' => '1']);

        $owner = User::create(['business_id' => $shop->id, 'name' => 'مالك '.$slug, 'email' => $email, 'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط']);

        return [$shop, $owner];
    }

    private function product(string $name, ?Category $category = null, array $over = [], ?Business $shop = null): Product
    {
        return Product::create($over + [
            'business_id' => ($shop ?? $this->shop)->id, 'name' => $name, 'price' => 10, 'category_id' => $category?->id,
            'cost' => 4, 'quantity' => 5, 'alert_qty' => 1, 'active' => true, 'published' => true,
        ]);
    }

    private function save(array $payload, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->owner)->from(route('admin.website.editor'))
            ->post(route('admin.marketing.store.save'), $payload);
    }

    private function saveShelf(array $order)
    {
        return $this->save([ShelfOrder::KEY => json_encode($order)]);
    }

    /** @return list<int> الرفُّ كما يراه الزائر */
    private function shelf(array $query = [], string $slug = 'ribbon'): array
    {
        auth()->logout();

        return array_column($this->get("/s/{$slug}/shop".($query ? '?'.http_build_query($query) : ''))->assertOk()->viewData('products'), 'id');
    }

    /** @return list<string> أقسامُ الرئيسية كما يراها الزائر */
    private function sections(string $lang = 'ar', string $slug = 'ribbon'): array
    {
        auth()->logout();

        return $this->get("/s/{$slug}".($lang === 'en' ? '?lang=en' : ''))->assertOk()->viewData('sections');
    }

    private function ids(string ...$keys): array
    {
        return array_map(fn ($k) => $this->p[$k], $keys);
    }

    private function sells(string $key, bool $pos, bool $online, bool $active = true): void
    {
        Product::whereKey($this->p[$key])->update(['pos_enabled' => $pos, 'published' => $online, 'active' => $active]);
    }

    /* ═════════════ القنواتُ في الرفّ المرتّب ═════════════ */

    public function test_an_online_only_product_takes_its_place_and_a_till_only_one_waits_unseen_in_its_own(): void
    {
        $this->sells('red', pos: false, online: true);
        $this->sells('pink', pos: true, online: false);

        $this->saveShelf([
            'all' => $this->ids('pink', 'red', 'white', 'card'),
            'categories' => [(string) $this->roses->id => $this->ids('pink', 'red', 'white')],
        ])->assertSessionHasNoErrors();

        $this->assertSame($this->ids('red', 'white', 'card'), $this->shelf(), '«للموقع فقط» غاب، أو ظهر «للصندوق فقط»');
        $this->assertSame($this->ids('red', 'white'), $this->shelf(['cat' => $this->roses->id]));

        // يُفتح للموقع فيعود إلى موضعه — لا إلى آخر الرفّ
        $this->sells('pink', pos: true, online: true);
        $this->assertSame($this->ids('pink', 'red', 'white', 'card'), $this->shelf(), 'عاد صنفُ الصندوق إلى غير موضعه');
        $this->assertSame($this->ids('pink', 'red', 'white'), $this->shelf(['cat' => $this->roses->id]));
    }

    public function test_a_product_that_lost_both_channels_or_was_stopped_returns_to_its_place(): void
    {
        $this->saveShelf(['all' => $this->ids('card', 'red', 'pink', 'white')])->assertSessionHasNoErrors();

        $this->sells('red', pos: false, online: false);
        $this->assertSame($this->ids('card', 'pink', 'white'), $this->shelf());

        $this->sells('red', pos: false, online: true);
        $this->assertSame($this->ids('card', 'red', 'pink', 'white'), $this->shelf(), 'صنفٌ عاد إلى الموقع فوقع في غير موضعه');

        // والموقوفُ عمومًا كذلك — ولو بقيت قناتاه مفتوحتين
        $this->sells('pink', pos: true, online: true, active: false);
        $this->assertSame($this->ids('card', 'red', 'white'), $this->shelf());
        $this->sells('pink', pos: true, online: true, active: true);
        $this->assertSame($this->ids('card', 'red', 'pink', 'white'), $this->shelf());
    }

    public function test_the_till_lists_its_products_as_before_whatever_the_shelf_order(): void
    {
        $this->sells('red', pos: false, online: true);
        $this->saveShelf(['all' => $this->ids('card', 'pink', 'red', 'white')])->assertSessionHasNoErrors();

        $branch = Branch::where('business_id', $this->shop->id)->firstOrFail();
        $this->actingAs($this->owner);
        $till = Demo::products($branch->id);

        // بالمعرّف كما كان (`Demo::products`) — لا بترتيب الرفّ
        $this->assertSame($this->ids('white', 'red', 'pink', 'card'), array_column($till, 'id'));
        // والصندوقُ يقرأ قناتَه من الصنف نفسِه: «للموقع فقط» لا يُباع فيه
        $flags = array_column($till, 'pos_enabled', 'id');
        $this->assertFalse($flags[$this->p['red']]);
        $this->assertTrue($flags[$this->p['white']]);
    }

    /* ═════════════ لغتان، ونشرٌ واحد ═════════════ */

    public function test_the_arabic_and_english_pages_read_one_order(): void
    {
        $this->saveShelf(['all' => $this->ids('card', 'pink', 'red', 'white')])->assertSessionHasNoErrors();
        $this->save(['store_sections' => 'about,!cats,best,new'])->assertSessionHasNoErrors();

        $this->assertSame($this->shelf(), $this->shelf(['lang' => 'en']));
        $this->assertSame($this->ids('card', 'pink', 'red', 'white'), $this->shelf(['lang' => 'en']));
        $this->assertSame(['about', 'best', 'new'], $this->sections('ar'));
        $this->assertSame(['about', 'best', 'new'], $this->sections('en'));
    }

    public function test_the_section_layout_and_the_shelf_order_wait_for_one_publish(): void
    {
        ThemePublisher::enable($this->shop, (int) $this->owner->id);

        $this->save(['store_sections' => 'about,!cats,best,new'])->assertSessionHasNoErrors();
        $this->saveShelf(['all' => $this->ids('card', 'pink', 'red', 'white')])->assertSessionHasNoErrors();

        // قبل النشر: الزائرُ على ما كان
        $this->assertSame(StorePage::DEFAULT_ORDER, $this->sections());
        $this->assertSame($this->ids('white', 'red', 'pink', 'card'), $this->shelf());

        // والمحرّرُ يُفتح على المسوّدة: المطفأُ في موضعه، والرفُّ بترتيبه
        $props = $this->actingAs($this->owner)->get(route('admin.website.editor'))->assertOk()->viewData('page')['props'];
        $this->assertSame(['about', '!cats', 'best', 'new', '!banner', '!block', '!reviews'], $props['layout']);
        $this->assertSame(json_encode(['all' => $this->ids('card', 'pink', 'red', 'white')]), $props['shelfOrder']['value']);

        $this->actingAs($this->owner)->from(route('admin.website.editor'))
            ->post(route('admin.website.store.publish'))->assertSessionHasNoErrors();

        $this->assertSame(['about', 'best', 'new'], $this->sections());
        $this->assertSame($this->ids('card', 'pink', 'red', 'white'), $this->shelf());
    }

    /* ═════════════ ومتجرٌ آخر ═════════════ */

    public function test_a_shop_outside_the_list_keeps_its_alphabet_with_its_channels_and_its_own_sections(): void
    {
        $cat = Category::create(['business_id' => $this->other->id, 'name' => 'ورد']);
        $b = $this->product('Bravo online only', $cat, ['pos_enabled' => false], $this->other)->id;
        $a = $this->product('Alpha both', $cat, [], $this->other)->id;
        $this->product('Charlie till only', $cat, ['published' => false], $this->other);

        $this->saveShelf(['all' => $this->ids('card', 'pink', 'red', 'white')])->assertSessionHasNoErrors();
        $this->save(['store_sections' => 'about,!cats,best,new'])->assertSessionHasNoErrors();

        $this->assertSame([$a, $b], $this->shelf([], 'other'));
        $this->assertSame(StorePage::DEFAULT_ORDER, $this->sections('ar', 'other'));
        $this->save([ShelfOrder::KEY => json_encode(['all' => [$b, $a]])], $this->otherOwner)->assertForbidden();
    }
}
