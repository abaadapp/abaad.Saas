<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Category;
use App\Models\Currency;
use App\Models\JobTitle;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Support\MarketingSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ما يُعرض في المتجر، وبكم يُعرض.
 *
 * الصفحة العامّة كانت تُبنى من `where('active', true)` وحدها وبعمود `price`
 * خامًا وبالريال العماني مثبَّتًا في القالب. وثلاثتها تُقرأ عند زبونٍ لا عند
 * موظّف: يرى ورقَ التغليف بضاعةً، ويرى سعرًا لا يدفعه أحد، ويرى عملةً ليست
 * عملة المحلّ الذي يشتري منه.
 */
class StorePicksTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    private Product $bouquet;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');

        $this->business = Business::create([
            'name' => 'ورد الخوير', 'type' => 'محل ورود', 'status' => 'نشط', 'phone' => '91234567',
        ]);
        JobTitle::create(['business_id' => $this->business->id, 'name' => 'مدير', 'role' => 'admin']);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->bouquet = Product::create([
            'business_id' => $this->business->id, 'name' => 'بوكيه ورد',
            'price' => 12.5, 'cost' => 5, 'quantity' => 10, 'active' => true,
        ]);

        $this->business->forceFill(['site_slug' => 'ward-alkhuwair'])->save();
        MarketingSettings::save($this->business->id, 'website', ['store_on' => '1']);
    }

    private function page()
    {
        return $this->get(route('store.show', 'ward-alkhuwair'));
    }

    /* ------------------------------ ما يُعرض ------------------------------ */

    /**
     * الهجرة لا تُفرغ متجرًا يعمل.
     *
     * عمودٌ افتراضُه «مخفيّ» يُفرغ كلَّ متجرٍ مفتوحٍ لحظةَ تشغيله — والمقبض
     * يُضاف ليُخفي من أراد، لا ليُطفئ ما يعمل.
     */
    public function test_what_was_already_shown_stays_shown(): void
    {
        $this->assertTrue($this->bouquet->fresh()->published);

        $this->page()->assertOk()->assertSee('بوكيه ورد');
    }

    /**
     * وما أخفاه صاحبُه لا يراه زبون.
     *
     * وهذا هو الباب كلُّه: في جرد كلّ محلٍّ ورقُ تغليفٍ ومكوّناتُ باقاتٍ
     * وأسعارُ جملة، وكانت تُعرض جميعًا.
     */
    public function test_a_hidden_product_never_reaches_the_page(): void
    {
        $wrap = Product::create([
            'business_id' => $this->business->id, 'name' => 'ورق تغليف',
            'price' => 0.2, 'cost' => 0.1, 'quantity' => 500, 'active' => true, 'published' => false,
        ]);

        $this->page()->assertOk()->assertDontSee('ورق تغليف');
        $this->assertFalse($wrap->fresh()->published);
    }

    /** وقسمٌ كلُّ أصنافه مخفيّة لا يبقى تبويبًا يفتح على فراغ */
    public function test_a_category_left_empty_by_hiding_is_not_offered(): void
    {
        $raw = Category::create(['business_id' => $this->business->id, 'name' => 'مواد خام']);
        Product::create([
            'business_id' => $this->business->id, 'category_id' => $raw->id, 'name' => 'شريط',
            'price' => 1, 'cost' => 1, 'quantity' => 5, 'active' => true, 'published' => false,
        ]);

        $this->page()->assertOk()->assertDontSee('مواد خام');
    }

    public function test_an_inactive_product_stays_out_even_when_shown(): void
    {
        $this->bouquet->update(['active' => false, 'published' => true]);

        $this->page()->assertOk()->assertDontSee('بوكيه ورد');
    }

    /* --------------------------- اختيارُ التاجر --------------------------- */

    public function test_the_merchant_hides_and_shows_in_bulk(): void
    {
        $this->actingAs($this->owner)
            ->post(route('admin.marketing.store.products'), ['all' => true, 'published' => false])
            ->assertSessionHasNoErrors();
        $this->assertFalse($this->bouquet->fresh()->published);

        $this->actingAs($this->owner)
            ->post(route('admin.marketing.store.products'), ['all' => true, 'published' => true]);
        $this->assertTrue($this->bouquet->fresh()->published);
    }

    /** ولا يمسّ التاجرُ أصنافَ غيره ولو أرسل معرّفاتها */
    public function test_hiding_by_id_never_reaches_another_business(): void
    {
        $other = Business::create(['name' => 'متجر آخر', 'type' => 'عام', 'status' => 'نشط']);
        $theirs = Product::create([
            'business_id' => $other->id, 'name' => 'صنف الجيران',
            'price' => 3, 'cost' => 1, 'quantity' => 5, 'active' => true, 'published' => true,
        ]);

        $this->actingAs($this->owner)
            ->post(route('admin.marketing.store.products'), ['ids' => [$theirs->id], 'published' => false])
            ->assertSessionHasNoErrors();

        $this->assertTrue($theirs->fresh()->published);
    }

    /** والعدّ في الشاشة يقول ما يُعرض فعلًا — لا كلَّ ما هو فعّال */
    public function test_the_counter_counts_what_is_actually_shown(): void
    {
        Product::create([
            'business_id' => $this->business->id, 'name' => 'ورق تغليف',
            'price' => 0.2, 'cost' => 0.1, 'quantity' => 5, 'active' => true, 'published' => false,
        ]);

        $store = $this->actingAs($this->owner)->get(route('admin.settings.index'))
            ->assertOk()->viewData('page')['props']['store'];

        $this->assertSame(1, $store['productCount']);
        $this->assertCount(2, $store['products']);
    }

    /* ------------------------------ بكم يُعرض ------------------------------ */

    /**
     * ذو المقاسات لا يُعرض بسعر عموده.
     *
     * «من له مقاسات لا يُباع بنفسه: السعر يأتي من المقاس المختار» — نصُّ
     * `ProductVariant`. فعرضُ العمود يُري الزبون رقمًا يطلب عليه ثمّ يُقال
     * له غيرُه.
     */
    public function test_a_product_with_sizes_shows_the_lowest_size_price(): void
    {
        $this->bouquet->update(['price' => 7.1]);

        foreach ([['وسط', 18.0], ['كبير', 25.0]] as [$name, $price]) {
            ProductVariant::create([
                'business_id' => $this->business->id, 'product_id' => $this->bouquet->id,
                'name' => $name, 'price' => $price, 'active' => true, 'sort_order' => 0,
            ]);
        }

        $this->page()->assertOk()
            ->assertSee('18.000', false)
            ->assertSee('من', false)
            ->assertDontSee('7.100', false);
    }

    /** وذو الخصم يُعرض بسعره بعد الخصم — وإلّا وعدَ بثمنٍ ثمّ خالفه */
    public function test_an_item_discount_reaches_the_page(): void
    {
        $this->bouquet->update(['price' => 20, 'discount' => 25]);

        $this->page()->assertOk()->assertSee('15.000', false)->assertDontSee('20.000', false);
    }

    /**
     * والعملة عملةُ المحلّ لا الريال العماني مثبَّتًا.
     *
     * كان القالب يكتب `number_format($p, 3).' ر.ع'` ورابطُ الطلب مثلَه، فمتجرٌ
     * في الإمارات يعرض أسعاره بالريال العماني على صفحةٍ يفتحها زبونُه.
     */
    public function test_the_page_speaks_the_shops_own_currency(): void
    {
        Currency::create([
            'business_id' => $this->business->id, 'code' => 'AED', 'name' => 'درهم',
            'symbol' => 'د.إ', 'rate' => 1, 'is_base' => true, 'active' => true,
        ]);

        $this->page()->assertOk()
            ->assertSee('د.إ', false)
            ->assertDontSee('ر.ع', false)
            // درهمان بخانتين لا ثلاث
            ->assertSee('12.50', false);
    }
}
