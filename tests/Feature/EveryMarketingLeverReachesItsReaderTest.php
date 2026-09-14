<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Models\WhatsAppConnection;
use App\Models\WhatsAppMessage;
use App\Support\CustomerInvoices;
use App\Support\Ledger;
use App\Support\MarketingSettings;
use App\Support\Seo;
use App\Support\Website\Builder;
use App\Support\Website\Publisher;
use App\Support\WhatsAppEvent;
use App\Support\WhatsAppMode;
use App\Support\WhatsAppQuota;
use App\Support\WhatsAppTemplates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * كلُّ مقبضٍ في أدوات التسويق يصل إلى من يقرؤه.
 *
 * وهذا الملفّ عن عطبٍ من نوعٍ واحد وقع مرّتين في أداتين: **مقبضٌ يُرسم من
 * قائمةٍ، وتُقرأ قيمتُه من قائمةٍ أخرى**. فما دامت القائمتان متطابقتين لا
 * يظهر شيء؛ ويوم يُضاف بندٌ إلى إحداهما وحدها يصير على الشاشة مقبضٌ كامل
 * الهيئة لا يُدير شيئًا — يُضغط، ويُحفظ النموذج، ويُقال «حُفظت إعداداتك».
 *
 * ١) **إشعارات واتساب.** الشاشة ترسم المقابض من `WhatsAppEvent::ALL` —
 *    ستّة. وتُقرأ قيمُها وتُحفظ من `MarketingSettings::GROUPS['whatsapp']` —
 *    أربعة. فحدثا تذكير الفاتورة (قبل الاستحقاق وبعده) مقبضان مرسومان بلا
 *    مفتاح: يعودان مطفأين مهما ضُغطا. وأبعدُ من ذلك أنّ **المُرسِل يسأل
 *    المجموعة نفسها** — فتذكيرُ السداد لم يكن يخرج لمتجرٍ واحد قطّ، ولو
 *    كُتب الصفُّ في القاعدة بيد.
 *
 * ٢) **الظهور في البحث.** معرّفُ القياس يُحفظ، ويُبنى منه وسمٌ **يُعطى
 *    للتاجر ليلصقه في موقعه**. وكان ذلك صحيحًا يوم كان موقعُه دائمًا عند
 *    غيرنا؛ ثمّ صار لأبعاد بانِي مواقع، وصارت الصفحةُ صفحتَنا — و`<head>`
 *    نكتبه نحن. فلا هو يستطيع اللصق، ولا نحن كنّا نضع الوسم: المعرّفُ
 *    يُحفظ ولا يخرج في صفحةٍ واحدة أبدًا، وصاحبُه ينتظر أرقامًا لا تأتي.
 *
 * والحارسُ الذي كان قائمًا يقيس اتّجاهًا واحدًا («كلُّ مفتاحٍ معروضٍ يقرؤه
 * المُرسِل») — والنقصُ كان في الاتّجاه الآخر. فصار يُقاس الاتّجاهان.
 */
class EveryMarketingLeverReachesItsReaderTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');

        $this->business = Business::create([
            'name' => 'ورود مسقط', 'type' => 'محل ورود', 'status' => 'نشط',
            'site_slug' => 'wrood', 'phone' => '96890000000', 'city' => 'مسقط',
        ]);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);
        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@abaadapp.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
    }

    /* ==================== واتساب: ستّةُ أحداثٍ وستّةُ مفاتيح ==================== */

    /** المُرسِلُ يقرأ مفتاحًا لكلّ حدث — فلكلّ حدثٍ مفتاحٌ يُحفظ */
    public function test_no_event_the_sender_reads_is_left_without_a_key_to_save(): void
    {
        $offered = array_keys(MarketingSettings::GROUPS['whatsapp']);

        foreach (WhatsAppEvent::ALL as $event) {
            $key = WhatsAppEvent::SETTING_KEYS[$event] ?? null;

            $this->assertNotNull($key, "الحدث «{$event}» بلا مفتاح إطفاء");
            $this->assertContains(
                $key,
                $offered,
                "«{$key}» يُرسم مقبضًا في الشاشة ولا تُحفظ قيمتُه — يُضغط ويعود مطفأً",
            );
        }
    }

    /** ولا مفتاحَ في المجموعة لا حدثَ له — القائمتان واحدة في الاتّجاهين */
    public function test_no_key_is_offered_that_the_sender_never_reads(): void
    {
        $read = array_values(WhatsAppEvent::SETTING_KEYS);

        foreach (array_keys(MarketingSettings::GROUPS['whatsapp']) as $key) {
            $this->assertContains($key, $read, "«{$key}» يُحفظ ولا يقرؤه مُرسِل الرسائل");
        }
    }

    /** ما ترسمه الشاشة مقبضًا تقرأ له قيمة — لا مقبضَ بلا حال */
    public function test_the_screen_reads_a_value_for_every_switch_it_draws(): void
    {
        $this->connectWhatsApp();

        $props = $this->actingAs($this->owner)->get(route('admin.marketing.whatsapp'))
            ->viewData('page')['props'];

        $this->assertCount(6, $props['automation']['events']);

        foreach ($props['automation']['events'] as $event) {
            $this->assertArrayHasKey(
                $event['setting'],
                $props['settings'],
                "«{$event['setting']}» مقبضٌ مرسوم ولا قيمةَ له تُقرأ — يعود مطفأً دائمًا",
            );
        }
    }

    /** وكلُّ مقبضٍ يُضغط يُحفظ — الستّة لا الأربعة */
    public function test_every_switch_the_screen_draws_is_saved_when_pressed(): void
    {
        $this->actingAs($this->owner)->post(
            route('admin.marketing.whatsapp.save'),
            array_fill_keys(array_values(WhatsAppEvent::SETTING_KEYS), true),
        )->assertRedirect();

        $saved = MarketingSettings::group((int) $this->business->id, 'whatsapp');

        foreach (WhatsAppEvent::SETTING_KEYS as $key) {
            $this->assertSame('1', $saved[$key] ?? null, "«{$key}» ضُغط ولم يُحفظ");
        }
    }

    /** وإطفاؤه قرارٌ يُحفظ كذلك */
    public function test_turning_a_switch_off_is_saved_too(): void
    {
        $this->actingAs($this->owner)->post(
            route('admin.marketing.whatsapp.save'),
            array_fill_keys(array_values(WhatsAppEvent::SETTING_KEYS), true),
        )->assertRedirect();

        $this->actingAs($this->owner)->post(
            route('admin.marketing.whatsapp.save'),
            array_fill_keys(array_values(WhatsAppEvent::SETTING_KEYS), false),
        )->assertRedirect();

        $saved = MarketingSettings::group((int) $this->business->id, 'whatsapp');

        $this->assertSame('0', $saved['wa_on_invoice_overdue']);
        $this->assertSame('0', $saved['wa_on_order']);
    }

    /**
     * ومطالبةُ السداد مطفأةٌ حتى يطلبها صاحبُها.
     *
     * رسائلُ الطلب يتوقّعها الزبون لأنّه اشترى للتوّ؛ والمطالبةُ رسالةٌ من
     * نوعٍ آخر تخرج باسم التاجر إلى عميلٍ قد يكون رتّب أمرَه معه بالهاتف.
     */
    public function test_the_payment_reminder_is_off_until_its_owner_asks_for_it(): void
    {
        $fresh = MarketingSettings::group((int) $this->business->id, 'whatsapp');

        $this->assertSame('0', $fresh['wa_on_invoice_due_soon']);
        $this->assertSame('0', $fresh['wa_on_invoice_overdue']);
    }

    /** والمُرسِلُ يطيع ما ضُغط في الشاشة — لا ما كُتب في مكانٍ آخر */
    public function test_the_reminder_goes_out_only_after_the_screen_switch_is_pressed(): void
    {
        $invoice = $this->overdueInvoice();
        $this->connectWhatsApp();

        // مطفأٌ بافتراضه: لا رسالة
        $this->artisan('invoices:remind')->assertSuccessful();
        $this->assertSame(0, WhatsAppMessage::count());

        // ثمّ يُضغط المقبض من الشاشة نفسها — لا يُكتب الصفُّ بيد
        $this->actingAs($this->owner)->post(route('admin.marketing.whatsapp.save'), [
            'wa_on_invoice_overdue' => true,
        ])->assertRedirect();

        $this->artisan('invoices:remind')->assertSuccessful();

        $this->assertSame(
            1,
            WhatsAppMessage::where('business_id', $this->business->id)->count(),
            'ضُغط المقبضُ في الشاشة ولم يصل المُرسِلَ — والتذكير لا يخرج أبدًا',
        );
        $this->assertSame(
            WhatsAppEvent::INVOICE_OVERDUE,
            WhatsAppMessage::latest('id')->value('event_type'),
        );

        unset($invoice);
    }

    /* ==================== السيو: الوسمُ يخرج حيث نملك الرأس ==================== */

    /** المعرّفُ الذي حفظه يخرج في الصفحة التي نخدمها له */
    public function test_the_measurement_id_reaches_the_page_we_serve_for_him(): void
    {
        $this->publishSite();
        $this->saveMeasurementId('G-ABC12345');

        $this->get('/s/wrood')->assertOk()->assertSee('G-ABC12345', false);
        // وفي صفحاته الداخليّة أيضًا — زائرٌ يدخل من «من نحن» يُعدّ مثلَه
        $this->get('/s/wrood/about')->assertOk()->assertSee('G-ABC12345', false);
    }

    /** ولا وسمَ لمن لم يربط — لا سكربتَ فارغ ولا `null` */
    public function test_no_tag_is_emitted_when_nothing_was_linked(): void
    {
        $this->publishSite();

        $this->get('/s/wrood')->assertOk()->assertDontSee('googletagmanager', false);
    }

    /** ومعاينةُ صاحب المتجر لا تُعدّ زيارةً في تقريره عن زبائنه */
    public function test_the_owners_own_preview_is_not_counted_as_a_visitor(): void
    {
        Setting::create(['business_id' => $this->business->id, 'key' => 'store_on', 'value' => '1']);
        $this->saveMeasurementId('G-ABC12345');

        $this->get('/s/wrood')->assertOk()->assertSee('G-ABC12345', false);

        $this->actingAs($this->owner)->get(route('admin.store.preview'))
            ->assertOk()->assertDontSee('G-ABC12345', false);
    }

    /** وموقعٌ منشورٌ يُفتح لا يُقال عنه «لم تُضف نطاقًا بعد» */
    public function test_a_published_site_is_never_called_addressless(): void
    {
        $this->publishSite();

        $link = Seo::forBusiness((int) $this->business->id);

        $this->assertTrue($link['hosted']);
        $this->assertNotNull($link['site_url']);
        $this->assertNotSame('nodomain', Seo::check((int) $this->business->id)['state']);
    }

    /** ولا يُطلب لصقُ وسمٍ في رأسٍ لا يملكه — فلا يُعطى ما لا يُلصق */
    public function test_he_is_not_handed_a_snippet_for_a_head_he_cannot_reach(): void
    {
        $this->publishSite();
        $this->saveMeasurementId('G-ABC12345');

        $this->assertNull(Seo::forBusiness((int) $this->business->id)['snippet']);
    }

    /** ومن موقعُه عند غيرنا يبقى يأخذ ما يلصقه — القسمةُ لم تُبدَّل عليه */
    public function test_a_merchant_hosted_elsewhere_still_gets_the_snippet_to_paste(): void
    {
        $this->business->forceFill(['site_slug' => null])->save();
        Setting::create(['business_id' => $this->business->id, 'key' => 'site_domain', 'value' => 'myshop.om']);
        $this->saveMeasurementId('G-ABC12345');

        $link = Seo::forBusiness((int) $this->business->id);

        $this->assertFalse($link['hosted']);
        $this->assertStringContainsString('G-ABC12345', (string) $link['snippet']);
        $this->assertSame('https://myshop.om', $link['site_url']);
    }

    /**
     * ولا يُقاس عليه ما ليس بيده.
     *
     * `robots.txt` و`sitemap.xml` يسكنان جذرَ المضيف — وعنوانُ متجرٍ على
     * مسارٍ ليس جذرًا، فالسؤالُ يقيس جذرَ أبعاد. وما ينقص منهما ينقص منّا
     * لا منه: «اطلبها ممّن بنى موقعك» يقرؤها من بنى موقعه عندنا فيبحث عن
     * جهةٍ لا وجود لها.
     */
    public function test_the_audit_asks_him_nothing_that_is_not_his_to_answer(): void
    {
        $this->publishSite();
        $this->saveMeasurementId('G-ABC12345');

        $checks = $this->auditWith('<html><head><title>ورود مسقط</title>'
            .'<script src="https://www.googletagmanager.com/gtag/js?id=G-ABC12345"></script>'
            .'</head><body></body></html>');

        $keys = array_column($checks, 'key');

        $this->assertNotContains('robots', $keys);
        $this->assertNotContains('sitemap', $keys);

        $analytics = $this->itemAt($checks, 'analytics');
        $this->assertSame('pass', $analytics['state']);
        $this->assertNull($analytics['fix']);
    }

    /**
     * ولو غاب الوسمُ عن الفحص لا يُطلب منه لصقُه.
     *
     * صفحتُه عندنا والوسمُ يخرج فيها لحظةَ الحفظ؛ فغيابُه عن قراءةٍ محفوظةٍ
     * من قبله خبرٌ عن الفحص لا نقصٌ يُصلحه بيده. و«الصقه داخل `<head>`»
     * تُرسله يبحث عن بابٍ لا يملكه، ثمّ يظنّ العطبَ في نفسه.
     */
    public function test_a_missing_tag_is_never_blamed_on_a_head_he_cannot_open(): void
    {
        $this->publishSite();
        $this->saveMeasurementId('G-ABC12345');

        // صفحةٌ ردّت بلا وسم — كأنّ الفحص محفوظٌ من قبل الحفظ
        $checks = $this->auditWith('<html><head><title>ورود مسقط</title></head><body></body></html>');

        $analytics = $this->itemAt($checks, 'analytics');

        $this->assertSame('fail', $analytics['state']);
        $this->assertStringNotContainsString('<head>', (string) $analytics['fix']);
        $this->assertStringContainsString('افحص الآن', (string) $analytics['fix']);
    }

    /**
     * ومفتاحُ الموقع الخارجيّ لا يُطفئ موقعًا نخدمه.
     *
     * `site_on` يحكم زرَّ الموقع الخارجيّ في الشريط. ولو أطفأ الفحصَ هنا
     * لَقالت الشاشة «موقعك مُطفأ» عن صفحةٍ يفتحها الزبون في اللحظة.
     */
    public function test_the_outside_site_switch_does_not_black_out_a_site_we_serve(): void
    {
        $this->publishSite();
        Setting::updateOrCreate(
            ['business_id' => $this->business->id, 'key' => 'site_on'], ['value' => '0'],
        );

        $this->assertTrue(Seo::forBusiness((int) $this->business->id)['hosted']);
        $this->assertNotSame('off', Seo::check((int) $this->business->id)['state']);
    }

    /** وما يُصلح يُقال حيث يُصلح — لا في رأسٍ لا يفتحه */
    public function test_what_is_missing_is_pointed_at_the_screen_that_fixes_it(): void
    {
        $this->publishSite();

        // صفحةٌ بلا عنوانٍ ولا وصف — وهما في «الموقع الإلكتروني ‹ الظهور في البحث»
        $checks = $this->auditWith('<html><head></head><body></body></html>');

        foreach (['title', 'description'] as $key) {
            $fix = (string) $this->itemAt($checks, $key)['fix'];

            $this->assertStringNotContainsString('<', $fix, "«{$key}»: يُطلب منه تحرير وسمٍ لا يملكه");
            $this->assertStringContainsString('الظهور في البحث', $fix);
        }
    }

    /** ومن لم ينشر بعدُ ليس له عنوانٌ يُفحص — ولا يُقال له «سليم» */
    public function test_a_site_still_in_draft_is_not_treated_as_a_live_address(): void
    {
        Builder::create($this->business, 'store', 'modern', $this->owner->id);

        $this->assertFalse(Seo::forBusiness((int) $this->business->id)['hosted']);
        $this->assertSame('nodomain', Seo::check((int) $this->business->id)['state']);
    }

    /* ------------------------------- التهيئة ------------------------------- */

    /** يفحص موقعه وقد ردّ هذا النصَّ — ويردّ بنودَ الفحص */
    private function auditWith(string $html): array
    {
        Http::fake(['*' => Http::response($html, 200)]);
        Seo::forget((int) $this->business->id);

        $audit = Seo::check((int) $this->business->id);

        $this->assertSame('ok', $audit['state']);

        return $audit['checks'];
    }

    /** @return array{key:string, label:string, state:string, detail:?string, fix:?string} */
    private function itemAt(array $checks, string $key): array
    {
        foreach ($checks as $item) {
            if ($item['key'] === $key) {
                return $item;
            }
        }

        $this->fail("لا بندَ «{$key}» في الفحص");
    }

    private function saveMeasurementId(string $id): void
    {
        $this->actingAs($this->owner)
            ->post(route('admin.marketing.seo.save'), ['ga_measurement_id' => $id])
            ->assertRedirect();

        // والجلسةُ تُترك كما كانت: الزائرُ الذي يفتح الموقع ليس صاحبَه
        auth()->logout();
    }

    private function publishSite(): void
    {
        $site = Builder::create($this->business, 'store', 'modern', $this->owner->id);
        Publisher::publish($site, $this->owner->id);
    }

    private function connectWhatsApp(): void
    {
        Setting::updateOrCreate(['business_id' => null, 'key' => 'whatsapp_enabled'], ['value' => '1']);
        Setting::updateOrCreate(['business_id' => null, 'key' => WhatsAppQuota::DEFAULT_KEY], ['value' => '100']);
        WhatsAppConnection::create([
            'owner_type' => WhatsAppMode::OWNER_PLATFORM,
            'phone_number_id' => 'ABAAD-PN',
            'display_phone_number' => '+96890000000',
            'access_token' => 'platform-token-value-0123456789',
            'status' => WhatsAppConnection::ACTIVE,
            'connected_at' => now(),
        ]);
        WhatsAppTemplates::seedPlatformDefaults('ar');
    }

    private function overdueInvoice(): CustomerInvoice
    {
        Currency::create([
            'business_id' => $this->business->id, 'code' => 'OMR', 'name' => 'ريال عماني',
            'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true,
        ]);
        Ledger::seedChart($this->business->id);
        Setting::create(['business_id' => $this->business->id, 'key' => 'vat_enabled', 'value' => '0']);

        $product = Product::create([
            'business_id' => $this->business->id, 'name' => 'باقة ورد',
            'price' => 10, 'cost' => 4, 'quantity' => 500, 'active' => true,
        ]);
        $company = Customer::create([
            'business_id' => $this->business->id, 'name' => 'شركة ABC', 'phone' => '99887766',
            'allow_credit_sales' => true, 'monthly_billing' => true, 'payment_terms_days' => 30,
        ]);

        $this->actingAs($this->owner)->postJson('/pos/checkout', [
            'items' => [['id' => $product->id, 'name' => 'باقة ورد', 'qty' => 8, 'price' => 10]],
            'payment_method' => 'نقدي', 'credit' => true, 'customer_id' => $company->id,
        ])->assertOk();

        $invoice = CustomerInvoices::consolidate(
            $company, [Order::latest('id')->value('id')], [], $this->owner->id,
        );
        $invoice->update(['due_at' => now()->subDays(5)->toDateString()]);

        return $invoice;
    }
}
