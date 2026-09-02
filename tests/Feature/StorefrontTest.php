<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Category;
use App\Models\Product;
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
}
