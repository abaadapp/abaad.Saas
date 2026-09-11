<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Setting;
use App\Models\User;
use App\Support\OrderStatus;
use App\Support\WhatsAppEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * يدٌ تُرسل ما لا تُرسله ميتا.
 *
 * ═══ ولمَ بُني هذا ═══
 *
 * في ٢٠٢٦-٠٩-١١ ردّت ميتا `API access blocked` على كلّ نداء، فبقي زبائنُ
 * كلّ تجّار المنصّة بلا خبرٍ أيّامًا: لا «طلبك جاهز» ولا «في الطريق». والعطبُ
 * خارجُنا، وانتظارُه أسابيع.
 *
 * وهذا الطريقُ لا يمرّ بميتا: يُفتح واتساب التاجر بنصٍّ مكتوب، ويضغط هو
 * «إرسال». فيعمل والحظرُ قائم.
 *
 * ═══ وما تحرسه هذه الحالات ═══
 *
 * أوّلًا أنّه **لا يدّعي** إرسالًا: لا صفَّ في `whatsapp_messages`، ولا كلمةَ
 * «أُرسل» في السجلّ. وطمأنينةٌ كاذبة أسوأ من تحذيرٍ كاذب.
 *
 * وثانيًا أنّ بابًا معروضًا يُفتح: ما تُخفيه الشاشة يَردّه الخادم بالسبب
 * نفسِه، وما تعرضه يعمل.
 */
class AHandSendsWhatMetaWillNotTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'زهور مسقط', 'type' => 'محل ورود', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);
        Currency::create([
            'business_id' => $this->business->id, 'code' => 'OMR', 'name' => 'ريال عماني',
            'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true,
        ]);
        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        $this->customer = Customer::create([
            'business_id' => $this->business->id, 'name' => 'سالم', 'phone' => '91234567',
        ]);

        $this->actingAs($this->owner);
    }

    private function order(string $status, array $extra = []): Order
    {
        return Order::create($extra + [
            'business_id' => $this->business->id,
            'branch_id' => Branch::where('business_id', $this->business->id)->value('id'),
            'customer_id' => $this->customer->id,
            'number' => 'INV-000'.rand(100, 999),
            'status' => $status,
            'is_held' => false,
            'payment_method' => 'نقدي',
            'payment_status' => 'مدفوع',
            'subtotal' => 25, 'total' => 25,
            'ordered_at' => now(),
        ]);
    }

    private function enable(string $key, string $value): void
    {
        Setting::updateOrCreate(
            ['business_id' => $this->business->id, 'key' => $key],
            ['value' => $value],
        );
    }

    private function notify(Order $order)
    {
        return $this->post(route('admin.orders.statusNotice', $order->number));
    }

    /* ------------------------- ما يقع فعلًا ------------------------- */

    /** طلبٌ جاهزٌ يُبلَّغ زبونُه — والرابطُ يفتح واتساب على رقمه */
    public function test_a_ready_order_opens_whatsapp_on_the_customers_number(): void
    {
        $order = $this->order(OrderStatus::READY);

        $this->notify($order)->assertRedirect();

        $toast = session('toast');
        $this->assertSame('success', $toast['type']);
        $this->assertStringContainsString('wa.me/96891234567', $toast['link']['url']);
    }

    /** والنصُّ يحمل رقمَ الطلب — فلا يسأل الزبونُ «أيُّ طلب؟» */
    public function test_the_text_carries_the_order_number(): void
    {
        $order = $this->order(OrderStatus::READY);

        $this->notify($order);

        $this->assertStringContainsString(
            rawurlencode((string) $order->number),
            session('toast')['link']['url'],
        );
    }

    /**
     * ويحمل اسمَ المحلّ.
     *
     * فزبونٌ يشتري من ثلاثة محلّاتٍ لا يعرف صاحبَ الرقم من صاحبه — ورسالةٌ
     * بلا اسمٍ تُقرأ من مجهول.
     */
    public function test_the_text_carries_the_shop_name(): void
    {
        $order = $this->order(OrderStatus::READY);

        $this->notify($order);

        $this->assertStringContainsString(
            rawurlencode('زهور مسقط'),
            session('toast')['link']['url'],
        );
    }

    /** ونصُّ «في الطريق» غيرُ نصِّ «جاهز» — وإلّا فحدثٌ واحدٌ بأربعة أسماء */
    public function test_each_status_has_its_own_words(): void
    {
        $this->notify($this->order(OrderStatus::READY));
        $ready = session('toast')['link']['url'];

        $this->notify($this->order(OrderStatus::OUT_FOR_DELIVERY));
        $out = session('toast')['link']['url'];

        $this->assertNotSame($ready, $out);
        $this->assertStringContainsString(rawurlencode('في الطريق إليك'), $out);
        $this->assertStringNotContainsString(rawurlencode('في الطريق إليك'), $ready);
    }

    /* --------------------- ولا يدّعي ما لم يقع --------------------- */

    /**
     * لا صفَّ في `whatsapp_messages`.
     *
     * ذاك سجلُّ ما مرّ بميتا، و`WhatsAppHealth` تحسب منه «وصلت :n من :t».
     * فصفٌّ بلا معرّفٍ منها يُطفئ تحذيرًا عن عطبٍ لم يُصلَح.
     */
    public function test_no_row_is_written_to_the_meta_message_log(): void
    {
        $before = DB::table('whatsapp_messages')->count();

        $this->notify($this->order(OrderStatus::READY));

        $this->assertSame($before, DB::table('whatsapp_messages')->count());
    }

    /** والسجلُّ يقول «أعدّ» لا «أرسل» — لأنّ الإرسال بيدِ التاجر */
    public function test_the_activity_log_says_prepared_not_sent(): void
    {
        $this->notify($this->order(OrderStatus::READY));

        $line = (string) DB::table('activity_logs')->latest('id')->value('description');

        $this->assertStringContainsString('أعدّ إبلاغ الزبون', $line);
        $this->assertStringNotContainsString('أرسل إلى الزبون', $line);
    }

    /* ------------------------- الختمُ وحدُّه ------------------------- */

    /** ويُختَم الحدثُ مع وقته — فيُعرف لأيّ حالةٍ أُعِدّ الإشعار */
    public function test_the_stamp_records_which_event_was_prepared(): void
    {
        $order = $this->order(OrderStatus::READY);

        $this->notify($order);

        $fresh = $order->fresh();
        $this->assertNotNull($fresh->status_notice_at);
        $this->assertSame(WhatsAppEvent::ORDER_READY, $fresh->status_notice_event);
    }

    /**
     * وختمُ حالةٍ ماضيةٍ لا يقول شيئًا عن الحالة الراهنة.
     *
     * طلبٌ أُبلغ زبونُه بـ«جاهز» ثمّ صار «في الطريق» لم يُبلَّغ بحالته هذه —
     * ولو بقي ختمُ الأمس في صفّه. وبعمودٍ واحدٍ للوقت كانت الشاشة تقول
     * «أُبلغ» عن خبرٍ لم يصل.
     */
    public function test_a_stamp_from_an_earlier_status_does_not_speak_for_the_current_one(): void
    {
        $order = $this->order(OrderStatus::READY);
        $this->notify($order);

        $order->forceFill(['status' => OrderStatus::OUT_FOR_DELIVERY])->save();

        $this->get(route('admin.orders.show', $order->number))
            ->assertInertia(fn ($p) => $p
                ->where('statusNotice.event', WhatsAppEvent::ORDER_OUT_FOR_DELIVERY)
                ->where('statusNotice.preparedAt', null));
    }

    /** وختمُ الحالة نفسِها يُقرأ — فيُقال «أبلغ مجددًا» لا «أبلغ» */
    public function test_a_stamp_for_the_current_status_is_read(): void
    {
        $order = $this->order(OrderStatus::READY);
        $this->notify($order);

        $this->get(route('admin.orders.show', $order->number))
            ->assertInertia(fn ($p) => $p->whereNot('statusNotice.preparedAt', null));
    }

    /* ----------------------- الأبوابُ المغلقة ----------------------- */

    /** حالةٌ لا خبرَ فيها لا زرَّ لها — «قيد التجهيز» ليست بشرى */
    public function test_a_status_with_no_news_offers_no_button(): void
    {
        $order = $this->order(OrderStatus::PREPARING);

        $this->get(route('admin.orders.show', $order->number))
            ->assertInertia(fn ($p) => $p->where('statusNotice.event', null)
                ->where('statusNotice.show', false));
    }

    /** ومناداةُ المسار عليها تُردّ — الشاشةُ تُخفي، والخادمُ يمنع */
    public function test_calling_the_route_on_such_a_status_is_refused(): void
    {
        $order = $this->order(OrderStatus::PREPARING);

        $this->notify($order)->assertRedirect();

        $this->assertSame('danger', session('toast')['type']);
        $this->assertNull($order->fresh()->status_notice_at);
    }

    /**
     * وإطفاءُ التاجر يُحترَم.
     *
     * `wa_on_delivered` مُطفأٌ افتراضًا. ومقبضٌ يخالف إعدادًا كتبه صاحبُه
     * يجعل الإعدادَ كذبًا.
     */
    public function test_an_event_the_merchant_switched_off_is_not_offered(): void
    {
        $order = $this->order(OrderStatus::DELIVERED);

        $this->get(route('admin.orders.show', $order->number))
            ->assertInertia(fn ($p) => $p->where('statusNotice.show', false)
                ->where('statusNotice.event', WhatsAppEvent::ORDER_DELIVERED));

        $this->notify($order)->assertRedirect();
        $this->assertSame('danger', session('toast')['type']);
        $this->assertNull($order->fresh()->status_notice_at);
    }

    /** وإن فعّله عمل — فالمنعُ من إعداده لا من عطبٍ فينا */
    public function test_switching_the_event_on_opens_the_door(): void
    {
        $this->enable('wa_on_delivered', '1');
        $order = $this->order(OrderStatus::DELIVERED);

        $this->notify($order)->assertRedirect();

        $this->assertSame('success', session('toast')['type']);
        $this->assertNotNull($order->fresh()->status_notice_at);
    }

    /** ولا رقمَ لا رسالة — ويُقال بنصٍّ يُقرأ، لا يُصمت */
    public function test_an_order_without_a_number_says_so(): void
    {
        $order = $this->order(OrderStatus::READY, [
            'customer_id' => null, 'recipient_phone' => null,
        ]);

        $this->get(route('admin.orders.show', $order->number))
            ->assertInertia(fn ($p) => $p->where('statusNotice.show', false)
                ->where('statusNotice.reason', 'لا رقم واتساب لهذا الطلب'));

        $this->notify($order)->assertRedirect();
        $this->assertSame('danger', session('toast')['type']);
    }

    /** والسببُ المعطَّلُ نصٌّ يُعرض لا حقلٌ يُهمَل */
    public function test_the_disabled_button_carries_its_reason(): void
    {
        $order = $this->order(OrderStatus::DELIVERED);

        $this->get(route('admin.orders.show', $order->number))
            ->assertInertia(fn ($p) => $p->whereNot('statusNotice.reason', null));
    }

    /* --------------------------- العزل --------------------------- */

    /** وطلبُ متجرٍ آخرَ بالرقم نفسِه لا يُبلَّغ عنه */
    public function test_another_shops_order_is_not_reachable(): void
    {
        $other = Business::create(['name' => 'ورد صلالة', 'type' => 'محل ورود', 'status' => 'نشط']);
        $branch = Branch::create(['business_id' => $other->id, 'name' => 'الرئيسي']);
        $theirs = Order::create([
            'business_id' => $other->id, 'branch_id' => $branch->id,
            'number' => 'INV-000777', 'status' => OrderStatus::READY, 'is_held' => false,
            'payment_method' => 'نقدي', 'payment_status' => 'مدفوع',
            'subtotal' => 10, 'total' => 10, 'ordered_at' => now(), 'recipient_phone' => '99887766',
        ]);

        $this->post(route('admin.orders.statusNotice', $theirs->number))->assertNotFound();

        $this->assertNull($theirs->fresh()->status_notice_at);
    }

    /* ------------------------ عطبٌ كان قائمًا ------------------------ */

    /**
     * وختمُ طلب التقييم يعود وقتًا لا نصًّا.
     *
     * كان بلا `cast`، و`optional('2026-09-11 …')->toIso8601String()` تُرجع
     * `null` بلا خطأ — فبقي «طلب التقييم مجددًا» لا يظهر أبدًا، ولا شيء
     * يقول لماذا.
     */
    public function test_the_review_stamp_comes_back_as_a_time_not_a_string(): void
    {
        $order = $this->order(OrderStatus::READY);
        $order->forceFill(['review_request_sent_at' => now()])->save();

        $this->assertNotNull(optional($order->fresh()->review_request_sent_at)->toIso8601String());
    }
}
