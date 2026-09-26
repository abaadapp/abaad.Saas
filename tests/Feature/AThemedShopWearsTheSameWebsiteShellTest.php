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
use PHPUnit\Framework\Attributes\DataProvider;
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
     * الشاشاتُ الستّ: الملفّ ← [التبويبُ الذي تُضيئه، المسارُ الذي يفتحها].
     *
     * و«التصميم» يفتح المحرّر: مسارُ التبويب `design` ويردُّ تحويلًا إليه
     * (انظر `DesignController::index`). فالتبويبُ الذي يُضيئه المحرّرُ هو
     * `design` لا `editor` — ولو أضاء اسمَ مساره لَما أضاء شيئًا.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const SCREENS = [
        'ThemeSettings.tsx' => ['admin.website.site', 'admin.website.site'],
        'ThemeEditor.tsx' => ['admin.website.design', 'admin.website.design'],
        'ThemePages.tsx' => ['admin.website.pages', 'admin.website.pages'],
        'ThemeStore.tsx' => ['admin.website.shop', 'admin.website.shop'],
        'ThemeDomain.tsx' => ['admin.website.domain', 'admin.website.domain'],
        'ThemeSeo.tsx' => ['admin.website.seo', 'admin.website.seo'],
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

        $this->assertCount(6, $seen);
    }

    /**
     * وشريطُ الواجهة الخاصّة ستّةٌ — هو شريطُ جاره بمساراته نفسِها.
     *
     * والترتيبُ يُحرَس لا العددُ وحدَه: «عام» أوّلًا لأنّها الحال، ثمّ
     * «التصميم» لأنّه أكثرُ ما يُفتح، ثمّ الباقي. وشريطٌ يُعاد ترتيبُه
     * بلا قصدٍ يُضيّع من تعوّد موضعَ تبويبه.
     */
    public function test_the_strip_is_six_tabs_and_all_open(): void
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
            [
                'admin.website.site', 'admin.website.design', 'admin.website.pages',
                'admin.website.shop', 'admin.website.domain', 'admin.website.seo',
            ],
            $matches[1],
            'شريطُ الواجهة الخاصّة ستّةٌ بترتيب شريط جاره',
        );

        foreach ($matches[1] as $name) {
            $this->assertNotNull(Route::getRoutes()->getByName($name), $name.' تبويبٌ إلى مسارٍ لا وجود له');

            $this->actingAs($this->owner)->followingRedirects()->get(route($name))
                ->assertOk($name.' تبويبٌ يُعرض ثمّ يردّ من ضغطه');
        }
    }

    /* ═══════════ وما كان شاشةً صار قسمًا ═══════════ */

    /**
     * وكلُّ تبويبٍ يفتح شاشتَه هو — لا شاشةَ جارٍ ولا تحويلًا إلى مرساة.
     *
     * وكانت الأربعةُ تُحوَّل إلى `site#anchor` يومَ كان الضبطُ صفحةً واحدة.
     * فمن حفظ إشارةً مرجعيّةً إلى «الدومين» كان يقف على أوّل صفحةٍ طويلة.
     * والآن لكلٍّ شاشتُه — ويُحرَس اسمُ المكوّن لا رمزُ الجواب وحدَه: مسارٌ
     * يردّ ٢٠٠ بشاشةِ غيره يمرّ من فحصٍ يسأل عن الرقم.
     */
    public function test_every_tab_opens_its_own_screen(): void
    {
        foreach (self::SCREENS as $file => [, $open]) {
            $page = $this->actingAs($this->owner)->followingRedirects()
                ->get(route($open))->assertOk()->viewData('page');

            $this->assertSame(
                'Admin/Website/'.str_replace('.tsx', '', $file),
                $page['component'],
                $open.' يفتح شاشةً غيرَ شاشته',
            );
        }
    }

    /**
     * ═══ وحفظُ شاشةٍ لا يمحو ما ضبطته أختُها ═══
     *
     * وهذا أثقلُ حارسٍ في تفكيك الصفحة الواحدة إلى ستّ.
     *
     * الشاشاتُ الستُّ تكتب في البابِ نفسِه (`marketing.store.save`). فلو
     * أرسلت كلُّ واحدةٍ نموذجَها كاملًا لَكتبت فوق ما ضبطته أختُها بقيمٍ
     * التقطتها يومَ فُتحت: يضبط التاجرُ رسمَ التوصيل في «المتجر»، ثمّ يفتح
     * «الظهور في البحث» في لسانٍ كان مفتوحًا قبله ويحفظ — فيعود الرسمُ إلى
     * ما كان، بلا خطأٍ ولا رسالة.
     *
     * فكلُّ شاشةٍ تُرسل مفاتيحَها وحدَها (`SCREEN_KEYS`)، والخادمُ مبنيٌّ
     * على ذلك: `$request->exists()` و`array_key_exists` في كلّ مفتاحٍ
     * حسّاس، و`validated()` تُسقط الغائبَ ولا تكتبه.
     *
     * ويُسأل هنا بالفعل لا بقراءة نصّ: يُضبط مفتاحٌ من كلّ شاشة، ثمّ تُحفظ
     * كلُّ شاشةٍ بحمولتها وحدَها، ثمّ يُقرأ الباقي.
     */
    public function test_saving_one_screen_keeps_what_the_others_set(): void
    {
        MarketingSettings::save($this->shop->id, 'website', [
            'store_delivery_fee' => '2.500',
            'store_seo_title' => 'ورودُ مسقط',
            'store_pages' => 'about',
            'store_on' => '1',
        ]);

        /** حمولةُ كلّ شاشةٍ — مفاتيحُها وحدَها، كما ترسلها `SCREEN_KEYS` */
        $payloads = [
            'الدومين' => ['site_slug' => 'ribbon', 'store_on' => '1'],
            'الصفحات' => ['store_pages' => 'about', 'store_about_image' => ''],
            'المتجر' => ['store_delivery_fee' => '2.500', 'store_pay_cod' => '1', 'store_fulfil' => 'pickup'],
            'السيو' => ['store_seo_title' => 'ورودُ مسقط', 'store_seo_desc' => '', 'store_seo_index' => '1'],
        ];

        foreach ($payloads as $screen => $body) {
            $this->actingAs($this->owner)
                ->post(route('admin.marketing.store.save'), $body)
                ->assertSessionHasNoErrors();

            $now = MarketingSettings::group($this->shop->id, 'website');

            $this->assertSame('2.500', $now['store_delivery_fee'] ?? null, 'حفظُ «'.$screen.'» محا رسمَ التوصيل');
            $this->assertSame('ورودُ مسقط', $now['store_seo_title'] ?? null, 'حفظُ «'.$screen.'» محا عنوانَ غوغل');
            $this->assertSame('about', $now['store_pages'] ?? null, 'حفظُ «'.$screen.'» محا إذنَ الصفحات');
            $this->assertSame('1', $now['store_on'] ?? null, 'حفظُ «'.$screen.'» أطفأ النشر');
            $this->assertSame('ribbon', $this->shop->fresh()->site_slug, 'حفظُ «'.$screen.'» محا العنوان');
        }
    }

    /**
     * ومتجرٌ منشورٌ لا يُفرَّغ عنوانه — ولو لم تحمل الحمولةُ مفتاحَ نشره.
     *
     * ═══ والحفظُ الجزئيُّ هو ما فتح هذا الباب ═══
     *
     * حارسُ الخادم يسأل: «أمنشورٌ هو وبلا عنوان؟» ثمّ يردّ. ولو قرأ النشرَ
     * من الحمولة وحدَها لَقرأه «مطفأً» في كلّ حفظٍ لا يحمله — وحفظُ خمسٍ من
     * الشاشات الستّ لا يحمله. فيمرّ تفريغُ العنوان بلا حارس، ويصير المتجرُ
     * «منشورًا» في شاشته و404 في كلّ رابطٍ وُزّع على زبائنه.
     *
     * ولا تصنع الشاشاتُ هذه الحمولةَ اليوم: «الدومين» ترسل الاثنين معًا.
     * لكنّ البابَ مفتوحٌ لمن أرسل بيده — ولحفظٍ جزئيٍّ يُكتب غدًا.
     */
    public function test_a_published_shop_cannot_be_stripped_of_its_address(): void
    {
        MarketingSettings::save($this->shop->id, 'website', ['store_on' => '1']);

        $this->actingAs($this->owner)
            ->from(route('admin.website.domain'))
            ->post(route('admin.marketing.store.save'), ['site_slug' => ''])
            ->assertSessionHasErrors('site_slug');

        $this->assertSame('ribbon', $this->shop->fresh()->site_slug, 'فُرّغ عنوانُ متجرٍ منشور');
    }

    /**
     * وكلُّ شاشةٍ تنادي قسمتَها فعلًا — لا تُكتفى القائمةُ بأن تُكتب.
     *
     * الحارسان أعلاه وأسفله يُثبتان أنّ الخادمَ يتحمّل الحفظَ الجزئيّ وأنّ
     * القسمةَ تامّة. ولا يُثبت أيٌّ منهما أنّ الشاشةَ **تستعملها**: شاشةٌ
     * ترسل نموذجَها كاملًا تمرّ من كليهما خضراء، وتمحو ما ضبطته أختُها.
     *
     * فيُقرأ المصدر: أتنادي كلُّ شاشةٍ `only` بمفتاحها هي؟
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function savingScreens(): array
    {
        return [
            'الدومين' => ['ThemeDomain.tsx', 'domain'],
            'الصفحات' => ['ThemePages.tsx', 'pages'],
            'المتجر والطلبات' => ['ThemeStore.tsx', 'store'],
            'الظهور في البحث' => ['ThemeSeo.tsx', 'seo'],
        ];
    }

    #[DataProvider('savingScreens')]
    public function test_each_screen_sends_only_its_own_slice(string $file, string $key): void
    {
        $code = $this->screen($file);

        $this->assertStringContainsString(
            'only(data, SCREEN_KEYS.'.$key.')',
            $code,
            $file.' تحفظ بلا قسمة — فتكتب فوق ما ضبطته أخواتها',
        );

        $this->assertStringContainsString(
            "route('admin.marketing.store.save')",
            $code,
            $file.' تحفظ في بابٍ آخر',
        );
    }

    /**
     * وكلُّ شاشةٍ ترسل مفاتيحَها هي — لا مفتاحَ جارتها.
     *
     * والحارسُ فوقه يُثبت أنّ الحفظَ الجزئيَّ لا يمحو. وهذا يُثبت أنّ
     * القسمةَ **تامّة**: لا مفتاحَ في نموذج الواجهة بلا شاشةٍ ترسله، ولا
     * مفتاحَ ترسله شاشتان. فمفتاحٌ يسقط من القائمتين يُرسم ويُقلَّب ولا
     * يُحفظ أبدًا — ولا يُكتشف إلّا من تاجرٍ يشتكي.
     */
    public function test_the_split_covers_every_key_exactly_once(): void
    {
        $source = (string) file_get_contents(
            resource_path('js/Pages/Admin/Website/theme/sections/form.ts'),
        );

        // مفاتيحُ النموذج كما أُعلنت في `ThemeSettingsData`
        $block = substr($source, $from = strpos($source, 'export interface ThemeSettingsData {'));
        $block = substr($block, 0, strpos($block, "\n}\n"));
        preg_match_all('/^    ([a-z_]+):/m', $block, $declared);

        // وما توزّعه `SCREEN_KEYS` على الشاشات
        $map = substr($source, $at = strpos($source, 'export const SCREEN_KEYS'));
        $map = substr($map, 0, strpos($map, '} as const'));
        preg_match_all("/'([a-z_]+)'/", $map, $split);

        $this->assertNotSame([], $declared[1], 'لم تُقرأ مفاتيحُ النموذج — الحارسُ يحرس لا شيء');

        sort($declared[1]);
        $spread = $split[1];
        sort($spread);

        $this->assertSame(
            $declared[1],
            array_values(array_unique($spread)),
            'مفتاحٌ في النموذج بلا شاشةٍ ترسله — أو العكس',
        );

        $this->assertSame(
            count($spread),
            count(array_unique($spread)),
            'مفتاحٌ ترسله شاشتان — فتكتب إحداهما فوق الأخرى',
        );
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

            $at = strpos($code, 'interface Props extends ThemeShell');
            $block = substr($code, (int) strpos($code, '{', (int) $at));
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
            ->get(route('admin.website.shop'))->assertOk()
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
            ->get(route('admin.website.pages'))->assertOk()
            ->viewData('page')['props'];

        $this->assertSame('about,contact', $props['pages']['allowed'], 'متجرٌ جديد: صفحتاه مفتوحتان');

        MarketingSettings::save($this->shop->id, 'website', ['store_pages' => 'none']);

        $props = $this->actingAs($this->owner)
            ->get(route('admin.website.pages'))->viewData('page')['props'];

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

        $response = $this->actingAs($this->owner)->get(route('admin.website.shop'))->assertOk();

        $response->assertDontSee('sk_live_secret');
        $response->assertDontSee('hmac_secret');

        $props = $response->viewData('page')['props'];
        $this->assertTrue($props['gateway']['has_secret']);
        $this->assertTrue($props['gateway']['has_hmac']);
    }

    /**
     * وكلُّ شاشةٍ في الشريط لها ملفُّها — ولا ملفَّ يتيمٌ بجانبها.
     *
     * فملفٌّ باقٍ لا يفتحه تبويبٌ يُعدَّل يومًا ويُظنّ أنّه ما يراه التاجر،
     * وهو لا يُعرض على أحد.
     */
    public function test_every_screen_has_its_file_and_no_stray_copy(): void
    {
        foreach (array_keys(self::SCREENS) as $file) {
            $this->assertFileExists(
                resource_path('js/Pages/Admin/Website/'.$file),
                $file.' تبويبٌ بلا شاشة',
            );
        }

        // وأسماءٌ جُرّبت ثمّ تُركت — لا يبقى منها ملفّ
        foreach (['ThemeSite', 'ThemeShop'] as $gone) {
            $this->assertFileDoesNotExist(
                resource_path('js/Pages/Admin/Website/'.$gone.'.tsx'),
                $gone.' ملفٌّ لا يفتحه تبويب',
            );
        }
    }
}
