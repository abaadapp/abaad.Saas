<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\CustomerInvoiceAttachment;
use App\Models\Setting;
use App\Models\User;
use App\Support\CustomerInvoices;
use App\Support\InvoiceAttachments;
use App\Support\Ledger;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * ورقةٌ تحمل مستنداتِها — وملاحظةٌ للعميل ليست ملاحظةً لأنفسنا.
 *
 * ═══ الملاحظتان ═══
 *
 * كان الحقل واحدًا وهو يُطبع. فمن أراد أن يكتب لنفسه «العميل يماطل، لا
 * تُسلَّم قبل الدفع» كتبها حيث يقرؤها العميلُ في الورقة التي تصله.
 *
 * ═══ والمرفقات ═══
 *
 * فاتورةُ جهةٍ تُرفَق بأمر شرائها وعقدها. وكانت لا تُرفَق بشيء — تُحفظ
 * الأوراق في بريدٍ أو مجلَّدٍ على جهاز أحدهم. وهي على قرصٍ خاصّ: أمرُ شراء
 * وزارةٍ ليس مستندًا يُفتح برابطٍ يُخمَّن.
 */
class APaperCarriesItsDocumentsTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    private Customer $ministry;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

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
            'business_id' => $this->business->id, 'name' => 'وزارة الثقافة', 'customer_type' => 'جهة حكومية',
        ]);
        Setting::create(['business_id' => $this->business->id, 'key' => 'vat_enabled', 'value' => '0']);

        $this->actingAs($this->owner);
    }

    private function invoice(): CustomerInvoice
    {
        return CustomerInvoices::create($this->business->id, $this->ministry, [], [
            ['description' => 'توريد', 'quantity' => 1, 'unit_price' => 400],
        ], $this->owner->id);
    }

    private function pdf(string $name = 'أمر شراء.pdf'): UploadedFile
    {
        return UploadedFile::fake()->create($name, 20, 'application/pdf');
    }

    /* ═══════════════ الملاحظتان ═══════════════ */

    public function test_the_two_notes_are_stored_apart(): void
    {
        $this->post(route('admin.customerInvoices.store'), [
            'customer_id' => $this->ministry->id,
            'payment_method' => 'آجل',
            'issued_at' => now()->toDateString(),
            'notes' => 'يُرجى السداد خلال شهر',
            'internal_notes' => 'العميل يماطل — لا تُسلَّم قبل الدفع',
            'items' => [['description' => 'توريد', 'quantity' => 1, 'unit_price' => 400]],
        ])->assertSessionHasNoErrors();

        $invoice = CustomerInvoice::firstOrFail();

        $this->assertSame('يُرجى السداد خلال شهر', $invoice->notes);
        $this->assertSame('العميل يماطل — لا تُسلَّم قبل الدفع', $invoice->internal_notes);
    }

    /**
     * والداخليّةُ لا تبلغ ورقةَ العميل.
     *
     * وهذا الحارسُ يقرأ الورقةَ نفسَها — لا العمود: العمودُ قد يكون صحيحًا
     * والقالبُ يطبع الاثنين.
     */
    public function test_the_internal_note_is_never_printed(): void
    {
        $invoice = CustomerInvoices::issue($this->invoice(), $this->owner->id);
        $invoice->update([
            'notes' => 'شروط السداد ثلاثون يومًا',
            'internal_notes' => 'سرٌّ لا يُطبع',
        ]);

        $paper = $this->get(route('admin.customerInvoices.pdf', $invoice->id))->assertOk()->getContent();

        $this->assertStringNotContainsString('سرٌّ لا يُطبع', $paper);
    }

    /** وتظهر في الشاشة لمن يفتحها */
    public function test_the_internal_note_reaches_the_screen(): void
    {
        $invoice = $this->invoice();
        $invoice->update(['internal_notes' => 'سرٌّ لا يُطبع']);

        $this->get(route('admin.customerInvoices.show', $invoice->id))
            ->assertInertia(fn ($p) => $p->where('invoice.internal_notes', 'سرٌّ لا يُطبع')->etc());
    }

    /* ═══════════════ المرفقات ═══════════════ */

    public function test_documents_are_attached_with_the_invoice_and_read_back(): void
    {
        $this->post(route('admin.customerInvoices.store'), [
            'customer_id' => $this->ministry->id,
            'payment_method' => 'آجل',
            'issued_at' => now()->toDateString(),
            'items' => [['description' => 'توريد', 'quantity' => 1, 'unit_price' => 400]],
            'attachments' => [$this->pdf(), $this->pdf('عقد.pdf')],
        ])->assertSessionHasNoErrors();

        $invoice = CustomerInvoice::firstOrFail();
        $this->assertSame(2, $invoice->attachments()->count());

        $one = $invoice->attachments()->first();
        Storage::disk('local')->assertExists($one->path);

        $this->get(route('admin.customerInvoices.attachment', [$invoice->id, $one->id]))->assertOk();
    }

    /** ومستندٌ يصل بعد الإصدار يُرفَق كذلك */
    public function test_a_document_arriving_later_is_attached_too(): void
    {
        $invoice = CustomerInvoices::issue($this->invoice(), $this->owner->id);

        $this->post(route('admin.customerInvoices.attach', $invoice->id), [
            'attachments' => [$this->pdf()],
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, $invoice->attachments()->count());
    }

    /**
     * ورفعُ ثانٍ لا يمحو الأوّل.
     *
     * وهي الحفرةُ التي وقع فيها إيصالُ أمر الشراء: عمودٌ واحد، فرفعُ العقد
     * يمحو أمرَ الشراء من القرص بلا كلمة.
     */
    public function test_a_second_document_does_not_erase_the_first(): void
    {
        $invoice = $this->invoice();

        $first = InvoiceAttachments::store($invoice, $this->pdf(), $this->owner->id);
        InvoiceAttachments::store($invoice, $this->pdf('عقد.pdf'), $this->owner->id);

        $this->assertSame(2, $invoice->attachments()->count());
        Storage::disk('local')->assertExists($first->path);
    }

    /** والحذفُ يرفع الصفَّ وملفَّه معًا — لا أحدهما */
    public function test_removing_takes_the_row_and_the_file(): void
    {
        $invoice = $this->invoice();
        $row = InvoiceAttachments::store($invoice, $this->pdf(), $this->owner->id);
        $path = $row->path;

        $this->delete(route('admin.customerInvoices.detach', [$invoice->id, $row->id]))
            ->assertSessionHasNoErrors();

        $this->assertNull(CustomerInvoiceAttachment::find($row->id));
        Storage::disk('local')->assertMissing($path);
    }

    /* ═══════════════ الأبواب ═══════════════ */

    /** ومرفقٌ لا يُقرأ يُقال موجودًا ولا يُبنى له رابط */
    public function test_a_document_you_may_not_read_is_not_offered_as_absent(): void
    {
        $invoice = $this->invoice();
        InvoiceAttachments::store($invoice, $this->pdf(), $this->owner->id);

        $blind = User::create([
            'business_id' => $this->business->id, 'name' => 'موظّف', 'email' => 'b@abaad.om',
            'password' => bcrypt('password'), 'role' => 'employee', 'status' => 'نشط',
            'permissions' => ['orders'],
        ]);

        $this->actingAs($blind)->get(route('admin.customerInvoices.show', $invoice->id))
            ->assertInertia(fn ($p) => $p
                ->has('invoice.attachments', 1)
                ->where('invoice.attachments.0.url', null)
                ->where('invoice.may_read_attachments', false)
                ->etc());

        // والبابُ يردّه كذلك — لا الشاشةَ وحدها
        $row = $invoice->attachments()->first();
        $this->actingAs($blind)
            ->get(route('admin.customerInvoices.attachment', [$invoice->id, $row->id]))
            ->assertForbidden();

        $this->actingAs($this->owner)
            ->get(route('admin.customerInvoices.show', $invoice->id))
            ->assertInertia(fn ($p) => $p
                ->where('invoice.attachments.0.url', route('admin.customerInvoices.attachment', [$invoice->id, $row->id]))
                ->where('invoice.may_read_attachments', true)
                ->etc());
    }

    /** ومرفقُ الجار لا يُفتح برقمٍ يُكتب في العنوان */
    public function test_a_neighbours_document_does_not_open(): void
    {
        $other = Business::create(['name' => 'متجر الجار', 'type' => 'عام', 'status' => 'نشط']);
        Ledger::seedChart($other->id);
        $theirs = Customer::create(['business_id' => $other->id, 'name' => 'عميلهم']);
        $theirInvoice = CustomerInvoices::create($other->id, $theirs, [], [
            ['description' => 'بند', 'quantity' => 1, 'unit_price' => 10],
        ]);
        $theirRow = InvoiceAttachments::store($theirInvoice, $this->pdf('سرّهم.pdf'), null);

        $mine = $this->invoice();

        // لا من عنوان فاتورتي، ولا من عنوان فاتورتهم
        $this->get(route('admin.customerInvoices.attachment', [$mine->id, $theirRow->id]))->assertNotFound();
        $this->get(route('admin.customerInvoices.attachment', [$theirInvoice->id, $theirRow->id]))->assertNotFound();
        $this->delete(route('admin.customerInvoices.detach', [$mine->id, $theirRow->id]))->assertNotFound();

        $this->assertNotNull(CustomerInvoiceAttachment::find($theirRow->id));
    }

    /**
     * ومرفقُ فاتورةٍ أخرى في متجري لا يُفتح من عنوان هذه.
     *
     * الجدارُ بين المتاجر ليس كلَّ الحراسة: رقمٌ يُبدَّل في العنوان داخل
     * المتجر الواحد يفتح مستندَ ورقةٍ أخرى — وهو ما يمنعه الشرطان معًا.
     */
    public function test_a_document_of_another_invoice_does_not_open_from_this_one(): void
    {
        $a = $this->invoice();
        $b = $this->invoice();
        $row = InvoiceAttachments::store($b, $this->pdf(), $this->owner->id);

        $this->get(route('admin.customerInvoices.attachment', [$a->id, $row->id]))->assertNotFound();
        $this->get(route('admin.customerInvoices.attachment', [$b->id, $row->id]))->assertOk();
    }

    /** وما لا يُقبل رفعُه يُردّ برسالة لا يُحفظ صامتًا */
    public function test_a_refused_file_is_answered_not_swallowed(): void
    {
        $invoice = $this->invoice();

        $this->post(route('admin.customerInvoices.attach', $invoice->id), [
            'attachments' => [UploadedFile::fake()->create('برنامج.exe', 20)],
        ])->assertSessionHasErrors('attachments.0');

        $this->assertSame(0, $invoice->attachments()->count());
        $this->assertSame(0, count(Storage::disk('local')->allFiles()));
    }

    /** والصلاحيةُ فعلٌ يُمنح باسمه: من لا يملكه لا يقرأ */
    public function test_reading_documents_is_a_named_permission(): void
    {
        $this->assertContains('orders', Permissions::SECTIONS);
        $this->assertNotNull(Permissions::ATTACHMENT_VIEW);
    }
}
