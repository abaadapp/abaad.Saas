<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Product;
use App\Models\RecipeItem;
use App\Models\Setting;
use App\Models\User;
use App\Models\Website;
use App\Support\PaymentMethods;
use App\Support\Website\Blueprints;
use App\Support\Website\Builder;
use App\Support\Website\Commerce;
use App\Support\Website\Preview;
use App\Support\Website\Readiness;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * الموقع يقول ما في المحلّ — لا ما في الجرد.
 *
 * ═══ ثلاثةُ أكاذيبَ كانت تُقال، وكلُّها بحسن نيّة ═══
 *
 * ١) **صنفٌ أخفاه صاحبُه كان يُعرض.** عمودُ `published` أُضيف ليُخفي أوراقَ
 *    التغليف ومكوّناتِ الباقات وأصنافَ الجملة، وتقرؤه الصفحةُ البسيطة. أمّا
 *    الموقعُ المبنيّ — وهو الذي يُعرض على العنوان نفسه إن نُشر — فكان يقرأ
 *    `active` وحدها. فمن أخفى صنفًا رآه في موقعه ولم يفهم لماذا لم يُطعه
 *    المقبض.
 *
 * ٢) **صنفٌ نفد كان يُعرض بزرّ «اطلب».** وذلك الزرّ ادّعاءُ توفّر: الزبون
 *    يكتب رسالةً فيُجاب «انتهى» — خذلانٌ يقع بعد أن قرّر الشراء.
 *
 * ٣) **«البطاقة مفعّلة»** كانت تُقرأ من إعدادات نقطة البيع، وهي تصف ما يأخذه
 *    الكاشير على المنضدة. ولا بوّابةَ دفعٍ في أبعاد، فالموقع لا يقبض شيئًا.
 *
 * وهذا الملفّ يحرس الثلاثة. وكلُّها تنكسر صامتةً: الموقعُ يُرسم، والشاشةُ
 * خضراء، ولا يكتشفها إلّا زبونٌ خُذل — وهو لا يعود ليُخبر.
 */
class TheSiteShowsWhatTheShopActuallyHasTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create([
            'name' => 'ورود مسقط', 'type' => 'محل ورود', 'status' => 'نشط', 'phone' => '96890000000',
        ]);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->actingAs($this->owner);
    }

    private function product(array $attrs = []): Product
    {
        return Product::create($attrs + [
            'business_id' => $this->business->id,
            'name' => 'باقة ورد',
            'price' => 12.5,
            'active' => true,
            'published' => true,
            'quantity' => 5,
        ]);
    }

    private function site(): Website
    {
        return Builder::create($this->business, Blueprints::STORE, 'modern', $this->owner->id);
    }

    /** أسماءُ ما يعرضه الموقع فعلًا */
    private function onSite(): array
    {
        return array_column(Preview::document($this->site())['data']['products'], 'name');
    }

    /* ==================== ١ · ما أخفاه صاحبُه لا يُعرض ==================== */

    public function test_a_product_the_owner_hid_is_not_on_the_site(): void
    {
        $this->product(['name' => 'باقة معروضة']);
        $this->product(['name' => 'ورق تغليف', 'published' => false]);

        $this->assertSame(['باقة معروضة'], $this->onSite());
    }

    /** والمطفأُ في نقطة البيع لا يُعرض كذلك — وهما شرطان لا شرط */
    public function test_a_product_switched_off_at_the_counter_is_not_on_the_site(): void
    {
        $this->product(['name' => 'باقة معروضة']);
        $this->product(['name' => 'صنف موقوف', 'active' => false]);

        $this->assertSame(['باقة معروضة'], $this->onSite());
    }

    /* ==================== ٢ · وما نفد لا يُعرض ==================== */

    public function test_a_sold_out_product_leaves_the_site(): void
    {
        $this->product(['name' => 'باقة متوفّرة']);
        $this->product(['name' => 'باقة نفدت', 'quantity' => 0]);

        $this->assertSame(['باقة متوفّرة'], $this->onSite());
    }

    /**
     * وذو الوصفة لا يُحكم عليه بكمّيته.
     *
     * مخزونُه مكوّناتُه، وكمّيتُه هو تبقى صفرًا أبدًا. والحكمُ عليها كان
     * سيُفرغ موقعَ محلّ الورود من الباقات كلِّها — وهي بضاعتُه.
     */
    public function test_a_recipe_product_is_not_judged_by_its_own_quantity(): void
    {
        $rose = $this->product(['name' => 'وردة', 'quantity' => 100]);
        $bouquet = $this->product(['name' => 'باقة مركّبة', 'quantity' => 0]);

        RecipeItem::create([
            'business_id' => $this->business->id,
            'product_id' => $bouquet->id,
            'component_product_id' => $rose->id,
            'quantity' => 12,
        ]);

        $this->assertContains('باقة مركّبة', $this->onSite());
    }

    /** ومتجرٌ يأذن بالبيع تحت الصفر لا نفادَ عنده — إعدادُ نقطة البيع نفسُه */
    public function test_a_shop_that_sells_below_zero_hides_nothing(): void
    {
        Setting::create([
            'business_id' => $this->business->id,
            'key' => 'allow_negative_stock', 'value' => '1',
        ]);

        $this->product(['name' => 'باقة نفدت', 'quantity' => 0]);

        $this->assertSame(['باقة نفدت'], $this->onSite());
    }

    /** والسعرُ حيٌّ لا مجمَّد: ما يُغيَّر في النظام يظهر بلا إعادة نشر */
    public function test_the_price_on_the_site_is_the_price_in_the_system(): void
    {
        $product = $this->product(['price' => 12.5]);
        $site = $this->site();

        $this->assertSame(12.5, Preview::document($site)['data']['products'][0]['final']);

        $product->update(['price' => 19.0]);

        $this->assertSame(19.0, Preview::document($site->fresh())['data']['products'][0]['final']);
    }

    /** ولا يُقرأ جردُ الجار: كلُّ استعلامٍ مقيَّدٌ بمتجره */
    public function test_a_neighbour_shelf_is_never_read(): void
    {
        $this->product(['name' => 'باقتي']);

        $other = Business::create(['name' => 'الجار', 'type' => 'عام', 'status' => 'نشط']);
        Product::create([
            'business_id' => $other->id, 'name' => 'باقة الجار',
            'price' => 9, 'active' => true, 'published' => true, 'quantity' => 9,
        ]);

        $this->assertSame(['باقتي'], $this->onSite());
    }

    /* ==================== ٣ · والدفع يُقال بحقيقته ==================== */

    /**
     * «مفعّلة في نقطة البيع» ليست «تُقبض على الموقع».
     *
     * وبينهما بوّابةُ دفعٍ مربوطةٌ ومتحقَّق منها — ولا بوّابةَ في أبعاد.
     */
    public function test_a_card_enabled_at_the_counter_is_not_called_online_ready(): void
    {
        $this->site();

        $card = collect(Commerce::payments($this->business->id))
            ->firstWhere('label', __(PaymentMethods::CARD));

        $this->assertTrue($card['pos'], 'الغياب إذنٌ — فالبطاقة مأذونةٌ في نقطة البيع');
        $this->assertFalse($card['online'], 'قيل إنّ الموقع يقبض البطاقة ولا بوّابة له');
        $this->assertNotSame('', $card['note'], 'قيل «غير متاحة» بلا سبب');
    }

    /** وما أطفأه التاجر يُقال مطفأً — لا «متاحًا على الموقع» */
    public function test_a_method_switched_off_is_shown_as_off(): void
    {
        Setting::create([
            'business_id' => $this->business->id, 'key' => 'pay_transfer', 'value' => '0',
        ]);

        $transfer = collect(Commerce::payments($this->business->id))
            ->firstWhere('label', __(PaymentMethods::TRANSFER));

        $this->assertFalse($transfer['pos']);
        $this->assertFalse($transfer['online']);
    }

    /** ولا سلّةَ في الموقع — والقناةُ تُقال باسمها */
    public function test_the_order_channel_is_named_for_what_it_is(): void
    {
        $this->assertFalse(Commerce::checkout($this->business->id), 'ادُّعيت سلّةٌ لا وجود لها');
        $this->assertSame(Commerce::CHANNEL_WHATSAPP, Commerce::channel($this->business->id));

        // ومن لا رقمَ له لا زرَّ طلبٍ عنده أصلًا
        $this->business->forceFill(['phone' => ''])->save();

        $this->assertSame(Commerce::CHANNEL_NONE, Commerce::channel($this->business->id));
    }

    /* ==================== ٤ · والجاهزيةُ تُقاس ==================== */

    public function test_readiness_counts_what_the_visitor_actually_sees(): void
    {
        $this->product(['name' => 'ظاهرة']);
        $this->product(['name' => 'نفدت', 'quantity' => 0]);
        $this->product(['name' => 'مخفيّة', 'published' => false]);

        $this->assertSame(1, Readiness::shown($this->business->id));

        $facts = collect(Readiness::check($this->site()))->keyBy('key');

        $this->assertTrue($facts['products']['ok']);
        // والنطاقُ اختياريّ: لأبعادَ عنوانٌ يعمل من اليوم الأوّل
        $this->assertTrue($facts['address']['optional']);
        // والدفعُ الإلكتروني لا يُعدّ نقصًا يُطالَب به التاجر — لا بوّابةَ بعد
        $this->assertFalse($facts['payment']['ok']);
        $this->assertTrue($facts['payment']['optional']);
    }

    /** ومتجرٌ فارغُ الرفّ يُقال له ذلك قبل أن ينشر لا بعده */
    public function test_an_empty_shelf_is_reported_before_publishing(): void
    {
        $this->product(['name' => 'نفدت', 'quantity' => 0]);

        $facts = collect(Readiness::check($this->site()))->keyBy('key');

        $this->assertFalse($facts['products']['ok']);
    }
}
