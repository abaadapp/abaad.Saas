<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\GoodsReceiptNote;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\User;
use App\Support\GoodsReceipts;
use App\Support\Ledger;
use App\Support\SupplierInvoices;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * مرفقاتُ المستندات المالية تُقرأ ببابٍ يسأل عن صاحبها.
 *
 * والقديمةُ على القرص العامّ: رابطُها يُفتح بلا تسجيل دخول، ومن عرف رابطًا
 * واحدًا عرف نمطَه. وورقةُ مورّدٍ فيها أسعارُ شرائك — أثمنُ ما في متجرك
 * عند منافسك.
 */
class FinancialAttachmentsAskWhoIsAskingTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->business = Business::create(['name' => 'زهور مسقط', 'type' => 'عام', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);
        Ledger::seedChart($this->business->id);
        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        $this->supplier = Supplier::create(['business_id' => $this->business->id, 'name' => 'ورد الخليج']);
    }

    /* ------------------------- فاتورةُ المورّد ------------------------- */

    /**
     * المرفوعُ يذهب إلى القرص الخاصّ لا العامّ — والاسمُ المخزَّن عشوائيّ.
     *
     * ومن سمّى الملفّ يبقى اسمُه معروضًا: اسمٌ عشوائيٌّ بلا حفظِ الأصل
     * يُنزَّل إلى المحاسب بلا معنى، واسمٌ يُبنى من الأصل يُخمَّن.
     */
    public function test_a_supplier_invoice_attachment_lands_on_the_private_disk(): void
    {
        $this->actingAs($this->owner)->post(route('admin.purchases.invoices.store'), [
            'supplier_id' => $this->supplier->id, 'supplier_ref' => 'S-1',
            'issued_at' => now()->toDateString(), 'subtotal' => 100, 'tax' => 0,
            'attachment' => UploadedFile::fake()->create('فاتورة سبتمبر.pdf', 20, 'application/pdf'),
        ])->assertSessionHasNoErrors();

        $invoice = SupplierInvoice::firstOrFail();

        $this->assertNotNull($invoice->attachment);
        $this->assertSame('فاتورة سبتمبر.pdf', $invoice->attachment_name);
        $this->assertStringStartsWith('supplier-invoices/'.$this->business->id, $invoice->attachment);
        // والاسمُ المخزَّن ليس اسمَ صاحبه: رابطٌ يُبنى من الأصل يُخمَّن
        $this->assertStringNotContainsString('فاتورة سبتمبر', $invoice->attachment);
        Storage::disk('local')->assertExists($invoice->attachment);
    }

    /** ويُقرأ من بابه */
    public function test_the_owner_can_read_the_attachment(): void
    {
        $this->actingAs($this->owner)->post(route('admin.purchases.invoices.store'), [
            'supplier_id' => $this->supplier->id, 'supplier_ref' => 'S-1',
            'issued_at' => now()->toDateString(), 'subtotal' => 100, 'tax' => 0,
            'attachment' => UploadedFile::fake()->create('ورقة.pdf', 10, 'application/pdf'),
        ]);

        $this->actingAs($this->owner)
            ->get(route('admin.purchases.invoices.attachment', SupplierInvoice::firstOrFail()->id))
            ->assertOk();
    }

    /** ومرفقُ الجار لا يُفتح برقمٍ يُكتب في العنوان */
    public function test_a_neighbours_attachment_is_out_of_reach(): void
    {
        $other = Business::create(['name' => 'الجار', 'type' => 'عام', 'status' => 'نشط']);
        $theirSupplier = Supplier::create(['business_id' => $other->id, 'name' => 'مورّدهم']);
        $theirs = SupplierInvoice::create([
            'business_id' => $other->id, 'supplier_id' => $theirSupplier->id,
            'supplier_ref' => 'X-1', 'issued_at' => now()->toDateString(),
            'subtotal' => 100, 'total' => 100,
            'attachment' => 'supplier-invoices/'.$other->id.'/secret.pdf',
            'attachment_name' => 'أسعارهم.pdf',
            'approval_status' => SupplierInvoices::PENDING,
        ]);
        Storage::disk('local')->put($theirs->attachment, 'سرٌّ');

        $this->actingAs($this->owner)
            ->get(route('admin.purchases.invoices.attachment', $theirs->id))->assertNotFound();
    }

    /** ومن لم يسجّل دخوله لا يقرأ شيئًا */
    public function test_a_guest_reads_nothing(): void
    {
        $this->actingAs($this->owner)->post(route('admin.purchases.invoices.store'), [
            'supplier_id' => $this->supplier->id, 'supplier_ref' => 'S-1',
            'issued_at' => now()->toDateString(), 'subtotal' => 100, 'tax' => 0,
            'attachment' => UploadedFile::fake()->create('ورقة.pdf', 10, 'application/pdf'),
        ]);

        $id = SupplierInvoice::firstOrFail()->id;

        $this->post(route('logout'));
        $this->get(route('admin.purchases.invoices.attachment', $id))->assertRedirect(route('login'));
    }

    /** ومرفقٌ لا وجود له يردّ ٤٠٤ لا خطأ خادم */
    public function test_a_missing_file_answers_not_found(): void
    {
        $invoice = SupplierInvoice::create([
            'business_id' => $this->business->id, 'supplier_id' => $this->supplier->id,
            'supplier_ref' => 'S-9', 'issued_at' => now()->toDateString(),
            'subtotal' => 10, 'total' => 10, 'approval_status' => SupplierInvoices::PENDING,
        ]);

        $this->actingAs($this->owner)
            ->get(route('admin.purchases.invoices.attachment', $invoice->id))->assertNotFound();
    }

    /* -------------------------- ورقةُ الشحنة -------------------------- */

    /** وورقةُ المورّد مع الشحنة كذلك — وهي غيرُ فاتورته */
    public function test_a_receipt_attachment_is_stored_privately_and_read_through_the_door(): void
    {
        $po = PurchaseOrder::create([
            'business_id' => $this->business->id,
            'branch_id' => Branch::where('business_id', $this->business->id)->value('id'),
            'number' => 'PO-1', 'supplier_id' => $this->supplier->id,
            'status' => 'مُرسل', 'total' => 90, 'ordered_at' => now(),
        ]);
        $po->items()->create(['name' => 'باقة', 'cost' => 9, 'quantity' => 10]);

        $this->actingAs($this->owner)->post(route('admin.purchases.receive', $po->id), [
            'attachment' => UploadedFile::fake()->create('بوليصة.pdf', 10, 'application/pdf'),
        ])->assertSessionHasNoErrors();

        $note = GoodsReceiptNote::firstOrFail();

        $this->assertSame(GoodsReceipts::PENDING, $note->status);
        $this->assertSame('بوليصة.pdf', $note->attachment_name);
        $this->assertStringStartsWith('receipts/'.$this->business->id, $note->attachment);
        Storage::disk('local')->assertExists($note->attachment);

        $this->actingAs($this->owner)
            ->get(route('admin.inventory.receipts.attachment', $note->id))->assertOk();
    }

    /**
     * ومرفقٌ رُفع ثمّ سقط الحفظ لا يبقى على القرص.
     *
     * والرفعُ يقع قبل الكتابة — فسندٌ يُردّ بعده يترك ملفًّا لا صفَّ يشير
     * إليه: يتراكم على القرص، ويحمل أسعارَ شراءٍ لا مستندَ لها.
     *
     * والحالةُ سندٌ بلا مبلغ: يمرّ بالتحقّق ويسقط عند الكتابة — بعد الرفع.
     */
    public function test_a_rejected_upload_leaves_no_orphan_on_disk(): void
    {
        $this->actingAs($this->owner)->post(route('admin.purchases.invoices.store'), [
            'supplier_id' => $this->supplier->id, 'supplier_ref' => 'S-جديد',
            'issued_at' => now()->toDateString(), 'subtotal' => 0, 'tax' => 0,
            'attachment' => UploadedFile::fake()->create('ورقة.pdf', 10, 'application/pdf'),
        ])->assertSessionHasErrors('subtotal');

        $this->assertSame(0, SupplierInvoice::count());
        $this->assertSame([], Storage::disk('local')->files('supplier-invoices/'.$this->business->id));
    }
}
