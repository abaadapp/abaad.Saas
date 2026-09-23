<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Category;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Support\Demo;
use App\Support\MarketingSettings;
use App\Support\Storefront;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * متجرُ التاجر على الإنترنت — الصفحة التي يفتحها زبون.
 *
 * وهي أوّل صفحةٍ في النظام **بلا جلسة**: من يفتحها لا حساب له ولا متجرَ في
 * جلسته، فالمتجر يُعرف من عنوانه وحده. وذلك يقلب كلّ ما اعتاده باقي النظام:
 * لا `Demo::bid()` تُخمّن المتجر، ولا حارسَ مستأجرٍ يمنع التسرّب. فالعزلُ
 * هنا يُفحص بعينه — منتجُ متجرٍ لا يظهر على صفحة جاره — لأنّ الخطأ فيه لا
 * يُكتشف إلّا حين يراه زبون.
 *
 * وما يُفحص ثلاثة: أنّ الصفحة **لا تُفتح** إلّا لمن نشرها، وأنّها **لا تعرض
 * غير ما أذن به صاحبُها**، وأنّ العنوان **لا يُحجز مرّتين**.
 */
class StorefrontTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create([
            'name' => 'ورد الخوير', 'type' => 'محل ورود', 'status' => 'نشط', 'phone' => '91234567',
        ]);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'صاحب النشاط', 'email' => 'o@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        Product::create([
            'business_id' => $this->business->id, 'name' => 'بوكيه ورد',
            'price' => 12.5, 'cost' => 5, 'quantity' => 10, 'alert_qty' => 2, 'active' => true,
        ]);
    }

    private function publish(array $extra = []): void
    {
        $this->business->forceFill(['site_slug' => 'ward-alkhuwair'])->save();
        MarketingSettings::save($this->business->id, 'website', array_merge(['store_on' => '1'], $extra));
    }

    private function open(string $slug = 'ward-alkhuwair')
    {
        return $this->get(route('store.show', $slug));
    }

    /* --------------------------- الشعار --------------------------- */

    /**
     * الشعارُ المرفوع يُعرض من مكانه — لا من `/storage/storage/…`.
     *
     * النموذجُ يردّ الشعارَ رابطًا جاهزًا، وكانت الصفحةُ تغلّفه ثانيةً فيصير
     * `/storage/storage/logos/…` — ٤٠٤ على كلّ شعارٍ مرفوع.
     */
    public function test_an_uploaded_logo_is_linked_once_not_twice(): void
    {
        $this->business->forceFill(['logo' => 'logos/ward.png'])->save();

        $logo = Storefront::page($this->business->fresh())['logo'];

        $this->assertStringEndsWith('/storage/logos/ward.png', $logo);
        $this->assertStringNotContainsString('/storage/storage/', $logo);
    }

    /** ورابطٌ خارجيّ يبقى كما هو — لا يُسبَق بـ`/storage/` */
    public function test_an_external_logo_is_left_alone(): void
    {
        $this->business->forceFill(['logo' => 'https://cdn.example.com/ward.png'])->save();

        $this->assertSame('https://cdn.example.com/ward.png', Storefront::page($this->business->fresh())['logo']);
    }

    /** ولا شعارَ يُقال فراغًا */
    public function test_no_logo_is_null(): void
    {
        $this->assertNull(Storefront::page($this->business->fresh())['logo']);
    }

    /* --------------------------- العنوان --------------------------- */

    public function test_a_slug_is_cleaned_to_something_a_customer_can_type(): void
    {
        $this->assertSame('ward-alkhuwair', Storefront::slug('  Ward  Alkhuwair '));
        $this->assertSame('ward-alkhuwair', Storefront::slug('ward--alkhuwair'));
        $this->assertSame('my-store', Storefront::slug('My_Store'));
    }

    /** والقصيرُ والمحجوز والعربيّ يُردّون — العنوان يُملى في هاتف ويُكتب على بطاقة */
    public function test_a_slug_that_cannot_serve_as_an_address_is_refused(): void
    {
        $this->assertNull(Storefront::slug('ab'));
        $this->assertNull(Storefront::slug('admin'));
        $this->assertNull(Storefront::slug('www'));
        $this->assertNull(Storefront::slug('ورد الخوير'));
        $this->assertNull(Storefront::slug(str_repeat('a', 41)));
    }

    /**
     * ولا يُحجز عنوانٌ مرّتين.
     *
     * والتفرّد مفروضٌ في القاعدة أيضًا (فهرسٌ فريد)، لكنّ الرفض هنا يقول
     * للتاجر «محجوز» بدل أن يُردّ بخطأ قاعدةٍ لا يفهمه.
     */
    public function test_two_shops_cannot_hold_the_same_address(): void
    {
        $neighbour = Business::create(['name' => 'الجار', 'type' => 'عام', 'status' => 'نشط']);
        $neighbour->forceFill(['site_slug' => 'ward-alkhuwair'])->save();

        $this->actingAs($this->owner)
            ->post(route('admin.marketing.store.save'), ['site_slug' => 'ward-alkhuwair'])
            ->assertSessionHasErrors('site_slug');
    }

    /** ولا يُنشر متجرٌ بلا عنوان: مفتاحٌ مرفوعٌ وصفحةٌ لا تُفتح من أيّ رابط */
    public function test_publishing_without_an_address_is_refused(): void
    {
        $this->actingAs($this->owner)
            ->post(route('admin.marketing.store.save'), ['site_slug' => '', 'store_on' => true])
            ->assertSessionHasErrors('site_slug');
    }

    /**
     * ═══ وحفظٌ لا يحمل العنوانَ لا يمحوه ═══
     *
     * `input` تردّ `null` على مفتاحٍ غائبٍ كما تردّها على حقلٍ فُرّغ. فكلُّ
     * حفظٍ لا يحمل `site_slug` كان يمسح عنوانَ المتجر: المفتاحُ يبقى
     * مرفوعًا في شاشة صاحبه — «منشور» — وكلُّ رابطٍ يقع على ٤٠٤.
     *
     * وهي الحالةُ التي يرفض الحارسُ فوق هذا إنشاءها بالكتابة، فكانت تُنشأ
     * بالسكوت. ولا تُكتشف إلّا من زبونٍ يقول «موقعك لا يفتح».
     */
    public function test_a_save_that_does_not_carry_the_address_keeps_it(): void
    {
        $this->publish();

        $this->actingAs($this->owner)
            ->post(route('admin.marketing.store.save'), ['store_image_note' => 'التوصيل خلال ساعتين'])
            ->assertSessionHasNoErrors();

        $this->assertSame('ward-alkhuwair', $this->business->refresh()->site_slug);
        $this->open()->assertOk();
    }

    /**
     * ومنشورٌ يُفرَّغ عنوانُه يُردّ — ولو لم تحمل الحمولةُ مفتاحَ النشر.
     *
     * السؤالُ «أمنشورٌ هو؟» يقع على الحال لا على الحمولة: حفظٌ لا يحمل
     * `store_on` كان يمرّ من الحارس فيُمحى العنوانُ ويبقى المفتاحُ مرفوعًا.
     */
    public function test_a_published_shop_is_not_stripped_of_its_address(): void
    {
        $this->publish();

        $this->actingAs($this->owner)
            ->post(route('admin.marketing.store.save'), ['site_slug' => ''])
            ->assertSessionHasErrors('site_slug');

        $this->assertSame('ward-alkhuwair', $this->business->refresh()->site_slug);
    }

    /** وتفريغُ الحقل بيده يبقى محوًا — `exists` تقول «أُرسل» لا «مُلئ» */
    public function test_clearing_the_field_himself_still_clears_it(): void
    {
        $this->publish();

        $this->actingAs($this->owner)
            ->post(route('admin.marketing.store.save'), ['site_slug' => '', 'store_on' => false])
            ->assertSessionHasNoErrors();

        $this->assertNull($this->business->refresh()->site_slug);
    }

    /* ------------------- ولا يبتلع النظامَ نفسه ------------------- */

    /**
     * `app.abaadapp.om` ليست متجرًا.
     *
     * مسارُ النطاق الفرعيّ يلتقط كلَّ اسمٍ تحت النطاق، ولاراڤيل تُقدّم المسار
     * المقيَّد بنطاق على المطلق — **فابتلع هذا المسارُ الصفحةَ الرئيسية للنظام
     * وردّها «غير موجود»**. وقع ذلك فعلًا على الإنتاج، ولم يظهر في اختبارٍ
     * لأنّ الاختبارات تطلب المسار ولا تحمل مضيفًا.
     *
     * فيُطلب هنا بالمضيف صراحةً — وهو الشيء الوحيد الذي كان سيكشفه.
     */
    public function test_the_system_own_hosts_are_never_read_as_shops(): void
    {
        foreach (['app', 'www', 'admin', 'api', 'pos'] as $host) {
            $this->get('https://'.$host.'.'.Storefront::domain().'/')
                ->assertOk();

            $this->assertSame(
                'login',
                request()->route()?->getName(),
                "«{$host}» قُرئ متجرًا فابتلع صفحة النظام",
            );
        }
    }

    /** والنطاق العاري كذلك: `abaadapp.om` هو النظام لا متجرٌ اسمُه فارغ */
    public function test_the_bare_domain_still_belongs_to_the_system(): void
    {
        $this->get('https://'.Storefront::domain().'/')->assertOk();
    }

    /* --------------------------- الباب --------------------------- */

    public function test_a_published_shop_opens_for_anyone_with_no_login(): void
    {
        $this->publish();

        $this->open()->assertOk()->assertSee('ورد الخوير')->assertSee('بوكيه ورد');
    }

    /**
     * وما لم يُنشر فهو ٤٠٤ لا صفحةٌ فارغة.
     *
     * صفحةٌ فارغة تقول للزائر إنّ المحلّ مغلق، و٤٠٤ تقول إنّه لا عنوان هنا —
     * وهي الحقيقة. والفرق يقع على من وصله رابطٌ قديم.
     */
    public function test_an_unpublished_shop_is_not_there_at_all(): void
    {
        $this->business->forceFill(['site_slug' => 'ward-alkhuwair'])->save();

        $this->open()->assertNotFound();
    }

    /** ومتجرٌ أوقفته المنصّة لا يبقى مفتوحًا للزبائن */
    public function test_a_suspended_shop_closes_its_public_page(): void
    {
        $this->publish();
        $this->business->update(['status' => 'موقوف']);

        $this->open()->assertNotFound();
    }

    public function test_an_unknown_address_is_not_found(): void
    {
        $this->get(route('store.show', 'la-shay'))->assertNotFound();
    }

    /* ------------------------- ما يُعرض وما لا يُعرض ------------------------- */

    /** الأسعار تُعرض بإذنٍ لا افتراضًا: من يسعّر حسب الطلب يُطفئها ويبقى الطلب يعمل */
    public function test_prices_can_be_withheld_while_the_order_button_still_works(): void
    {
        $this->publish(['store_show_prices' => '0']);

        $this->open()->assertOk()->assertDontSee('12.500')->assertSee('اطلب عبر واتساب');
    }

    /**
     * ولا تخرج أرقامُ الإدارة إلى صفحةٍ عامّة.
     *
     * التكلفةُ تقول لمنافسك هامشك، والكميّةُ تقول له مخزونك. والمعروضُ حالةٌ
     * لا رقم: متوفّرٌ أو نفد.
     */
    public function test_the_public_page_never_leaks_cost_or_stock(): void
    {
        $this->publish();

        $html = $this->open()->assertOk()->getContent();

        $this->assertStringNotContainsString('5.000', $html, 'التكلفة ظهرت في صفحةٍ عامّة');
        $this->assertStringNotContainsString('"quantity"', $html);
        $this->assertStringNotContainsString('cost', $html);
    }

    /** والمنتج المُطفأ لا يُعرض: أطفأه صاحبُه فلا يُطلب */
    public function test_an_inactive_product_is_not_shown(): void
    {
        Product::create([
            'business_id' => $this->business->id, 'name' => 'صنف مخفيّ',
            'price' => 9, 'cost' => 1, 'quantity' => 5, 'alert_qty' => 1, 'active' => false,
        ]);
        $this->publish();

        $this->open()->assertOk()->assertDontSee('صنف مخفيّ');
    }

    /**
     * وعزلُ المتاجر قبل كلّ شيء.
     *
     * الصفحة بلا جلسة، فلا حارسَ مستأجرٍ يمنع التسرّب — والقيدُ في كلّ
     * استعلامٍ على حدة. وسهوٌ واحد يعرض بضاعة الجار على صفحتنا.
     */
    public function test_a_neighbours_products_never_appear(): void
    {
        $neighbour = Business::create(['name' => 'الجار', 'type' => 'عام', 'status' => 'نشط']);
        Product::create([
            'business_id' => $neighbour->id, 'name' => 'بضاعة الجار',
            'price' => 3, 'cost' => 1, 'quantity' => 9, 'alert_qty' => 1, 'active' => true,
        ]);
        $this->publish();

        $this->open()->assertOk()->assertDontSee('بضاعة الجار');
    }

    /** والقسم الفارغ لا يُعرض تبويبًا يفتح على فراغ */
    public function test_only_categories_that_hold_something_are_offered(): void
    {
        Category::create(['business_id' => $this->business->id, 'name' => 'قسم فارغ']);
        $this->publish();

        $this->open()->assertOk()->assertDontSee('قسم فارغ');
    }

    /* --------------------------- الطلب --------------------------- */

    /** الطلب يقع في واتساب — والرقم يقع على رقم المتجر إن لم يُكتب غيرُه */
    public function test_the_order_button_carries_the_shop_number_and_the_item(): void
    {
        $this->publish();

        $html = $this->open()->assertOk()->getContent();

        $this->assertStringContainsString('wa.me/96891234567', $html);
        $this->assertStringContainsString(rawurlencode('بوكيه ورد'), $html);
    }

    /** وزرٌّ بلا رقمٍ يفتح محادثةً بلا مستقبِل، فلا يُرسم */
    public function test_no_order_button_is_drawn_without_a_number(): void
    {
        $this->business->update(['phone' => null]);
        $this->publish();

        $this->open()->assertOk()->assertDontSee('اطلب عبر واتساب');
    }

    /* --------------------------- الترويسة --------------------------- */

    /** ومتجرُ أبعاد يسبق الرابط الخارجيّ: زرُّ الترويسة يفتح المتجر الحيّ */
    public function test_the_header_button_prefers_the_shop_hosted_here(): void
    {
        MarketingSettings::save($this->business->id, 'website', [
            'site_on' => '1', 'site_domain' => 'old-site.om',
        ]);
        $this->publish();

        $this->actingAs($this->owner);

        $this->assertSame(
            'https://ward-alkhuwair.'.Storefront::domain(),
            Demo::websiteUrl(),
        );
    }

    /** ومن لم ينشر يبقى رابطُه الخارجيّ كما كان */
    public function test_an_unpublished_shop_keeps_its_external_link(): void
    {
        MarketingSettings::save($this->business->id, 'website', [
            'site_on' => '1', 'site_domain' => 'old-site.om',
        ]);

        $this->actingAs($this->owner);

        $this->assertSame('https://old-site.om', Demo::websiteUrl());
    }

    /* ==================== ما على الرفّ — من مصدرٍ واحد ==================== */

    /**
     * والباقةُ ذاتُ الوصفة بضاعةٌ لا صنفٌ نافد.
     *
     * ═══ العطبُ الذي وُضع له هذا الحارس ═══
     *
     * كانت الصفحةُ تحكم بـ`quantity > 0` مكتوبًا بيدها. ومخزونُ ذي الوصفة
     * مكوّناتُه (انظر `Recipe`)، وكمّيتُه هو **صفرٌ أبدًا** — فباقاتُ محلّ
     * الورود كلُّها كانت تخرج «غير متوفّر حاليًا»، وهي بضاعتُه.
     *
     * والموقعُ المبنيُّ يسأل `Shelf` منذ كُتبت، وهذه لا تسأله: فيقول أحدُهما
     * «نفد» ويقول الآخر «متوفّر» عن الصنف نفسِه.
     */
    public function test_a_recipe_product_is_on_the_shelf_though_its_quantity_is_zero(): void
    {
        $bouquet = Product::create([
            'business_id' => $this->business->id, 'name' => 'باقة الأعراس',
            'price' => 25, 'cost' => 9, 'quantity' => 0, 'alert_qty' => 0, 'active' => true,
        ]);
        $rose = Product::create([
            'business_id' => $this->business->id, 'name' => 'وردة مفردة',
            'price' => 1, 'cost' => 0.3, 'quantity' => 500, 'alert_qty' => 10, 'active' => true,
        ]);
        $bouquet->recipeItems()->create([
            'business_id' => $this->business->id,
            'component_product_id' => $rose->id,
            'quantity' => 12,
        ]);

        $this->publish();

        $shown = collect(Storefront::page($this->business->fresh())['products'])->keyBy('name');

        $this->assertTrue(
            $shown['باقة الأعراس']['available'],
            'خرجت باقةٌ ذاتُ وصفةٍ «غير متوفّرة» وهي بضاعةُ المحلّ',
        );
        $this->assertNotNull($shown['باقة الأعراس']['order_url'], 'وحُجب زرُّ طلبها');
    }

    /** ومتجرٌ يأذن بالبيع تحت الصفر لا نفادَ عنده — كما في نقطة بيعه */
    public function test_a_shop_that_sells_below_zero_shows_everything(): void
    {
        Setting::updateOrCreate(
            ['business_id' => $this->business->id, 'key' => 'allow_negative_stock'],
            ['value' => '1'],
        );
        Product::create([
            'business_id' => $this->business->id, 'name' => 'صنفٌ نفد',
            'price' => 4, 'cost' => 1, 'quantity' => 0, 'alert_qty' => 1, 'active' => true,
        ]);

        $this->publish();

        $shown = collect(Storefront::page($this->business->fresh())['products'])->keyBy('name');

        $this->assertTrue($shown['صنفٌ نفد']['available']);
    }

    /**
     * وما نفد حقًّا يبقى معروضًا موسومًا — ويتأخّر عمّا على الرفّ.
     *
     * واسمُه يسبق أبجديًّا عمدًا: بلا ذلك يتأخّر بترتيب الاسم وحده، فتمرّ
     * الحالةُ ولو أُسقط الترتيبُ بالتوفّر كلُّه — وقد نجت عليها طفرةٌ فعلًا.
     */
    public function test_what_is_truly_out_is_marked_and_falls_behind(): void
    {
        Product::create([
            // «ا» تسبق «ب» في «بوكيه ورد» — فترتيبُ الاسم وحده يضعه أوّلًا
            'business_id' => $this->business->id, 'name' => 'الصنف الذي نفد',
            'price' => 4, 'cost' => 1, 'quantity' => 0, 'alert_qty' => 1, 'active' => true,
        ]);

        $this->publish();

        $names = collect(Storefront::page($this->business->fresh())['products'])->pluck('name')->all();
        $shown = collect(Storefront::page($this->business->fresh())['products'])->keyBy('name');

        $this->assertFalse($shown['الصنف الذي نفد']['available']);
        $this->assertTrue($shown['بوكيه ورد']['available']);
        // والمتوفّر يتقدّمه — لا يُدفن ما يُباع تحت ما لا يُباع
        $this->assertSame(['بوكيه ورد', 'الصنف الذي نفد'], $names, 'تقدّم ما نفد على ما هو على الرفّ');
    }

    /* ================== و«قبولُ الطلبات» يُدير البابين ================== */

    /**
     * يُطفئ استقبالَ الطلبات فيُطفأ هنا أيضًا.
     *
     * كان الموقعُ المبنيُّ يقرأ المفتاح وهذه الصفحةُ لا تقرؤه. فمن أطفأه —
     * مسافرًا، أو مراجعًا لأسعاره — أُطفئ في أحد موقعيه وبقي في الآخر.
     */
    public function test_closing_orders_removes_the_button_here_too(): void
    {
        $this->publish(['store_allow_orders' => '0']);

        $page = Storefront::page($this->business->fresh());

        $this->assertNull($page['whatsapp'], 'بقي رقمُ الطلب معروضًا بعد إطفاء الاستقبال');
        $this->assertNull(
            collect($page['products'])->first()['order_url'],
            'بقي زرُّ الطلب بعد إطفاء الاستقبال',
        );

        $this->open()->assertOk()->assertDontSee('اطلب عبر واتساب');
    }
}
