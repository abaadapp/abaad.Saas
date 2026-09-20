<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\Order;
use App\Models\Setting;
use App\Models\User;
use App\Models\WhatsAppConnection;
use App\Models\WhatsAppMessage;
use App\Models\WhatsAppTemplateMapping;
use App\Support\OrderStatus;
use App\Support\WhatsAppAutomation;
use App\Support\WhatsAppEvent;
use App\Support\WhatsAppMode;
use App\Support\WhatsAppQuota;
use App\Support\WhatsAppStatus;
use App\Support\WhatsAppTemplates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * الزبونُ يُراسَل بلغته — إن اعتمدت ميتا القالبَ بها، وإلّا بلغة القالب.
 *
 * ═══ ولمَ ليس «بلغته» بلا شرط ═══
 *
 * القالبُ عند ميتا صفٌّ لكلّ لغة، وكلٌّ يُعتمد وحدَه. فطلبُ `en` لقالبٍ
 * اعتُمد بالعربيّة وحدَها يُردّ بـ`132001` وتُقيَّد الرسالةُ فاشلةً على زبونٍ
 * كان يقبل العربيّة. فلغتُه تُختار حين تكون بين ما اعتُمد، ويُرجَع إلى
 * الأصل سواها — والشاشةُ لا تعد بما لم يُعتمد.
 *
 * ═══ وقبلها: المزامنةُ كانت تطوي اللغاتِ في صفٍّ واحد ═══
 *
 * `MetaWhatsAppClient::templates` كانت تكتب `اسم ← حال` فيبقى آخرُ صفٍّ:
 * نسخةٌ إنجليزيّة أُضيفت أمس وهي PENDING كانت تمحو APPROVED العربيّة —
 * فتُقرأ العربيّة غيرَ معتمَدة وتُتخطّى رسائلُ كلّ المتاجر يومَ يُضاف
 * أوّلُ قالبٍ إنجليزيّ. وهو عينُ ما سيقع الآن، فيُحرَس أوّلًا.
 */
class ACustomerIsMessagedInHisOwnLanguageTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private Branch $branch;

    private WhatsAppConnection $line;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');
        Http::preventStrayRequests();

        $this->shop = Business::create([
            'name' => 'محل الورد', 'type' => 'محل ورود', 'status' => 'نشط', 'whatsapp_enabled' => true,
        ]);
        $this->branch = Branch::create(['business_id' => $this->shop->id, 'name' => 'الرئيسي']);
        Currency::create(['business_id' => $this->shop->id, 'code' => 'OMR', 'name' => 'ريال',
            'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);

        Setting::updateOrCreate(['business_id' => null, 'key' => 'whatsapp_enabled'], ['value' => '1']);
        Setting::updateOrCreate(['business_id' => null, 'key' => WhatsAppQuota::DEFAULT_KEY], ['value' => '100']);
        Setting::updateOrCreate(['business_id' => $this->shop->id, 'key' => 'wa_on_ready'], ['value' => '1']);

        $this->line = WhatsAppConnection::create([
            'owner_type' => WhatsAppMode::OWNER_PLATFORM,
            'purpose' => WhatsAppMode::PURPOSE_NOTIFICATIONS,
            'phone_number_id' => 'PN', 'waba_id' => 'WABA-1',
            'display_phone_number' => '+968 7114 1624',
            'access_token' => 'tok-0123456789abcdef',
            'status' => WhatsAppConnection::ACTIVE, 'connected_at' => now(),
        ]);

        WhatsAppTemplates::seedPlatformDefaults('ar');
    }

    /** ميتا تردّ الاسمَ صفًّا لكلّ لغة — كما تفعل فعلًا */
    private function metaLists(array $rows): void
    {
        Http::fake(['*message_templates*' => Http::response(['data' => $rows], 200)]);
        WhatsAppTemplates::sync($this->line, WhatsAppMode::OWNER_PLATFORM);
    }

    private function readyMapping(): WhatsAppTemplateMapping
    {
        return WhatsAppTemplateMapping::query()->platform()
            ->where('event_type', WhatsAppEvent::ORDER_READY)->firstOrFail();
    }

    private function fireReady(?string $language): WhatsAppMessage
    {
        $customer = Customer::create([
            'business_id' => $this->shop->id, 'name' => 'زبون', 'phone' => '9988'.random_int(1000, 9999),
            'language' => $language,
        ]);
        $order = Order::create([
            'business_id' => $this->shop->id, 'branch_id' => $this->branch->id,
            'customer_id' => $customer->id, 'number' => 'ORD-'.$customer->id,
            'status' => OrderStatus::READY, 'total' => 10, 'subtotal' => 10,
        ]);

        WhatsAppAutomation::handle($order->fresh(), OrderStatus::READY);

        return WhatsAppMessage::where('order_id', $order->id)->latest('id')->firstOrFail();
    }

    /* ═════════════ المزامنةُ لا تطوي اللغات ═════════════ */

    public function test_a_pending_english_version_does_not_unapprove_the_arabic_one(): void
    {
        $this->metaLists([
            ['name' => 'abaad_order_ready', 'language' => 'ar', 'status' => 'APPROVED'],
            ['name' => 'abaad_order_ready', 'language' => 'en', 'status' => 'PENDING'],
        ]);

        $mapping = $this->readyMapping();
        $this->assertSame('APPROVED', $mapping->meta_status, 'النسخةُ الإنجليزيّةُ المعلَّقة محت اعتمادَ العربيّة');
        $this->assertSame(['ar'], $mapping->approved_languages);

        $message = $this->fireReady(null);
        $this->assertNotSame(WhatsAppStatus::SKIPPED, $message->status, 'تُخطّيت رسالةٌ عربيّةٌ لقالبٍ معتمَد');
        $this->assertSame('ar', $message->language_code);
    }

    public function test_the_sync_records_every_language_meta_approved(): void
    {
        $this->metaLists([
            ['name' => 'abaad_order_ready', 'language' => 'en', 'status' => 'APPROVED'],
            ['name' => 'abaad_order_ready', 'language' => 'ar', 'status' => 'APPROVED'],
        ]);

        $this->assertEqualsCanonicalizing(['ar', 'en'], $this->readyMapping()->approved_languages);
    }

    /* ═════════════ واللغةُ تُختار للزبون ═════════════ */

    public function test_an_english_customer_gets_the_english_version_once_approved(): void
    {
        $this->metaLists([
            ['name' => 'abaad_order_ready', 'language' => 'ar', 'status' => 'APPROVED'],
            ['name' => 'abaad_order_ready', 'language' => 'en', 'status' => 'APPROVED'],
        ]);

        $this->assertSame('en', $this->fireReady('en')->language_code);
        $this->assertSame('ar', $this->fireReady('ar')->language_code);
        $this->assertSame('ar', $this->fireReady(null)->language_code, 'بلا لغةٍ يُرجَع إلى لغة القالب');
    }

    public function test_an_english_customer_falls_back_to_arabic_until_english_is_approved(): void
    {
        $this->metaLists([
            ['name' => 'abaad_order_ready', 'language' => 'ar', 'status' => 'APPROVED'],
            ['name' => 'abaad_order_ready', 'language' => 'en', 'status' => 'PENDING'],
        ]);

        $message = $this->fireReady('en');
        $this->assertSame('ar', $message->language_code, 'طُلبت لغةٌ لم تعتمدها ميتا — سيردّها');
        $this->assertNotSame(WhatsAppStatus::SKIPPED, $message->status);
    }

    /** وتذكيرُ الفاتورة يسلك الطريقَ نفسَه */
    public function test_an_invoice_reminder_follows_the_customers_language_too(): void
    {
        Setting::updateOrCreate(['business_id' => $this->shop->id, 'key' => WhatsAppEvent::SETTING_KEYS[WhatsAppEvent::INVOICE_DUE_SOON]], ['value' => '1']);
        $this->metaLists([
            ['name' => 'abaad_invoice_due_soon', 'language' => 'ar', 'status' => 'APPROVED'],
            ['name' => 'abaad_invoice_due_soon', 'language' => 'en', 'status' => 'APPROVED'],
        ]);

        $customer = Customer::create(['business_id' => $this->shop->id, 'name' => 'Smith', 'phone' => '99001122', 'language' => 'en']);
        $invoice = CustomerInvoice::create([
            'business_id' => $this->shop->id, 'customer_id' => $customer->id, 'number' => 'CINV-1',
            'status' => CustomerInvoice::ISSUED, 'issued_at' => now()->toDateString(), 'due_at' => now()->addDays(2)->toDateString(),
            'subtotal' => 10, 'discount_total' => 0, 'tax_total' => 0, 'total' => 10, 'customer_name' => 'Smith',
        ]);

        WhatsAppAutomation::handleInvoice($invoice, WhatsAppEvent::INVOICE_DUE_SOON);

        $message = WhatsAppMessage::where('customer_invoice_id', $invoice->id)->firstOrFail();
        $this->assertSame('en', $message->language_code);
    }

    /* ═════════════ والشاشةُ تكتبها ═════════════ */

    public function test_the_owner_saves_a_customers_language_from_the_form(): void
    {
        $owner = User::create([
            'business_id' => $this->shop->id, 'name' => 'المالك', 'email' => 'o@abaad.om',
            'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->actingAs($owner)->post(route('admin.customers.store'), [
            'name' => 'John', 'phone' => '99112233', 'language' => 'en',
        ])->assertSessionHasNoErrors();

        $john = Customer::where('business_id', $this->shop->id)->where('phone', '99112233')->firstOrFail();
        $this->assertSame('en', $john->language);

        $this->actingAs($owner)->put(route('admin.customers.update', $john->id), [
            'name' => 'John', 'phone' => '99112233', 'language' => '',
        ])->assertSessionHasNoErrors();
        $this->assertNull($john->fresh()->language, 'الفراغُ يعني «لغة المتجر» لا يُردّ');

        $this->actingAs($owner)->put(route('admin.customers.update', $john->id), [
            'name' => 'John', 'phone' => '99112233', 'language' => 'fr',
        ])->assertSessionHasErrors('language');
    }

    /** وشاشةُ الطلب تُعلِم الكاشيرَ بلغة الزبون قبل أن يغيّر الحالة */
    public function test_the_order_screen_tells_the_cashier_which_language_the_customer_gets(): void
    {
        $owner = User::create([
            'business_id' => $this->shop->id, 'name' => 'المالك', 'email' => 'o@abaad.om',
            'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        $english = Customer::create(['business_id' => $this->shop->id, 'name' => 'John', 'phone' => '99110001', 'language' => 'en']);
        $unset = Customer::create(['business_id' => $this->shop->id, 'name' => 'سالم', 'phone' => '99110002']);

        foreach ([[$english, 'en'], [$unset, null]] as [$customer, $expected]) {
            $order = Order::create([
                'business_id' => $this->shop->id, 'branch_id' => $this->branch->id,
                'customer_id' => $customer->id, 'number' => 'ORD-L-'.$customer->id,
                'status' => OrderStatus::PREPARING, 'total' => 10, 'subtotal' => 10,
            ]);

            $this->actingAs($owner)->get(route('admin.orders.show', $order->number))
                ->assertInertia(fn ($p) => $p->where('order.customer_language', $expected));
        }
    }
}
