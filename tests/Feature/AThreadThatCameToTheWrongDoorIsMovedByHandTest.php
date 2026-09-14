<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\CrmLead;
use App\Models\CrmMessage;
use App\Models\SupportConversation;
use App\Models\SupportMessage;
use App\Models\User;
use App\Support\ConversationHandover;
use App\Support\Support;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * خيطٌ طرق البابَ الخطأ — يُنقل بيدٍ، ولا يُحزر.
 *
 * ═══ ما جرى ═══
 *
 * الواردُ على الرقم الواحد يُوزَّع بـ**من كتب** لا بـ**ما كتب**: رقمٌ يُطابق
 * تاجرًا عندنا يذهب إلى الدعم، وغريبٌ لم نراسله قطّ يصير عميلًا محتمَلًا.
 * وهذا فاصلٌ يُقاس ولا يُخطئ.
 *
 * لكنّه لا يقرأ النيّة. فكُتبت «السلام عليكم» من رقمٍ مسجَّلٍ لتاجرٍ عندنا
 * فصارت تذكرةَ دعمٍ موضوعُها «السلام عليكم» — وهي ليست شكوى.
 *
 * ═══ ولمَ لم يُحلّ بقراءة النصّ ═══
 *
 * لأنّ أوّلَ ما يُكسَر حينها هو الفاصلُ نفسُه: تصير زبونةُ محلِّ ورودٍ سألت
 * عن سعرٍ «عميلًا محتمَلًا» في دفتر مبيعاتنا، ويُقرأ نصُّها في لوحة المنصّة.
 * والحدسُ يخطئ صامتًا؛ والعمودُ يخطئ ظاهرًا ويُصحَّح بضغطة.
 *
 * فهذه الحرّاسُ تحرس النقلَ نفسَه: أن يُنقل ما يُنقل، وأن يبقى ما يبقى، وأن
 * لا يُفتح بابٌ لا يُرسل منه شيء.
 */
class AThreadThatCameToTheWrongDoorIsMovedByHandTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private User $merchant;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');

        $this->shop = Business::create(['name' => 'محل الورد', 'type' => 'محل ورود', 'status' => 'active']);

        $this->merchant = User::create([
            'name' => 'مالك النشاط', 'email' => 'o@shop.om', 'password' => bcrypt('x'),
            'role' => 'admin', 'status' => 'active', 'business_id' => $this->shop->id,
            'phone' => '96890683111',
        ]);
    }

    /** خيطُ واتساب كما يكتبه المستقبِل */
    private function thread(array $attrs = []): SupportConversation
    {
        return SupportConversation::create(array_merge([
            'reference' => 'SUP-'.uniqid('', false),
            'business_id' => $this->shop->id,
            'opened_by' => $this->merchant->id,
            'subject' => 'السلام عليكم',
            'category' => 'other',
            'channel' => 'whatsapp',
            'status' => 'new',
            'contact_phone' => '96890683111',
            'whatsapp_window_at' => now()->subHour(),
        ], $attrs));
    }

    /* ═════════════ ما يُنقل ═════════════ */

    /** الرسائلُ تصل دفترَ المبيعات بنصّها واتّجاهها */
    public function test_the_words_reach_the_sales_book_as_they_were(): void
    {
        $thread = $this->thread();
        Support::say($thread, $this->merchant, 'business', 'السلام عليكم', false, null, null, 'wamid.A');
        Support::say($thread, null, 'platform', 'أهلًا بك', false, null, null, 'wamid.B');

        $result = ConversationHandover::toSales($thread);

        $this->assertTrue($result['ok']);
        $this->assertSame(2, $result['moved']);

        $lead = CrmLead::where('phone', '96890683111')->firstOrFail();
        $messages = CrmMessage::where('lead_id', $lead->id)->orderBy('id')->get();

        $this->assertSame('السلام عليكم', $messages[0]->body);
        $this->assertSame(CrmMessage::IN, $messages[0]->direction);
        $this->assertSame(CrmMessage::OUT, $messages[1]->direction);
    }

    /**
     * ومعرّفُ ميتا يُحفظ — وبه يجد إشعارُ الحال المتأخّر صفَّه.
     *
     * ولولاه لبقيت كلُّ رسالةٍ نُقلت «أُرسلت» أبدًا: لا تُسلَّم ولا تفشل.
     */
    public function test_metas_message_id_travels_with_the_words(): void
    {
        $thread = $this->thread();
        Support::say($thread, $this->merchant, 'business', 'مرحبا', false, null, null, 'wamid.KEEP');

        ConversationHandover::toSales($thread);

        $this->assertTrue(CrmMessage::where('external_message_id', 'wamid.KEEP')->exists());
    }

    /**
     * وختمُ النافذة يُنقل ولا يُجدَّد.
     *
     * النافذةُ تبدأ من آخر واردٍ من العميل لا من لحظة النقل. وتجديدُها يجعل
     * الشاشة تقول «الباب مفتوح» بعد أن أُغلق — فيكتب موظّفُ المبيعات ردًّا
     * تردُّه ميتا بالخطأ ١٣١٠٤٧.
     */
    public function test_the_window_is_carried_not_restarted(): void
    {
        $opened = now()->subHours(3);
        $thread = $this->thread(['whatsapp_window_at' => $opened]);
        Support::say($thread, $this->merchant, 'business', 'مرحبا', false, null, null, 'wamid.W');

        ConversationHandover::toSales($thread);

        $lead = CrmLead::where('phone', '96890683111')->firstOrFail();

        $this->assertSame(
            $opened->format('Y-m-d H:i'),
            $lead->whatsapp_window_at->format('Y-m-d H:i'),
            'جُدّدت النافذةُ بلحظة النقل — فيُفتح صندوقُ ردٍّ لا يُرسل',
        );
    }

    /* ═════════════ وما لا يُنقل ═════════════ */

    /**
     * الملاحظةُ الداخلية وحدثُ النظام يبقيان في الدعم.
     *
     * الأولى كُتبت ليقرأها الفريقُ وحدَه، والثاني ليس كلامًا قاله أحد.
     */
    public function test_an_internal_note_and_a_system_event_stay_behind(): void
    {
        $thread = $this->thread();
        Support::say($thread, $this->merchant, 'business', 'مرحبا', false, null, null, 'wamid.A');
        Support::say($thread, null, 'platform', 'ملاحظةٌ لنا', true);
        Support::say($thread, null, 'system', null, false, 'status', ['from' => 'new', 'to' => 'open']);

        $result = ConversationHandover::toSales($thread);

        $this->assertSame(1, $result['moved'], 'نُقل ما لم يُكتب للعميل');
    }

    /**
     * وتذكرةُ اللوحة لا تُنقل — لا رقمَ لها ولا نافذةَ ردّ.
     *
     * ونقلُها يصنع عميلًا لا نملك أن نكتب إليه حرفًا، وصفٌّ في دفترٍ لا
     * يُفتح على شيء.
     */
    public function test_an_in_app_ticket_is_refused_with_a_reason(): void
    {
        $result = ConversationHandover::toSales($this->thread([
            'channel' => 'in_app', 'contact_phone' => null,
        ]));

        $this->assertFalse($result['ok']);
        $this->assertNotNull($result['reason']);
        $this->assertSame(0, CrmLead::count(), 'فُتح عميلٌ لا سبيلَ إلى مراسلته');
    }

    /**
     * ورقمٌ صالحٌ على تذكرةِ لوحةٍ لا يجعلها قابلةً للنقل.
     *
     * ═══ ولمَ حارسان لا حارس ═══
     *
     * أوّلُ صيغةٍ لهذا الملفّ اختبرت التذكرةَ بلا رقم، فكان حارسُ الرقم
     * يردُّها وحدَه — وحارسُ القناة لم يُقَس قطّ. وطفرةٌ تُسقطه بقيت حيّة.
     *
     * والرقمُ قد يكون على تذكرةِ لوحةٍ فعلًا (يُنسخ من بطاقة التاجر)، ولا
     * نافذةَ ردٍّ عليها مع ذلك: لم يكتب إلينا أحدٌ على واتساب، فلا ساعاتٍ
     * أربعًا وعشرين تُحسب. والقناةُ هي ما يقول ذلك لا الرقم.
     */
    public function test_a_phone_on_an_in_app_ticket_does_not_make_it_movable(): void
    {
        $result = ConversationHandover::toSales($this->thread([
            'channel' => 'in_app', 'contact_phone' => '96890683111',
        ]));

        $this->assertFalse($result['ok'], 'نُقلت تذكرةُ لوحةٍ لأنّ عليها رقمًا');
        $this->assertSame(0, CrmLead::count());
        $this->assertSame(0, CrmMessage::count());
    }

    /** وخيطُ واتسابٍ بلا رقمٍ صالح يُردّ كذلك */
    public function test_a_whatsapp_thread_without_a_usable_number_is_refused(): void
    {
        $result = ConversationHandover::toSales($this->thread(['contact_phone' => 'لا رقم']));

        $this->assertFalse($result['ok']);
        $this->assertSame(0, CrmLead::count());
    }

    /* ═════════════ وما يبقى بعد النقل ═════════════ */

    /**
     * خيطُ الدعم يُقفل ولا يُحذف.
     *
     * الحذفُ يُفقد الأثر، ويجعل إشعارَ حالٍ متأخّرًا من ميتا يصل إلى صفٍّ
     * لا وجود له.
     */
    public function test_the_support_thread_is_closed_not_erased(): void
    {
        $thread = $this->thread();
        Support::say($thread, $this->merchant, 'business', 'مرحبا', false, null, null, 'wamid.A');

        ConversationHandover::toSales($thread);

        $thread->refresh();

        $this->assertSame('closed', $thread->status);
        $this->assertNotNull($thread->closed_at);
        $this->assertTrue(
            SupportMessage::where('conversation_id', $thread->id)->where('event', 'moved_to_crm')->exists(),
            'أُقفل الخيطُ بلا أن يُقال لماذا',
        );
    }

    /**
     * ونقلٌ ثانٍ لخيطٍ نُقل لا يُكرّر رسالةً ولا يُسقط شيئًا.
     *
     * والمعرّفُ فريدٌ في القاعدة — فبلا هذا التخطّي يرتدّ النقلُ خطأَ قاعدةٍ
     * يصير صفحةَ خمسمئة.
     */
    public function test_moving_twice_repeats_nothing_and_breaks_nothing(): void
    {
        $thread = $this->thread();
        Support::say($thread, $this->merchant, 'business', 'مرحبا', false, null, null, 'wamid.A');

        ConversationHandover::toSales($thread);
        $second = ConversationHandover::toSales($thread->fresh());

        $this->assertTrue($second['ok']);
        $this->assertSame(0, $second['moved']);
        $this->assertSame(1, CrmMessage::count());
        $this->assertSame(1, CrmLead::count());
    }

    /* ═════════════ والباب لا يُفتح لغير مالك المنصّة ═════════════ */

    /** تاجرٌ لا ينقل خيطًا إلى دفتر مبيعاتنا */
    public function test_a_merchant_cannot_move_a_thread_into_our_sales_book(): void
    {
        $thread = $this->thread();
        Support::say($thread, $this->merchant, 'business', 'مرحبا', false, null, null, 'wamid.A');

        $this->actingAs($this->merchant)
            ->post(route('super-admin.conversations.toCrm', $thread->id))
            ->assertForbidden();

        $this->assertSame(0, CrmLead::count());
    }

    /** ومالكُ المنصّة ينقله ويُقاد إلى العميل */
    public function test_the_platform_owner_moves_it_and_lands_on_the_lead(): void
    {
        $super = User::create([
            'name' => 'مدير المنصة', 'email' => 'root@abaad.om', 'password' => bcrypt('x'),
            'role' => 'super_admin', 'status' => 'active', 'business_id' => null,
        ]);

        $thread = $this->thread();
        Support::say($thread, $this->merchant, 'business', 'مرحبا', false, null, null, 'wamid.A');

        $lead = null;

        $this->actingAs($super)
            ->post(route('super-admin.conversations.toCrm', $thread->id))
            ->assertRedirect();

        $lead = CrmLead::where('phone', '96890683111')->first();

        $this->assertNotNull($lead);
        $this->assertSame(1, CrmMessage::where('lead_id', $lead->id)->count());
    }
}
