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
use App\Support\Store\PageEditor;
use App\Support\Store\StorePage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
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

        $this->assertSame(PageEditor::HERO, $keys[0]);
        $this->assertSame(PageEditor::FOOT, end($keys));
        $this->assertSame(['about', 'cats'], array_slice($keys, 1, 2), 'الترتيبُ ليس ترتيبَ صاحبه');

        // والمطفأُ يبقى معروضًا ليُرفع ثانية — لا يخرج من الشاشة إلى العدم
        foreach (StorePage::SECTIONS as $section) {
            $this->assertContains($section, $keys, "القسم {$section} اختفى من المحرّر");
        }
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
}
