<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\JournalEntry;
use App\Models\Setting;
use App\Models\User;
use App\Support\CustomerInvoices;
use App\Support\Ledger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * مسودّةٌ لا تُنفق رقمَ فاتورة.
 *
 * ═══ العطب ═══
 *
 * الرقمُ كان يُقطع لحظةَ الحفظ. فمن فتح شاشةَ الإنشاء وحفظ مسودّةً ثمّ عدل
 * عنها أخذ `CINV-000042` معه — ويقفز الدفترُ من ٤١ إلى ٤٣ بلا جواب. وهو
 * دفترٌ يُقرأ عند المحاسب القانونيّ وعند الضريبة، وفجوةٌ في تسلسل الفواتير
 * سؤالٌ يُسأل صاحبُ المتجر عنه ولا يملك إجابته.
 *
 * ═══ وما ترتّب عليه ═══
 *
 * ونقلُ القطع إلى الإصدار يكسر افتراضًا كان صحيحًا: أنّ ترتيب المعرّفات هو
 * ترتيب الأرقام. ولم يعد — مسودّةٌ كُتبت أمسِ تُصدَر بعد واحدةٍ كُتبت
 * صباحًا. فمن يقرأ «آخر صفّ» ليأخذ الرقم التالي يُعيد رقمًا مستعمَلًا.
 */
class ADraftDoesNotSpendAnInvoiceNumberTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    private Customer $ministry;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'زهور مسقط', 'type' => 'عام', 'status' => 'نشط']);
        Currency::create([
            'business_id' => $this->business->id, 'code' => 'OMR', 'name' => 'ريال عماني',
            'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true,
        ]);
        Ledger::seedChart($this->business->id);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        $this->ministry = Customer::create([
            'business_id' => $this->business->id, 'name' => 'وزارة الثقافة',
            'customer_type' => 'جهة حكومية', 'legal_name' => 'وزارة الثقافة والرياضة والشباب',
        ]);
        Setting::create(['business_id' => $this->business->id, 'key' => 'vat_enabled', 'value' => '0']);

        $this->actingAs($this->owner);
    }

    private function draft(float $price = 500): CustomerInvoice
    {
        return CustomerInvoices::create($this->business->id, $this->ministry, [], [
            ['description' => 'توريد زهور', 'quantity' => 1, 'unit_price' => $price],
        ], $this->owner->id);
    }

    /* ═══════════════ ١) المسودّة لا تأخذ رقمًا ═══════════════ */

    public function test_a_draft_carries_no_official_number(): void
    {
        $draft = $this->draft();

        $this->assertNull($draft->number);
        $this->assertSame(CustomerInvoice::DRAFT, $draft->status);
    }

    /** ومسودّةٌ تُهجر لا تترك فجوةً في الدفتر */
    public function test_an_abandoned_draft_leaves_no_gap(): void
    {
        $this->draft();
        $this->draft();
        $this->draft();

        // ثلاثُ مسودّات، ثمّ تُصدَر واحدةٌ رابعة — فتكون الأولى لا الرابعة
        $this->assertSame('CINV-000001', CustomerInvoices::issue($this->draft(), $this->owner->id)->number);
    }

    /* ═══════════════ ٢) الإصدار يقطع الرقم ═══════════════ */

    public function test_issuing_assigns_the_official_number(): void
    {
        $issued = CustomerInvoices::issue($this->draft(), $this->owner->id);

        $this->assertSame('CINV-000001', $issued->number);
        $this->assertSame(CustomerInvoice::ISSUED, $issued->status);
        $this->assertSame('CINV-000001', $issued->fresh()->number);
    }

    /**
     * ═══════════════ ٣) الترتيبُ يتبع الإصدار لا الإنشاء ═══════════════
     *
     * وهذا هو ما كسره النقل: مسودّةٌ أقدمُ صفًّا تُصدَر بعد أحدثَ منها.
     * فمن يقرأ «آخر معرّف» ليأخذ الرقم التالي يجد رقمَ الثانية، ويُعطي
     * الأولى الرقمَ الذي يليه — وهو مستعمَلٌ إن سبقتها ثالثة.
     */
    public function test_the_sequence_follows_issue_order_not_row_order(): void
    {
        $first = $this->draft();     // أقدمُ صفًّا
        $second = $this->draft();

        $this->assertSame('CINV-000001', CustomerInvoices::issue($second, $this->owner->id)->number);
        $this->assertSame('CINV-000002', CustomerInvoices::issue($first, $this->owner->id)->number);

        /*
         * والثالثةُ هي التي تكشف العطب.
         *
         * بعد الإصدار المقلوب صار أكبرُ رقمٍ على أصغر معرّف. فمن يقرأ «آخر
         * صفٍّ مرقَّم» يجد `CINV-000001` ويعطي التالية `CINV-000002` — وهو
         * في يد الأولى. فيُردّ الإصدارُ على فهرس التفرّد، أو يمرّ فيصير
         * رقمان لورقتين حيث لا فهرس.
         */
        $this->assertSame('CINV-000003', CustomerInvoices::issue($this->draft(), $this->owner->id)->number);

        // ولا رقمَ مكرَّرًا في المتجر
        $numbers = CustomerInvoice::where('business_id', $this->business->id)
            ->whereNotNull('number')->orderBy('id')->pluck('number');

        $this->assertCount($numbers->count(), $numbers->unique());
    }

    /* ═══════════════ ٤) الإصدارُ مرّتين لا يُرقّم مرّتين ═══════════════ */

    public function test_issuing_twice_neither_renumbers_nor_posts_twice(): void
    {
        $draft = $this->draft();

        $once = CustomerInvoices::issue($draft, $this->owner->id);

        /*
         * والثانيةُ تُمرَّر النسخةَ القديمة عمدًا.
         *
         * فهي ما في يد المتصفّح: كائنٌ قُرئ قبل الإصدار وما زال يقول
         * «مسودة». ومن يقرأ الحالةَ من الكائن الممرَّر لا من الصفّ يمضي
         * فيكتب قيدًا ثانيًا لورقةٍ واحدة.
         */
        $twice = CustomerInvoices::issue($draft, $this->owner->id);

        $this->assertSame($once->number, $twice->number);
        $this->assertSame('CINV-000001', $twice->number);

        $entries = JournalEntry::where('business_id', $this->business->id)
            ->where('sourceable_type', CustomerInvoice::class)
            ->where('sourceable_id', $once->id)->count();

        $this->assertSame(1, $entries, 'قيدان لفاتورةٍ واحدة');
    }

    /** والبابُ نفسُه من الشاشة: ضغطتان لا تكتبان قيدين */
    public function test_pressing_issue_twice_from_the_screen_posts_once(): void
    {
        $draft = $this->draft();

        $this->post(route('admin.customerInvoices.issue', $draft->id))->assertSessionHasNoErrors();
        $this->post(route('admin.customerInvoices.issue', $draft->id))->assertSessionHasNoErrors();

        $this->assertSame(1, JournalEntry::where('business_id', $this->business->id)
            ->where('sourceable_type', CustomerInvoice::class)
            ->where('sourceable_id', $draft->id)->count());
    }

    /* ═══════════════ ٥) الملغاةُ لا يُعاد رقمُها ═══════════════ */

    public function test_a_cancelled_number_is_never_reused(): void
    {
        $first = CustomerInvoices::issue($this->draft(), $this->owner->id);
        $this->assertSame('CINV-000001', $first->number);

        CustomerInvoices::cancel($first, 'خطأ في المبلغ', $this->owner->id);

        // والملغاةُ تبقى برقمها — لا تُمحى ولا يُعاد استعماله
        $this->assertSame('CINV-000001', $first->fresh()->number);
        $this->assertSame('CINV-000002', CustomerInvoices::issue($this->draft(), $this->owner->id)->number);
    }

    /* ═══════════════ ٦) ما مضى لا يُرقَّم من جديد ═══════════════ */

    /**
     * ومسودّةٌ كُتبت تحت القاعدة القديمة تحمل رقمًا حجزته — فتُصدَر به.
     *
     * والتسلسلُ يستأنف من بعده لا من واحد: وإلّا لَاصطدم أوّلُ إصدارٍ بعد
     * الترقية بفهرس التفرّد.
     */
    public function test_a_legacy_draft_keeps_the_number_it_reserved(): void
    {
        $legacy = $this->draft();
        $legacy->update(['number' => 'CINV-000007']);

        $issued = CustomerInvoices::issue($legacy->fresh(), $this->owner->id);

        $this->assertSame('CINV-000007', $issued->number);
        $this->assertSame('CINV-000008', CustomerInvoices::issue($this->draft(), $this->owner->id)->number);
    }

    /* ═══════════════ ٧) الرقم لا يُكتب من الواجهة ═══════════════ */

    public function test_the_number_cannot_be_dictated_by_the_browser(): void
    {
        $this->post(route('admin.customerInvoices.store'), [
            'customer_id' => $this->ministry->id,
            'issued_at' => now()->toDateString(),
            'number' => 'CINV-999999',
            'issue' => true,
            'items' => [['description' => 'توريد', 'quantity' => 1, 'unit_price' => 100]],
        ])->assertSessionHasNoErrors();

        $this->assertSame('CINV-000001', CustomerInvoice::firstOrFail()->number);
    }

    /** وشاشةُ الإنشاء لا تَعِد برقمٍ لم يُقطع بعد */
    public function test_the_create_screen_promises_no_number(): void
    {
        $this->get(route('admin.customerInvoices.create'))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->missing('next_number')->etc());

        $screen = file_get_contents(base_path('resources/js/Pages/Admin/CustomerInvoices/Create.tsx'));
        $this->assertStringContainsString('سيتم إنشاء رقم الفاتورة عند الإصدار', $screen);
    }
}
