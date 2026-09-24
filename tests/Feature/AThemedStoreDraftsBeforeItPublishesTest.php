<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Category;
use App\Models\Currency;
use App\Models\Product;
use App\Models\Setting;
use App\Models\StoreSite;
use App\Models\User;
use App\Models\WebsiteVersion;
use App\Support\Ledger;
use App\Support\MarketingSettings;
use App\Support\Store\StaleDraft;
use App\Support\Store\StoreContent;
use App\Support\Store\ThemePublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * متجرُ الواجهة الخاصّة يُحرَّر في مسوّدةٍ ويُنشر حين يرضى صاحبُه.
 *
 * ═══ ما كان ═══
 *
 * كلُّ حفظٍ نشرٌ: يكتب التاجرُ نصفَ نبذةٍ ثمّ ينشغل، فيقرأ زبونُه نصفَ نبذة.
 * ولا «تغييراتٌ غير منشورة» لأنّه لا يوجد ما يُنشر، ولا رجوعَ إلى ما كان.
 *
 * ═══ وأثقلُ ما يُحرس هنا ═══
 *
 * ليس أنّ النشر يعمل — بل أنّ **الترحيل لا يُغيّر ما يراه الزبون**، وأنّ
 * ما لم يُرحَّل يعمل كما كان بالحرف، وأنّ السعرَ والمخزونَ لا يدخلان نشرةً.
 */
class AThemedStoreDraftsBeforeItPublishesTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->setLocale('ar');

        $this->shop = $this->shopNamed('ريبون', 'ribbon');
        $this->owner = User::create([
            'business_id' => $this->shop->id, 'name' => 'سعود', 'email' => 'saud@abaad.om',
            'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        MarketingSettings::save($this->shop->id, 'website', [
            'store_on' => '1', 'store_pay_cod' => '1',
            'store_headline' => 'المنشورُ القديم', 'store_delivery_fee' => '2',
        ]);

        $cat = Category::create(['business_id' => $this->shop->id, 'name' => 'باقات']);
        Product::create([
            'business_id' => $this->shop->id, 'name' => 'باقة ورد', 'price' => 12, 'category_id' => $cat->id,
            'cost' => 4, 'quantity' => 10, 'active' => true, 'published' => true,
        ]);
    }

    private function shopNamed(string $name, string $slug): Business
    {
        $b = Business::create([
            'name' => $name, 'type' => 'محل ورد', 'status' => 'نشط', 'phone' => '96895259066',
            'city' => 'مسقط', 'site_slug' => $slug, 'tier' => 'gold', 'storefront_theme' => 'ribbon',
        ]);
        Currency::create(['business_id' => $b->id, 'code' => 'OMR', 'name' => 'ريال', 'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);
        Ledger::seedChart($b->id);
        Branch::create(['business_id' => $b->id, 'name' => 'الخوير']);
        Setting::create(['business_id' => $b->id, 'key' => 'vat_enabled', 'value' => '0']);

        return $b;
    }

    private function open(): StoreSite
    {
        return ThemePublisher::enable($this->shop, (int) $this->owner->id);
    }

    private function liveHtml(): string
    {
        return (string) $this->get('/s/ribbon')->assertOk()->getContent();
    }

    /* ═══════════ العقد: أيُّ مفتاحٍ في أيّ مجموعة ═══════════ */

    /**
     * ثمانيةٌ وأربعون مفتاحًا، لا يسقط منها واحدٌ ولا يقع في مجموعتين.
     *
     * ═══ ولمَ يُحرَس العدد ═══
     *
     * مفتاحٌ يُضاف غدًا إلى `MarketingSettings::GROUPS['website']` ولا
     * يُقرَّر له موضع يقع في «الحيّ» بالسكوت — وقد يكون نصَّ صفحةٍ يجب أن
     * يُنشر. فيسقط هذا الحارس ويُسأل كاتبُه: أيُّهما هو؟
     */
    public function test_every_setting_key_belongs_to_exactly_one_group(): void
    {
        $all = array_keys(MarketingSettings::GROUPS['website']);
        $versioned = StoreContent::VERSIONED;
        $live = array_values(array_diff($all, $versioned));

        $this->assertCount(48, $all, 'تبدّل عددُ مفاتيح المتجر — أقرِّر موضعَ الجديد');
        $this->assertCount(17, $versioned, 'تبدّل عددُ ما يُنشر');
        $this->assertCount(31, $live, 'تبدّل عددُ ما يسري فورًا');

        $this->assertSame([], array_diff($versioned, $all),
            'مفتاحٌ في العقد وليس في المجموعة: '.implode(', ', array_diff($versioned, $all)));
        $this->assertSame(count($versioned), count(array_unique($versioned)), 'مفتاحٌ مكرّرٌ في العقد');
    }

    /**
     * وما يُغيّر ما يدفعه الزبون أو ما يصل إليه لا يُنشر — يسري فورًا.
     *
     * ولا يُقاس بالعدد: يُسمّى كلُّ مفتاحٍ خطرٍ باسمه. فلو زُحلق أحدُها إلى
     * العقد يومًا لَغيّره التاجرُ صباحًا وبقي القديمُ **يُحصَّل من الزبائن**
     * حتّى ينشر — وهو خطأٌ ماليّ لا بصريّ.
     */
    public function test_nothing_that_touches_money_or_reach_is_ever_versioned(): void
    {
        $mustStayLive = [
            // مال
            'store_delivery_fee', 'store_free_delivery_over', 'store_gift_card_price',
            // دفع
            'store_allow_orders', 'store_pay_cod', 'store_pay_transfer', 'store_bank',
            // توصيل واستلام
            'store_delivery_areas', 'store_delivery_slots', 'store_fulfil', 'store_max_days',
            'store_delivery_note', 'store_image_note', 'store_gift_card',
            // حقولُ الإتمام
            'store_field_area', 'store_field_address', 'store_field_date',
            'store_field_slot', 'store_field_recipient', 'store_field_promo',
            // نطاقٌ وخدمة
            'site_on', 'site_domain', 'site_domain_mode', 'site_path', 'store_on',
            // تواصلٌ تشغيليّ — رقمٌ خطأ أو ساعةٌ خطأ يُصحَّحان الآن
            'store_whatsapp', 'store_hours', 'store_instagram',
            // وإذنُ الفهرسة: «لا تُفهرسني» طلبٌ يُستعجَل
            'store_seo_index',
            // وقرارٌ تجاريٌّ قد يُتَّخذ فجأة
            'store_show_prices',
            // والقالبُ لا يُنشر ولا يُستعاد
            'store_theme',
        ];

        foreach ($mustStayLive as $key) {
            $this->assertNotContains($key, StoreContent::VERSIONED,
                'المفتاح «'.$key.'» دخل النشر — وهو يسري على زبونٍ يشتري الآن.');
        }

        $this->assertCount(31, $mustStayLive, 'قائمةُ الحيّ ناقصةٌ أو زائدة');
        $this->assertSame([], array_diff($mustStayLive, array_keys(MarketingSettings::GROUPS['website'])));
    }

    /** وما يراه الزائرُ فقط يُنشر — لا يسري بالحفظ */
    public function test_everything_the_visitor_only_reads_is_versioned(): void
    {
        foreach ([
            'store_headline', 'store_about', 'store_tagline',
            'store_hero_image', 'store_banner_image', 'store_about_image',
            'store_featured', 'store_sections', 'store_pages',
            'store_block_on', 'store_block_title', 'store_block_text',
            'store_block_image', 'store_block_cta', 'store_block_href',
            'store_seo_title', 'store_seo_desc',
        ] as $key) {
            $this->assertContains($key, StoreContent::VERSIONED,
                'المفتاح «'.$key.'» يسري بالحفظ — ونصفُ نبذةٍ يقرؤها زبون.');
        }
    }

    /* ═══════════ الترحيل لا يُغيّر ما يراه الزبون ═══════════ */

    /**
     * وهو أثقلُ حارسٍ هنا: الصفحةُ قبل الترحيل وبعده **حرفًا بحرف**.
     *
     * ولا يُقارَن مفتاحٌ أو اثنان: تُقارَن الصفحةُ المصيَّرة كلُّها، إذ
     * العطبُ الذي يُخشى هو أن يتبدّل رسمٌ لا يخطر على بال.
     */
    public function test_opening_publishing_does_not_change_one_letter_for_the_visitor(): void
    {
        $before = $this->liveHtml();

        $this->open();

        $this->assertSame(
            $this->strip($before), $this->strip($this->liveHtml()),
            'تبدّلت صفحةُ الزبون بالترحيل — وهو ما لا يُغتفر.',
        );
    }

    /** ورموزُ الجلسة تتبدّل مع كلّ طلب فتُطرح من المقارنة */
    private function strip(string $html): string
    {
        return (string) preg_replace('~content="[A-Za-z0-9]{40}"~', 'content="TOKEN"', $html);
    }

    /** ولا مفتاحَ حيٌّ يُمسّ */
    public function test_opening_publishing_writes_no_live_setting(): void
    {
        $before = DB::table('settings')->where('business_id', $this->shop->id)
            ->orderBy('key')->pluck('value', 'key')->all();

        $this->open();

        $this->assertSame($before, DB::table('settings')->where('business_id', $this->shop->id)
            ->orderBy('key')->pluck('value', 'key')->all());
    }

    /** واللوحةُ تقول «الموقع محدّث» لا «فيه تغييرات» */
    public function test_a_freshly_opened_store_has_nothing_unpublished(): void
    {
        $this->open();

        $this->assertSame([], StoreContent::changed((int) $this->shop->id));
    }

    /** ويُعاد الأمرُ بلا ضرر — تشغيلُه مرّتين كتشغيله مرّة */
    public function test_opening_twice_changes_nothing(): void
    {
        $first = $this->open();
        ThemePublisher::saveDraft((int) $this->shop->id, ['store_headline' => 'مسوّدة'], null);

        $again = $this->open();

        $this->assertSame($first->id, $again->id);
        $this->assertSame('مسوّدة', StoreContent::draft((int) $this->shop->id)['store_headline']);
        $this->assertSame(1, WebsiteVersion::where('business_id', $this->shop->id)->count());
    }

    /* ═══════════ المسوّدة لا تُنشر ═══════════ */

    /** حفظُ المسوّدة لا يبلغ الزبون */
    public function test_saving_a_draft_does_not_publish_it(): void
    {
        $this->open();

        $this->actingAs($this->owner)->post(route('admin.marketing.store.save'), [
            'store_headline' => 'عنوانٌ جديد',
        ]);

        $this->assertSame('عنوانٌ جديد', StoreContent::draft((int) $this->shop->id)['store_headline']);
        $this->assertSame('المنشورُ القديم', StoreContent::live((int) $this->shop->id)['store_headline']);
        $this->assertStringContainsString('المنشورُ القديم', $this->liveHtml());
        $this->assertStringNotContainsString('عنوانٌ جديد', $this->liveHtml());
    }

    /** وتُعرف أنّها غيرُ منشورة */
    public function test_an_edited_draft_is_reported_as_unpublished(): void
    {
        $this->open();
        ThemePublisher::saveDraft((int) $this->shop->id, ['store_headline' => 'جديد'], null);

        $this->assertSame(['store_headline'], StoreContent::changed((int) $this->shop->id));
    }

    /** وحفظٌ يُعيد القيمةَ إلى ما كانت لا يُعدّ تغييرًا */
    public function test_saving_the_same_value_is_not_an_unpublished_change(): void
    {
        $this->open();
        ThemePublisher::saveDraft((int) $this->shop->id, ['store_headline' => 'المنشورُ القديم'], null);

        $this->assertSame([], StoreContent::changed((int) $this->shop->id));
    }

    /* ═══════════ النشر ═══════════ */

    /** والنشرُ يبلغ الزبون، وينشئ نشرةً بمن نشر ومتى */
    public function test_publishing_reaches_the_visitor_and_records_who_and_when(): void
    {
        $this->open();
        ThemePublisher::saveDraft((int) $this->shop->id, ['store_headline' => 'عنوانٌ منشور'], (int) $this->owner->id);

        $version = ThemePublisher::publish($this->shop, (int) $this->owner->id, 'أوّلُ تغيير');

        $this->assertNotNull($version);
        $this->assertSame(2, (int) $version->number);
        $this->assertSame((int) $this->owner->id, (int) $version->created_by);
        $this->assertNotNull($version->published_at);
        $this->assertSame('أوّلُ تغيير', $version->note);
        $this->assertStringContainsString('عنوانٌ منشور', $this->liveHtml());
    }

    /** ونشرٌ بلا تغييرٍ لا يكتب نشرةً — «الموقع محدّث» لا «نُشر بنجاح» */
    public function test_publishing_with_nothing_to_publish_writes_no_version(): void
    {
        $this->open();

        $this->assertNull(ThemePublisher::publish($this->shop, (int) $this->owner->id));
        $this->assertSame(1, WebsiteVersion::where('business_id', $this->shop->id)->count());
    }

    /** والضغطُ مرّتين لا يكتب نشرتين */
    public function test_pressing_publish_twice_writes_one_version(): void
    {
        $this->open();
        ThemePublisher::saveDraft((int) $this->shop->id, ['store_headline' => 'مرّة'], null);

        $first = ThemePublisher::publish($this->shop, (int) $this->owner->id);
        $second = ThemePublisher::publish($this->shop, (int) $this->owner->id);

        $this->assertNotNull($first);
        $this->assertNull($second, 'الضغطةُ الثانية كتبت نشرةً ثانية بلا تغيير');
        $this->assertSame(2, WebsiteVersion::where('business_id', $this->shop->id)->count());
    }

    /** ومن نشر مسوّدةً بدّلها زميلُه بعد أن فتح شاشته يُردّ */
    public function test_publishing_a_draft_that_changed_underneath_is_refused(): void
    {
        $this->open();
        ThemePublisher::saveDraft((int) $this->shop->id, ['store_headline' => 'ما رآه'], null);
        $seen = (int) StoreSite::where('business_id', $this->shop->id)->value('draft_revision');

        // زميلٌ يكتب بعده
        ThemePublisher::saveDraft((int) $this->shop->id, ['store_about' => 'ما كتبه زميلُه'], null);

        $this->expectException(StaleDraft::class);
        ThemePublisher::publish($this->shop, (int) $this->owner->id, null, $seen);
    }

    /**
     * والأرقامُ متسلسلةٌ لكلّ متجرٍ على حدة.
     *
     * ولا يكفي أن يُفتح متجران — كلاهما يبدأ بواحدٍ مكتوبٍ بيده. يُنشر في
     * كلٍّ منهما نشرًا حقيقيًّا: بالترقيم العامّ يقفز الثاني إلى ثلاثة،
     * فيرى صاحبُه «نشرة ٣» وليس له إلّا اثنتان.
     */
    public function test_version_numbers_run_per_business(): void
    {
        $this->open();

        $other = $this->shopNamed('جار', 'jar');
        MarketingSettings::save($other->id, 'website', ['store_on' => '1']);
        ThemePublisher::enable($other, null);

        ThemePublisher::saveDraft((int) $this->shop->id, ['store_headline' => 'أ'], null);
        $mine = ThemePublisher::publish($this->shop, (int) $this->owner->id);

        ThemePublisher::saveDraft((int) $other->id, ['store_headline' => 'ب'], null);
        $his = ThemePublisher::publish($other, null);

        $this->assertSame(2, (int) $mine?->number);
        $this->assertSame(2, (int) $his?->number, 'رقمُ النشرة عامٌّ لا لكلّ متجر — فيرى صاحبُه رقمًا ليس له.');
    }

    /* ═══════════ الاسترجاع ═══════════ */

    /** الاسترجاعُ يُعيد النصَّ الصحيح — إلى المسوّدة لا إلى الموقع */
    public function test_restoring_returns_the_right_version_into_the_draft_only(): void
    {
        $this->open();
        $first = WebsiteVersion::where('business_id', $this->shop->id)->firstOrFail();

        ThemePublisher::saveDraft((int) $this->shop->id, ['store_headline' => 'الثاني'], null);
        ThemePublisher::publish($this->shop, (int) $this->owner->id);
        $this->assertStringContainsString('الثاني', $this->liveHtml());

        $this->assertTrue(ThemePublisher::restore($this->shop, $first, (int) $this->owner->id));

        $this->assertSame('المنشورُ القديم', StoreContent::draft((int) $this->shop->id)['store_headline']);
        $this->assertStringContainsString('الثاني', $this->liveHtml(), 'الاسترجاعُ بدّل موقعًا يعمل بلا نشر!');
    }

    /** ولا يُسترجَع القالبُ — فلا يُبدَّل تصميمُ متجرٍ بضغطةٍ في شاشة تاريخ */
    public function test_restoring_never_changes_the_template(): void
    {
        $this->open();
        $version = WebsiteVersion::where('business_id', $this->shop->id)->firstOrFail();
        $version->update(['payload' => array_merge((array) $version->payload, [
            'theme' => 'other', 'content' => ['store_theme' => 'night', 'store_headline' => 'قديم'],
        ])]);

        ThemePublisher::restore($this->shop, $version, null);

        $this->assertSame('ribbon', $this->shop->fresh()->storefrontTheme());
        $this->assertArrayNotHasKey('store_theme', StoreContent::only(
            (array) StoreSite::where('business_id', $this->shop->id)->value('draft')
        ));
    }

    /* ═══════════ عزلُ الأنشطة ═══════════ */

    /** ومسوّدةُ متجرٍ ليست مسوّدةَ جاره */
    public function test_drafts_are_isolated_between_businesses(): void
    {
        $this->open();
        $other = $this->shopNamed('جار', 'jar');
        MarketingSettings::save($other->id, 'website', ['store_on' => '1', 'store_headline' => 'عنوانُ الجار']);
        ThemePublisher::enable($other, null);

        ThemePublisher::saveDraft((int) $this->shop->id, ['store_headline' => 'مسوّدةُ سعود'], null);

        $this->assertSame('عنوانُ الجار', StoreContent::draft((int) $other->id)['store_headline']);
        $this->assertSame([], StoreContent::changed((int) $other->id));
    }

    /** ولا تُسترجَع نشرةُ جارٍ في متجرٍ آخر */
    public function test_a_version_of_another_business_is_refused(): void
    {
        $this->open();
        $other = $this->shopNamed('جار', 'jar');
        ThemePublisher::enable($other, null);

        $hisVersion = WebsiteVersion::where('business_id', $other->id)->firstOrFail();

        $this->assertFalse(ThemePublisher::restore($this->shop, $hisVersion, null));
    }

    /** وموظّفٌ بلا صلاحية ضبط الموقع لا ينشر */
    public function test_a_clerk_cannot_publish(): void
    {
        $this->open();
        ThemePublisher::saveDraft((int) $this->shop->id, ['store_headline' => 'جديد'], null);

        $clerk = User::create(['business_id' => $this->shop->id, 'name' => 'موظّف', 'email' => 'c@abaad.om', 'password' => bcrypt('x'), 'role' => 'cashier', 'status' => 'نشط']);

        $this->actingAs($clerk)->post(route('admin.website.store.publish'))->assertForbidden();
        $this->assertSame('المنشورُ القديم', StoreContent::live((int) $this->shop->id)['store_headline']);
    }

    /* ═══════════ التشغيليُّ يبقى حيًّا ═══════════ */

    /** رسمُ التوصيل يسري فورًا — ولا ينتظر نشرةً ولا يعود باسترجاع */
    public function test_operational_settings_apply_at_once_and_survive_a_restore(): void
    {
        $this->open();
        $first = WebsiteVersion::where('business_id', $this->shop->id)->firstOrFail();

        $this->actingAs($this->owner)->post(route('admin.marketing.store.save'), [
            'store_delivery_fee' => '5', 'store_headline' => 'مسوّدة',
        ]);

        $this->assertSame('5', MarketingSettings::group((int) $this->shop->id, 'website')['store_delivery_fee'],
            'رسمُ التوصيل انتظر نشرةً — وهو مالٌ يُحصَّل من الزبون.');

        ThemePublisher::restore($this->shop, $first, null);
        ThemePublisher::publish($this->shop, (int) $this->owner->id);

        $this->assertSame('5', MarketingSettings::group((int) $this->shop->id, 'website')['store_delivery_fee'],
            'الاسترجاعُ أعاد رسمَ توصيلٍ قديم!');
    }

    /** و«لا تُفهرسني» يسري فورًا */
    public function test_noindex_applies_at_once(): void
    {
        $this->open();

        $this->actingAs($this->owner)->post(route('admin.marketing.store.save'), ['store_seo_index' => '0']);

        $this->assertStringContainsString('rb-noindex', $this->liveHtml());
    }

    /** ولا يدخل النشرةَ سعرٌ ولا مخزونٌ ولا صنف */
    public function test_a_version_freezes_no_price_and_no_stock(): void
    {
        $this->open();
        $payload = json_encode((array) WebsiteVersion::where('business_id', $this->shop->id)->value('payload'));

        foreach (['price', 'quantity', 'stock', 'product'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, (string) $payload,
                'اللقطةُ تحمل «'.$forbidden.'» — فنشرُ تصميمٍ يُجمّد بضاعة.');
        }
    }

    /** وتغييرُ سعرٍ لا يحتاج نشرًا: يظهر للزبون وحدَه */
    public function test_changing_a_price_needs_no_publish(): void
    {
        $this->open();
        Product::where('business_id', $this->shop->id)->update(['price' => 99]);

        $this->assertStringContainsString('99', $this->liveHtml());
        $this->assertSame([], StoreContent::changed((int) $this->shop->id));
    }

    /**
     * والنشرُ يكتب **مفاتيحَ العقد وحدَها** — لا مفتاحًا سواها.
     *
     * ولا يُقاس بمفتاحٍ أو اثنين: تُقارَن صفوفُ `settings` كلُّها قبل النشر
     * وبعده، ويُشترط أن يكون الفرقُ محصورًا في الثمانية عشر. فمفتاحٌ
     * تشغيليٌّ يتسرّب إلى النسخ يُكشف ولو لم يخطر على بال كاتبِ الاختبار.
     */
    public function test_publishing_touches_only_the_versioned_keys(): void
    {
        $this->open();

        $before = DB::table('settings')->where('business_id', $this->shop->id)
            ->orderBy('key')->pluck('value', 'key')->all();

        ThemePublisher::saveDraft((int) $this->shop->id, [
            'store_headline' => 'عنوان', 'store_about' => 'نبذة', 'store_tagline' => 'تذييل',
        ], null);
        ThemePublisher::publish($this->shop, (int) $this->owner->id);

        $after = DB::table('settings')->where('business_id', $this->shop->id)
            ->orderBy('key')->pluck('value', 'key')->all();

        $moved = [];

        foreach ($after as $key => $value) {
            if (($before[$key] ?? null) !== $value) {
                $moved[] = $key;
            }
        }

        /*
         * والسؤالُ «أكلُّ ما تحرّك من العقد؟» لا «أتحرّك الثلاثةُ وحدها؟».
         *
         * النشرُ يُثبت المسوّدة كلَّها، فتُكتب مفاتيحُ لم يكن لها صفٌّ من
         * قبلُ بقيمتها الافتراضيّة نفسِها — ولا يتبدّل بها شيءٌ للزائر.
         * والذي يُحرَس هو ألّا يتسرّب مفتاحٌ **تشغيليّ** إلى النسخ.
         */
        $this->assertSame([], array_diff($moved, StoreContent::VERSIONED),
            'النشرُ مسّ مفتاحًا ليس من عقد التصميم والمحتوى: '
            .implode(', ', array_diff($moved, StoreContent::VERSIONED)));

        foreach (['store_headline', 'store_about', 'store_tagline'] as $edited) {
            $this->assertContains($edited, $moved, 'ما حُرّر لم يُنشر');
        }

        // ورسمُ التوصيل لم يتحرّك — وهو أقربُ ما يكون إلى التسرّب
        $this->assertSame($before['store_delivery_fee'] ?? null, $after['store_delivery_fee'] ?? null);
    }

    /** ولا يُسقط النشرُ مفتاحًا كان محفوظًا */
    public function test_publishing_deletes_no_setting(): void
    {
        $this->open();
        $keys = DB::table('settings')->where('business_id', $this->shop->id)->pluck('key')->sort()->values()->all();

        ThemePublisher::saveDraft((int) $this->shop->id, ['store_headline' => 'عنوان'], null);
        ThemePublisher::publish($this->shop, (int) $this->owner->id);

        $after = DB::table('settings')->where('business_id', $this->shop->id)->pluck('key')->sort()->values()->all();

        $this->assertSame([], array_diff($keys, $after), 'النشرُ محا مفتاحًا محفوظًا');
    }

    /**
     * وتراكبُ معاينةِ متجرٍ لا يبلغ جارَه — ولو في الطلب نفسِه.
     *
     * وهو ما يُخشى من ثابتٍ يحمل حالًا: عاملٌ يخدم متجرين في العمليّة
     * نفسِها، فيقرأ الثاني ما وُضع للأوّل.
     */
    public function test_a_preview_overlay_never_reaches_another_business(): void
    {
        $other = $this->shopNamed('جار', 'jar');
        MarketingSettings::save($other->id, 'website', ['store_on' => '1', 'store_headline' => 'عنوانُ الجار']);

        $seen = MarketingSettings::withOverlay((int) $this->shop->id, 'website',
            ['store_headline' => 'تراكبُ سعود'],
            fn () => MarketingSettings::group((int) $other->id, 'website')['store_headline'],
        );

        $this->assertSame('عنوانُ الجار', $seen, 'تراكبُ متجرٍ بلغ جارَه في الطلب نفسِه.');
    }

    /* ═══════════ ومن لم يُرحَّل يعمل كما كان ═══════════ */

    /** متجرٌ بلا صفٍّ يُحفظ فيظهر — بالحرف كما كان قبل النظام */
    public function test_a_store_that_was_never_opened_saves_straight_to_live(): void
    {
        $this->actingAs($this->owner)->post(route('admin.marketing.store.save'), [
            'store_headline' => 'حفظٌ مباشر',
        ]);

        $this->assertStringContainsString('حفظٌ مباشر', $this->liveHtml());
        $this->assertSame([], StoreContent::changed((int) $this->shop->id));
        $this->assertSame(0, WebsiteVersion::where('business_id', $this->shop->id)->count());
    }

    /** ولا ينشر من لم يُفتح له النظام */
    public function test_a_store_that_was_never_opened_cannot_publish(): void
    {
        $this->actingAs($this->owner)->post(route('admin.website.store.publish'))->assertNotFound();
    }

    /* ═══════════ والمعاينة ═══════════ */

    /** المعاينةُ تُري المسوّدةَ المحفوظة — والزائرُ لا يراها */
    public function test_the_preview_shows_the_saved_draft(): void
    {
        $this->open();
        ThemePublisher::saveDraft((int) $this->shop->id, ['store_headline' => 'مسوّدةٌ محفوظة'], null);

        $preview = (string) $this->actingAs($this->owner)->get(route('admin.store.preview'))
            ->assertOk()->getContent();

        $this->assertStringContainsString('مسوّدةٌ محفوظة', $preview);
        $this->assertStringContainsString('المنشورُ القديم', $this->liveHtml());
    }

    /** وما لم يُحفظ بعدُ يعلو على المسوّدة المحفوظة */
    public function test_unsaved_edits_sit_on_top_of_the_saved_draft(): void
    {
        $this->open();
        ThemePublisher::saveDraft((int) $this->shop->id, ['store_headline' => 'محفوظ', 'store_tagline' => 'تذييلٌ محفوظ'], null);

        $html = (string) $this->actingAs($this->owner)
            ->post(route('admin.store.preview'), ['draft' => json_encode(['store_headline' => 'لم يُحفظ'])])
            ->assertOk()->getContent();

        $this->assertStringContainsString('لم يُحفظ', $html);
        $this->assertStringContainsString('تذييلٌ محفوظ', $html);
    }

    /** وطبقةُ المعاينة تُنزَع حتّى إذا وقع استثناء */
    public function test_the_preview_layer_is_removed_even_when_rendering_throws(): void
    {
        $bid = (int) $this->shop->id;

        try {
            MarketingSettings::withOverlay($bid, 'website', ['store_headline' => 'تراكب'], function () {
                throw new \RuntimeException('انهار التصيير');
            });
        } catch (\RuntimeException) {
            // ما يُحرَس هو ما بعده
        }

        $this->assertSame('المنشورُ القديم', MarketingSettings::group($bid, 'website')['store_headline'],
            'بقي التراكبُ بعد الاستثناء — فتخرج مسوّدةُ التاجر لزبونه.');
    }
}
