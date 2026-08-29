<?php

namespace Tests\Feature;

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
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * رقم المحلّ الخاص — ميزةٌ تُمنح، ولا تُفتح لمن لم تُمنح له.
 *
 * وأهمّ ما يُحرَس هنا ثلاثة: أنّ الميزة لا تُنال بالمحاولة، وأنّ من يُرسل من
 * رقمه لا يُخصم من حصّة المشترك، وأنّ وصلةً منقطعة **لا** تتحوّل بصمت إلى
 * رقم أبعاد — فالتاجر يظنّ رسائله تخرج من رقمه، والزبون يقرأ رقمًا غريبًا.
 */
class WhatsAppOwnNumberTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        Setting::updateOrCreate(['business_id' => null, 'key' => 'whatsapp_enabled'], ['value' => '1']);
        Setting::updateOrCreate(['business_id' => null, 'key' => WhatsAppQuota::DEFAULT_KEY], ['value' => '100']);

        WhatsAppConnection::create([
            'owner_type' => WhatsAppMode::OWNER_PLATFORM,
            'phone_number_id' => 'ABAAD-PN',
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

        $this->actingAs($this->owner);
    }

    private function order(): Order
    {
        return Order::create([
            'business_id' => $this->business->id,
            'branch_id' => Branch::where('business_id', $this->business->id)->value('id'),
            'customer_id' => $this->customer->id,
            'number' => 'INV-'.uniqid(), 'status' => OrderStatus::PENDING, 'is_held' => false,
            'payment_method' => 'نقدي', 'payment_status' => 'مدفوع',
            'subtotal' => 25, 'total' => 25, 'ordered_at' => now(),
        ]);
    }

    private function grant(): void
    {
        $this->business->update(['whatsapp_own_allowed' => true]);
    }

    private function connectOwn(string $phoneNumberId = 'SHOP-PN'): WhatsAppConnection
    {
        WhatsAppTemplates::seedBusinessDefaults($this->business->id);

        return WhatsAppConnection::create([
            'owner_type' => WhatsAppMode::OWNER_BUSINESS,
            'business_id' => $this->business->id,
            'phone_number_id' => $phoneNumberId,
            'display_phone_number' => '+96892222222',
            'access_token' => 'shop-token-value-9876543210',
            'status' => WhatsAppConnection::ACTIVE,
            'connected_at' => now(),
        ]);
    }

    private function fakeMeta(): void
    {
        Http::fake(['*/messages' => Http::response(['messages' => [['id' => 'wamid.OWN']]], 200)]);
    }

    /* ------------------------------- الإذن ------------------------------- */

    public function test_a_business_cannot_connect_its_own_number_without_the_entitlement(): void
    {
        $this->post(route('admin.marketing.whatsapp.connect'), [
            'phone_number_id' => 'SHOP-PN',
            'access_token' => 'shop-token-value-9876543210',
        ])->assertSessionHasErrors('access_token');

        $this->assertSame(0, WhatsAppConnection::where('owner_type', WhatsAppMode::OWNER_BUSINESS)->count());
    }

    public function test_a_business_cannot_switch_to_own_mode_without_the_entitlement(): void
    {
        $this->post(route('admin.marketing.whatsapp.mode'), ['mode' => WhatsAppMode::BUSINESS_OWN])
            ->assertSessionHasErrors('mode');

        $this->assertSame(WhatsAppMode::ABAAD_SHARED, $this->business->fresh()->whatsapp_mode);
    }

    /** والإذن وحده لا يكفي: وضعٌ بلا وصلةٍ صالحة وضعٌ مكسور */
    public function test_own_mode_is_refused_while_there_is_no_usable_connection(): void
    {
        $this->grant();

        $this->post(route('admin.marketing.whatsapp.mode'), ['mode' => WhatsAppMode::BUSINESS_OWN])
            ->assertSessionHasErrors('mode');

        $this->assertSame(WhatsAppMode::ABAAD_SHARED, $this->business->fresh()->whatsapp_mode);
    }

    public function test_the_entitlement_allows_connecting_and_switching(): void
    {
        $this->grant();

        $this->post(route('admin.marketing.whatsapp.connect'), [
            'phone_number_id' => 'SHOP-PN',
            'display_phone_number' => '+96892222222',
            'access_token' => 'shop-token-value-9876543210',
        ])->assertRedirect();

        $this->post(route('admin.marketing.whatsapp.mode'), ['mode' => WhatsAppMode::BUSINESS_OWN])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(WhatsAppMode::BUSINESS_OWN, $this->business->fresh()->whatsapp_mode);
    }

    /* ------------------------------ الإرسال ------------------------------ */

    /**
     * يُرسل من رقمه — ولا يُخصم من حصّة المشترك.
     *
     * وهذا شرط النموذج التجاريّ كلّه: من دفع ثمن الميزة يُرسل على حسابه هو،
     * فلا حدَّ عليه منّا ولا تكلفةَ علينا.
     */
    public function test_own_mode_sends_from_the_shop_number_and_spends_no_shared_quota(): void
    {
        $this->grant();
        $own = $this->connectOwn();
        $this->business->update(['whatsapp_mode' => WhatsAppMode::BUSINESS_OWN]);
        $this->fakeMeta();

        $this->order()->update(['status' => OrderStatus::READY]);

        $message = WhatsAppMessage::firstOrFail();
        $this->assertSame(WhatsAppMode::BUSINESS_OWN, $message->source_mode);
        $this->assertSame($own->id, $message->whatsapp_connection_id);
        $this->assertSame(WhatsAppStatus::SENT, $message->status);
        $this->assertFalse($message->quota_consumed);
        $this->assertSame(0, WhatsAppQuota::used($this->business->fresh()));

        // والنداء ذهب إلى رقم المحلّ لا إلى رقم أبعاد
        Http::assertSent(fn ($request) => str_contains($request->url(), '/SHOP-PN/messages'));
    }

    /** وحدٌّ نفد على المشترك لا يمنعه: حصّتنا ليست حصّته */
    public function test_own_mode_ignores_an_exhausted_shared_quota(): void
    {
        $this->grant();
        $this->connectOwn();
        $this->business->update([
            'whatsapp_mode' => WhatsAppMode::BUSINESS_OWN,
            'whatsapp_monthly_limit' => 0,
        ]);
        $this->fakeMeta();

        $this->order()->update(['status' => OrderStatus::READY]);

        $this->assertSame(WhatsAppStatus::SENT, WhatsAppMessage::firstOrFail()->status);
    }

    /* ----------------------------- لا احتياط ----------------------------- */

    /**
     * وصلةٌ منقطعة تقف — ولا تُدفع إلى رقم أبعاد.
     *
     * الرجوع التلقائيّ يبدو لطفًا وهو غشّ: التاجر يظنّ رسائله تخرج من رقمه،
     * والزبون يقرأ رقمًا غريبًا فلا يثق، والحصّة المشتركة تُستهلك بلا علم
     * أحد. فتُمتنع الرسالة ويُقيَّد سببها.
     */
    public function test_a_broken_own_connection_never_falls_back_to_abaad(): void
    {
        $this->grant();
        $own = $this->connectOwn();
        $this->business->update(['whatsapp_mode' => WhatsAppMode::BUSINESS_OWN]);
        $own->update(['status' => WhatsAppConnection::REVOKED]);
        $this->fakeMeta();

        $this->order()->update(['status' => OrderStatus::READY]);

        $message = WhatsAppMessage::firstOrFail();
        $this->assertSame(WhatsAppStatus::SKIPPED, $message->status);
        $this->assertSame(WhatsAppStatus::SKIP_NO_CONNECTION, $message->error_code);
        $this->assertSame(0, WhatsAppQuota::used($this->business->fresh()));
        Http::assertNothingSent();
    }

    /** ورمزٌ انتهت مدّته وصلةٌ غير صالحة — يُفحص عندنا لا عند ميتا */
    public function test_an_expired_token_is_treated_as_no_connection(): void
    {
        $this->grant();
        $this->connectOwn()->update(['token_expires_at' => now()->subDay()]);
        $this->business->update(['whatsapp_mode' => WhatsAppMode::BUSINESS_OWN]);
        $this->fakeMeta();

        $this->order()->update(['status' => OrderStatus::READY]);

        $this->assertSame(WhatsAppStatus::SKIPPED, WhatsAppMessage::firstOrFail()->status);
        Http::assertNothingSent();
    }

    /* --------------------------- سحب الإذن --------------------------- */

    /**
     * سُحب الإذن: يقف الإرسال، ولا تُحذف الوصلة.
     *
     * الحذف يُفقد التاريخَ صلتَه، ويجعل إعادة المنح ربطًا من الصفر. والوقوف
     * صريحٌ يُقرأ سببُه.
     */
    public function test_revoking_the_entitlement_stops_sending_without_destroying_anything(): void
    {
        $this->grant();
        $this->connectOwn();
        $this->business->update(['whatsapp_mode' => WhatsAppMode::BUSINESS_OWN]);

        $this->business->update(['whatsapp_own_allowed' => false]);
        $this->fakeMeta();

        $this->order()->update(['status' => OrderStatus::READY]);

        Http::assertNothingSent();
        $this->assertSame(0, WhatsAppMessage::count(), 'المنع قبل السجلّ — لا ضجيج في الدفتر');
        // الوصلة باقية، والوضع باقٍ: إعادة المنح تُعيد كلّ شيء
        $this->assertSame(1, WhatsAppConnection::where('business_id', $this->business->id)->count());
        $this->assertSame(WhatsAppMode::BUSINESS_OWN, $this->business->fresh()->whatsapp_mode);
        $this->assertSame(
            WhatsAppStatus::SKIP_OWN_NOT_ALLOWED,
            WhatsAppFeature::blockReason($this->business->fresh()),
        );
    }

    /** ولا يُدفَع إلى الرقم المشترك بصمت حين يُسحب إذنه */
    public function test_revoking_the_entitlement_does_not_silently_use_the_shared_number(): void
    {
        $this->grant();
        $this->connectOwn();
        $this->business->update(['whatsapp_mode' => WhatsAppMode::BUSINESS_OWN]);
        $this->business->update(['whatsapp_own_allowed' => false]);
        $this->fakeMeta();

        $this->order()->update(['status' => OrderStatus::READY]);

        $this->assertSame(0, WhatsAppQuota::used($this->business->fresh()));
    }

    /* ----------------------------- العزل ----------------------------- */

    /** رقمٌ يملكه متجرٌ آخر لا يُنتزع منه */
    public function test_a_number_already_bound_elsewhere_cannot_be_claimed(): void
    {
        $other = Business::create(['name' => 'ورد آخر', 'type' => 'محل ورود', 'status' => 'نشط']);
        WhatsAppConnection::create([
            'owner_type' => WhatsAppMode::OWNER_BUSINESS,
            'business_id' => $other->id,
            'phone_number_id' => 'TAKEN-PN',
            'access_token' => 'other-shop-token-value-000000',
            'status' => WhatsAppConnection::ACTIVE,
        ]);

        $this->grant();

        $this->post(route('admin.marketing.whatsapp.connect'), [
            'phone_number_id' => 'TAKEN-PN',
            'access_token' => 'my-token-value-1234567890',
        ])->assertSessionHasErrors('phone_number_id');

        $this->assertSame(
            $other->id,
            WhatsAppConnection::where('phone_number_id', 'TAKEN-PN')->value('business_id'),
        );
    }

    /**
     * ومعرّف المتجر يُقرأ من الجلسة لا ممّا يصل في الطلب.
     *
     * ولو قُبل من الطلب لَربط تاجرٌ رقمًا لمتجر غيره بسطرٍ واحد في أدوات
     * المتصفّح.
     */
    public function test_a_forged_business_id_in_the_request_is_ignored(): void
    {
        $other = Business::create(['name' => 'ورد آخر', 'type' => 'محل ورود', 'status' => 'نشط']);
        $this->grant();

        $this->post(route('admin.marketing.whatsapp.connect'), [
            'business_id' => $other->id,
            'phone_number_id' => 'SHOP-PN',
            'access_token' => 'shop-token-value-9876543210',
        ])->assertRedirect();

        $this->assertSame(
            $this->business->id,
            WhatsAppConnection::where('phone_number_id', 'SHOP-PN')->value('business_id'),
        );
        $this->assertSame(0, WhatsAppConnection::where('business_id', $other->id)->count());
    }

    /** والفصل يُعطّل ويعيد الوضع إلى المشترك صراحةً — لا يترك وضعًا لا يُرسل منه شيء */
    public function test_disconnecting_returns_the_shop_to_the_shared_number(): void
    {
        $this->grant();
        $this->connectOwn();
        $this->business->update(['whatsapp_mode' => WhatsAppMode::BUSINESS_OWN]);

        $this->delete(route('admin.marketing.whatsapp.disconnect'))->assertRedirect();

        $this->assertSame(WhatsAppMode::ABAAD_SHARED, $this->business->fresh()->whatsapp_mode);
        $this->assertSame(
            WhatsAppConnection::INACTIVE,
            WhatsAppConnection::where('business_id', $this->business->id)->value('status'),
        );
    }
}
