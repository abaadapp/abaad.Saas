<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Category;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * المتجر على الإنترنت — البوّابة، وما يُعرض فيه، ولمن.
 *
 * هذه أوّل صفحةٍ في النظام تُفتح بلا حساب، فكلُّ شرطٍ يُنسى فيها يُقرأ عند
 * زبونٍ لا عند موظّف: صنفٌ لم يُنشر يظهر بسعر الجملة، أو جردُ تاجرٍ يظهر على
 * صفحة تاجرٍ آخر لأنّ `business_id` سقط من استعلام.
 *
 * ولذلك تُفحص البوّابة قبل الشكل: من يُفتح له، ومتى، وما الذي يراه.
 */
class StorefrontTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    private Product $shown;

    private Product $hidden;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');

        $this->business = Business::create(['name' => 'ورود مسقط', 'type' => 'محل ورود', 'status' => 'نشط']);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@abaadapp.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $category = Category::create(['business_id' => $this->business->id, 'name' => 'باقات']);

        $this->shown = Product::create([
            'business_id' => $this->business->id, 'category_id' => $category->id, 'name' => 'باقة الورد الأحمر',
            'price' => 12.5, 'cost' => 5, 'quantity' => 10, 'active' => true, 'published' => true,
        ]);

        $this->hidden = Product::create([
            'business_id' => $this->business->id, 'name' => 'ورقة تغليف',
            'price' => 0.2, 'cost' => 0.1, 'quantity' => 500, 'active' => true, 'published' => false,
        ]);

        $this->publish();
    }

    private function publish(bool $on = true): void
    {
        $this->set('site_published', $on ? '1' : '0');
    }

    private function set(string $key, string $value): void
    {
        Setting::updateOrCreate(['business_id' => $this->business->id, 'key' => $key], ['value' => $value]);
    }

    private function home()
    {
        return $this->get(route('store.home', $this->business));
    }

    /* ------------------------------ البوّابة ------------------------------ */

    public function test_a_published_store_opens_for_anyone(): void
    {
        $this->home()->assertOk()->assertSee('ورود مسقط');
    }

    public function test_an_unpublished_store_is_not_found_for_a_visitor(): void
    {
        $this->publish(false);

        $this->home()->assertNotFound();
    }

    /**
     * ٤٠٤ لا ٤٠٣ — ولا يُقال «مغلق».
     *
     * الفرق ليس في الشكل: صفحةٌ تقول «هذا المتجر غير منشور» تجعل تجربة
     * المعرّفات واحدًا واحدًا كشفًا لمتاجر المنصّة وأيّها نشط.
     */
    public function test_a_closed_store_does_not_admit_it_exists(): void
    {
        $this->publish(false);

        $this->home()->assertNotFound()->assertDontSee('ورود مسقط');
    }

    public function test_the_owner_previews_the_store_before_publishing_it(): void
    {
        $this->publish(false);

        $this->actingAs($this->owner)->get(route('store.home', $this->business))
            ->assertOk()
            ->assertSee('هذه معاينة — متجرك غير منشور، ولا يفتحه أحد سواك.', false);
    }

    public function test_a_merchant_does_not_preview_another_merchants_store(): void
    {
        $this->publish(false);
        $other = Business::create(['name' => 'متجر آخر', 'type' => 'عام', 'status' => 'نشط']);
        $stranger = User::create([
            'business_id' => $other->id, 'name' => 'غريب', 'email' => 's@abaadapp.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->actingAs($stranger)->get(route('store.home', $this->business))->assertNotFound();
    }

    /**
     * نشاطٌ معطَّل لا يبيع.
     *
     * وإلّا بقي موقع متجرٍ أُوقف يستقبل الطلبات: زبونٌ يطلب وينتظر، وتاجرٌ
     * لا يرى الطلب لأنّ لوحته مقفلة عليه.
     */
    public function test_a_disabled_business_closes_its_store(): void
    {
        $this->business->update(['status' => 'معطل']);

        $this->home()->assertNotFound();
    }

    public function test_an_expired_subscription_closes_its_store(): void
    {
        $this->business->update(['ends_at' => now()->subYear()]);

        $this->home()->assertNotFound();
    }

    /* --------------------------- ما يُعرض فيه --------------------------- */

    public function test_only_published_products_appear(): void
    {
        $this->home()->assertOk()
            ->assertSee('باقة الورد الأحمر')
            ->assertDontSee('ورقة تغليف');
    }

    public function test_an_inactive_product_does_not_appear_even_if_published(): void
    {
        $this->shown->update(['active' => false]);

        $this->home()->assertOk()->assertDontSee('باقة الورد الأحمر');
    }

    /**
     * جردُ تاجرٍ لا يظهر على صفحة تاجرٍ آخر.
     *
     * شرطٌ واحد يسقط من استعلامٍ واحد يكشف الأسعار والأصناف كلَّها — ولا
     * يظهر ذلك في أيّ شاشةٍ داخلية، بل عند زبون.
     */
    public function test_another_businesss_products_never_appear(): void
    {
        $other = Business::create(['name' => 'متجر آخر', 'type' => 'عام', 'status' => 'نشط']);
        Product::create([
            'business_id' => $other->id, 'name' => 'صنف الجيران',
            'price' => 3, 'cost' => 1, 'quantity' => 5, 'active' => true, 'published' => true,
        ]);

        $this->home()->assertOk()->assertDontSee('صنف الجيران');
    }

    public function test_a_product_page_of_another_store_is_not_found(): void
    {
        $other = Business::create(['name' => 'متجر آخر', 'type' => 'عام', 'status' => 'نشط']);
        Setting::create(['business_id' => $other->id, 'key' => 'site_published', 'value' => '1']);

        $this->get(route('store.product', [$other, $this->shown]))->assertNotFound();
    }

    public function test_an_unpublished_product_page_is_not_found(): void
    {
        $this->get(route('store.product', [$this->business, $this->hidden]))->assertNotFound();
    }

    /**
     * ولا صورةَ عشوائية من الإنترنت على صفحةٍ يفتحها زبون.
     *
     * كلُّ صنفٍ يُنشأ بلا صورةٍ مرفوعة يُكتب في عموده رابطُ `picsum.photos`.
     * وهو داخل اللوحة حشوٌ لا يضرّ، وعلى المتجر صورةُ منتجٍ ليست منتجَه:
     * الزبون يطلب ما رأى فيصله غيرُه.
     */
    public function test_a_product_without_a_real_image_shows_no_random_photo(): void
    {
        $this->shown->update(['image' => \App\Support\Demo::image('prod'.$this->shown->id)]);

        $this->home()->assertOk()->assertDontSee('picsum.photos', false);
        $this->get(route('store.product', [$this->business, $this->shown]))
            ->assertOk()->assertDontSee('picsum.photos', false);
    }

    public function test_an_uploaded_image_is_shown_as_it_is(): void
    {
        $this->shown->update(['image' => 'https://cdn.example.com/rose.jpg']);

        $this->home()->assertOk()->assertSee('https://cdn.example.com/rose.jpg', false);
    }

    /* ------------------------- ما يضبطه التاجر ------------------------- */

    public function test_prices_disappear_when_the_merchant_hides_them(): void
    {
        $this->get(route('store.product', [$this->business, $this->shown]))
            ->assertOk()->assertSee('12.500', false);

        $this->set('site_show_prices', '0');

        $this->get(route('store.product', [$this->business, $this->shown]))
            ->assertOk()->assertDontSee('12.500', false)->assertSee('السعر عند الطلب');
    }

    /**
     * زرّ الطلب لا يظهر بلا رقمٍ يستقبله.
     *
     * «قبول الطلبات» مرفوعًا ورقم واتساب فارغًا يعني زرًّا يفتح `wa.me/`
     * بلا رقم — صفحةَ خطأ من واتساب في وجه زبونٍ أراد أن يشتري.
     */
    public function test_the_order_button_needs_both_the_switch_and_a_number(): void
    {
        $this->set('site_allow_orders', '1');
        $this->get(route('store.product', [$this->business, $this->shown]))
            ->assertOk()->assertDontSee('اطلب عبر واتساب');

        $this->set('site_whatsapp', '968 9000 0000');
        $this->get(route('store.product', [$this->business, $this->shown]))
            ->assertOk()->assertSee('اطلب عبر واتساب')->assertSee('wa.me/96890000000', false);
    }

    /* --------------------------- الكثرة والبحث --------------------------- */

    /**
     * متجرٌ كبير يُصفَّح — لا يُبنى في صفحةٍ واحدة.
     *
     * ألفا صنفٍ منشور تعني ألفي بطاقة وألفي صورة في مستندٍ واحد: يُفتح على
     * هاتفٍ في شبكةٍ بطيئة فلا يُفتح.
     */
    public function test_a_large_catalogue_is_paginated(): void
    {
        for ($i = 1; $i <= \App\Support\Storefront::PER_PAGE + 5; $i++) {
            Product::create([
                'business_id' => $this->business->id, 'name' => 'صنف رقم '.$i,
                'price' => 1, 'cost' => 1, 'quantity' => 1, 'active' => true, 'published' => true,
            ]);
        }

        $this->home()->assertOk()->assertSee('page=2', false);
    }

    public function test_the_search_narrows_to_the_matching_products(): void
    {
        Product::create([
            'business_id' => $this->business->id, 'name' => 'شمعة عطرية',
            'price' => 3, 'cost' => 1, 'quantity' => 4, 'active' => true, 'published' => true,
        ]);

        $this->get(route('store.home', [$this->business, 'q' => 'شمعة']))
            ->assertOk()->assertSee('شمعة عطرية')->assertDontSee('باقة الورد الأحمر');
    }

    /* ------------------------ اختيار ما يُعرض ------------------------ */

    /**
     * «اعرض كل الأصناف النشطة» لا تعرض ما أُطفئ في نقطة البيع.
     *
     * ما أُطفئ أُطفئ لسبب — نفد، أو لم يعد يُباع. وعرضُه على الموقع يعني
     * طلبًا على صنفٍ لا يُصرف.
     */
    public function test_publishing_all_skips_what_is_inactive(): void
    {
        $off = Product::create([
            'business_id' => $this->business->id, 'name' => 'صنف موقوف',
            'price' => 1, 'cost' => 1, 'quantity' => 0, 'active' => false, 'published' => false,
        ]);

        $this->actingAs($this->owner)
            ->post(route('admin.marketing.website.products'), ['all' => true, 'published' => true])
            ->assertSessionHasNoErrors();

        $this->assertTrue($this->hidden->fresh()->published, 'المخفيّ النشِط لم يُعرض');
        $this->assertFalse($off->fresh()->published, 'الموقوف عن البيع عُرض على الموقع');
    }

    /** ولا يمسّ التاجرُ أصنافَ غيره ولو أرسل معرّفاتها */
    public function test_publishing_by_id_never_reaches_another_business(): void
    {
        $other = Business::create(['name' => 'متجر آخر', 'type' => 'عام', 'status' => 'نشط']);
        $theirs = Product::create([
            'business_id' => $other->id, 'name' => 'صنف الجيران',
            'price' => 3, 'cost' => 1, 'quantity' => 5, 'active' => true, 'published' => false,
        ]);

        $this->actingAs($this->owner)
            ->post(route('admin.marketing.website.products'), [
                'ids' => [$theirs->id], 'published' => true,
            ])->assertSessionHasNoErrors();

        $this->assertFalse($theirs->fresh()->published);
    }

    /**
     * وذو المقاسات لا يُعرض بسعر عموده — ذاك رقمٌ لا يدفعه أحد.
     *
     * «من له مقاسات لا يُباع بنفسه: السعر يأتي من المقاس المختار» — نصُّ
     * `ProductVariant`. فعرضُ `price` عليه سعرٌ مكذوب: يرى الزبون رقمًا
     * ويطلب فيُقال له غيرُه.
     */
    public function test_a_product_with_sizes_shows_its_real_prices(): void
    {
        // ٧٫١٠٠ لا ٥: «5.000» جزءٌ من «25.000» فيمرّ نفيُها على نصٍّ فيه
        $this->shown->update(['price' => 7.1]);

        foreach ([['وسط', 18.0], ['كبير', 25.0]] as [$name, $price]) {
            \App\Models\ProductVariant::create([
                'business_id' => $this->business->id, 'product_id' => $this->shown->id,
                'name' => $name, 'price' => $price, 'active' => true, 'sort_order' => 0,
            ]);
        }

        $this->home()->assertOk()
            ->assertSee('18.000', false)
            ->assertDontSee('7.100', false);

        $this->get(route('store.product', [$this->business, $this->shown]))
            ->assertOk()
            ->assertSee('وسط')->assertSee('18.000', false)
            ->assertSee('كبير')->assertSee('25.000', false)
            ->assertDontSee('7.100', false);
    }

    public function test_the_chosen_colour_reaches_the_page(): void
    {
        $this->set('site_theme', '#0ea5e9');

        $this->home()->assertOk()->assertSee('--store-accent: #0ea5e9', false);
    }

    /**
     * لونٌ فاسد لا يكسر الصفحة.
     *
     * القيمة تُكتب في CSS مباشرةً، فنصٌّ فيه `}` يُنهي القاعدة ويفتح ما بعدها
     * — والصفحة تُقدَّم بلا شكلٍ أو بشكلٍ حقنه غيرُ صاحبها.
     */
    public function test_a_broken_colour_falls_back_instead_of_leaking_into_the_css(): void
    {
        $this->set('site_theme', '} body { display:none } .x {');

        $this->home()->assertOk()
            ->assertSee('--store-accent: #111827', false)
            ->assertDontSee('display:none', false);
    }

    /* ------------------------- زرّ المتجر في اللوحة ------------------------- */

    /**
     * الزرّ لا يُرسَل إلا لمتجرٍ يُفتح.
     *
     * زرٌّ في الترويسة يفتح صفحةً تردّ ٤٠٤ أسوأ من زرٍّ يدلّ على الإعدادات:
     * الأوّل يجعل التاجر يظنّ أنّ متجره معطوب، والثاني يقول له ما ينقص.
     */
    public function test_the_topbar_gets_the_store_link_only_when_it_is_published(): void
    {
        $context = fn () => $this->actingAs($this->owner)
            ->get(route('admin.settings.index'))
            ->assertOk()->viewData('page')['props']['context'];

        $this->assertSame(route('store.home', $this->business), $context()['storeUrl']);

        $this->publish(false);

        $this->assertNull($context()['storeUrl']);
    }

    public function test_an_unpublished_store_asks_search_engines_to_stay_away(): void
    {
        $this->publish(false);

        $this->actingAs($this->owner)->get(route('store.home', $this->business))
            ->assertOk()->assertSee('noindex', false);
    }
}
