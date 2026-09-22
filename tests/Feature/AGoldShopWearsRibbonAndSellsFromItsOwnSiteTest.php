<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Coupon;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Season;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Ledger;
use App\Support\MarketingSettings;
use App\Support\SalesChannel;
use App\Support\SeasonSales;
use App\Support\Storefront;
use App\Support\Website\Commerce;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * المتجرُ الذهبيّ يلبس واجهةَ RIBBON ويبيع من موقعه — طلبًا حقيقيًّا في أبعاد.
 *
 * والحارسُ الأهمّ: سلّةٌ واحدة تُباع من الموقع ومن الصندوق تكتب في الدفتر
 * الشيءَ نفسَه حرفًا — لا قاعدةَ ثانية للسعر أو الضريبة أو القيد.
 */
class AGoldShopWearsRibbonAndSellsFromItsOwnSiteTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    private Business $plain;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2027-02-01 10:00:00');

        $this->business = Business::create(['name' => 'RIBBON', 'type' => 'محل ورد', 'status' => 'نشط', 'phone' => '96895259066', 'city' => 'مسقط', 'address' => 'الخوير', 'site_slug' => 'ribbon', 'tier' => 'gold', 'storefront_theme' => 'ribbon']);
        Currency::create(['business_id' => $this->business->id, 'code' => 'OMR', 'name' => 'ريال عماني', 'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);
        Ledger::seedChart($this->business->id);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الخوير']);
        Setting::create(['business_id' => $this->business->id, 'key' => 'vat_enabled', 'value' => '0']);
        $this->owner = User::create(['business_id' => $this->business->id, 'name' => 'سعود', 'email' => 'ribbon@abaad.om', 'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط']);
        MarketingSettings::save($this->business->id, 'website', [
            'store_on' => '1', 'store_pay_cod' => '1', 'store_pay_transfer' => '1', 'store_bank' => 'بنك مسقط — 0123456789',
            'store_delivery_fee' => '3', 'store_free_delivery_over' => '50', 'store_delivery_areas' => 'الخوير, القرم', 'store_delivery_slots' => "9 ص – 12 م\n4 م – 8 م",
        ]);

        $this->plain = Business::create(['name' => 'عادي', 'type' => 'عام', 'status' => 'نشط', 'site_slug' => 'plain']);
        Currency::create(['business_id' => $this->plain->id, 'code' => 'OMR', 'name' => 'ريال عماني', 'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);
        User::create(['business_id' => $this->plain->id, 'name' => 'جار', 'email' => 'plain@abaad.om', 'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط']);
        MarketingSettings::save($this->plain->id, 'website', ['store_on' => '1']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function product(array $attrs = []): Product
    {
        return Product::create($attrs + [
            'business_id' => $this->business->id, 'name' => 'باقة ورد', 'name_en' => 'Rose bouquet', 'price' => 20, 'cost' => 8,
            'quantity' => 10, 'alert_qty' => 1, 'active' => true, 'published' => true,
        ]);
    }

    private function order(array $over = []): array
    {
        return $over + [
            'items' => [], 'fulfil' => 'delivery', 'pay' => 'cod', 'name' => 'مريم', 'phone' => '96899110001',
            'area' => 'الخوير', 'address' => 'شارع ١٨، منزل ٤', 'date' => '2027-02-03', 'slot' => '9 ص – 12 م', 'card' => 'كل عام وأنتِ بخير',
        ];
    }

    private function place(array $over = [])
    {
        return $this->postJson('/s/ribbon/checkout', $this->order($over));
    }

    /* ═══════════ الخطّ — خطُّ التصميم من ملفّات المتجر ═══════════ */

    /**
     * خطُّ الواجهة `IBM Plex Sans Arabic`، ويُخدم من عندنا لا من شبكةٍ خارجيّة.
     *
     * والشرطان معًا لا أحدُهما: خطٌّ صحيحٌ من نطاقٍ أجنبيّ يجعل صفحةَ التاجر
     * تنتظر خادمَ غيرِنا لتُقرأ، ويُسرّب زائرَه إليه. وملفٌّ عندنا بخطٍّ آخر
     * يكسر التصميم الذي كُتبت الواجهةُ له.
     *
     * والملفّاتُ تُفحص وجودًا: رابطٌ إلى ملفٍّ غير موجود يسقط الصفحةَ كلَّها
     * إلى خطّ النظام بلا أن يُخطئ شيء.
     *
     * ═══ وأوّلُ السلسلة قد لا يكون هو العامل ═══
     *
     * دليلُ هوية Ribbon يسمّي `GE Hili` خطًّا عربيًّا، وملفّاتُه ليست معنا
     * ورخصتُه لا تُجيز استخراجَها من الدليل. فهو أوّلُ السلسلة اسمًا لِما
     * يأتي، والعاملُ اليومَ ما بعده. فيُفحص أنّ الخطّ المخزَّن عندنا **في**
     * السلسلة لا أنّه أوّلُها — وإلّا سقط الحارسُ يومَ تُشترى ملفّاتُ الخطّ
     * وتُضاف، وهو اليومُ الذي يجب أن يمرّ فيه بلا تعديل.
     */
    public function test_the_theme_serves_the_design_font_from_its_own_files(): void
    {
        // والتعليقاتُ تُنزع أوّلًا: شرحٌ يذكر اسمَ الملفّ ليس رابطًا إليه
        $layout = preg_replace(
            '/\\{\\{--.*?--\\}\\}|\\/\\*.*?\\*\\//s',
            '',
            (string) file_get_contents(resource_path('views/store/ribbon/layout.blade.php')),
        );

        // على الجذر: فلا يبقى في الصفحة موضعٌ يسقط إلى خطّ النظام
        $this->assertMatchesRegularExpression(
            "/html\\s*\\{[^}]*font-family:[^;]*'IBM Plex Sans Arabic'/",
            (string) $layout,
            'خطُّ التصميم غير مضبوطٍ على جذر المستند',
        );
        $this->assertMatchesRegularExpression(
            "/body\\s*\\{[^}]*font-family:[^;]*'IBM Plex Sans Arabic'/",
            (string) $layout,
            'خطُّ التصميم غير مضبوطٍ على متن الصفحة',
        );

        // ومن ملفّاتنا: رابطٌ صريحٌ إلى ملفّ الخطّ عندنا
        $this->assertMatchesRegularExpression(
            '#<link[^>]+href="/fonts/ibm-plex-arabic\\.css"#',
            (string) $layout,
            'ملفُّ الخطّ غير مربوطٍ في الترويسة',
        );

        // ولا خطَّ من شبكةٍ خارجيّة — لا Google ولا سواها
        $this->assertDoesNotMatchRegularExpression('#<link[^>]+href="https?://#i', (string) $layout, 'خطٌّ من نطاقٍ أجنبيّ');

        $css = public_path('fonts/ibm-plex-arabic.css');
        $this->assertFileExists($css);

        preg_match_all('#url\(/fonts/([^)]+)\)#', (string) file_get_contents($css), $m);
        $this->assertNotEmpty($m[1], 'ملفُّ الخطّ لا يشير إلى ملفّاتٍ عندنا');

        foreach (array_unique($m[1]) as $file) {
            $this->assertFileExists(public_path('fonts/'.$file));
        }

        // والأوزانُ التي ترسمها الواجهة — ٤٠٠ و٥٠٠ و٦٠٠ — معرَّفةٌ كلُّها
        foreach ([400, 500, 600] as $weight) {
            $this->assertMatchesRegularExpression(
                "/font-weight:\s*$weight\b/",
                (string) file_get_contents($css),
                "وزنُ $weight غير معرَّفٍ في ملفّ الخطّ",
            );
        }
    }

    /* ═══════════ الهوية — شعارُ الدليل وألوانُه ═══════════ */

    public static function brandAssets(): array
    {
        return [['logo.svg'], ['logo-cream.svg'], ['logo.png'], ['favicon.svg'], ['apple-touch-icon.png']];
    }

    /**
     * ملفّاتُ الهوية موجودةٌ فعلًا — ورابطٌ إلى مفقودٍ شعارٌ مكسورٌ في ترويسة المتجر.
     */
    #[DataProvider('brandAssets')]
    public function test_every_brand_asset_exists(string $file): void
    {
        $this->assertFileExists(public_path('brand/ribbon/'.$file));
    }

    /**
     * والشعارُ رسمٌ متّجهٌ لا لقطةُ شاشة — يبقى حادًّا في كلّ مقاسٍ وعلى كلّ شاشة.
     *
     * وكان قبله ملفَّ PNG عرضُه ثابت، يُرى ناعمًا على الشاشات عالية الكثافة
     * ويثقل أوّلَ فتحةٍ للصفحة بمئة كيلوبايت.
     */
    public function test_the_logo_is_a_real_vector(): void
    {
        foreach (['logo.svg', 'logo-cream.svg', 'favicon.svg'] as $file) {
            $svg = (string) file_get_contents(public_path('brand/ribbon/'.$file));

            $this->assertStringContainsString('<path', $svg, "$file بلا مسارات");
            $this->assertStringNotContainsString('base64', $svg, "$file صورةٌ مضمَّنة لا رسم");
            $this->assertStringNotContainsString('<image', $svg, "$file صورةٌ مضمَّنة لا رسم");
        }
    }

    /**
     * والنسخةُ الكريمية كريميّةٌ فعلًا — فالترويسةُ والتذييلُ زيتونيّان داكنان.
     *
     * وشعارٌ أخضرُ على خلفيةٍ خضراء يختفي — وهو أوّلُ ما يقع يوم تُنسخ النسخةُ
     * الواحدة إلى الموضعين.
     */
    public function test_the_reversed_logo_carries_no_dark_ink(): void
    {
        $svg = (string) file_get_contents(public_path('brand/ribbon/logo-cream.svg'));

        $this->assertStringContainsString('#F7F2EC', $svg, 'النسخةُ الكريمية بلا اللون الكريميّ');
        $this->assertDoesNotMatchRegularExpression('/#58563[cC]/', $svg, 'فيها أخضرُ العلامة فتختفي على الترويسة');
    }

    /**
     * وواجهةُ المتجر تلبس الشعارَ المتّجه وألوانَ الدليل — لا صورةً ولا لونًا مقارَبًا.
     *
     * وقيمُ الدليل بالحرف: مقاربةٌ في رقمٍ واحد تُخرج لونًا ليس لون العلامة،
     * ولا تُكتشف بالعين.
     */
    public function test_the_storefront_wears_the_guide_colours_and_the_vector_logo(): void
    {
        $layout = (string) file_get_contents(resource_path('views/store/ribbon/layout.blade.php'));

        $this->assertStringContainsString('--rb-olive: #58563c', $layout, 'أخضرُ الدليل ليس لون الواجهة');
        $this->assertStringContainsString('--rb-cream: #f7f2ec', $layout, 'كريميُّ الدليل ليس لون الواجهة');

        // الشعارُ من ملفّ الهوية، ولا أثرَ للصورة التي كانت
        $this->assertStringContainsString('/brand/ribbon/logo-cream.svg', $layout);
        $this->assertStringNotContainsString('logo-wide.png', $layout);

        // وأيقونةُ المتصفّح من الهوية أيضًا
        $this->assertStringContainsString('/brand/ribbon/favicon.svg', $layout);
    }

    /* ═══════════ الحواف — مستديرةٌ بمقدارٍ واحد ═══════════ */

    /**
     * كلُّ حافةٍ في الواجهة تُقرأ من رمز — ولا رقمَ منثورٌ في موضع.
     *
     * كانت الأرقامُ مبعثرةً بين ٤ و٦ و٨ وصفرٍ في أزرارٍ بعينها، فيقف الزرُّ
     * المربّع إلى جانب الحقل المستدير في الشاشة نفسِها. والحارسُ يمنع عودةَ
     * الرقم لا يصف المقدار: من أراد استدارةً أخرى بدّل الرموزَ الثلاثة في
     * `layout` فتتبدّل الواجهةُ كلُّها.
     *
     * و`999px` و`50%` ليسا مقدارًا بل شكل — حبّةٌ ودائرة — فيبقيان.
     */
    public function test_every_corner_in_the_theme_reads_one_scale(): void
    {
        $files = glob(resource_path('views/store/ribbon/*.blade.php'));
        $this->assertNotEmpty($files);

        $tokens = (string) file_get_contents(resource_path('views/store/ribbon/layout.blade.php'));
        foreach (['--rb-r-sm:', '--rb-r:', '--rb-r-lg:'] as $token) {
            $this->assertStringContainsString($token, $tokens, "رمزُ الحافة $token غير معرَّف");
        }

        $guilty = [];

        foreach ($files as $file) {
            $source = preg_replace('/\{\{--.*?--\}\}|\/\*.*?\*\//s', '', (string) file_get_contents($file));

            preg_match_all('/border-radius:\s*([^;"\']+)/', (string) $source, $m);

            foreach ($m[1] as $value) {
                $value = trim($value);

                if (str_starts_with($value, 'var(--rb-r') || $value === '999px' || $value === '50%') {
                    continue;
                }

                $guilty[] = basename($file).': '.$value;
            }
        }

        $this->assertSame([], $guilty, "حافةٌ بمقدارٍ مكتوبٍ بيده:\n".implode("\n", $guilty));
    }

    /* ═══════════ الفئةُ والواجهة ═══════════ */

    public function test_the_gold_tier_is_read_and_sent_where_the_name_is_read(): void
    {
        $this->assertTrue($this->business->isGold());
        $this->assertFalse($this->plain->isGold());

        $props = $this->actingAs($this->owner)->get(route('admin.dashboard'))->viewData('page')['props'];
        $this->assertSame('gold', $props['context']['tier']);
    }

    public function test_the_platform_sets_tier_and_theme_from_a_closed_list(): void
    {
        $super = User::create(['name' => 'المنصّة', 'email' => 'super@abaad.om', 'password' => bcrypt('x'), 'role' => 'super_admin', 'status' => 'نشط']);

        $this->actingAs($super)->put(route('super-admin.businesses.update', $this->plain->id), [
            'name' => 'عادي', 'type' => 'عام', 'status' => 'نشط', 'tier' => 'gold', 'storefront_theme' => 'ribbon',
        ])->assertSessionHasNoErrors();
        $this->assertSame(['gold', 'ribbon'], [$this->plain->fresh()->tier, $this->plain->fresh()->storefront_theme]);

        $this->actingAs($super)->put(route('super-admin.businesses.update', $this->plain->id), [
            'name' => 'عادي', 'type' => 'عام', 'status' => 'نشط', 'tier' => 'platinum',
        ])->assertSessionHasErrors('tier');

        $this->actingAs($super)->put(route('super-admin.businesses.update', $this->plain->id), [
            'name' => 'عادي', 'type' => 'عام', 'status' => 'نشط', 'tier' => '', 'storefront_theme' => '',
        ])->assertSessionHasNoErrors();
        $this->assertSame([null, null], [$this->plain->fresh()->tier, $this->plain->fresh()->storefront_theme]);
    }

    public function test_the_theme_is_served_before_the_builder_and_the_simple_page(): void
    {
        $this->assertSame(Storefront::SERVES_THEME, Storefront::serves($this->business));
        $this->assertSame(Storefront::SERVES_SIMPLE, Storefront::serves($this->plain));
        $this->assertTrue(Commerce::checkout($this->business->id));
        $this->assertFalse(Commerce::checkout($this->plain->id));

        MarketingSettings::save($this->business->id, 'website', ['store_on' => '0']);
        $this->assertSame(Storefront::SERVES_NONE, Storefront::serves($this->business->fresh()), 'و«نشر المتجر» يُطاع: مطفأً لا يُخدم شيء');
        $this->get('/s/ribbon')->assertNotFound();
        MarketingSettings::save($this->business->id, 'website', ['store_on' => '1']);

        $this->business->update(['storefront_theme' => null]);
        $this->assertSame(Storefront::SERVES_SIMPLE, Storefront::serves($this->business->fresh()), 'حذفُ المفتاح يُعيده كسائر المتاجر');
    }

    /* ═══════════ الصفحات ═══════════ */

    public function test_every_ribbon_page_opens_in_both_languages(): void
    {
        $p = $this->product();
        Product::create(['business_id' => $this->business->id, 'name' => 'مخفيّ', 'price' => 5, 'cost' => 1, 'quantity' => 3, 'active' => true, 'published' => false]);

        foreach (['', '/shop', '/p/'.$p->id, '/cart', '/checkout'] as $path) {
            foreach (['ar', 'en'] as $lang) {
                $res = $this->get('/s/ribbon'.$path.'?lang='.$lang)->assertOk();
                $this->assertStringContainsString('dir="'.($lang === 'en' ? 'ltr' : 'rtl').'"', $res->getContent(), $path.' '.$lang);
            }
        }

        $home = $this->get('/s/ribbon')->assertOk()->getContent();
        $this->assertStringContainsString('باقة ورد', $home);
        $this->assertStringNotContainsString('مخفيّ', $home, 'غيرُ المنشور لا يُعرض');
        $this->assertStringContainsString('Rose bouquet', $this->get('/s/ribbon/shop?lang=en')->getContent());
        $this->assertStringNotContainsString('cardNo', $this->get('/s/ribbon/checkout')->getContent(), 'لا حقولَ بطاقةٍ بلا بوّابة');

        $this->get('/s/ribbon/nothing-here')->assertNotFound();
        $this->get('/s/ribbon/p/999999')->assertNotFound();
        $this->get('/s/plain/cart')->assertNotFound('المتجرُ العاديّ لا سلّةَ له');
    }

    /* ═══════════ التسعير ═══════════ */

    public function test_the_quote_prices_from_the_database_and_never_from_the_browser(): void
    {
        $p = $this->product(['price' => 20]);

        $q = $this->postJson('/s/ribbon/quote', ['items' => [['id' => $p->id, 'qty' => 2, 'price' => 1]]])->assertOk()->json();

        $this->assertSame(40.0, (float) $q['subtotal']);
        $this->assertSame(3.0, (float) $q['delivery'], 'دون حدّ المجّان');
        $this->assertSame(43.0, (float) $q['total']);

        $q = $this->postJson('/s/ribbon/quote', ['items' => [['id' => $p->id, 'qty' => 3]]])->json();
        $this->assertSame(0.0, (float) $q['delivery'], 'فوق الحدّ: مجّانًا');

        $q = $this->postJson('/s/ribbon/quote', ['items' => [['id' => $p->id, 'qty' => 1]], 'fulfil' => 'pickup'])->json();
        $this->assertSame(0.0, (float) $q['delivery'], 'الاستلامُ من المحلّ بلا رسم');

        $this->postJson('/s/plain/quote', ['items' => [['id' => $p->id, 'qty' => 1]]])->assertNotFound();
    }

    public function test_sizes_are_priced_by_their_variant(): void
    {
        $p = $this->product(['price' => 0]);
        $small = ProductVariant::create(['business_id' => $this->business->id, 'product_id' => $p->id, 'name' => 'صغير', 'price' => 15, 'active' => true]);
        ProductVariant::create(['business_id' => $this->business->id, 'product_id' => $p->id, 'name' => 'كبير', 'price' => 30, 'active' => true]);

        $this->assertStringContainsString('صغير', $this->get('/s/ribbon/p/'.$p->id)->getContent());

        $q = $this->postJson('/s/ribbon/quote', ['items' => [['id' => $p->id, 'variant_id' => $small->id, 'qty' => 1]]])->json();
        $this->assertSame(15.0, (float) $q['subtotal']);

        $this->postJson('/s/ribbon/quote', ['items' => [['id' => $p->id, 'qty' => 1]]])->assertStatus(422);
    }

    public function test_a_coupon_follows_the_pos_rules_and_an_error_is_said(): void
    {
        $p = $this->product(['price' => 20]);
        Coupon::create(['business_id' => $this->business->id, 'code' => 'RIBBON10', 'type' => 'نسبة', 'value' => 10, 'min_order' => 0, 'active' => true]);

        $q = $this->postJson('/s/ribbon/quote', ['items' => [['id' => $p->id, 'qty' => 1]], 'promo' => 'ribbon10'])->json();
        $this->assertSame(2.0, (float) $q['discount']);
        $this->assertSame('RIBBON10', $q['coupon']);

        $q = $this->postJson('/s/ribbon/quote', ['items' => [['id' => $p->id, 'qty' => 1]], 'promo' => 'NOPE'])->json();
        $this->assertSame(0.0, (float) $q['discount']);
        $this->assertSame('كود الخصم غير صحيح', $q['promo_error']);
    }

    /* ═══════════ الإتمام ═══════════ */

    public function test_a_website_order_is_a_real_order_in_abaad(): void
    {
        $p = $this->product(['price' => 20, 'cost' => 8, 'quantity' => 10]);
        Coupon::create(['business_id' => $this->business->id, 'code' => 'RIBBON10', 'type' => 'نسبة', 'value' => 10, 'min_order' => 0, 'active' => true]);

        $res = $this->place(['items' => [['id' => $p->id, 'qty' => 2]], 'promo' => 'RIBBON10', 'pay' => 'transfer'])->assertOk();
        $this->assertTrue($res->json('ok'));

        $order = Order::where('business_id', $this->business->id)->firstOrFail();
        $this->assertSame(SalesChannel::WEBSITE, $order->channel);
        $this->assertSame('جديد', $order->status);
        $this->assertSame('غير مدفوع', $order->payment_status);
        $this->assertSame('تحويل بنكي', $order->payment_method);
        $this->assertSame('delivery', $order->fulfillment_type);
        $this->assertSame('مريم', $order->recipient_name);
        $this->assertSame('كل عام وأنتِ بخير', $order->card_message);
        $this->assertSame('2027-02-03 09:00:00', $order->scheduled_for->toDateTimeString());
        $this->assertSame('الخوير — شارع 18، منزل 4', $order->delivery_address, 'والأرقامُ تُطبَّع غربيّةً كما في كلّ طلب');
        $this->assertSame(40.0, (float) $order->subtotal);
        $this->assertSame(4.0, (float) $order->discount);
        $this->assertSame(3.0, (float) $order->delivery_fee);
        $this->assertSame(39.0, (float) $order->total);
        $this->assertSame('RIBBON10', $order->coupon_code);
        $this->assertSame(1, Coupon::first()->used_count);

        $item = $order->items->first();
        $this->assertSame(8.0, (float) $item->cost, 'لقطةُ التكلفة');
        $this->assertSame(8, (int) $p->fresh()->quantity, 'الرفُّ خُصم');
        $this->assertSame(1, Transaction::where('order_id', $order->id)->count());
        $this->assertSame(2, JournalEntry::where('sourceable_id', $order->id)->count(), 'قيدُ البيع وقيدُ التكلفة');
        $this->assertTrue(Ledger::trialBalance($this->business->id)['balanced']);

        $customer = Customer::where('business_id', $this->business->id)->firstOrFail();
        $this->assertSame(['مريم', '96899110001', 'ar'], [$customer->name, $customer->phone, $customer->language]);
        $this->assertSame($customer->id, (int) $order->customer_id);

        // وصفحةُ التأكيد برمزها — لا بمعرّف الطلب وحده
        $redirect = $res->json('redirect');
        $this->assertStringStartsWith('/s/ribbon/done/'.$order->id.'?t=', $redirect);
        $done = $this->get($redirect)->assertOk()->getContent();
        $this->assertStringContainsString($order->number, $done);
        $this->assertStringContainsString('بنك مسقط', $done, 'بياناتُ التحويل تظهر بعد الطلب');
        $this->get('/s/ribbon/done/'.$order->id.'?t=wrong')->assertNotFound();

        // ويظهر في تقرير المواسم قناةً موثوقة حين يُنسب — والقناةُ محفوظة
        $this->assertSame('website', Order::first()->channel);
    }

    /** السلّةُ نفسُها من الموقع ومن الصندوق — والدفترُ واحد */
    public function test_a_website_sale_posts_exactly_what_the_pos_posts_for_the_same_cart(): void
    {
        Setting::where('business_id', $this->business->id)->where('key', 'vat_enabled')->update(['value' => '1']);
        Setting::create(['business_id' => $this->business->id, 'key' => 'vat_rate', 'value' => '5']);
        $p = $this->product(['price' => 12.5, 'cost' => 4.25, 'quantity' => 20]);

        $snapshot = function (Order $o): array {
            $entries = JournalEntry::where('sourceable_type', Order::class)->where('sourceable_id', $o->id)->orderBy('id')->get();
            $lines = JournalLine::whereIn('journal_entry_id', $entries->pluck('id'))
                ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')->orderBy('journal_lines.id')
                ->get(['accounts.system_key', 'journal_lines.debit', 'journal_lines.credit'])
                ->map(fn ($l) => [$l->system_key, round((float) $l->debit, 3), round((float) $l->credit, 3)])->all();

            return ['subtotal' => (float) $o->subtotal, 'tax' => (float) $o->tax, 'total' => (float) $o->total,
                'tx' => (float) Transaction::where('order_id', $o->id)->sum('amount'), 'sources' => $entries->pluck('source')->all(), 'lines' => $lines];
        };

        // الصندوق — آجلًا، لأنّ طلبَ الموقع «غير مدفوع» حتى يُقبض
        Setting::create(['business_id' => $this->business->id, 'key' => 'pay_credit', 'value' => '1']);
        $customer = Customer::create(['business_id' => $this->business->id, 'name' => 'مريم', 'phone' => '96899110001', 'language' => 'ar', 'allow_credit_sales' => true, 'credit_limit' => 1000]);
        $this->actingAs($this->owner)->postJson('/pos/checkout', [
            'items' => [['id' => $p->id, 'name' => $p->name, 'qty' => 2, 'price' => 12.5]],
            'payment_method' => 'نقدي', 'customer_id' => $customer->id, 'credit' => true, 'paid_now' => 0,
        ])->assertOk();
        $pos = $snapshot(Order::where('business_id', $this->business->id)->orderByDesc('id')->firstOrFail());

        $this->place(['items' => [['id' => $p->id, 'qty' => 2]], 'fulfil' => 'pickup'])->assertOk();
        $web = $snapshot(Order::where('channel', 'website')->firstOrFail());

        $this->assertSame($pos, $web);
        $this->assertSame(18, (int) $p->fresh()->quantity + 2, 'الرفُّ خُصم مرّتين لبيعتين');
    }

    public function test_the_checkout_refuses_what_the_shop_refuses(): void
    {
        $p = $this->product(['quantity' => 1]);
        $hidden = $this->product(['name' => 'مخفيّ', 'published' => false]);
        $gone = $this->product(['name' => 'نافد', 'quantity' => 0]);

        $this->place(['items' => []])->assertStatus(422)->assertJsonValidationErrors('items');
        $this->place(['items' => [['id' => $hidden->id, 'qty' => 1]]])->assertStatus(422)->assertJsonValidationErrors('items');
        $this->place(['items' => [['id' => $gone->id, 'qty' => 1]]])->assertStatus(422)->assertJsonValidationErrors('items');
        $this->place(['items' => [['id' => $p->id, 'qty' => 5]]])->assertStatus(422);
        $this->place(['items' => [['id' => $p->id, 'qty' => 1]], 'name' => '', 'phone' => '12'])->assertStatus(422)->assertJsonValidationErrors(['name', 'phone']);
        $this->place(['items' => [['id' => $p->id, 'qty' => 1]], 'address' => '', 'area' => 'غير موجودة'])->assertStatus(422)->assertJsonValidationErrors(['address', 'area']);
        $this->place(['items' => [['id' => $p->id, 'qty' => 1]], 'date' => '2027-01-01'])->assertStatus(422)->assertJsonValidationErrors('date');
        $this->place(['items' => [['id' => $p->id, 'qty' => 1]], 'pay' => 'card'])->assertStatus(422)->assertJsonValidationErrors('pay');

        $this->assertSame(0, Order::count());
        $this->assertSame(1, (int) $p->fresh()->quantity);

        // وسلّةٌ من متجرٍ آخر لا تُباع من هنا
        $foreign = Product::create(['business_id' => $this->plain->id, 'name' => 'غريب', 'price' => 9, 'cost' => 1, 'quantity' => 5, 'active' => true, 'published' => true]);
        $this->place(['items' => [['id' => $foreign->id, 'qty' => 1]]])->assertStatus(422);
    }

    public function test_orders_close_when_the_owner_closes_them(): void
    {
        $p = $this->product();
        MarketingSettings::save($this->business->id, 'website', ['store_allow_orders' => '0']);

        $this->assertStringContainsString('لا يستقبل طلبات', $this->get('/s/ribbon/checkout')->getContent());
        $this->place(['items' => [['id' => $p->id, 'qty' => 1]]])->assertStatus(422);

        MarketingSettings::save($this->business->id, 'website', ['store_allow_orders' => '1', 'store_pay_cod' => '0', 'store_pay_transfer' => '0']);
        $this->place(['items' => [['id' => $p->id, 'qty' => 1]]])->assertStatus(422);
        $this->assertSame(0, Order::count());
    }

    public function test_a_known_phone_is_the_same_customer(): void
    {
        $p = $this->product();
        $known = Customer::create(['business_id' => $this->business->id, 'name' => 'مريم القديمة', 'phone' => '+968 9911 0001', 'language' => 'en']);

        $this->place(['items' => [['id' => $p->id, 'qty' => 1]]])->assertOk();

        $this->assertSame(1, Customer::where('business_id', $this->business->id)->count());
        $this->assertSame($known->id, (int) Order::first()->customer_id);
    }

    public function test_the_website_channel_reaches_the_season_report(): void
    {
        $p = $this->product();
        $this->place(['items' => [['id' => $p->id, 'qty' => 1]]])->assertOk();
        $order = Order::first();
        $season = Season::create(['business_id' => $this->business->id, 'name' => 'رمضان', 'starts_at' => '2027-01-20', 'ends_at' => '2027-02-20', 'active' => true, 'show_in_pos' => true, 'show_on_website' => true]);
        $order->items()->update(['season_id' => $season->id, 'season_name' => 'رمضان']);

        $channels = SeasonSales::report($season)['channels'];
        $this->assertSame([['key' => 'website', 'label' => 'الموقع الإلكتروني']], array_map(fn ($c) => ['key' => $c['key'], 'label' => $c['label']], $channels));
    }
}
