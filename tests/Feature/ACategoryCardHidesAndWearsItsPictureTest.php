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
use App\Support\Store\PageEditor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * بطاقةُ القسم في «تسوّق حسب الفئة» — تُخفى بيد صاحبها، وتلبس صورتَه.
 *
 * ═══ العطب الذي وُضعت له ═══
 *
 * سعود يريد أن تغيب «الإضافات» عن بطاقات الرئيسيّة، ولم يكن أمامه إلّا أن
 * يُخفي أصنافَها من المتجر كلِّه — فتغيب عن الطلب أيضًا. والبطاقةُ خطوطٌ
 * ملوّنةٌ ثابتة لا تقبل صورة.
 *
 * ═══ وما يُحرَس ═══
 *
 *   - الإخفاءُ يُسقط البطاقةَ من الرئيسيّة وحدها: الرفُّ ومرشِّحُه وأصنافُه باقية.
 *   - الصورةُ تُرفع في مجلّد النشاط وتظهر في البطاقة، وإزالتُها تُعيد الخطوط.
 *   - الصورةُ صورة: لا SVG ولا ملفٌّ آخر.
 *   - قسمُ متجرٍ آخر: ٤٠٤ ولا يُمسّ ولا يُرفع له ملفّ.
 *   - تنبيهُ المحرّر «لا يظهر» يعدّ المخفيَّ غائبًا — كما تعدّه الواجهة.
 */
class ACategoryCardHidesAndWearsItsPictureTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private User $owner;

    private Category $roses;

    private Category $addons;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = $this->shop('RIBBON', 'ribbon');
        $this->owner = User::create(['business_id' => $this->shop->id, 'name' => 'سعود', 'email' => 'saud@abaad.om', 'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط']);
        MarketingSettings::save($this->shop->id, 'website', ['store_on' => '1']);

        $this->roses = Category::create(['business_id' => $this->shop->id, 'name' => 'باقات']);
        $this->addons = Category::create(['business_id' => $this->shop->id, 'name' => 'الإضافات']);
        $this->product('باقة الربيع', $this->roses);
        $this->product('بطاقة إهداء', $this->addons);
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

    private function product(string $name, Category $category): Product
    {
        return Product::create([
            'business_id' => $category->business_id, 'name' => $name, 'price' => 10, 'category_id' => $category->id,
            'cost' => 4, 'quantity' => 5, 'alert_qty' => 1, 'active' => true, 'published' => true,
        ]);
    }

    /** @return list<int> */
    private function homeCards(): array
    {
        return array_column($this->get('/s/ribbon')->assertOk()->viewData('categories')->all(), 'id');
    }

    private function hide(Category $category, bool $hidden = true)
    {
        return $this->actingAs($this->owner)
            ->patchJson(route('admin.products.categories.card', $category->id), ['card_hidden' => $hidden]);
    }

    /* ═══════════ الإخفاء ═══════════ */

    public function test_hiding_a_card_drops_it_from_the_home_section_only(): void
    {
        $this->assertSame([$this->addons->id, $this->roses->id], $this->homeCards());

        $this->hide($this->addons)->assertOk()
            ->assertJson(['ok' => true, 'category' => ['id' => $this->addons->id, 'card_hidden' => true, 'card_image' => null]]);

        $this->assertSame([$this->roses->id], $this->homeCards(), 'البطاقةُ المخفيّة بقيت في الرئيسيّة');

        // والرفُّ ومرشِّحُه كما كانا: القسمُ يُختار وأصنافُه تُباع
        $shop = $this->get('/s/ribbon/shop')->assertOk();
        $this->assertContains($this->addons->id, array_column($shop->viewData('categories')->all(), 'id'), 'الإخفاءُ أسقط القسمَ من رفّ المتجر');
        $this->get('/s/ribbon/shop?cat='.$this->addons->id)->assertOk()->assertSee('بطاقة إهداء');
        $this->assertSame(2, Product::where('business_id', $this->shop->id)->where('active', true)->where('published', true)->count());
    }

    public function test_a_hidden_card_comes_back_when_shown(): void
    {
        $this->hide($this->addons)->assertOk();
        $this->hide($this->addons, false)->assertOk()->assertJsonPath('category.card_hidden', false);

        $this->assertSame([$this->addons->id, $this->roses->id], $this->homeCards());
    }

    /** وكلُّ البطاقات مخفيّة: لا قسمَ ولا زرَّ «استكشف» يقفز إلى لا شيء */
    public function test_every_card_hidden_draws_no_section_and_no_explore_button(): void
    {
        $this->hide($this->addons)->assertOk();
        $this->hide($this->roses)->assertOk();

        $this->get('/s/ribbon')->assertOk()
            ->assertDontSee('data-testid="rb-sec-cats"', false)
            ->assertDontSee('href="#rb-cats"', false);
    }

    public function test_the_flag_must_be_said(): void
    {
        $this->actingAs($this->owner)
            ->patchJson(route('admin.products.categories.card', $this->addons->id), [])
            ->assertStatus(422)->assertJsonValidationErrors('card_hidden');

        $this->assertFalse($this->addons->fresh()->card_hidden);
    }

    /* ═══════════ الصورة ═══════════ */

    public function test_the_card_wears_the_uploaded_picture_and_the_others_keep_their_stripes(): void
    {
        Storage::fake('public');

        $response = $this->actingAs($this->owner)
            ->post(route('admin.products.categories.cardImage', $this->roses->id), ['image' => UploadedFile::fake()->image('roses.jpg', 800, 600)], ['Accept' => 'application/json'])
            ->assertOk();

        $url = $response->json('category.card_image');
        $this->assertStringStartsWith('/storage/website/'.$this->shop->id.'/', $url, 'الصورةُ خارج مجلّد النشاط');
        Storage::disk('public')->assertExists(substr($url, strlen('/storage/')));
        $this->assertSame($url, $this->roses->fresh()->card_image);

        $html = $this->get('/s/ribbon')->assertOk()->getContent();
        $this->assertStringContainsString('<img src="'.$url.'"', $html, 'البطاقةُ لم تلبس صورتَها');
        $this->assertSame(1, substr_count($html, 'data-testid="rb-cat-image"'), 'صورةُ قسمٍ ظهرت على بطاقة غيره');
        $this->assertStringContainsString('repeating-linear-gradient', $html, 'البطاقةُ بلا صورة فقدت خطوطها');
    }

    public function test_removing_the_picture_brings_back_the_stripes_and_keeps_the_file(): void
    {
        Storage::fake('public');
        $url = $this->actingAs($this->owner)
            ->post(route('admin.products.categories.cardImage', $this->roses->id), ['image' => UploadedFile::fake()->image('roses.png')], ['Accept' => 'application/json'])
            ->assertOk()->json('category.card_image');

        $this->actingAs($this->owner)
            ->deleteJson(route('admin.products.categories.cardImage.clear', $this->roses->id))
            ->assertOk()->assertJsonPath('category.card_image', null);

        $this->assertNull($this->roses->fresh()->card_image);
        $this->assertStringNotContainsString('data-testid="rb-cat-image"', $this->get('/s/ribbon')->getContent());
        // نسخةٌ على القرص أرخصُ من صورةٍ ضاعت
        Storage::disk('public')->assertExists(substr($url, strlen('/storage/')));
    }

    /** ولا تُرفع إلّا صورة — وSVG نصٌّ يُنفَّذ في صفحة الزبون */
    public function test_only_a_picture_is_taken(): void
    {
        Storage::fake('public');

        $svg = UploadedFile::fake()->createWithContent('x.svg', '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"></svg>');
        $pdf = UploadedFile::fake()->create('menu.pdf', 10, 'application/pdf');

        foreach ([$svg, $pdf] as $file) {
            $this->actingAs($this->owner)
                ->post(route('admin.products.categories.cardImage', $this->roses->id), ['image' => $file], ['Accept' => 'application/json'])
                ->assertStatus(422)->assertJsonPath('errors.image.0', 'الملفّ صورة — PNG أو JPG أو WEBP');
        }

        $this->assertNull($this->roses->fresh()->card_image);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    /* ═══════════ متجرٌ آخر ═══════════ */

    public function test_another_shops_category_is_not_found_and_not_touched(): void
    {
        Storage::fake('public');
        $other = $this->shop('متجر آخر', 'other');
        $theirs = Category::create(['business_id' => $other->id, 'name' => 'فئتهم', 'card_image' => '/storage/website/'.$other->id.'/theirs.jpg']);
        $before = (array) DB::table('categories')->find($theirs->id);

        $this->hide($theirs)->assertNotFound();
        $this->actingAs($this->owner)
            ->post(route('admin.products.categories.cardImage', $theirs->id), ['image' => UploadedFile::fake()->image('x.jpg')], ['Accept' => 'application/json'])
            ->assertNotFound();
        $this->actingAs($this->owner)->deleteJson(route('admin.products.categories.cardImage.clear', $theirs->id))->assertNotFound();

        $this->assertEquals($before, (array) DB::table('categories')->find($theirs->id), 'قسمُ متجرٍ آخر تغيّر');
        $this->assertSame([], Storage::disk('public')->allFiles(), 'رُفع ملفٌّ لقسمٍ ليس له');
    }

    /* ═══════════ المحرّر ═══════════ */

    /** التنبيهُ يعدّ المخفيَّ غائبًا — والقسمُ لا يُرسم فعلًا */
    public function test_the_editor_says_the_section_is_silent_when_every_stocked_card_is_hidden(): void
    {
        MarketingSettings::save($this->shop->id, 'website', ['store_sections' => 'cats']);
        $silent = fn () => collect(PageEditor::rows($this->shop->id))->firstWhere('key', 'cats')['silent'];

        $this->hide($this->addons)->assertOk();
        $this->assertNull($silent(), 'قسمٌ فيه بطاقةٌ ظاهرة قيل عنه إنّه لا يظهر');

        $this->hide($this->roses)->assertOk();
        $this->assertSame(PageEditor::SILENT['cats'], $silent(), 'كلُّ البطاقات مخفيّة والمحرّرُ ساكت');
    }

    /** واللوحةُ تحمل حالَ كلِّ بطاقة — لمن فُتحت له وحده */
    public function test_the_panel_carries_each_cards_state(): void
    {
        config(['storefront.ribbon_catalog_editor_businesses' => [$this->shop->id]]);
        DB::table('categories')->where('id', $this->roses->id)->update(['card_image' => '/storage/website/'.$this->shop->id.'/r.jpg']);
        $this->hide($this->addons)->assertOk();

        $cats = collect($this->actingAs($this->owner)->get(route('admin.website.editor'))->assertOk()
            ->viewData('page')['props']['catalogTools']['categories'])->keyBy('id');

        $this->assertSame([true, null], [$cats[$this->addons->id]['card_hidden'], $cats[$this->addons->id]['card_image']]);
        $this->assertSame([false, '/storage/website/'.$this->shop->id.'/r.jpg'], [$cats[$this->roses->id]['card_hidden'], $cats[$this->roses->id]['card_image']]);
    }
}
