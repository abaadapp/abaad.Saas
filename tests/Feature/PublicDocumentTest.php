<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Category;
use App\Models\Product;
use App\Models\Review;
use App\Models\Setting;
use App\Models\User;
use App\Support\Website\Builder;
use App\Support\Website\Preview;
use App\Support\Website\Publisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * المستند العامّ — ما يقرؤه العارض في نطاق التاجر.
 *
 * وهو عقدٌ بين مستودعين: أبعادُ تكتبه و`abaadapp/Storefront` يقرؤه. فما
 * يُكسر هنا لا يظهر في اختبارات أبعاد بل في موقع تاجرٍ يعمل — صورةٌ لا
 * تُحمَّل، أو سعرٌ بلا عملة، أو شعارٌ غائب.
 *
 * وثلاثةٌ يحرسها هذا الملفّ خاصّةً:
 *
 * ١) **الروابط مطلقة.** رابطٌ نسبيّ يُقرأ في نطاق التاجر لا في نطاق أبعاد،
 *    فيطلب صورةً من متجره هو ولا يجدها.
 * ٢) **الهويّة تُقرأ لا تُجمَّد.** من بدّل شعاره لا يخطر له أن ينشر موقعه
 *    من جديد ليظهر التبديل.
 * ٣) **النصوص عربيّة مهما كانت لغة الطلب.** زائرُ متجرٍ ليس مستخدمًا في
 *    أبعاد ولا لغةَ له تُقرأ منها، والمستند يقول `locale: ar`.
 */
class PublicDocumentTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create([
            'name' => 'ورود مسقط', 'type' => 'محل ورود', 'status' => 'نشط',
            'phone' => '96890000000', 'email' => 'shop@abaad.om', 'city' => 'مسقط',
            'address' => 'الخوير، شارع ١٨', 'logo' => 'logos/wrood.png',
        ]);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->actingAs($this->owner);
    }

    private function seedShop(): void
    {
        $bid = $this->business->id;

        foreach ([
            'store_headline' => 'ورودٌ طازجة كلّ صباح',
            'store_about' => 'محلُّ ورودٍ في الخوير منذ ٢٠١٥.',
            'store_instagram' => 'wroodmuscat',
            'store_whatsapp' => '96890000000',
            'currency' => 'OMR',
            'site_domain' => 'wrood.om',
        ] as $key => $value) {
            Setting::create(['business_id' => $bid, 'key' => $key, 'value' => $value]);
        }

        $cat = Category::create(['business_id' => $bid, 'name' => 'باقات']);
        Category::create(['business_id' => $bid, 'name' => 'نباتات داخلية']);

        foreach ([['باقة ورد جوري', 12.5, 0], ['باقة توليب', 18.0, 20], ['صبّار صغير', 4.75, 0]] as $i => [$n, $p, $d]) {
            Product::create([
                'business_id' => $bid, 'name' => $n, 'price' => $p, 'discount' => $d,
                'category_id' => $cat->id, 'active' => true, 'quantity' => 10, 'alert_qty' => 2,
                'description' => 'وصفٌ قصير للمنتج يظهر في البطاقة.',
                'image' => 'products/sample'.$i.'.jpg',
            ]);
        }

        Review::create([
            'business_id' => $bid, 'author_name' => 'سالم', 'rating' => 5,
            'comment' => 'خدمة ممتازة والورد وصل طازجًا.', 'status' => 'منشور',
        ]);
    }

    /** @return array<string, mixed> */
    private function document(string $goal = 'store', string $template = 'modern'): array
    {
        $site = Builder::create($this->business, $goal, $template, $this->owner->id);

        return Preview::document($site);
    }

    public function test_the_document_carries_brand_currency_and_direction(): void
    {
        $this->seedShop();
        $doc = $this->document();

        $this->assertSame('ورود مسقط', $doc['brand']['name']);
        $this->assertSame('ورودٌ طازجة كلّ صباح', $doc['brand']['tagline']);
        $this->assertSame('96890000000', $doc['brand']['phone']);
        $this->assertSame('الخوير، شارع ١٨، مسقط', $doc['brand']['address']);
        $this->assertSame('OMR', $doc['currency']['code']);
        $this->assertSame(3, $doc['currency']['decimals']);
        $this->assertSame('ar', $doc['locale']);
        $this->assertSame('rtl', $doc['dir']);
    }

    public function test_every_image_url_in_the_document_is_absolute(): void
    {
        $this->seedShop();
        $site = Builder::create($this->business, 'store', 'modern', $this->owner->id);

        // صورةٌ رفعها التاجر — تُحفظ مسارًا وتخرج رابطًا كاملًا
        $hero = $site->pages()->where('key', 'home')->first()->sections()->where('type', 'hero')->first();
        $hero->update(['data' => array_merge($hero->data, ['image' => 'website/1/hero.jpg'])]);

        $doc = Preview::document($site->refresh());
        $found = 0;

        foreach ($doc['pages'] as $page) {
            foreach ($page['sections'] as $section) {
                foreach (['image'] as $key) {
                    if (($section['data'][$key] ?? '') !== '') {
                        $found++;
                        $this->assertStringStartsWith('http', $section['data'][$key]);
                    }
                }

                foreach ($section['items'] ?? [] as $item) {
                    if (($item['image'] ?? null) !== null) {
                        $found++;
                        $this->assertStringStartsWith('http', $item['image']);
                    }
                }
            }
        }

        $this->assertGreaterThan(0, $found, 'لا صورة في المستند — الاختبار لا يفحص شيئًا');
        $this->assertStringStartsWith('http', $doc['brand']['logo']);
    }

    public function test_a_product_without_a_real_photo_reports_no_photo(): void
    {
        $this->seedShop();

        // ما يكتبه إنشاء المنتج حين لا يرفع التاجر صورة
        Product::where('business_id', $this->business->id)->first()
            ->update(['image' => 'https://picsum.photos/seed/prod9/400/400']);

        $doc = $this->document();
        $images = [];

        foreach ($doc['data']['products'] as $product) {
            $images[] = $product['image'];
        }

        $this->assertContains(null, $images, 'صورةٌ وهميّة خرجت إلى موقع تاجر');
    }

    public function test_the_document_speaks_the_shops_language_not_the_readers(): void
    {
        $this->seedShop();

        // زائرٌ لا حساب له: لغةُ التطبيق الافتراضية إنجليزية (APP_LOCALE)
        app()->setLocale('en');

        $doc = $this->document();

        $this->assertSame('ar', $doc['locale']);
        $this->assertSame('rtl', $doc['dir']);
        $this->assertSame('إنستغرام', $doc['brand']['social'][0]['label']);
        $this->assertContains('نقدي', $doc['brand']['payments']);
    }

    public function test_a_shop_that_chose_english_gets_an_english_document(): void
    {
        $this->seedShop();
        Setting::create(['business_id' => $this->business->id, 'key' => 'locale', 'value' => 'en']);

        app()->setLocale('ar');
        $doc = $this->document();

        $this->assertSame('en', $doc['locale']);
        $this->assertSame('ltr', $doc['dir']);
        $this->assertSame('Instagram', $doc['brand']['social'][0]['label']);
        $this->assertContains('Cash', $doc['brand']['payments']);
    }

    public function test_social_accounts_reach_the_footer_from_where_they_were_written(): void
    {
        $this->seedShop();
        $doc = $this->document();

        $this->assertSame('instagram', $doc['brand']['social'][0]['network']);
        $this->assertSame('https://instagram.com/wroodmuscat', $doc['brand']['social'][0]['url']);
    }

    public function test_payment_methods_come_from_the_point_of_sale_not_a_second_setting(): void
    {
        $this->seedShop();
        Setting::create(['business_id' => $this->business->id, 'key' => 'pay_transfer', 'value' => '0']);

        $doc = $this->document();

        $this->assertNotContains('تحويل بنكي', $doc['brand']['payments']);
        $this->assertContains('نقدي', $doc['brand']['payments']);
    }

    public function test_the_published_route_answers_the_external_renderer(): void
    {
        $this->seedShop();
        $site = Builder::create($this->business, 'store', 'modern', $this->owner->id);
        Publisher::publish($site, $this->owner->id);

        $this->post(route('logout'));

        $response = $this->getJson('/site/wrood.om');

        $response->assertOk()
            ->assertJsonPath('site.brand.name', 'ورود مسقط')
            ->assertJsonPath('site.currency.code', 'OMR')
            ->assertJsonPath('site.dir', 'rtl')
            ->assertJsonStructure(['version', 'published_at', 'site' => ['pages', 'globals', 'tokens', 'brand']]);
    }

    public function test_a_shop_with_no_domain_of_its_own_is_reached_by_its_reserved_subdomain(): void
    {
        $this->seedShop();
        Setting::where('business_id', $this->business->id)->where('key', 'site_domain')->delete();
        Setting::create(['business_id' => $this->business->id, 'key' => 'site_subdomain', 'value' => 'wrood']);

        $site = Builder::create($this->business, 'store', 'modern', $this->owner->id);
        Publisher::publish($site, $this->owner->id);

        $this->post(route('logout'));

        $host = \App\Support\DomainOptions::host('wrood');

        $this->getJson('/site/'.$host)->assertOk()->assertJsonPath('site.brand.name', 'ورود مسقط');

        // ولا يُقرأ اسمٌ مركّب على أنّه حجز: `a.wrood.abaadapp.om` ليس أحدًا
        $this->getJson('/site/a.'.$host)->assertNotFound();
        $this->getJson('/site/wrood.example.com')->assertNotFound();
    }

    public function test_maintenance_answers_with_identity_so_the_page_is_not_blank(): void
    {
        $this->seedShop();
        $site = Builder::create($this->business, 'store', 'modern', $this->owner->id);
        Publisher::publish($site, $this->owner->id);
        $site->update(['maintenance' => true, 'maintenance_message' => 'نجدّد المحل — نعود الأحد']);

        $this->post(route('logout'));

        $this->getJson('/site/wrood.om')
            ->assertStatus(503)
            ->assertJsonPath('message', 'نجدّد المحل — نعود الأحد')
            ->assertJsonPath('brand.name', 'ورود مسقط')
            ->assertJsonPath('brand.whatsapp', '96890000000')
            ->assertJsonMissingPath('site');
    }

    /**
     * ويُولَّد من هذا الاختبار ما تقرؤه اختبارات العارض.
     *
     * مستندٌ يُكتب بيدٍ في مستودع العارض يوافق فهمَ كاتبه للعقد لا العقدَ
     * نفسه، فيبقى أخضرَ بينما الموقع الحقيقيّ لا يُرسم. فيُولَّد من هنا،
     * ولا يُشترط وجودُ المستودع الآخر ليمرّ هذا الاختبار.
     */
    public function test_it_refreshes_the_fixtures_the_renderer_tests_read(): void
    {
        $this->seedShop();

        // بلغة التاجر لا بلغة الاختبار: عناوينُ الصفحات تُخزَّن مترجمةً وقت
        // البناء، ومستندٌ فيه «Contact us» لا يشبه ما يبنيه تاجرٌ عربيّ
        app()->setLocale('ar');

        $site = Builder::create($this->business, 'store', 'modern', $this->owner->id);
        Publisher::publish($site, $this->owner->id);
        $site->refresh();

        $dir = base_path('../Storefront/tests/fixtures');

        if (! is_dir(dirname($dir))) {
            $this->markTestSkipped('مستودع العارض غير موجود بجانب أبعاد');
        }

        @mkdir($dir, 0777, true);

        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT;
        file_put_contents(
            $dir.'/store.json',
            json_encode(Preview::resolve($site->publishedVersion->payload, $this->business->id), $flags),
        );

        $other = Business::create([
            'name' => 'عيادة نور', 'type' => 'عيادة', 'status' => 'نشط',
            'phone' => '96899999999', 'city' => 'صلالة',
        ]);
        $doctor = User::create([
            'business_id' => $other->id, 'name' => 'مالك', 'email' => 'n@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        $this->actingAs($doctor);

        file_put_contents(
            $dir.'/profile.json',
            json_encode(Preview::document(Builder::create($other, 'profile', 'minimal', $doctor->id)), $flags),
        );

        $this->assertFileExists($dir.'/store.json');
    }
}
