<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Category;
use App\Models\Currency;
use App\Models\Product;
use App\Models\Review;
use App\Models\Setting;
use App\Models\User;
use App\Support\Ledger;
use App\Support\MarketingSettings;
use App\Support\Store\CatalogTools;
use App\Support\Store\PageEditor;
use App\Support\Store\RibbonPicks;
use App\Support\Store\StorePage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * صاحبُ الواجهة الخاصّة يُحرّر صفحتَه حيث تظهر — لا في بطاقةِ إعدادات.
 *
 * ═══ العطب الذي وُضع له ═══
 *
 * كانت صفحتُه تُضبط من ثلاثين مقبضًا في بطاقةٍ واحدة مرتّبةٍ بترتيب ما
 * أُضيف: صورةُ شريط المناسبات على بعد شاشتين من المفتاح الذي يُشغّل الشريط،
 * وسطرُ التذييل فوق قائمة الأقسام وهو ليس قسمًا، ومنتقي لونٍ **لا تقرؤه
 * واجهتُه أصلًا**. وسائرُ متاجر أبعاد لها محرّرٌ يعرض أقسامَ الصفحة بترتيبها
 * وحقولَ كلّ قسمٍ فيه.
 *
 * ═══ وأثقلُ ما يُحرَس هنا ═══
 *
 * أنّ الحقلَ في **موضعٍ واحد**. لا لأنّ موضعين قبيحان، بل لأنّ نموذج
 * Inertia يلتقط قيمَه حين تُفتح الشاشة: فلسانٌ مفتوحٌ على الإعدادات منذ
 * الصباح يحمل ترتيبَ الأقسام كما كان، وحفظُ رسمِ توصيلٍ منه يكتبه فوق ما
 * رتّبه في المحرّر قبل دقيقة — بلا خطأٍ ولا رسالة، فقط تعديلٌ يختفي.
 */
class AThemedShopEditsItsPageWhereItShowsTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2027-02-10 10:00:00');

        $this->shop = Business::create([
            'name' => 'RIBBON', 'type' => 'محل ورد', 'status' => 'نشط', 'phone' => '96895259066',
            'city' => 'مسقط', 'site_slug' => 'ribbon', 'tier' => 'gold', 'storefront_theme' => 'ribbon',
        ]);
        Currency::create(['business_id' => $this->shop->id, 'code' => 'OMR', 'name' => 'ريال عماني', 'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);
        Ledger::seedChart($this->shop->id);
        Branch::create(['business_id' => $this->shop->id, 'name' => 'الخوير']);
        Setting::create(['business_id' => $this->shop->id, 'key' => 'vat_enabled', 'value' => '0']);

        $this->owner = User::create(['business_id' => $this->shop->id, 'name' => 'سعود', 'email' => 'saud@abaad.om', 'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function sell(): Product
    {
        $cat = Category::create(['business_id' => $this->shop->id, 'name' => 'باقات']);

        return Product::create([
            'business_id' => $this->shop->id, 'name' => 'باقة ورد', 'price' => 10, 'category_id' => $cat->id,
            'cost' => 4, 'quantity' => 5, 'alert_qty' => 1, 'active' => true, 'published' => true,
        ]);
    }

    /** @return array<string, array<string, mixed>> */
    private function rows(): array
    {
        $out = [];
        foreach (PageEditor::rows($this->shop->id) as $row) {
            $out[$row['key']] = $row;
        }

        return $out;
    }

    /* ═══════════ البابُ نفسُه يقود إلى محرّره ═══════════ */

    /**
     * ومساره مسارُ سائر المتاجر — لا بابٌ ثانٍ يُحفظ ويُشارَك.
     *
     * و`siteOrFail` كانت تردّه إلى اللوحة، وذلك صوابٌ يوم لم يكن له محرّر.
     */
    public function test_the_themed_shop_opens_its_own_editor_on_the_same_path(): void
    {
        $this->actingAs($this->owner)
            ->get(route('admin.website.editor'))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->component('Admin/Website/ThemeEditor'));
    }

    /** ومن لا واجهةَ خاصّةَ له يبقى على محرّر البانِي — لا يُساق إلى هذا */
    public function test_a_plain_shop_is_not_sent_to_the_theme_editor(): void
    {
        $this->shop->forceFill(['storefront_theme' => null, 'tier' => 'basic'])->save();

        $response = $this->actingAs($this->owner)->get(route('admin.website.editor'));

        $this->assertNotSame(200, $response->status(), 'مُحرّرُ الواجهة يُفتح لمن لا يلبسها');
    }

    /* ═══════════ وكلُّ حقلٍ في الصفّ الذي يظهر فيه ═══════════ */

    /** الواجهةُ أوّلًا والتذييلُ آخرًا — وبينهما الأقسامُ بترتيب صاحبها */
    public function test_the_rows_come_in_the_order_the_visitor_sees(): void
    {
        MarketingSettings::save($this->shop->id, 'website', ['store_sections' => 'about,cats']);

        $keys = array_column(PageEditor::rows($this->shop->id), 'key');

        // ورأسُ المتجر فوق الواجهة: هو أوّلُ ما يُرى في كلّ صفحة
        $this->assertSame(PageEditor::HEAD, $keys[0]);
        $this->assertSame(PageEditor::HERO, $keys[1]);
        $this->assertSame(PageEditor::FOOT, end($keys));
        $this->assertSame(['about', 'cats'], array_slice($keys, 2, 2), 'الترتيبُ ليس ترتيبَ صاحبه');

        // والمطفأُ يبقى معروضًا ليُرفع ثانية — لا يخرج من الشاشة إلى العدم
        foreach (StorePage::DEFAULT_ORDER as $section) {
            $this->assertContains($section, $keys, "القسم {$section} اختفى من المحرّر");
        }

        // و«اختيارات RIBBON» صفٌّ لمن فُتحت له وحده — انظر `RibbonPicksTest`
        $this->assertSame(
            RibbonPicks::allowed($this->shop->id),
            in_array(RibbonPicks::SECTION, $keys, true),
            'صفُّ «اختيارات RIBBON» يتبع قائمتَه',
        );
    }

    /** وما أطفأه صاحبُه يُقال إنّه مطفأ — لا يُعرض كأنّه يعمل */
    public function test_a_switched_off_section_says_so(): void
    {
        MarketingSettings::save($this->shop->id, 'website', ['store_sections' => 'cats']);

        $rows = $this->rows();

        $this->assertTrue($rows['cats']['on']);
        $this->assertFalse($rows['reviews']['on']);
    }

    /**
     * وحقولُ كلّ صفٍّ هي حقولُ ما يظهر فيه — لا حقولُ شاشةٍ أخرى.
     *
     * وصورةُ الشريط أوضحُها: كانت في مجموعةٍ على بعد شاشتين من المفتاح
     * الذي يُشغّل الشريط نفسَه.
     */
    public function test_every_field_sits_in_the_row_it_shows_in(): void
    {
        $rows = $this->rows();

        $at = function (string $row): array {
            return array_column(PageEditor::FIELDS[$row], 'key');
        };

        $this->assertContains('store_banner_image', $at('banner'));
        $this->assertContains('store_hero_image', $at(PageEditor::HERO));
        $this->assertContains('store_tagline', $at(PageEditor::FOOT));
        $this->assertContains('store_about', $at('about'));
        $this->assertContains('store_featured', $at('best'));
        $this->assertContains('store_block_title', $at('block'));

        // ولا حقلَ في صفّين: يُحرَّر في موضعٍ واحد أو لا يُحرَّر
        $seen = [];
        foreach (PageEditor::FIELDS as $row => $fields) {
            foreach ($fields as $field) {
                $this->assertArrayNotHasKey($field['key'], $seen, $field['key'].' يُحرَّر في صفّين: '.($seen[$field['key']] ?? '').' و'.$row);
                $seen[$field['key']] = $row;
            }
        }

        $this->assertNotSame([], $rows);
    }

    /**
     * وكلُّ مفتاحٍ يُحرَّر هنا مفتاحٌ يقبله الحفظ فعلًا.
     *
     * حرفٌ يسقط من مفتاحٍ لا يُخطئ أحدًا: `MarketingSettings::save` تتخطّاه
     * بهدوء، فيُكتب الحقلُ ويُحفظ ويُعاد فتحُ الشاشة فارغًا — ويُظنّ العطبُ
     * في الشاشة.
     */
    public function test_every_field_is_a_key_the_store_form_really_saves(): void
    {
        $allowed = array_keys(\App\Support\MarketingSettings::GROUPS['website']);

        foreach (PageEditor::FIELDS as $row => $fields) {
            foreach ($fields as $field) {
                $this->assertContains($field['key'], $allowed, $field['key'].' في صفّ '.$row.' ليس مفتاحًا يُحفظ');
            }
        }
    }

    /* ═══════════ والموضعُ واحد — وهذا ما يحمي التعديل ═══════════ */

    /**
     * ما يُحرَّر في المحرّر لا ترسله بطاقةُ الإعدادات.
     *
     * وهو الحارسُ الذي يمنع العطبَ الصامت: لسانٌ مفتوحٌ على الإعدادات منذ
     * الصباح يحمل الترتيبَ القديم في حمولته، وحفظُ رسمِ توصيلٍ منه يكتبه
     * فوق ما رتّبه في المحرّر.
     */
    public function test_nothing_the_editor_writes_is_sent_by_the_settings_card(): void
    {
        $omitted = PageEditor::omitted();

        foreach (PageEditor::FIELDS as $fields) {
            foreach ($fields as $field) {
                $this->assertContains($field['key'], $omitted, $field['key'].' يُحرَّر في موضعين');
            }
        }

        $this->assertContains('store_sections', $omitted, 'ترتيبُ الأقسام يُكتب من بابين');
    }

    /**
     * ومقابضُ الصفحة البسيطة ليست في المحرّر ولا في بطاقته.
     *
     * لونُ `store.show.blade` وإظهارُ أسعارها لا تقرؤهما واجهةُ RIBBON —
     * ومقبضٌ يُقلَّب ويُحفظ ولا يتبدّل به شيء أسوأُ من غياب المقبض.
     */
    public function test_the_knobs_that_move_nothing_are_offered_to_nobody(): void
    {
        foreach (PageEditor::DEAD as $key) {
            $this->assertContains($key, PageEditor::omitted(), $key.' ما زال يُرسَل من بطاقة الإعدادات');

            foreach (PageEditor::FIELDS as $fields) {
                $this->assertNotContains($key, array_column($fields, 'key'), $key.' عُرض في المحرّر وهو لا يُحرّك شيئًا');
            }
        }
    }

    /** وبطاقةُ الإعدادات تعرف ما لم تعد تملكه — وتصمت عنه لمن لا واجهةَ له */
    public function test_the_settings_screen_is_told_what_moved(): void
    {
        $this->actingAs($this->owner)
            ->get(route('admin.settings.index', ['section' => 'website']))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->where('store.themed.omit', PageEditor::omitted()));

        $this->shop->forceFill(['storefront_theme' => null, 'tier' => 'basic'])->save();

        $this->actingAs($this->owner)
            ->get(route('admin.settings.index', ['section' => 'website']))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->where('store.themed', null));
    }

    /**
     * والشاشةُ تحذف ما أُخبرت به — لا تكتفي بإخفائه.
     *
     * وهذا حارسُ مصدرٍ لأنّ الفرقَ بين الحالين لا يُرى في الخادم: الحمولةُ
     * التي لا تحمل المفتاح والحمولةُ التي تحمله قديمًا تُحفظان بلا خطأ،
     * وإحداهما تمحو عملَ صاحبها.
     */
    public function test_the_settings_screen_drops_them_from_the_payload(): void
    {
        $screen = file_get_contents(resource_path('js/Pages/Admin/Settings/Index.tsx'));

        $this->assertStringContainsString('store.themed?.omit', $screen, 'البطاقةُ لا تقرأ ما انتقل عنها');
        $this->assertStringContainsString('for (const key of omit) delete out[key];', $screen, 'البطاقةُ تُخفي ولا تحذف');
    }

    /** ولا تُرسم في الإعدادات حقولُ صفحةٍ انتقلت — فمقبضان لشيءٍ واحد يفترقان */
    public function test_the_moved_fields_are_no_longer_drawn_in_the_settings(): void
    {
        $screen = file_get_contents(resource_path('js/Pages/Admin/Settings/Index.tsx'));

        foreach (['store_hero_image', 'store_banner_image', 'store_tagline', 'store_featured', 'store_block_title'] as $key) {
            $this->assertStringNotContainsString("setData('{$key}'", $screen, $key.' ما زال يُكتب في الإعدادات');
        }
    }

    /**
     * وحفظٌ لا يحمل الترتيب لا يمسّه.
     *
     * وهو ما يجعل حذفَ المفاتيح من الحمولة كافيًا: الخادمُ لا يكتب إلّا ما
     * أُرسل — فلو كُتب الغائبُ فراغًا لَعادت الأقسامُ السبعةُ كلُّها.
     */
    public function test_a_save_that_does_not_carry_the_page_keeps_it(): void
    {
        MarketingSettings::save($this->shop->id, 'website', [
            'store_sections' => 'about,cats',
            'store_tagline' => 'وردٌ وأكثر',
        ]);

        $this->actingAs($this->owner)
            ->from(route('admin.settings.index', ['section' => 'website']))
            ->post(route('admin.marketing.store.save'), ['store_delivery_fee' => '1.500'])
            ->assertRedirect();

        $site = MarketingSettings::group($this->shop->id, 'website');

        $this->assertSame('about,cats', $site['store_sections']);
        $this->assertSame('وردٌ وأكثر', $site['store_tagline']);
    }

    /* ═══════════ و«مُشغَّلٌ ولا يظهر» يُقال في صفّه ═══════════ */

    /** قسمٌ شغّله صاحبُه ولا بضاعةَ له يُقال له لماذا لا يراه */
    public function test_a_section_that_shows_nothing_says_why(): void
    {
        MarketingSettings::save($this->shop->id, 'website', ['store_sections' => 'cats,best,reviews']);

        $rows = $this->rows();

        $this->assertSame(PageEditor::SILENT['cats'], $rows['cats']['silent']);
        $this->assertSame(PageEditor::SILENT['best'], $rows['best']['silent']);
        $this->assertSame(PageEditor::SILENT['reviews'], $rows['reviews']['silent']);
    }

    /**
     * وفئةٌ بلا بضاعةٍ معروضة فئةٌ صامتة.
     *
     * والواجهةُ لا ترسم فئةً فارغة (انظر `RibbonController::categories`).
     * فوجودُ صفٍّ في `categories` لا يكفي جوابًا — والسؤالُ يُطرح على ما
     * يقرؤه المتجرُ لا على ما في القاعدة.
     */
    public function test_a_category_with_nothing_shown_in_it_is_still_silent(): void
    {
        MarketingSettings::save($this->shop->id, 'website', ['store_sections' => 'cats']);

        $cat = Category::create(['business_id' => $this->shop->id, 'name' => 'مناسبات']);
        Product::create([
            'business_id' => $this->shop->id, 'name' => 'باقة عيد', 'price' => 12, 'category_id' => $cat->id,
            'cost' => 5, 'quantity' => 3, 'alert_qty' => 1, 'active' => true, 'published' => false,
        ]);

        $this->assertSame(PageEditor::SILENT['cats'], $this->rows()['cats']['silent']);
    }

    /** وحين يصير له ما يعرضه يسكت التحذير — ولا يبقى يُقرأ عطبًا */
    public function test_the_warning_goes_when_the_section_has_something_to_show(): void
    {
        MarketingSettings::save($this->shop->id, 'website', ['store_sections' => 'cats,best']);
        $this->sell();

        $rows = $this->rows();

        $this->assertNull($rows['cats']['silent']);
        $this->assertNull($rows['best']['silent']);
    }

    /** ورأيٌ منشورٌ يُسكت تحذيرَ قسمه */
    public function test_a_published_review_quiets_its_row(): void
    {
        MarketingSettings::save($this->shop->id, 'website', ['store_sections' => 'reviews']);

        Review::create([
            'business_id' => $this->shop->id, 'author_name' => 'ريم', 'rating' => 5,
            'comment' => 'باقةٌ جميلة', 'status' => 'منشور',
        ]);

        $this->assertNull($this->rows()['reviews']['silent']);
    }

    /**
     * ولا يُقال «لا يظهر» لقسمٍ أطفأه صاحبُه.
     *
     * هو يعرف لمَ أطفأه — وتحذيرٌ على صفٍّ مطفأ يُقرأ عطبًا ويُعلَّم على
     * أنّه ضجيجٌ يُتخطّى، فيُتخطّى معه ما يُقال على صفٍّ مشتغل.
     */
    public function test_a_switched_off_section_is_not_warned_about(): void
    {
        MarketingSettings::save($this->shop->id, 'website', ['store_sections' => 'cats']);

        $this->assertNull($this->rows()['reviews']['silent']);
    }

    /** والقسمُ الحرُّ لا يظهر بنصفه — ويُقال ذلك قبل أن يُحفظ */
    public function test_the_free_section_says_it_needs_both_its_halves(): void
    {
        MarketingSettings::save($this->shop->id, 'website', [
            'store_sections' => 'block', 'store_block_on' => '1', 'store_block_title' => 'اشتراك الورد',
        ]);

        $this->assertSame(PageEditor::SILENT['block'], $this->rows()['block']['silent']);

        MarketingSettings::save($this->shop->id, 'website', ['store_block_text' => 'باقةٌ كلّ أسبوع']);

        $this->assertNull($this->rows()['block']['silent']);
    }

    /* ═══════════ وما لا يُكتب هنا يُقال أين يُكتب ═══════════ */

    /** وكلُّ إحالةٍ إلى بابٍ قائم — نصيحةٌ تُحيل إلى مسارٍ محذوف أسوأ من لا شيء */
    public function test_every_referred_door_is_a_real_route(): void
    {
        foreach (PageEditor::ROWS as $key => $spec) {
            if ($spec['source'] === null) {
                continue;
            }

            $this->assertNotNull(
                Route::getRoutes()->getByName($spec['source'][1]),
                'صفُّ '.$key.' يُحيل إلى مسارٍ لا وجود له: '.$spec['source'][1],
            );
        }
    }

    /**
     * ولا يُترك نصٌّ عربيٌّ بلا إنجليزيّته.
     *
     * و`TranslationCoverageTest` لا تبلغ هذه النصوص: هي في ثابتٍ في PHP
     * تقرؤه الشاشةُ وتترجمه بـ`t()` — فلا تظهر في المصدر داخل `t('…')` ولا
     * داخل `__('…')`. فتُحرَس هنا بقراءة الثابت نفسِه.
     */
    public function test_every_word_the_editor_says_has_its_english(): void
    {
        $dictionary = json_decode(file_get_contents(base_path('lang/en.json')), true);

        $strings = PageEditor::SILENT;

        foreach (PageEditor::ROWS as $spec) {
            $strings[] = $spec['label'];
            $strings[] = $spec['hint'];

            if ($spec['source'] !== null) {
                $strings[] = $spec['source'][0];
            }
        }

        foreach (PageEditor::FIELDS as $fields) {
            foreach ($fields as $field) {
                $strings[] = $field['label'];
                $strings[] = $field['hint'] ?? 'x';
            }
        }

        $missing = array_values(array_filter(
            array_unique($strings),
            fn ($s) => $s !== 'x' && ! isset($dictionary[$s]),
        ));

        $this->assertSame([], $missing, 'نصوصٌ في المحرّر بلا ترجمة');
    }

    /* ═══════════ والمنتقي يعرض المعروضَ وحده ═══════════ */

    /**
     * صنفٌ مخفيٌّ لا يُعرض في «مختاراتنا».
     *
     * الواجهةُ لا تُقحم في صفحتها ما أخفاه صاحبُه — فعرضُه هنا اختيارٌ
     * يُحفظ ولا أثرَ له، وصاحبُه ينتظره على صفحته فلا يجده.
     */
    public function test_the_picker_shows_only_what_the_page_would_show(): void
    {
        $shown = $this->sell();
        $hidden = Product::create([
            'business_id' => $this->shop->id, 'name' => 'ورق تغليف', 'price' => 1,
            'cost' => 0.2, 'quantity' => 100, 'alert_qty' => 1, 'active' => true, 'published' => false,
        ]);

        $this->actingAs($this->owner)
            ->get(route('admin.website.editor'))
            ->assertInertia(function ($p) use ($shown, $hidden) {
                $ids = array_column($p->toArray()['props']['products'], 'id');

                $this->assertContains($shown->id, $ids);
                $this->assertNotContains($hidden->id, $ids, 'مخفيٌّ يُعرض للاختيار — فيُختار ولا يظهر');
            });
    }

    /* ═══════════ ولوحتا الفئات و«وصل حديثًا» — لمتجر سعود وحده ═══════════ */

    /** يفتح اللوحتين لهذا المتجر بالقائمة — كما يُفتح متجرُ سعود على الإنتاج */
    private function allowCatalogTools(?int $businessId = null): void
    {
        config(['storefront.ribbon_catalog_editor_businesses' => [$businessId ?? $this->shop->id]]);
    }

    /** @return array<string, mixed>|null */
    private function catalogTools(): ?array
    {
        return $this->actingAs($this->owner)
            ->get(route('admin.website.editor'))
            ->assertOk()
            ->viewData('page')['props']['catalogTools'] ?? null;
    }

    private function product(string $name, array $over = []): Product
    {
        return Product::create($over + [
            'business_id' => $this->shop->id, 'name' => $name, 'price' => 10,
            'cost' => 4, 'quantity' => 5, 'alert_qty' => 1, 'active' => true, 'published' => true,
        ]);
    }

    /**
     * متجرُ RIBBON آخرُ ليس في القائمة: لا لوحةَ ولا حمولة — وصفّاه كما كانا.
     *
     * ولبسُ الواجهة لا يكفي، وقائمةُ Paymob لا تُستعار: متجرٌ فيها وحده لا
     * يُفتح له هذا.
     */
    public function test_a_ribbon_shop_outside_the_list_keeps_the_editor_it_had(): void
    {
        $other = Business::create(['name' => 'متجر آخر', 'type' => 'عام', 'status' => 'نشط', 'site_slug' => 'other-ribbon', 'storefront_theme' => 'ribbon']);
        $this->allowCatalogTools($other->id);
        $this->sell();

        $response = $this->actingAs($this->owner)->get(route('admin.website.editor'))->assertOk();
        $props = $response->viewData('page')['props'];

        $this->assertArrayHasKey('catalogTools', $props);
        $this->assertNull($props['catalogTools'], 'لوحتا سعود وصلتا متجرًا ليس في قائمتهما');
        $this->assertFalse(CatalogTools::allowed($this->shop->id));

        // وسطرُ «يُكتب في…» باقٍ في الصفّين — فالمصدرُ لم يُحذف من `PageEditor`
        $rows = array_column($props['rows'], null, 'key');
        $this->assertSame('admin.products.index', $rows['cats']['source']['route']);
        $this->assertSame('admin.products.index', $rows['new']['source']['route']);
    }

    /** وقائمةٌ فارغة لا تفتحها لأحد */
    public function test_an_empty_list_opens_it_for_nobody(): void
    {
        config(['storefront.ribbon_catalog_editor_businesses' => []]);

        $this->assertNull($this->catalogTools());
    }

    /** والقائمةُ على الإنتاج متجرُ سعود وحده — ولا تُقرأ من `env` */
    public function test_the_shipped_list_is_saud_alone(): void
    {
        $config = require config_path('storefront.php');

        $this->assertSame([5], $config['ribbon_catalog_editor_businesses']);
    }

    /** ومن في القائمة تصله اللوحتان — ولا سعرَ ولا مخزونَ ولا كلفةَ فيهما */
    public function test_the_listed_shop_receives_both_panels_and_nothing_financial(): void
    {
        $this->allowCatalogTools();
        $this->sell();

        $tools = $this->catalogTools();

        $this->assertIsArray($tools);
        $this->assertSame(['categories', 'new_arrivals'], array_keys($tools));
        $this->assertSame(['id', 'name', 'name_en', 'shown_count'], array_keys($tools['categories'][0]));
        $this->assertSame(['id', 'name', 'image', 'category'], array_keys($tools['new_arrivals'][0]));
    }

    /** فئاتُ متجرٍ آخر وأصنافُه لا تظهر أبدًا — ولا تُعدّ في فئات هذا */
    public function test_another_shops_categories_and_products_never_appear(): void
    {
        $this->allowCatalogTools();
        $mine = $this->sell();

        $stranger = Business::create(['name' => 'غريب', 'type' => 'عام', 'status' => 'نشط', 'site_slug' => 'stranger', 'storefront_theme' => 'ribbon']);
        $theirs = Category::create(['business_id' => $stranger->id, 'name' => 'فئة الغريب']);
        $foreign = Product::create([
            'business_id' => $stranger->id, 'name' => 'صنف الغريب', 'price' => 1, 'category_id' => $theirs->id,
            'cost' => 0, 'quantity' => 1, 'alert_qty' => 0, 'active' => true, 'published' => true,
        ]);
        // وصنفٌ غريبٌ مُلصَقٌ بفئةٍ من هذا المتجر لا يُحسب فيها
        Product::create([
            'business_id' => $stranger->id, 'name' => 'متسلّل', 'price' => 1, 'category_id' => $mine->category_id,
            'cost' => 0, 'quantity' => 1, 'alert_qty' => 0, 'active' => true, 'published' => true,
        ]);

        $tools = $this->catalogTools();

        $this->assertSame([$mine->category_id], array_column($tools['categories'], 'id'));
        $this->assertSame(1, $tools['categories'][0]['shown_count'], 'صنفُ متجرٍ آخر عُدّ في فئةٍ من هذا');
        $this->assertSame([$mine->id], array_column($tools['new_arrivals'], 'id'));
        $this->assertNotContains($foreign->id, array_column($tools['new_arrivals'], 'id'));
    }

    /**
     * العدُّ للمفعَّل المنشور وحده — والفئةُ الفارغةُ تبقى وتُعدّ صفرًا.
     *
     * والصنفُ بلا فئة لا يصنع فئةً وهميّة: القائمةُ من جدول الفئات لا من الأصناف.
     */
    public function test_each_category_counts_only_what_is_active_and_published(): void
    {
        $this->allowCatalogTools();
        $roses = Category::create(['business_id' => $this->shop->id, 'name' => 'ورد', 'name_en' => 'Roses']);
        $empty = Category::create(['business_id' => $this->shop->id, 'name' => 'هدايا']);

        $this->product('وردة ١', ['category_id' => $roses->id]);
        $this->product('وردة ٢', ['category_id' => $roses->id]);
        $this->product('وردة مطفأة', ['category_id' => $roses->id, 'active' => false]);
        $this->product('وردة مخفيّة', ['category_id' => $roses->id, 'published' => false]);
        $this->product('هديّة مخفيّة', ['category_id' => $empty->id, 'published' => false]);
        $this->product('بلا فئة');

        $cats = array_column($this->catalogTools()['categories'], null, 'id');

        $this->assertSame([$empty->id, $roses->id], array_keys($cats), 'فئةٌ وهميّةٌ ظهرت، أو غابت فئةٌ قائمة');
        $this->assertSame(2, $cats[$roses->id]['shown_count'], 'المطفأُ أو المخفيُّ عُدّ');
        $this->assertSame('Roses', $cats[$roses->id]['name_en']);
        $this->assertSame(0, $cats[$empty->id]['shown_count'], 'فئةٌ لا يُعرض فيها شيءٌ عُدّت');
        $this->assertNull($cats[$empty->id]['name_en']);
    }

    /**
     * «وصل حديثًا» = أحدثُ أربعةٍ مفعَّلةٍ منشورة بالمعرّف — وتعديلُ قديمٍ لا يرفعه.
     *
     * والتاريخان يُحرَّكان عمدًا: صنفٌ قديمٌ عُدّل اليوم، وقديمٌ آخرُ بتاريخ
     * إنشاءٍ متأخّر (استيرادٌ مثلًا). فلو رتّبت اللوحةُ بغير المعرّف لظهر
     * أحدُهما — والواجهةُ لا تُظهره.
     */
    public function test_new_arrivals_are_the_newest_four_shown_by_id(): void
    {
        $this->allowCatalogTools();
        $cat = Category::create(['business_id' => $this->shop->id, 'name' => 'باقات']);

        $old = $this->product('قديم عُدّل', ['category_id' => $cat->id]);
        $backdated = $this->product('قديم بتاريخٍ متأخّر');
        $a = $this->product('أ', ['category_id' => $cat->id]);
        $b = $this->product('ب');
        $this->product('مطفأ', ['active' => false]);
        $c = $this->product('ج');
        $d = $this->product('د');
        $this->product('مخفيّ', ['published' => false]);

        Carbon::setTestNow('2027-03-01 10:00:00');
        $old->update(['name' => 'قديم عُدّل اليوم']);
        DB::table('products')->where('id', $backdated->id)->update(['created_at' => '2030-01-01 00:00:00']);

        $arrivals = $this->catalogTools()['new_arrivals'];

        $this->assertSame([$d->id, $c->id, $b->id, $a->id], array_column($arrivals, 'id'), 'ليس أحدثَ أربعةٍ معروضةٍ بالمعرّف');
        $this->assertSame('باقات', $arrivals[3]['category']);
        $this->assertNull($arrivals[0]['category']);
        $this->assertNull($arrivals[0]['image'], 'صورةٌ بديلةٌ من الإنترنت عُرضت كأنّها بضاعتُه');
    }

    /** وأقلُّ من أربعة يُعرض كما هو — ولا شيءَ يُعرض قائمةً فارغة */
    public function test_new_arrivals_show_what_there_is_and_nothing_when_nothing(): void
    {
        $this->allowCatalogTools();

        $this->assertSame([], $this->catalogTools()['new_arrivals']);

        $only = $this->product('وحيد');
        $this->assertSame([$only->id], array_column($this->catalogTools()['new_arrivals'], 'id'));
    }

    /**
     * ═══ والقاعدةُ قاعدةُ الواجهة — بالمقارنة لا بالنسخ ═══
     *
     * `RibbonController` لا يُمسّ في هذا العمل، وقاعدتُه خاصّةٌ فيه. فتُطلب
     * الصفحةُ الرئيسيّة نفسُها ويُقارن ما تعرضه بما تقوله اللوحتان — فإن
     * تبدّلت إحداهما دون الأخرى سقط هذا.
     */
    public function test_the_panels_say_exactly_what_the_storefront_shows(): void
    {
        $this->allowCatalogTools();
        MarketingSettings::save($this->shop->id, 'website', ['store_on' => '1']);

        $roses = Category::create(['business_id' => $this->shop->id, 'name' => 'ورد']);
        $gifts = Category::create(['business_id' => $this->shop->id, 'name' => 'هدايا']);
        Category::create(['business_id' => $this->shop->id, 'name' => 'فارغة']);

        foreach (range(1, 6) as $n) {
            $this->product('صنف '.$n, ['category_id' => $n % 2 ? $roses->id : $gifts->id]);
        }
        $this->product('مطفأ', ['category_id' => $gifts->id, 'active' => false]);
        $this->product('مخفيّ', ['category_id' => $roses->id, 'published' => false]);

        $tools = $this->catalogTools();
        $home = $this->get('/s/ribbon')->assertOk();

        $this->assertSame(
            array_column($home->viewData('new'), 'id'),
            array_column($tools['new_arrivals'], 'id'),
            '«وصل حديثًا» في المحرّر غيرُ ما تعرضه الواجهة',
        );

        $shown = array_values(array_filter($tools['categories'], fn ($c) => $c['shown_count'] > 0));
        $this->assertSame(
            collect($home->viewData('categories'))->map(fn ($c) => [$c['id'], $c['count']])->all(),
            array_map(fn ($c) => [$c['id'], $c['shown_count']], $shown),
            'الفئاتُ «الظاهرة» في المحرّر غيرُ ما تعرضه الواجهة',
        );
    }

    /**
     * والفئةُ تُضاف من المحرّر بالباب القائم — وتعود في اللوحة فارغةً «لن تظهر».
     *
     * ولا بابَ ثانٍ لإنشاء الفئات: واحدٌ يحمل قواعدَها وحصرَها بالمتجر.
     */
    public function test_a_category_added_from_the_editor_uses_the_one_existing_door(): void
    {
        $this->allowCatalogTools();

        $doors = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($r) => str_ends_with((string) $r->getActionName(), 'CatalogQuickAddController@storeCategory'))
            ->map(fn ($r) => $r->getName())->values()->all();
        $this->assertSame(['admin.products.categories.store'], $doors, 'بابٌ ثانٍ لإنشاء الفئات');

        $panel = file_get_contents(resource_path('js/Pages/Admin/Website/theme/CatalogTools.tsx'));
        $this->assertStringContainsString("route('admin.products.categories.store')", $panel);

        $this->actingAs($this->owner)
            ->postJson(route('admin.products.categories.store'), ['name' => 'شوكولاتة', 'name_en' => 'Chocolate'])
            ->assertOk();

        $this->actingAs($this->owner)
            ->postJson(route('admin.products.categories.store'), ['name' => 'شوكولاتة'])
            ->assertStatus(422)->assertJsonValidationErrors('name');

        $made = collect($this->catalogTools()['categories'])->firstWhere('name', 'شوكولاتة');
        $this->assertSame(['Chocolate', 0], [$made['name_en'], $made['shown_count']]);
        $this->assertSame(0, Product::where('business_id', $this->shop->id)->count(), 'إضافةُ فئةٍ أنشأت صنفًا');
    }

    /**
     * ولا معرّفَ متجرٍ مكتوبًا في الشاشة ولا في المتحكّم — القائمةُ وحدها تقرّر.
     */
    public function test_no_business_id_is_written_outside_the_list(): void
    {
        $files = [
            resource_path('js/Pages/Admin/Website/ThemeEditor.tsx'),
            resource_path('js/Pages/Admin/Website/theme/CatalogTools.tsx'),
            app_path('Http/Controllers/Admin/Website/EditorController.php'),
            app_path('Support/Store/CatalogTools.php'),
        ];

        foreach ($files as $file) {
            $this->assertDoesNotMatchRegularExpression(
                '/(business_?[iI]d|bid|id)\s*(===?|!==?)\s*5\b|\[\s*5\s*\]/',
                file_get_contents($file),
                basename($file).' يسأل عن متجرٍ بمعرّفه',
            );
        }
    }
}
