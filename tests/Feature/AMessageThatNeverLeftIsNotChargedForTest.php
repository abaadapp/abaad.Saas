<?php

namespace Tests\Feature;

use App\Jobs\SendWhatsAppMessage;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Setting;
use App\Models\User;
use App\Models\WhatsAppConnection;
use App\Models\WhatsAppMessage;
use App\Support\WhatsAppEvent;
use App\Support\WhatsAppMode;
use App\Support\WhatsAppQuota;
use App\Support\WhatsAppStatus;
use App\Support\WhatsAppTemplates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * رسالةٌ لم تخرج لا تُحاسَب عليها — ولا تُحاسَب مرّتين.
 *
 * ═══ الحجزُ قبل النداء، وهو الترتيب الوحيد الآمن ═══
 *
 * `SendWhatsAppMessage` تحجز ذرّةً من الحصّة **قبل** أن تنادي ميتا: لو
 * خُصمت بعد القبول لَخرجت رسالتان متزامنتان من حدٍّ فيه واحدة. والثمنُ أنّ
 * كلَّ مخرجٍ بعد الحجز مسؤولٌ عن ردّ ما حُجز — ومخرجان كانا ينسيان.
 *
 *  • **«لا موضوع لها».** الطلبُ أو الفاتورةُ يُمحى بين الإدراج والتنفيذ،
 *    فتقف الوظيفة. وكانت تقف بلا ردّ: يُنقص عدّادُ الشهر، أو **تحترق
 *    رسالةٌ دُفع ثمنُها**. ولا يُكتشف: المخرجُ يكتب `quota_consumed = false`
 *    فيُنكر الصفُّ حجزًا وقع.
 *
 *  • **محاولةٌ ثانية.** أوّلُ سطرٍ في `handle` يحرس ما خرج بالحالة، ولا
 *    يحرس ما حُجز ولم يخرج. فمحاولةٌ سقطت باستثناءٍ بعد الحجز تترك الصفَّ
 *    «مُدرَجة» والعدّادَ ناقصًا، فتحجز التاليةُ من جديد: حصّتان لرسالةٍ
 *    واحدة.
 *
 * ═══ والجيبُ يُعرَف ═══
 *
 * الحصّةُ جيبان: عطيّةُ الشهر تسقط بقيّتُها، والرصيدُ المشترى مالٌ دُفع.
 * فحرّاسُ الردّ هنا مكرَّرةٌ على الجيبين معًا — الخطأُ في الأوّل رقمٌ على
 * شاشة، وفي الثاني ريالٌ لا يعود.
 */
class AMessageThatNeverLeftIsNotChargedForTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    private Customer $customer;

    private WhatsAppConnection $shared;

    protected function setUp(): void
    {
        parent::setUp();

        Setting::updateOrCreate(['business_id' => null, 'key' => 'whatsapp_enabled'], ['value' => '1']);
        Setting::updateOrCreate(['business_id' => null, 'key' => WhatsAppQuota::DEFAULT_KEY], ['value' => '100']);

        $this->shared = WhatsAppConnection::create([
            'owner_type' => WhatsAppMode::OWNER_PLATFORM,
            'phone_number_id' => 'ABAAD-PN',
            'display_phone_number' => '+96890000000',
            'access_token' => 'platform-token-value-0123456789',
            'status' => WhatsAppConnection::ACTIVE,
            'connected_at' => now(),
        ]);
        WhatsAppTemplates::seedPlatformDefaults('ar');

        $this->business = Business::create(['name' => 'محل ورد', 'type' => 'محل ورود', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);
        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        $this->customer = Customer::create([
            'business_id' => $this->business->id, 'name' => 'زبون', 'phone' => '91234567',
        ]);

        foreach (WhatsAppEvent::SETTING_KEYS as $key) {
            Setting::updateOrCreate(['business_id' => $this->business->id, 'key' => $key], ['value' => '1']);
        }
    }

    /** @param  array<string, mixed>  $over */
    private function queued(array $over = []): WhatsAppMessage
    {
        return WhatsAppMessage::create(array_merge([
            'business_id' => $this->business->id,
            'customer_id' => $this->customer->id,
            'order_id' => null,
            'whatsapp_connection_id' => $this->shared->id,
            'source_mode' => WhatsAppMode::ABAAD_SHARED,
            'event_type' => WhatsAppEvent::ORDER_READY,
            'template_name' => 'order_ready',
            'language_code' => 'ar',
            'recipient_phone' => '96891234567',
            'status' => WhatsAppStatus::QUEUED,
            'dedupe_key' => 'guard-'.bin2hex(random_bytes(6)),
        ], $over));
    }

    private function order(): Order
    {
        return Order::create([
            'business_id' => $this->business->id, 'customer_id' => $this->customer->id,
            'number' => 'INV-000001', 'status' => 'قيد التجهيز',
            'subtotal' => 10, 'total' => 10, 'ordered_at' => now(),
        ]);
    }

    private function used(): int
    {
        return WhatsAppQuota::used($this->business->fresh());
    }

    private function credits(): int
    {
        return (int) $this->business->fresh()->whatsapp_message_credits;
    }

    /* ─────────── أوّلًا: ما وقف بلا موضوع يردّ ما حجز ─────────── */

    /** رسالةٌ محا طلبُها قبل تنفيذها لا تُنقص عدّادَ الشهر */
    public function test_a_message_with_no_subject_gives_the_month_back(): void
    {
        Http::fake();

        $message = $this->queued();

        (new SendWhatsAppMessage($message->id))->handle();

        $this->assertSame(WhatsAppStatus::SKIPPED, $message->fresh()->status);
        Http::assertNothingSent();
        $this->assertSame(0, $this->used(), 'نقص العدّادُ لرسالةٍ لم تخرج');
    }

    /** ولا تحرق رصيدًا دُفع ثمنُه — وهذا الأخطر: ريالٌ لا يعود */
    public function test_a_message_with_no_subject_does_not_burn_a_purchased_credit(): void
    {
        Http::fake();

        // لا عطيّةَ شهريّة، فالحجزُ يقع على المشترى وحدَه
        $this->business->update(['whatsapp_monthly_limit' => 0]);
        WhatsAppQuota::grant($this->business, 3);

        (new SendWhatsAppMessage($this->queued()->id))->handle();

        $this->assertSame(3, $this->credits(), 'احترقت رسالةٌ مدفوعةٌ بلا أن تخرج');
    }

    /* ─────────── ثانيًا: ومحاولةٌ ثانية لا تحجز ثانيةً ─────────── */

    /**
     * صفٌّ يحمل أثرَ حجزٍ سابق يُستأنَف لا يُعاد.
     *
     * وهذه صورةُ محاولةٍ سقطت باستثناءٍ بعد الحجز: الحالةُ «مُدرَجة» بعد،
     * والعدّادُ ناقصٌ واحدًا، والعمودُ يقول إنّ الحجز وقع.
     */
    public function test_a_second_attempt_resumes_the_first_reservation(): void
    {
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.OK']]], 200)]);

        $message = $this->queued([
            'order_id' => $this->order()->id,
            'quota_consumed' => true,
            'quota_source' => WhatsAppQuota::SOURCE_MONTHLY,
        ]);
        WhatsAppQuota::reserve($this->business);

        $this->assertSame(1, $this->used());

        (new SendWhatsAppMessage($message->id))->handle();

        $this->assertSame(WhatsAppStatus::SENT, $message->fresh()->status);
        $this->assertSame(1, $this->used(), 'حُجزت حصّةٌ ثانية لرسالةٍ واحدة');
    }

    /** والرصيدُ المشترى كذلك: محاولتان لا تأكلان ريالين */
    public function test_a_second_attempt_does_not_spend_a_second_credit(): void
    {
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.OK']]], 200)]);

        $this->business->update(['whatsapp_monthly_limit' => 0]);
        WhatsAppQuota::grant($this->business, 3);
        WhatsAppQuota::reserve($this->business);
        $this->assertSame(2, $this->credits());

        $message = $this->queued([
            'order_id' => $this->order()->id,
            'quota_consumed' => true,
            'quota_source' => WhatsAppQuota::SOURCE_CREDIT,
        ]);

        (new SendWhatsAppMessage($message->id))->handle();

        $this->assertSame(WhatsAppStatus::SENT, $message->fresh()->status);
        $this->assertSame(2, $this->credits(), 'أُنفق ريالٌ ثانٍ على رسالةٍ واحدة');
    }

    /* ─────────── ثالثًا: ولا تُردّ مرّتين ─────────── */

    /**
     * رسالةٌ ردّها المزوّد تُردّ حصّتُها مرّةً واحدة.
     *
     * ═══ ولمَ يُسأل عن هذا ═══
     *
     * مسارُ الرفض يردّ حصّتَه بنفسه ثمّ يمضي إلى المخرج النهائيّ — والمخرجُ
     * صار يردّ ما وجده محجوزًا. فلولا أنّه يُصفّر العمودَ قبل أن يصل إليه
     * لَرُدَّت مرّتان: يربح التاجرُ رسالةً لم يدفعها، ويخسر بيتُ المال.
     * وهو عطبٌ لا يقع إلّا من إصلاحِ العطب الذي قبله.
     */
    public function test_a_rejected_message_is_refunded_once_not_twice(): void
    {
        Http::fake(['*' => Http::response([
            'error' => ['code' => 131047, 'message' => 'Re-engagement message'],
        ], 400)]);

        WhatsAppQuota::reserve($this->business);
        WhatsAppQuota::release($this->business, WhatsAppQuota::SOURCE_MONTHLY);
        $this->assertSame(0, $this->used());

        $message = $this->queued(['order_id' => $this->order()->id]);

        (new SendWhatsAppMessage($message->id))->handle();

        $this->assertSame(WhatsAppStatus::FAILED, $message->fresh()->status);
        $this->assertSame(0, $this->used(), 'رُدّت الحصّةُ مرّتين — أو لم تُردّ');
        $this->assertFalse((bool) $message->fresh()->quota_consumed);
    }

    /**
     * ورسالةٌ مشتراةٌ يردّها المزوّد تعود إلى الرصيد مرّةً واحدة.
     *
     * والعدّادُ الشهريّ يُخفي الردَّ المكرَّر: `release` عليه مشروطةٌ
     * بـ`used > 0`، فالردّةُ الثانية على صفرٍ لا تفعل شيئًا. أمّا الرصيدُ
     * فيُزاد بلا شرط — فردٌّ مكرَّرٌ عليه يهب التاجرَ رسالةً لم يدفعها.
     * فيُسأل عن الجيبين لا عن أحدهما.
     */
    public function test_a_rejected_purchased_message_is_refunded_once_not_twice(): void
    {
        Http::fake(['*' => Http::response([
            'error' => ['code' => 131047, 'message' => 'Re-engagement message'],
        ], 400)]);

        $this->business->update(['whatsapp_monthly_limit' => 0]);
        WhatsAppQuota::grant($this->business, 3);

        $message = $this->queued(['order_id' => $this->order()->id]);

        (new SendWhatsAppMessage($message->id))->handle();

        $this->assertSame(WhatsAppStatus::FAILED, $message->fresh()->status);
        $this->assertSame(3, $this->credits(), 'رُدّت الرسالةُ المشتراةُ مرّتين — أو لم تُردّ');
    }

    /**
     * وحجزٌ مُستأنَفٌ يعود إلى جيبه هو حين يُرفض.
     *
     * الاستئنافُ يقرأ `quota_source` من الصفّ. ولو أُهمل وقُرئ «عطيّةَ
     * الشهر» دائمًا لَمضى الإرسالُ صحيحًا — ولا يظهر الخطأ إلّا يوم يُرفض:
     * تُردّ رسالةٌ مشتراةٌ إلى عدّاد الشهر، فيربح التاجرُ عطيّةً ويخسر ريالَه.
     */
    public function test_a_resumed_credit_reservation_is_refunded_to_the_credit(): void
    {
        Http::fake(['*' => Http::response([
            'error' => ['code' => 131047, 'message' => 'Re-engagement message'],
        ], 400)]);

        $this->business->update(['whatsapp_monthly_limit' => 0]);
        WhatsAppQuota::grant($this->business, 3);
        WhatsAppQuota::reserve($this->business);
        $this->assertSame(2, $this->credits());

        $message = $this->queued([
            'order_id' => $this->order()->id,
            'quota_consumed' => true,
            'quota_source' => WhatsAppQuota::SOURCE_CREDIT,
        ]);

        (new SendWhatsAppMessage($message->id))->handle();

        $this->assertSame(WhatsAppStatus::FAILED, $message->fresh()->status);
        $this->assertSame(3, $this->credits(), 'رُدّت رسالةٌ مشتراةٌ إلى جيبٍ غير جيبها');
        $this->assertSame(0, $this->used(), 'رُدّت إلى عدّاد الشهر — وهو لم يُخصم منه');
    }

    /**
     * وردُّ الرسالة المشتراة لا يمسّ عدّادَ الشهر.
     *
     * ═══ ولمَ هذا سؤالٌ ثالث ═══
     *
     * المخرجُ النهائيّ صار يردّ ما وجده محجوزًا. ومسارُ الرفض يردّ بنفسه ثمّ
     * **يُصفّر العمود والجيب** قبل أن يصل إليه. فلو سُئل المخرجُ بلا شرطٍ —
     * «ردّ دائمًا» — لَوقعت ردّةٌ ثانيةٌ بجيبٍ فارغ، فتسقط على عطيّة الشهر:
     * `release` بلا مصدرٍ تقرأ «الشهر» بالتعريف.
     *
     * ولا يظهر ذلك في متجرٍ عدّادُه صفر — `release` عليه مشروطةٌ بـ`used > 0`
     * فتبتلعه. فيُسأل هنا عن متجرٍ **استهلك من شهره** قبلها: رسالةٌ مشتراةٌ
     * تُردّ، فينقص عدّادُ شهرٍ لم يُخصم منه شيء.
     */
    public function test_refunding_a_purchased_message_does_not_touch_the_month(): void
    {
        $order = $this->order();

        // عطيّةُ شهرٍ واحدة، تُستهلك برسالةٍ خرجت فعلًا — فالعدّادُ واحدٌ ونفد
        $this->business->update(['whatsapp_monthly_limit' => 1]);

        /*
         * وردّان في تتابعٍ لا `fake` مرّتين: النداءُ الثاني لـ`Http::fake`
         * يُضيف قالبًا ولا يُلغي الأوّل، فيبقى `*` الأوّلُ هو الذي يُجيب —
         * وأوّلُ كتابةٍ لهذا الحارس سقطت به: نجحت الرسالةُ الثانية فلم
         * تُردّ حصّتُها، وقرأتُ ذلك عطبًا في الكود وهو عطبٌ في الحارس.
         */
        Http::fakeSequence()
            ->push(['messages' => [['id' => 'wamid.OK']]], 200)
            ->push(['error' => ['code' => 131047, 'message' => 'Re-engagement message']], 400);

        (new SendWhatsAppMessage($this->queued(['order_id' => $order->id])->id))->handle();
        $this->assertSame(1, $this->used());

        // ثمّ رسالةٌ تسقط إلى الرصيد المشترى — ويردّها المزوّد
        WhatsAppQuota::grant($this->business, 2);
        WhatsAppQuota::reserve($this->business);
        $this->assertSame(1, $this->credits(), 'لم يقع الحجزُ على الرصيد — الحارسُ يقيس غيرَ ما يقصد');

        (new SendWhatsAppMessage($this->queued([
            'order_id' => $order->id,
            'quota_consumed' => true,
            'quota_source' => WhatsAppQuota::SOURCE_CREDIT,
        ])->id))->handle();

        $this->assertSame(2, $this->credits(), 'لم تُردّ الرسالةُ المشتراة إلى جيبها');
        $this->assertSame(1, $this->used(), 'نقص عدّادُ الشهر بردٍّ لا يخصّه');
    }

    /** ورسالةٌ خرجت فعلًا تبقى محسوبةً — الردُّ لمن لم يخرج لا لكلّ واقف */
    public function test_a_message_that_did_leave_stays_charged(): void
    {
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.OK']]], 200)]);

        $message = $this->queued(['order_id' => $this->order()->id]);

        (new SendWhatsAppMessage($message->id))->handle();

        $this->assertSame(WhatsAppStatus::SENT, $message->fresh()->status);
        $this->assertTrue((bool) $message->fresh()->quota_consumed);
        $this->assertSame(1, $this->used(), 'خرجت رسالةٌ ولم تُحسب');
    }

    /** وحدٌّ نفد يُردّ قبل الحجز — فلا شيء يُردّ بعده */
    public function test_a_shop_out_of_quota_reserves_nothing(): void
    {
        Http::fake();

        $this->business->update(['whatsapp_monthly_limit' => 0]);

        $message = $this->queued(['order_id' => $this->order()->id]);

        (new SendWhatsAppMessage($message->id))->handle();

        $this->assertSame(WhatsAppStatus::QUOTA_EXCEEDED, $message->fresh()->status);
        Http::assertNothingSent();
        $this->assertSame(0, $this->used());
        $this->assertSame(0, $this->credits());
    }
}
