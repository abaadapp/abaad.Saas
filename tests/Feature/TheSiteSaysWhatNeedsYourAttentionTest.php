<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Category;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\CustomerPaymentLink;
use App\Models\PaymentGateway;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Support\Ledger;
use App\Support\MarketingSettings;
use App\Support\Store\SiteAttention;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * «ما يحتاج انتباهك في موقعك» — ما ينقص الآن، وأين يُصلح.
 *
 * ═══ وما يُحرَس ═══
 *
 *   - متجرٌ مكتمل: القائمةُ فارغة — ولا بندَ يُخترع.
 *   - منتجٌ منشورٌ بلا صورة، وفئةٌ ظاهرةٌ ببطاقةٍ مخطّطة، واسمٌ بلا إنجليزيّ،
 *     وروابطُ دفعٍ ينقصها مفتاحٌ أو رُدّ دفعُها — كلٌّ ببنده وبابِه.
 *   - المخفيُّ والمطفأُ والفارغُ لا يُعدّ: ما لا يراه الزبونُ لا ينقص الموقع.
 *   - البندُ لمن يملك إصلاحَه، وما لمتجرٍ آخر لا يظهر أبدًا.
 */
class TheSiteSaysWhatNeedsYourAttentionTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = $this->shop('RIBBON', 'ribbon', 'ribbon');
        $this->owner = User::create(['business_id' => $this->shop->id, 'name' => 'سعود', 'email' => 'saud@abaad.om', 'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط']);
        MarketingSettings::save($this->shop->id, 'website', ['store_on' => '1']);
    }

    private function shop(string $name, string $slug, ?string $theme): Business
    {
        $shop = Business::create([
            'name' => $name, 'type' => 'محل ورد', 'status' => 'نشط', 'phone' => '96895259066',
            'city' => 'مسقط', 'site_slug' => $slug, 'tier' => 'gold', 'storefront_theme' => $theme,
        ]);
        Currency::create(['business_id' => $shop->id, 'code' => 'OMR', 'name' => 'ريال عماني', 'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);
        Ledger::seedChart($shop->id);
        Branch::create(['business_id' => $shop->id, 'name' => 'الخوير']);
        Setting::create(['business_id' => $shop->id, 'key' => 'vat_enabled', 'value' => '0']);

        return $shop;
    }

    /** فئةٌ مكتملة — صورةُ بطاقة واسمٌ إنجليزيّ */
    private function category(string $name, array $over = [], ?Business $shop = null): Category
    {
        return Category::create($over + [
            'business_id' => ($shop ?? $this->shop)->id, 'name' => $name, 'name_en' => 'EN '.$name, 'card_image' => '/storage/website/1/c.jpg',
        ]);
    }

    /** منتجٌ مكتملٌ منشور — صورةٌ واسمٌ إنجليزيّ */
    private function product(string $name, Category $category, array $over = []): Product
    {
        return Product::create($over + [
            'business_id' => $category->business_id, 'name' => $name, 'name_en' => 'EN '.$name, 'image' => 'products/'.md5($name).'.jpg',
            'price' => 10, 'category_id' => $category->id, 'cost' => 4, 'quantity' => 5, 'alert_qty' => 1, 'active' => true, 'published' => true,
        ]);
    }

    /** @return array<string, array<string, mixed>> */
    private function attention(?User $user = null): array
    {
        $props = $this->actingAs($user ?? $this->owner)->get(route('admin.website.index'))->assertOk()->viewData('page')['props'];

        return collect($props['attention'])->keyBy('key')->all();
    }

    public function test_a_complete_shop_has_nothing_to_say(): void
    {
        $this->product('باقة الربيع', $this->category('باقات'));

        $this->assertSame([], $this->attention());
    }

    public function test_a_published_product_without_a_photo_is_named_with_its_page(): void
    {
        $roses = $this->category('باقات');
        $bare = $this->product('باقة بلا صورة', $roses, ['image' => null]);
        $this->product('مطفأ بلا صورة', $roses, ['image' => null, 'active' => false]);
        $this->product('مخفي بلا صورة', $roses, ['image' => null, 'published' => false]);

        $item = $this->attention()['photos'];

        $this->assertSame('منتجات منشورة بلا صورة: 1', $item['title']);
        $this->assertSame([['name' => 'باقة بلا صورة', 'href' => route('admin.products.edit', $bare->id)]], $item['items']);
        $this->assertSame(0, $item['more']);
    }

    /** وما زاد على خمسة يُعدّ ولا يُسرد */
    public function test_only_five_names_are_listed_and_the_rest_counted(): void
    {
        $roses = $this->category('باقات');
        foreach (range(1, 7) as $n) {
            $this->product('صنف '.$n, $roses, ['image' => '']);
        }

        $item = $this->attention()['photos'];

        $this->assertCount(SiteAttention::NAMES, $item['items']);
        $this->assertSame(2, $item['more']);
        $this->assertSame('منتجات منشورة بلا صورة: 7', $item['title']);
    }

    /** البطاقةُ المخطّطة — بقاعدة الرئيسيّة: فيها بضاعة، وظاهرة، والقسمُ مرفوع */
    public function test_a_shown_category_card_without_a_picture_leads_to_the_editor_row(): void
    {
        $bare = $this->category('منتجات', ['card_image' => null]);
        $this->product('صنف', $bare);
        $this->category('مخفيّة', ['card_image' => null, 'card_hidden' => true]);
        $this->product('صنف مخفيّة البطاقة', Category::where('name', 'مخفيّة')->first());
        $this->category('فارغة', ['card_image' => null]);

        $item = $this->attention()['card_images'];

        $this->assertSame([['name' => 'منتجات', 'href' => null]], $item['items']);
        $this->assertSame(route('admin.website.editor', ['row' => 'cats']), $item['action']['href']);

        // والقسمُ مُطفأٌ في ترتيب الصفحة: لا بطاقاتٍ أصلًا
        MarketingSettings::save($this->shop->id, 'website', ['store_sections' => 'best,new']);
        $this->assertArrayNotHasKey('card_images', $this->attention());
    }

    public function test_names_without_english_are_counted_from_both_categories_and_products(): void
    {
        $roses = $this->category('باقات', ['name_en' => null]);
        $this->product('باقة', $roses, ['name_en' => '']);
        $this->product('مكتمل', $roses);

        $item = $this->attention()['english'];

        $this->assertSame('أسماء بلا ترجمة إنجليزية: 2', $item['title']);
        $this->assertSame(['باقات', 'باقة'], array_column($item['items'], 'name'));
    }

    /** وصورُ البطاقات والأسماءُ الإنجليزيّة لقالبٍ يرسمهما — لا لكلّ متجر */
    public function test_a_shop_without_the_ribbon_theme_is_not_asked_for_cards_or_english(): void
    {
        $plain = $this->shop('عادي', 'plain', null);
        $cat = $this->category('عامّ', ['card_image' => null, 'name_en' => null], $plain);
        $this->product('صنف', $cat, ['name_en' => null]);
        $owner = User::create(['business_id' => $plain->id, 'name' => 'م', 'email' => 'p@abaad.om', 'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط']);

        $this->assertSame([], SiteAttention::for($plain, $owner));
    }

    public function test_payment_links_say_when_the_key_is_missing_and_when_a_link_needs_a_look(): void
    {
        $this->product('صنف', $this->category('باقات'));
        $gateway = PaymentGateway::create([
            'business_id' => $this->shop->id, 'provider' => PaymentGateway::PAYMOB, 'active' => true,
            'public_key' => 'pk_x', 'secret_key' => 'sk_x', 'hmac_secret' => 'h', 'card_integration_id' => '111',
        ]);

        $off = $this->attention()['links_off'];
        $this->assertSame(route('admin.integrations.paymob'), $off['action']['href']);

        $gateway->update(['api_key' => 'key', 'mode' => PaymentGateway::LIVE]);
        $this->assertSame([], $this->attention(), 'روابطُ مفعّلةٌ بلا مشكلة قيل عنها شيء');

        $customer = Customer::create(['business_id' => $this->shop->id, 'name' => 'عميل', 'phone' => '91234567']);
        CustomerPaymentLink::create([
            'business_id' => $this->shop->id, 'customer_id' => $customer->id, 'reference' => 'pay-x', 'amount' => 5, 'currency' => 'OMR',
            'status' => CustomerPaymentLink::PENDING, 'is_live' => true, 'error' => 'Declined',
        ]);

        $trouble = $this->attention()['links_trouble'];
        $this->assertSame('روابط دفع تحتاج نظرة: 1', $trouble['title']);
        $this->assertSame(route('admin.finance.paymentLinks'), $trouble['action']['href']);
    }

    /** ومن لا Paymob عنده لا يُسأل عن روابط الدفع */
    public function test_no_paymob_no_payment_link_item(): void
    {
        $this->product('صنف', $this->category('باقات'));

        $this->assertArrayNotHasKey('links_off', $this->attention());
    }

    /** البندُ لمن يملك إصلاحَه — من لا يملك «المنتجات» لا يُدلّ على صفحةٍ مغلقةٍ عليه */
    public function test_items_are_only_for_who_can_fix_them(): void
    {
        $this->product('بلا صورة', $this->category('باقات'), ['image' => null]);
        $web = User::create(['business_id' => $this->shop->id, 'name' => 'موظّف الموقع', 'email' => 'w@abaad.om', 'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط', 'permissions' => ['website']]);

        $this->assertSame(['photos'], array_column(SiteAttention::for($this->shop, $this->owner), 'key'));
        $this->assertSame([], SiteAttention::for($this->shop, $web));
    }

    /** وما لمتجرٍ آخر لا يظهر — ولا يُعدّ */
    public function test_another_shops_gaps_never_appear(): void
    {
        $this->product('مكتمل', $this->category('باقات'));
        $other = $this->shop('غريب', 'stranger', 'ribbon');
        $theirs = $this->category('فئتهم', ['card_image' => null, 'name_en' => null], $other);
        $this->product('صنفهم', $theirs, ['image' => null, 'name_en' => null]);

        $this->assertSame([], $this->attention());
    }
}
