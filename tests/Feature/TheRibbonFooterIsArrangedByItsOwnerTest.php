<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Category;
use App\Models\Currency;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Support\MarketingSettings;
use App\Support\Store\StoreCollections;
use App\Support\Store\StoreFooter;
use App\Support\Store\ThemePublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * تذييلُ RIBBON كما يرتّبه صاحبُه — أعمدتُه وعناوينُها وما يظهر فيها وسطرُه الأخير.
 *
 * ═══ وما يُحرَس ═══
 *
 *  • متجرٌ لم يرتّب تذييلَه تذييلُه القائمُ: ثلاثةُ أعمدةٍ بعناوينها، والحقوقُ والسطر.
 *  • ومن رتّبه: الأعمدةُ بترتيبه وعناوينه، والمخفيُّ لا يُرسم، وما أطفأه من سطور
 *    التواصل لا يُرسم، وعمودُ «تسوّق» بمجموعاته الظاهرة، وعمودُ نصٍّ بلغته وحدها.
 *  • وبياناتُ التواصل من «بيانات النشاط» نفسِها — لا تُكتب في التذييل ثانيةً.
 *  • والحفظُ صارم، والتذييلُ يُنشر كأخواته، ولا يمسّ متجرًا غيرَه.
 *
 * وقواعدُ الشاشة في `the-owner-arranges-his-footer.test.tsx`.
 */
class TheRibbonFooterIsArrangedByItsOwnerTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private Business $neighbour;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');

        [$this->shop, $this->owner] = $this->ribbon('ribbon', 'owner@foot.test');
        [$this->neighbour] = $this->ribbon('jaar', 'jaar@foot.test');

        $this->shop->forceFill(['email' => 'hello@ribbon.om', 'address' => 'مسقط'])->save();
        $cat = Category::create(['business_id' => $this->shop->id, 'name' => 'باقات', 'name_en' => 'Bouquets']);
        $rose = Product::create([
            'business_id' => $this->shop->id, 'name' => 'باقة ورد', 'price' => 10, 'cost' => 2, 'quantity' => 20,
            'category_id' => $cat->id, 'active' => true, 'published' => true,
        ]);

        MarketingSettings::save($this->shop->id, 'website', [
            'store_about' => 'نبذةٌ عن المحلّ',
            'store_tagline' => 'باقاتٌ تُقال بها الكلمات',
            'store_hours' => 'يوميًّا من العاشرة',
            StoreCollections::KEY => json_encode([
                ['slug' => 'occasions', 'title_ar' => 'المناسبات', 'title_en' => 'Occasions', 'visible' => true, 'products' => [$rose->id]],
                ['slug' => 'secret', 'title_ar' => 'مخفيّة', 'visible' => false, 'products' => [$rose->id]],
            ], JSON_UNESCAPED_UNICODE),
        ]);
    }

    /* ═══════════════════════ أدوات ═══════════════════════ */

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

    private function save(array|string $footer, array $more = [])
    {
        return $this->actingAs($this->owner)->post(route('admin.marketing.store.save'), [
            StoreFooter::KEY => is_string($footer) ? $footer : json_encode($footer, JSON_UNESCAPED_UNICODE),
        ] + $more);
    }

    /** التذييلُ وحده — من صفحةٍ يقرؤها الزائر */
    private function footer(string $lang = 'ar', string $slug = 'ribbon'): string
    {
        $html = $this->get('/s/'.$slug.'/?lang='.$lang)->assertOk()->getContent();
        preg_match('#<footer class="rb-foot">.*?</footer>#su', $html, $m);

        return $m[0] ?? '';
    }

    /** @return list<string> الأعمدةُ بترتيب رسمها */
    private function columns(string $lang = 'ar'): array
    {
        preg_match_all('#data-testid="rb-foot-(col-pages|col-shop|col-contact|text-[a-z0-9]+)"#', $this->footer($lang), $m);

        return $m[1];
    }

    private function column(string $key, array $over = []): array
    {
        return $over + ['key' => $key, 'visible' => true, 'title_ar' => '', 'title_en' => ''];
    }

    /* ═══════════════════════ التذييلُ القائم ═══════════════════════ */

    public function test_a_shop_that_arranged_nothing_keeps_its_footer(): void
    {
        $footer = $this->footer();

        $this->assertSame(['col-pages', 'col-shop', 'col-contact'], $this->columns());
        $this->assertStringContainsString('<h3>الموقع</h3>', $footer);
        $this->assertStringContainsString('<h3>تسوّق</h3>', $footer);
        $this->assertStringContainsString('<h3>تواصل معنا</h3>', $footer);
        $this->assertStringContainsString('نبذةٌ عن المحلّ', $footer);
        $this->assertStringContainsString('data-testid="rb-foot-whatsapp"', $footer);
        $this->assertStringContainsString('data-testid="rb-foot-email"', $footer);
        $this->assertStringContainsString('© '.now()->year.' متجر ribbon', $footer);
        $this->assertStringContainsString('باقاتٌ تُقال بها الكلمات', $footer);
        $this->assertStringNotContainsString('rb-foot-col-occasions', $footer, 'لا مجموعةَ في التذييل حتّى يختارها');

        // وما يساوي الأصليَّ يُحفظ فراغًا — فلا يتبدّل حرف
        $this->save(StoreFooter::defaults())->assertSessionHasNoErrors();
        $this->assertSame('', MarketingSettings::group($this->shop->id, 'website')[StoreFooter::KEY]);
    }

    /* ═══════════════════════ تذييلٌ يرتّبه ═══════════════════════ */

    public function test_the_owner_orders_names_and_hides_his_columns(): void
    {
        $this->save([
            'about' => false,
            'social' => false,
            'columns' => [
                $this->column('contact', ['title_ar' => 'كلّمنا', 'title_en' => 'Talk to us', 'phone' => true, 'whatsapp' => true, 'email' => false, 'address' => true, 'hours' => false]),
                $this->column('pages', ['hide' => ['about']]),
                $this->column('text', ['id' => 'd1', 'title_ar' => 'التوصيل', 'title_en' => '', 'body_ar' => "نوصل في مسقط\nفي اليوم نفسِه", 'body_en' => '']),
                $this->column('shop', ['visible' => false]),
            ],
            'line' => false,
            'copyright' => true,
            'copyright_ar' => 'جميع الحقوق لـ RIBBON',
            'copyright_en' => '',
        ])->assertSessionHasNoErrors();

        $this->assertSame(['col-contact', 'col-pages', 'text-d1'], $this->columns(), 'بترتيبه — والمخفيُّ لا يُرسم');

        $footer = $this->footer();
        $this->assertStringContainsString('<h3>كلّمنا</h3>', $footer);
        $this->assertStringContainsString('data-testid="rb-foot-phone"', $footer);
        $this->assertStringContainsString('data-testid="rb-foot-address"', $footer);
        $this->assertStringNotContainsString('data-testid="rb-foot-email"', $footer, 'ما أطفأه من سطور التواصل لا يُرسم');
        $this->assertStringNotContainsString('data-testid="rb-foot-hours"', $footer);
        $this->assertStringNotContainsString('data-testid="rb-foot-about"', $footer, 'وما أخفاه من روابط «الموقع»');
        $this->assertStringContainsString('data-testid="rb-foot-home"', $footer);
        $this->assertStringContainsString("نوصل في مسقط\nفي اليوم نفسِه", $footer);
        $this->assertStringNotContainsString('نبذةٌ عن المحلّ', $footer);
        $this->assertStringNotContainsString('rb-social', $footer);
        $this->assertStringContainsString('<span data-testid="rb-copyright">جميع الحقوق لـ RIBBON</span>', $footer);
        $this->assertStringNotContainsString('rb-tagline', $footer, 'والسطرُ المُطفأُ لا يُرسم');
    }

    public function test_the_shop_column_carries_the_collections_he_chose_and_only_the_visible_ones(): void
    {
        $this->save(['columns' => [$this->column('shop', ['all' => true, 'categories' => false, 'collections' => ['occasions', 'secret', 'gone']])]])
            ->assertSessionHasNoErrors();

        $saved = StoreFooter::settings(MarketingSettings::group($this->shop->id, 'website')[StoreFooter::KEY]);
        $shop = collect($saved['columns'])->firstWhere('key', 'shop');
        $this->assertSame(['occasions', 'secret'], $shop['collections'], 'ومجموعةٌ لا توجد تسقط ولا تردّ الحفظ');

        $footer = $this->footer();
        $this->assertStringContainsString('<a href="/s/ribbon/shop?collection=occasions" data-testid="rb-foot-col-occasions">المناسبات</a>', $footer);
        $this->assertStringNotContainsString('collection=secret', $footer, 'والمخفيّةُ لا تُرسم');
        $this->assertStringNotContainsString('/shop?cat=', $footer, 'وأطفأ الفئات');
        $this->assertSame(['col-shop', 'col-pages', 'col-contact'], $this->columns(), 'والغائبُ من الثابتة يُلحق آخرَه');
    }

    public function test_the_english_footer_reads_its_own_words(): void
    {
        $this->save([
            'columns' => [
                $this->column('contact', ['title_ar' => 'كلّمنا', 'title_en' => 'Talk to us']),
                $this->column('text', ['id' => 'd1', 'title_ar' => 'التوصيل', 'title_en' => '', 'body_ar' => 'بالعربيّة وحدها', 'body_en' => '']),
            ],
            'copyright_ar' => 'الحقوق محفوظة', 'copyright_en' => 'All rights reserved',
        ])->assertSessionHasNoErrors();

        $footer = $this->footer('en');
        $this->assertStringContainsString('<h3>Talk to us</h3>', $footer);
        $this->assertStringContainsString('<h3>Site</h3>', $footer, 'وبلا عنوانٍ يبقى عنوانُ القالب بلغة الصفحة');
        $this->assertStringNotContainsString('rb-foot-text-d1', $footer, 'وعمودُ نصٍّ بلا إنجليزيّة لا يُرسم في صفحتها');
        $this->assertStringContainsString('All rights reserved', $footer);
    }

    /* ═══════════════════════ الحفظ ═══════════════════════ */

    /** @return array<string, array{0: string}> */
    public static function forged(): array
    {
        $json = fn (array $v) => json_encode($v, JSON_UNESCAPED_UNICODE);
        $col = fn (string $key, array $over = []) => $over + ['key' => $key, 'visible' => true, 'title_ar' => '', 'title_en' => ''];

        return [
            'ليس JSON' => ['<footer>'],
            'عمودٌ لا يُعرف' => [$json(['columns' => [$col('script')]])],
            'عمودٌ مكرّر' => [$json(['columns' => [$col('pages'), $col('pages')]])],
            'ثلاثةُ أعمدة نصّ' => [$json(['columns' => [$col('text', ['id' => 'a']), $col('text', ['id' => 'b']), $col('text', ['id' => 'c'])]])],
            'عمودُ نصٍّ بلا معرّف' => [$json(['columns' => [$col('text', ['id' => '../x'])]])],
            'عنوانٌ أطولُ من حدّه' => [$json(['columns' => [$col('pages', ['title_ar' => str_repeat('ا', StoreFooter::TITLE_MAX + 1)])]])],
            'حقوقٌ أطولُ من حدّها' => [$json(['columns' => [], 'copyright_ar' => str_repeat('ا', StoreFooter::COPYRIGHT_MAX + 1)])],
        ];
    }

    #[DataProvider('forged')]
    public function test_a_forged_footer_is_refused_and_nothing_is_saved(string $raw): void
    {
        $this->save($raw)->assertSessionHasErrors(StoreFooter::KEY);

        $this->assertSame('', (string) (MarketingSettings::group($this->shop->id, 'website')[StoreFooter::KEY] ?? ''));
    }

    public function test_text_is_printed_as_text(): void
    {
        $this->save(['columns' => [$this->column('text', ['id' => 'x', 'title_ar' => '<b>عنوان</b>', 'body_ar' => '<script>alert(1)</script>', 'body_en' => ''])]])
            ->assertSessionHasNoErrors();

        $footer = $this->footer();
        $this->assertStringNotContainsString('<script>alert(1)</script>', $footer);
        $this->assertStringContainsString('&lt;script&gt;', $footer);
    }

    /* ═══════════════════════ النشرُ والعزل ═══════════════════════ */

    public function test_the_footer_waits_for_publishing_and_stays_in_its_shop(): void
    {
        ThemePublisher::enable($this->shop, (int) $this->owner->id);

        $this->save(['columns' => [$this->column('contact'), $this->column('pages'), $this->column('shop')], 'copyright' => false])
            ->assertSessionHasNoErrors();

        $this->assertSame(['col-pages', 'col-shop', 'col-contact'], $this->columns(), 'لا يراه زبونٌ قبل النشر');

        ThemePublisher::publish($this->shop, (int) $this->owner->id, 'التذييل');

        $this->assertSame(['col-contact', 'col-pages', 'col-shop'], $this->columns());
        $this->assertStringNotContainsString('rb-copyright', $this->footer());
        $this->assertStringContainsString('rb-copyright', $this->footer('ar', 'jaar'), 'وتذييلُ جاره كما هو');
    }

    /* ═══════════════════════ وحسابُ إنستغرام ═══════════════════════ */

    /*
     * كان حقلُه في المحرّر ولا قاعدةَ له في الحفظ — فيُسقطه التحقّقُ ولا يُحفظ
     * أبدًا. والاسمُ يُحفظ وحده: من الاسم أو من رابط الحساب الملصوق.
     */
    public function test_the_instagram_account_is_saved_from_its_name_or_its_link(): void
    {
        $this->save('', ['store_instagram' => 'https://www.instagram.com/ribbon.om/?hl=ar'])->assertSessionHasNoErrors();
        $this->assertSame('ribbon.om', MarketingSettings::group($this->shop->id, 'website')['store_instagram']);
        $this->assertStringContainsString('href="https://instagram.com/ribbon.om"', $this->footer());

        $this->save('', ['store_instagram' => '@ribbon_om'])->assertSessionHasNoErrors();
        $this->assertSame('ribbon_om', MarketingSettings::group($this->shop->id, 'website')['store_instagram']);

        $this->save('', ['store_instagram' => 'ليس اسمًا'])->assertSessionHasErrors('store_instagram');
        $this->assertSame('ribbon_om', MarketingSettings::group($this->shop->id, 'website')['store_instagram']);
    }
}
