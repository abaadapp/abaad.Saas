<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Customer;
use App\Models\SupportConversation;
use App\Models\SupportMessage;
use App\Models\User;
use App\Models\WhatsAppConnection;
use App\Models\WhatsAppMessage;
use App\Support\Support;
use App\Support\SupportWhatsApp;
use App\Support\WhatsAppMode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * واتسابُ الدعم — ما يُقرأ منه، وما لا يُقرأ أبدًا، وما لا يخرج.
 *
 * ═══ أثقلُ ما هنا حارسان ═══
 *
 * **الأوّل**: الرقمُ المشترك يُرسل إشعاراتِ الطلبات نيابةً عن المحلّات،
 * فيردّ عليه زبائنُهم. ولو خُزّن ردُّ زبونٍ لَقرأه مديرُ المنصّة في لوحته:
 * ما كتبته زبونةٌ لمحلّ ورودٍ عن هديّةٍ لزوجها. فلا يُخزَّن واردٌ إلّا من
 * رقمٍ يُطابق مستخدمًا **واحدًا** له متجر.
 *
 * **والثاني**: الملاحظةُ الداخليّة لا تخرج إلى هاتف التاجر. وهي قناةٌ
 * جديدة، والشرطُ الذي يحرسها في الشاشة لا يحرسها هنا.
 *
 * ═══ وحالُ التسليم تُقاس ولا تُفترض ═══
 *
 * ميتا تمنع النصّ الحرّ خارج نافذة الأربعِ والعشرين ساعة. فردٌّ يُكتب في
 * المركز قد لا يخرج — ويُقال إنّه لم يخرج، لا يُصمَت عنه فيُظنّ واصلًا.
 */
class AbaadAnswersOnWhatsAppTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private User $owner;

    private User $staff;

    private WhatsAppConnection $line;

    protected function setUp(): void
    {
        parent::setUp();

        config(['whatsapp.app_secret' => 'test-app-secret']);
        Http::preventStrayRequests();

        $this->shop = $this->shop('محل الورد');
        $this->owner = $this->person($this->shop->id, 'admin', 'صاحب المحل', '96891112222');
        $this->staff = $this->person(null, 'super_admin', 'دعم أبعاد');

        $this->line = WhatsAppConnection::create([
            'owner_type' => WhatsAppMode::OWNER_PLATFORM,
            'phone_number_id' => 'ABAAD-PN',
            'display_phone_number' => '+968 7114 1624',
            'access_token' => 'platform-token-value-0123456789',
            'status' => WhatsAppConnection::ACTIVE,
            'supports_inbox' => true,
        ]);
    }

    /* ═══════════════ أدوات ═══════════════ */

    private function shop(string $name): Business
    {
        return Business::create([
            'name' => $name, 'type' => 'محل ورود', 'status' => 'نشط', 'city' => 'مسقط',
        ]);
    }

    private function person(?int $businessId, string $role, string $name = 'مستخدم', ?string $phone = null): User
    {
        static $n = 0;
        $n++;

        return User::create([
            'business_id' => $businessId, 'name' => $name, 'email' => "wa{$n}@abaad.om",
            'password' => bcrypt('password'), 'role' => $role, 'status' => 'نشط', 'phone' => $phone,
        ]);
    }

    /** حمولةُ رسالةٍ واردة موقَّعةٌ كما توقّعها ميتا */
    private function inbound(
        string $from = '96891112222',
        string $text = 'الفاتورة لا تُطبع عندي',
        string $wamid = 'wamid.IN-1',
        string $phoneId = 'ABAAD-PN',
        ?array $message = null,
    ) {
        $payload = ['entry' => [['changes' => [['value' => [
            'metadata' => ['phone_number_id' => $phoneId],
            'messages' => [$message ?? [
                'id' => $wamid,
                'from' => $from,
                'type' => 'text',
                'timestamp' => (string) now()->timestamp,
                'text' => ['body' => $text],
            ]],
        ]]]]]];

        $body = json_encode($payload, JSON_UNESCAPED_UNICODE);

        return $this->call('POST', route('webhooks.whatsapp'), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, 'test-app-secret'),
        ], $body);
    }

    /** محادثةُ واتسابٍ نافذتُها مفتوحة */
    private function live(): SupportConversation
    {
        $this->inbound()->assertOk();

        return SupportConversation::firstOrFail();
    }

    /* ═══════════════ الحدُّ الذي لا يُعبر ═══════════════ */

    /**
     * ردُّ زبونٍ على إشعارِ طلبه لا يصير محادثةَ دعم.
     *
     * وهو أثقلُ حارسٍ في هذا الملفّ: الرقمُ يُرسل نيابةً عن المحلّات، وأكثرُ
     * ما يصله ردودُ زبائنِهم. وخزنُ واحدةٍ منها يعني أن يقرأ مديرُ المنصّة
     * ما كتبته زبونةٌ لمحلِّها.
     */
    public function test_a_customer_reply_is_not_stored_at_all(): void
    {
        Customer::create([
            'business_id' => $this->shop->id, 'name' => 'زبونة', 'phone' => '96895556666',
        ]);

        $this->inbound(from: '96895556666', text: 'وين وصل الطلب؟')->assertOk();

        $this->assertSame(0, SupportConversation::count(), 'رسالةُ زبونٍ صارت محادثةَ دعم');
        $this->assertSame(0, SupportMessage::count(), 'نصُّ زبونٍ خُزّن في جداول الدعم');
    }

    /** ورقمٌ لا يعرفه النظامُ أصلًا يُسقَط */
    public function test_an_unknown_number_is_dropped(): void
    {
        $this->inbound(from: '96899998888')->assertOk();

        $this->assertSame(0, SupportConversation::count());
    }

    /**
     * ورقمٌ يُطابق اثنين لا يُنسب لأحدهما.
     *
     * نسبتُه بالحدس تعني محادثةً تُفتح باسم متجرٍ لم يكتبها أحدٌ فيه.
     */
    public function test_an_ambiguous_number_is_dropped(): void
    {
        $other = $this->shop('محل آخر');
        $this->person($other->id, 'admin', 'صاحب الآخر', '+968 9111 2222');

        $this->inbound()->assertOk();

        $this->assertSame(0, SupportConversation::count(), 'رقمٌ مكرَّرٌ نُسب لمتجرٍ بالحدس');
    }

    /** والبابُ مغلقٌ ما لم يُؤذن له صراحةً */
    public function test_nothing_is_read_while_the_inbox_is_closed(): void
    {
        $this->line->update(['supports_inbox' => false]);

        $this->inbound()->assertOk();

        $this->assertSame(0, SupportConversation::count(), 'قُرئ الواردُ على رقمٍ لم يُفتح بابُه');
    }

    /** وهو مغلقٌ في الهجرة — ترقيةٌ لا تفتح بابًا لم يطلبه أحد */
    public function test_the_inbox_is_closed_by_default(): void
    {
        $fresh = WhatsAppConnection::create([
            'owner_type' => WhatsAppMode::OWNER_PLATFORM,
            'phone_number_id' => 'OTHER-PN',
            'access_token' => 'token-value-0123456789012',
            'status' => WhatsAppConnection::ACTIVE,
        ]);

        $this->assertFalse((bool) $fresh->fresh()->supports_inbox);
    }

    /** وتوقيعٌ مزوَّرٌ لا يفتح شيئًا */
    public function test_an_unsigned_payload_stores_nothing(): void
    {
        $body = json_encode(['entry' => [['changes' => [['value' => [
            'metadata' => ['phone_number_id' => 'ABAAD-PN'],
            'messages' => [['id' => 'wamid.X', 'from' => '96891112222', 'type' => 'text', 'text' => ['body' => 'مرحبا']]],
        ]]]]]]);

        $this->call('POST', route('webhooks.whatsapp'), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256=deadbeef',
        ], $body)->assertStatus(403);

        $this->assertSame(0, SupportConversation::count());
    }

    /* ═══════════════ الوارد يُقرأ ═══════════════ */

    public function test_a_shop_owner_message_opens_a_conversation(): void
    {
        $this->inbound()->assertOk();

        $c = SupportConversation::firstOrFail();

        $this->assertSame($this->shop->id, $c->business_id);
        $this->assertSame($this->owner->id, $c->opened_by);
        $this->assertSame('whatsapp', $c->channel);
        $this->assertSame('96891112222', $c->contact_phone);
        $this->assertSame('new', $c->status);
        $this->assertStringStartsWith('SUP-', $c->reference);
        $this->assertSame('الفاتورة لا تُطبع عندي', $c->messages()->first()->body);
        $this->assertSame('business', $c->messages()->first()->sender_scope);
    }

    /** ورسالةٌ ثانيةٌ تُكتب في الخيط نفسِه لا في خيطٍ جديد */
    public function test_a_second_message_joins_the_same_thread(): void
    {
        $this->inbound()->assertOk();
        $this->inbound(text: 'وأيضًا الطباعة بطيئة', wamid: 'wamid.IN-2')->assertOk();

        $this->assertSame(1, SupportConversation::count(), 'كلُّ رسالةٍ فتحت خيطًا');
        $this->assertSame(2, SupportMessage::whereNull('event')->count());
        $this->assertSame('waiting_abaad', SupportConversation::first()->status);
    }

    /**
     * وإشعارٌ وصل مرّتين يُكتب مرّة.
     *
     * ميتا تُعيد الإشعارَ حين لا تصلها ٢٠٠ في الوقت. وبلا هذا يقرأ التاجرُ
     * رسالتَه مكرَّرةً في خيطه ويظنّ العطبَ في يده.
     */
    public function test_a_repeated_webhook_writes_one_message(): void
    {
        $this->inbound()->assertOk();
        $this->inbound()->assertOk();

        $this->assertSame(1, SupportMessage::whereNull('event')->count());
        $this->assertSame(1, SupportConversation::count());
    }

    /** وخيطٌ حُلّ قبل شهرٍ لا يُبعث برسالةٍ عن شيءٍ آخر */
    public function test_an_old_resolved_thread_is_not_revived(): void
    {
        $c = $this->live();
        $c->forceFill([
            'status' => 'resolved',
            'resolved_at' => now()->subMonth(),
            'last_message_at' => now()->subMonth(),
        ])->save();

        $this->inbound(text: 'سؤالٌ آخر تمامًا', wamid: 'wamid.IN-9')->assertOk();

        $this->assertSame(2, SupportConversation::count(), 'خيطٌ قديمٌ بُعث بدل أن يُفتح جديد');
        $this->assertSame('resolved', $c->fresh()->status, 'تبدّلت حالُ خيطٍ محلولٍ من رسالةٍ لا تخصّه');
    }

    /** وخيطٌ حُلّ للتوّ يُعاد فتحُه — من ردّ في دقيقته يتكلّم عن الشيء نفسِه */
    public function test_a_just_resolved_thread_reopens(): void
    {
        $c = $this->live();
        $c->forceFill(['status' => 'resolved', 'resolved_at' => now()])->save();

        $this->inbound(text: 'ما زالت المشكلة', wamid: 'wamid.IN-3')->assertOk();

        $this->assertSame(1, SupportConversation::count());
        $this->assertSame('waiting_abaad', $c->fresh()->status);
    }

    /**
     * وواردٌ غيرُ نصّيٍّ يُكتب بما هو — لا يُسقَط ولا يُكتب فارغًا.
     *
     * تاجرٌ أرسل صورةً ولم يردّ عليه أحد يظنّ الدعمَ لا يقرأ؛ وسطرٌ أبيضُ في
     * الخيط لا يقول شيئًا لمن يفتحه.
     */
    public function test_a_non_text_message_is_recorded_as_what_it_is(): void
    {
        $this->inbound(message: [
            'id' => 'wamid.IMG', 'from' => '96891112222', 'type' => 'image',
            'image' => ['id' => 'media-1', 'mime_type' => 'image/jpeg'],
        ])->assertOk();

        $body = (string) SupportMessage::whereNull('event')->first()->body;

        $this->assertNotSame('', trim($body), 'وارِدٌ غيرُ نصّيٍّ كُتب سطرًا فارغًا');
        $this->assertStringContainsString('صورة', $body);
    }

    /* ═══════════════ الصادر ═══════════════ */

    /**
     * الملاحظةُ الداخليّة لا تخرج إلى واتساب — ولا تُحاوَل.
     *
     * والمحادثةُ هنا محادثةُ واتسابٍ نافذتُها مفتوحةٌ وخطُّها موصول: كلُّ
     * شرطٍ آخرَ يقول «أرسِل». فلو سقط فحصُ `is_internal` وحدَه لَخرج كلامُ
     * الفريق عن التاجر إلى هاتف التاجر نفسِه.
     */
    public function test_an_internal_note_never_leaves_abaad(): void
    {
        $c = $this->live();

        $this->actingAs($this->staff)->post(route('super-admin.conversations.reply', $c->id), [
            'body' => 'هذا التاجر متأخّرٌ في السداد — لا تَعِده بشيء',
            'internal' => true,
        ])->assertRedirect();

        Http::assertNothingSent();

        $note = SupportMessage::where('is_internal', true)->whereNull('event')->firstOrFail();
        $this->assertNull($note->delivery, 'مُنحت ملاحظةٌ داخليّةٌ حالَ تسليم');
        $this->assertNull($note->external_message_id);
    }

    /**
     * والحارسُ في `deliver` نفسِها — لا في المتحكّم وحده.
     *
     * المتحكّمُ يخرج مبكّرًا عند الملاحظة، فلو كان ذلك حارسَها الوحيد لَبقي
     * فحصُ `is_internal` ميتًا: يومَ يُنادى `deliver` من طابورٍ أو مهمّةٍ
     * أو قناةٍ ثانية يخرج كلامُ الفريق إلى هاتف التاجر ولا شيء يمنعه.
     *
     * فتُنادى الدالّةُ هنا مباشرةً بملاحظةٍ داخليّة، وكلُّ شرطٍ آخرَ يقول
     * «أرسِل»: الخطُّ موصولٌ والنافذةُ مفتوحةٌ والرقمُ محفوظ.
     */
    public function test_deliver_itself_refuses_an_internal_note(): void
    {
        $c = $this->live();

        $this->assertTrue(SupportWhatsApp::windowOpen($c), 'شرطُ النافذة لم يتحقّق فالطفرةُ لا تُقاس');
        $this->assertTrue(SupportWhatsApp::connected(), 'الخطُّ غيرُ موصولٍ فالطفرةُ لا تُقاس');

        $note = Support::say($c, $this->staff, 'platform', 'لا تَعِده بشيء', internal: true);

        SupportWhatsApp::deliver($c->fresh(), $note);

        Http::assertNothingSent();
        $this->assertNull($note->fresh()->delivery);
    }

    /**
     * والنافذةُ تُقرأ من ختم الوارد لا من آخر حركةٍ في الخيط.
     *
     * `last_message_at` يتحرّك بردّ أبعادٍ أيضًا. ولو حُسبت النافذةُ منه
     * لَقالت الشاشةُ «مفتوحة» لأنّ الدعمَ كتب — ثمّ تردّ ميتا الرسالة.
     */
    public function test_the_window_is_read_from_the_inbound_stamp_only(): void
    {
        $c = $this->live();

        $c->forceFill([
            'whatsapp_window_at' => now()->subHours(30),
            'last_message_at' => now(),
        ])->save();

        $this->assertFalse(
            SupportWhatsApp::windowOpen($c->fresh()),
            'حُسبت النافذةُ من آخر حركةٍ في الخيط لا من ختم الوارد',
        );
    }

    /** وردٌّ داخلَ النافذة يخرج فعلًا — إلى الرقم الصحيح وبالنصّ الذي كُتب */
    public function test_a_reply_inside_the_window_is_sent(): void
    {
        $c = $this->live();

        Http::fake([
            '*/messages' => Http::response(['messages' => [['id' => 'wamid.OUT-1']]], 200),
        ]);

        $this->actingAs($this->staff)->post(route('super-admin.conversations.reply', $c->id), [
            'body' => 'حدّث المتصفّح ثم جرّب الطباعة',
        ])->assertRedirect();

        Http::assertSent(function ($request) {
            $body = $request->data();

            return str_contains($request->url(), 'ABAAD-PN/messages')
                && $body['to'] === '96891112222'
                && $body['type'] === 'text'
                && $body['text']['body'] === 'حدّث المتصفّح ثم جرّب الطباعة';
        });

        $reply = SupportMessage::where('sender_scope', 'platform')->whereNull('event')->firstOrFail();
        $this->assertSame('sent', $reply->delivery);
        $this->assertSame('wamid.OUT-1', $reply->external_message_id);
        $this->assertNull($reply->delivery_error);
        $this->assertSame('success', session('toast')['type'] ?? null);
    }

    /**
     * وردٌّ خارجَ النافذة لا يُحاوَل، ويُكتب أنّه لم يخرج.
     *
     * ميتا تردّه بالخطأ ١٣١٠٤٧. وكتابتُه «فشل» تعني دعمًا يُعيد المحاولة على
     * منعٍ لا يزول؛ والصمتُ عنه يعني دعمًا ينتظر جوابًا على كلامٍ لم يصل.
     */
    public function test_a_reply_outside_the_window_is_saved_but_not_sent(): void
    {
        $c = $this->live();
        $c->forceFill(['whatsapp_window_at' => now()->subHours(25)])->save();

        $this->actingAs($this->staff)->post(route('super-admin.conversations.reply', $c->id), [
            'body' => 'اعتذر عن التأخير',
        ])->assertRedirect();

        Http::assertNothingSent();

        $reply = SupportMessage::where('sender_scope', 'platform')->whereNull('event')->firstOrFail();
        $this->assertSame('blocked', $reply->delivery);
        $this->assertNotNull($reply->delivery_error);
        $this->assertSame('اعتذر عن التأخير', $reply->body, 'ضاع الردُّ لأنّه لم يخرج');

        /* ويُقال في اللحظة نفسِها — صمتٌ بعد الضغط يُقرأ نجاحًا */
        $toast = session('toast');
        $this->assertSame('error', $toast['type'] ?? null, 'مضى الردُّ بلا خبرٍ عن أنّه لم يخرج');
        $this->assertStringContainsString((string) $reply->delivery_error, (string) ($toast['msg'] ?? ''));
    }

    /** والنافذةُ تُقاس من الوارد وحده — لا من آخر رسالةٍ في الخيط */
    public function test_an_abaad_reply_does_not_reopen_metas_window(): void
    {
        $c = $this->live();
        $c->forceFill(['whatsapp_window_at' => now()->subHours(30)])->save();

        Support::say($c->fresh(), $this->staff, 'platform', 'ردٌّ متأخّر');

        $this->assertFalse(SupportWhatsApp::windowOpen($c->fresh()), 'ردُّ أبعادٍ فتح نافذةَ ميتا');
    }

    /** وعطلٌ عند المزوّد يُكتب بنصّه لا يُبتلع */
    public function test_a_provider_failure_is_written_on_the_message(): void
    {
        $c = $this->live();

        Http::fake([
            '*/messages' => Http::response(['error' => ['code' => 131047, 'message' => 'Re-engagement message']], 400),
        ]);

        $this->actingAs($this->staff)->post(route('super-admin.conversations.reply', $c->id), [
            'body' => 'جرّب الآن',
        ])->assertRedirect();

        $reply = SupportMessage::where('sender_scope', 'platform')->whereNull('event')->firstOrFail();
        $this->assertSame('failed', $reply->delivery);
        $this->assertStringContainsString('Re-engagement', (string) $reply->delivery_error);
        $this->assertSame('جرّب الآن', $reply->body);
    }

    /** وخطٌّ فُصل بعد أن بدأت المحادثة: يُقال، ولا يُحاوَل النداء */
    public function test_a_disconnected_line_blocks_the_send_and_says_so(): void
    {
        $c = $this->live();
        $this->line->update(['status' => WhatsAppConnection::INACTIVE]);

        $this->actingAs($this->staff)->post(route('super-admin.conversations.reply', $c->id), [
            'body' => 'أهلًا',
        ])->assertRedirect();

        Http::assertNothingSent();

        $reply = SupportMessage::where('sender_scope', 'platform')->whereNull('event')->firstOrFail();
        $this->assertSame('failed', $reply->delivery);
    }

    /** ومحادثةٌ داخل أبعادٍ لا تُقاس بحال تسليم — لا شيء يخرج منها أصلًا */
    public function test_an_in_app_reply_carries_no_delivery_state(): void
    {
        $c = Support::open($this->owner, 'سؤال', 'أخرى', 'كيف أضيف موظّفًا؟');

        $this->actingAs($this->staff)->post(route('super-admin.conversations.reply', $c->id), [
            'body' => 'من صفحة الموظفين',
        ])->assertRedirect();

        Http::assertNothingSent();

        $this->assertNull(
            SupportMessage::where('sender_scope', 'platform')->whereNull('event')->firstOrFail()->delivery,
        );
    }

    /* ═══════════════ العزلُ عن رسائل المتاجر ═══════════════ */

    /**
     * ودعمُ أبعادٍ لا يُكتب في سجلّ رسائل المتاجر ولا يستهلك حصّةَ أحد.
     *
     * `whatsapp_messages` سجلُّ ما أرسله المحلُّ لزبائنه، ومنه تُحسب حصّتُه
     * الشهرية. وكتابةُ ردِّ دعمٍ فيه تعني تاجرًا يُخصم من حصّته لأنّنا
     * كلّمناه.
     */
    public function test_support_traffic_never_touches_the_shops_message_log(): void
    {
        $c = $this->live();

        Http::fake(['*/messages' => Http::response(['messages' => [['id' => 'wamid.OUT-2']]], 200)]);

        $this->actingAs($this->staff)->post(route('super-admin.conversations.reply', $c->id), [
            'body' => 'تمّ',
        ])->assertRedirect();

        $this->assertSame(0, WhatsAppMessage::count(), 'رسالةُ دعمٍ كُتبت في سجلّ رسائل المتجر');
    }

    /* ═══════════════ القناةُ تُعرض حين تعمل ═══════════════ */

    /** خطٌّ موصول: القناةُ تُعرض */
    public function test_the_channel_is_offered_once_the_line_is_open(): void
    {
        $channels = $this->actingAs($this->staff)
            ->get(route('super-admin.conversations.index'))
            ->viewData('page')['props']['channels'];

        $this->assertContains('whatsapp', array_column($channels, 'value'));
    }

    /** وخطٌّ لم يُفتح بابُه: لا تُعرض — بابٌ معروضٌ لا يُفتح أسوأ من بابٍ لا يُعرض */
    public function test_the_channel_is_hidden_while_the_line_is_closed(): void
    {
        $this->line->update(['supports_inbox' => false]);

        $channels = $this->actingAs($this->staff)
            ->get(route('super-admin.conversations.index'))
            ->viewData('page')['props']['channels'];

        $this->assertSame(['in_app'], array_column($channels, 'value'));
    }

    /** ورمزٌ منتهٍ ليس خطًّا موصولًا مهما قال العمود */
    public function test_an_expired_token_is_not_an_open_line(): void
    {
        $this->line->update(['token_expires_at' => now()->subDay()]);

        $this->assertFalse(SupportWhatsApp::connected());
    }

    /* ═══════════════ الشاشة تقول الحقيقة ═══════════════ */

    /** حالُ النافذة تُقرأ في خصائص الشاشة قبل أن يكتب الدعم */
    public function test_the_screen_is_told_whether_the_window_is_open(): void
    {
        $c = $this->live();

        $props = $this->actingAs($this->staff)
            ->get(route('super-admin.conversations.index', ['conversation' => $c->id]))
            ->viewData('page')['props'];

        $this->assertTrue($props['active']['whatsappWindowOpen']);
        $this->assertNotNull($props['active']['whatsappWindowEndsAt']);

        $c->forceFill(['whatsapp_window_at' => now()->subHours(25)])->save();

        $props = $this->actingAs($this->staff)
            ->get(route('super-admin.conversations.index', ['conversation' => $c->id]))
            ->viewData('page')['props'];

        $this->assertFalse($props['active']['whatsappWindowOpen'], 'قالت الشاشةُ إنّ النافذة مفتوحةٌ وهي مغلقة');
    }

    /** وحالُ الخروج تُقرأ في الرسالة نفسِها */
    public function test_the_thread_carries_the_delivery_state(): void
    {
        $c = $this->live();
        $c->forceFill(['whatsapp_window_at' => now()->subHours(25)])->save();

        $this->actingAs($this->staff)->post(route('super-admin.conversations.reply', $c->id), ['body' => 'مرحبًا']);

        $messages = $this->actingAs($this->staff)
            ->get(route('super-admin.conversations.index', ['conversation' => $c->id]))
            ->viewData('page')['props']['messages'];

        $last = end($messages);
        $this->assertSame('blocked', $last['delivery']);
        $this->assertNotNull($last['deliveryLabel']);
    }

    /* ═══════════════ المقبض ═══════════════ */

    public function test_only_the_platform_may_open_the_inbox(): void
    {
        $this->actingAs($this->owner)
            ->post(route('super-admin.whatsapp.support-inbox'), ['supports_inbox' => true])
            ->assertForbidden();
    }

    public function test_the_platform_opens_and_closes_the_inbox(): void
    {
        $this->line->update(['supports_inbox' => false]);

        $this->actingAs($this->staff)
            ->post(route('super-admin.whatsapp.support-inbox'), ['supports_inbox' => true])
            ->assertRedirect();

        $this->assertTrue((bool) $this->line->fresh()->supports_inbox);

        $this->actingAs($this->staff)
            ->post(route('super-admin.whatsapp.support-inbox'), ['supports_inbox' => false])
            ->assertRedirect();

        $this->assertFalse((bool) $this->line->fresh()->supports_inbox);
    }

    /**
     * وعددُ من يستطيع أن يصل — محسوبٌ لا موعود.
     *
     * القناةُ تُشعَل ثمّ لا يصل شيء فيُظنّ العطبُ في الربط، والسببُ أنّ
     * أصحابَ المتاجر لم يكتبوا أرقامهم.
     */
    public function test_the_reach_is_counted_honestly(): void
    {
        $other = $this->shop('محل ثانٍ');
        $this->person($other->id, 'admin', 'بلا رقم');
        $twinShop = $this->shop('محل ثالث');
        $this->person($twinShop->id, 'admin', 'توأم', '96897777777');
        $this->person($this->shop->id, 'sales', 'توأم آخر', '+968 9777 7777');

        $reach = SupportWhatsApp::reach();

        $this->assertSame(4, $reach['total']);
        $this->assertSame(1, $reach['reachable'], 'عُدّ من لا يُطابَق قابلًا للوصول');
        $this->assertSame(1, $reach['ambiguous']);
    }

    /* ═══════════════ التاجرُ يقرأ خيطَه ═══════════════ */

    /** ومحادثةُ واتسابٍ يقرؤها صاحبُها في لوحته — هي محادثتُه */
    public function test_the_shop_reads_its_own_whatsapp_thread(): void
    {
        $c = $this->live();

        $this->actingAs($this->owner)
            ->get(route('admin.help.show', $c->id))
            ->assertOk();
    }

    /** ولا يقرؤها متجرٌ آخر */
    public function test_another_shop_cannot_read_it(): void
    {
        $c = $this->live();
        $stranger = $this->person($this->shop('محل الجار')->id, 'admin', 'الجار');

        $this->actingAs($stranger)->get(route('admin.help.show', $c->id))->assertNotFound();
    }

    /** والملاحظةُ الداخليّةُ على خيط واتساب لا تُعرض له */
    public function test_an_internal_note_is_invisible_to_the_shop(): void
    {
        $c = $this->live();

        Support::say($c, $this->staff, 'platform', 'سرٌّ للفريق', internal: true);

        $this->actingAs($this->owner)
            ->get(route('admin.help.show', $c->id))
            ->assertDontSee('سرٌّ للفريق', false);
    }
}
