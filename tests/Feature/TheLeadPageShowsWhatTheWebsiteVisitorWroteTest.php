<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\CrmLead;
use App\Models\CrmNote;
use App\Models\Customer;
use App\Models\User;
use App\Support\Crm;
use App\Support\CrmLeads;
use App\Support\CrmWebsiteInquiries;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ملفُّ العميل المحتمل يعرض طلبَ «تواصل معنا» حقولًا — لا ملاحظةً يُبحث فيها.
 *
 * الطلبُ محفوظٌ ملاحظةً كاتبُها «الموقع الإلكتروني» (لا أعمدةَ للبريد والنوع
 * والرسالة). فيُستخرج في الخادم مرّةً (`CrmWebsiteInquiries::forLead`)، ويصل
 * الشاشةَ `websiteInquiries`: كلُّ الطلبات، الأحدثُ أوّلًا. والملاحظةُ نفسُها
 * باقيةٌ في `notes` كما كُتبت، والعميلُ الذي لم يكتب من الموقع لا قسمَ له.
 */
class TheLeadPageShowsWhatTheWebsiteVisitorWroteTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->staff = User::create([
            'name' => 'سالم', 'email' => 'salem@abaad.om',
            'password' => bcrypt('secret'), 'role' => 'super_admin', 'status' => 'active',
        ]);
        config(['mail.default' => 'log']); // البريدُ على HOLD — كما على الإنتاج
    }

    /** طلبُ الموقع كما يكتبه البابُ العامّ نفسُه */
    private function inquiry(array $over = []): CrmLead
    {
        return CrmWebsiteInquiries::receive(array_merge([
            'name' => 'Abaad Website Test',
            'business_name' => 'Abaad Website Test',
            'phone' => '90000000',
            'email' => 'abaadappom@gmail.com',
            'activity_type' => 'other',
            'message' => 'Website to CRM production verification',
            'locale' => 'ar',
        ], $over))['lead'];
    }

    private function page(CrmLead $lead, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->staff)->get(route('super-admin.crm.leads.show', $lead->id))->assertOk();
    }

    /* ═════════════════════ طلبٌ واحد ═════════════════════ */

    public function test_a_website_lead_shows_every_field_the_visitor_wrote(): void
    {
        $lead = $this->inquiry();
        $note = $lead->notes()->sole();

        $this->page($lead)->assertInertia(fn ($page) => $page
            ->where('lead.source', Crm::SOURCE_WEBSITE)
            ->has('websiteInquiries', 1)
            ->where('websiteInquiries.0', [
                'id' => $note->id,
                'name' => 'Abaad Website Test',
                'businessName' => 'Abaad Website Test',
                'phone' => '90000000',
                'email' => 'abaadappom@gmail.com',
                'activity' => 'أخرى',
                'locale' => 'العربية',
                'message' => 'Website to CRM production verification',
                'receivedAt' => $note->created_at->format('Y-m-d H:i'),
            ])
            // والملاحظةُ الأصليّة باقيةٌ سجلًّا كما كُتبت
            ->where('notes.0.body', $note->body)
            ->where('notes.0.author', CrmWebsiteInquiries::AUTHOR));
    }

    /** ولا تُملأ حقولُ الدفتر التي لم يقلها الزائر */
    public function test_nothing_is_guessed_into_crm_fields_the_visitor_never_gave(): void
    {
        $lead = $this->inquiry();

        $this->page($lead)->assertInertia(fn ($page) => $page
            ->where('lead.wilayat', null)
            ->where('lead.branchesCount', null)
            ->where('lead.currentSystem', null)
            ->where('lead.planId', null)
            ->where('lead.expectedValue', null)
            ->where('lead.nextFollowUpAt', null));
    }

    /* ═════════════════════ طلبان من رقمٍ واحد ═════════════════════ */

    public function test_two_requests_from_one_phone_show_both_newest_first(): void
    {
        $this->inquiry(['message' => 'الطلب الأول: نريد نقطة بيع.']);
        $this->travel(2)->days();
        $lead = $this->inquiry(['message' => "الطلب الثاني: نريد متجرًا أيضًا.\nوسطرٌ ثانٍ.", 'activity_type' => 'ecommerce']);

        $this->assertSame(1, CrmLead::count(), 'رقمٌ واحدٌ صار عميلَين');

        $this->page($lead)->assertInertia(fn ($page) => $page
            ->has('websiteInquiries', 2)
            ->where('websiteInquiries.0.message', "الطلب الثاني: نريد متجرًا أيضًا.\nوسطرٌ ثانٍ.")
            ->where('websiteInquiries.0.activity', 'التجارة الإلكترونية')
            ->where('websiteInquiries.1.message', 'الطلب الأول: نريد نقطة بيع.')
            ->where('websiteInquiries.1.activity', 'أخرى')
            ->where('websiteInquiries.0.receivedAt', fn ($at) => $at > $page->toArray()['props']['websiteInquiries'][1]['receivedAt'])
            ->has('notes', 2));
    }

    /* ═════════════════════ عملاءُ بلا طلب موقع ═════════════════════ */

    public function test_manual_and_whatsapp_leads_open_without_a_website_section(): void
    {
        $manual = CrmLeads::findOrCreateByPhone('91111111', Crm::SOURCE_MANUAL, 'يدويّ')['lead'];
        $whatsapp = CrmLeads::findOrCreateByPhone('92222222', Crm::SOURCE_WHATSAPP, 'واتساب')['lead'];
        $legacy = CrmLead::create([
            'phone' => '96893333333', 'name' => 'قديم', 'source' => 'website',
            'status' => Crm::ACTIVE, 'stage' => Crm::CONTACTED,
        ]);
        CrmLeads::note($whatsapp, $this->staff, 'اتصلتُ به وطلب عرض سعر.');

        foreach ([$manual, $whatsapp, $legacy] as $lead) {
            $this->page($lead)->assertInertia(fn ($page) => $page->where('websiteInquiries', []));
        }
    }

    /** ملاحظةُ موظّفٍ تبدأ بسطر العنوان نفسِه ليست طلبَ موقع — الكاتبُ هو الحارس لا النصّ */
    public function test_ordinary_notes_are_never_read_as_website_requests(): void
    {
        $lead = CrmLeads::findOrCreateByPhone('94444444', Crm::SOURCE_MANUAL, 'عميل')['lead'];

        CrmLeads::note($lead, $this->staff, CrmWebsiteInquiries::HEADER."\nالاسم: منسوخ\nالبريد: copied@example.com\nالرسالة:\nنسختُ طلبًا من بريد");
        CrmNote::create(['lead_id' => $lead->id, 'user_id' => null, 'user_name' => 'النظام', 'body' => CrmWebsiteInquiries::HEADER."\nالاسم: نظام"]);

        $this->page($lead)->assertInertia(fn ($page) => $page->where('websiteInquiries', [])->has('notes', 2));
    }

    /* ═════════════════════ ملاحظاتٌ ناقصة أو قديمة ═════════════════════ */

    public function test_a_partial_or_legacy_website_note_does_not_break_the_page(): void
    {
        $lead = CrmLeads::findOrCreateByPhone('95555555', Crm::SOURCE_WEBSITE, 'ناقص')['lead'];
        $at = fn (int $minutes) => now()->subMinutes($minutes);
        $write = fn (string $body, int $minutes) => CrmNote::forceCreate([
            'lead_id' => $lead->id, 'user_id' => null, 'user_name' => CrmWebsiteInquiries::AUTHOR,
            'body' => $body, 'created_at' => $at($minutes), 'updated_at' => $at($minutes),
        ]);

        $write('ملاحظةٌ آليّةٌ قديمة بلا سطر العنوان', 40);                    // ليست طلبًا — تُترك
        $write(CrmWebsiteInquiries::HEADER, 30);                              // العنوانُ وحده
        $write(CrmWebsiteInquiries::HEADER."\r\nالاسم: ريم\r\nنوع النشاط: نوعٌ لا نعرفه\r\nالرسالة:\r\nسطرٌ\r\nالاسم: ليس اسمًا بل من الرسالة", 20);

        $this->page($lead)->assertInertia(fn ($page) => $page
            ->has('websiteInquiries', 2)
            ->where('websiteInquiries.0', fn ($q) => $q['name'] === 'ريم'
                && $q['activity'] === 'نوعٌ لا نعرفه'                          // يُعرض كما حُفظ، لا يُخمَّن
                && $q['email'] === null && $q['phone'] === null && $q['locale'] === null
                && $q['message'] === "سطرٌ\nالاسم: ليس اسمًا بل من الرسالة")
            ->where('websiteInquiries.1', fn ($q) => collect($q)->except(['id', 'receivedAt'])->filter()->isEmpty())
            ->has('notes', 3));
    }

    /** والقراءةُ والكتابةُ من الثوابت نفسِها: ما يكتبه `receive` يُقرأ كاملًا */
    public function test_what_is_written_is_read_back_whole(): void
    {
        $lead = $this->inquiry(['name' => 'Sara', 'business_name' => 'Rose: Shop', 'activity_type' => 'services', 'locale' => 'en', 'message' => "سطر\n\nبعد سطرٍ فارغ"]);

        // خارج الطلب لا يضبط أحدٌ اللغة — والتسميتان تُترجمان بلغة القارئ
        app()->setLocale('ar');
        $parsed = CrmWebsiteInquiries::parse($lead->notes()->sole()->body);

        $this->assertSame([
            'name' => 'Sara', 'businessName' => 'Rose: Shop', 'phone' => '90000000', 'email' => 'abaadappom@gmail.com',
            'activity' => 'الخدمات والحجوزات', 'locale' => 'الإنجليزية', 'message' => "سطر\n\nبعد سطرٍ فارغ",
        ], $parsed);
        $this->assertNull(CrmWebsiteInquiries::parse('ملاحظة عادية'));

        app()->setLocale('en');
        $this->assertSame(['Services & bookings', 'English'], array_values(array_intersect_key(
            CrmWebsiteInquiries::parse($lead->notes()->sole()->body), array_flip(['activity', 'locale']))));
    }

    /**
     * والنصُّ المحفوظ ثابتٌ حرفًا بحرف — كما كُتب على الإنتاج قبل هذه الشاشة.
     *
     * ملاحظاتٌ قائمةٌ بهذا الشكل تُقرأ به، والتكرارُ الحرفيّ يُقارن به. فتغييرُ
     * فاصلٍ أو تسميةٍ يفصل الجديدَ عن القديم، ولو بقي المستخرِجُ يتسامح معه.
     */
    public function test_the_stored_note_keeps_its_exact_production_shape(): void
    {
        $lead = $this->inquiry();

        $this->assertSame(
            "طلب تواصل من الموقع الإلكتروني\nالاسم: Abaad Website Test\nاسم النشاط: Abaad Website Test\nالهاتف: 90000000\n"
            ."البريد: abaadappom@gmail.com\nنوع النشاط: أخرى\nلغة الصفحة: العربية\nالرسالة:\nWebsite to CRM production verification",
            $lead->notes()->sole()->body,
        );
    }

    /* ═════════════════════ اللغتان ═════════════════════ */

    public function test_the_english_panel_reads_the_type_and_language_in_english(): void
    {
        $lead = $this->inquiry(['activity_type' => 'retail']);
        $this->staff->forceFill(['locale' => 'en'])->save();

        $this->page($lead)->assertInertia(fn ($page) => $page
            ->where('websiteInquiries.0.activity', 'Retail')
            ->where('websiteInquiries.0.message', 'Website to CRM production verification'));

        $this->staff->forceFill(['locale' => 'ar'])->save();
        $this->page($lead)->assertInertia(fn ($page) => $page->where('websiteInquiries.0.activity', 'التجزئة'));
    }

    /* ═════════════════════ ما حوله ═════════════════════ */

    public function test_showing_the_request_touches_no_merchant_data(): void
    {
        $shop = Business::create(['name' => 'محل ورد', 'type' => 'محل ورود', 'status' => 'active']);
        Customer::create(['business_id' => $shop->id, 'name' => 'مريم', 'phone' => '90000000']);
        $lead = $this->inquiry();

        $tables = ['businesses', 'customers', 'orders', 'crm_leads', 'crm_notes'];
        $before = collect($tables)->mapWithKeys(fn ($t) => [$t => DB::table($t)->count()]);

        $this->page($lead)->assertInertia(fn ($page) => $page
            ->where('websiteInquiries.0.name', 'Abaad Website Test'));

        $this->assertEquals($before, collect($tables)->mapWithKeys(fn ($t) => [$t => DB::table($t)->count()]));
        $this->assertStringNotContainsString('مريم', json_encode($this->page($lead)->viewData('page'), JSON_UNESCAPED_UNICODE));
    }
}
