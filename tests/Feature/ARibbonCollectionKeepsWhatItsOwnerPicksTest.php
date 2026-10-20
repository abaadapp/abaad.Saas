<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Category;
use App\Models\Currency;
use App\Models\Product;
use App\Models\Setting;
use App\Models\StoreSite;
use App\Models\User;
use App\Support\MarketingSettings;
use App\Support\Store\StoreCollections;
use App\Support\Store\StoreContent;
use App\Support\Store\StoreCustomPages;
use App\Support\Store\ThemePublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * مجموعاتُ المتجر تحفظ ما اختاره صاحبُها — والسببُ الذي كان يُضيّعه.
 *
 * ═══ ما كان يقع ═══
 *
 *  • المحرّرُ يعرف المعروضَ وحده: صنفٌ أخفاه صاحبُه بعد اختياره يُسقَط من
 *    المجموعة حين تُفتح الشاشة (`keepShownCollections`) ويضيع مع أوّل حفظ.
 *  • والخادمُ يردّ حفظَ المجموعات كلِّها من أجل صنفٍ واحدٍ لا يُعرض، برسالةٍ
 *    لا تسمّيه — فلا يُحفظ شيءٌ حتّى يجده ويُزيله.
 *  • والمنتقي أسماءٌ عربيّةٌ في صندوقٍ صغيرٍ بلا بحث — لا اسمَ إنجليزيّ ولا فئة.
 *
 * ═══ وما يُحرَس الآن ═══
 *
 *  • المحرّرُ يُرسل المعروضَ باسمَيه وفئته، وما اختاره ثمّ أخفاه باسمه وصورته.
 *  • والحفظُ يُبقي صنفَ متجره ولو أُخفي، ولا يرسمه لزبونه حتّى يعود.
 *  • وصنفُ متجرٍ آخر يُردّ باسم المجموعة. والمجموعةُ تُنشر فتظهر بأصنافها.
 *
 * وقواعدُ الشاشة في `the-owner-picks-products-with-their-pictures.test.tsx`.
 */
class ARibbonCollectionKeepsWhatItsOwnerPicksTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private Business $neighbour;

    private User $owner;

    /** @var array<string, Product> */
    private array $p = [];

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');

        [$this->shop, $this->owner] = $this->ribbon('ribbon', 'owner@keep.test');
        [$this->neighbour] = $this->ribbon('jaar', 'jaar@keep.test');

        $cat = Category::create(['business_id' => $this->shop->id, 'name' => 'باقات', 'name_en' => 'Bouquets']);
        $make = fn (string $name, string $en, array $over = []) => Product::create($over + [
            'business_id' => $this->shop->id, 'name' => $name, 'name_en' => $en, 'price' => 10, 'cost' => 2, 'quantity' => 20,
            'category_id' => $cat->id, 'active' => true, 'published' => true,
        ]);
        $this->p['rose'] = $make('باقة ورد', 'Rose bouquet');
        $this->p['tulip'] = $make('باقة توليب', 'Tulip bouquet');
        $this->p['away'] = $make('باقة الموسم', 'Season bouquet', ['published' => false]);
        $this->p['other'] = $make('غير مختار', 'Not chosen', ['active' => false]);
        $this->p['foreign'] = Product::create([
            'business_id' => $this->neighbour->id, 'name' => 'منتج الجار', 'price' => 5, 'cost' => 1, 'quantity' => 5, 'active' => true, 'published' => false,
        ]);
    }

    /** @return array{0: Business, 1: User} */
    private function ribbon(string $slug, string $email): array
    {
        $shop = Business::create([
            'name' => 'متجر '.$slug, 'type' => 'محل ورد', 'status' => 'نشط', 'site_slug' => $slug,
            'tier' => 'gold', 'storefront_theme' => 'ribbon', 'phone' => '+96890000000',
        ]);
        Currency::create(['business_id' => $shop->id, 'code' => 'OMR', 'name' => 'ريال', 'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);
        Branch::create(['business_id' => $shop->id, 'name' => 'الرئيسي']);
        Setting::create(['business_id' => $shop->id, 'key' => 'vat_enabled', 'value' => '0']);
        $owner = User::create(['business_id' => $shop->id, 'name' => 'المالك', 'email' => $email, 'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط']);
        MarketingSettings::save($shop->id, 'website', ['store_on' => '1', 'store_pay_cod' => '1']);

        return [$shop, $owner];
    }

    private function collections(array $products, string $name = 'هدايا التخرّج'): string
    {
        return json_encode([[
            'slug' => 'graduation', 'title_ar' => $name, 'title_en' => 'Graduation', 'desc_ar' => '', 'desc_en' => '',
            'image' => '', 'visible' => true, 'in_row' => true, 'products' => $products,
        ]], JSON_UNESCAPED_UNICODE);
    }

    private function save(string $collections)
    {
        return $this->actingAs($this->owner)->post(route('admin.marketing.store.save'), [StoreCollections::KEY => $collections]);
    }

    /** @return array<string, mixed> */
    private function props(): array
    {
        return $this->actingAs($this->owner)->get(route('admin.website.editor'))->assertOk()->viewData('page')['props'];
    }

    public function test_the_editor_sends_both_names_and_the_category_so_the_owner_can_search(): void
    {
        $rows = collect($this->props()['products'])->keyBy('id');

        $this->assertSame(['باقة ورد', 'Rose bouquet', 'باقات'], [
            $rows[$this->p['rose']->id]['name'], $rows[$this->p['rose']->id]['name_en'], $rows[$this->p['rose']->id]['category'],
        ]);
        $this->assertFalse($rows->has($this->p['away']->id), 'والمنتقي يعرض المعروضَ وحده');
        $this->assertFalse($rows->has($this->p['foreign']->id), 'ومن متجره وحده');
    }

    public function test_a_product_hidden_after_it_was_chosen_reaches_the_editor_and_survives_a_save(): void
    {
        // اختاره وهو معروض…
        MarketingSettings::save($this->shop->id, 'website', [StoreCollections::KEY => $this->collections([$this->p['rose']->id, $this->p['away']->id])]);

        // …ثمّ أخفاه. والمحرّرُ يعرفه باسمه — لا رقمًا يُسقَط
        $hidden = collect($this->props()['hiddenProducts']);
        $this->assertSame([$this->p['away']->id], $hidden->pluck('id')->all(), 'المخفيُّ المختارُ وحده — لا كلُّ مخفيّ');
        $this->assertSame('باقة الموسم', $hidden->first()['name']);

        // والشاشةُ ترسل المجموعةَ كما هي — فتُحفظ بأصنافها
        $this->save($this->collections([$this->p['rose']->id, $this->p['away']->id]))->assertSessionHasNoErrors();
        $this->assertSame([$this->p['rose']->id, $this->p['away']->id], StoreCollections::all($this->shop->id)[0]['products']);

        // ويعود يومَ يُعرض
        $this->p['away']->forceFill(['published' => true])->save();
        $html = $this->get('/s/ribbon/shop?collection=graduation')->assertOk()->getContent();
        $this->assertStringContainsString('/s/ribbon/p/'.$this->p['away']->id.'"', $html);
    }

    public function test_a_page_product_hidden_after_it_was_chosen_reaches_the_editor_too(): void
    {
        MarketingSettings::save($this->shop->id, 'website', [StoreCustomPages::KEY => json_encode([[
            'slug' => 'gifts', 'kind' => 'products', 'title_ar' => 'هدايا', 'title_en' => '', 'intro_ar' => '', 'intro_en' => '',
            'blocks' => [['type' => 'products', 'title_ar' => '', 'title_en' => '', 'products' => [$this->p['away']->id]]], 'visible' => true,
        ]], JSON_UNESCAPED_UNICODE)]);

        $this->assertSame([$this->p['away']->id], array_column($this->props()['hiddenProducts'], 'id'));
    }

    public function test_a_product_of_another_shop_is_refused_by_its_collections_name(): void
    {
        $this->save($this->collections([$this->p['rose']->id, $this->p['foreign']->id], 'المناسبات'))
            ->assertSessionHasErrors([StoreCollections::KEY => 'منتجٌ في مجموعة «المناسبات» ليس من منتجاتك — أزله واختر من منتجاتك.']);

        $this->assertSame([], StoreCollections::all($this->shop->id));
    }

    public function test_a_collection_is_saved_published_and_shown_with_its_products(): void
    {
        ThemePublisher::enable($this->shop, (int) $this->owner->id);

        $this->save($this->collections([$this->p['tulip']->id, $this->p['rose']->id]))->assertSessionHasNoErrors();
        $this->get('/s/ribbon/shop?collection=graduation')->assertNotFound();

        ThemePublisher::publish($this->shop, (int) $this->owner->id, 'المجموعات');

        $html = $this->get('/s/ribbon/shop?collection=graduation')->assertOk()->getContent();
        preg_match_all('#href="/s/ribbon/p/(\d+)" class="rb-card"#', $html, $m);
        $this->assertSame([$this->p['tulip']->id, $this->p['rose']->id], array_map('intval', $m[1]), 'بأصنافها وبترتيبه');
    }

    /* ═══════════════════════ والتراجعُ عن المسوّدة ═══════════════════════ */

    public function test_unpublished_changes_can_be_taken_back_without_touching_the_live_shop(): void
    {
        MarketingSettings::save($this->shop->id, 'website', ['store_hero_title' => 'العنوان المنشور']);
        ThemePublisher::enable($this->shop, (int) $this->owner->id);
        ThemePublisher::enable($this->neighbour, (int) $this->owner->id);

        $this->actingAs($this->owner)->post(route('admin.marketing.store.save'), ['store_hero_title' => 'عنوانٌ لم يُنشر'])->assertSessionHasNoErrors();
        ThemePublisher::saveDraft((int) $this->neighbour->id, ['store_hero_title' => 'مسوّدةُ الجار']);
        $revision = (int) StoreSite::where('business_id', $this->shop->id)->value('draft_revision');
        $this->assertNotSame([], StoreContent::changed($this->shop->id));

        $this->actingAs($this->owner)->post(route('admin.website.store.discard'))->assertSessionHasNoErrors();

        $this->assertSame([], StoreContent::changed($this->shop->id), 'عادت المسوّدةُ إلى المنشور');
        $this->assertSame('العنوان المنشور', StoreContent::draft($this->shop->id)['store_hero_title']);
        $this->assertSame('العنوان المنشور', MarketingSettings::group($this->shop->id, 'website')['store_hero_title'], 'والحيُّ لم يُمسّ');
        $this->assertGreaterThan($revision, (int) StoreSite::where('business_id', $this->shop->id)->value('draft_revision'), 'ومن فتح الشاشةَ قبل التراجع يُقال له');
        $this->assertSame('مسوّدةُ الجار', StoreContent::draft($this->neighbour->id)['store_hero_title'], 'ومسوّدةُ جاره كما هي');

        // ولا تغييرَ يُتراجَع عنه — فلا يتبدّل شيء
        $after = (int) StoreSite::where('business_id', $this->shop->id)->value('draft_revision');
        $this->actingAs($this->owner)->post(route('admin.website.store.discard'))->assertSessionHasNoErrors();
        $this->assertSame($after, (int) StoreSite::where('business_id', $this->shop->id)->value('draft_revision'));
    }

    public function test_a_clerk_cannot_take_back_the_owners_changes(): void
    {
        ThemePublisher::enable($this->shop, (int) $this->owner->id);
        $clerk = User::create(['business_id' => $this->shop->id, 'name' => 'موظّف', 'email' => 'clerk@keep.test', 'password' => bcrypt('x'), 'role' => 'cashier', 'status' => 'نشط']);

        $this->actingAs($clerk)->post(route('admin.website.store.discard'))->assertForbidden();
    }
}
