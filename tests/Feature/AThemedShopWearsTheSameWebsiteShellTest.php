<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\PaymentGateway;
use App\Models\Setting;
use App\Models\User;
use App\Support\Ledger;
use App\Support\MarketingSettings;
use App\Support\Store\PageEditor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * ضبطُ متجر الواجهة الخاصّة — صفحةٌ واحدة، ومحرّرٌ إلى جانبها.
 *
 * ═══ العطبُ الأوّل: لوحتان للشيء نفسه ═══
 *
 * كان صاحبُ الواجهة يُساق إلى بطاقةٍ داخل «الإعدادات» فيها ثلاثون مقبضًا،
 * وجارُه يفتح قسمًا كاملًا بترويسةٍ وشريطٍ وأبواب. فصار له مثلُ ما لجاره:
 * ستُّ شاشاتٍ بشريط تبويبات. وهو ما كان يحرسه هذا الملفّ.
 *
 * ═══ والعطبُ الثاني: الدواءُ صار داءً ═══
 *
 * قِيست الشاشاتُ الستّ فوُجد التوزيعُ غيرَ عادل:
 *
 *   • «الدومين» ثلاثةُ مقابض، و«الظهور في البحث» ثلاثة، و«الصفحات» ثلاثة.
 *   • و«المتجر والطلبات» **اثنان وثلاثون** ومعها **زرّا حفظٍ متجاوران**
 *     لا يحفظ أحدُهما ما يحفظه الآخر.
 *   • وفوقها «عام» — وهي **قائمةٌ ثانية**: سبعُ بطاقاتٍ تقود إلى التبويبات
 *     نفسِها، فوقها شريطُها.
 *
 * فمن أراد تغيير رسم التوصيل مرّ بقائمتين وحمّل الصفحةَ مرّتين قبل أن يبلغ
 * حقلًا واحدًا.
 *
 * ═══ وما صار ═══
 *
 * صفحةُ ضبطٍ واحدة بعمودٍ يقفز إلى قسمها، وزرُّ حفظٍ واحد يظهر عند أوّل
 * تغيير. و«التصميم» تبقى شاشتَها: محرّرُ أقسامٍ بمعاينةٍ حيّة لا نموذجُ
 * حقول. وقائمةُ الجاهزية انتقلت إلى لوحة التشغيل — «أين متجري الآن» سؤالُ
 * لوحةٍ لا سؤالُ نموذج.
 *
 * ═══ وما يُحرَس هنا ═══
 *
 * أنّ الشاشتين ترتديان الترويسة نفسَها، وأنّ كلَّ عنوانٍ قديمٍ يصل قسمَه،
 * وأنّ كلَّ مقبضٍ في الأقسام يصل مخزنَه، وأنّ مقبضًا لا يسكن قسمين.
 */
class AThemedShopWearsTheSameWebsiteShellTest extends TestCase
{
    use RefreshDatabase;

    /**
     * الشاشتان: الملفّ ← [التبويبُ الذي تُضيئه، المسارُ الذي يفتحها].
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const SCREENS = [
        'ThemeSettings.tsx' => ['admin.website.site', 'admin.website.site'],
        'ThemeEditor.tsx' => ['admin.website.editor', 'admin.website.editor'],
    ];

    /** أقسامُ صفحة الضبط: الملفّ ← مُعرّفُ القسم في الصفحة وفي العمود */
    private const SECTIONS = [
        'Address.tsx' => 'address',
        'Pages.tsx' => 'pages',
        'Checkout.tsx' => 'checkout',
        'Fields.tsx' => 'fields',
        'Gateway.tsx' => 'gateway',
        'Seo.tsx' => 'seo',
    ];

    /** ما كان شاشةً وصار قسمًا — المسارُ القديم ← القسمُ الذي يصله */
    private const MOVED = [
        'admin.website.shop' => 'checkout',
        'admin.website.seo' => 'seo',
        'admin.website.domain' => 'address',
        'admin.website.pages' => 'pages',
    ];

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

    private function screen(string $file): string
    {
        return (string) file_get_contents(resource_path('js/Pages/Admin/Website/'.$file));
    }

    private function section(string $file): string
    {
        return (string) file_get_contents(resource_path('js/Pages/Admin/Website/theme/sections/'.$file));
    }

    /* ═══════════ الترويسةُ واحدة ═══════════ */

    /**
     * والشاشتان تقرآن ترويستَهما من ملفٍّ واحد.
     *
     * ولو كُتبت في كلٍّ منهما لاختلفت: زرٌّ يُسمّى «معاينة» هنا و«عرض المتجر»
     * هناك، وشارةٌ تقول «منشور» في شاشةٍ و«يعمل» في أختها عن المتجر نفسِه.
     */
    public function test_both_screens_wear_one_header(): void
    {
        foreach (self::SCREENS as $file => [$tab]) {
            $code = $this->screen($file);

            $this->assertStringContainsString('ThemeHeader', $code, $file.' بلا الترويسة المشتركة');
            $this->assertStringContainsString(
                'current="'.$tab.'"',
                $code,
                $file.' لا يُضيء تبويبَه — أو يُضيء تبويبَ غيره',
            );
        }
    }

    /** ولا شاشةَ تُضيء تبويبَ أختها — فيقف صاحبُها لا يعرف أين هو */
    public function test_no_screen_lights_another_screens_tab(): void
    {
        $seen = [];

        foreach (self::SCREENS as $file => [$tab]) {
            $this->assertArrayNotHasKey($tab, $seen, $tab.' يُضيء من شاشتين: '.($seen[$tab] ?? '').' و'.$file);
            $seen[$tab] = $file;
        }

        $this->assertCount(2, $seen);
    }

    /**
     * وشريطُ الواجهة الخاصّة من تبويبين — ويفترق عن شريط جاره عن حقّ.
     *
     * البانِي مواقعُ كثيرةٌ وصفحاتٌ تُضاف وقوالبُ تُبدَّل، فله ستّة. وهذه
     * واجهةٌ واحدةٌ بصفحاتٍ أربعٍ مكتوبة، فلم يبقَ ممّا يُنتقل إليه إلّا
     * وجهتان: صفحتُه التي تُرسَم، وضبطُه الذي يُملأ.
     */
    public function test_the_strip_is_two_tabs_and_both_open(): void
    {
        $strip = (string) file_get_contents(resource_path('js/Components/SectionTabs.tsx'));

        $from = strpos($strip, 'export const THEME_TABS');
        $this->assertNotFalse($from, 'لا شريطَ تبويباتٍ للواجهة الخاصّة');

        preg_match_all(
            "/routeName: '([^']+)'/",
            substr($strip, $from, strpos($strip, '];', $from) - $from),
            $matches,
        );

        $this->assertSame(
            ['admin.website.editor', 'admin.website.site'],
            $matches[1],
            'شريطُ الواجهة الخاصّة تبويبان: صفحتُه وضبطُه',
        );

        foreach ($matches[1] as $name) {
            $this->assertNotNull(Route::getRoutes()->getByName($name), $name.' تبويبٌ إلى مسارٍ لا وجود له');

            $this->actingAs($this->owner)->followingRedirects()->get(route($name))
                ->assertOk($name.' تبويبٌ يُعرض ثمّ يردّ من ضغطه');
        }
    }

    /* ═══════════ وما كان شاشةً صار قسمًا ═══════════ */

    /**
     * والعناوينُ القديمة تصل أقسامَها — لا 404 ولا أوّلَ الصفحة.
     *
     * أربعةُ مساراتٍ كانت شاشاتٍ قائمة، وقد وُزّعت على زبائن ومُحفظت في
     * متصفّحاتهم. ومن فتح إشارتَه المرجعية إلى «الدومين» يجب أن يقف على
     * عنوان متجره لا على أوّل صفحةٍ طويلة يبحث فيها.
     */
    public function test_every_old_path_lands_on_its_section(): void
    {
        foreach (self::MOVED as $name => $anchor) {
            $this->actingAs($this->owner)->get(route($name))
                ->assertRedirect(route('admin.website.site').'#'.$anchor);
        }
    }

    /**
     * وكلُّ مدخلٍ في العمود يجد قسمَه في الصفحة.
     *
     * والعمودُ يقفز بـ`getElementById`: مُعرّفٌ لا وجود له يعني ضغطةً لا
     * يقع بها شيء — وهو أسوأُ من مدخلٍ لا يُرسم، لأنّ صاحبَه يظنّ الصفحةَ
     * معطوبة.
     */
    public function test_every_rail_entry_finds_its_section(): void
    {
        $page = $this->screen('ThemeSettings.tsx');

        preg_match_all("/id: '([a-z]+)',\n\s+label:/", $page, $rail);
        $this->assertNotSame([], $rail[1], 'لا مداخلَ في العمود — الحارسُ يحرس لا شيء');

        /* والمُعرّفاتُ تُقرأ من الأقسام نفسِها لا من قائمةٍ ثانيةٍ تُكتب هنا */
        $ids = [];
        foreach (self::SECTIONS as $file => $_) {
            preg_match_all('/<section id="([a-z]+)"/', $this->section($file), $m);
            $ids = array_merge($ids, $m[1]);
        }

        foreach ($rail[1] as $id) {
            $this->assertContains($id, $ids, 'العمودُ يقفز إلى «'.$id.'» ولا قسمَ بهذا المُعرّف');
        }

        // ولا قسمَ بلا مدخلٍ يقود إليه: قسمٌ لا يُذكر في العمود لا يُعثر عليه
        foreach ($ids as $id) {
            $this->assertContains($id, $rail[1], 'قسمُ «'.$id.'» لا مدخلَ له في العمود');
        }
    }

    /**
     * ═══ وكلُّ خاصيّةٍ تقرؤها الصفحةُ يُرسلها الخادم ═══
     *
     * وهذا الحارسُ وُضع بعد شاشةٍ بيضاء: شاشةٌ ارتدت الترويسةَ المشتركة
     * فصارت تقرأ `site` — ولم يُرسَل. فصار `site` غيرَ معرَّف، و`site.published`
     * تُسقط الشاشةَ كلَّها عند أوّل تصيير: صفحةٌ بيضاء بلا رسالةٍ ولا سطرٍ في
     * سجلّ الخادم، لأنّ العطب في المتصفّح لا فيه.
     */
    public function test_every_property_the_screens_read_is_actually_sent(): void
    {
        foreach (self::SCREENS as $file => [, $open]) {
            $code = $this->screen($file);

            $block = substr($code, $from = strpos($code, 'interface Props extends ThemeShell {'));
            $block = substr($block, 0, strpos($block, "\n}\n"));

            // الأسماءُ في الجذر وحدها — وما كان اختياريًّا (`?:`) لا يُشترط
            preg_match_all('/^    ([a-zA-Z_]+): /m', $block, $m);

            $wanted = array_merge(['theme', 'site'], $m[1]);
            $props = $this->actingAs($this->owner)->followingRedirects()->get(route($open))->assertOk()
                ->viewData('page')['props'];

            foreach ($wanted as $key) {
                $this->assertArrayHasKey($key, $props, $file.' تقرأ «'.$key.'» ولا يرسلها الخادم');
            }

            // والترويسةُ تقرأ منها خمسًا — وغيابُ أيّها يُسقط الشاشة
            foreach (['name', 'published', 'url', 'slug', 'host'] as $key) {
                $this->assertArrayHasKey($key, $props['site'], $file.' ترويستُها بلا «'.$key.'»');
            }
        }
    }

    /* ═══════════ والمقبضُ في قسمٍ واحد ═══════════ */

    /**
     * لا مفتاحَ يُحرَّر في موضعين.
     *
     * وهو الحارسُ الذي يمنع العطبَ الصامت: نموذجُ Inertia يلتقط قيمَه حين
     * تُفتح الشاشة، فمقبضان لمفتاحٍ واحد — أحدُهما في المحرّر والآخرُ في
     * الضبط — يعني أنّ الحفظَ من أحدهما يكتب فوق الآخر بلا خطأٍ ولا رسالة.
     */
    public function test_no_key_is_edited_in_two_places(): void
    {
        $where = [];

        // ما يُحرَّر في محرّر الصفحة — من مصدره لا من قراءة نصّه
        foreach (PageEditor::FIELDS as $fields) {
            foreach ($fields as $field) {
                $where[$field['key']] = 'ThemeEditor.tsx';
            }
        }
        $where['store_sections'] = 'ThemeEditor.tsx';

        foreach (array_keys(self::SECTIONS) as $file) {
            preg_match_all("/setData\('((?:store|site)_[a-z_]+)'/", $this->section($file), $m);

            foreach (array_unique($m[1]) as $key) {
                $this->assertArrayNotHasKey(
                    $key,
                    $where,
                    $key.' يُحرَّر في '.$file.' وفي '.($where[$key] ?? ''),
                );

                $where[$key] = $file;
            }
        }

        // وساعاتُ العمل تُكتب في التذييل — فموضعُها المحرّر لا «التوصيل»
        $this->assertSame('ThemeEditor.tsx', $where['store_hours'] ?? null);
    }

    /**
     * وبطاقةُ المتجر في «الإعدادات» لا تُرسم لمن لبس واجهةً خاصّة.
     *
     * ولو رُسمت لَحملت نسخةً ثانية من كلّ مقبضٍ انتقل — ونصفُ شاشةٍ باقية
     * أسوأُ من شاشةٍ كاملةٍ في الموضع الخطأ.
     */
    public function test_the_settings_card_is_not_drawn_for_a_themed_shop(): void
    {
        $screen = (string) file_get_contents(resource_path('js/Pages/Admin/Settings/Index.tsx'));

        $this->assertStringContainsString(
            'const showStoreCard = ! store.themed &&',
            $screen,
            'بطاقةُ المتجر ما زالت تُرسم لمن لبس واجهةً خاصّة',
        );
    }

    /**
     * ═══ وكلُّ مقبضٍ في الأقسام يصل مخزنَه فعلًا ═══
     *
     * وهذا أقسى الحرّاس وأرخصُها: القسمُ يرسم المقبض، والنموذجُ يرسله،
     * والخادمُ يُصادق حمولتَه بقائمةٍ مكتوبةٍ بيده — فمفتاحٌ خارجَ القائمة
     * يُسقطه `validated()` بهدوء، ويُردّ «حُفظ متجرك الإلكتروني» ولا يُحفظ
     * شيء. فيُقلَّب المقبضُ ويُحفظ ويُعاد فتحُ الصفحة على ما كان.
     */
    public function test_every_knob_the_sections_draw_really_reaches_its_store(): void
    {
        $sample = [
            'store_allow_orders' => '0',
            'store_bank' => 'بنك مسقط — 1234',
            'store_delivery_areas' => "الخوير\nالسيب",
            'store_delivery_fee' => '1.500',
            'store_delivery_note' => 'يُسلَّم خلال ساعتين',
            'store_delivery_slots' => '9 ص – 12 م',
            'store_free_delivery_over' => '20',
            'store_gift_card' => '1',
            'store_gift_card_price' => '0.750',
            'store_image_note' => 'تُنسَّق يدويًّا',
            'store_max_days' => '30',
            'store_on' => '1',
            'store_pay_cod' => '0',
            'store_pay_transfer' => '1',
            'store_about_image' => '/storage/store/about.jpg',
            'store_pages' => 'contact',
            'store_seo_title' => 'ريبون لاونج — ورد وهدايا بمسقط',
            'store_seo_desc' => 'باقاتُ وردٍ تُوصَّل في مسقط خلال اليوم نفسه.',
            'store_seo_index' => '0',
        ];

        /*
         * والمفاتيحُ تُقرأ من الأقسام نفسِها — فمقبضٌ يُضاف غدًا يدخل الحارس
         * بلا سطرٍ يُكتب له.
         *
         * و`site_slug` ليس منها: مخزنُه عمودٌ في `businesses` لا صفٌّ في
         * `settings`، وله حرّاسه (تفرّدُه، ورفضُ النشر بلا عنوان).
         */
        $drawn = [];
        foreach (array_keys(self::SECTIONS) as $file) {
            preg_match_all("/setData\('(store_[a-z_]+)'/", $this->section($file), $m);
            $drawn = array_merge($drawn, $m[1]);
        }
        $drawn = array_values(array_unique($drawn));

        $this->assertNotSame([], $drawn, 'لم يُقرأ من الأقسام مقبضٌ واحد — الحارسُ يحرس لا شيء');
        $this->assertContains('store_pages', $drawn, 'مفاتيحُ الصفحات لا تُحفظ مع الصفحة');

        foreach ($drawn as $key) {
            $this->assertArrayHasKey($key, $sample, $key.' مقبضٌ جديد بلا قيمةِ فحصٍ في هذا الحارس');

            $this->actingAs($this->owner)
                ->from(route('admin.website.site'))
                ->post(route('admin.marketing.store.save'), [$key => $sample[$key]])
                ->assertRedirect();

            $this->assertSame(
                $sample[$key],
                (string) (MarketingSettings::group($this->shop->id, 'website')[$key] ?? ''),
                $key.' يُرسَل من القسم ولا يكتبه الخادم — مقبضٌ لا يُدير شيئًا',
            );
        }
    }

    /**
     * ═══ وزرُّ الحفظ واحد — إلّا بوّابةَ الدفع ═══
     *
     * كانت شاشةُ «المتجر والطلبات» تحمل زرَّين متجاورين لا يحفظ أحدُهما ما
     * يحفظه الآخر: من عدّل رسمَ التوصيل ثمّ ضغط الزرَّ الأسفل أضاع ما كتب
     * ولا شيء يقول له ذلك.
     *
     * فصار الحفظُ شريطًا واحدًا، ولم يبقَ نموذجٌ بزرِّه إلّا بوّابةَ الدفع —
     * وسببُه مكتوبٌ في ملفّها: حقلان فيها سرّان لا يُرسلان مع كلّ حفظ.
     */
    public function test_only_the_payment_gateway_keeps_a_button_of_its_own(): void
    {
        $withButtons = [];

        foreach (self::SECTIONS as $file => $id) {
            if (str_contains($this->section($file), 'type="submit"')) {
                $withButtons[] = $id;
            }
        }

        $this->assertSame(['gateway'], $withButtons, 'قسمٌ يحمل زرَّ حفظٍ خاصًّا به بلا سببٍ مكتوب');

        $this->assertStringContainsString(
            'data-testid="save-bar"',
            (string) file_get_contents(resource_path('js/Pages/Admin/Website/theme/SaveBar.tsx')),
            'لا شريطَ حفظٍ واحدًا للصفحة',
        );
    }

    /* ═══════════ وما تقوله الصفحةُ يُقاس ═══════════ */

    /** ولا رابطَ يُعرض على متجرٍ لا يُفتح — زرٌّ يردّ «غير موجود» يُقرأ عطبًا */
    public function test_an_unpublished_shop_is_offered_no_link(): void
    {
        $props = $this->actingAs($this->owner)
            ->get(route('admin.website.site'))->assertOk()
            ->viewData('page')['props'];

        $this->assertFalse($props['site']['published']);
        $this->assertNull($props['site']['url']);

        MarketingSettings::save($this->shop->id, 'website', ['store_on' => '1']);

        $props = $this->actingAs($this->owner)
            ->get(route('admin.website.site'))->assertOk()
            ->viewData('page')['props'];

        $this->assertTrue($props['site']['published']);
        $this->assertNotNull($props['site']['url']);
    }

    /**
     * وجاهزيةُ المتجر تُقرأ في لوحة التشغيل — حيث انتقلت.
     *
     * وكانت في شاشة «عام»: شاشةٌ لا يُضبط فيها شيء، قائمةُ جاهزيةٍ وسبعُ
     * بطاقاتٍ تكرّر شريطَ التبويبات. فحُذفت الشاشةُ وبقي ما فيها حيث يُقرأ.
     */
    public function test_the_readiness_list_is_read_on_the_operations_board(): void
    {
        $props = $this->actingAs($this->owner)
            ->get(route('admin.website.index'))->assertOk()
            ->viewData('page')['props'];

        $this->assertNotSame([], $props['readiness'], 'لوحةُ التشغيل بلا جاهزية');

        foreach ($props['readiness'] as $fact) {
            foreach (['key', 'label', 'ok', 'optional', 'detail'] as $key) {
                $this->assertArrayHasKey($key, $fact, 'سطرُ جاهزيةٍ بلا «'.$key.'»');
            }
        }

        $keys = array_column($props['readiness'], 'key');
        $this->assertContains('slug', $keys, 'الجاهزيةُ لا تسأل عن عنوان المتجر');
        $this->assertContains('products', $keys, 'الجاهزيةُ لا تسأل عن بضاعةٍ معروضة');
    }

    /** وحقولُ إتمام الطلب تصل محسوبةً لا فارغة — فالفراغُ يُقرأ «ما كان» */
    public function test_the_checkout_fields_arrive_as_the_store_works_today(): void
    {
        $props = $this->actingAs($this->owner)
            ->get(route('admin.website.site'))->assertOk()
            ->viewData('page')['props'];

        $this->assertNotSame([], $props['fieldStates']);
        $this->assertNotSame([], $props['fulfilments']);
    }

    /**
     * وما أذِن به من الصفحات يصل محسوبًا لا خامًا.
     *
     * الفراغُ في العمود يعني «كلُّها» (انظر `StoreNav::allowed`)، فإرسالُه
     * فراغًا إلى الصفحة يجعل مفاتيحَ الصفحتين مطفأةً وهما مفتوحتان على
     * زبائنه — ويكفي أن يُحفظ مرّةً ليصير الكذبُ حقيقة.
     */
    public function test_the_pages_arrive_as_the_store_really_shows_them(): void
    {
        $props = $this->actingAs($this->owner)
            ->get(route('admin.website.site'))->assertOk()
            ->viewData('page')['props'];

        $this->assertSame('about,contact', $props['pages']['allowed'], 'متجرٌ جديد: صفحتاه مفتوحتان');

        MarketingSettings::save($this->shop->id, 'website', ['store_pages' => 'none']);

        $props = $this->actingAs($this->owner)
            ->get(route('admin.website.site'))->viewData('page')['props'];

        $this->assertSame('none', $props['pages']['allowed'], 'ومن أطفأهما يقرأ ذلك لا «كلُّها»');
    }

    /** ولا يخرج سرُّ بوّابةٍ إلى المتصفّح — خصائصُ Inertia تُقرأ في مصدر الصفحة */
    public function test_no_gateway_secret_reaches_the_browser(): void
    {
        PaymentGateway::create([
            'business_id' => $this->shop->id,
            'provider' => PaymentGateway::PAYMOB,
            'public_key' => 'pk_live_x',
            'secret_key' => 'sk_live_secret',
            'hmac_secret' => 'hmac_secret',
            'card_integration_id' => '12345',
            'active' => true,
        ]);

        $response = $this->actingAs($this->owner)->get(route('admin.website.site'))->assertOk();

        $response->assertDontSee('sk_live_secret');
        $response->assertDontSee('hmac_secret');

        $props = $response->viewData('page')['props'];
        $this->assertTrue($props['gateway']['has_secret']);
        $this->assertTrue($props['gateway']['has_hmac']);
    }

    /** ولا شاشةَ من الخمسِ القديمة باقيةٌ تُنسى فتفترق عن الأقسام */
    public function test_the_screens_that_became_sections_are_gone(): void
    {
        foreach (['ThemeSite', 'ThemeShop', 'ThemeSeo', 'ThemeDomain', 'ThemePages'] as $gone) {
            $this->assertFileDoesNotExist(
                resource_path('js/Pages/Admin/Website/'.$gone.'.tsx'),
                $gone.' ما زالت قائمةً إلى جانب قسمها — نسختان تفترقان',
            );
        }
    }
}
