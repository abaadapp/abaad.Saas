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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * المعاينةُ تُري ما لم يُحفظ بعد — ولا تمسّ ما هو منشور.
 *
 * ═══ العطبُ الذي وُضعت لأجله ═══
 *
 * كانت المعاينةُ تُصيّر المحفوظ وحدَه: يكتب صاحبُ المحلّ عنوانًا في المحرّر
 * فلا يراه حتّى يضغط «حفظ» — والحفظُ يبلغ زبونَه في اللحظة نفسِها. فلا
 * معاينةَ قبل التطبيق أصلًا: التطبيقُ كان شرطَ المعاينة، وهو ما يجعل التاجر
 * يضبط موقعه أعمى أو ينشر ليرى ثمّ يُطفئ ليُصلح.
 *
 * وأخطرُ ما يُحرس هنا ليس أنّ المعاينة تعمل — بل أنّها **لا تكتب**.
 */
class APreviewShowsWhatIsNotSavedYetTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->setLocale('ar');

        $this->shop = Business::create([
            'name' => 'ريبون', 'type' => 'محل ورد', 'status' => 'نشط', 'phone' => '96895259066',
            'city' => 'مسقط', 'site_slug' => 'ribbon', 'tier' => 'gold', 'storefront_theme' => 'ribbon',
        ]);
        Currency::create(['business_id' => $this->shop->id, 'code' => 'OMR', 'name' => 'ريال عماني', 'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);
        Ledger::seedChart($this->shop->id);
        Branch::create(['business_id' => $this->shop->id, 'name' => 'الخوير']);
        Setting::create(['business_id' => $this->shop->id, 'key' => 'vat_enabled', 'value' => '0']);

        $this->owner = User::create([
            'business_id' => $this->shop->id, 'name' => 'سعود', 'email' => 'saud@abaad.om',
            'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        MarketingSettings::save($this->shop->id, 'website', [
            'store_on' => '1', 'store_pay_cod' => '1',
            'store_headline' => 'المنشورُ القديم',
        ]);

        $cat = Category::create(['business_id' => $this->shop->id, 'name' => 'باقات']);
        Product::create([
            'business_id' => $this->shop->id, 'name' => 'باقة ورد', 'price' => 12, 'category_id' => $cat->id,
            'cost' => 4, 'quantity' => 10, 'active' => true, 'published' => true,
        ]);
    }

    private function preview(array $draft)
    {
        return $this->actingAs($this->owner)->post(route('admin.store.preview'), ['draft' => json_encode($draft)]);
    }

    /* ═══════════ تُري ما لم يُحفظ ═══════════ */

    /** ما كُتب في المحرّر يظهر في المعاينة قبل أن يُحفظ */
    public function test_the_preview_shows_a_headline_that_was_never_saved(): void
    {
        $html = (string) $this->preview(['store_headline' => 'عنوانٌ لم يُحفظ'])->assertOk()->getContent();

        $this->assertStringContainsString('عنوانٌ لم يُحفظ', $html);
        $this->assertStringNotContainsString('المنشورُ القديم', $html);
    }

    /** وما لم يُكتب يبقى على حاله المحفوظ — لا يعود إلى فراغٍ لم يطلبه */
    public function test_what_the_draft_does_not_carry_keeps_its_saved_value(): void
    {
        MarketingSettings::save($this->shop->id, 'website', ['store_tagline' => 'سطرٌ محفوظ']);

        $html = (string) $this->preview(['store_headline' => 'جديد'])->assertOk()->getContent();

        $this->assertStringContainsString('سطرٌ محفوظ', $html);
    }

    /** وهو القالبُ نفسُه لا رسمٌ يشبهه */
    public function test_the_preview_is_the_real_storefront_template(): void
    {
        $html = (string) $this->preview(['store_headline' => 'عنوان'])->assertOk()->getContent();

        $this->assertStringContainsString('data-testid="rb-landing"', $html);
        $this->assertStringContainsString('--rb-olive', $html, 'المعاينةُ بلا متغيّرات القالب — فهي ليست صفحتَه');
    }

    /* ═══════════ ولا تمسّ المنشور ═══════════ */

    /**
     * ولا صفَّ يُكتب في القاعدة — وهو أثقلُ ما يُحرس هنا.
     *
     * تراكبٌ يُكتب في `settings` يصير موقعًا منشورًا بلا أن يضغط أحدٌ «حفظ».
     */
    public function test_previewing_writes_nothing_to_the_database(): void
    {
        $before = DB::table('settings')->where('business_id', $this->shop->id)
            ->orderBy('key')->pluck('value', 'key')->all();

        $this->preview([
            'store_headline' => 'تجربة', 'store_about' => 'نصٌّ يُجرَّب',
            'store_sections' => 'hero', 'store_seo_title' => 'عنوانُ بحثٍ يُجرَّب',
        ])->assertOk();

        $after = DB::table('settings')->where('business_id', $this->shop->id)
            ->orderBy('key')->pluck('value', 'key')->all();

        $this->assertSame($before, $after, 'المعاينةُ كتبت في القاعدة — فالمسوّدةُ نُشرت بلا أن يُطلَب.');
    }

    /** والموقعُ العامُّ يبقى على المحفوظ بينما تُعايَن مسوّدة */
    public function test_the_public_store_is_untouched_by_a_preview(): void
    {
        $this->preview(['store_headline' => 'مسوّدة'])->assertOk();

        $live = (string) $this->get('/s/ribbon')->assertOk()->getContent();

        $this->assertStringContainsString('المنشورُ القديم', $live);
        $this->assertStringNotContainsString('مسوّدة', $live);
    }

    /** ولا تُنشئ المعاينةُ طلبًا ولا حركةً ماليّة */
    public function test_a_preview_creates_no_order_and_no_money(): void
    {
        $this->preview(['store_headline' => 'تجربة'])->assertOk();

        $this->assertSame(0, DB::table('orders')->count());
        $this->assertSame(0, DB::table('transactions')->count());
    }

    /* ═══════════ وحراسةُ الباب ═══════════ */

    /** ولا يُعاين زائرٌ مجهول */
    public function test_a_stranger_cannot_preview(): void
    {
        $this->post(route('admin.store.preview'), ['draft' => json_encode(['store_headline' => 'دخيل'])])
            ->assertRedirect();
    }

    /**
     * ولا يُعاين تاجرٌ متجرَ جاره.
     *
     * والنشاطُ من الجلسة لا من الطلب — فلا معرّفَ يُبدَّل في الرابط.
     */
    public function test_a_neighbour_previews_his_own_shop_not_this_one(): void
    {
        $other = Business::create(['name' => 'جار', 'type' => 'محل', 'status' => 'نشط', 'site_slug' => 'jar', 'phone' => '968', 'tier' => 'gold', 'storefront_theme' => 'ribbon']);
        Currency::create(['business_id' => $other->id, 'code' => 'OMR', 'name' => 'ر', 'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);
        Ledger::seedChart($other->id);
        Branch::create(['business_id' => $other->id, 'name' => 'ف']);
        Setting::create(['business_id' => $other->id, 'key' => 'vat_enabled', 'value' => '0']);
        MarketingSettings::save($other->id, 'website', ['store_on' => '1', 'store_headline' => 'متجرُ الجار']);

        $neighbour = User::create(['business_id' => $other->id, 'name' => 'جار', 'email' => 'n@abaad.om', 'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط']);

        /*
         * ويحقن معرّفَ متجر سعود في الطلب — فيُهمَل: النشاطُ من الجلسة.
         *
         * والمسوّدةُ تمسّ مفتاحًا **غير** العنوان عمدًا: لو كتبت العنوانَ
         * لَغطّت على المتجر المقروء — يُعرض عنوانُ المسوّدة أيًّا كان صاحبُ
         * الصفحة، فيمرّ الحارسُ ولو تسرّب. وهذا وقع فعلًا ونجت به طفرة.
         */
        $html = (string) $this->actingAs($neighbour)
            ->post(route('admin.store.preview'), [
                'draft' => json_encode(['store_tagline' => 'تذييلُ الجار']),
                'bid' => $this->shop->id,
                'business_id' => $this->shop->id,
            ])
            ->assertOk()->getContent();

        $this->assertStringContainsString('تذييلُ الجار', $html, 'مسوّدةُ الجار لم تبلغ معاينته');
        $this->assertStringContainsString('متجرُ الجار', $html, 'الجارُ لا يرى متجرَه');
        $this->assertStringNotContainsString('المنشورُ القديم', $html, 'الجارُ عايَن متجرَ سعود بحقن معرّفٍ في الطلب!');
    }

    /**
     * وما لا يقبله بابُ الحفظ لا يقبله بابُ المعاينة.
     *
     * ويُقاس **أثناء** التصيير لا بعده: التراكبُ يُنزَع بانتهائه، فقياسُه
     * بعده يمرّ دائمًا ولو قُبل كلُّ مفتاح — وهو حارسٌ نجا من طفرته مرّةً
     * لهذا السبب بعينه.
     */
    public function test_a_key_that_is_not_a_website_setting_never_enters(): void
    {
        $seen = MarketingSettings::withOverlay(
            (int) $this->shop->id, 'website',
            ['vat_enabled' => '1', 'store_headline' => 'عنوان', 'ga_measurement_id' => 'G-XXXX'],
            fn () => MarketingSettings::group((int) $this->shop->id, 'website'),
        );

        $this->assertSame('عنوان', $seen['store_headline'], 'المفتاحُ المسموح لم يمرّ');
        $this->assertArrayNotHasKey('vat_enabled', $seen, 'مفتاحٌ ليس من المجموعة دخل المعاينة');
        $this->assertArrayNotHasKey('ga_measurement_id', $seen, 'مفتاحُ مجموعةٍ أخرى دخل المعاينة');
    }

    /** ولا قيمةً مركّبة: مصفوفةٌ في موضع نصٍّ تُطرح ولا تُسقط الصفحة */
    public function test_a_value_that_is_not_text_is_thrown_away(): void
    {
        $seen = MarketingSettings::withOverlay(
            (int) $this->shop->id, 'website',
            ['store_headline' => ['x'], 'store_about' => 'نصّ'],
            fn () => MarketingSettings::group((int) $this->shop->id, 'website'),
        );

        $this->assertSame('المنشورُ القديم', $seen['store_headline']);
        $this->assertSame('نصّ', $seen['store_about']);
    }

    /**
     * ورابطٌ محفوظٌ في متصفّح يفتح المحفوظ لا مسوّدةَ أحد.
     *
     * والمعاينةُ بـ`GET` هي ما يفتحه زرُّ لوحة التشغيل — فتبقى على حالها.
     */
    public function test_a_plain_get_still_previews_what_is_saved(): void
    {
        $html = (string) $this->actingAs($this->owner)->get(route('admin.store.preview'))
            ->assertOk()->getContent();

        $this->assertStringContainsString('المنشورُ القديم', $html);

        // وحتّى لو حُشرت مسوّدةٌ في الرابط — فالروابطُ تُحفظ وتُشارَك
        $withDraft = (string) $this->actingAs($this->owner)
            ->get(route('admin.store.preview').'?draft='.urlencode((string) json_encode(['store_headline' => 'مسوّدةٌ في رابط'])))
            ->assertOk()->getContent();

        $this->assertStringContainsString('المنشورُ القديم', $withDraft);
        $this->assertStringNotContainsString('مسوّدةٌ في رابط', $withDraft, 'رابطٌ مُشارَكٌ عرض مسوّدةً — والروابطُ تُحفظ وتُرسَل.');
    }

    /** ولا تُفهرَس المعاينةُ ولا تُخزَّن */
    public function test_a_preview_is_never_indexed_or_cached(): void
    {
        $res = $this->preview(['store_headline' => 'تجربة'])->assertOk();

        $this->assertStringContainsString('noindex', (string) $res->headers->get('X-Robots-Tag'));
        $this->assertStringContainsString('no-store', (string) $res->headers->get('Cache-Control'));
    }

    /* ═══════════ ولا يتأثّر البانِي ═══════════ */

    /** ومتجرُ البانِي يُعايَن كما كان — لا يمسّه هذا الباب */
    public function test_a_builder_site_previews_as_before(): void
    {
        $built = Business::create(['name' => 'بانٍ', 'type' => 'محل', 'status' => 'نشط', 'site_slug' => 'builder', 'phone' => '968']);
        Currency::create(['business_id' => $built->id, 'code' => 'OMR', 'name' => 'ر', 'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);
        Ledger::seedChart($built->id);
        Branch::create(['business_id' => $built->id, 'name' => 'ف']);
        Setting::create(['business_id' => $built->id, 'key' => 'vat_enabled', 'value' => '0']);
        MarketingSettings::save($built->id, 'website', ['store_on' => '1']);

        $user = User::create(['business_id' => $built->id, 'name' => 'ب', 'email' => 'b@abaad.om', 'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط']);

        $this->actingAs($user)->get(route('admin.store.preview'))->assertOk();
        $this->actingAs($user)->post(route('admin.store.preview'), ['draft' => json_encode(['store_headline' => 'x'])])->assertOk();
    }
}
