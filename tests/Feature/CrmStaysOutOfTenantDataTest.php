<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Customer;
use App\Models\SupportConversation;
use App\Models\User;
use App\Models\WhatsAppMessage;
use App\Support\CrmLeads;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * CRM لا يبلغ ما بين التاجر وزبائنه — لا في الشاشة ولا في المصدر.
 *
 * ═══ لمَ هذا أخطرُ حارسٍ في القسم ═══
 *
 * رقمُ أبعادٍ المشترك يُرسل إشعاراتِ الطلبات **نيابةً عن المحلّات**، فيردّ
 * عليه زبائنُهم: «وصل؟»، «غيّر العنوان». فلو صار كلُّ رقمٍ مجهولٍ يكتب إلينا
 * «عميلًا محتملًا»، لَصارت زبونةُ محلِّ ورودٍ سألت عن هديّةٍ لزوجها صفًّا في
 * دفتر مبيعاتنا — ونصُّ رسالتها مقروءًا في لوحة المنصّة.
 *
 * والحارسُ هنا على طبقتين: الحمولةُ التي تصل المتصفّح فعلًا، والمصدرُ نفسُه.
 * الأولى تشهد على اليوم، والثانية تمنع سطرًا يُكتب بعد سنة.
 */
class CrmStaysOutOfTenantDataTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Business $shop;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create([
            'name' => 'سالم', 'email' => 'salem@abaad.om',
            'password' => bcrypt('secret'), 'role' => 'super_admin', 'status' => 'active',
        ]);

        $this->shop = Business::create(['name' => 'محل ورد', 'type' => 'محل ورود', 'status' => 'active']);

        /* زبونةٌ من زبائن المحلّ — ورقمُها هو ما لا يجوز أن يصير عميلًا محتملًا */
        $this->customer = Customer::create([
            'business_id' => $this->shop->id,
            'name' => 'مريم',
            'phone' => '97778888',
        ]);
    }

    /**
     * ولا سطرَ في مصدر CRM يمسّ جدولًا تحت `business_id`.
     *
     * ═══ ولمَ يُفحص المصدر ═══
     *
     * فحصُ الحمولة يشهد على ما يُرسل اليوم. وهذا يشهد على الغد: من يكتب بعد
     * سنةٍ `Customer::where(...)` في متحكّم CRM ليعرض «عملاء المتجر» يسقط
     * هنا — لا يوم يشكو تاجر.
     */
    /** @return list<string> ما يُفحص — ومصدرٌ واحد يقرؤه الحارسان */
    private function guardedFiles(): array
    {
        return [
            base_path('app/Http/Controllers/SuperAdmin/CrmController.php'),
            base_path('app/Support/CrmLeads.php'),
            base_path('app/Support/Crm.php'),
            base_path('app/Models/CrmLead.php'),
            base_path('app/Models/CrmNote.php'),
            base_path('app/Models/CrmTask.php'),
            base_path('app/Models/CrmStageEvent.php'),
            /*
             * وقناةُ واتساب معها — وهي أخطرُها.
             *
             * هنا يُقرأ واردٌ من رقمٍ **مجهول**، فسطرٌ يقرأ `Customer::`
             * ليخمّن «من هذا؟» يفتح جدولَ زبائن التجّار كلِّهم على دفتر
             * مبيعاتنا. والقائمةُ تُوسَّع مع كلّ ملفٍّ يُضاف إلى القسم —
             * وإلّا حرست ما كان ولا تحرس ما يُكتب.
             */
            base_path('app/Support/CrmWhatsApp.php'),
            base_path('app/Http/Controllers/SuperAdmin/CrmConversationController.php'),
            base_path('app/Models/CrmMessage.php'),
        ];
    }

    public function test_no_line_of_crm_source_touches_a_tenant_table(): void
    {
        $files = $this->guardedFiles();

        /* نماذجُ التاجر وزبائنه — ما لا يُذكر اسمُه في هذا القسم */
        $forbidden = [
            'Customer::', 'Order::', 'OrderItem::', 'Product::', 'Invoice::',
            'CustomerInvoice::', 'WhatsAppMessage::', 'SupportMessage::',
            'SupportConversation::', 'PointTransaction::', 'CustomerAddress::',
        ];

        foreach ($files as $path) {
            $source = file_get_contents($path);

            foreach ($forbidden as $needle) {
                $this->assertStringNotContainsString(
                    $needle,
                    $source,
                    'مصدرُ CRM يمسّ جدولَ تاجر — '.basename($path).' يذكر '.$needle
                );
            }
        }
    }

    /**
     * ولا ملفَّ CRM يبقى خارج القائمة أعلاه.
     *
     * «قائمةٌ تُكتب باليد تنسى التاليَ دائمًا»: يُضاف ملفٌّ إلى القسم بعد
     * شهرٍ فلا يُدرَج، فيمرّ فيه `Customer::` صامتًا. فيُقرأ ما في المجلّدات
     * ويُقارَن بما يُفحص.
     */
    public function test_no_crm_file_escapes_the_source_guard(): void
    {
        $guarded = array_map('basename', $this->guardedFiles());
        $found = [];

        foreach ([app_path('Support'), app_path('Models'), app_path('Http/Controllers/SuperAdmin')] as $dir) {
            foreach (scandir($dir) ?: [] as $name) {
                if (str_starts_with($name, 'Crm') && str_ends_with($name, '.php')) {
                    $found[] = $name;
                }
            }
        }

        $this->assertSame(
            [],
            array_values(array_diff($found, $guarded)),
            'ملفُّ CRM خارج حارس المصدر — أضِفه إلى `guardedFiles`'
        );
    }

    /**
     * ولا اسمُ زبونٍ ولا رقمُه يصل حمولةَ شاشةِ CRM.
     *
     * والفحصُ على الحمولة الخام لا على مصفوفةِ الخصائص: الحمولةُ هي ما يقرؤه
     * من يفتح أدوات المتصفّح.
     */
    public function test_a_tenant_customer_never_reaches_a_crm_payload(): void
    {
        $this->actingAs($this->admin);
        CrmLeads::findOrCreateByPhone('91234567', 'manual', 'خالد');

        $screens = [
            route('super-admin.crm.dashboard'),
            route('super-admin.crm.leads.index'),
            route('super-admin.crm.pipeline'),
            route('super-admin.crm.tasks'),
            route('super-admin.crm.reports'),
        ];

        foreach ($screens as $url) {
            $body = $this->get($url)->assertOk()->getContent();
            $payload = $this->inertiaPayload($body);

            $this->assertStringNotContainsString('مريم', $payload, 'اسمُ زبونةِ المحلّ وصل '.$url);
            $this->assertStringNotContainsString('97778888', $payload, 'رقمُ زبونةِ المحلّ وصل '.$url);
        }
    }

    /**
     * ومحادثةُ دعمٍ لتاجرٍ لا تُقرأ من CRM.
     *
     * البابان متجاوران في القائمة، والخلطُ بينهما سهلٌ في الكود: كلاهما
     * «محادثة». ومركزُ المحادثات دفترُ **الدعم**، وهذا دفترُ **البيع**.
     */
    public function test_a_support_thread_is_not_a_crm_lead(): void
    {
        $owner = User::create([
            'name' => 'صاحب المحل', 'email' => 'o@shop.om', 'password' => bcrypt('secret'),
            'role' => 'admin', 'status' => 'active', 'business_id' => $this->shop->id,
            'phone' => '95556666',
        ]);

        SupportConversation::create([
            'reference' => 'SUP-2026-000001',
            'business_id' => $this->shop->id,
            'opened_by' => $owner->id,
            'subject' => 'سرٌّ لا يُقرأ في المبيعات',
            'category' => 'أخرى',
            'channel' => 'in_app',
            'status' => 'new',
            'priority' => 'normal',
            'last_message_at' => now(),
        ]);

        $this->actingAs($this->admin);
        $body = $this->get(route('super-admin.crm.leads.index'))->assertOk()->getContent();

        $this->assertStringNotContainsString(
            'سرٌّ لا يُقرأ في المبيعات',
            $this->inertiaPayload($body),
            'موضوعُ محادثةِ دعمٍ وصل شاشةَ العملاء المحتملين'
        );
    }

    /**
     * ورسالةُ متجرٍ لزبونه لا يصل منها شيءٌ إلى دفتر المبيعات.
     *
     * والفحصُ على الجدول نفسِه بعد فتح كلّ شاشة: ما لا يُقرأ لا يُسرَّب.
     */
    public function test_a_merchant_to_customer_message_leaves_no_trace_in_crm(): void
    {
        WhatsAppMessage::create([
            'business_id' => $this->shop->id,
            'customer_id' => $this->customer->id,
            'source_mode' => 'abaad_shared',
            'event_type' => 'order_ready',
            'direction' => 'outbound',
            'recipient_phone' => '96897778888',
            'dedupe_key' => 'test-'.uniqid(),
            'status' => 'sent',
        ]);

        $this->actingAs($this->admin);

        foreach ([
            route('super-admin.crm.dashboard'),
            route('super-admin.crm.leads.index'),
            route('super-admin.crm.reports'),
        ] as $url) {
            $payload = $this->inertiaPayload($this->get($url)->assertOk()->getContent());

            $this->assertStringNotContainsString('96897778888', $payload, 'رقمُ زبونٍ وصل '.$url);
            $this->assertStringNotContainsString('order_ready', $payload, 'حدثُ طلبٍ لتاجرٍ وصل '.$url);
        }

        /* ولا صفَّ عميلٍ محتمَلٍ أُنشئ من رقم زبونة */
        $this->assertDatabaseMissing('crm_leads', ['phone' => '96897778888']);
    }

    /**
     * حمولةُ Inertia — من **جسم** الوسم لا من خاصيّته.
     *
     * والعربيّةُ داخلها مهروبةٌ `\uXXXX`، فالبحثُ في النصّ الخام لا يجد شيئًا
     * ويُظنّ الحارسُ قد شهد. فتُفكّ أوّلًا.
     */
    private function inertiaPayload(string $html): string
    {
        preg_match('/data-page="app" type="application\/json">(.*?)<\/script>/s', $html, $m);

        $this->assertNotEmpty($m[1] ?? '', 'لم تُقرأ حمولةُ الصفحة — تغيّر شكلُ الوسم');

        $decoded = json_decode(html_entity_decode($m[1], ENT_QUOTES), true);

        return json_encode($decoded, JSON_UNESCAPED_UNICODE) ?: '';
    }
}
