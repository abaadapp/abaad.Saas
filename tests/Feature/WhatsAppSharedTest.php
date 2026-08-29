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
use App\Support\OrderStatus;
use App\Support\WhatsAppEvent;
use App\Support\WhatsAppFeature;
use App\Support\WhatsAppMode;
use App\Support\WhatsAppQuota;
use App\Support\WhatsAppStatus;
use App\Support\WhatsAppTemplates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * الرقم المشترك: رقمٌ واحد لأبعاد، وحصّةٌ مستقلّة لكلّ محلّ.
 *
 * وأخطر ما يُحرَس هنا شيئان: أنّ حصّة متجرٍ لا تُخصم من متجرٍ آخر وإن خرجت
 * الرسالتان من الرقم نفسه، وأنّ اسم محلّ الورد هو ما يقرؤه الزبون لا اسم
 * أبعاد — الرقم رقمنا، والطلب طلبُه.
 */
class WhatsAppSharedTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->platform('whatsapp_enabled', '1');
        $this->platform(WhatsAppQuota::DEFAULT_KEY, '100');
        $this->sharedConnection();
        WhatsAppTemplates::seedPlatformDefaults('ar');

        [$this->business, $this->owner, $this->customer] = $this->shop('محل ورد', 'o@abaad.om', '91234567');
        $this->actingAs($this->owner);
    }

    /* ------------------------------- التهيئة ------------------------------- */

    private function platform(string $key, string $value): void
    {
        Setting::updateOrCreate(['business_id' => null, 'key' => $key], ['value' => $value]);
    }

    private function sharedConnection(): WhatsAppConnection
    {
        return WhatsAppConnection::create([
            'owner_type' => WhatsAppMode::OWNER_PLATFORM,
            'phone_number_id' => 'ABAAD-PN',
            'display_phone_number' => '+96890000000',
            'access_token' => 'platform-token-value-0123456789',
            'status' => WhatsAppConnection::ACTIVE,
            'connected_at' => now(),
        ]);
    }

    /** @return array{0: Business, 1: User, 2: Customer} */
    private function shop(string $name, string $email, string $phone): array
    {
        $business = Business::create(['name' => $name, 'type' => 'محل ورود', 'status' => 'نشط']);
        Branch::create(['business_id' => $business->id, 'name' => 'الرئيسي']);

        $user = User::create([
            'business_id' => $business->id, 'name' => 'المالك', 'email' => $email,
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $customer = Customer::create([
            'business_id' => $business->id, 'name' => 'زبون', 'phone' => $phone,
        ]);

        // المفاتيح الأربعة مفتوحة — الافتراضيّ يُطفئ «تم التسليم»
        foreach (WhatsAppEvent::SETTING_KEYS as $key) {
            Setting::updateOrCreate(['business_id' => $business->id, 'key' => $key], ['value' => '1']);
        }

        return [$business, $user, $customer];
    }

    private function order(?Business $business = null, ?Customer $customer = null, array $extra = []): Order
    {
        $business ??= $this->business;
        $customer ??= $this->customer;

        return Order::create(array_merge([
            'business_id' => $business->id,
            'branch_id' => Branch::where('business_id', $business->id)->value('id'),
            'customer_id' => $customer->id,
            'number' => 'INV-'.uniqid(),
            'status' => OrderStatus::PENDING,
            'is_held' => false,
            'payment_method' => 'نقدي', 'payment_status' => 'مدفوع',
            'subtotal' => 25, 'total' => 25, 'ordered_at' => now(),
        ], $extra));
    }

    private function fakeMeta(string $id = 'wamid.TEST'): void
    {
        Http::fake([
            '*/messages' => Http::response(['messages' => [['id' => $id]]], 200),
        ]);
    }

    /* ----------------------------- الأحداث الأربعة ----------------------------- */

    /**
     * الحالات الأربع تُرسل، و«قيد التجهيز» لا تُرسل.
     *
     * والتحقّق على ما كُتب في السجلّ لا على ما نُودي به: السجلّ هو ما يُقرأ
     * حين يسأل التاجر لماذا لم تصل رسالة.
     */
    public function test_the_four_events_each_produce_one_message(): void
    {
        Bus::fake();
        $order = $this->order();

        foreach ([OrderStatus::CONFIRMED, OrderStatus::PREPARING, OrderStatus::READY,
            OrderStatus::OUT_FOR_DELIVERY, OrderStatus::DELIVERED] as $status) {
            $order->update(['status' => $status]);
        }

        $events = WhatsAppMessage::where('business_id', $this->business->id)
            ->orderBy('id')->pluck('event_type')->all();

        $this->assertSame([
            WhatsAppEvent::ORDER_CONFIRMED,
            WhatsAppEvent::ORDER_READY,
            WhatsAppEvent::ORDER_OUT_FOR_DELIVERY,
            WhatsAppEvent::ORDER_DELIVERED,
        ], $events, '«قيد التجهيز» لا تُرسل — والأربع الباقية تُرسل مرّةً لكلٍّ');
    }

    /** والإرسال في الطابور لا في الطلب: البيع لا ينتظر ميتا */
    public function test_sending_goes_to_the_queue_not_the_request(): void
    {
        Bus::fake();

        $order = $this->order();
        $order->update(['status' => OrderStatus::READY]);

        Bus::assertDispatched(SendWhatsAppMessage::class);
        Http::assertNothingSent();
    }

    /* ------------------------------ اسم المحلّ ------------------------------ */

    /**
     * الرسالة تحمل اسم محلّ الورد لا اسم أبعاد.
     *
     * الرقم رقم أبعاد، فإن لم يقل النصّ اسم المحلّ قرأها الزبون رسالةً من
     * جهةٍ لا يعرفها عن طلبٍ لا يذكره — فلا يثق بها، أو يردّ على رقمٍ لا
     * يقرأ ردَّه أحد.
     */
    public function test_the_message_carries_the_flower_shop_name_not_abaad(): void
    {
        $this->fakeMeta();

        $order = $this->order();
        $order->update(['status' => OrderStatus::READY]);

        $sent = null;
        Http::assertSent(function ($request) use (&$sent) {
            $sent = $request->data();

            return true;
        });

        $params = $sent['template']['components'][0]['parameters'];
        $this->assertSame('محل ورد', $params[0]['text']);
        $this->assertSame($order->number, $params[1]['text']);
    }

    /* ----------------------------- عزل المستأجرين ----------------------------- */

    /**
     * متجران على الرقم نفسه — ولكلٍّ حصّتُه وسجلُّه.
     *
     * وهذا لبّ الوضع المشترك: لو خُصمت رسالة متجرٍ من عدّاد آخر لَنفدت حصّةُ
     * من لم يُرسل شيئًا، وهو خطأٌ لا يُكتشف إلا بشكوى.
     */
    public function test_two_shops_share_the_number_but_not_the_quota(): void
    {
        $this->fakeMeta();

        [$other, , $otherCustomer] = $this->shop('ورد آخر', 'x@abaad.om', '99887766');

        $this->order()->update(['status' => OrderStatus::READY]);
        $this->order($other, $otherCustomer)->update(['status' => OrderStatus::READY]);

        $this->assertSame(1, WhatsAppQuota::used($this->business->fresh()));
        $this->assertSame(1, WhatsAppQuota::used($other->fresh()));

        // ولكلٍّ سجلُّه: لا صفَّ لمتجرٍ تحت معرّف متجرٍ آخر
        $this->assertSame(1, WhatsAppMessage::where('business_id', $this->business->id)->count());
        $this->assertSame(1, WhatsAppMessage::where('business_id', $other->id)->count());

        // والوصلة واحدة — نسخةُ رمزٍ لكلّ متجرٍ هي ما تجنّبناه
        $this->assertSame(1, WhatsAppConnection::query()->platform()->count());
    }

    /* -------------------------------- الحصّة -------------------------------- */

    public function test_the_platform_default_limit_applies_without_an_override(): void
    {
        $this->platform(WhatsAppQuota::DEFAULT_KEY, '7');

        $this->assertNull($this->business->whatsapp_monthly_limit);
        $this->assertSame(7, WhatsAppQuota::effectiveLimit($this->business->fresh()));
    }

    public function test_a_business_override_beats_the_default(): void
    {
        $this->platform(WhatsAppQuota::DEFAULT_KEY, '100');
        $this->business->update(['whatsapp_monthly_limit' => 5]);

        $this->assertSame(5, WhatsAppQuota::effectiveLimit($this->business->fresh()));
    }

    /** و`null` تعني «خذ الافتراضيّ» — لا «بلا حدّ» */
    public function test_clearing_the_override_returns_to_the_default(): void
    {
        $this->platform(WhatsAppQuota::DEFAULT_KEY, '30');
        $this->business->update(['whatsapp_monthly_limit' => 5]);
        $this->business->update(['whatsapp_monthly_limit' => null]);

        $this->assertSame(30, WhatsAppQuota::effectiveLimit($this->business->fresh()));
    }

    public function test_minus_one_means_unlimited(): void
    {
        $this->business->update(['whatsapp_monthly_limit' => WhatsAppQuota::UNLIMITED]);

        $snapshot = WhatsAppQuota::snapshot($this->business->fresh());

        $this->assertTrue($snapshot['unlimited']);
        $this->assertNull($snapshot['remaining']);
        $this->assertFalse($snapshot['is_exhausted']);
    }

    /**
     * نفدت الحصّة: لا تخرج رسالة، ولا يفشل الطلب.
     *
     * وهذا الشرط الأهمّ في الاتّجاه الآخر: نظام إشعاراتٍ يُسقط بيعةً لأنّ
     * حصّة رسائل نفدت هو نظامٌ يُطفَأ في أوّل يومٍ مزدحم.
     */
    public function test_an_exhausted_quota_stops_the_message_and_not_the_order(): void
    {
        $this->fakeMeta();
        $this->business->update(['whatsapp_monthly_limit' => 1]);

        $first = $this->order();
        $first->update(['status' => OrderStatus::READY]);

        $second = $this->order();
        $second->update(['status' => OrderStatus::READY]);

        $this->assertSame(OrderStatus::READY, $second->fresh()->status, 'الطلب لا يتأثّر بامتناع الرسالة');
        $this->assertSame(1, WhatsAppQuota::used($this->business->fresh()));

        $blocked = WhatsAppMessage::where('order_id', $second->id)->firstOrFail();
        $this->assertSame(WhatsAppStatus::QUOTA_EXCEEDED, $blocked->status);
        $this->assertFalse($blocked->quota_consumed);

        // ولا نداء ثانٍ على ميتا — الحصّة تُفحص قبل النداء لا بعده
        Http::assertSentCount(1);
    }

    public function test_a_zero_limit_sends_nothing_at_all(): void
    {
        $this->fakeMeta();
        $this->business->update(['whatsapp_monthly_limit' => 0]);

        $this->order()->update(['status' => OrderStatus::READY]);

        Http::assertNothingSent();
        $this->assertSame(WhatsAppStatus::QUOTA_EXCEEDED, WhatsAppMessage::firstOrFail()->status);
    }

    /**
     * التزاحم: رسالتان في اللحظة نفسها وحدٌّ فيه واحدة.
     *
     * الحجز شرطٌ داخل جملة التحديث، فالمحرّك يُسلسِل ولا يمرّ إلا واحد.
     * ولو كان `count(*)` ثمّ `send` لَنجحت الاثنتان — وهو عطبٌ لا يظهر في
     * تجربةٍ يدوية أبدًا.
     */
    public function test_concurrent_reservations_cannot_pass_the_limit(): void
    {
        $this->business->update(['whatsapp_monthly_limit' => 1]);
        $business = $this->business->fresh();

        $results = [
            WhatsAppQuota::reserve($business),
            WhatsAppQuota::reserve($business),
            WhatsAppQuota::reserve($business),
        ];

        $this->assertSame([true, false, false], $results);
        $this->assertSame(1, WhatsAppQuota::used($business));
    }

    /** وردّ الحجز لا ينزل تحت الصفر */
    public function test_releasing_more_than_reserved_never_goes_negative(): void
    {
        $business = $this->business->fresh();

        WhatsAppQuota::reserve($business);
        WhatsAppQuota::release($business);
        WhatsAppQuota::release($business);

        $this->assertSame(0, WhatsAppQuota::used($business));
    }

    /* ------------------------------ منع التكرار ------------------------------ */

    /**
     * الحالة نفسها مرّتين — رسالةٌ واحدة وحصّةٌ واحدة.
     *
     * الموظّف ينقل الطلب إلى «جاهز» ثمّ يُعيده إلى «قيد التجهيز» ثمّ إلى
     * «جاهز». والزبون لا يهمّه تردّده.
     */
    public function test_the_same_event_twice_sends_once_and_charges_once(): void
    {
        $this->fakeMeta();
        $order = $this->order();

        $order->update(['status' => OrderStatus::READY]);
        $order->update(['status' => OrderStatus::PREPARING]);
        $order->update(['status' => OrderStatus::READY]);

        $this->assertSame(1, WhatsAppMessage::where('order_id', $order->id)
            ->where('event_type', WhatsAppEvent::ORDER_READY)->count());
        $this->assertSame(1, WhatsAppQuota::used($this->business->fresh()));
    }

    /** وإعادة الوظيفة من الطابور لا تُرسل ثانية */
    public function test_a_retried_job_does_not_send_twice(): void
    {
        $this->fakeMeta();
        $this->order()->update(['status' => OrderStatus::READY]);

        $message = WhatsAppMessage::firstOrFail();
        $this->assertSame(WhatsAppStatus::SENT, $message->status);

        (new SendWhatsAppMessage($message->id))->handle();

        Http::assertSentCount(1);
        $this->assertSame(1, WhatsAppQuota::used($this->business->fresh()));
    }

    /* ------------------------------ ما يُمتنع عنه ------------------------------ */

    public function test_a_disabled_event_sends_nothing(): void
    {
        $this->fakeMeta();
        Setting::updateOrCreate(
            ['business_id' => $this->business->id, 'key' => 'wa_on_ready'],
            ['value' => '0'],
        );

        $this->order()->update(['status' => OrderStatus::READY]);

        Http::assertNothingSent();
        $this->assertSame(0, WhatsAppMessage::count());
    }

    public function test_a_customerless_order_is_recorded_as_skipped(): void
    {
        $this->fakeMeta();

        $order = $this->order(extra: ['customer_id' => null]);
        $order->update(['status' => OrderStatus::READY]);

        $message = WhatsAppMessage::firstOrFail();
        $this->assertSame(WhatsAppStatus::SKIPPED, $message->status);
        $this->assertSame(WhatsAppStatus::SKIP_NO_RECIPIENT, $message->error_code);
        $this->assertFalse($message->quota_consumed);
        Http::assertNothingSent();
    }

    public function test_turning_whatsapp_off_globally_stops_everything(): void
    {
        $this->fakeMeta();
        $this->platform('whatsapp_enabled', '0');

        $this->order()->update(['status' => OrderStatus::READY]);

        Http::assertNothingSent();
        $this->assertSame(0, WhatsAppMessage::count());
    }

    public function test_turning_the_shared_number_off_stops_shared_sends(): void
    {
        $this->fakeMeta();
        $this->platform(WhatsAppFeature::SHARED_KEY, '0');

        $this->order()->update(['status' => OrderStatus::READY]);

        Http::assertNothingSent();
    }

    public function test_disabling_one_business_leaves_the_others_working(): void
    {
        $this->fakeMeta();
        [$other, , $otherCustomer] = $this->shop('ورد آخر', 'x@abaad.om', '99887766');

        $this->business->update(['whatsapp_enabled' => false]);

        $this->order()->update(['status' => OrderStatus::READY]);
        $this->order($other, $otherCustomer)->update(['status' => OrderStatus::READY]);

        Http::assertSentCount(1);
        $this->assertSame(0, WhatsAppQuota::used($this->business->fresh()));
        $this->assertSame(1, WhatsAppQuota::used($other->fresh()));
    }

    public function test_no_shared_connection_means_a_skipped_record_not_a_crash(): void
    {
        WhatsAppConnection::query()->platform()->update(['status' => WhatsAppConnection::INACTIVE]);

        $this->order()->update(['status' => OrderStatus::READY]);

        $message = WhatsAppMessage::firstOrFail();
        $this->assertSame(WhatsAppStatus::SKIPPED, $message->status);
        $this->assertSame(WhatsAppStatus::SKIP_NO_CONNECTION, $message->error_code);
    }

    /* ------------------------------ نداء المزوّد ------------------------------ */

    public function test_a_successful_send_stores_the_provider_id(): void
    {
        $this->fakeMeta('wamid.ABC123');

        $this->order()->update(['status' => OrderStatus::READY]);

        $message = WhatsAppMessage::firstOrFail();
        $this->assertSame(WhatsAppStatus::SENT, $message->status);
        $this->assertSame('wamid.ABC123', $message->provider_message_id);
        $this->assertTrue($message->quota_consumed);
        $this->assertNotNull($message->sent_at);
    }

    /** ورقمٌ يرفضه المزوّد لا يُحاسَب عليه التاجر */
    public function test_a_rejected_message_gives_the_quota_back(): void
    {
        Http::fake(['*/messages' => Http::response([
            'error' => ['code' => 131026, 'message' => 'Message undeliverable'],
        ], 400)]);

        $this->order()->update(['status' => OrderStatus::READY]);

        $message = WhatsAppMessage::firstOrFail();
        $this->assertSame(WhatsAppStatus::FAILED, $message->status);
        $this->assertSame('131026', $message->error_code);
        $this->assertFalse($message->quota_consumed);
        $this->assertSame(0, WhatsAppQuota::used($this->business->fresh()), 'ما لم يُقبل لا يُخصم');
    }

    /** ورمزٌ مسحوب لا يُعاد عليه: تكرارُ نداءٍ مرفوضٍ دائمًا يُغرق الطابور */
    public function test_an_auth_error_is_not_retried(): void
    {
        Http::fake(['*/messages' => Http::response([
            'error' => ['code' => 190, 'message' => 'Invalid OAuth access token'],
        ], 401)]);

        $this->order()->update(['status' => OrderStatus::READY]);

        $this->assertSame(WhatsAppStatus::FAILED, WhatsAppMessage::firstOrFail()->status);
        Http::assertSentCount(1);
    }

    /** والانقطاع يُعاد — ويبقى الصفّ «مُدرَجة» حتى تُستنفد المحاولات */
    public function test_a_timeout_leaves_the_message_queued_for_another_try(): void
    {
        Bus::fake();
        $this->order()->update(['status' => OrderStatus::READY]);
        Bus::assertDispatched(SendWhatsAppMessage::class);

        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('timeout'));

        $message = WhatsAppMessage::firstOrFail();
        $job = new SendWhatsAppMessage($message->id);
        $job->handle();

        $fresh = $message->fresh();
        $this->assertSame(WhatsAppStatus::QUEUED, $fresh->status);
        $this->assertSame('network_error', $fresh->error_code);
        $this->assertSame(0, WhatsAppQuota::used($this->business->fresh()), 'الحجز يُردّ حتى في الانتظار');
    }

    /* -------------------------------- الأمان -------------------------------- */

    /** الرمز لا يخرج من النموذج ولو نُودي `toArray` سهوًا */
    public function test_the_access_token_never_leaves_the_model(): void
    {
        $connection = WhatsAppConnection::query()->platform()->firstOrFail();

        $this->assertArrayNotHasKey('access_token', $connection->toArray());
        $this->assertStringNotContainsString('platform-token', json_encode($connection));
    }

    /** ولا يُخزَّن في القاعدة نصًّا مقروءًا */
    public function test_the_token_is_encrypted_at_rest(): void
    {
        $raw = \Illuminate\Support\Facades\DB::table('whatsapp_connections')
            ->where('phone_number_id', 'ABAAD-PN')->value('access_token');

        $this->assertNotSame('platform-token-value-0123456789', $raw);
        $this->assertSame('platform-token-value-0123456789',
            WhatsAppConnection::query()->platform()->firstOrFail()->access_token);
    }

    /** ولا يظهر في سجلّ النشاط */
    public function test_no_secret_reaches_the_activity_log(): void
    {
        $super = User::create([
            'business_id' => null, 'name' => 'مدير المنصة', 'email' => 'root@abaad.om',
            'password' => bcrypt('password'), 'role' => 'super_admin', 'status' => 'نشط',
        ]);

        $this->actingAs($super)->post(route('super-admin.whatsapp.shared.connect'), [
            'phone_number_id' => 'NEW-PN',
            'display_phone_number' => '+96891111111',
            'access_token' => 'super-secret-token-value-123456',
        ])->assertRedirect();

        $logs = \App\Models\ActivityLog::pluck('description')->implode(' | ');
        $this->assertStringNotContainsString('super-secret-token', $logs);
    }
}
