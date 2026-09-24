<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Category;
use App\Models\Currency;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Support\Ledger;
use App\Support\MarketingSettings;
use App\Support\Store\StoreReadiness;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * دليلُ تجهيز المتجر — يقول لصاحبه أين هو، فيضبطه بيده.
 *
 * ═══ وما يُحرَس ═══
 *
 * أنّ كلَّ صفٍّ يُقاس بالمصدر الذي يقرؤه المتجرُ **فعلًا**. فقائمةٌ تقول
 * «تمّ» على ما لا أثرَ له أسوأُ من لا قائمة: يطمئنّ صاحبُها ويبقى متجره
 * معطوبًا، ولا يعود ينظر فيها.
 */
class AShopOwnerIsToldWhereHeStandsTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2027-02-10 10:00:00');

        $this->shop = Business::create([
            'name' => 'RIBBON', 'type' => 'محل ورد', 'status' => 'نشط', 'phone' => '',
            'city' => 'مسقط', 'tier' => 'gold', 'storefront_theme' => 'ribbon',
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

    /** @return array<string, array<string, mixed>> */
    private function steps(): array
    {
        $out = [];
        foreach (StoreReadiness::steps($this->shop->refresh()) as $s) {
            $out[$s['key']] = $s;
        }

        return $out;
    }

    private function done(string $key): bool
    {
        return (bool) ($this->steps()[$key]['done'] ?? false);
    }

    private function set(array $over): void
    {
        MarketingSettings::save($this->shop->id, 'website', $over);
    }

    private function sell(): Product
    {
        $cat = Category::create(['business_id' => $this->shop->id, 'name' => 'باقات']);

        return Product::create([
            'business_id' => $this->shop->id, 'name' => 'باقة ورد', 'price' => 10, 'category_id' => $cat->id,
            'cost' => 4, 'quantity' => 5, 'alert_qty' => 1, 'active' => true, 'published' => true,
        ]);
    }

    /* ═══════════ كلُّ صفٍّ يُقاس بمصدره ═══════════ */

    /** متجرٌ جديد: لا شيءَ تمّ، ويُقال له كم بقي */
    public function test_a_fresh_shop_is_told_nothing_is_done(): void
    {
        $steps = $this->steps();

        $this->assertNotSame([], $steps);
        foreach (['slug', 'products', 'phone', 'published'] as $key) {
            $this->assertFalse($steps[$key]['done'], "قيل «تمّ» على {$key} ولم يتمّ");
        }

        /*
         * و«الدفع عند الاستلام» مفتوحٌ في متجرٍ جديد — فالصفُّ تامٌّ بحقّ.
         *
         * وهو ما يقوله المصدر: `WebCheckout::settings` تقرأ الفراغَ «نعم»
         * لهذا المفتاح وحده. وقائمةٌ تقول «لم يتمّ» عن شيءٍ يعمل تُرسل
         * صاحبَها إلى مقبضٍ لا شيءَ فيه ليُصلحه.
         */
        $this->assertTrue($steps['pay']['done']);
        $this->assertFalse(StoreReadiness::ready($this->shop));
    }

    /** والعنوانُ يُقرأ من العمود الذي يفتح به الزبونُ المتجر */
    public function test_the_address_is_read_where_the_visitor_opens_it(): void
    {
        $this->assertFalse($this->done('slug'));

        $this->shop->forceFill(['site_slug' => 'ribbon'])->save();

        $this->assertTrue($this->done('slug'));
    }

    /**
     * و«صنفٌ معروض» يعني المعروضَ لا المُدخَل.
     *
     * صنفٌ في القاعدة وهو مخفيٌّ لا يراه زبون — وقائمةٌ تعدّه تقول «متجرك
     * فيه بضاعة» عن صفحةٍ تُفتح خالية.
     */
    public function test_a_hidden_product_does_not_count_as_shown(): void
    {
        $p = $this->sell();
        $this->assertTrue($this->done('products'));

        $p->update(['published' => false]);
        $this->assertFalse($this->done('products'), 'عُدّ صنفٌ مخفيٌّ بضاعةً معروضة');

        $p->update(['published' => true, 'active' => false]);
        $this->assertFalse($this->done('products'), 'عُدّ صنفٌ موقوفٌ بضاعةً معروضة');
    }

    /**
     * وطريقةُ الدفع تُقرأ ممّا يعرضه المتجرُ فعلًا — لا من مفتاحٍ في الإعدادات.
     *
     * فبوّابةُ البطاقة طريقةٌ ثالثة لا مفتاحَ لها في `settings` (انظر
     * `WebCheckout::payments`). وقائمةٌ تقرأ المفتاحين وحدهما تقول «لا دفع»
     * لمتجرٍ يقبض بالبطاقة.
     */
    public function test_payment_is_read_from_what_the_store_offers(): void
    {
        // والمتجرُ الجديد يقبل النقدَ عند الاستلام — فيُطفأ ليُقاس الصفّ
        $this->set(['store_pay_cod' => '0', 'store_pay_transfer' => '0']);
        $this->assertFalse($this->done('pay'));

        $this->set(['store_pay_transfer' => '1']);
        $this->assertTrue($this->done('pay'));
    }

    /** والهاتفُ من بيانات النشاط — وهو ما يُطبع في تذييل المتجر */
    public function test_the_phone_is_read_from_the_business(): void
    {
        $this->assertFalse($this->done('phone'));

        $this->shop->forceFill(['phone' => '95259066'])->save();

        $this->assertTrue($this->done('phone'));
    }

    /* ═══════════ واللازمُ غيرُ المستحسن ═══════════ */

    /**
     * «جاهز» تعني اللازمَ وحده.
     *
     * ولولا الفصلُ لبقيت الشارةُ حمراءَ أبدًا — متجرٌ بلا نبذةٍ يبيع، ومتجرٌ
     * بلا عنوانٍ لا يُفتح. وقائمةٌ حمراءُ دائمًا لا تُقرأ.
     */
    public function test_ready_means_the_required_alone(): void
    {
        $this->sell();
        $this->shop->forceFill(['site_slug' => 'ribbon', 'phone' => '95259066'])->save();
        $this->set(['store_on' => '1', 'store_pay_cod' => '1']);

        $this->assertTrue(StoreReadiness::ready($this->shop->refresh()));

        // ومستحسنٌ لم يتمّ لا يُبطل الجاهزيّة
        $this->assertFalse($this->done('about'));
        $this->assertFalse($this->done('hero'));
    }

    /* ═══════════ ولا يُسأل عمّا لا يعنيه ═══════════ */

    /**
     * بياناتُ الحساب البنكيّ لا تُطلب ممّن لا يقبل التحويل.
     *
     * وسؤالٌ بلا جوابٍ صحيحٍ لمن لا يعنيه يبقى معلّقًا أبدًا، فيُقرأ عيبًا
     * في النظام لا خطوةً تُتمّ.
     */
    public function test_a_step_that_does_not_apply_is_not_asked(): void
    {
        $this->set(['store_pay_transfer' => '0']);
        $this->assertArrayNotHasKey('bank', $this->steps());

        $this->set(['store_pay_transfer' => '1']);
        $this->assertArrayHasKey('bank', $this->steps());
        $this->assertFalse($this->done('bank'));
    }

    /** ومناطقُ التوصيل لا تُطلب ممّن لا يوصّل */
    public function test_delivery_areas_are_not_asked_of_a_pickup_only_shop(): void
    {
        $this->set(['store_fulfil' => 'pickup']);
        $this->assertArrayNotHasKey('areas', $this->steps());

        $this->set(['store_fulfil' => 'pickup,delivery']);
        $this->assertArrayHasKey('areas', $this->steps());
    }

    /* ═══════════ والشاشةُ تقرؤها قبل النشر ═══════════ */

    /**
     * ═══ وتُعرض لمتجرٍ لم يُنشر بعد ═══
     *
     * وهي اللحظةُ التي تُقرأ فيها: من يجهّز متجره لم ينشره بعد. وشرطٌ
     * يقول «يُخدم الآن» يُخفي الدليلَ عمّن يحتاجه — ويُخفي معه «صفحة
     * متجرك»، فلا يستطيع ترتيبَ واجهةٍ قبل أن يفتحها ولا يعرف لمَ اختفت.
     */
    public function test_the_guide_shows_before_the_shop_is_published(): void
    {
        $props = $this->actingAs($this->owner)
            ->get(route('admin.settings.index', ['section' => 'website']))
            ->assertOk()->viewData('page')['props'];

        $this->assertSame('none', $props['store']['serves'], 'المتجرُ منشورٌ فسقط معنى الاختبار');
        $this->assertNotNull($props['store']['readiness'], 'اختفى الدليلُ عن متجرٍ يُجهَّز');
        $this->assertNotEmpty($props['store']['readiness']);
    }

    /** ومن لا يلبس الواجهةَ لا دليلَ له — مقبضٌ لا يُحرّك شيئًا */
    public function test_a_shop_without_the_theme_has_no_guide(): void
    {
        $this->shop->forceFill(['storefront_theme' => null])->save();

        $props = $this->actingAs($this->owner)
            ->get(route('admin.settings.index', ['section' => 'website']))
            ->assertOk()->viewData('page')['props'];

        $this->assertNull($props['store']['readiness']);
    }

    /**
     * ═══ ولا تُحجب بطاقةُ ضبطٍ خلف «يُخدم الآن» ═══
     *
     * `serves === 'theme'` تعني «يُخدم على عنوانه **الآن**» — لا «يلبس
     * الواجهة». فبطاقةٌ تُشترط بها تختفي عن متجرٍ لم يُنشر بعد، أي عن
     * صاحبها في اللحظة التي يجهّز فيها صفحته. فلا يستطيع ترتيبَ واجهةٍ
     * قبل أن يفتحها، ولا شيءَ يقول له لمَ اختفت البطاقة.
     *
     * وقد وقع ذلك فعلًا في «صفحة متجرك». وشرطُ البطاقات `store.checkout`
     * — وهي تسأل عن لبس الواجهة (انظر `Website\Commerce::checkout`).
     *
     * والحارسُ يقرأ الشاشةَ نفسَها لأنّ الشرطَ فيها لا في الخادم: خاصيّةٌ
     * صحيحةٌ تصل شاشةً تحجبها لا يكشفها اختبارُ خصائص.
     */
    public function test_no_setting_card_hides_behind_being_served_now(): void
    {
        $screen = file_get_contents(resource_path('js/Pages/Admin/Settings/Index.tsx'));

        /*
         * والمرفوضُ شرطٌ **مجرَّد**: `store.serves === 'theme' &&`.
         *
         * أمّا `serves === 'theme' || context?.storefrontTheme` فتقرأ
         * الحالين معًا — وهي ما يُكتب حين يُقصد «يلبسها أو يُخدم بها».
         */
        $this->assertSame(
            0,
            preg_match_all("/store\\.serves === 'theme' &&/", (string) $screen),
            'بطاقةٌ في الإعدادات تُشترط بـ«يُخدم الآن» — فتختفي عمّن يجهّز متجره قبل نشره',
        );
    }
}
