<?php

namespace Tests\Feature;

use App\Jobs\SendWhatsAppMessage;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Models\WhatsAppConnection;
use App\Models\WhatsAppMessage;
use App\Support\CustomerInvoices;
use App\Support\Ledger;
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
 * الرسالةُ تُسمّي الورقةَ التي تتحدّث عنها — ولا تُرسَل نصفَ مملوءة.
 *
 * قالبُ تذكير السداد نصُّه «رقم الفاتورة: {{2}}»، وكان المُرسِل يملأ الثاني
 * بنصٍّ **فارغ**: الصفُّ لا يحمل الفاتورة (`order_id` فارغٌ لأنّها ليست طلبًا،
 * ولا عمودَ لها)، فيُكتب `''` صراحةً لكلّ ما ليس طلبًا.
 *
 * وميتا ترفض متغيّرًا بلا قيمة. فالرسالةُ تخرج، وتُستهلك بها **محاولةٌ
 * وحصّة**، وتُردّ، وتُقيَّد `failed` — ويقرأ التاجر «فشل» بلا سبب يفهمه.
 *
 * ولا يُقرأ المعرّفُ من `dedupe_key`: مفتاحٌ وُضع لمنع التكرار، وقراءتُه
 * لغير ما وُضع له تجعل تبديلَ صيغته يومًا يكسر رسالةً لا صلةَ له بها.
 */
class AMessageNamesThePaperItSpeaksOfTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    private Customer $company;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');

        /*
         * ولا نداءَ حقيقيًّا يخرج من اختبار.
         *
         * والطابور `sync` هنا: الوظيفة تُنفَّذ داخل الأمر الذي أطلقها. فلو
         * زُيّف الاتّصالُ بعده لَذهب النداءُ إلى ميتا فعلًا — وهو ما كان يقع.
         */
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.X']]], 200)]);

        $this->business = Business::create(['name' => 'زهور مسقط', 'type' => 'عام', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);
        Currency::create(['business_id' => $this->business->id, 'code' => 'OMR', 'name' => 'ريال',
            'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);
        Ledger::seedChart($this->business->id);
        $this->set('vat_enabled', '0');

        Setting::updateOrCreate(['business_id' => null, 'key' => 'whatsapp_enabled'], ['value' => '1']);
        Setting::updateOrCreate(['business_id' => null, 'key' => WhatsAppQuota::DEFAULT_KEY], ['value' => '100']);
        WhatsAppConnection::create([
            'owner_type' => WhatsAppMode::OWNER_PLATFORM, 'phone_number_id' => 'PN',
            'display_phone_number' => '+96890000000', 'access_token' => 'tok-0123456789abcdef',
            'status' => WhatsAppConnection::ACTIVE, 'connected_at' => now(),
        ]);
        WhatsAppTemplates::seedPlatformDefaults('ar');

        $this->owner = User::create(['business_id' => $this->business->id, 'name' => 'المالك',
            'email' => 'o@abaadapp.om', 'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط']);
        $this->product = Product::create(['business_id' => $this->business->id, 'name' => 'باقة',
            'price' => 10, 'cost' => 4, 'quantity' => 500, 'active' => true]);
        $this->company = Customer::create(['language' => 'ar', 'business_id' => $this->business->id, 'name' => 'شركة',
            'phone' => '99887766', 'allow_credit_sales' => true, 'monthly_billing' => true,
            'payment_terms_days' => 30]);
    }

    /* ==================== الفاتورةُ تُسمّى ==================== */

    /** ما يصل ميتا هو رقمُ الفاتورة — لا فراغٌ مكانَه */
    public function test_the_reminder_carries_the_invoice_number_meta_asks_for(): void
    {
        $invoice = $this->overdue();

        $params = $this->sendAndCapture(WhatsAppEvent::INVOICE_OVERDUE);

        $this->assertSame('زهور مسقط', $params[0]['text'] ?? null);
        $this->assertSame(
            $invoice->number,
            $params[1]['text'] ?? null,
            'القالب يقول «رقم الفاتورة: {{2}}» — والمُرسَل غيرُ رقمها',
        );
    }

    /** ولا متغيّرَ فارغًا يخرج — ميتا تردّه، والحصّةُ تُستهلك في الردّ */
    public function test_no_empty_variable_is_ever_shipped(): void
    {
        $this->overdue();

        foreach ($this->sendAndCapture(WhatsAppEvent::INVOICE_OVERDUE) as $i => $p) {
            $this->assertNotSame('', (string) ($p['text'] ?? ''), 'المتغيّر رقم '.($i + 1).' خرج فارغًا');
        }
    }

    /** والصفُّ نفسُه يحمل الفاتورة — لا يُستخرج معرّفُها من مفتاح التكرار */
    public function test_the_row_names_its_invoice_in_a_column_of_its_own(): void
    {
        $invoice = $this->overdue();

        $this->artisan('invoices:remind')->assertSuccessful();

        $message = WhatsAppMessage::where('event_type', WhatsAppEvent::INVOICE_OVERDUE)->firstOrFail();

        $this->assertSame($invoice->id, $message->customer_invoice_id);
        $this->assertSame($invoice->id, $message->customerInvoice?->id);
    }

    /* ==================== ورسائلُ الطلب لم تتبدّل ==================== */

    /** رسالةُ الطلب تبقى على اسم المحلّ ورقم الطلب */
    public function test_an_order_message_still_carries_its_order_number(): void
    {
        $order = $this->order();

        $this->set('wa_on_ready', '1');
        $order->update(['status' => OrderStatus::READY]);
        WhatsAppAutomation::handle($order->fresh(), OrderStatus::READY);

        $message = WhatsAppMessage::where('event_type', WhatsAppEvent::ORDER_READY)->firstOrFail();
        $this->assertNull($message->customer_invoice_id);
        $this->assertSame(WhatsAppStatus::SENT, $message->status);

        $params = $this->captured('abaad_order_ready');
        $this->assertSame('زهور مسقط', $params[0]['text'] ?? null);
        $this->assertSame($order->number, $params[1]['text'] ?? null);
    }

    /* ==================== وما لا موضوعَ له لا يخرج ==================== */

    /**
     * رسالةٌ بلا طلبٍ ولا فاتورة تقف ولا تُنادى.
     *
     * وكانت تخرج بمتغيّرٍ فارغ فتُردّ — وتُستهلك محاولةٌ وحصّةٌ في ردّ.
     */
    public function test_a_message_about_nothing_stops_instead_of_burning_a_try(): void
    {
        // صفٌّ مُدرَجٌ لا طلبَ له ولا فاتورة — يُبنى هنا لأنّ المقيس هو المُرسِل
        $message = WhatsAppMessage::create([
            'business_id' => $this->business->id,
            'order_id' => null,
            'customer_invoice_id' => null,
            'customer_id' => $this->company->id,
            'whatsapp_connection_id' => WhatsAppConnection::query()->value('id'),
            'source_mode' => WhatsAppMode::ABAAD_SHARED,
            'event_type' => WhatsAppEvent::INVOICE_OVERDUE,
            'direction' => 'outbound',
            'recipient_phone' => '96899887766',
            'template_name' => 'abaad_invoice_overdue',
            'language_code' => 'ar',
            'dedupe_key' => 'probe:no-subject',
            'status' => WhatsAppStatus::QUEUED,
            'quota_consumed' => false,
            'queued_at' => now(),
        ]);

        SendWhatsAppMessage::dispatchSync($message->id);

        // ولا نداءَ بقالبها خرج — لا نصفَ مملوءةٍ تُردّ وتأكل حصّة
        $this->assertSame([], $this->requestsFor('abaad_invoice_overdue'));

        $message->refresh();
        $this->assertSame(WhatsAppStatus::SKIPPED, $message->status);
        $this->assertSame(WhatsAppStatus::SKIP_NO_SUBJECT, $message->error_code);
    }

    /* ------------------------------- التهيئة ------------------------------- */

    private function set(string $key, string $value): void
    {
        Setting::updateOrCreate(
            ['business_id' => $this->business->id, 'key' => $key], ['value' => $value],
        );
    }

    private function order(): Order
    {
        $this->actingAs($this->owner)->postJson('/pos/checkout', [
            'items' => [['id' => $this->product->id, 'name' => 'باقة', 'qty' => 8, 'price' => 10]],
            'payment_method' => 'نقدي', 'credit' => true, 'customer_id' => $this->company->id,
        ])->assertOk();

        return Order::latest('id')->first();
    }

    private function overdue(): CustomerInvoice
    {
        $this->set('wa_on_invoice_overdue', '1');

        $invoice = CustomerInvoices::consolidate(
            $this->company, [$this->order()->id], [], $this->owner->id,
        );
        $invoice->update(['due_at' => now()->subDays(5)->toDateString()]);

        return $invoice->fresh();
    }

    /**
     * يُشغّل التذكير ويردّ متغيّرات القالب كما وصلت ميتا.
     *
     * والتزييفُ يسبق الأمر: الطابور `sync` في الاختبار، فالوظيفة تُنفَّذ داخل
     * `invoices:remind` نفسه. ولو زُيّف بعده لَخرج نداءٌ حقيقيّ إلى ميتا.
     */
    private function sendAndCapture(string $event): array
    {
        $this->artisan('invoices:remind')->assertSuccessful();

        $sent = WhatsAppMessage::where('event_type', $event)->firstOrFail();
        $this->assertSame(WhatsAppStatus::SENT, $sent->status);

        return $this->captured((string) $sent->template_name);
    }

    /**
     * متغيّراتُ النداء الذي حمل هذا القالب.
     *
     * ويُنتقى بالقالب لا بالترتيب: شراءُ التهيئة يُطلق «تأكيد الطلب» قبله،
     * فقراءةُ «أوّل نداء» تقيس رسالةً غير التي يدّعي الاختبار قياسَها.
     */
    private function captured(string $template): array
    {
        $calls = $this->requestsFor($template);

        $this->assertNotSame([], $calls, "لم يخرج نداءٌ بالقالب «{$template}»");

        return $calls[0]['template']['components'][0]['parameters'] ?? [];
    }

    /** @return list<array> حمولاتُ كلّ نداءٍ حمل هذا القالب */
    private function requestsFor(string $template): array
    {
        return Http::recorded()
            ->map(fn ($pair) => $pair[0]->data())
            ->filter(fn ($data) => ($data['template']['name'] ?? null) === $template)
            ->values()->all();
    }
}
