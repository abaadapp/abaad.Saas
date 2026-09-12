<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Models\Website;
use App\Support\Website\Blueprints;
use App\Support\Website\Builder;
use App\Support\Website\Sections;
use App\Support\Website\Templates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * المتجر يُعدَّل حيث يُرى — لا في شاشةٍ إلى جانبه.
 *
 * ═══ ما كان ═══
 *
 * أربعُ شاشاتٍ لعملٍ واحد: معالجٌ من ثلاث خطوات لإنشائه، ومحرّرٌ فيه قائمةُ
 * أقسامٍ ومعاينةٌ صغيرة بجانبها، وشاشةُ تصميمٍ مستقلّة بمعاينتها الثانية،
 * وصفحاتٌ في ثالثة. ومن أراد تجربة لونٍ وهو يكتب نصَّ واجهته يغادر المحرّر
 * ويعود.
 *
 * ═══ وما يحرسه هذا الملفّ ═══
 *
 * ثلاثةُ عقودٍ يُبنى عليها الشكلُ الجديد، وكلُّها تنكسر صامتةً:
 *
 * ١) **المحرّر يحمل تصميمَه.** حمولتُه فيها القوالبُ ورموزُها وخياراتُها،
 *    فلو سقطت لَعُرضت لوحةُ تصميمٍ فارغة بلا خطأ في الطرفية.
 * ٢) **بابُ التصميم القديم يقود إلى اللوحة الجديدة.** روابطُ محفوظةٌ
 *    وتبويبٌ يشيران إليه، و404 عليه فقدانٌ لا نقل.
 * ٣) **المنتجُ مصدرُه واحد.** ما يُحفظ في قسم المنتجات وصفُ عرضٍ لا بضاعة:
 *    اسمٌ أو سعرٌ يُنسخ في الموقع يعني كتالوجين يفترقان في أوّل تعديل.
 */
class TheShopIsEditedWhereItIsSeenTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create([
            'name' => 'ورود مسقط', 'type' => 'محل ورود', 'status' => 'نشط',
            'phone' => '96890000000', 'city' => 'مسقط',
        ]);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->actingAs($this->owner);
    }

    private function props(string $url): array
    {
        return $this->get($url)->assertOk()->viewData('page')['props'];
    }

    private function build(string $template = 'modern'): Website
    {
        return Builder::create($this->business, Blueprints::STORE, $template, $this->owner->id);
    }

    /* ===================== ١ · المحرّر يحمل تصميمَه ===================== */

    public function test_the_editor_carries_its_own_design_panel(): void
    {
        $this->build();

        $props = $this->props(route('admin.website.editor'));

        $this->assertNotEmpty($props['templates']);
        $this->assertSame('#2563eb', $props['theme']['primary']);
        $this->assertNotEmpty($props['themeOptions']['fonts']);
        $this->assertNotEmpty($props['themeOptions']['radii']);
        $this->assertNotEmpty($props['themeOptions']['buttons']);
    }

    /**
     * والقوالبُ كلُّها تصل، وثلاثةٌ منها معلَّمة.
     *
     * ولا تُرشَّح في الخادم: صاحبُ موقعٍ مبنيٍّ على «فاخر» لا يجد قالبَه في
     * شاشته لو أُرسلت الثلاثةُ وحدها — فيظنّه حُذف.
     */
    public function test_every_template_reaches_the_panel_and_three_are_marked(): void
    {
        $this->build('luxury');

        $templates = $this->props(route('admin.website.editor'))['templates'];

        $this->assertCount(count(Templates::CATALOGUE), $templates);

        $marked = array_column(array_filter($templates, fn ($x) => $x['featured']), 'key');

        sort($marked);
        $this->assertSame(collect(Templates::FEATURED)->sort()->values()->all(), $marked);
        $this->assertContains('luxury', array_column($templates, 'key'));
    }

    public function test_the_panel_opens_where_the_link_says(): void
    {
        $this->build();

        $this->assertSame('sections', $this->props(route('admin.website.editor'))['panel']);
        $this->assertSame(
            'design',
            $this->props(route('admin.website.editor', ['panel' => 'design']))['panel'],
        );
        // وما لا يُعرف يُردّ إلى الأقسام لا إلى لوحةٍ فارغة
        $this->assertSame(
            'sections',
            $this->props(route('admin.website.editor', ['panel' => 'nowhere']))['panel'],
        );
    }

    /* ================== ٢ · بابُ التصميم القديم ================== */

    public function test_the_old_design_door_leads_to_the_new_panel(): void
    {
        $this->build();

        $this->get(route('admin.website.design'))
            ->assertRedirect(route('admin.website.editor', ['panel' => 'design']));
    }

    /** ومن لا موقع له يُردّ إلى الاختيار لا إلى محرّرٍ لا موقعَ له */
    public function test_the_design_door_without_a_site_leads_to_the_picker(): void
    {
        $this->get(route('admin.website.design'))->assertRedirect(route('admin.website.index'));
    }

    /* ================== ٣ · المنتجُ مصدرُه واحد ================== */

    /**
     * ما يُحفظ في قسم المنتجات وصفُ عرضٍ لا بضاعة.
     *
     * والدليلُ أنّ تبديل اسم المنتج في «المنتجات» يبلغ الموقعَ بلا أن يُمسّ
     * القسم: لو كان الاسمُ منسوخًا في `data` لَبقي القديم في الموقع بعد أن
     * صار في النظام غيرُه.
     */
    public function test_the_product_is_read_from_the_system_not_copied_into_the_site(): void
    {
        $category = Category::create(['business_id' => $this->business->id, 'name' => 'باقات']);
        $product = Product::create([
            'business_id' => $this->business->id, 'category_id' => $category->id,
            'name' => 'باقة ورد', 'price' => 12.5, 'active' => true,
        ]);

        $site = $this->build();
        $section = $site->homePage()->sections()->where('type', 'featured_products')->firstOrFail();

        // وصفُ عرضٍ لا بضاعة: لا اسمَ ولا سعرَ ولا صورةً في المحفوظ
        $this->assertSame(['title', 'product_ids', 'limit', 'columns'], array_keys($section->data));
        $this->assertSame('products', Sections::source('featured_products'));

        $before = $this->props(route('admin.website.editor'))['document'];
        $this->assertSame('باقة ورد', $before['data']['products'][0]['name']);

        $product->update(['name' => 'باقة الورد الأحمر', 'price' => 15.0]);

        $after = $this->props(route('admin.website.editor'))['document'];

        $this->assertSame('باقة الورد الأحمر', $after['data']['products'][0]['name']);
        $this->assertSame(15.0, $after['data']['products'][0]['price']);
        // والقسمُ لم يُمسّ: ما تغيّر تغيّر في مصدره
        $this->assertSame($section->data, $section->fresh()->data);
    }

    /* ================== ٤ · نوعُ الموقع بابٌ موجود ================== */

    /**
     * «تبدّل ذلك من إعدادات الموقع» — وعدٌ كان يُقال ولا بابَ له.
     *
     * `saveSite` مسارٌ حيٌّ منذ بُني هذا القسم ولا يناديه شيءٌ في الواجهة،
     * وشاشةُ «المتجر» تحيل إليه في موضعين. فصار له مقبضٌ في الشاشة نفسِها.
     */
    public function test_the_site_type_can_actually_be_changed(): void
    {
        $site = $this->build();

        $props = $this->props(route('admin.website.shop'));

        $this->assertSame(Blueprints::STORE, $props['goal']);
        $this->assertCount(3, $props['goals']);
        $this->assertSame($site->name, $props['name']);

        $this->put(route('admin.website.settings.save'), [
            'name' => $site->name,
            'goal' => Blueprints::CATALOG,
        ])->assertRedirect();

        $this->assertSame(Blueprints::CATALOG, $site->fresh()->goal);
        // وصفحاتُه وأقسامُه تبقى: تبديلُ النوع لا يهدم ما بُني
        $this->assertSame(4, $site->pages()->count());
    }

    /* ================== ٥ · القالبُ هيئةٌ لا لونٌ وحده ================== */

    /**
     * وثلاثةُ قوالبَ لا يفرّقها اللون وحده.
     *
     * قالبان لونُهما مختلفٌ وكلُّ ما عداهما واحد يُقرآن قالبًا واحدًا بلونين،
     * ومن يُعرض عليه ثلاثةٌ منها يظنّ أنّه يختار لونًا فيختار أيَّها كان.
     */
    public function test_the_three_templates_differ_in_shape_not_only_in_colour(): void
    {
        $shape = function (string $template): array {
            $business = Business::create([
                'name' => 'متجر '.$template, 'type' => 'عام', 'status' => 'نشط',
            ]);

            /* وبضاعةٌ في كلٍّ منها: القسمُ الذي لا يجد ما يعرضه لا يُبنى أصلًا */
            Product::create([
                'business_id' => $business->id, 'name' => 'صنف', 'price' => 1, 'active' => true,
            ]);

            $site = Builder::create($business, Blueprints::STORE, $template);
            $hero = $site->homePage()->sections()->where('type', 'hero')->firstOrFail();

            return [
                'header' => $site->slot('header')->data['preset'],
                'height' => $hero->data['height'],
                'align' => $hero->data['align'],
                'columns' => $site->homePage()->sections()
                    ->where('type', 'featured_products')->firstOrFail()->data['columns'],
            ];
        };

        $shapes = array_map($shape, array_combine(Templates::FEATURED, Templates::FEATURED));

        $this->assertCount(3, array_unique(array_map('json_encode', $shapes)), 'قالبان بهيئةٍ واحدة');
        $this->assertSame('full', $shapes['modern']['header']);
        $this->assertSame('simple', $shapes['minimal']['header']);
        $this->assertSame('centered', $shapes['bold']['header']);
    }

    /**
     * وهيئةُ القالب لا تخترع مقبضًا.
     *
     * `presets` تختار من مقابضَ يعرفها وصفُ القسم، فلو رُفع حقلٌ منه غدًا
     * سقط ذكرُه بلا أن يُكتب في القاعدة ما لا يقرؤه أحد.
     */
    public function test_a_template_cannot_invent_a_field(): void
    {
        $out = Templates::apply('bold', 'hero', ['height' => 'small', 'title' => 'أهلًا']);

        $this->assertSame(['height' => 'large', 'title' => 'أهلًا'], $out);

        $this->assertSame(
            ['title' => 'أهلًا'],
            Templates::apply('bold', 'hero', ['title' => 'أهلًا']),
            'كُتب مفتاحٌ لا يعرفه وصفُ القسم',
        );
    }

    /**
     * وتبديلُ القالب بعد الإنشاء لا يمسّ ما كتبه التاجر.
     *
     * من رفع ارتفاع واجهته ثمّ بدّل قالبه لا يُعاد ارتفاعُه إلى ما لم يختره:
     * الهيئةُ بعد الإنشاء ملكُ من كتبها لا ملكُ القالب.
     */
    public function test_switching_a_template_changes_colours_not_content(): void
    {
        $site = $this->build('minimal');
        $hero = $site->homePage()->sections()->where('type', 'hero')->firstOrFail();

        $this->assertSame('small', $hero->data['height']);

        $this->put(route('admin.website.design.update'), ['template' => 'bold', 'adopt' => true])
            ->assertRedirect();

        $this->assertSame('bold', $site->fresh()->template);
        $this->assertSame('#f97316', $site->fresh()->theme['primary']);
        $this->assertSame('small', $hero->fresh()->data['height'], 'بُدّل القالبُ فبُدّل محتوى التاجر');
    }
}
