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
use App\Support\Store\StorePage;
use App\Support\Store\ThemePublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * المحرّرُ يُعاد فتحُه على الترتيب الذي حُفظ — لا على المنشور.
 *
 * ═══ ما كان ═══
 *
 * المحرّرُ يقرأ حقولَه كلَّها من المسوّدة (`PageEditor::values`)، والترتيبَ
 * وحده من المنشور (`StorePage::order`). فمن قدّم «الأكثر مبيعًا» وحفظ ولم
 * ينشر ثمّ أعاد فتح المحرّر وجد الترتيبَ القديم — وأوّلُ سهمٍ يضغطه بعدها
 * يُرسل الترتيبَ القديم كلَّه، فيكتبه في المسوّدة فوق الجديد.
 *
 * ═══ ما يُحرس — خطواتُ المالك الثماني ═══
 *
 *   1–3. متجرٌ على المسوّدات، يُقدَّم قسمٌ على آخر ويُحفظ بلا نشر.
 *   4–5. يُعاد فتحُ المحرّر فيجد الترتيبَ الجديد — في القائمة وفي الصفوف.
 *   6.   والموقعُ العامّ لم يتغيّر قبل النشر.
 *   7–8. يُنشر فيعرض الموقعُ الترتيبَ الجديد.
 *
 * ومتجرٌ يحفظ مباشرةً (بلا صفٍّ في `store_sites`) يبقى كما كان: الحفظُ نشر.
 */
class TheEditorReopensOnTheOrderItSavedTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private User $owner;

    private const SWAPPED = ['best', 'cats', 'new', 'banner', 'block', 'about', 'reviews'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->setLocale('ar');

        $this->shop = Business::create([
            'name' => 'ريبون', 'type' => 'محل ورد', 'status' => 'نشط', 'phone' => '96895259066',
            'city' => 'مسقط', 'site_slug' => 'ribbon', 'tier' => 'gold', 'storefront_theme' => 'ribbon',
        ]);
        Currency::create(['business_id' => $this->shop->id, 'code' => 'OMR', 'name' => 'ريال', 'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);
        Ledger::seedChart($this->shop->id);
        Branch::create(['business_id' => $this->shop->id, 'name' => 'الخوير']);
        Setting::create(['business_id' => $this->shop->id, 'key' => 'vat_enabled', 'value' => '0']);
        MarketingSettings::save($this->shop->id, 'website', ['store_on' => '1']);

        $this->owner = User::create([
            'business_id' => $this->shop->id, 'name' => 'المالك', 'email' => 'owner@ribbon.test',
            'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $cat = Category::create(['business_id' => $this->shop->id, 'name' => 'باقات']);
        Product::create([
            'business_id' => $this->shop->id, 'name' => 'باقة ورد', 'price' => 12, 'category_id' => $cat->id,
            'cost' => 4, 'quantity' => 10, 'active' => true, 'published' => true,
        ]);
    }

    private function save(array $payload)
    {
        return $this->actingAs($this->owner)->from(route('admin.website.editor'))
            ->post(route('admin.marketing.store.save'), $payload);
    }

    /** @return array<string, mixed> خصائصُ المحرّر كما تصل الشاشة */
    private function editor(): array
    {
        return $this->actingAs($this->owner)->get(route('admin.website.editor'))->assertOk()->viewData('page')['props'];
    }

    /** @return list<string> أقسامُ الصفحة المشغَّلة بترتيب صفوف المحرّر */
    private function rowOrder(array $props): array
    {
        return array_values(array_map(
            fn ($r) => $r['key'],
            array_filter($props['rows'], fn ($r) => ! $r['fixed'] && $r['on']),
        ));
    }

    /** @return list<string> أقسامُ الرئيسية كما يراها الزائر */
    private function visitorSees(): array
    {
        auth()->logout();

        return $this->get('/s/ribbon')->assertOk()->viewData('sections');
    }

    public function test_a_saved_draft_order_is_what_the_editor_reopens_on_and_the_visitor_waits_for_publish(): void
    {
        // ١. متجرٌ على المسوّدات
        ThemePublisher::enable($this->shop, (int) $this->owner->id);

        // ٢–٣. يُقدَّم «الأكثر مبيعًا» على «تسوّق حسب الفئة» ويُحفظ بلا نشر
        $this->save(['store_sections' => implode(',', self::SWAPPED)])->assertRedirect()->assertSessionHasNoErrors();

        // ٤–٥. يُعاد فتحُ المحرّر: الترتيبُ الجديد في القائمة وفي الصفوف
        $props = $this->editor();
        $this->assertSame(self::SWAPPED, $props['order'], 'فُتح المحرّرُ على الترتيب المنشور لا المسوّدة');
        $this->assertSame(self::SWAPPED, $this->rowOrder($props), 'صفوفُ المحرّر بالترتيب المنشور');

        // ٦. والموقعُ العامّ لم يتغيّر قبل النشر
        $this->assertSame(StorePage::DEFAULT_ORDER, $this->visitorSees(), 'وصل ترتيبُ المسوّدة إلى الزائر قبل النشر');

        // ٧–٨. يُنشر فيعرض الموقعُ الترتيبَ الجديد
        $this->actingAs($this->owner)->from(route('admin.website.editor'))
            ->post(route('admin.website.store.publish'))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(self::SWAPPED, $this->visitorSees());
        $this->assertSame(self::SWAPPED, $this->editor()['order']);
    }

    public function test_a_second_arrow_after_reopening_starts_from_the_draft(): void
    {
        ThemePublisher::enable($this->shop, (int) $this->owner->id);
        $this->save(['store_sections' => implode(',', self::SWAPPED)]);

        // المحرّرُ يُرسل القائمةَ كلَّها بعد كلّ سهم — من الترتيب الذي فُتح عليه
        $opened = $this->editor()['order'];
        [$opened[2], $opened[3]] = [$opened[3], $opened[2]];
        $this->save(['store_sections' => implode(',', $opened)]);

        $this->assertSame(
            ['best', 'cats', 'banner', 'new', 'block', 'about', 'reviews'],
            $this->editor()['order'],
            'ضاع التقديمُ الأوّلُ تحت السهم الثاني',
        );
    }

    public function test_the_rows_say_what_the_draft_shows(): void
    {
        ThemePublisher::enable($this->shop, (int) $this->owner->id);

        // القسمُ الحرُّ مكتوبٌ في المسوّدة وحدها — والمنشورُ بلا نصّ
        $this->save([
            'store_sections' => 'block,cats,best,new,banner,about,reviews',
            'store_block_on' => true, 'store_block_title' => 'اشتراك شهري', 'store_block_text' => 'باقةٌ كلَّ أسبوع',
        ])->assertSessionHasNoErrors();

        $block = collect($this->editor()['rows'])->firstWhere('key', 'block');
        $this->assertTrue($block['on']);
        $this->assertNull($block['silent'], 'قال المحرّرُ «لا يظهر» عن قسمٍ مكتوبٍ في مسوّدته');
    }

    /**
     * وكلُّ ترتيبٍ آخر يرسمه المحرّر — يُقرأ من المسوّدة كذلك.
     *
     * المختاراتُ واختياراتُ RIBBON و«أضف مع طلبك» والمجموعاتُ واختصاراتُ
     * المتجر وصفُّه والقائمةُ والصفحاتُ والشريطُ المصوّر والتذييل: كلٌّ منها
     * قائمةٌ يرتّبها بسهمين، ويُعاد فتحُ المحرّر عليها كما حُفظت لا كما نُشرت.
     */
    public function test_every_other_arrangement_the_editor_draws_reopens_from_the_draft(): void
    {
        $keys = [
            'store_featured', 'store_picks', 'store_ribbon_upsells', 'store_collections',
            'store_shop_nav_categories', 'store_shop_row', 'store_menu', 'store_custom_pages',
            'store_visual_bar', 'store_footer',
        ];

        ThemePublisher::enable($this->shop, (int) $this->owner->id);
        MarketingSettings::save($this->shop->id, 'website', array_fill_keys($keys, 'المنشور'));
        ThemePublisher::saveDraft((int) $this->shop->id, array_fill_keys($keys, 'المسوّدة'), (int) $this->owner->id);

        $values = $this->editor()['values'];

        foreach ($keys as $key) {
            $this->assertArrayHasKey($key, $values, $key.' لا يصل المحرّر');
            $this->assertSame('المسوّدة', $values[$key], $key.' يُفتح على المنشور');
        }
    }

    public function test_a_shop_that_saves_straight_to_live_is_unchanged(): void
    {
        // بلا `enable`: الحفظُ نشرٌ كما كان
        $this->save(['store_sections' => implode(',', self::SWAPPED)])->assertSessionHasNoErrors();

        $this->assertSame(self::SWAPPED, $this->editor()['order']);
        $this->assertSame(self::SWAPPED, $this->visitorSees());
    }
}
