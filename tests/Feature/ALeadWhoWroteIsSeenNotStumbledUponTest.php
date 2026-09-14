<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\CrmLead;
use App\Models\CrmMessage;
use App\Models\CrmRead;
use App\Models\Setting;
use App\Models\User;
use App\Models\WhatsAppConnection;
use App\Support\Crm;
use App\Support\CrmWhatsApp;
use App\Support\WhatsAppMode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * من كتب إلينا يُرى — لا يُعثر عليه بالصدفة.
 *
 * ═══ العطب ═══
 *
 * تاجرٌ يكتب إلى رقمنا يُضيء شارةً في الشريط الجانبيّ. وعميلٌ محتمَلٌ يكتب
 * إلى **الرقم نفسِه** لا يُضيء شيئًا: تُكتب رسالتُه في `crm_messages`
 * وتنتظر، ولا يعرف بها أحدٌ حتّى يفتح «CRM ‹ المحادثات» بمحض الصدفة.
 *
 * ومن جاء ليشتري وانتظر يومًا بلا ردّ يذهب إلى غيرنا. وهذا أغلى صمتٍ في
 * النظام: صمتٌ يُكلّف مشتركًا.
 *
 * ═══ وما تحرسه هذه الحرّاس ═══
 *
 * أنّ الشارة **تُضيء** حين يكتب، وأنّها **تنطفئ** حين يُقرأ — والثانيةُ
 * أهمّ: شارةٌ لا تُطفأ تصير جزءًا من الأثاث فلا تقع عليها عين، ثمّ لا
 * تُقرأ حين تعني شيئًا.
 */
class ALeadWhoWroteIsSeenNotStumbledUponTest extends TestCase
{
    use RefreshDatabase;

    private User $sales;

    private User $colleague;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');
        Http::preventStrayRequests();

        $this->sales = User::create([
            'name' => 'موظّف مبيعات', 'email' => 's@abaad.om', 'password' => bcrypt('x'),
            'role' => 'super_admin', 'status' => 'active', 'business_id' => null,
        ]);

        $this->colleague = User::create([
            'name' => 'زميل', 'email' => 'c@abaad.om', 'password' => bcrypt('x'),
            'role' => 'super_admin', 'status' => 'active', 'business_id' => null,
        ]);

        Setting::updateOrCreate(['business_id' => null, 'key' => 'crm_whatsapp_shared'], ['value' => '1']);

        WhatsAppConnection::create([
            'owner_type' => WhatsAppMode::OWNER_PLATFORM,
            'purpose' => WhatsAppMode::PURPOSE_NOTIFICATIONS,
            'phone_number_id' => 'PN', 'display_phone_number' => '+968 7114 1624',
            'access_token' => 'tok-0123456789abcdef',
            'status' => WhatsAppConnection::ACTIVE, 'supports_inbox' => true,
        ]);
    }

    private function lead(string $phone = '96877778888', array $attrs = []): CrmLead
    {
        return CrmLead::create(array_merge([
            'phone' => $phone, 'phone_raw' => $phone, 'name' => 'عميل',
            'source' => Crm::SOURCE_WHATSAPP, 'status' => Crm::ACTIVE, 'stage' => Crm::NEW,
            'last_contact_at' => now(),
        ], $attrs));
    }

    private function wrote(CrmLead $lead, string $body = 'كم سعر أبعاد؟'): CrmMessage
    {
        return CrmMessage::create([
            'lead_id' => $lead->id, 'direction' => CrmMessage::IN, 'body' => $body,
            'external_message_id' => 'wamid.'.uniqid('', true),
        ]);
    }

    /* ═════════════ تُضيء حين يكتب ═════════════ */

    /** رسالةٌ من عميلٍ تُضيء الشارة */
    public function test_a_lead_who_writes_lights_the_badge(): void
    {
        $this->assertSame(0, CrmWhatsApp::badge($this->sales), 'أضاءت الشارةُ ولا رسالة');

        $this->wrote($this->lead());

        $this->assertSame(1, CrmWhatsApp::badge($this->sales));
    }

    /**
     * وثلاثةُ أسطرٍ من عميلٍ واحد شيءٌ واحد ينتظر.
     *
     * ورقمٌ يقفز إلى «٢٣» لأنّ ثلاثةً أسهبوا يُقرأ ضجيجًا فيُهمَل.
     */
    public function test_three_lines_from_one_lead_are_one_thing_waiting(): void
    {
        $lead = $this->lead();
        $this->wrote($lead, 'مرحبا');
        $this->wrote($lead, 'عندي محل');
        $this->wrote($lead, 'كم السعر؟');

        $this->assertSame(1, CrmWhatsApp::badge($this->sales));
    }

    /** وعميلان يكتبان شيئان */
    public function test_two_leads_are_two(): void
    {
        $this->wrote($this->lead('96877778888'));
        $this->wrote($this->lead('96877779999'));

        $this->assertSame(2, CrmWhatsApp::badge($this->sales));
    }

    /**
     * وردُّنا نحن لا يُضيء شيئًا.
     *
     * ولو عُدّ لَبقيت الشارةُ مضيئةً بعد أن يردّ الموظّف بنفسه.
     */
    public function test_our_own_reply_lights_nothing(): void
    {
        $lead = $this->lead();

        CrmMessage::create([
            'lead_id' => $lead->id, 'direction' => CrmMessage::OUT, 'body' => 'أهلًا',
        ]);

        $this->assertSame(0, CrmWhatsApp::badge($this->sales));
    }

    /**
     * ومن انتهى أمرُه لا يُضيء.
     *
     * من رُبح أو خُسر أُغلق بابُه، ورسالةٌ متأخّرةٌ منه لا تُعيد فتحَ
     * الشارة على من لا يُنتظر منه قرار.
     */
    public function test_a_finished_lead_lights_nothing(): void
    {
        $this->wrote($this->lead('96877778888', ['status' => Crm::CONVERTED]));

        $this->assertSame(0, CrmWhatsApp::badge($this->sales));
    }

    /* ═════════════ وتنطفئ حين يُقرأ ═════════════ */

    /** فتحُ المحادثة يُطفئ الشارةَ لمن فتحها */
    public function test_opening_the_conversation_puts_the_badge_out(): void
    {
        $lead = $this->lead();
        $this->wrote($lead);

        $this->actingAs($this->sales)
            ->get(route('super-admin.crm.conversations', ['lead' => $lead->id]))
            ->assertOk();

        $this->assertSame(0, CrmWhatsApp::badge($this->sales));
    }

    /**
     * ولا يُطفئها عن زميله.
     *
     * ومقروءٌ واحدٌ للجميع يعني أنّ مرورَ زميلٍ على المحادثة يُخفيها عمّن
     * كان سيردّ — فتُنسى الرسالةُ لأنّ أحدَهم رآها ولم يفعل شيئًا.
     */
    public function test_one_reader_does_not_put_it_out_for_the_others(): void
    {
        $lead = $this->lead();
        $this->wrote($lead);

        /*
         * والقارئُ هنا هو **الزميل** لا الأوّل.
         *
         * أوّلُ صيغةٍ لهذا الحارس جعلت القارئَ أوّلَ مستخدمٍ في القاعدة،
         * فكان `user_id` مثبَّتًا على `1` يمرّ بلا أن يُلحظ: يقرأ الأوّل
         * فتنطفئ عنه، ويقرأ الثاني فتنطفئ عن الأوّل — والحارسُ أخضر.
         */
        $this->actingAs($this->colleague)
            ->get(route('super-admin.crm.conversations', ['lead' => $lead->id]))->assertOk();

        $this->assertSame(0, CrmWhatsApp::badge($this->colleague));
        $this->assertSame(1, CrmWhatsApp::badge($this->sales), 'أُطفئت الشارةُ عن موظّفٍ لم يقرأ');
    }

    /** ورسالةٌ جديدةٌ بعد القراءة تُعيد إضاءتها */
    public function test_a_new_line_after_reading_lights_it_again(): void
    {
        $lead = $this->lead();
        $this->wrote($lead);

        $this->actingAs($this->sales)
            ->get(route('super-admin.crm.conversations', ['lead' => $lead->id]))->assertOk();

        $this->wrote($lead, 'ألو؟');

        $this->assertSame(1, CrmWhatsApp::badge($this->sales));
    }

    /* ═════════════ والعددُ يصل الشاشة ═════════════ */

    /** الشارةُ تصل كلَّ صفحةٍ في الحمولة المشتركة — لا شاشةً واحدة */
    public function test_the_count_reaches_every_screen(): void
    {
        $this->wrote($this->lead());

        $this->actingAs($this->sales)
            ->get(route('super-admin.crm.dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p->where('crmBadge', 1));
    }

    /** وكلُّ صفٍّ يقول كم ينتظر منه */
    public function test_each_row_says_how_many_wait_in_it(): void
    {
        $lead = $this->lead();
        $this->wrote($lead, 'مرحبا');
        $this->wrote($lead, 'كم السعر؟');

        /* وردُّنا بينهما لا يُعدّ انتظارًا — وإلّا بقي الصفُّ يقول «٣» بعد أن رددنا */
        CrmMessage::create([
            'lead_id' => $lead->id, 'direction' => CrmMessage::OUT, 'body' => 'أهلًا بك',
        ]);

        $this->actingAs($this->sales)
            ->get(route('super-admin.crm.conversations'))
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p->where('conversations.0.unread', 2));
    }

    /* ═════════════ ولا تاجرَ يقرأ دفترَ مبيعاتنا ═════════════ */

    /** وشارةُ المبيعات صفرٌ لمن ليس من المنصّة */
    public function test_a_merchant_never_carries_our_sales_badge(): void
    {
        $business = Business::create([
            'name' => 'محل', 'type' => 'عام', 'status' => 'active',
        ]);

        $merchant = User::create([
            'name' => 'تاجر', 'email' => 'm@shop.om', 'password' => bcrypt('x'),
            'role' => 'admin', 'status' => 'active', 'business_id' => $business->id,
        ]);

        $this->wrote($this->lead());

        $this->assertSame(0, CrmWhatsApp::badge($merchant));
    }

    /** ولا صفَّ قراءةٍ يُكتب لمن لم يفتح شيئًا */
    public function test_no_read_row_is_written_for_a_list_that_was_only_listed(): void
    {
        $this->wrote($this->lead());

        $this->actingAs($this->sales)
            ->get(route('super-admin.crm.conversations'))->assertOk();

        $this->assertSame(0, CrmRead::count(), 'عُدّت القائمةُ قراءةً — فتُطفأ الشارةُ بلا أن يُقرأ شيء');
        $this->assertSame(1, CrmWhatsApp::badge($this->sales));
    }
}
