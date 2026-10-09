<?php

namespace Tests\Feature;

use App\Jobs\SendWhatsAppMessage;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\Order;
use App\Models\Setting;
use App\Models\User;
use App\Models\WhatsAppConnection;
use App\Models\WhatsAppMessage;
use App\Models\WhatsAppTemplateMapping;
use App\Support\OrderStatus;
use App\Support\WhatsAppConversations;
use App\Support\WhatsAppEvent;
use App\Support\WhatsAppLog;
use App\Support\WhatsAppMode;
use App\Support\WhatsAppQuota;
use App\Support\WhatsAppStatus;
use App\Support\WhatsAppTemplates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * «محادثات واتساب» — لنشاطٍ فُتحت له، ومن رقمه الحاليّ وحده، وللقراءة.
 *
 * ما يُحرس هنا:
 * - المفتاح: من لم تُفتح له لا يتغيّر عليه شيء، وبابُها الجديد 404 عنده.
 * - النطاق: لا رسائلُ رقم أبعاد، ولا رسائلُ رقمٍ سبق، ولا متجرٍ آخر.
 * - الهويّة: المحادثةُ رقمُ الزبون لا اسمُه.
 * - النصّ: يُكتب وقتَ الإرسال من القالب المعتمَد، ولا يُخترع لما لم يُعرف.
 */
class WhatsAppConversationsTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private Business $other;

    private User $owner;

    private User $otherOwner;

    private Customer $sara;

    private static int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Setting::updateOrCreate(['business_id' => null, 'key' => 'whatsapp_enabled'], ['value' => '1']);
        Setting::updateOrCreate(['business_id' => null, 'key' => WhatsAppQuota::DEFAULT_KEY], ['value' => '100']);

        // رقمُ أبعاد المشترك — موجودٌ ونشط، ولا يفتح المحادثات وحده
        WhatsAppConnection::create([
            'owner_type' => WhatsAppMode::OWNER_PLATFORM, 'phone_number_id' => 'ABAAD-PN',
            'access_token' => 'platform-token-value-0123456789', 'status' => WhatsAppConnection::ACTIVE,
            'connected_at' => now(),
        ]);
        WhatsAppTemplates::seedPlatformDefaults('ar');

        $this->shop = Business::create(['name' => 'محل الورد', 'type' => 'محل ورود', 'status' => 'نشط',
            'whatsapp_conversations_enabled' => true]);
        $this->other = Business::create(['name' => 'محل الجار', 'type' => 'محل ورود', 'status' => 'نشط']);

        foreach ([$this->shop, $this->other] as $b) {
            Branch::create(['business_id' => $b->id, 'name' => 'الرئيسي']);
            foreach (WhatsAppEvent::SETTING_KEYS as $key) {
                Setting::updateOrCreate(['business_id' => $b->id, 'key' => $key], ['value' => '1']);
            }
        }

        $this->owner = User::create(['business_id' => $this->shop->id, 'name' => 'المالك', 'email' => 'o@a.om',
            'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط']);
        $this->otherOwner = User::create(['business_id' => $this->other->id, 'name' => 'الجار', 'email' => 'n@a.om',
            'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط']);

        $this->sara = Customer::create(['business_id' => $this->shop->id, 'name' => 'سارة', 'phone' => '91234567']);
    }

    /* ============================== أدوات ============================== */

    private function connectOwn(Business $b, string $pn = 'SHOP-PN'): WhatsAppConnection
    {
        WhatsAppTemplates::seedBusinessDefaults($b->id);

        return WhatsAppConnection::create([
            'owner_type' => WhatsAppMode::OWNER_BUSINESS, 'business_id' => $b->id,
            'phone_number_id' => $pn, 'display_phone_number' => '+96892222222',
            'waba_id' => 'WABA-'.$b->id, 'access_token' => 'shop-token-value-9876543210',
            'status' => WhatsAppConnection::ACTIVE, 'connected_at' => now(),
        ]);
    }

    /** رسالةٌ مكتوبةٌ في الدفتر — من رقم المحلّ ما لم يُقل غيرُه */
    private function msg(Business $b, ?WhatsAppConnection $c, array $over = []): WhatsAppMessage
    {
        self::$seq++;

        return WhatsAppMessage::create($over + [
            'business_id' => $b->id,
            'whatsapp_connection_id' => $c?->id,
            'sender_phone_number_id' => $c?->phone_number_id,
            'source_mode' => WhatsAppMode::BUSINESS_OWN,
            'event_type' => WhatsAppEvent::ORDER_READY,
            'direction' => 'outbound',
            'recipient_phone' => '96891234567',
            'template_name' => 'order_ready',
            'language_code' => 'ar',
            'dedupe_key' => 'test:'.self::$seq,
            'status' => WhatsAppStatus::SENT,
            'quota_consumed' => false,
        ]);
    }

    private function order(Business $b, ?Customer $customer = null): Order
    {
        return Order::create([
            'business_id' => $b->id, 'branch_id' => Branch::where('business_id', $b->id)->value('id'),
            'customer_id' => $customer?->id, 'number' => 'INV-'.(++self::$seq),
            'status' => OrderStatus::PENDING, 'is_held' => false, 'payment_method' => 'نقدي',
            'payment_status' => 'مدفوع', 'subtotal' => 25, 'total' => 25, 'ordered_at' => now(),
        ]);
    }

    private function page(array $query = [], ?User $as = null): array
    {
        $res = $this->actingAs($as ?? $this->owner)->get(route('admin.marketing.whatsapp.log', $query))->assertOk();

        return [$res->viewData('page')['component'], $res->viewData('page')['props']];
    }

    /* ======================= A · المفتاح ======================= */

    public function test_an_unflagged_merchant_keeps_the_legacy_log_exactly(): void
    {
        $own = $this->connectOwn($this->other, 'OTHER-PN');
        $this->msg($this->other, $own, ['status' => WhatsAppStatus::FAILED, 'error_code' => '131026', 'error_message' => 'x']);
        $this->msg($this->other, null, ['source_mode' => WhatsAppMode::ABAAD_SHARED, 'status' => WhatsAppStatus::SKIPPED,
            'error_code' => WhatsAppStatus::SKIP_NO_RECIPIENT]);

        [$component, $props] = $this->page(['filter' => 'all'], $this->otherOwner);

        $this->assertSame('Admin/Marketing/WhatsappLog', $component);

        // والمحتوى نفسُه الذي كان يحسبه السجلّ قبل الميزة — صفًّا صفًّا
        $expected = WhatsAppLog::page($this->other->id, null, '');
        $this->assertSame(collect($expected->items())->all(), $props['rows']);
        $this->assertSame(WhatsAppLog::summary($this->other->id), $props['summary']);
        $this->assertSame(WhatsAppLog::filters(), $props['buckets']);
        $this->assertSame(['filter' => 'all', 'q' => ''], $props['params']);
        // مفاتيحُ الشاشة كما كانت — ولا مفتاحَ من المحادثات
        $own = ['readiness', 'rows', 'pagination', 'summary', 'summaryDays', 'buckets', 'params',
            'connected', 'number', 'conversations', 'thread'];
        $this->assertSame(['readiness', 'rows', 'pagination', 'summary', 'summaryDays', 'buckets', 'params'],
            array_values(array_intersect(array_keys($props), $own)));
        $this->assertCount(2, $props['rows']);

        // ولا «محادثات» في شريطه
        $this->assertNotContains('whatsapp_conversations', $props['context']['hosted']);
    }

    public function test_a_flagged_shop_reads_conversations_on_the_same_route(): void
    {
        $this->connectOwn($this->shop);

        [$component, $props] = $this->page();

        $this->assertSame('Admin/Marketing/WhatsappConversations', $component);
        $this->assertContains('whatsapp_conversations', $props['context']['hosted']);
    }

    public function test_the_new_endpoint_is_404_for_an_unflagged_business_even_by_hand(): void
    {
        $own = $this->connectOwn($this->other, 'OTHER-PN');
        $m = $this->msg($this->other, $own);

        $this->actingAs($this->otherOwner)
            ->getJson(route('admin.marketing.whatsapp.conversations.older', ['message' => $m->id]))
            ->assertNotFound();

        // ومن فُتحت له يصل — فالـ404 للمفتاح لا للمسار
        $mine = $this->msg($this->shop, $this->connectOwn($this->shop));
        $this->actingAs($this->owner)
            ->getJson(route('admin.marketing.whatsapp.conversations.older', ['message' => $mine->id]))
            ->assertOk();
    }

    public function test_no_runtime_code_names_the_shop_by_id_or_name(): void
    {
        $files = [
            app_path('Support/WhatsAppConversations.php'),
            app_path('Http/Controllers/Admin/WhatsAppController.php'),
            app_path('Http/Middleware/HandleInertiaRequests.php'),
            resource_path('js/Pages/Admin/Marketing/WhatsappConversations.tsx'),
            resource_path('js/lib/nav.ts'),
            resource_path('js/Components/Sidebar.tsx'),
        ];

        foreach ($files as $file) {
            $code = (string) file_get_contents($file);
            $this->assertDoesNotMatchRegularExpression('/RIBBON|سعود|Saud/u', $code, basename($file).' يسمّي متجرًا بعينه');
            $this->assertDoesNotMatchRegularExpression('/(business_id|->id|bid\(\))\s*===?\s*5\b/', $code, basename($file).' يقارن بمعرّف متجر');
        }

        // ومسارُ الباب الجديد كذلك — سطرُه وحده (في الملفّ مساراتٌ قديمةٌ لواجهة متجرٍ بعينه)
        $line = collect(file(base_path('routes/web.php')))->first(fn ($l) => str_contains($l, 'conversations/{message}/older'));
        $this->assertNotNull($line);
        $this->assertDoesNotMatchRegularExpression('/RIBBON|سعود|Saud|\b5\b/u', $line);

        // والمفتاحُ عمودٌ يُقرأ — لا قائمةُ الإعداد
        $this->shop->update(['whatsapp_conversations_enabled' => false]);
        config(['whatsapp.conversations_businesses' => [$this->shop->id]]);
        $this->assertFalse(WhatsAppConversations::enabled($this->shop->id));
    }

    public function test_the_migration_opens_it_for_the_configured_shop_alone(): void
    {
        $this->shop->update(['whatsapp_conversations_enabled' => false]);
        config(['whatsapp.conversations_businesses' => [$this->shop->id]]);

        (include database_path('migrations/2026_10_09_140000_a_shop_may_read_its_whatsapp_as_conversations.php'))->up();

        $this->assertTrue(WhatsAppConversations::enabled($this->shop->id));
        $this->assertFalse(WhatsAppConversations::enabled($this->other->id));
    }

    /* ======================= B · الوصلة ======================= */

    public function test_a_flagged_shop_without_its_own_number_sees_the_gate_even_with_abaads_number_active(): void
    {
        [$component, $props] = $this->page();

        $this->assertSame('Admin/Marketing/WhatsappConversations', $component);
        $this->assertFalse($props['connected']);
        $this->assertSame([], $props['conversations']);

        // ووصلةٌ مفصولةٌ كذلك
        $this->connectOwn($this->shop)->update(['status' => WhatsAppConnection::INACTIVE]);
        $this->assertFalse($this->page()[1]['connected']);
    }

    public function test_the_screen_opens_once_the_own_number_is_connected_and_says_when_nothing_was_sent(): void
    {
        $this->connectOwn($this->shop);

        $props = $this->page()[1];

        $this->assertTrue($props['connected']);
        $this->assertSame('+96892222222', $props['number']);
        $this->assertSame([], $props['conversations']);
    }

    /* ======================= C · D · ما لا يُعرض ======================= */

    public function test_shared_and_previous_number_messages_are_hidden_and_the_current_number_shown(): void
    {
        $own = $this->connectOwn($this->shop);
        $platform = WhatsAppConnection::where('owner_type', WhatsAppMode::OWNER_PLATFORM)->first();

        // قديمةٌ من رقم أبعاد المشترك — لهذا المتجر نفسِه
        $this->msg($this->shop, $platform, ['source_mode' => WhatsAppMode::ABAAD_SHARED, 'recipient_phone' => '96890000001']);
        // من رقمٍ سبق على الصفّ نفسِه (أُعيد الربطُ برقمٍ آخر)
        $this->msg($this->shop, $own, ['sender_phone_number_id' => 'OLD-PN', 'recipient_phone' => '96890000002']);
        // ومن قبل أن يُكتب الرقمُ في الصفّ أصلًا
        $this->msg($this->shop, $own, ['sender_phone_number_id' => null, 'recipient_phone' => '96890000003']);
        // ومن وصلةٍ أخرى
        $this->msg($this->shop, null, ['whatsapp_connection_id' => $platform->id, 'sender_phone_number_id' => 'SHOP-PN', 'recipient_phone' => '96890000004']);
        // وصفٌّ موسومٌ «مشترك» على وصلة المحلّ نفسِها (أثرٌ قديم) — الوضعُ يُسأل لا الوصلةُ وحدها
        $this->msg($this->shop, $own, ['source_mode' => WhatsAppMode::ABAAD_SHARED, 'recipient_phone' => '96890000006']);
        // ولم تخرج أصلًا
        $this->msg($this->shop, $own, ['status' => WhatsAppStatus::SKIPPED, 'error_code' => WhatsAppStatus::SKIP_NO_TEMPLATE, 'recipient_phone' => '96890000005']);

        $current = $this->msg($this->shop, $own, ['recipient_phone' => '96891112222']);

        $props = $this->page()[1];

        $this->assertSame(['96891112222'], array_column($props['conversations'], 'phone'));
        $this->assertSame($current->id, $props['conversations'][0]['key']);

        // وإعادةُ تفويض الرقم نفسِه لا تُسقط رسائله — تُحدّث `connected_at` على الصفّ نفسِه
        $own->update(['connected_at' => now()->addMinute(), 'access_token' => 'shop-token-renewed-0000000000']);
        $this->assertSame(['96891112222'], array_column($this->page()[1]['conversations'], 'phone'));
    }

    /* ======================= E · عزل المتاجر ======================= */

    public function test_the_same_phone_in_two_shops_stays_two_and_a_foreign_message_id_is_404(): void
    {
        $own = $this->connectOwn($this->shop);
        $theirs = $this->connectOwn($this->other, 'OTHER-PN');
        $this->other->update(['whatsapp_conversations_enabled' => true]);

        $mine = $this->msg($this->shop, $own, ['recipient_phone' => '96899999999']);
        $foreign = $this->msg($this->other, $theirs, ['recipient_phone' => '96899999999']);
        // وصفُّ متجرٍ آخر مكتوبٌ على وصلة هذا المتجر ورقمِه — المتجرُ يُسأل لا الوصلةُ وحدها
        $this->msg($this->other, $own, ['recipient_phone' => '96898888888']);

        $props = $this->page()[1];
        $this->assertSame([$mine->id], array_column($props['conversations'], 'key'));
        $this->assertSame(1, $props['conversations'][0]['messages']);

        // ومعرّفُ رسالة الجار في العنوان لا يفتح شيئًا — ولا في باب الأقدم
        $this->actingAs($this->owner)->get(route('admin.marketing.whatsapp.log', ['c' => $foreign->id]))->assertNotFound();
        $this->actingAs($this->owner)
            ->getJson(route('admin.marketing.whatsapp.conversations.older', ['message' => $foreign->id]))
            ->assertNotFound();

        // ولا business_id في الطلب يُبدّل النشاط
        $props = $this->page(['business_id' => $this->other->id])[1];
        $this->assertSame([$mine->id], array_column($props['conversations'], 'key'));
    }

    /* ======================= F · G · H · القائمة ======================= */

    public function test_conversations_group_by_phone_not_by_name_and_show_the_current_customer_name(): void
    {
        $own = $this->connectOwn($this->shop);
        $sameName = Customer::create(['business_id' => $this->shop->id, 'name' => 'سارة', 'phone' => '97777777']);

        $this->msg($this->shop, $own, ['customer_id' => $this->sara->id, 'body_snapshot' => 'الأولى']);
        $this->msg($this->shop, $own, ['customer_id' => $this->sara->id, 'body_snapshot' => 'الثانية']);
        $last = $this->msg($this->shop, $own, ['customer_id' => $this->sara->id, 'body_snapshot' => 'الثالثة']);
        // اسمٌ مطابقٌ برقمٍ آخر — محادثةٌ ثانية
        $this->msg($this->shop, $own, ['customer_id' => $sameName->id, 'recipient_phone' => '96897777777', 'body_snapshot' => 'غيرها']);
        // ورقمٌ بلا عميل
        $this->msg($this->shop, $own, ['recipient_phone' => '96895555555']);
        // وعميلٌ من متجرٍ آخر لا يُسمّي محادثةً هنا
        $stranger = Customer::create(['business_id' => $this->other->id, 'name' => 'غريب', 'phone' => '96000000']);
        $this->msg($this->shop, $own, ['customer_id' => $stranger->id, 'recipient_phone' => '96896000000']);

        $this->sara->update(['name' => 'سارة الجديدة']);

        $list = $this->page()[1]['conversations'];

        $this->assertCount(4, $list);
        $this->assertNull(collect($list)->firstWhere('phone', '96896000000')['name']);
        $sara = collect($list)->firstWhere('phone', '96891234567');
        $this->assertSame($last->id, $sara['key']);
        $this->assertSame(3, $sara['messages']);
        $this->assertSame('الثالثة', $sara['preview']);
        $this->assertSame('سارة الجديدة', $sara['name']);
        $this->assertNull(collect($list)->firstWhere('phone', '96895555555')['name']);

        // آخرُ رسالةٍ أوّلًا
        $this->assertSame('96896000000', $list[0]['phone']);

        // وعميلٌ حُذف: تبقى محادثتُه برقمها
        $this->sara->delete();
        $sara = collect($this->page()[1]['conversations'])->firstWhere('phone', '96891234567');
        $this->assertNotNull($sara);
        $this->assertNull($sara['name']);
    }

    public function test_search_finds_by_customer_name_and_by_phone_on_the_server(): void
    {
        $own = $this->connectOwn($this->shop);
        $this->msg($this->shop, $own, ['customer_id' => $this->sara->id]);
        $this->msg($this->shop, $own, ['recipient_phone' => '96895555555']);

        $this->assertSame(['96891234567'], array_column($this->page(['q' => 'سارة'])[1]['conversations'], 'phone'));
        $this->assertSame(['96895555555'], array_column($this->page(['q' => '5555'])[1]['conversations'], 'phone'));
        $this->assertSame(['96895555555'], array_column($this->page(['q' => '+968 9555'])[1]['conversations'], 'phone'));
        $this->assertSame([], $this->page(['q' => 'لا أحد'])[1]['conversations']);
    }

    public function test_the_list_is_paginated(): void
    {
        $own = $this->connectOwn($this->shop);
        for ($i = 0; $i < WhatsAppConversations::PER_PAGE + 3; $i++) {
            $this->msg($this->shop, $own, ['recipient_phone' => '9689'.str_pad((string) $i, 7, '0', STR_PAD_LEFT)]);
        }

        $props = $this->page()[1];
        $this->assertCount(WhatsAppConversations::PER_PAGE, $props['conversations']);
        $this->assertSame(WhatsAppConversations::PER_PAGE + 3, $props['pagination']['total']);
        $this->assertCount(3, $this->page(['page' => 2])[1]['conversations']);
    }

    /* ======================= I · J · K · المحادثة ======================= */

    public function test_a_thread_is_chronological_paginated_and_holds_one_phone_only(): void
    {
        $own = $this->connectOwn($this->shop);
        $ids = [];
        for ($i = 1; $i <= 55; $i++) {
            $ids[] = $this->msg($this->shop, $own, ['body_snapshot' => 'رسالة '.$i])->id;
        }
        $this->msg($this->shop, $own, ['recipient_phone' => '96890000009', 'body_snapshot' => 'غيرها']);

        $thread = $this->page(['c' => end($ids)])[1]['thread'];

        $this->assertCount(50, $thread['messages']);
        $this->assertSame('رسالة 6', $thread['messages'][0]['body']);
        $this->assertSame('رسالة 55', $thread['messages'][49]['body']);
        $this->assertTrue($thread['has_more']);
        $this->assertSame('96891234567', $thread['phone']);

        $older = $this->actingAs($this->owner)
            ->getJson(route('admin.marketing.whatsapp.conversations.older', ['message' => end($ids), 'before' => $thread['before']]))
            ->assertOk()->json();

        $this->assertSame(['رسالة 1', 'رسالة 2', 'رسالة 3', 'رسالة 4', 'رسالة 5'], array_column($older['messages'], 'body'));
        $this->assertFalse($older['has_more']);
    }

    public function test_each_status_reads_with_the_systems_own_label_and_a_failure_says_why(): void
    {
        $own = $this->connectOwn($this->shop);
        foreach ([WhatsAppStatus::QUEUED, WhatsAppStatus::SENT, WhatsAppStatus::DELIVERED, WhatsAppStatus::READ] as $s) {
            $last = $this->msg($this->shop, $own, ['status' => $s]);
        }
        $failed = $this->msg($this->shop, $own, ['status' => WhatsAppStatus::FAILED, 'error_code' => '131026',
            'error_message' => 'Message undeliverable']);

        $bubbles = collect($this->page(['c' => $failed->id])[1]['thread']['messages'])->keyBy('status');

        foreach ([WhatsAppStatus::QUEUED, WhatsAppStatus::SENT, WhatsAppStatus::DELIVERED, WhatsAppStatus::READ, WhatsAppStatus::FAILED] as $s) {
            $this->assertSame(WhatsAppStatus::label($s), $bubbles[$s]['status_label']);
        }
        $this->assertSame('Message undeliverable', $bubbles['failed']['error']);
        $this->assertSame(WhatsAppLog::row($failed)['reason'], $bubbles['failed']['reason']);
    }

    public function test_a_message_links_to_its_order_or_its_invoice(): void
    {
        $own = $this->connectOwn($this->shop);
        $order = $this->order($this->shop, $this->sara);
        $invoice = CustomerInvoice::create(['business_id' => $this->shop->id, 'customer_id' => $this->sara->id,
            'number' => 'CI-77', 'status' => 'غير مدفوعة', 'subtotal' => 10, 'total' => 10, 'issued_at' => now()]);

        $this->msg($this->shop, $own, ['order_id' => $order->id]);
        $inv = $this->msg($this->shop, $own, ['customer_invoice_id' => $invoice->id, 'event_type' => WhatsAppEvent::INVOICE_OVERDUE]);

        $bubbles = $this->page(['c' => $inv->id])[1]['thread']['messages'];

        $this->assertSame('order', $bubbles[0]['subject_kind']);
        $this->assertSame(route('admin.orders.show', $order->number), $bubbles[0]['subject']['url']);
        $this->assertSame('invoice', $bubbles[1]['subject_kind']);
        $this->assertSame(route('admin.customerInvoices.show', $invoice->id), $bubbles[1]['subject']['url']);
    }

    /* ======================= L · نصُّ الرسالة ======================= */

    public function test_the_sync_keeps_each_approved_body_by_language_without_touching_status(): void
    {
        $own = $this->connectOwn($this->shop);
        $name = WhatsAppEvent::DEFAULT_TEMPLATES[WhatsAppEvent::ORDER_READY];
        Http::fake(['*message_templates*' => Http::response(['data' => [
            ['name' => $name, 'status' => 'APPROVED', 'language' => 'ar', 'components' => [
                ['type' => 'HEADER', 'format' => 'TEXT', 'text' => 'رأس'],
                ['type' => 'BODY', 'text' => 'مرحبًا من {{1}}، طلبك {{2}} جاهز.'],
            ]],
            ['name' => $name, 'status' => 'PENDING', 'language' => 'en', 'components' => [
                ['type' => 'BODY', 'text' => 'Hi from {{1}}, order {{2}} is ready.'],
            ]],
        ]], 200)]);

        WhatsAppTemplates::sync($own, WhatsAppMode::OWNER_BUSINESS, $this->shop->id);

        $mapping = WhatsAppTemplateMapping::where('business_id', $this->shop->id)->where('template_name', $name)->first();
        $this->assertSame(['ar' => 'مرحبًا من {{1}}، طلبك {{2}} جاهز.', 'en' => 'Hi from {{1}}, order {{2}} is ready.'], $mapping->body_by_language);
        $this->assertSame('APPROVED', $mapping->meta_status);
        $this->assertSame(['ar'], $mapping->approved_languages);

        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), 'fields=name%2Cstatus%2Clanguage%2Ccomponents'));
    }

    public function test_a_sent_message_keeps_the_text_it_was_sent_with_and_meta_gets_the_same_payload(): void
    {
        $this->shop->update(['whatsapp_own_allowed' => true, 'whatsapp_mode' => WhatsAppMode::BUSINESS_OWN]);
        $own = $this->connectOwn($this->shop);
        WhatsAppTemplateMapping::where('business_id', $this->shop->id)->where('event_type', WhatsAppEvent::ORDER_READY)
            ->update(['body_by_language' => json_encode(['ar' => 'مرحبًا من {{1}}، طلبك {{2}} جاهز.'])]);
        Http::fake(['*/messages' => Http::response(['messages' => [['id' => 'wamid.X']]], 200)]);

        $order = $this->order($this->shop, $this->sara);
        $order->update(['status' => OrderStatus::READY]);

        $message = WhatsAppMessage::where('order_id', $order->id)->firstOrFail();
        $this->assertSame(WhatsAppStatus::SENT, $message->status);
        $this->assertSame('مرحبًا من محل الورد، طلبك '.$order->number.' جاهز.', $message->body_snapshot);
        $this->assertSame('SHOP-PN', $message->sender_phone_number_id);
        $this->assertSame($own->id, $message->whatsapp_connection_id);

        // وما خرج إلى ميتا: القالبُ ومتغيّراه كما كانا — لا نصّ
        Http::assertSent(function (HttpRequest $r) use ($order) {
            $components = $r['template']['components'] ?? [];

            return str_contains($r->url(), '/messages')
                && ! isset($r['text'])
                && $components === [['type' => 'body', 'parameters' => [
                    ['type' => 'text', 'text' => 'محل الورد'], ['type' => 'text', 'text' => $order->number],
                ]]];
        });

        // وقالبٌ يُعدَّل بعدها لا يُغيّر ما قرأه الزبون
        WhatsAppTemplateMapping::where('business_id', $this->shop->id)->where('event_type', WhatsAppEvent::ORDER_READY)
            ->update(['body_by_language' => json_encode(['ar' => 'نصٌّ آخر {{1}} {{2}}'])]);
        $this->assertSame('مرحبًا من محل الورد، طلبك '.$order->number.' جاهز.', $message->fresh()->body_snapshot);

        // ويُعرض في المحادثة كما هو
        $bubble = $this->page(['c' => $message->id])[1]['thread']['messages'][0];
        $this->assertSame($message->body_snapshot, $bubble['body']);
        $this->assertNull($bubble['summary']);
    }

    public function test_the_job_writes_the_number_it_actually_sends_from(): void
    {
        $this->shop->update(['whatsapp_own_allowed' => true, 'whatsapp_mode' => WhatsAppMode::BUSINESS_OWN]);
        $own = $this->connectOwn($this->shop);
        Http::fake(['*/messages' => Http::response(['messages' => [['id' => 'wamid.Z']]], 200)]);

        // أُدرجت والرقمُ غيرُه، ثمّ رُبط الرقمُ الحاليّ على الصفّ نفسِه قبل أن تخرج
        $order = $this->order($this->shop, $this->sara);
        $queued = $this->msg($this->shop, $own, ['order_id' => $order->id, 'status' => WhatsAppStatus::QUEUED,
            'sender_phone_number_id' => 'OLD-PN', 'template_name' => WhatsAppEvent::DEFAULT_TEMPLATES[WhatsAppEvent::ORDER_READY]]);

        (new SendWhatsAppMessage($queued->id))->handle();

        $this->assertSame(WhatsAppStatus::SENT, $queued->fresh()->status);
        $this->assertSame('SHOP-PN', $queued->fresh()->sender_phone_number_id);
    }

    public function test_an_unknown_body_writes_no_text_and_reads_as_a_description(): void
    {
        $this->shop->update(['whatsapp_own_allowed' => true, 'whatsapp_mode' => WhatsAppMode::BUSINESS_OWN]);
        $this->connectOwn($this->shop);
        Http::fake(['*/messages' => Http::response(['messages' => [['id' => 'wamid.Y']]], 200)]);

        // لا صيغةَ محفوظة — لم تُزامَن القوالب
        $order = $this->order($this->shop, $this->sara);
        $order->update(['status' => OrderStatus::READY]);
        $message = WhatsAppMessage::where('order_id', $order->id)->firstOrFail();
        $this->assertNull($message->body_snapshot);

        // وصيغةٌ بمكانٍ لا رقمَ له لا تُملأ تخمينًا
        $this->assertNull(WhatsAppTemplates::snapshot(WhatsAppMode::OWNER_PLATFORM, null, 'x', 'ar', ['a']));
        WhatsAppTemplateMapping::where('business_id', $this->shop->id)->where('event_type', WhatsAppEvent::ORDER_READY)
            ->update(['body_by_language' => json_encode(['ar' => 'مرحبًا {{name}}'])]);
        $this->assertNull(WhatsAppTemplates::snapshot(WhatsAppMode::OWNER_BUSINESS, $this->shop->id, WhatsAppEvent::DEFAULT_TEMPLATES[WhatsAppEvent::ORDER_READY], 'ar', ['a', 'b']));
        WhatsAppTemplateMapping::where('business_id', $this->shop->id)->where('event_type', WhatsAppEvent::ORDER_READY)
            ->update(['body_by_language' => json_encode(['ar' => '{{1}} {{3}}'])]);
        $this->assertNull(WhatsAppTemplates::snapshot(WhatsAppMode::OWNER_BUSINESS, $this->shop->id, WhatsAppEvent::DEFAULT_TEMPLATES[WhatsAppEvent::ORDER_READY], 'ar', ['a', 'b']));

        $bubble = $this->page(['c' => $message->id])[1]['thread']['messages'][0];
        $this->assertNull($bubble['body']);
        $this->assertSame(WhatsAppEvent::label(WhatsAppEvent::ORDER_READY).' — #'.$order->number, $bubble['summary']);
    }

    /* ======================= M · لا سرَّ يعبر ======================= */

    public function test_no_secret_reaches_the_browser(): void
    {
        $own = $this->connectOwn($this->shop);
        $m = $this->msg($this->shop, $own, ['metadata' => ['internal' => 'raw-provider-payload']]);
        config(['whatsapp.app_secret' => 'APP-SECRET-VALUE']);

        $html = $this->actingAs($this->owner)->get(route('admin.marketing.whatsapp.log', ['c' => $m->id]))->assertOk()->getContent();
        $json = $this->actingAs($this->owner)
            ->getJson(route('admin.marketing.whatsapp.conversations.older', ['message' => $m->id]))->getContent();

        foreach ([$html, $json] as $body) {
            foreach (['shop-token-value-9876543210', 'platform-token-value-0123456789', 'APP-SECRET-VALUE',
                'access_token', 'WABA-'.$this->shop->id, 'SHOP-PN', 'raw-provider-payload'] as $secret) {
                $this->assertStringNotContainsString($secret, $body);
            }
        }
    }

    /* ======================= ولا كتابة ======================= */

    public function test_the_screen_offers_no_way_to_send(): void
    {
        $source = (string) file_get_contents(resource_path('js/Pages/Admin/Marketing/WhatsappConversations.tsx'));

        $this->assertStringNotContainsString('Textarea', $source);
        $this->assertStringNotContainsString('router.post', $source);
        $this->assertStringNotContainsString("method: 'post'", $source);
        $this->assertStringNotContainsString('sendText', (string) file_get_contents(app_path('Support/WhatsAppConversations.php')));

        $routes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($r) => str_contains($r->uri(), 'whatsapp/conversations'));
        $this->assertSame([['GET', 'HEAD']], $routes->map(fn ($r) => $r->methods())->values()->all());
    }
}
