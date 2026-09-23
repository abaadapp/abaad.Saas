<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Rules\SafeLink;
use App\Support\Ledger;
use App\Support\MarketingSettings;
use App\Support\Store\StorePage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * صاحبُ المحلّ يبني صفحتَه بنفسه — ولا يرجع إلى أحد.
 *
 * أربعةٌ: صورةُ الواجهة يختارها، وقسمٌ يكتبه، وأصنافٌ يُبرزها، وأقسامٌ
 * يُطفئها ويرتّبها.
 *
 * ═══ وأثقلُ ما يُحرَس ═══
 *
 * أنّ متجرًا لم يمسّ الشاشةَ لا يتبدّل عليه شيء. تسعةُ مفاتيحَ فارغةٍ تنزل
 * على كلّ متجرٍ في أبعاد — ولو قُرئ الفراغُ «لا أقسام» لَصارت مئةُ صفحةٍ
 * واجهةً عاريةً بلا صنفٍ ولا فئة، بلا أن يطلب ذلك أحد.
 */
class TheShopOwnerBuildsHisOwnPageTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2027-02-01 10:00:00');
        $this->app->setLocale('ar');

        $this->shop = Business::create([
            'name' => 'RIBBON', 'type' => 'محل ورد', 'status' => 'نشط', 'phone' => '96895259066',
            'city' => 'مسقط', 'site_slug' => 'ribbon', 'tier' => 'gold', 'storefront_theme' => 'ribbon',
        ]);
        Currency::create(['business_id' => $this->shop->id, 'code' => 'OMR', 'name' => 'ريال عماني', 'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);
        Ledger::seedChart($this->shop->id);
        Branch::create(['business_id' => $this->shop->id, 'name' => 'الخوير']);
        Setting::create(['business_id' => $this->shop->id, 'key' => 'vat_enabled', 'value' => '0']);

        $this->owner = User::create(['business_id' => $this->shop->id, 'name' => 'سعود', 'email' => 'saud@abaad.om', 'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط']);

        MarketingSettings::save($this->shop->id, 'website', ['store_on' => '1', 'store_pay_cod' => '1']);

        // وللأصناف تصنيفٌ — وإلّا لم يُرسَم قسمُ «تسوّق حسب الفئة» أصلًا
        $cat = \App\Models\Category::create(['business_id' => $this->shop->id, 'name' => 'باقات']);

        foreach (['باقة ورد', 'عطر', 'شوكولاتة'] as $i => $name) {
            Product::create([
                'business_id' => $this->shop->id, 'name' => $name, 'price' => 10 + $i, 'category_id' => $cat->id,
                'cost' => 4, 'quantity' => 10, 'alert_qty' => 1, 'active' => true, 'published' => true,
            ]);
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function set(array $over): void
    {
        MarketingSettings::save($this->shop->id, 'website', $over);
    }

    private function page(): string
    {
        return $this->get('/s/ribbon')->assertOk()->getContent();
    }

    /* ═══════════ والفراغُ يعني «ما كان» ═══════════ */

    /** متجرٌ لم يمسّ الشاشةَ تبقى صفحتُه كما كانت — أقسامُها كلُّها بترتيبها */
    public function test_a_shop_that_touched_nothing_keeps_the_page_it_had(): void
    {
        $this->assertSame(StorePage::SECTIONS, StorePage::order($this->shop->id));
        $this->assertNull(StorePage::heroImage($this->shop->id));
        $this->assertNull(StorePage::block($this->shop->id));
        $this->assertSame([], StorePage::featured($this->shop->id));

        $page = $this->page();
        foreach (['rb-sec-cats', 'rb-sec-best'] as $mark) {
            $this->assertStringContainsString($mark, $page);
        }
    }

    /** وقائمةٌ لا تصحّ منها واحدةٌ تُردّ إلى الأصل — لا صفحةٌ عارية */
    public function test_a_broken_order_never_empties_the_page(): void
    {
        $this->set(['store_sections' => 'عبث,لا شيء']);

        $this->assertSame(StorePage::SECTIONS, StorePage::order($this->shop->id));
        $this->assertStringContainsString('rb-sec-cats', $this->page());
    }

    /* ═══════════ ١) صورةُ الواجهة ═══════════ */

    /** الصورةُ التي اختارها تتصدّر — ولا تُترك للصدفة */
    public function test_the_hero_is_the_picture_he_picked(): void
    {
        $this->set(['store_hero_image' => '/storage/website/1/hero.jpg']);

        $this->assertStringContainsString('/storage/website/1/hero.jpg', $this->page());
    }

    /** ومن لم يختر تبقى عليه القاعدةُ القديمة — لا واجهةٌ فارغة */
    public function test_a_shop_that_picked_none_keeps_the_old_rule(): void
    {
        $this->set(['store_hero_image' => '']);

        // الصفحةُ تُفتح ولا تسقط، والقسمُ قائم
        $this->assertStringContainsString('rb-landing', $this->page());
    }

    /** ويرفعها بنفسه فيعود رابطُها إليه */
    public function test_he_uploads_the_picture_himself(): void
    {
        Storage::fake('public');

        $this->actingAs($this->owner)
            ->post(route('admin.marketing.store.image'), ['image' => UploadedFile::fake()->image('واجهة.jpg')])
            ->assertSessionHas('uploaded');

        $this->assertNotEmpty(Storage::disk('public')->files('website/'.$this->shop->id));
    }

    /**
     * والرابطُ يصل الشاشةَ فعلًا.
     *
     * كان يُومَض في الجلسة ولا يُشارَك مع Inertia — فيقرأ الحقلُ
     * `undefined` ولا يمتلئ: تُرفع الصورةُ وتُحفظ على القرص، ولا يظهر
     * في الشاشة شيء. (ويقرؤه حقلُ صور البانِي من الموضع نفسِه.)
     */
    public function test_the_uploaded_link_reaches_the_screen(): void
    {
        Storage::fake('public');

        $this->actingAs($this->owner)
            ->post(route('admin.marketing.store.image'), ['image' => UploadedFile::fake()->image('a.jpg')]);

        $flash = $this->actingAs($this->owner)->get(route('admin.settings.index'))
            ->assertOk()->viewData('page')['props']['flash'];

        $this->assertArrayHasKey('uploaded', $flash);
        $this->assertStringContainsString('/storage/website/'.$this->shop->id.'/', (string) $flash['uploaded']);
    }

    /** وما ليس صورةً يُردّ */
    public function test_a_file_that_is_not_a_picture_is_refused(): void
    {
        Storage::fake('public');

        $this->actingAs($this->owner)
            ->post(route('admin.marketing.store.image'), ['image' => UploadedFile::fake()->create('x.pdf', 10)])
            ->assertSessionHasErrors('image');
    }

    /* ═══════════ ٢) القسمُ الذي يكتبه ═══════════ */

    /** قسمُه يُعرض بعنوانه ونصّه وزرّه */
    public function test_his_own_section_appears_as_he_wrote_it(): void
    {
        $this->set([
            'store_block_on' => '1', 'store_block_title' => 'اشتراك الورد الشهريّ',
            'store_block_text' => "باقةٌ كلَّ أسبوع\nتصلك إلى بابك",
            'store_block_cta' => 'اشترك', 'store_block_href' => '/shop',
        ]);

        $page = $this->page();

        $this->assertStringContainsString('اشتراك الورد الشهريّ', $page);
        $this->assertStringContainsString('تصلك إلى بابك', $page);
        $this->assertStringContainsString('rb-sec-block', $page);
    }

    /** ولا نصفَ قسمٍ على صفحة: عنوانٌ بلا نصٍّ لا يُعرض */
    public function test_half_a_section_is_not_shown(): void
    {
        $this->set(['store_block_on' => '1', 'store_block_title' => 'عنوانٌ وحده', 'store_block_text' => '']);

        $this->assertNull(StorePage::block($this->shop->id));
        $this->assertStringNotContainsString('عنوانٌ وحده', $this->page());
    }

    /** ولا زرَّ بلا وجهة — ولا وجهةَ بلا اسمٍ يُضغط */
    public function test_a_button_without_a_destination_is_not_drawn(): void
    {
        $this->set([
            'store_block_on' => '1', 'store_block_title' => 'قسمي', 'store_block_text' => 'نصّي',
            'store_block_cta' => 'اضغط', 'store_block_href' => '',
        ]);

        $this->assertNull(StorePage::block($this->shop->id)['cta']);
    }

    /** ومُطفأٌ لا يُعرض ولو مُلئ */
    public function test_a_closed_section_stays_closed(): void
    {
        $this->set(['store_block_on' => '0', 'store_block_title' => 'قسمي', 'store_block_text' => 'نصّي']);

        $this->assertNull(StorePage::block($this->shop->id));
        $this->assertStringNotContainsString('rb-sec-block', $this->page());
    }

    /* ═══════════ ٣) المختارات ═══════════ */

    /** ما اختاره يتصدّر — بترتيب اختياره */
    public function test_what_he_picked_leads_the_page(): void
    {
        $ids = Product::where('business_id', $this->shop->id)->orderByDesc('id')->pluck('id')->take(2)->all();
        $this->set(['store_featured' => implode(',', $ids)]);

        $this->assertSame($ids, StorePage::featured($this->shop->id));

        $best = $this->get('/s/ribbon')->assertOk()->viewData('best');
        $this->assertSame($ids, array_column($best, 'id'));
    }

    /** وصنفٌ أُبرز ثمّ أُخفي لا يُقحَم بأمرِ إعدادٍ قديم */
    public function test_a_hidden_product_is_not_forced_onto_the_page(): void
    {
        $p = Product::where('business_id', $this->shop->id)->firstOrFail();
        $this->set(['store_featured' => (string) $p->id]);
        $p->update(['published' => false]);

        $best = $this->get('/s/ribbon')->assertOk()->viewData('best');

        $this->assertNotContains($p->id, array_column($best, 'id'), 'عُرض صنفٌ مخفيّ لأنّه مُبرَز');
    }

    /** وأربعةٌ لا أكثر — ولو كُتب عشرون */
    public function test_four_and_no_more(): void
    {
        $this->set(['store_featured' => '1,2,3,4,5,6,7']);

        $this->assertCount(4, StorePage::featured($this->shop->id));
    }

    /** ومن لم يختر يبقى على المحسوب */
    public function test_picking_none_keeps_the_counted_list(): void
    {
        $this->set(['store_featured' => '']);

        $this->assertNotEmpty($this->get('/s/ribbon')->assertOk()->viewData('best'));
    }

    /* ═══════════ ٤) الأقسامُ تُطفأ وتُرتَّب ═══════════ */

    /** قسمٌ أُطفئ لا يُعرض */
    public function test_a_section_he_closed_is_gone(): void
    {
        $this->set(['store_sections' => 'cats,best,about']);

        $page = $this->page();

        $this->assertStringContainsString('rb-sec-cats', $page);
        $this->assertStringNotContainsString('rb-sec-block', $page);
        // «آراء الزبائن» خرجت من القائمة — فلا عنوانَ لها
        $this->assertSame(['cats', 'best', 'about'], StorePage::order($this->shop->id));
        $this->assertFalse(StorePage::shows($this->shop->id, 'reviews'));
    }

    /**
     * والترتيبُ ترتيبُه — يُقرأ من الصفحة لا من الإعداد.
     *
     * توكيدٌ يقرأ الإعدادَ يقارنه بنفسه. والمقياسُ أيُّهما يسبق الآخر في
     * الصفحة التي يفتحها الزبون.
     */
    public function test_the_order_is_the_order_he_chose(): void
    {
        // و«عنّا» لا يُرسم بلا نبذة — فتُكتب قبل أن يُقاس ترتيبُه
        $this->set(['store_about' => 'محلُّ وردٍ في الخوير منذ ٢٠١٠.', 'store_sections' => 'about,cats']);
        $page = $this->page();

        $this->assertLessThan(
            strpos($page, 'rb-sec-cats'),
            strpos($page, 'rb-sec-about'),
            'لم يسبق «عنّا» الفئاتِ كما رُتّب',
        );

        $this->set(['store_sections' => 'cats,about']);
        $flipped = $this->page();

        $this->assertLessThan(
            strpos($flipped, 'rb-sec-about'),
            strpos($flipped, 'rb-sec-cats'),
            'لم ينقلب الترتيبُ حين قُلب',
        );
    }

    /** والواجهةُ فوقها دائمًا — هي هويّةُ الصفحة لا قسمًا يُطفأ */
    public function test_the_hero_is_never_one_of_the_switches(): void
    {
        $this->assertNotContains('hero', StorePage::SECTIONS);

        $this->set(['store_sections' => 'about']);
        $this->assertStringContainsString('rb-landing', $this->page());
    }

    /* ═══════════ ٥) والوجهةُ رابطٌ لا سطرُ كود ═══════════ */

    /**
     * زرٌّ وجهتُه `javascript:` يُردّ عند الحفظ — لا يُعرض على صفحةٍ عامّة.
     *
     * ومتاجرُ أبعاد كلُّها على نطاقٍ واحد: ما يُنفَّذ في صفحةِ متجرٍ يقرأ ما
     * يخصّ النطاق نفسَه. والقاعدةُ مكتوبةٌ في المستودع منذ البانِي — وكان
     * هذا الحقلُ وحدَه خارجَها.
     */
    public function test_a_button_that_runs_code_is_refused(): void
    {
        $this->actingAs($this->owner)
            ->from(route('admin.settings.index'))
            ->post(route('admin.marketing.store.save'), [
                'store_block_on' => true,
                'store_block_title' => 'اشتراك الورد',
                'store_block_text' => 'باقةٌ كلَّ أسبوع.',
                'store_block_cta' => 'اشترك',
                'store_block_href' => 'javascript:alert(document.cookie)',
            ])
            ->assertSessionHasErrors('store_block_href');

        $this->assertSame('', MarketingSettings::group($this->shop->id, 'website')['store_block_href'] ?? '');
    }

    /** و`data:` و`//host` مثلُها — والشكلُ النسبيّ لا يخدعُ الحارس */
    public function test_the_shapes_that_look_relative_but_are_not(): void
    {
        foreach (['data:text/html,<script>1</script>', '//evil.example/x', 'JaVaScRiPt:alert(1)'] as $bad) {
            $this->assertFalse(SafeLink::allows($bad), "قُبلت وجهةٌ لا تصلح: {$bad}");
        }
    }

    /** ورابطُه الصحيحُ يمرّ — كاملًا كان أو مسارًا داخل متجره */
    public function test_the_destination_he_meant_is_saved(): void
    {
        foreach (['/shop', 'https://wa.me/96895259066', 'tel:+96895259066'] as $good) {
            $this->actingAs($this->owner)
                ->from(route('admin.settings.index'))
                ->post(route('admin.marketing.store.save'), [
                    'store_block_on' => true,
                    'store_block_title' => 'اشتراك الورد',
                    'store_block_text' => 'باقةٌ كلَّ أسبوع.',
                    'store_block_cta' => 'اشترك',
                    'store_block_href' => $good,
                ])
                ->assertSessionHasNoErrors();

            $this->assertSame($good, MarketingSettings::group($this->shop->id, 'website')['store_block_href'] ?? null);
            $this->assertStringContainsString('href="'.$good.'"', $this->page());
        }
    }

    /** وصورةُ الواجهة رابطٌ كذلك — ومن أرسل الحمولةَ بيده لا يمرّ ببابِ الرفع */
    public function test_a_picture_that_is_not_a_link_is_refused(): void
    {
        $this->actingAs($this->owner)
            ->from(route('admin.settings.index'))
            ->post(route('admin.marketing.store.save'), ['store_hero_image' => 'javascript:alert(1)'])
            ->assertSessionHasErrors('store_hero_image');
    }

    /* ═══════════ ٦) والعنوانُ يتبع مصدرَه ═══════════ */

    /**
     * صفٌّ اختاره بيده يُسمّى «مختاراتنا» لا «الأكثر مبيعاً».
     *
     * والثانيةُ دعوى عن البيع يقرؤها الزبون ويبني عليها ثقتَه — فصفٌّ رتّبه
     * صاحبُ المحلّ ثمّ سُمّي بها كذبٌ عليه وهو لا يدري.
     */
    public function test_what_he_arranged_is_not_called_what_sold_most(): void
    {
        $ids = Product::where('business_id', $this->shop->id)->orderByDesc('id')->pluck('id')->take(2)->all();
        $this->set(['store_featured' => implode(',', $ids)]);

        $page = $this->page();

        $this->assertStringContainsString('مختاراتنا', $page);
        $this->assertStringNotContainsString('الأكثر مبيعاً', $page);
    }

    /** ومن لم يختر يبقى عنوانُه على الدعوى الصحيحة */
    public function test_the_counted_list_keeps_its_own_name(): void
    {
        $page = $this->page();

        $this->assertStringContainsString('الأكثر مبيعاً', $page);
        $this->assertStringNotContainsString('مختاراتنا', $page);
    }

    /**
     * ومختاراتٌ لم يبقَ منها شيءٌ معروض تعود إلى الحسبة — لا إلى فراغ.
     *
     * يختار أربعةً في العيد ثمّ تنفد أو يُخفيها بعده، فيختفي القسمُ كلُّه
     * من صفحته بلا أن يفعل شيئًا ولا شيءَ يقول له لماذا.
     */
    public function test_picks_that_all_went_away_do_not_empty_the_row(): void
    {
        $ids = Product::where('business_id', $this->shop->id)->pluck('id')->take(2)->all();
        $this->set(['store_featured' => implode(',', $ids)]);

        Product::whereIn('id', $ids)->update(['published' => false]);

        $page = $this->page();

        $this->assertNotEmpty($this->get('/s/ribbon')->viewData('best'), 'اختفى الصفُّ كلُّه لأنّ مختاراتِه أُخفيت');
        $this->assertStringContainsString('rb-sec-best', $page);
        // وعنوانُه يعود إلى الحسبة لأنّ المعروضَ صار محسوبًا
        $this->assertStringContainsString('الأكثر مبيعاً', $page);
    }

    /* ═══════════ ٧) ولا يَعِد المتجرُ بما ليس على رفّه ═══════════ */

    /**
     * متجرٌ لا صنفَ منشورًا فيه لا يُرسم عليه شريطُ المناسبات.
     *
     * نصُّه وعدٌ — «نجهّز الباقة مع كرت هدية ونوصلها في الوقت الذي تحدده» —
     * وزرُّه يقود إلى «لا منتجات هنا بعد». فيقرأ الزبون وعدًا ثمّ يجد رفًّا
     * فارغًا، ولا يعود. وكلُّ أخواته تسأل قبل أن تُرسم، وكان وحدَه لا يسأل.
     */
    public function test_an_empty_shelf_promises_nothing(): void
    {
        Product::where('business_id', $this->shop->id)->update(['published' => false]);

        $page = $this->page();

        $this->assertStringNotContainsString('rb-sec-banner', $page);
        // والصفحةُ تبقى صفحةً — الواجهةُ هويّةٌ لا تُطفأ بفراغ الرفّ
        $this->assertStringContainsString('rb-landing', $page);
    }

    /** ورفٌّ عامرٌ يُرسم عليه — ولو أُطفئ «وصل حديثًا» */
    public function test_a_full_shelf_still_promises(): void
    {
        $this->assertStringContainsString('rb-sec-banner', $this->page());

        $this->set(['store_sections' => 'banner,best']);
        $this->assertStringContainsString('rb-sec-banner', $this->page());
    }

    /**
     * و«استكشف مجموعاتنا» قفزةٌ إلى قسمٍ في الصفحة — فلا تُرسم بلا قسمها.
     *
     * كان الزرُّ يُرسم دائمًا ويشير إلى `#rb-cats`: يُضغط فلا يقع شيء.
     * وصاحبُ المحلّ صار يُطفئ ذلك القسمَ بيده من «صفحة متجرك» — فصنعت
     * الشاشةُ الجديدةُ زرًّا ميّتًا بأمرٍ مشروع.
     */
    public function test_the_jump_is_not_drawn_without_its_landing(): void
    {
        $this->assertStringContainsString('#rb-cats', $this->page());

        $this->set(['store_sections' => 'best,new']);
        $this->assertStringNotContainsString('#rb-cats', $this->page());
    }

    /** ومتجرٌ بلا فئةٍ ذاتِ بضاعة مثلُه — القسمُ لا يُرسم، فلا قفزةَ إليه */
    public function test_no_jump_where_there_are_no_categories(): void
    {
        Product::where('business_id', $this->shop->id)->update(['category_id' => null]);

        $page = $this->page();

        $this->assertStringNotContainsString('rb-sec-cats', $page);
        $this->assertStringNotContainsString('#rb-cats', $page);
    }

    /* ═══════════ ٨) صورةُ الشريط وسطرُ التذييل ═══════════ */

    /**
     * شريطُ المناسبات يلبس صورةً يرفعها — وبلا صورةٍ تبقى الخطوطُ المرسومة.
     *
     * والشريطُ يَعِد بباقةٍ وكرتِ هدية، وكان يُرسم إلى جانب وعده مستطيلٌ
     * مخطَّطٌ بالـCSS. فيقرأ الزبون وعدًا ولا يرى منه شيئًا.
     */
    public function test_the_banner_wears_the_picture_he_picked(): void
    {
        $this->assertStringNotContainsString('rb-banner-image', $this->page());

        $this->set(['store_banner_image' => '/storage/website/1/banner.jpg']);

        $this->assertStringContainsString('/storage/website/1/banner.jpg', $this->page());
    }

    /** ووجهةُ الصورة تُحرس كأختيها — لا سطرَ كودٍ في `src` */
    public function test_a_banner_that_is_not_a_link_is_refused(): void
    {
        $this->actingAs($this->owner)
            ->from(route('admin.settings.index'))
            ->post(route('admin.marketing.store.save'), ['store_banner_image' => 'javascript:alert(1)'])
            ->assertSessionHasErrors('store_banner_image');
    }

    /**
     * وسطرُ التذييل يكتبه — وبلا كتابةٍ يبقى ما كان.
     *
     * «FLOWERS · LOUNGE · AND MORE» وصفُ محلٍّ بعينه، وكان مكتوبًا بحروفه في
     * القالب. ومحلٌّ آخر يلبس الواجهةَ نفسَها يُذيّل صفحتَه بوصفِ غيره.
     */
    public function test_the_footer_line_is_his_to_write(): void
    {
        $this->assertStringContainsString('FLOWERS · LOUNGE · AND MORE', $this->page());

        $this->set(['store_tagline' => 'ورودٌ · هدايا · توصيل']);
        $page = $this->page();

        $this->assertStringContainsString('ورودٌ · هدايا · توصيل', $page);
        $this->assertStringNotContainsString('FLOWERS · LOUNGE · AND MORE', $page);
    }

    /** وفي كلّ صفحةٍ لا في الرئيسية وحدها — التذييلُ في القالب العامّ */
    public function test_the_footer_line_follows_every_page(): void
    {
        $this->set(['store_tagline' => 'ورودٌ · هدايا · توصيل']);

        $this->assertStringContainsString(
            'ورودٌ · هدايا · توصيل',
            $this->get('/s/ribbon/shop')->assertOk()->getContent(),
        );
    }
}
