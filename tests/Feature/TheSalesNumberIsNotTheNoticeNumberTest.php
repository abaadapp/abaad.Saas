<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\CrmLead;
use App\Models\CrmMessage;
use App\Models\SupportConversation;
use App\Models\SupportMessage;
use App\Models\User;
use App\Models\WhatsAppConnection;
use App\Support\Crm;
use App\Support\CrmLeads;
use App\Support\CrmWhatsApp;
use App\Support\SupportWhatsApp;
use App\Support\WhatsAppConnections;
use App\Support\WhatsAppMode;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * رقمان لأبعاد، ونطاقان لا يلتقيان.
 *
 * ═══ أثقلُ ما يُحرَس هنا ═══
 *
 * رقمُ الإشعارات يُرسل نيابةً عن المحلّات، فيردّ عليه **زبائنُهم**: «وصل؟»،
 * «غيّر العنوان». ورقمُ المبيعات يستقبل من يريد أن يشتري أبعاد، فهو يقرأ
 * **المجهول** بالضرورة.
 *
 * فلو قُرئ المجهولُ على رقم الإشعارات، لَصارت زبونةُ محلِّ ورودٍ سألت عن
 * هديّةٍ لزوجها صفًّا في دفتر مبيعاتنا — ونصُّ رسالتها مقروءًا في لوحة
 * المنصّة. وهذا ما تحرسه أكثرُ اختبارات هذا الملفّ.
 *
 * ═══ والعطبُ الثاني أقلُّ ظهورًا وأوسعُ أثرًا ═══
 *
 * `WhatsAppConnections::platform()` كانت تأخذ **أحدثَ** وصلةِ منصّةٍ نشطة.
 * فربطُ رقمِ مبيعاتٍ ثانٍ كان سيجعل إشعاراتِ طلبات **كلّ المتاجر** تخرج منه
 * — يقرأ الزبون رقمًا لا يعرفه، ويردّ عليه فلا يصل أحدًا.
 */
class TheSalesNumberIsNotTheNoticeNumberTest extends TestCase
{
    use RefreshDatabase;

    private WhatsAppConnection $notices;

    private WhatsAppConnection $sales;

    private Business $shop;

    private User $merchant;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        config(['whatsapp.app_secret' => 'test-app-secret']);
        Http::preventStrayRequests();

        $this->shop = Business::create(['name' => 'محل الورد', 'type' => 'محل ورود', 'status' => 'active']);

        $this->merchant = User::create([
            'name' => 'صاحب المحل', 'email' => 'o@shop.om', 'password' => bcrypt('x'),
            'role' => 'admin', 'status' => 'active', 'business_id' => $this->shop->id,
            'phone' => '96891112222',
        ]);

        $this->admin = User::create([
            'name' => 'سالم', 'email' => 'salem@abaad.om', 'password' => bcrypt('x'),
            'role' => 'super_admin', 'status' => 'active',
        ]);

        $this->notices = WhatsAppConnection::create([
            'owner_type' => WhatsAppMode::OWNER_PLATFORM,
            'purpose' => WhatsAppMode::PURPOSE_NOTIFICATIONS,
            'phone_number_id' => 'NOTICES-PN',
            'display_phone_number' => '+968 7114 1624',
            'access_token' => 'notices-token-0123456789',
            'status' => WhatsAppConnection::ACTIVE,
            'supports_inbox' => true,
        ]);

        /* ورقمُ المبيعات **أحدثُ** — وهو ما كان يكسر الاختيار بلا عمود الغرض */
        $this->sales = WhatsAppConnection::create([
            'owner_type' => WhatsAppMode::OWNER_PLATFORM,
            'purpose' => WhatsAppMode::PURPOSE_CRM_SALES,
            'phone_number_id' => 'SALES-PN',
            'display_phone_number' => '+968 9000 0000',
            'access_token' => 'sales-token-0123456789',
            'status' => WhatsAppConnection::ACTIVE,
        ]);
    }

    /* ═══════════════════ اختيارُ الوصلة ═══════════════════ */

    /**
     * إشعاراتُ الطلبات تخرج من رقم الإشعارات ولو كان رقمُ المبيعات أحدث.
     *
     * وهذا هو العطبُ الذي كان: `orderByDesc('id')` بلا شرطِ غرض.
     */
    public function test_order_notices_never_leave_from_the_sales_number(): void
    {
        $chosen = WhatsAppConnections::platform();

        $this->assertNotNull($chosen);
        $this->assertSame(
            $this->notices->id,
            $chosen->id,
            'إشعاراتُ الطلبات كانت ستخرج من رقم المبيعات لأنّه الأحدث'
        );
    }

    /** وخطُّ المبيعات هو رقمُ المبيعات لا رقمُ الإشعارات */
    public function test_the_sales_line_is_the_sales_number(): void
    {
        $line = CrmWhatsApp::line();

        $this->assertNotNull($line);
        $this->assertSame($this->sales->id, $line->id);
    }

    /**
     * وخطُّ الدعم يبقى على رقم الإشعارات.
     *
     * ولو انزلق إلى رقم المبيعات لَصار سؤالُ عميلٍ محتمَلٍ عن السعر «محادثةَ
     * دعمٍ» منسوبةً إلى متجرٍ لم يكتبها أحدٌ فيه.
     */
    public function test_the_support_line_stays_on_the_notice_number(): void
    {
        $line = SupportWhatsApp::line();

        $this->assertNotNull($line);
        $this->assertSame($this->notices->id, $line->id);
    }

    /** ورقمُ المبيعات لا يصير خطَّ دعمٍ ولو أُشعل مقبضُه عليه */
    public function test_turning_on_the_inbox_flag_does_not_make_sales_a_support_line(): void
    {
        $this->sales->update(['supports_inbox' => true]);
        $this->notices->update(['status' => WhatsAppConnection::INACTIVE]);

        $this->assertNull(
            SupportWhatsApp::line(),
            'رقمُ المبيعات صار خطَّ دعمٍ لأنّ مقبضًا أُشعل عليه'
        );
    }

    /* ═══════════════════ الوارد: أينَ يُكتب ═══════════════════ */

    /**
     * رقمٌ مجهولٌ يكتب إلى **المبيعات** ← عميلٌ محتمَلٌ جديد.
     *
     * وهذا هو المقصود: العميلُ المحتمَل بتعريفه ليس مستخدمًا عندنا.
     */
    public function test_an_unknown_number_writing_to_sales_becomes_a_lead(): void
    {
        $this->inbound(phoneId: 'SALES-PN', from: '96893334444', text: 'عندي محل ورد وأبي أعرف عن النظام')
            ->assertOk();

        $lead = CrmLead::firstOrFail();
        $this->assertSame('96893334444', $lead->phone);
        $this->assertSame(Crm::SOURCE_WHATSAPP, $lead->source);
        $this->assertSame(Crm::NEW, $lead->stage);
        $this->assertNotNull($lead->whatsapp_window_at, 'لم تُفتح نافذةُ ميتا بورودِ رسالته');

        $message = CrmMessage::firstOrFail();
        $this->assertSame(CrmMessage::IN, $message->direction);
        $this->assertSame('عندي محل ورد وأبي أعرف عن النظام', $message->body);
    }

    /**
     * ═══ وهذا أهمُّ حارسٍ في الملفّ ═══
     *
     * رقمٌ مجهولٌ يكتب إلى **رقم الإشعارات** لا يصير عميلًا محتملًا.
     *
     * وهو في الواقع زبونُ محلِّ ورودٍ يردّ على إشعار طلبه. وسطرٌ واحدٌ يفتح
     * المجهول هناك يُدخل رسائلَ زبائن التجّار كلِّهم في دفتر مبيعاتنا.
     */
    public function test_an_unknown_number_writing_to_the_notice_line_never_becomes_a_lead(): void
    {
        $this->inbound(phoneId: 'NOTICES-PN', from: '96897778888', text: 'وين الورد؟ ما وصل')
            ->assertOk();

        $this->assertSame(0, CrmLead::count(), 'زبونُ محلٍّ صار عميلًا محتملًا لأبعاد');
        $this->assertSame(0, CrmMessage::count(), 'رسالةُ زبونٍ كُتبت في دفتر المبيعات');
        $this->assertDatabaseMissing('crm_leads', ['phone' => '96897778888']);

        /* ولا في مركز المحادثات كذلك: لا يُطابق مستخدمًا له متجر */
        $this->assertSame(0, SupportConversation::count());
    }

    /**
     * وتاجرٌ معروفٌ يكتب إلى رقم الإشعارات ← محادثةُ دعم، لا عميلٌ محتمَل.
     *
     * والحارسُ على الطرفين معًا: أن تُكتب هناك، وألّا تُكتب هنا.
     */
    public function test_a_known_merchant_writing_to_the_notice_line_opens_support_not_a_lead(): void
    {
        $this->inbound(phoneId: 'NOTICES-PN', from: '96891112222', text: 'الفاتورة لا تُطبع')
            ->assertOk();

        $this->assertSame(1, SupportConversation::count());
        $this->assertSame(0, CrmLead::count(), 'محادثةُ دعمٍ صارت عميلًا محتملًا');
    }

    /**
     * وتاجرٌ معروفٌ يكتب إلى رقم **المبيعات** ← عميلٌ محتمَل، لا محادثةُ دعم.
     *
     * وقد يبدو غريبًا — لكنّه الصواب: الغرضُ يقرّر، لا هويّةُ المُرسِل. ولو
     * قُرّر بالهويّة لَصار نفسُ الرقم يُكتب هنا مرّةً وهناك مرّةً بحسب من
     * سجّل رقمه في حسابه — وهو ما لا يستطيع أحدٌ أن يتوقّعه.
     *
     * والشاشةُ تقول لموظّف المبيعات إنّه مشتركٌ أصلًا — انظر `existingMerchant`.
     */
    public function test_a_known_merchant_writing_to_sales_is_a_lead_not_a_support_thread(): void
    {
        $this->inbound(phoneId: 'SALES-PN', from: '96891112222', text: 'أبي أضيف فرع')->assertOk();

        $this->assertSame(1, CrmLead::count());
        $this->assertSame(0, SupportConversation::count(), 'خيطُ مبيعاتٍ فُتح في مركز الدعم');
    }

    /* ═══════════════════ التكرار ═══════════════════ */

    /** إشعارٌ يصل مرّتين يُكتب مرّة — والفهرسُ الفريد هو الحارسُ خلف الفحص */
    public function test_a_repeated_notification_writes_one_message(): void
    {
        $this->inbound(phoneId: 'SALES-PN', from: '96893334444', wamid: 'wamid.SAME')->assertOk();
        $this->inbound(phoneId: 'SALES-PN', from: '96893334444', wamid: 'wamid.SAME')->assertOk();

        $this->assertSame(1, CrmMessage::count(), 'رسالةُ العميل كُتبت مرّتين');
        $this->assertSame(1, CrmLead::count());
    }

    /** ورسالتان من الرقم نفسِه خيطٌ واحدٌ لا عميلان */
    public function test_two_messages_from_one_number_are_one_lead(): void
    {
        $this->inbound(phoneId: 'SALES-PN', from: '96893334444', wamid: 'wamid.A', text: 'مرحبا')->assertOk();
        $this->inbound(phoneId: 'SALES-PN', from: '96893334444', wamid: 'wamid.B', text: 'كم السعر؟')->assertOk();

        $this->assertSame(1, CrmLead::count());
        $this->assertSame(2, CrmMessage::count());
    }

    /**
     * وكلُّ بابٍ يحرس نفسَه — ولو نودي من غير طريقه.
     *
     * ═══ ولمَ حارسٌ مستقلٌّ لهذا ═══
     *
     * `WebhookController` يوزّع بالغرض، فما دام التوزيعُ سليمًا لا تصل
     * `CrmWhatsApp::receive` وصلةَ إشعاراتٍ أبدًا — وطفرةٌ تحذف فحصَها
     * الداخليّ لا يسقط بها حارس. جرّبتُها فنجت.
     *
     * والفحصُ الداخليّ ليس زائدًا: من ينادي هذه الدالّة يومًا من موضعٍ آخر —
     * أمرَ صيانةٍ يُعيد معالجةَ حمولاتٍ قديمة مثلًا — يفتح بابَ المجهول على
     * رقم الإشعارات بسهو. فيُنادى هنا مباشرةً بوصلةِ الإشعارات، ويُشهد أنّ
     * شيئًا لا يُكتب.
     */
    public function test_the_crm_door_refuses_a_notice_connection_even_when_called_directly(): void
    {
        CrmWhatsApp::receive($this->notices, [
            'id' => 'wamid.DIRECT',
            'from' => '96897778888',
            'type' => 'text',
            'text' => ['body' => 'وين الورد؟'],
        ]);

        $this->assertSame(0, CrmLead::count(), 'وصلةُ إشعاراتٍ صنعت عميلًا محتملًا بنداءٍ مباشر');
        $this->assertSame(0, CrmMessage::count());
    }

    /**
     * والحارسُ الأخير للتكرار في القاعدة لا في الكود.
     *
     * ═══ وهذا ما أثبتته الطفرة ═══
     *
     * حذفتُ القراءةَ السابقة في `receive` فلم يسقط حارس: هي اختصارُ طريقٍ لا
     * حارس. الذي يمنع الثانيةَ فهرسُ التفرّد على `external_message_id`، ومعه
     * `Contention` التي تلتقط الاصطدام وتردّ المعاملةَ كلَّها.
     *
     * فالحارسُ يُكتب على ما يحرس فعلًا.
     */
    public function test_the_database_itself_refuses_a_second_message_with_one_wamid(): void
    {
        $this->inbound(phoneId: 'SALES-PN', from: '96893334444', wamid: 'wamid.ONE')->assertOk();

        $this->expectException(UniqueConstraintViolationException::class);

        CrmMessage::create([
            'lead_id' => CrmLead::firstOrFail()->id,
            'direction' => CrmMessage::IN,
            'body' => 'نسخةٌ ثانية',
            'external_message_id' => 'wamid.ONE',
        ]);
    }

    /* ═══════════════════ النافذة ═══════════════════ */

    /**
     * ولا يخرج نصٌّ حرٌّ قبل أن يكتب هو — ويُقال ذلك، ولا يُدّعى الإرسال.
     *
     * واتساب لا يبدأ محادثةً بنصٍّ حرّ. والادّعاءُ هنا يعني موظّفَ مبيعاتٍ
     * ينتظر ردًّا على رسالةٍ لم تخرج.
     */
    public function test_nothing_leaves_before_the_customer_writes(): void
    {
        $this->actingAs($this->admin);
        $lead = CrmLeads::findOrCreateByPhone('96893334444')['lead'];

        $message = CrmWhatsApp::send($lead, $this->admin, 'أهلًا بك');

        $this->assertSame('blocked', $message->delivery);
        $this->assertNotNull($message->delivery_error);
        Http::assertNothingSent();
    }

    /** ونافذةٌ مضت تُغلق — الخطأُ ١٣١٠٤٧ يُمنع قبل النداء لا بعده */
    public function test_an_expired_window_blocks_before_the_call(): void
    {
        $this->actingAs($this->admin);
        $lead = CrmLeads::findOrCreateByPhone('96893334444')['lead'];
        $lead->forceFill(['whatsapp_window_at' => now()->subHours(25)])->save();

        $message = CrmWhatsApp::send($lead->refresh(), $this->admin, 'تابعنا معك');

        $this->assertSame('blocked', $message->delivery);
        Http::assertNothingSent();
    }

    /** ونافذةٌ مفتوحةٌ تُخرج الردّ — ومن رقم المبيعات لا من رقم الإشعارات */
    public function test_an_open_window_sends_from_the_sales_number(): void
    {
        Http::fake([
            '*' => Http::response(['messages' => [['id' => 'wamid.OUT-1']]], 200),
        ]);

        $this->inbound(phoneId: 'SALES-PN', from: '96893334444')->assertOk();

        $this->actingAs($this->admin);
        $lead = CrmLead::firstOrFail();
        $message = CrmWhatsApp::send($lead, $this->admin, 'أهلًا وسهلًا');

        $this->assertSame('sent', $message->delivery);
        $this->assertSame('wamid.OUT-1', $message->external_message_id);

        /* والنداءُ ذهب إلى معرّف رقم المبيعات — لا إلى رقم الإشعارات */
        Http::assertSent(fn ($request) => str_contains($request->url(), 'SALES-PN'));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'NOTICES-PN'));
    }

    /** وردُّ ميتا بالفشل يُكتب فشلًا — لا صمتًا يُقرأ نجاحًا */
    public function test_a_provider_failure_is_written_as_a_failure(): void
    {
        Http::fake(['*' => Http::response(['error' => ['message' => 'Recipient not found']], 400)]);

        $this->inbound(phoneId: 'SALES-PN', from: '96893334444')->assertOk();

        $this->actingAs($this->admin);
        $message = CrmWhatsApp::send(CrmLead::firstOrFail(), $this->admin, 'مرحبا');

        $this->assertSame('failed', $message->delivery);
        $this->assertNotEmpty($message->delivery_error);
    }

    /* ═══════════════════ حالُ التسليم ═══════════════════ */

    /**
     * وحالُ التسليم تُقرأ من ميتا وتُكتب — ولا ترجع إلى الوراء.
     *
     * وبلا هذا تبقى كلُّ رسالةٍ «أُرسلت» أبدًا: يقرأ موظّفُ المبيعات أنّها
     * خرجت وهي راقدةٌ عند ميتا.
     */
    public function test_delivery_status_is_written_and_never_goes_backwards(): void
    {
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.OUT-1']]], 200)]);
        $this->inbound(phoneId: 'SALES-PN', from: '96893334444')->assertOk();

        $this->actingAs($this->admin);
        $message = CrmWhatsApp::send(CrmLead::firstOrFail(), $this->admin, 'مرحبا');

        $this->deliveryEvent('wamid.OUT-1', 'read', 'SALES-PN')->assertOk();
        $this->assertSame('read', $message->fresh()->delivery);

        /* ثمّ يصل «سُلّمت» متأخّرًا — ولا يمحو «قُرئت» */
        $this->deliveryEvent('wamid.OUT-1', 'delivered', 'SALES-PN')->assertOk();
        $this->assertSame('read', $message->fresh()->delivery, 'حالُ التسليم رجعت إلى الوراء');
    }

    /** والفشلُ بعد التسليم يُكتب — خبرٌ لا يُبتلع */
    public function test_a_failure_after_delivery_is_written(): void
    {
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.OUT-1']]], 200)]);
        $this->inbound(phoneId: 'SALES-PN', from: '96893334444')->assertOk();

        $this->actingAs($this->admin);
        $message = CrmWhatsApp::send(CrmLead::firstOrFail(), $this->admin, 'مرحبا');

        $this->deliveryEvent('wamid.OUT-1', 'delivered', 'SALES-PN')->assertOk();
        $this->deliveryEvent('wamid.OUT-1', 'failed', 'SALES-PN')->assertOk();

        $this->assertSame('failed', $message->fresh()->delivery);
    }

    /**
     * وحالُ رسالةٍ على رقم الإشعارات لا تمسّ دفترَ المبيعات.
     *
     * الجدولان مستقلّان، والمعرّفُ قد يتشابه في اختبارٍ أو حمولةٍ مزوَّرة.
     */
    public function test_a_notice_line_status_never_touches_a_crm_message(): void
    {
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.OUT-1']]], 200)]);
        $this->inbound(phoneId: 'SALES-PN', from: '96893334444')->assertOk();

        $this->actingAs($this->admin);
        $message = CrmWhatsApp::send(CrmLead::firstOrFail(), $this->admin, 'مرحبا');

        /* المعرّفُ نفسُه، لكنّ الإشعار وصل على رقم الإشعارات */
        $this->deliveryEvent('wamid.OUT-1', 'read', 'NOTICES-PN')->assertOk();

        $this->assertSame('sent', $message->fresh()->delivery, 'إشعارُ رقم الإشعارات عدّل رسالةَ مبيعات');
    }

    /* ═══════════════════ الربط ═══════════════════ */

    /** ورقمُ المبيعات لا يكون رقمَ الإشعارات نفسَه — ويُقال لماذا */
    public function test_one_phone_number_cannot_serve_both_purposes(): void
    {
        $this->actingAs($this->admin)
            ->post(route('super-admin.whatsapp.sales.connect'), [
                'phone_number_id' => 'NOTICES-PN',
                'access_token' => 'another-token-0123456789',
            ])->assertSessionHasErrors('phone_number_id');
    }

    /** وربطُ رقم إشعاراتٍ جديد لا يسحب رقمَ المبيعات ويُحوّله */
    public function test_connecting_a_notice_number_does_not_steal_the_sales_row(): void
    {
        $this->actingAs($this->admin)
            ->post(route('super-admin.whatsapp.shared.connect'), [
                'phone_number_id' => 'NOTICES-2',
                'access_token' => 'notices2-token-0123456789',
            ])->assertSessionHasNoErrors();

        $this->assertSame(
            WhatsAppMode::PURPOSE_CRM_SALES,
            $this->sales->fresh()->purpose,
            'ربطُ رقمِ إشعاراتٍ حوّل رقمَ المبيعات إلى رقم إشعارات'
        );

        $this->assertSame('SALES-PN', $this->sales->fresh()->phone_number_id);
    }

    /** والوصلةُ القائمة قبل الترقية تبقى رقمَ إشعارات — لا تنقلب مبيعات */
    public function test_an_existing_connection_defaults_to_notices(): void
    {
        $legacy = WhatsAppConnection::create([
            'owner_type' => WhatsAppMode::OWNER_PLATFORM,
            'phone_number_id' => 'LEGACY-PN',
            'access_token' => 'legacy-token-0123456789',
            'status' => WhatsAppConnection::INACTIVE,
        ]);

        $this->assertSame(WhatsAppMode::PURPOSE_NOTIFICATIONS, $legacy->fresh()->purpose);
    }

    /* ═══════════════════ الشاشة ═══════════════════ */

    /** وموظّفُ متجرٍ لا يبلغ شاشةَ المحادثات ولا يردّ فيها */
    public function test_a_merchant_employee_reaches_no_crm_conversation(): void
    {
        $cashier = User::create([
            'name' => 'كاشير', 'email' => 'c@shop.om', 'password' => bcrypt('x'),
            'role' => 'cashier', 'status' => 'active', 'business_id' => $this->shop->id,
        ]);

        $this->inbound(phoneId: 'SALES-PN', from: '96893334444')->assertOk();
        $lead = CrmLead::firstOrFail();

        $this->actingAs($cashier)->get(route('super-admin.crm.conversations'))->assertStatus(403);
        $this->actingAs($cashier)
            ->post(route('super-admin.crm.conversations.reply', $lead->id), ['body' => 'اخترقت'])
            ->assertStatus(403);

        $this->assertSame(1, CrmMessage::count(), 'رسالةٌ كُتبت من طلبٍ كان يجب أن يُردّ');
    }

    /**
     * ولا رسالةُ دعمٍ تصل شاشةَ محادثات المبيعات.
     *
     * والفحصُ على الحمولة التي تصل المتصفّح فعلًا.
     */
    public function test_a_support_message_never_reaches_the_sales_screen(): void
    {
        $this->inbound(phoneId: 'NOTICES-PN', from: '96891112222', text: 'سرُّ الدعم لا يُقرأ في المبيعات')
            ->assertOk();

        $this->assertSame(1, SupportMessage::count());

        $this->inbound(phoneId: 'SALES-PN', from: '96893334444', wamid: 'wamid.S1')->assertOk();

        $this->actingAs($this->admin);
        $html = $this->get(route('super-admin.crm.conversations', ['lead' => CrmLead::firstOrFail()->id]))
            ->assertOk()->getContent();

        preg_match('/data-page="app" type="application\/json">(.*?)<\/script>/s', $html, $m);
        $payload = json_encode(json_decode(html_entity_decode($m[1] ?? '{}', ENT_QUOTES), true), JSON_UNESCAPED_UNICODE);

        $this->assertStringNotContainsString('سرُّ الدعم لا يُقرأ في المبيعات', (string) $payload);
    }

    /* ═══════════════════ أدواتٌ ═══════════════════ */

    private function inbound(
        string $phoneId,
        string $from,
        string $text = 'مرحبا',
        string $wamid = 'wamid.IN-1',
    ) {
        return $this->signed(['entry' => [['changes' => [['value' => [
            'metadata' => ['phone_number_id' => $phoneId],
            'messages' => [[
                'id' => $wamid,
                'from' => $from,
                'type' => 'text',
                'timestamp' => (string) now()->timestamp,
                'text' => ['body' => $text],
            ]],
        ]]]]]]);
    }

    private function deliveryEvent(string $wamid, string $state, string $phoneId)
    {
        return $this->signed(['entry' => [['changes' => [['value' => [
            'metadata' => ['phone_number_id' => $phoneId],
            'statuses' => [[
                'id' => $wamid,
                'status' => $state,
                'timestamp' => (string) now()->timestamp,
                'errors' => $state === 'failed' ? [['title' => 'تعذّر التسليم']] : [],
            ]],
        ]]]]]]);
    }

    private function signed(array $payload)
    {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE);

        return $this->call('POST', route('webhooks.whatsapp'), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, 'test-app-secret'),
        ], $body);
    }
}
