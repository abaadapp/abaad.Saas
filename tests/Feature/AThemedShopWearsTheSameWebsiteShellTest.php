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

    /** الشاشاتُ الأربع: الملفّ ← اسمُ تبويبه */
    private const SCREENS = [
        'ThemeSite.tsx' => 'admin.website.site',
        'ThemeEditor.tsx' => 'admin.website.editor',
        'ThemeShop.tsx' => 'admin.website.shop',
        'ThemeDomain.tsx' => 'admin.website.domain',
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
        foreach (self::SCREENS as $file => $tab) {
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

        foreach (self::SCREENS as $file => $tab) {
            $this->assertArrayNotHasKey($tab, $seen, $tab.' يُضيء من شاشتين: '.($seen[$tab] ?? '').' و'.$file);
            $seen[$tab] = $file;
        }

        $this->assertCount(4, $seen);
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

        $from = strpos($strip, 'export const THEME_TABS');
        $this->assertNotFalse($from, 'لا شريطَ تبويباتٍ للواجهة الخاصّة');

        preg_match_all(
            "/routeName: '([^']+)'/",
            substr($strip, $from, strpos($strip, '];', $from) - $from),
            $matches,
        );

        $this->assertCount(4, $matches[1]);

        foreach ($matches[1] as $name) {
            $this->assertNotNull(Route::getRoutes()->getByName($name), $name.' تبويبٌ إلى مسارٍ لا وجود له');

            $this->actingAs($this->owner)->get(route($name))
                ->assertOk($name.' تبويبٌ يُعرض ثمّ يردّ من ضغطه');
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

        foreach (['ThemeShop.tsx', 'ThemeDomain.tsx'] as $file) {
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
