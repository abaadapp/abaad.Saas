<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Category;
use App\Models\Currency;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Support\Ledger;
use App\Support\MarketingSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ترتيبُ الفئات في الموقع — بيد صاحبها لا بالأبجديّة.
 *
 * ═══ العطب الذي وُضع له ═══
 *
 * بطاقاتُ «تسوّق حسب الفئة» وروابطُ التذييل كانت بالاسم: «الإضافات» قبل
 * «منتجات» لأنّ الألف قبل الميم، ولا سبيلَ إلى غير ذلك إلّا بتبديل الاسم.
 *
 * ═══ وما يُحرَس ═══
 *
 *   - قبل أن يرتّب أحد: الأبجديّةُ كما كانت.
 *   - بعده: البطاقاتُ وروابطُ التذييل واللوحةُ بترتيبه — ترتيبٌ واحد.
 *   - الفئةُ الجديدة تقع آخرًا، والقائمةُ الناقصة يُلحق باقيها بترتيبه.
 *   - معرّفٌ من متجرٍ آخر أو مكرَّر: تُردّ القائمةُ كلُّها ولا يُكتب شيء.
 */
class ACategoryOrderIsTheShopsOwnTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private User $owner;

    /** @var array<string, Category> */
    private array $cats = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = $this->shop('RIBBON', 'ribbon');
        $this->owner = User::create(['business_id' => $this->shop->id, 'name' => 'سعود', 'email' => 'saud@abaad.om', 'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط']);
        MarketingSettings::save($this->shop->id, 'website', ['store_on' => '1']);
        config(['storefront.ribbon_catalog_editor_businesses' => [$this->shop->id]]);

        // بالأبجديّة: الإضافات، باقات، منتجات
        foreach (['منتجات', 'الإضافات', 'باقات'] as $name) {
            $this->cats[$name] = $this->stocked($this->shop, $name);
        }
    }

    private function shop(string $name, string $slug): Business
    {
        $shop = Business::create([
            'name' => $name, 'type' => 'محل ورد', 'status' => 'نشط', 'phone' => '96895259066',
            'city' => 'مسقط', 'site_slug' => $slug, 'tier' => 'gold', 'storefront_theme' => 'ribbon',
        ]);
        Currency::create(['business_id' => $shop->id, 'code' => 'OMR', 'name' => 'ريال عماني', 'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);
        Ledger::seedChart($shop->id);
        Branch::create(['business_id' => $shop->id, 'name' => 'الخوير']);
        Setting::create(['business_id' => $shop->id, 'key' => 'vat_enabled', 'value' => '0']);

        return $shop;
    }

    /** فئةٌ فيها صنفٌ معروض — فتُرسم بطاقتُها */
    private function stocked(Business $shop, string $name): Category
    {
        $category = Category::create(['business_id' => $shop->id, 'name' => $name]);
        Product::create([
            'business_id' => $shop->id, 'name' => 'صنف '.$name, 'price' => 10, 'category_id' => $category->id,
            'cost' => 4, 'quantity' => 5, 'alert_qty' => 1, 'active' => true, 'published' => true,
        ]);

        return $category;
    }

    /** @param list<string> $names */
    private function ids(array $names): array
    {
        return array_map(fn ($n) => $this->cats[$n]->id, $names);
    }

    private function order(array $ids)
    {
        return $this->actingAs($this->owner)->putJson(route('admin.products.categories.order'), ['ids' => $ids]);
    }

    /** @return array{cards: list<int>, footer: list<int>, panel: list<int>} */
    private function seen(): array
    {
        $home = $this->get('/s/ribbon')->assertOk();
        $panel = $this->actingAs($this->owner)->get(route('admin.website.editor'))->assertOk()
            ->viewData('page')['props']['catalogTools']['categories'];

        return [
            'cards' => array_column($home->viewData('categories')->all(), 'id'),
            'footer' => array_column($home->viewData('catsNav'), 'id'),
            'panel' => array_column($panel, 'id'),
        ];
    }

    public function test_before_anyone_orders_it_stays_alphabetical(): void
    {
        $abc = $this->ids(['الإضافات', 'باقات', 'منتجات']);

        $this->assertSame(['cards' => $abc, 'footer' => $abc, 'panel' => $abc], $this->seen());
    }

    public function test_the_cards_the_footer_and_the_panel_follow_his_order(): void
    {
        $mine = $this->ids(['منتجات', 'باقات', 'الإضافات']);

        $this->order($mine)->assertOk()->assertJson(['ok' => true, 'ids' => $mine]);

        $this->assertSame(['cards' => $mine, 'footer' => $mine, 'panel' => $mine], $this->seen(), 'الترتيبُ لم يصل كلَّ موضع');
        $this->assertSame([1, 2, 3], array_column($this->actingAs($this->owner)->get(route('admin.website.editor'))
            ->viewData('page')['props']['catalogTools']['categories'], 'sort_order'));
    }

    /** والفئةُ الجديدة بعد الترتيب تقع آخرًا — لا وسطَ ترتيبه بحرفها */
    public function test_a_new_category_lands_at_the_end(): void
    {
        $this->order($this->ids(['منتجات', 'باقات', 'الإضافات']))->assertOk();
        $new = $this->stocked($this->shop, 'أ ورود');

        $this->assertSame([...$this->ids(['منتجات', 'باقات', 'الإضافات']), $new->id], $this->seen()['cards']);
    }

    /** وقائمةٌ ناقصة — فئةٌ أُضيفت من لسانٍ آخر — يُلحق باقيها بترتيبه القائم */
    public function test_a_short_list_keeps_the_rest_after_it_in_their_order(): void
    {
        $this->order($this->ids(['باقات']))->assertOk()
            ->assertJsonPath('ids', $this->ids(['باقات', 'الإضافات', 'منتجات']));

        $this->assertSame($this->ids(['باقات', 'الإضافات', 'منتجات']), $this->seen()['cards']);
    }

    /** والبطاقةُ المخفيّة تغيب عن الرئيسيّة وتحفظ مكانها في الترتيب */
    public function test_a_hidden_card_keeps_its_place(): void
    {
        $this->order($this->ids(['منتجات', 'الإضافات', 'باقات']))->assertOk();
        $this->cats['الإضافات']->update(['card_hidden' => true]);

        $seen = $this->seen();
        $this->assertSame($this->ids(['منتجات', 'باقات']), $seen['cards']);
        $this->assertSame($this->ids(['منتجات', 'الإضافات', 'باقات']), $seen['footer']);
    }

    /** معرّفُ متجرٍ آخر: تُردّ القائمةُ كلُّها — ولا يُكتب في المتجرين شيء */
    public function test_a_list_with_another_shops_category_is_refused_whole(): void
    {
        $other = $this->shop('متجر آخر', 'other');
        $theirs = $this->stocked($other, 'فئتهم');
        DB::table('categories')->where('id', $theirs->id)->update(['sort_order' => 7]);

        $this->order([...$this->ids(['منتجات', 'باقات']), $theirs->id])
            ->assertStatus(422)
            ->assertJsonPath('errors', ['ids.2' => ['في الترتيب فئةٌ ليست في متجرك — حدّث الصفحة.']]);

        $this->assertSame([null, null, null], Category::where('business_id', $this->shop->id)->orderBy('id')->pluck('sort_order')->all());
        $this->assertSame(7, (int) DB::table('categories')->where('id', $theirs->id)->value('sort_order'));
    }

    public function test_a_repeated_or_empty_list_is_refused(): void
    {
        $roses = $this->cats['باقات']->id;

        $this->order([$roses, $roses])->assertStatus(422)->assertJsonValidationErrors('ids.0');
        $this->order([])->assertStatus(422)->assertJsonValidationErrors('ids');

        $this->assertSame([null, null, null], Category::where('business_id', $this->shop->id)->pluck('sort_order')->all());
    }
}
