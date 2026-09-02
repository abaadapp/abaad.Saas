<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Review;
use App\Models\Setting;
use App\Models\User;
use App\Support\MarketingSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * أدوات التسويق: إعدادٌ يُحفظ فيُقرأ، وتقييمٌ لا يُنشر حتى يُقرأ.
 *
 * أخطر ما في شاشات الإعداد أنّ عطبها صامت: مفتاحٌ يُكتب بحرفٍ ويُقرأ بآخر
 * يُظهر شاشةً سليمة وقيمةً افتراضية، فيضبط التاجر الإعداد ويحفظه ويراه محفوظًا
 * ولا أثر له في النظام. فالحفظ والقراءة يُفحصان معًا من الطرفين.
 */
class MarketingToolsTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'متجري', 'type' => 'عام', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);

        $owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->actingAs($owner);
    }

    private function bid(): int
    {
        return $this->business->id;
    }

    /* --------------------------- الموقع الإلكتروني --------------------------- */

    /**
     * مفتاحان لا أكثر — وكلٌّ منهما يقرؤه شيء.
     *
     * كانت الشاشة تحفظ ثمانية تصف واجهة متجرٍ لا وجود لها في النظام: «نشر
     * الموقع» و«عرض الأسعار» وجملةً تعريفية ونبذة. يملؤها التاجر فتُحفظ ولا
     * يقرؤها شيء — فيظنّ أنّه نشر متجرًا وينتظر طلبًا لا يأتي.
     *
     * وبقي النطاق، ثمّ لحق به `site_on`: تفعيلٌ **يُقرأ** في موضعين —
     * `Demo::websiteUrl` لزرّ الترويسة، و`Seo::forBusiness` للفحص. وليس هو
     * `site_enabled` الميّت: ذاك معناه «انشر متجري» ولا متجرَ يُنشر، وإعادةُ
     * اسمه بمعنًى آخر تجعل قيمةً قديمة تُطفئ موقعًا لم يطلب صاحبُه إطفاءه.
     */
    public function test_only_what_is_read_is_saved_from_the_website_form(): void
    {
        $this->post(route('admin.marketing.website.save'), [
            'site_domain' => 'mystore.om',
            'site_enabled' => true,
            'site_tagline' => 'أجود المنتجات',
            'site_show_prices' => false,
        ])->assertSessionHasNoErrors();

        $saved = MarketingSettings::group($this->bid(), 'website');

        $this->assertSame('mystore.om', $saved['site_domain']);
        $this->assertSame(['site_on', 'site_domain'], array_keys($saved), 'مفتاحٌ لا يقرؤه شيء ما زال يُحفظ');

        foreach (['site_enabled', 'site_tagline', 'site_show_prices'] as $dead) {
            $this->assertDatabaseMissing('settings', ['business_id' => $this->bid(), 'key' => $dead]);
        }
    }

    public function test_a_domain_written_as_a_url_is_refused(): void
    {
        /*
         * لصقُ «https://» يبني روابط معطوبة في كل صفحة، ولا يظهر العطب إلا
         * حين يفتحها زبون.
         */
        $this->post(route('admin.marketing.website.save'), [
            'site_domain' => 'https://mystore.om/shop',
        ])->assertSessionHasErrors('site_domain');

        $this->assertSame('', MarketingSettings::group($this->bid(), 'website')['site_domain']);
    }

    /* ----------------------------- برنامج الولاء ----------------------------- */

    public function test_loyalty_settings_reach_the_point_of_sale(): void
    {
        // الشاشة والبيع يقرآن المفتاح نفسه — وإلا ضُبط الإعداد ولم يتغيّر البيع
        $this->post(route('admin.marketing.loyalty.save'), [
            'loyalty_enabled' => true,
            'loyalty_earn_rate' => 8,
            'loyalty_redeem_max_pct' => 30,
            'loyalty_redeem_min' => 200,
        ])->assertSessionHasNoErrors();

        $this->assertSame('8', Setting::where('business_id', $this->bid())->where('key', 'loyalty_earn_rate')->value('value'));
        $this->assertSame('30', Setting::where('business_id', $this->bid())->where('key', 'loyalty_redeem_max_pct')->value('value'));
        $this->assertSame('200', Setting::where('business_id', $this->bid())->where('key', 'loyalty_redeem_min')->value('value'));
    }

    public function test_a_redemption_cap_above_a_hundred_percent_is_refused(): void
    {
        // تجاوزُها يجعل النقاط تُغطّي الفاتورة كلّها وزيادة، فيخرج البيع بمبلغٍ سالب
        $this->post(route('admin.marketing.loyalty.save'), [
            'loyalty_enabled' => true,
            'loyalty_earn_rate' => 5,
            'loyalty_redeem_max_pct' => 150,
            'loyalty_redeem_min' => 100,
        ])->assertSessionHasErrors('loyalty_redeem_max_pct');
    }

    /**
     * وشاشةُ السيو عادت — بشرطِ ألّا تعِد بما لا تفعل.
     *
     * كانت تضبط عنوانًا ووصفًا وكلماتٍ مفتاحية ومفتاح «اسمح بالفهرسة»
     * لصفحاتٍ لا يقرؤها محرّكٌ من عندنا: الموقع خارج النظام، فما يُكتب هنا
     * لا يصل صفحةً. والحدودُ فيها كانت مضبوطةً بدقّة — ٦٠ محرفًا و١٦٠ — على
     * شيءٍ لا يقرؤه أحد.
     *
     * وما عاد ليس ذاك: معرّفُ قياسٍ **يُقرأ** فيُبنى منه الوسم ويُبحث عنه في
     * الصفحة، وفحصٌ **يفتح الموقع ويقول ما فيه**. فالحدودُ نفسها — ٦٠ و١٦٠ —
     * صارت تُقاس على ما في صفحته هو لا على ما كُتب في حقلٍ عندنا.
     *
     * وهذا الاختبار يحرس الفرق: لا يعود إلى المجموعة حقلٌ يُملأ ولا يُقرأ.
     */
    public function test_the_seo_screen_stores_only_what_something_reads(): void
    {
        $this->assertTrue(Route::has('admin.marketing.seo'));

        $this->assertSame(
            ['ga_measurement_id'],
            array_keys(MarketingSettings::GROUPS['seo']),
            'عاد إلى شاشة السيو حقلٌ يُملأ ولا يقرؤه شيء',
        );
    }

    /* -------------------------- إشعارات واتساب -------------------------- */

    public function test_the_whatsapp_screen_saves_only_what_the_sender_reads(): void
    {
        /*
         * كان الحقل يقبل رقمًا ويفحص صيغته ويحفظه — ولا يقرؤه أحد: الرسائل
         * تخرج من رقم الوصلة المعتمدة عند ميتا لا من رقمٍ يُكتب هنا. وحقلٌ
         * يُفحص بدقّةٍ ولا أثر له أخدعُ من حقلٍ لا يُفحص.
         */
        $this->post(route('admin.marketing.whatsapp.save'), [
            'wa_number' => '96890000000',
            'wa_template_order' => 'نصّ',
            'wa_enabled' => true,
        ])->assertSessionHasNoErrors();

        $saved = MarketingSettings::group($this->bid(), 'whatsapp');

        $this->assertSame(['wa_on_order', 'wa_on_ready', 'wa_on_out_for_delivery', 'wa_on_delivered'], array_keys($saved));
        $this->assertDatabaseMissing('settings', ['business_id' => $this->bid(), 'key' => 'wa_number']);
    }

    public function test_saving_one_group_does_not_touch_another(): void
    {
        // مجموعةٌ تكتب فوق أخرى تمحو إعدادًا لم يفتح التاجر شاشته أصلًا
        $this->post(route('admin.marketing.website.save'), ['site_domain' => 'mystore.om']);
        $this->post(route('admin.marketing.whatsapp.save'), ['wa_on_delivered' => true]);

        $this->assertSame('mystore.om', MarketingSettings::group($this->bid(), 'website')['site_domain']);
        $this->assertSame('1', MarketingSettings::group($this->bid(), 'whatsapp')['wa_on_delivered']);
    }

    public function test_a_key_that_is_not_in_the_group_is_ignored(): void
    {
        // نموذجٌ يُرسل ما ليس له لا يكتب في إعدادات غيره
        $this->post(route('admin.marketing.website.save'), [
            'site_domain' => 'mystore.om',
            'loyalty_earn_rate' => 999,
        ]);

        $this->assertNull(
            Setting::where('business_id', $this->bid())->where('key', 'loyalty_earn_rate')->value('value')
        );
    }

    /* --------------------------- تقييمات العملاء --------------------------- */

    public function test_a_review_arrives_pending_and_is_not_published_on_its_own(): void
    {
        /*
         * الموقع واجهةُ المتجر، وتقييمٌ مسيء يظهر فيها فور وصوله يُقرأ على أنه
         * رأي المتجر في نفسه.
         */
        $this->post(route('admin.marketing.reviews.store'), [
            'rating' => 2, 'author_name' => 'زائر', 'comment' => 'الخدمة بطيئة',
        ])->assertSessionHasNoErrors();

        $this->assertSame('معلّق', Review::first()->status);
    }

    public function test_replying_publishes_the_review_it_answers(): void
    {
        // ردٌّ على تقييمٍ محجوب لا يراه أحد — يكتبه التاجر ويظنّه معروضًا
        $this->post(route('admin.marketing.reviews.store'), ['rating' => 4, 'comment' => 'جيد']);
        $review = Review::first();

        $this->post(route('admin.marketing.reviews.reply', $review->id), ['reply' => 'شكرًا لك'])
            ->assertSessionHasNoErrors();

        $this->assertSame('منشور', $review->fresh()->status);
        $this->assertNotNull($review->fresh()->replied_at);
    }

    public function test_a_rejected_review_is_kept_not_erased(): void
    {
        // الممحوّ لا يقول شيئًا، والمرفوض يُعرف كم رُفض ولماذا
        $this->post(route('admin.marketing.reviews.store'), ['rating' => 1, 'comment' => 'سيّئ']);
        $review = Review::first();

        $this->post(route('admin.marketing.reviews.status', $review->id), ['status' => 'مرفوض'])
            ->assertSessionHasNoErrors();

        $this->assertSame('مرفوض', $review->fresh()->status);
        $this->assertSame(1, Review::count());
    }

    public function test_the_average_counts_published_reviews_only(): void
    {
        // المعلّق لم يُقرأ بعد فلا يُحتسب رأيًا
        $this->post(route('admin.marketing.reviews.store'), ['rating' => 5, 'comment' => 'ممتاز']);
        $this->post(route('admin.marketing.reviews.status', Review::first()->id), ['status' => 'منشور']);
        $this->post(route('admin.marketing.reviews.store'), ['rating' => 1, 'comment' => 'معلّق']);

        $props = $this->get(route('admin.marketing.reviews'))->assertOk()->viewData('page')['props'];

        $this->assertSame(5.0, $props['summary']['average']);
        $this->assertSame(1, $props['summary']['pending']);
    }

    public function test_another_stores_review_is_out_of_reach(): void
    {
        $other = Business::create(['name' => 'الجار', 'type' => 'عام', 'status' => 'نشط']);
        $theirs = Review::create(['business_id' => $other->id, 'rating' => 5, 'comment' => 'رأيهم']);

        $this->post(route('admin.marketing.reviews.status', $theirs->id), ['status' => 'مرفوض'])
            ->assertNotFound();

        $this->assertSame('معلّق', $theirs->fresh()->status);
    }
}
