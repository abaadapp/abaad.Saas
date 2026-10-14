<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\CrmLead;
use App\Models\CrmMessage;
use App\Models\CrmNote;
use App\Models\CrmRead;
use App\Models\CrmWebsiteInquiryRead;
use App\Models\Customer;
use App\Models\User;
use App\Support\Crm;
use App\Support\CrmWebsiteInquiries;
use App\Support\CrmWhatsApp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * طلبُ «تواصل معنا» يُرى في CRM غيرَ مقروء — لكلّ موظّفٍ حتّى يفتحه هو.
 *
 * شارةُ «العملاء المحتملون» تعدّ العملاءَ لا الطلبات، وفتحُ ملفّ العميل يقرأ
 * طلباته حتّى أحدثِها لحظتَها — لقارئه وحدَه. وهي أختُ شارة المحادثات لا هي:
 * قراءةُ واتساب في `crm_reads`، وقراءةُ طلبات الموقع في `crm_website_inquiry_reads`،
 * ولا يُطفئ أحدُهما الآخر. وما كان قبل الميزة لا يُحسب.
 */
class AWebsiteInquiryWaitsUnreadInTheCrmTest extends TestCase
{
    use RefreshDatabase;

    private const SITE = 'https://abaadapp.om';

    private const MIGRATION = 'database/migrations/2026_10_14_120000_a_website_inquiry_is_unread_until_its_reader_opens_it.php';

    private User $a;

    private User $b;

    private int $ip = 1;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');
        Http::preventStrayRequests();
        Mail::fake();
        Queue::fake();

        // البريدُ على HOLD كما على الإنتاج
        config(['mail.default' => 'log']);

        $this->a = User::create([
            'name' => 'سالم', 'email' => 'a@abaad.om', 'password' => bcrypt('x'),
            'role' => 'super_admin', 'status' => 'active', 'business_id' => null,
        ]);
        $this->b = User::create([
            'name' => 'مريم', 'email' => 'b@abaad.om', 'password' => bcrypt('x'),
            'role' => 'super_admin', 'status' => 'active', 'business_id' => null,
        ]);
    }

    /** طلبٌ من الموقع بالطريق الحقيقيّ — عنوانٌ مختلفٌ لكلّ طلب فلا يُخنق */
    private function inquire(string $phone = '9123 4567', string $message = 'أريد نظام نقاط بيع لمحلّي.'): TestResponse
    {
        return $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.'.$this->ip++])->post(route('website.contact'), [
            'name' => 'محمد', 'business_name' => 'ورد الخوير', 'phone' => $phone,
            'email' => 'm@example.com', 'activity_type' => 'retail', 'message' => $message, 'locale' => 'ar',
        ], ['Origin' => self::SITE, 'Accept' => 'application/json'])->assertOk();
    }

    private function leadOf(string $phone = '96891234567'): CrmLead
    {
        return CrmLead::where('phone', $phone)->firstOrFail();
    }

    private function open(User $user, CrmLead $lead): TestResponse
    {
        return $this->actingAs($user)->get(route('super-admin.crm.leads.show', $lead->id))->assertOk();
    }

    private function badgeOnScreen(User $user): int
    {
        $badge = null;
        $this->actingAs($user)->get(route('super-admin.crm.dashboard'))->assertOk()
            ->assertInertia(function (Assert $p) use (&$badge) {
                $badge = $p->toArray()['props']['crmLeadsBadge'];
            });

        return $badge;
    }

    /** @return array<int, bool> رقمُ العميل ← هل عليه «طلب موقع جديد» */
    private function rows(User $user): array
    {
        $rows = [];
        $this->actingAs($user)->get(route('super-admin.crm.leads.index'))->assertOk()
            ->assertInertia(function (Assert $p) use (&$rows) {
                foreach ($p->toArray()['props']['leads'] as $row) {
                    $rows[$row['id']] = $row['hasUnreadWebsiteInquiry'];
                }
            });
        ksort($rows);

        return $rows;
    }

    private function manualLead(string $phone, string $source = Crm::SOURCE_MANUAL): CrmLead
    {
        return CrmLead::create([
            'phone' => $phone, 'phone_raw' => $phone, 'name' => 'عميل', 'source' => $source,
            'status' => Crm::ACTIVE, 'stage' => Crm::NEW, 'last_contact_at' => now(),
        ]);
    }

    /* ═══════════════ ١ إلى ٤ — لكلّ قارئٍ حالُه ═══════════════ */

    public function test_a_new_inquiry_is_unread_for_every_super_admin_until_each_opens_it(): void
    {
        $this->inquire();

        // ١ — يراه A
        $this->assertSame(1, $this->badgeOnScreen($this->a));

        // ٢ — يفتحه A فتنطفئ عنه، في صفحة الفتح نفسِها
        $this->open($this->a, $this->leadOf())
            ->assertInertia(fn (Assert $p) => $p->where('crmLeadsBadge', 0));
        $this->assertSame(0, $this->badgeOnScreen($this->a));

        // ٣ — ولا تنطفئ عن B
        $this->assertSame(1, $this->badgeOnScreen($this->b));
        $this->assertSame([$this->leadOf()->id => true], $this->rows($this->b));
        $this->assertSame([$this->leadOf()->id => false], $this->rows($this->a));

        // ٤ — وطلبٌ جديدٌ للعميل نفسِه يعيدها لكلّ من لم يقرأه
        $this->inquire(message: 'وعندي فرعٌ ثانٍ في صحار.');
        $this->assertSame(1, $this->badgeOnScreen($this->a));
        $this->assertSame(1, $this->badgeOnScreen($this->b));
        $this->assertSame(1, CrmLead::count());
    }

    public function test_opening_reads_only_what_existed_at_that_moment(): void
    {
        $this->inquire();
        $lead = $this->leadOf();
        $this->open($this->a, $lead);

        $cursor = CrmWebsiteInquiryRead::where('user_id', $this->a->id)->value('last_read_note_id');
        $this->assertSame(CrmNote::max('id'), (int) $cursor);

        $this->inquire(message: 'وصل بعد أن فتحتَ الملف.');
        $this->assertSame(1, CrmWebsiteInquiries::badge($this->a));
        $this->assertSame((int) $cursor, (int) CrmWebsiteInquiryRead::where('user_id', $this->a->id)->value('last_read_note_id'));
    }

    /* ═══════════════ ٥ و٦ — العملاءُ لا الطلبات ═══════════════ */

    public function test_three_inquiries_from_one_lead_count_once_and_two_leads_count_two(): void
    {
        $this->inquire(message: 'الأولى');
        $this->inquire(message: 'الثانية');
        $this->inquire(message: 'الثالثة');
        $this->assertSame(3, CrmNote::count());
        $this->assertSame(1, $this->badgeOnScreen($this->a));

        $this->inquire('9876 5432', 'من عميلٍ ثانٍ');
        $this->assertSame(2, $this->badgeOnScreen($this->a));
    }

    /* ═══════════════ ٧ — التكرارُ الحرفيّ لا يُعيدها ═══════════════ */

    public function test_an_exact_resend_writes_no_note_and_lights_nothing(): void
    {
        $this->inquire();
        $this->open($this->a, $this->leadOf());

        $this->inquire();

        $this->assertSame(1, CrmNote::count());
        $this->assertSame(0, $this->badgeOnScreen($this->a));
    }

    /* ═══════════════ ٨ و٩ — ما ليس طلبَ موقع لا يُعدّ ═══════════════ */

    public function test_staff_system_and_malformed_notes_are_not_website_inquiries(): void
    {
        $lead = $this->manualLead('96890001111');

        CrmNote::create(['lead_id' => $lead->id, 'user_id' => $this->a->id, 'user_name' => 'سالم',
            'body' => CrmWebsiteInquiries::HEADER."\nالاسم: نسخها موظّف"]);
        CrmNote::create(['lead_id' => $lead->id, 'user_id' => null, 'user_name' => 'النظام',
            'body' => CrmWebsiteInquiries::HEADER]);
        // كاتبُها الموقع ولا عنوانَ لها — قديمةٌ أو مشوّهة
        CrmNote::create(['lead_id' => $lead->id, 'user_id' => null, 'user_name' => CrmWebsiteInquiries::AUTHOR,
            'body' => "شيءٌ آخر\nبلا عنوان"]);

        $this->assertSame(0, $this->badgeOnScreen($this->a));
        $this->assertSame([$lead->id => false], $this->rows($this->a));

        // والصفحةُ تُفتح، ولا يُكتب صفُّ قراءة
        $this->open($this->a, $lead)->assertInertia(fn (Assert $p) => $p->where('websiteInquiries', []));
        $this->assertSame(0, CrmWebsiteInquiryRead::count());
    }

    /* ═══════════════ ١٠ و١١ — العميلُ اليدويّ وعميلُ واتساب ═══════════════ */

    public function test_manual_and_whatsapp_leads_carry_no_flag_until_a_website_inquiry_arrives(): void
    {
        $manual = $this->manualLead('96890001111');
        $whatsapp = $this->manualLead('96891234567', Crm::SOURCE_WHATSAPP);

        $this->assertSame([$manual->id => false, $whatsapp->id => false], $this->rows($this->a));

        // عميلُ واتساب يكتب لاحقًا من الموقع — العميلُ نفسُه، والآن عليه الشارة
        $this->inquire('9123 4567');
        $this->assertSame(2, CrmLead::count());
        $this->assertSame(Crm::SOURCE_WHATSAPP, $whatsapp->fresh()->source);
        $this->assertSame([$manual->id => false, $whatsapp->id => true], $this->rows($this->a));
    }

    /* ═══════════════ ١٢ إلى ١٤ — واتساب وطلبات الموقع لا يلتقيان ═══════════════ */

    public function test_the_whatsapp_badge_is_the_same_before_and_after_and_neither_reads_the_other(): void
    {
        $lead = $this->manualLead('96891234567', Crm::SOURCE_WHATSAPP);
        CrmMessage::create(['lead_id' => $lead->id, 'direction' => CrmMessage::IN, 'body' => 'مرحبا',
            'external_message_id' => 'wamid.IN1']);

        $before = CrmWhatsApp::badge($this->a);
        $this->assertSame(1, $before);

        $this->inquire('9123 4567');

        // ١٢ — شارةُ المحادثات لا تتحرّك بطلب الموقع، والشارتان لا تُجمعان
        $this->actingAs($this->a)->get(route('super-admin.crm.dashboard'))
            ->assertInertia(fn (Assert $p) => $p->where('crmBadge', $before)->where('crmLeadsBadge', 1));

        // ١٤ — فتحُ المحادثة لا يقرأ طلبَ الموقع
        $this->actingAs($this->a)->get(route('super-admin.crm.conversations', ['lead' => $lead->id]))->assertOk();
        $this->assertSame(0, CrmWhatsApp::badge($this->a));
        $this->assertSame(1, CrmWebsiteInquiries::badge($this->a));
        $this->assertSame(0, CrmWebsiteInquiryRead::count());

        // ١٣ — وفتحُ الملفّ لا يقرأ رسالةَ واتساب جديدة
        CrmMessage::create(['lead_id' => $lead->id, 'direction' => CrmMessage::IN, 'body' => 'ألو؟',
            'external_message_id' => 'wamid.IN2']);
        $reads = CrmRead::all()->toArray();

        $this->open($this->a, $lead);

        $this->assertSame(1, CrmWhatsApp::badge($this->a));
        $this->assertSame(0, CrmWebsiteInquiries::badge($this->a));
        $this->assertSame($reads, CrmRead::all()->toArray());
    }

    /* ═══════════════ القراءةُ لا تمسّ العميلَ ولا الملاحظة ═══════════════ */

    public function test_opening_changes_nothing_on_the_lead_or_its_notes(): void
    {
        $this->inquire();
        $lead = $this->leadOf();
        $lead->forceFill(['stage' => Crm::CONTACTED, 'assigned_to' => $this->b->id])->save();

        $leadBefore = DB::table('crm_leads')->where('id', $lead->id)->first();
        $notesBefore = DB::table('crm_notes')->get()->toArray();

        $this->travel(2)->hours();
        $this->open($this->a, $lead);

        $this->assertEquals($leadBefore, DB::table('crm_leads')->where('id', $lead->id)->first());
        $this->assertEquals($notesBefore, DB::table('crm_notes')->get()->toArray());
    }

    /* ═══════════════ ١٥ — لا بياناتُ تاجر ═══════════════ */

    public function test_no_merchant_data_is_read_or_changed_and_a_merchant_sees_no_badge(): void
    {
        $shop = Business::create(['name' => 'محل ورد', 'type' => 'محل ورود', 'status' => 'active']);
        Customer::create(['business_id' => $shop->id, 'name' => 'مريم', 'phone' => '91234567']);
        $merchant = User::create([
            'name' => 'تاجر', 'email' => 'm@shop.om', 'password' => bcrypt('x'),
            'role' => 'admin', 'status' => 'active', 'business_id' => $shop->id,
        ]);

        $tables = ['businesses', 'customers', 'orders', 'invoices', 'customer_invoices',
            'support_conversations', 'support_messages', 'whatsapp_messages', 'crm_messages', 'crm_reads'];
        $snapshot = fn () => collect($tables)->mapWithKeys(fn ($t) => [$t => DB::table($t)->get()->toJson()])->all();
        $before = $snapshot();

        $this->inquire();
        $this->rows($this->a);
        $this->open($this->a, $this->leadOf());

        $this->assertSame($before, $snapshot());
        $this->assertSame(0, CrmWebsiteInquiries::badge($merchant));
    }

    /* ═══════════════ ١٦ — بلا N+1 ═══════════════ */

    public function test_the_list_reads_unread_state_in_a_fixed_number_of_queries(): void
    {
        $queriesFor = function (int $leads): int {
            for ($i = CrmLead::count(); $i < $leads; $i++) {
                $this->inquire('9'.(1000000 + $i), 'طلبٌ رقم '.$i);
            }

            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($this->a)->get(route('super-admin.crm.leads.index'))->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };

        $few = $queriesFor(2);
        $many = $queriesFor(12);

        $this->assertSame(12, collect($this->rows($this->a))->filter()->count());
        $this->assertSame($few, $many, 'عددُ الاستعلامات يكبر مع عدد الصفوف');
    }

    /* ═══════════════ ما قبل الميزة لا يُحسب ═══════════════ */

    public function test_inquiries_from_before_the_feature_never_light_the_badge(): void
    {
        $this->inquire('9123 4567', 'قديمٌ عولج قبل الميزة');
        $this->inquire('9876 5432', 'قديمٌ آخر');
        $this->assertSame(2, CrmWebsiteInquiries::badge($this->a));

        // يُعاد الترحيلُ كما يجري يومَ النشر — والطلباتُ موجودةٌ قبله
        $migration = require base_path(self::MIGRATION);
        $migration->down();
        $migration->up();

        $this->assertSame((string) CrmNote::max('id'), DB::table('settings')->whereNull('business_id')
            ->where('key', CrmWebsiteInquiries::UNREAD_AFTER_KEY)->value('value'));
        $this->assertSame(0, $this->badgeOnScreen($this->a));
        $this->assertSame(0, $this->badgeOnScreen($this->b));
        $this->assertSame(0, CrmWebsiteInquiryRead::count(), 'كُتب صفُّ قراءةٍ لكلّ موظّفٍ في كلّ عميل');

        // والطلبُ الأوّلُ بعده يُضيئها
        $this->inquire('9123 4567', 'جديدٌ بعد النشر');
        $this->assertSame(1, $this->badgeOnScreen($this->a));
        $this->assertSame([$this->leadOf()->id => true, $this->leadOf('96898765432')->id => false], $this->rows($this->a));
    }

    public function test_listing_writes_no_read_row_and_a_lead_without_inquiries_gets_none(): void
    {
        $this->inquire();
        $this->rows($this->a);
        $this->open($this->a, $this->manualLead('96890001111'));

        $this->assertSame(0, CrmWebsiteInquiryRead::count());
        $this->assertSame(1, CrmWebsiteInquiries::badge($this->a));
    }

    public function test_the_card_from_the_website_still_shows_every_inquiry_after_reading(): void
    {
        $this->inquire(message: 'الأولى');
        $this->inquire(message: 'الثانية');

        $this->open($this->a, $this->leadOf());
        $this->open($this->a, $this->leadOf())->assertInertia(fn (Assert $p) => $p
            ->has('websiteInquiries', 2)
            ->where('websiteInquiries.0.message', 'الثانية')
            ->has('notes', 2));
    }
}
