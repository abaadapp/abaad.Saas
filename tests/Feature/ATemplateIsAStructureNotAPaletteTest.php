<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Models\Website;
use App\Support\Website\Blueprints;
use App\Support\Website\Builder;
use App\Support\Website\Content;
use App\Support\Website\Layout;
use App\Support\Website\Preview;
use App\Support\Website\Publication;
use App\Support\Website\Publisher;
use App\Support\Website\Templates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * القالب بنيةٌ لا لوحةُ ألوان.
 *
 * `presets` تختار للقالب بدايةَ **مقابضَ قائمة** — ارتفاعُ الواجهة وعددُ
 * الأعمدة — وتبقى ملكَ التاجر بعد الإنشاء. وهذا نصفُ الجواب: قالبان يختلفان
 * في ارتفاع واجهةٍ وعددِ عمودٍ يبقيان صفحةً واحدة ما دامت الترويسةُ رسمًا
 * واحدًا والبطاقةُ رسمًا واحدًا.
 *
 * فهذه الاختبارات تحرس النصف الثاني: رموزَ **البنية** التي يقرؤها العارض
 * فيرسم بناءً آخر. وتحرس معها ما **لم** يتغيّر — وهو الأهمّ: موقعٌ قائمٌ على
 * قالبٍ قديم يُرسم اليوم كما رُسم أمس، ونشرةٌ نُشرت قبل هذه الطبقة تُفتح كما
 * نُشرت.
 *
 * فلو كسر تغييرٌ لاحقٌ هذا التوافق لسقط هنا، لا في شكوى تاجرٍ يقول إنّ موقعه
 * تبدّل وهو لم يلمسه.
 */
class ATemplateIsAStructureNotAPaletteTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create([
            'name' => 'ورود مسقط', 'type' => 'محل ورود', 'status' => 'نشط',
            'phone' => '96890000000', 'email' => 'shop@abaad.om', 'city' => 'مسقط',
        ]);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->actingAs($this->owner);
    }

    private function bid(): int
    {
        return $this->business->id;
    }

    private function catalogue(int $products = 1): void
    {
        $category = Category::create(['business_id' => $this->bid(), 'name' => 'باقات']);

        for ($i = 1; $i <= $products; $i++) {
            Product::create([
                'business_id' => $this->bid(), 'category_id' => $category->id,
                'name' => "باقة رقم {$i}", 'price' => 10 + $i, 'active' => true, 'quantity' => 5,
            ]);
        }
    }

    private function props(string $url): array
    {
        return $this->get($url)->assertOk()->viewData('page')['props'];
    }

    /* ══════════════════ ما يُعرض على من يختار ══════════════════ */

    public function test_the_chooser_is_offered_four_templates_and_no_old_one(): void
    {
        $this->assertSame(['atelier', 'souq', 'bloom', 'mono'], array_column(Templates::featured(), 'key'));

        foreach (Templates::FEATURED as $key) {
            $this->assertFalse(Templates::isLegacy($key), $key);
        }
    }

    public function test_each_of_the_four_carries_a_structure_not_only_colours(): void
    {
        $structures = [];

        foreach (Templates::FEATURED as $key) {
            $layout = Templates::layout($key);

            // ولا واحدٌ منها على الافتراضيّ: قالبٌ بلا بنيةٍ خاصّة ليس قالبًا
            $this->assertNotSame(Layout::defaults(), $layout, $key);

            $structures[] = json_encode([
                $layout['header'], $layout['hero'], $layout['card'], $layout['grid'], $layout['footer'],
            ]);
        }

        // ولا يتشابه اثنان في ترويسته وواجهته وبطاقته وشبكته وتذييله
        $this->assertCount(4, array_unique($structures));
    }

    public function test_an_old_template_keeps_rendering_exactly_as_it_did(): void
    {
        foreach (['minimal', 'modern', 'bold', 'luxury', 'fashion', 'food'] as $key) {
            $this->assertTrue(Templates::isLegacy($key), $key);
            // رموزُ بنيتها هي الافتراضيّة — وهي رسمُ ما قبل هذه الطبقة حرفيًّا
            $this->assertSame(Layout::defaults(), Templates::layout($key), $key);
        }
    }

    /* ══════════════════ الرموز تصل العارض ══════════════════ */

    public function test_a_site_carries_its_structure_tokens_beside_its_colours(): void
    {
        $site = Builder::create($this->business, Blueprints::STORE, 'souq', $this->owner->id);
        $tokens = $site->tokens();

        $this->assertSame('#b4123b', $tokens['primary']);
        $this->assertSame('commerce', $tokens['header']);
        $this->assertSame('dense', $tokens['grid']);
        $this->assertSame('compact', $tokens['density']);
    }

    public function test_a_site_built_before_this_layer_reads_its_template_structure(): void
    {
        $site = Builder::create($this->business, Blueprints::STORE, 'modern', $this->owner->id);

        // موقعٌ قديم: لا عمودَ بنيةٍ له أصلًا
        $site->forceFill(['layout' => null])->save();

        $tokens = $site->fresh()->tokens();

        foreach (Layout::defaults() as $key => $value) {
            $this->assertSame($value, $tokens[$key], $key);
        }
    }

    public function test_an_unknown_structure_value_falls_back_instead_of_breaking_the_page(): void
    {
        $layout = Layout::normalize(['hero' => 'قالبٌ لا وجود له', 'card' => 'commerce']);

        $this->assertSame('classic', $layout['hero']);
        $this->assertSame('commerce', $layout['card']);
    }

    /* ══════════════════ تبديل القالب ══════════════════ */

    public function test_switching_a_template_takes_its_structure_with_its_colours(): void
    {
        $site = Builder::create($this->business, Blueprints::STORE, 'souq', $this->owner->id);

        $this->put(route('admin.website.design.update'), ['template' => 'atelier', 'adopt' => true])
            ->assertRedirect();

        $site = $site->fresh();

        $this->assertSame('editorial', $site->layout['hero']);
        $this->assertSame('amiri', $site->layout['heading_font']);
        // والمحتوى لا يُمسّ: تبديلُ قالبٍ ليس إعادةَ بناء
        $this->assertSame(4, $site->pages()->count());
    }

    public function test_tuning_one_structure_token_does_not_reset_the_others(): void
    {
        $site = Builder::create($this->business, Blueprints::STORE, 'atelier', $this->owner->id);

        $this->put(route('admin.website.design.layout'), [
            'layout' => ['density' => 'compact'] + $site->layout,
        ])->assertStatus(303);

        $site = $site->fresh();

        $this->assertSame('compact', $site->layout['density']);
        $this->assertSame('editorial', $site->layout['hero']);
        $this->assertSame('atelier', $site->template);
    }

    /* ══════════════════ ما يُنشر وما يُستعاد ══════════════════ */

    public function test_a_published_document_carries_the_structure(): void
    {
        $site = Builder::create($this->business, Blueprints::STORE, 'bloom', $this->owner->id);
        $payload = Publisher::publish($site, $this->owner->id)->payload;

        $this->assertSame(Publication::SCHEMA, $payload['schema_version']);
        $this->assertSame('split', $payload['layout']['hero']);
        // والعارضُ يقرؤها من `tokens` — لا يعرف المفتاح الآخر أصلًا
        $this->assertSame('split', $payload['tokens']['hero']);
        $this->assertSame('#db2777', $payload['tokens']['primary']);
    }

    public function test_a_document_published_before_this_layer_is_upgraded_to_what_it_looked_like(): void
    {
        $site = Builder::create($this->business, Blueprints::STORE, 'modern', $this->owner->id);
        $version = Publisher::publish($site, $this->owner->id);

        // نشرةٌ من النسخة الثانية: لا `layout` فيها ولا رموزَ بنيةٍ في `tokens`
        $payload = $version->payload;
        $payload['schema_version'] = 2;
        unset($payload['layout']);
        $payload['tokens'] = array_diff_key($payload['tokens'], Layout::defaults());

        $upgraded = Publication::upgrade($payload);

        $this->assertSame(Layout::defaults(), $upgraded['layout']);
        $this->assertSame('classic', $upgraded['tokens']['hero']);
        $this->assertSame('minimal', $upgraded['tokens']['header']);
    }

    public function test_restoring_an_old_version_brings_back_the_shape_it_had(): void
    {
        $site = Builder::create($this->business, Blueprints::STORE, 'atelier', $this->owner->id);
        $version = Publisher::publish($site, $this->owner->id);

        $this->put(route('admin.website.design.update'), ['template' => 'souq', 'adopt' => true]);
        $this->assertSame('commerce', $site->fresh()->layout['header']);

        Publisher::restore($site->fresh(), $version);

        $this->assertSame('editorial', $site->fresh()->layout['header']);
    }

    /* ══════════════════ صفحة المتجر ══════════════════ */

    public function test_a_new_store_gets_a_shop_page_that_holds_the_whole_catalogue(): void
    {
        $this->catalogue(3);

        $site = Builder::create($this->business, Blueprints::STORE, 'souq', $this->owner->id);
        $shop = $site->pages()->where('key', 'shop')->firstOrFail();

        $this->assertSame(['product_catalog'], $shop->sections()->pluck('type')->all());
    }

    public function test_the_shop_page_is_given_more_than_a_section_of_eight(): void
    {
        $this->catalogue(30);

        $site = Builder::create($this->business, Blueprints::STORE, 'souq', $this->owner->id);
        $document = Preview::document($site);

        $catalog = collect($document['pages'])->firstWhere('key', 'shop')['sections'][0];

        $this->assertSame('product_catalog', $catalog['type']);
        $this->assertCount(30, $catalog['items']);
        $this->assertGreaterThan(Preview::MAX, count($catalog['items']));
    }

    public function test_a_merchant_with_no_products_gets_no_empty_shop_section(): void
    {
        $site = Builder::create($this->business, Blueprints::STORE, 'souq', $this->owner->id);
        $shop = $site->pages()->where('key', 'shop')->firstOrFail();

        $this->assertSame(0, $shop->sections()->count());
    }

    /* ══════════════════ اختيار القسم يعلو على قالبه ══════════════════ */

    public function test_a_section_may_override_its_template_layout_and_a_bad_value_may_not(): void
    {
        $clean = Content::clean('hero', ['layout' => 'split', 'title' => 'باقات'], Blueprints::STORE);

        $this->assertSame('split', $clean['layout']);

        $bad = Content::clean('hero', ['layout' => 'أيًّا كان'], Blueprints::STORE);

        $this->assertSame('auto', $bad['layout']);
    }

    /* ══════════════════ ما تعرضه الشاشتان ══════════════════ */

    public function test_the_picker_draws_the_merchants_own_shop_in_four_structures(): void
    {
        $this->catalogue(2);

        $cards = $this->props(route('admin.website.index'))['templates'];

        $this->assertCount(4, $cards);

        $atelier = collect($cards)->firstWhere('key', 'atelier')['document'];

        $this->assertSame('ورود مسقط', $atelier['brand']['name']);
        $this->assertSame('editorial', $atelier['tokens']['hero']);
        $this->assertSame('portrait', $atelier['tokens']['ratio']);
        // ولا يُكتب شيء: المعاينة لقطةٌ في الذاكرة لا موقعٌ يُبنى
        $this->assertSame(0, Website::where('business_id', $this->bid())->count());
    }

    public function test_the_editor_hands_the_panel_the_structure_and_its_choices(): void
    {
        Builder::create($this->business, Blueprints::STORE, 'mono', $this->owner->id);

        $props = $this->props(route('admin.website.editor'));

        $this->assertSame('minimal', $props['layout']['header']);
        $this->assertNotEmpty($props['layoutOptions']['primary']);
        $this->assertNotEmpty($props['layoutOptions']['advanced']);

        // وثلاثةٌ في الوجه لا اثنا عشر: الباقي تحت «خيارات إضافية»
        $this->assertCount(count(Layout::PRIMARY), $props['layoutOptions']['primary']);
    }
}
