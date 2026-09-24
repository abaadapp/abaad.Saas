<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
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
 * صاحبُ الواجهة الخاصّة يفتح قسمَ موقعه فيجد ما يجده جارُه.
 *
 * ═══ العطب الذي وُضع له ═══
 *
 * لوحتان مختلفتان للشيء نفسه. جارُه يفتح «الموقع الإلكتروني» فيجد ترويسةً
 * فيها اسمُ موقعه وثلاثةَ أزرار، وشريطَ تبويبات، وبطاقةَ حالٍ فيها رابطُه
 * وشاراتُه، وأبوابًا مرسومة. وهو كان يُساق إلى بطاقةٍ داخل «الإعدادات» فيها
 * ثلاثون مقبضًا بلا شريطٍ ولا أبواب.
 *
 * ومن تعلّم إحدى اللوحتين لا يعرف أين يبحث في الأخرى — والشكلُ هنا ليس
 * زينةً بل خريطة.
 *
 * ═══ وما يُحرَس ═══
 *
 * أنّ الشاشات الأربع **ترتدي الترويسة نفسَها** من ملفٍّ واحد، وأنّ كلَّ
 * تبويبٍ يقود إلى شاشةٍ تُفتح، وأنّ مقبضًا لا يسكن شاشتين.
 */
class AThemedShopWearsTheSameWebsiteShellTest extends TestCase
{
    use RefreshDatabase;

    /**
     * الشاشاتُ الستّ: الملفّ ← [التبويبُ الذي تُضيئه، المسارُ الذي يفتحها].
     *
     * و«التصميم» تبويبٌ يُضيئه المحرّر ولا يفتحه: هو يُحوّل إليه كما يُحوّل
     * عند جاره إلى لوحة تصميمه (انظر `DesignController::index`).
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const SCREENS = [
        'ThemeSite.tsx' => ['admin.website.site', 'admin.website.site'],
        'ThemeEditor.tsx' => ['admin.website.design', 'admin.website.editor'],
        'ThemePages.tsx' => ['admin.website.pages', 'admin.website.pages'],
        'ThemeShop.tsx' => ['admin.website.shop', 'admin.website.shop'],
        'ThemeDomain.tsx' => ['admin.website.domain', 'admin.website.domain'],
        'ThemeSeo.tsx' => ['admin.website.seo', 'admin.website.seo'],
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

    /* ═══════════ الترويسةُ واحدة ═══════════ */

    /**
     * والشاشاتُ الأربع تقرأ ترويستَها من ملفٍّ واحد.
     *
     * ولو كُتبت في كلٍّ منها لاختلفت: زرٌّ يُسمّى «معاينة» هنا و«عرض المتجر»
     * هناك، وشارةٌ تقول «منشور» في شاشةٍ و«يعمل» في أختها عن المتجر نفسِه —
     * وهو ما وقع في شاشات البانِي قبل أن تُجمع في `shell`.
     */
    public function test_the_four_screens_wear_one_header(): void
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

        $this->assertCount(6, $seen);
    }

    /**
     * وكلُّ تبويبٍ في الشريط يقود إلى شاشةٍ تُفتح.
     *
     * وتبويبٌ يُعرض ثمّ يردّ من ضغطه أسوأُ من تبويبٍ لا يُعرض: الأوّل
     * يُعلّمه أنّ الشاشةَ تكذب، والثاني لا يَعِد بشيء.
     */
    public function test_every_tab_in_the_strip_opens(): void
    {
        $strip = (string) file_get_contents(resource_path('js/Components/SectionTabs.tsx'));

        /*
         * والشريطُ واحدٌ لا شريطان: `THEME_TABS` صار اسمًا آخر لـ`WEBSITE_TABS`
         * بعد أن صار لصاحب الواجهة ما لجاره. فيُقرأ من موضع القيم.
         */
        $this->assertStringContainsString(
            'export const THEME_TABS = WEBSITE_TABS;',
            $strip,
            'شريطُ الواجهة الخاصّة انفصل عن شريط جاره — وقائمتان تفترقان',
        );

        $from = strpos($strip, 'export const WEBSITE_TABS');
        $this->assertNotFalse($from, 'لا شريطَ تبويباتٍ لقسم الموقع');

        preg_match_all(
            "/routeName: '([^']+)'/",
            substr($strip, $from, strpos($strip, '];', $from) - $from),
            $matches,
        );

        $this->assertCount(6, $matches[1]);

        foreach ($matches[1] as $name) {
            $this->assertNotNull(Route::getRoutes()->getByName($name), $name.' تبويبٌ إلى مسارٍ لا وجود له');

            // ويُتبَع التحويل: «التصميم» يصل إلى المحرّر لا إلى صفحةٍ له
            $this->actingAs($this->owner)->followingRedirects()->get(route($name))
                ->assertOk($name.' تبويبٌ يُعرض ثمّ يردّ من ضغطه');
        }
    }

    /**
     * ═══ وكلُّ خاصيّةٍ تقرؤها الشاشةُ يُرسلها الخادم ═══
     *
     * وهذا الحارسُ وُضع بعد شاشةٍ بيضاء.
     *
     * «صفحة المتجر» كانت ترسل `published` و`url` بيدها. ثمّ ارتدت الترويسةَ
     * المشتركة وصارت تقرأ `site` — ولم يُرسَل. فصار `site` غيرَ معرَّف،
     * و`site.published` تُسقط الشاشةَ كلَّها عند أوّل تصيير: صفحةٌ بيضاء بلا
     * رسالةٍ ولا سطرٍ في سجلّ الخادم — لأنّ العطب في المتصفّح لا فيه.
     *
     * ولم يكشفه حارسٌ قائم: فحصُ Inertia يسأل «أيُّ شاشةٍ تُعرض؟» ولا يُصيّر
     * منها شيئًا، وحارسُ الواجهة يُمرّر الخصائصَ بيده فيكتب ما نسيه الخادم.
     * فالسؤالُ يُطرح هنا: ما تطلبه الشاشةُ في `Props` — أيصلها؟
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
            $props = $this->actingAs($this->owner)->get(route($open))->assertOk()
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

    /* ═══════════ والمقبضُ في شاشةٍ واحدة ═══════════ */

    /**
     * لا مفتاحَ يُحرَّر في شاشتين من شاشات القسم.
     *
     * وهو الحارسُ الذي يمنع العطبَ الصامت: نموذجُ Inertia يلتقط قيمَه حين
     * تُفتح الشاشة، فلسانان مفتوحان على شاشتين يحملان المفتاحَ نفسَه —
     * والحفظُ من الثاني يكتب فوق الأوّل بلا خطأٍ ولا رسالة.
     */
    public function test_no_key_is_edited_in_two_screens(): void
    {
        $where = [];

        // ما يُحرَّر في محرّر الصفحة — من مصدره لا من قراءة نصّه
        foreach (PageEditor::FIELDS as $fields) {
            foreach ($fields as $field) {
                $where[$field['key']] = 'ThemeEditor.tsx';
            }
        }
        $where['store_sections'] = 'ThemeEditor.tsx';

        foreach (['ThemeShop.tsx', 'ThemeDomain.tsx', 'ThemePages.tsx', 'ThemeSeo.tsx'] as $file) {
            preg_match_all("/setData\('((?:store|site)_[a-z_]+)'/", $this->screen($file), $m);

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
     * أسوأُ من شاشةٍ كاملةٍ في الموضع الخطأ: يُبدَّل المقبضُ هناك فيكتب
     * الحفظُ من هنا فوقه.
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
     * ═══ وكلُّ مقبضٍ في الشاشة يصل مخزنَه فعلًا ═══
     *
     * وهذا أقسى الحرّاس وأرخصُها: الشاشةُ ترسم المقبض، والنموذجُ يرسله،
     * والخادمُ يُصادق حمولتَه بقائمةٍ مكتوبةٍ بيده — فمفتاحٌ خارجَ القائمة
     * يُسقطه `validated()` بهدوء، ويُردّ «حُفظ متجرك الإلكتروني» ولا يُحفظ
     * شيء. فيُقلَّب المقبضُ ويُحفظ ويُعاد فتحُ الشاشة على ما كان.
     *
     * ولا يكفي أن يكون المفتاحُ في `MarketingSettings::GROUPS`: تلك تقول
     * «يصحّ تخزينه»، وهذه تسأل «أيكتبه البابُ الذي تطرقه الشاشة؟». وبينهما
     * وقع العطب — `store_allow_orders` معرَّفٌ في المجموعة منذ نسخ، ولا
     * تكتبه `MarketingController::saveStore`.
     */
    public function test_every_knob_the_screens_draw_really_reaches_its_store(): void
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
            // وما أضافته «الصفحات» و«الظهور في البحث»
            'store_about_image' => '/storage/store/about.jpg',
            'store_pages' => 'contact',
            'store_seo_title' => 'ريبون لاونج — ورد وهدايا بمسقط',
            'store_seo_desc' => 'باقاتُ وردٍ تُوصَّل في مسقط خلال اليوم نفسه.',
            'store_seo_index' => '0',
        ];

        /*
         * والمفاتيحُ تُقرأ من الشاشة نفسِها — فمقبضٌ يُضاف غدًا يدخل الحارس
         * بلا سطرٍ يُكتب له.
         *
         * و`site_slug` ليس منها: مخزنُه عمودٌ في `businesses` لا صفٌّ في
         * `settings`، وله حرّاسه (تفرّدُه، ورفضُ النشر بلا عنوان).
         */
        $drawn = [];
        foreach (['ThemeShop.tsx', 'ThemeDomain.tsx', 'ThemePages.tsx', 'ThemeSeo.tsx'] as $file) {
            preg_match_all("/setData\('(store_[a-z_]+)'/", $this->screen($file), $m);
            $drawn = array_merge($drawn, $m[1]);
        }

        /*
         * و«الصفحات» تكتب مفتاحَها بـ`router.post` لا بنموذج — فلا تلتقطه
         * `setData`. ويُضمّ بيده، وإلّا بقي أثقلُ مقبضٍ في الشاشة بلا حارس:
         * إطفاءُ صفحةٍ على زبائن متجرٍ مفتوح.
         */
        $drawn[] = 'store_pages';
        $drawn = array_values(array_unique($drawn));

        $this->assertNotSame([], $drawn, 'لم يُقرأ من الشاشات مقبضٌ واحد — الحارسُ يحرس لا شيء');

        foreach ($drawn as $key) {
            $this->assertArrayHasKey($key, $sample, $key.' مقبضٌ جديد بلا قيمةِ فحصٍ في هذا الحارس');

            $this->actingAs($this->owner)
                ->from(route('admin.website.shop'))
                ->post(route('admin.marketing.store.save'), [$key => $sample[$key]])
                ->assertRedirect();

            $this->assertSame(
                $sample[$key],
                (string) (MarketingSettings::group($this->shop->id, 'website')[$key] ?? ''),
                $key.' يُرسَل من الشاشة ولا يكتبه الخادم — مقبضٌ لا يُدير شيئًا',
            );
        }
    }

    /**
     * وكلُّ بابٍ في شاشة «عام» يُفتح لمن يراه.
     *
     * والأبوابُ ليست تبويبات: `SmartLink` لا تُخفي شيئًا بالصلاحيات، فبابٌ
     * يقود إلى قسمٍ لا يملكه من يراه يُصفع بـ٤٠٣ عند الضغط — وقد فُتحت له
     * الشاشةُ التي فيها البابُ بحقّ.
     */
    public function test_every_door_on_the_general_screen_opens(): void
    {
        preg_match_all("/route: '([a-z.]+)'/", $this->screen('ThemeSite.tsx'), $m);

        $this->assertNotSame([], $m[1], 'لا أبوابَ في شاشة «عام»');

        foreach ($m[1] as $name) {
            $this->assertNotNull(Route::getRoutes()->getByName($name), $name.' بابٌ إلى مسارٍ لا وجود له');

            // ويُتبَع التحويل: «التصميم» بابٌ يصل إلى المحرّر لا إلى شاشةٍ له
            $this->actingAs($this->owner)->followingRedirects()->get(route($name))
                ->assertOk($name.' بابٌ يُرسم ثمّ يُصفع من يضغطه');
        }
    }

    /* ═══════════ وما تقوله بطاقةُ الحال يُقاس ═══════════ */

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
     * وعددُ طرق الدفع يُقرأ من قارئه لا من مفتاحين.
     *
     * بوّابةُ البطاقة طريقةٌ ثالثةٌ بلا مفتاحٍ في مجموعة `website` — فعدٌّ
     * يقرأ المفاتيح يقول «اثنتان» ومتجرُه يقبض بثلاث.
     */
    public function test_the_payment_count_reads_what_the_store_really_takes(): void
    {
        MarketingSettings::save($this->shop->id, 'website', ['store_pay_cod' => '1', 'store_pay_transfer' => '1']);

        $props = $this->actingAs($this->owner)
            ->get(route('admin.website.site'))->viewData('page')['props'];

        $this->assertSame(2, $props['counts']['payments']);

        MarketingSettings::save($this->shop->id, 'website', ['store_pay_cod' => '0', 'store_pay_transfer' => '0']);

        $props = $this->actingAs($this->owner)
            ->get(route('admin.website.site'))->viewData('page')['props'];

        $this->assertSame(0, $props['counts']['payments'], 'متجرٌ بلا طريقة دفعٍ يُقال عنه ذلك');
    }

    /** وحقولُ إتمام الطلب تصل محسوبةً لا فارغة — فالفراغُ يُقرأ «ما كان» */
    public function test_the_checkout_fields_arrive_as_the_store_works_today(): void
    {
        $props = $this->actingAs($this->owner)
            ->get(route('admin.website.shop'))->assertOk()
            ->viewData('page')['props'];

        $this->assertNotSame([], $props['fieldStates']);
        $this->assertNotSame([], $props['fulfilments']);
    }

    /** ولا يخرج سرُّ بوّابةٍ إلى المتصفّح — خصائصُ Inertia تُقرأ في مصدر الصفحة */
    public function test_no_gateway_secret_reaches_the_browser(): void
    {
        \App\Models\PaymentGateway::create([
            'business_id' => $this->shop->id,
            'provider' => \App\Models\PaymentGateway::PAYMOB,
            'public_key' => 'pk_live_x',
            'secret_key' => 'sk_live_secret',
            'hmac_secret' => 'hmac_secret',
            'card_integration_id' => '12345',
            'active' => true,
        ]);

        $response = $this->actingAs($this->owner)->get(route('admin.website.shop'))->assertOk();

        $response->assertDontSee('sk_live_secret');
        $response->assertDontSee('hmac_secret');

        $props = $response->viewData('page')['props'];
        $this->assertTrue($props['gateway']['has_secret']);
        $this->assertTrue($props['gateway']['has_hmac']);
    }
}
