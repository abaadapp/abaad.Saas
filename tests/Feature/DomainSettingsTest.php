<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\DomainRequest;
use App\Models\Setting;
use App\Models\User;
use App\Support\Demo;
use App\Support\DomainOptions;
use App\Support\MarketingSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * إعدادات الدومين: سؤالٌ قبل حقل، وسعرٌ لا يُخترع، ووعدٌ لا يُقطع.
 *
 * الشاشة تعرض ثلاث طرقٍ إلى عنوانٍ على الإنترنت وتكلفةَ كلٍّ منها. وأخطر ما
 * فيها ثلاثة أشياء يصمت عطبُها:
 *
 * ١) السعر: رقمٌ يُعرض للتاجر ولم يضعه المشغّل هو وعدٌ لم يقطعه أحد. والفرق
 *    بين «مجاني» (صفر) و«يُحدَّد بالتواصل» (فراغ) فرقٌ تجاريّ لا تجميليّ.
 *
 * ٢) النطاق الفرعي: لا استضافة له اليوم. فإن سال اسمُه المحجوز إلى
 *    `site_domain` ظهر زرُّ «فتح الموقع» في الترويسة وقاد إلى عنوانٍ لا يردّ.
 *
 * ٣) من ضبط نطاقه قبل هذه النسخة يجب ألّا تُفتح في وجهه شاشةُ الاختيار.
 */
class DomainSettingsTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'متجري', 'type' => 'عام', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->actingAs($this->owner);
    }

    private function bid(): int
    {
        return $this->business->id;
    }

    private function site(): array
    {
        return MarketingSettings::group($this->bid(), 'website');
    }

    private function cashier(): User
    {
        return User::create([
            'business_id' => $this->bid(), 'name' => 'كاشير', 'email' => 'c@abaad.om',
            'password' => bcrypt('password'), 'role' => 'cashier', 'status' => 'نشط',
        ]);
    }

    private function platformAdmin(string $email): User
    {
        return User::create([
            'name' => 'مدير المنصة', 'email' => $email,
            'password' => bcrypt('password'), 'role' => 'super_admin', 'status' => 'نشط',
        ]);
    }

    /* ----------------------------- الاختيار ----------------------------- */

    public function test_a_store_with_no_domain_has_not_chosen_yet(): void
    {
        $this->assertSame('', DomainOptions::mode($this->site()));
    }

    public function test_choosing_a_mode_is_saved_and_read_back(): void
    {
        $this->post(route('admin.settings.domain.mode'), ['site_domain_mode' => 'subdomain'])
            ->assertSessionHasNoErrors();

        $this->assertSame('subdomain', DomainOptions::mode($this->site()));
    }

    public function test_an_invented_mode_is_rejected(): void
    {
        $this->post(route('admin.settings.domain.mode'), ['site_domain_mode' => 'free-forever'])
            ->assertSessionHasErrors('site_domain_mode');

        $this->assertSame('', DomainOptions::mode($this->site()));
    }

    /**
     * والعودة إلى الاختيار لا تمحو ما ضُبط.
     *
     * من ضغط «تغيير الطريقة» ليقرأ الخيارات ثمّ عاد يجب أن يجد نطاقه كما
     * تركه. ومحوُه هنا يعني متجرًا فقد عنوانه بنقرة استطلاع.
     */
    public function test_returning_to_the_chooser_keeps_what_was_already_set(): void
    {
        $this->post(route('admin.marketing.website.save'), ['site_domain' => 'mystore.om'])
            ->assertSessionHasNoErrors();

        $this->post(route('admin.settings.domain.mode'), ['site_domain_mode' => ''])
            ->assertSessionHasNoErrors();

        $this->assertSame('mystore.om', $this->site()['site_domain']);
    }

    /**
     * ومن ضبط نطاقه قبل هذه النسخة لا يُسأل من جديد.
     *
     * صفُّ `site_domain_mode` قد لا يكون كُتب له — والهجرة تكتبه، وهذا السطر
     * يمسك ما فاتها.
     */
    public function test_an_existing_domain_counts_as_having_chosen_own(): void
    {
        Setting::create(['business_id' => $this->bid(), 'key' => 'site_domain', 'value' => 'old-store.om']);

        $this->assertSame(DomainOptions::OWN, DomainOptions::mode($this->site()));
    }

    /* --------------------------- النطاق الفرعي --------------------------- */

    public function test_a_subdomain_is_reserved_and_sets_the_mode(): void
    {
        $this->post(route('admin.settings.domain.subdomain'), ['site_subdomain' => 'my-store'])
            ->assertSessionHasNoErrors();

        $site = $this->site();
        $this->assertSame('my-store', $site['site_subdomain']);
        $this->assertSame(DomainOptions::SUBDOMAIN, DomainOptions::mode($site));
    }

    /**
     * والاسم المحجوز لا يسيل إلى `site_domain`.
     *
     * هذا هو الاختبار الذي يحرس الوعد: لا استضافة للنطاقات الفرعية اليوم،
     * فلو كُتب الاسم في `site_domain` لقرأه `Demo::websiteUrl` ولظهر زرّ
     * «الموقع الإلكتروني» في ترويسة كلّ صفحة يقود إلى عنوانٍ لا يردّ.
     */
    public function test_a_reserved_subdomain_never_becomes_the_public_website_url(): void
    {
        $this->post(route('admin.settings.domain.subdomain'), ['site_subdomain' => 'my-store'])
            ->assertSessionHasNoErrors();

        $this->assertSame('', $this->site()['site_domain']);
        $this->assertNull(Demo::websiteUrl());
    }

    public function test_reserved_platform_names_are_refused(): void
    {
        $this->post(route('admin.settings.domain.subdomain'), ['site_subdomain' => 'app'])
            ->assertSessionHasErrors('site_subdomain');

        $this->assertSame('', $this->site()['site_subdomain']);
    }

    #[DataProvider('badLabels')]
    public function test_a_subdomain_must_be_a_valid_dns_label(string $label): void
    {
        $this->post(route('admin.settings.domain.subdomain'), ['site_subdomain' => $label])
            ->assertSessionHasErrors('site_subdomain');
    }

    public static function badLabels(): array
    {
        return [
            'حرف كبير' => ['MyStore'],
            'عربية' => ['متجري'],
            'نقطة' => ['my.store'],
            'يبدأ بشرطة' => ['-store'],
            'ينتهي بشرطة' => ['store-'],
            'أقصر من ثلاثة' => ['ab'],
            'مسافة' => ['my store'],
        ];
    }

    /**
     * والاسم الواحد لا يشير إلى متجرين.
     *
     * التصادم لا يظهر اليوم لأنّ لا استضافة، ولا يُكتشف إلا يوم تُوصَل —
     * حين يكون لكلٍّ منهما ورقٌ ولافتةٌ تحمل العنوان نفسه.
     */
    public function test_a_subdomain_taken_by_another_store_is_refused(): void
    {
        $other = Business::create(['name' => 'متجر آخر', 'type' => 'عام', 'status' => 'نشط']);
        Setting::create(['business_id' => $other->id, 'key' => 'site_subdomain', 'value' => 'my-store']);

        $this->post(route('admin.settings.domain.subdomain'), ['site_subdomain' => 'my-store'])
            ->assertSessionHasErrors('site_subdomain');

        $this->assertSame('', $this->site()['site_subdomain']);
    }

    public function test_a_store_may_keep_reserving_its_own_name(): void
    {
        Setting::create(['business_id' => $this->bid(), 'key' => 'site_subdomain', 'value' => 'my-store']);

        $this->post(route('admin.settings.domain.subdomain'), ['site_subdomain' => 'my-store'])
            ->assertSessionHasNoErrors();
    }

    /* ---------------------------- طلب التجهيز ---------------------------- */

    public function test_a_purchase_request_is_recorded_for_the_operator(): void
    {
        $this->post(route('admin.settings.domain.request'), [
            'domain' => 'MyStore.om', 'note' => 'أقبل mystore.com أيضًا',
        ])->assertSessionHasNoErrors();

        $req = DomainRequest::where('business_id', $this->bid())->firstOrFail();
        // يُخزَّن بحروفٍ صغيرة: MyStore.om وmystore.om نطاقٌ واحد
        $this->assertSame('mystore.om', $req->domain);
        $this->assertSame(DomainRequest::PENDING, $req->status);
        $this->assertSame(DomainOptions::SERVICE, DomainOptions::mode($this->site()));
    }

    public function test_a_second_pending_request_is_refused(): void
    {
        $this->post(route('admin.settings.domain.request'), ['domain' => 'mystore.om']);
        $this->post(route('admin.settings.domain.request'), ['domain' => 'other.om'])
            ->assertSessionHasErrors('domain');

        $this->assertSame(1, DomainRequest::where('business_id', $this->bid())->count());
    }

    public function test_a_merchant_may_withdraw_a_pending_request(): void
    {
        $this->post(route('admin.settings.domain.request'), ['domain' => 'mystore.om']);
        $id = DomainRequest::where('business_id', $this->bid())->value('id');

        $this->delete(route('admin.settings.domain.request.cancel', $id))->assertSessionHasNoErrors();

        $this->assertSame(0, DomainRequest::where('business_id', $this->bid())->count());
    }

    /**
     * ولا يسحب تاجرٌ طلبَ غيره.
     *
     * المعرّف في المسار، والمتجر في الجلسة. ولو لم يُقيَّد الاستعلام بالمتجر
     * لكان رقمٌ مكتوبٌ بيدٍ في الرابط كافيًا لمحو طلب متجرٍ آخر.
     */
    public function test_a_request_of_another_store_cannot_be_withdrawn(): void
    {
        $other = Business::create(['name' => 'متجر آخر', 'type' => 'عام', 'status' => 'نشط']);
        $req = DomainRequest::create([
            'business_id' => $other->id, 'domain' => 'theirs.om', 'status' => DomainRequest::PENDING,
        ]);

        $this->delete(route('admin.settings.domain.request.cancel', $req->id))->assertNotFound();

        $this->assertDatabaseHas('domain_requests', ['id' => $req->id]);
    }

    /* ------------------------------ التسعير ------------------------------ */

    /**
     * السعر من إعدادات المنصّة — وما لم يُسعَّر لا يُخترع له رقم.
     *
     * `null` تعني «يُحدَّد بالتواصل» و`0.0` تعني «مجاني»، والخلط بينهما وعدٌ
     * لم يقطعه أحد.
     */
    public function test_an_unpriced_option_is_null_not_zero(): void
    {
        $pricing = DomainOptions::pricing();

        $this->assertNull($pricing['subdomain']);
        $this->assertNull($pricing['setup']);
        // ربطُ نطاقٍ يملكه التاجر لا تأخذ عليه أبعاد شيئًا — وهذا ليس إعدادًا
        $this->assertSame(0.0, $pricing['own']);
    }

    public function test_prices_set_by_the_operator_are_what_the_merchant_reads(): void
    {
        Setting::create(['business_id' => null, 'key' => 'domain_subdomain_price', 'value' => '0']);
        Setting::create(['business_id' => null, 'key' => 'domain_setup_price', 'value' => '12.500']);

        $pricing = DomainOptions::pricing();

        $this->assertSame(0.0, $pricing['subdomain']);
        $this->assertSame(12.5, $pricing['setup']);
    }

    public function test_the_operator_can_save_domain_pricing(): void
    {
        $this->actingAs($this->platformAdmin('admin@abaadapp.om'))->post(route('super-admin.settings.update'), [
            'domain_subdomain_price' => '5',
            'domain_setup_price' => '15',
            'domain_subdomain_suffix' => 'abaadapp.om',
        ])->assertSessionHasNoErrors();

        $this->assertSame(5.0, DomainOptions::pricing()['subdomain']);
        $this->assertSame(15.0, DomainOptions::pricing()['setup']);
        $this->assertSame('abaadapp.om', DomainOptions::suffix());
    }

    public function test_the_subdomain_suffix_falls_back_when_unset(): void
    {
        $this->assertSame(DomainOptions::DEFAULT_SUFFIX, DomainOptions::suffix());
        $this->assertSame('my-store.'.DomainOptions::DEFAULT_SUFFIX, DomainOptions::host('my-store'));
    }

    /* -------------------------- لوحة المنصّة -------------------------- */

    public function test_the_operator_closes_a_request_with_a_reply_the_merchant_reads(): void
    {
        $this->post(route('admin.settings.domain.request'), ['domain' => 'mystore.om']);
        $id = DomainRequest::where('business_id', $this->bid())->value('id');

        $admin = $this->platformAdmin('admin2@abaadapp.om');

        $this->actingAs($admin)->post(route('super-admin.domains.status', $id), [
            'status' => DomainRequest::DONE, 'note' => 'اشتُري وضُبط',
        ])->assertSessionHasNoErrors();

        $req = DomainRequest::find($id);
        $this->assertSame(DomainRequest::DONE, $req->status);
        $this->assertSame('اشتُري وضُبط', $req->note);
        $this->assertNotNull($req->handled_at);
        $this->assertSame($admin->id, $req->handled_by);
    }

    /**
     * و«تمّ التجهيز» تُنزل النطاق في إعدادات المتجر.
     *
     * بدونها يقرأ التاجر «مكتمل» ويبقى حقلُ نطاقه فارغًا، فلا يظهر ما
     * اشتريناه له في فاتورةٍ ولا في شاشة السيو.
     */
    public function test_completing_a_request_writes_the_domain_into_the_store(): void
    {
        $this->post(route('admin.settings.domain.request'), ['domain' => 'mystore.om']);
        $id = DomainRequest::where('business_id', $this->bid())->value('id');

        $this->actingAs($this->platformAdmin('done@abaadapp.om'))
            ->post(route('super-admin.domains.status', $id), ['status' => DomainRequest::DONE])
            ->assertSessionHasNoErrors();

        $site = $this->site();
        $this->assertSame('mystore.om', $site['site_domain']);
        $this->assertSame(DomainOptions::OWN, DomainOptions::mode($site));
    }

    /**
     * ولا يُكتب فوق نطاقٍ يعمل.
     *
     * تاجرٌ على `live.om` يطلب `extra.om`: إغلاقُ الطلب يجب ألّا يبدّل عنوانه
     * في فواتيره لأنّ المشغّل ضغط زرًّا.
     */
    public function test_completing_a_request_never_overwrites_a_live_domain(): void
    {
        $this->post(route('admin.marketing.website.save'), ['site_domain' => 'live.om']);
        $this->post(route('admin.settings.domain.request'), ['domain' => 'extra.om']);
        $id = DomainRequest::where('business_id', $this->bid())->value('id');

        $this->actingAs($this->platformAdmin('keep@abaadapp.om'))
            ->post(route('super-admin.domains.status', $id), ['status' => DomainRequest::DONE])
            ->assertSessionHasNoErrors();

        $this->assertSame('live.om', $this->site()['site_domain']);
    }

    /** والرفض بلا سببٍ يجعل صاحبه يعيد الطلب نفسه */
    public function test_a_rejection_without_a_reason_is_refused(): void
    {
        $this->post(route('admin.settings.domain.request'), ['domain' => 'mystore.om']);
        $id = DomainRequest::where('business_id', $this->bid())->value('id');

        $this->actingAs($this->platformAdmin('admin3@abaadapp.om'))->post(route('super-admin.domains.status', $id), [
            'status' => DomainRequest::REJECTED,
        ])->assertSessionHasErrors('note');

        $this->assertSame(DomainRequest::PENDING, DomainRequest::find($id)->status);
    }

    public function test_a_merchant_cannot_close_their_own_request(): void
    {
        $this->post(route('admin.settings.domain.request'), ['domain' => 'mystore.om']);
        $id = DomainRequest::where('business_id', $this->bid())->value('id');

        $this->post(route('super-admin.domains.status', $id), [
            'status' => DomainRequest::DONE,
        ])->assertForbidden();

        $this->assertSame(DomainRequest::PENDING, DomainRequest::find($id)->status);
    }

    /* ------------------------------ الشاشة ------------------------------ */

    public function test_the_settings_screen_carries_the_options_and_their_prices(): void
    {
        Setting::create(['business_id' => null, 'key' => 'domain_setup_price', 'value' => '15']);

        $this->get(route('admin.settings.index', ['section' => 'domain']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('domain.mode', '')
                ->where('domain.pricing.setup', 15)
                ->where('domain.pricing.subdomain', null)
                ->where('domain.suffix', DomainOptions::DEFAULT_SUFFIX)
                ->where('domain.subdomain', '')
                ->where('domain.request', null));
    }

    /* ---------------------------- الصلاحيات ---------------------------- */

    /**
     * ومن لا يملك «الإعدادات» لا يمسّ الدومين.
     *
     * الأفعال الأربعة تحت أسماءٍ تبدأ بـ`admin.settings.` فتقع على صلاحيتها
     * (انظر Permissions::sectionFromRoute). واسمٌ ينحرف عن هذا النمط يصنع
     * مفتاحًا لا وجود له — فيُفتح البابُ لكلّ من في المتجر بدل أن يُغلق.
     */
    public function test_a_cashier_cannot_touch_the_domain_routes(): void
    {
        $this->actingAs($this->cashier());

        $this->post(route('admin.settings.domain.mode'), ['site_domain_mode' => 'own'])->assertForbidden();
        $this->post(route('admin.settings.domain.subdomain'), ['site_subdomain' => 'abc'])->assertForbidden();
        $this->post(route('admin.settings.domain.request'), ['domain' => 'x.om'])->assertForbidden();
        $this->delete(route('admin.settings.domain.request.cancel', 1))->assertForbidden();
    }

    public function test_a_merchant_cannot_open_the_platform_queue(): void
    {
        $this->get(route('super-admin.domains.index'))->assertForbidden();
    }

    /* -------------------------- سلامة الإعدادات -------------------------- */

    /**
     * وحفظُ «إعدادات الموقع» لا يمحو الخيار ولا الاسم المحجوز.
     *
     * البطاقتان تتشاركان مجموعةَ `website` نفسها. ولو مرّ المفتاحان الجديدان
     * في نموذج الموقع لَمحاهما كلُّ حفظِ وصفٍ أو رقمِ واتساب — بصمتٍ تامّ.
     */
    public function test_saving_website_settings_keeps_the_mode_and_the_reserved_name(): void
    {
        $this->post(route('admin.settings.domain.subdomain'), ['site_subdomain' => 'my-store']);

        $this->post(route('admin.marketing.website.save'), ['site_domain' => 'mystore.om'])
            ->assertSessionHasNoErrors();

        $site = $this->site();
        $this->assertSame('my-store', $site['site_subdomain']);
        $this->assertSame(DomainOptions::SUBDOMAIN, DomainOptions::mode($site));
    }

    /** ولا صفَّ مكرّرًا لمفتاحٍ واحد مهما تكرّر الحفظ — الجدول بلا قيدٍ فريد */
    public function test_repeated_saves_never_duplicate_setting_rows(): void
    {
        foreach ([DomainOptions::OWN, DomainOptions::SUBDOMAIN, DomainOptions::SERVICE] as $m) {
            $this->post(route('admin.settings.domain.mode'), ['site_domain_mode' => $m]);
        }
        $this->post(route('admin.settings.domain.subdomain'), ['site_subdomain' => 'my-store']);
        $this->post(route('admin.settings.domain.subdomain'), ['site_subdomain' => 'my-store2']);

        $rows = fn (string $key) => DB::table('settings')
            ->where('business_id', $this->bid())->where('key', $key)->count();

        $this->assertSame(1, $rows('site_domain_mode'));
        $this->assertSame(1, $rows('site_subdomain'));
    }

    /** ولا يقرأ متجرٌ طلبَ متجرٍ آخر من شاشته */
    public function test_the_screen_shows_only_this_stores_request(): void
    {
        $other = Business::create(['name' => 'آخر', 'type' => 'عام', 'status' => 'نشط']);
        DomainRequest::create([
            'business_id' => $other->id, 'domain' => 'theirs.om', 'status' => DomainRequest::PENDING,
        ]);

        $this->get(route('admin.settings.index', ['section' => 'domain']))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->where('domain.request', null));
    }

    /**
     * وطلبٌ أُغلق لا يُغلق مرّةً أخرى.
     *
     * «مكتمل» يعني نطاقًا اشتُري ونُزّل في إعدادات المتجر. وقلبُه «مرفوضًا»
     * يجعل التاجر يقرأ رفضًا لنطاقٍ يعمل على فواتيره.
     */
    public function test_a_closed_request_cannot_be_closed_again(): void
    {
        $req = DomainRequest::create([
            'business_id' => $this->bid(), 'domain' => 'done.om', 'status' => DomainRequest::DONE,
        ]);

        $this->actingAs($this->platformAdmin('twice@abaadapp.om'))
            ->post(route('super-admin.domains.status', $req->id), [
                'status' => DomainRequest::REJECTED, 'note' => 'تراجع',
            ])->assertSessionHasErrors('status');

        $this->assertSame(DomainRequest::DONE, DomainRequest::find($req->id)->status);
    }

    /**
     * والسعر سعرُ المنصّة لا سعرُ بضاعة التاجر.
     *
     * لو مرّ بـ`money()` لضُرب في سعر صرف عملة العرض التي يختارها التاجر
     * لمتجره — فيرى من بدّل عرضه رسومَ تجهيزٍ محوّلةً بسعرٍ ضبطه هو لبضاعته.
     */
    public function test_pricing_ignores_the_merchants_display_currency(): void
    {
        Setting::create(['business_id' => null, 'key' => 'domain_setup_price', 'value' => '15']);
        Setting::create(['business_id' => $this->bid(), 'key' => 'currency', 'value' => 'USD']);

        $this->assertSame(15.0, DomainOptions::pricing()['setup']);
    }

    /** واللاحقة تتبع المشغّل لا ثابتًا مدفونًا */
    public function test_the_suffix_follows_the_operator(): void
    {
        Setting::create(['business_id' => null, 'key' => DomainOptions::SUFFIX_KEY, 'value' => 'abaad.shop']);

        $this->assertSame('abaad.shop', DomainOptions::suffix());
        $this->assertSame('x.abaad.shop', DomainOptions::host('x'));

        $this->get(route('admin.settings.index', ['section' => 'domain']))
            ->assertInertia(fn ($p) => $p->where('domain.suffix', 'abaad.shop'));
    }

    /**
     * ومسحُ السعر يعود به إلى «بالتواصل» لا إلى صفر.
     *
     * الصفر يقول «مجاني» — وعدٌ يقطعه المشغّل بمسحِ حقلٍ لا بكتابةِ قرار.
     * وهو الفرق كلّه بين هذين المفتاحين.
     */
    public function test_clearing_a_price_returns_to_priced_on_request(): void
    {
        Setting::create(['business_id' => null, 'key' => 'domain_setup_price', 'value' => '15']);
        $this->assertSame(15.0, DomainOptions::pricing()['setup']);

        $this->actingAs($this->platformAdmin('clear@abaadapp.om'))
            ->post(route('super-admin.settings.update'), ['domain_setup_price' => ''])
            ->assertSessionHasNoErrors();

        $this->assertNull(DomainOptions::pricing()['setup']);
    }

    public function test_zero_is_kept_as_zero_and_reads_free(): void
    {
        $this->actingAs($this->platformAdmin('zero@abaadapp.om'))
            ->post(route('super-admin.settings.update'), ['domain_subdomain_price' => '0'])
            ->assertSessionHasNoErrors();

        $this->assertSame(0.0, DomainOptions::pricing()['subdomain']);
    }

    /** والمشغّل يكتب بأرقامٍ عربية أحيانًا — انظر NormalizeMoneyInput */
    public function test_arabic_numerals_in_a_price_are_normalised(): void
    {
        $this->actingAs($this->platformAdmin('ar@abaadapp.om'))
            ->post(route('super-admin.settings.update'), ['domain_setup_price' => '١٢٫٥٠٠'])
            ->assertSessionHasNoErrors();

        $this->assertSame(12.5, DomainOptions::pricing()['setup']);
    }

    public function test_a_bogus_price_or_suffix_is_refused(): void
    {
        $admin = $this->platformAdmin('bogus@abaadapp.om');

        $this->actingAs($admin)->post(route('super-admin.settings.update'), ['domain_setup_price' => '-5'])
            ->assertSessionHasErrors('domain_setup_price');
        $this->actingAs($admin)->post(route('super-admin.settings.update'), ['domain_setup_price' => 'مجانا'])
            ->assertSessionHasErrors('domain_setup_price');
        // اللاحقة نطاقٌ لا رابط: «https://» فيها تبني عناوين معطوبة
        $this->actingAs($admin)->post(route('super-admin.settings.update'), ['domain_subdomain_suffix' => 'https://abaadapp.om'])
            ->assertSessionHasErrors('domain_subdomain_suffix');
    }
}
