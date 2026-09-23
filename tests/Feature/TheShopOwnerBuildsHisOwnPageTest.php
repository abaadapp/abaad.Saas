<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
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
}
