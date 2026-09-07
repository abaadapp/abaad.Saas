<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\JournalEntry;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\User;
use App\Support\Ledger;
use App\Support\Permissions;
use App\Support\SupplierInvoices;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ذمّةُ المورّد تنتظر توقيعًا — وتُطابَق بثلاثة قبله.
 *
 * كان السند يُرحَّل لحظةَ كتابته: يُدخله المحاسب فيصير على المتجر دَينٌ في
 * الدفتر قبل أن يراه أحد. وأخطرُ منه أنّه لا يُقابَل بشيء: سندٌ بمئةٍ على
 * أمرٍ بثمانين، أو سندٌ كاملٌ على شحنةٍ وصل تسعون بالمئة منها — يمرّ ويُدفع.
 */
class TheSupplierDebtWaitsForASignatureTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    private User $clerk;

    private Supplier $supplier;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'زهور مسقط', 'type' => 'عام', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);
        Ledger::seedChart($this->business->id);
        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        $this->clerk = User::create([
            'business_id' => $this->business->id, 'name' => 'المحاسب', 'email' => 'k@abaad.om',
            'password' => bcrypt('password'), 'role' => 'employee', 'status' => 'نشط',
            'permissions' => ['purchases', 'finance'],
        ]);
        $this->supplier = Supplier::create(['business_id' => $this->business->id, 'name' => 'ورد الخليج']);
        $this->product = Product::create([
            'business_id' => $this->business->id, 'name' => 'باقة',
            'price' => 10, 'cost' => 4, 'quantity' => 0,
        ]);
    }

    private function order(float $total = 900, int $qty = 100, float $cost = 9): PurchaseOrder
    {
        $po = PurchaseOrder::create([
            'business_id' => $this->business->id,
            'branch_id' => Branch::where('business_id', $this->business->id)->value('id'),
            'number' => 'PO-'.random_int(1000, 9999),
            'supplier_id' => $this->supplier->id, 'supplier_name' => $this->supplier->name,
            'status' => 'مُرسل', 'total' => $total, 'ordered_at' => now(),
        ]);
        $po->items()->create([
            'product_id' => $this->product->id, 'name' => $this->product->name,
            'cost' => $cost, 'quantity' => $qty,
        ]);

        return $po->refresh();
    }

    /** يستلم كميّةً ويعتمدها — فتصير «واصلة» في نظر المطابقة */
    private function receiveAndApprove(PurchaseOrder $po, int $qty): void
    {
        $this->actingAs($this->clerk)->post(route('admin.purchases.receive', $po->id), [
            'items' => [['id' => $po->items()->first()->id, 'quantity' => $qty]],
        ])->assertSessionHasNoErrors();

        $this->approvePendingReceipts($this->business->id, $this->owner);
    }

    /** @return array<string, mixed> */
    private function payload(array $over = []): array
    {
        return $over + [
            'supplier_id' => $this->supplier->id,
            'supplier_ref' => 'S-'.random_int(1000, 9999),
            'issued_at' => now()->toDateString(),
            'subtotal' => 900, 'tax' => 0,
        ];
    }

    /* ------------------------- الكتابةُ لا تُرحّل ------------------------- */

    /** سندٌ كُتب ولم يُعتمد: لا ذمّةَ له في الدفتر */
    public function test_writing_an_invoice_creates_no_debt(): void
    {
        $this->actingAs($this->clerk)
            ->post(route('admin.purchases.invoices.store'), $this->payload())
            ->assertSessionHasNoErrors();

        $invoice = SupplierInvoice::firstOrFail();

        $this->assertSame(SupplierInvoices::PENDING, $invoice->approval_status);
        $this->assertSame($this->clerk->id, (int) $invoice->submitted_by);
        $this->assertSame(0, JournalEntry::where('business_id', $this->business->id)->count());
        $this->assertSame(0.0, Ledger::balance($this->business->id, 'payable'));
    }

    /** والاعتمادُ يُنشئها: مدين المخزون / دائن الموردين */
    public function test_approval_creates_the_debt(): void
    {
        $this->actingAs($this->clerk)->post(route('admin.purchases.invoices.store'), $this->payload());
        $invoice = SupplierInvoice::firstOrFail();

        $this->actingAs($this->owner)
            ->post(route('admin.purchases.invoices.approve', $invoice->id))
            ->assertSessionHasNoErrors();

        $this->assertSame(SupplierInvoices::APPROVED, $invoice->fresh()->approval_status);
        $this->assertSame($this->owner->id, (int) $invoice->fresh()->approved_by);
        $this->assertSame(900.0, Ledger::balance($this->business->id, 'payable'));
        $this->assertSame(900.0, Ledger::balance($this->business->id, 'inventory'));
    }

    /** واعتمادان لا يُنشئان ذمّتين */
    public function test_two_approvals_create_one_debt(): void
    {
        $this->actingAs($this->clerk)->post(route('admin.purchases.invoices.store'), $this->payload());
        $invoice = SupplierInvoice::firstOrFail();

        $this->actingAs($this->owner)->post(route('admin.purchases.invoices.approve', $invoice->id));
        $this->actingAs($this->owner)->post(route('admin.purchases.invoices.approve', $invoice->id));

        $this->assertSame(900.0, Ledger::balance($this->business->id, 'payable'));
        $this->assertSame(1, JournalEntry::where('business_id', $this->business->id)->count());
    }

    /** والرفضُ لا يُنشئ شيئًا، ولا يُمحى السند */
    public function test_rejection_creates_nothing_and_deletes_nothing(): void
    {
        $this->actingAs($this->clerk)->post(route('admin.purchases.invoices.store'), $this->payload());
        $invoice = SupplierInvoice::firstOrFail();

        $this->actingAs($this->owner)
            ->post(route('admin.purchases.invoices.reject', $invoice->id), ['reason' => 'مبلغٌ خاطئ'])
            ->assertSessionHasNoErrors();

        $this->assertSame(SupplierInvoices::REJECTED, $invoice->fresh()->approval_status);
        $this->assertSame('مبلغٌ خاطئ', $invoice->fresh()->rejection_reason);
        $this->assertSame(0.0, Ledger::balance($this->business->id, 'payable'));
        $this->assertDatabaseHas('supplier_invoices', ['id' => $invoice->id]);
    }

    /* ---------------------------- الصلاحية ---------------------------- */

    /** المحاسبُ يكتب ولا يعتمد */
    public function test_the_one_who_writes_cannot_approve(): void
    {
        $this->actingAs($this->clerk)->post(route('admin.purchases.invoices.store'), $this->payload());
        $invoice = SupplierInvoice::firstOrFail();

        $this->actingAs($this->clerk)
            ->post(route('admin.purchases.invoices.approve', $invoice->id))->assertForbidden();

        $this->assertSame(SupplierInvoices::PENDING, $invoice->fresh()->approval_status);
        $this->assertSame(0.0, Ledger::balance($this->business->id, 'payable'));
    }

    /** و«اعتمِد الآن» لمن يملك الفعل وحده — ولا يُعتمد لمن لا يملكه */
    public function test_approve_now_obeys_the_permission(): void
    {
        $this->actingAs($this->clerk)
            ->post(route('admin.purchases.invoices.store'), $this->payload(['approve_now' => true]));
        $this->assertSame(SupplierInvoices::PENDING, SupplierInvoice::firstOrFail()->approval_status);

        $this->actingAs($this->owner)->post(route('admin.purchases.invoices.store'), $this->payload([
            'supplier_ref' => 'OWNER-1', 'approve_now' => true,
        ]))->assertSessionHasNoErrors();

        $this->assertSame(
            SupplierInvoices::APPROVED,
            SupplierInvoice::where('supplier_ref', 'OWNER-1')->firstOrFail()->approval_status,
        );
    }

    /* ------------------------- المطابقةُ الثلاثيّة ------------------------- */

    /** سندٌ بلا أمرِ شراءٍ لا يُطابَق: خدمةٌ أو شراءٌ عاجل */
    public function test_an_invoice_with_no_order_is_matched_by_default(): void
    {
        $this->actingAs($this->clerk)->post(route('admin.purchases.invoices.store'), $this->payload());

        $this->assertSame(SupplierInvoices::MATCHED, SupplierInvoice::firstOrFail()->match_status);
    }

    /** والمطابقُ يُعتمد بلا سبب: أمرٌ استُلم كاملًا وسندٌ بقيمته */
    public function test_a_fully_received_order_matches_and_approves_plainly(): void
    {
        $po = $this->order();
        $this->receiveAndApprove($po, 100);

        $this->actingAs($this->clerk)->post(route('admin.purchases.invoices.store'),
            $this->payload(['purchase_order_id' => $po->id]));
        $invoice = SupplierInvoice::firstOrFail();

        $this->assertSame(SupplierInvoices::MATCHED, $invoice->match_status);

        $this->actingAs($this->owner)->post(route('admin.purchases.invoices.approve', $invoice->id))
            ->assertSessionHasNoErrors();

        $this->assertSame(900.0, Ledger::balance($this->business->id, 'payable'));
    }

    /**
     * سندٌ أعلى من أمره يُمنع — ويقول بكم.
     *
     * وهو أشيعُ ما يُدفع بلا حقّ: يرفع المورّد السعر بلا اتّفاق، أو يُدخل
     * المحاسب رقمًا زائدًا، فيمرّ ويُسدَّد.
     */
    public function test_an_invoice_higher_than_its_order_is_blocked_and_says_by_how_much(): void
    {
        $po = $this->order(total: 900);
        $this->receiveAndApprove($po, 100);

        $this->actingAs($this->clerk)->post(route('admin.purchases.invoices.store'),
            $this->payload(['purchase_order_id' => $po->id, 'subtotal' => 912.5]));

        $invoice = SupplierInvoice::firstOrFail();

        $this->assertSame(SupplierInvoices::BLOCKED, $invoice->match_status);
        $this->assertStringContainsString('12.500', (string) $invoice->match_notes);

        // ولا يُعتمد بلا سبب — والذمّةُ لا تنشأ
        $this->actingAs($this->owner)->post(route('admin.purchases.invoices.approve', $invoice->id))
            ->assertSessionHasErrors('approve');

        $this->assertSame(SupplierInvoices::PENDING, $invoice->fresh()->approval_status);
        $this->assertSame(0.0, Ledger::balance($this->business->id, 'payable'));
    }

    /** وسندٌ كاملٌ على استلامٍ ناقص يُمنع — ويقول كم وصل */
    public function test_a_full_invoice_on_a_short_receipt_is_blocked(): void
    {
        $po = $this->order(total: 900);
        $this->receiveAndApprove($po, 90);

        $this->actingAs($this->clerk)->post(route('admin.purchases.invoices.store'),
            $this->payload(['purchase_order_id' => $po->id]));

        $invoice = SupplierInvoice::firstOrFail();

        $this->assertSame(SupplierInvoices::BLOCKED, $invoice->match_status);
        $this->assertStringContainsString('90', (string) $invoice->match_notes);
    }

    /** وسندٌ بلا استلامٍ معتمَد يُمنع: يُطالَب المتجر بما لم يصله */
    public function test_an_invoice_before_any_approved_receipt_is_blocked(): void
    {
        $po = $this->order(total: 900);

        // استلامٌ كُتب ولم يُعتمد لا يُحسب واصلًا — وإلّا صار طريقًا لتمرير سند
        $this->actingAs($this->clerk)->post(route('admin.purchases.receive', $po->id));

        $this->actingAs($this->clerk)->post(route('admin.purchases.invoices.store'),
            $this->payload(['purchase_order_id' => $po->id]));

        $this->assertSame(SupplierInvoices::BLOCKED, SupplierInvoice::firstOrFail()->match_status);
    }

    /** وسندٌ أقلّ من أمره تنبيهٌ لا منع: شحنةٌ ناقصة تُفوتَر بما وصل */
    public function test_an_invoice_lower_than_its_order_is_only_a_warning(): void
    {
        $po = $this->order(total: 900);
        $this->receiveAndApprove($po, 100);

        $this->actingAs($this->clerk)->post(route('admin.purchases.invoices.store'),
            $this->payload(['purchase_order_id' => $po->id, 'subtotal' => 800]));

        $invoice = SupplierInvoice::firstOrFail();
        $this->assertSame(SupplierInvoices::WARNING, $invoice->match_status);

        // والتنبيهُ يُعتمد بلا تجاوز
        $this->actingAs($this->owner)->post(route('admin.purchases.invoices.approve', $invoice->id))
            ->assertSessionHasNoErrors();

        $this->assertSame(800.0, Ledger::balance($this->business->id, 'payable'));
    }

    /* ---------------------------- التجاوز ---------------------------- */

    /** والممنوعُ يُعتمد بتجاوزٍ بسببٍ يُكتب ويُنسب — لا صامتًا */
    public function test_a_blocked_invoice_needs_a_written_override(): void
    {
        $po = $this->order(total: 900);
        $this->receiveAndApprove($po, 100);

        $this->actingAs($this->clerk)->post(route('admin.purchases.invoices.store'),
            $this->payload(['purchase_order_id' => $po->id, 'subtotal' => 950]));
        $invoice = SupplierInvoice::firstOrFail();

        $this->actingAs($this->owner)->post(route('admin.purchases.invoices.approve', $invoice->id), [
            'override_reason' => 'وافقتُ على رفع السعر هاتفيًّا',
        ])->assertSessionHasNoErrors();

        $fresh = $invoice->fresh();

        $this->assertSame(SupplierInvoices::APPROVED, $fresh->approval_status);
        $this->assertSame('وافقتُ على رفع السعر هاتفيًّا', $fresh->override_reason);
        $this->assertSame($this->owner->id, (int) $fresh->override_by);
        $this->assertNotNull($fresh->override_at);
        $this->assertSame(950.0, Ledger::balance($this->business->id, 'payable'));
    }

    /**
     * والتجاوزُ ليس لكلّ من يعتمد.
     *
     * مديرُ الفرع يعتمد السنداتِ المطابِقة يوميًّا؛ والدفعُ عن بضاعةٍ لم تصل
     * قرارُ من يملك المال وحده.
     */
    public function test_a_manager_may_approve_but_not_override(): void
    {
        $manager = User::create([
            'business_id' => $this->business->id, 'name' => 'المدير', 'email' => 'm@abaad.om',
            'password' => bcrypt('password'), 'role' => 'manager', 'status' => 'نشط',
        ]);

        $po = $this->order(total: 900);
        $this->receiveAndApprove($po, 100);

        $this->actingAs($this->clerk)->post(route('admin.purchases.invoices.store'),
            $this->payload(['purchase_order_id' => $po->id, 'subtotal' => 950]));
        $invoice = SupplierInvoice::firstOrFail();

        $this->actingAs($manager)->post(route('admin.purchases.invoices.approve', $invoice->id), [
            'override_reason' => 'أوافق',
        ])->assertSessionHasErrors('approve');

        $this->assertSame(SupplierInvoices::PENDING, $invoice->fresh()->approval_status);
        $this->assertSame(0.0, Ledger::balance($this->business->id, 'payable'));

        // والمالكُ يملكه
        $this->actingAs($this->owner)->post(route('admin.purchases.invoices.approve', $invoice->id), [
            'override_reason' => 'أوافق',
        ])->assertSessionHasNoErrors();

        $this->assertSame(SupplierInvoices::APPROVED, $invoice->fresh()->approval_status);
    }

    /* ---------------------------- السداد ---------------------------- */

    /** ولا سدادَ على سندٍ لم يُعتمد: مالٌ يخرج مقابل دَينٍ لا وجود له */
    public function test_an_unapproved_invoice_cannot_be_paid(): void
    {
        $this->actingAs($this->clerk)->post(route('admin.purchases.invoices.store'), $this->payload());
        $invoice = SupplierInvoice::firstOrFail();

        $this->actingAs($this->owner)->post(route('admin.purchases.invoices.pay', $invoice->id), [
            'amount' => 100, 'paid_at' => now()->toDateString(), 'from' => 'cash',
        ])->assertSessionHasErrors('amount');

        $this->assertSame(0.0, (float) $invoice->fresh()->paid);
        $this->assertSame(0.0, Ledger::balance($this->business->id, 'cash'));
    }

    /* ------------------------ حسابُ الإجمالي ------------------------ */

    /** والإجماليُّ يُحسب من طرفيه عند الاعتماد: عمودٌ عُبث به لا يُرحَّل */
    public function test_a_tampered_total_is_not_posted(): void
    {
        $this->actingAs($this->clerk)->post(route('admin.purchases.invoices.store'), $this->payload());
        $invoice = SupplierInvoice::firstOrFail();

        // كما لو عُبث بالصفّ مباشرةً — أو استُعيد من نسخةٍ فيها خلل
        $invoice->update(['total' => 9000]);

        $this->actingAs($this->owner)->post(route('admin.purchases.invoices.approve', $invoice->id))
            ->assertSessionHasErrors('approve');

        $this->assertSame(0.0, Ledger::balance($this->business->id, 'payable'));
    }

    /* ------------------------- حدّ المتجر ------------------------- */

    /** وسندُ متجرٍ آخر لا يُعتمد من هنا */
    public function test_a_neighbours_invoice_is_out_of_reach(): void
    {
        $other = Business::create(['name' => 'الجار', 'type' => 'عام', 'status' => 'نشط']);
        $theirSupplier = Supplier::create(['business_id' => $other->id, 'name' => 'مورّدهم']);
        $theirs = SupplierInvoice::create([
            'business_id' => $other->id, 'supplier_id' => $theirSupplier->id,
            'supplier_ref' => 'X-1', 'issued_at' => now()->toDateString(),
            'subtotal' => 100, 'total' => 100, 'approval_status' => SupplierInvoices::PENDING,
        ]);

        $this->actingAs($this->owner)
            ->post(route('admin.purchases.invoices.approve', $theirs->id))->assertNotFound();

        $this->assertSame(SupplierInvoices::PENDING, $theirs->fresh()->approval_status);
    }

    /** والطابورُ يُعدّ في الشاشة، والأزرارُ تتبع الصلاحية */
    public function test_the_screen_counts_the_queue_and_follows_the_permission(): void
    {
        $this->actingAs($this->clerk)->post(route('admin.purchases.invoices.store'), $this->payload());

        $this->actingAs($this->owner)->get(route('admin.purchases.invoices'))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->where('pendingCount', 1)
                ->where('canApprove', true)
                ->where('canOverride', true));

        $this->actingAs($this->clerk)->get(route('admin.purchases.invoices'))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->where('canApprove', false)->where('canOverride', false));
    }

    /** ومن مُنح الاعتماد باسمه يعتمد */
    public function test_a_clerk_granted_the_action_may_approve(): void
    {
        $this->clerk->update(['permissions' => ['purchases', 'finance', Permissions::INVOICE_APPROVE]]);

        $this->actingAs($this->clerk)->post(route('admin.purchases.invoices.store'), $this->payload());
        $invoice = SupplierInvoice::firstOrFail();

        $this->actingAs($this->clerk)->post(route('admin.purchases.invoices.approve', $invoice->id))
            ->assertSessionHasNoErrors();

        $this->assertSame(900.0, Ledger::balance($this->business->id, 'payable'));
    }

    /** واستلامٌ معلَّقٌ لا يُقرأ واصلًا في المطابقة */
    public function test_a_pending_receipt_does_not_count_as_arrived(): void
    {
        $po = $this->order(total: 900);
        $this->actingAs($this->clerk)->post(route('admin.purchases.receive', $po->id));

        $this->assertSame(0.0, SupplierInvoices::receivedValue($po->id));

        $this->approvePendingReceipts($this->business->id, $this->owner);

        $this->assertSame(900.0, SupplierInvoices::receivedValue($po->id));
    }

    /** ووحدةُ المطابقة تُقاس بالخدمة لا بالشاشة — فتُسأل مباشرةً */
    public function test_a_supplier_mismatch_blocks(): void
    {
        $other = Supplier::create(['business_id' => $this->business->id, 'name' => 'مورّدٌ آخر']);
        $po = $this->order();
        $this->receiveAndApprove($po, 100);

        $invoice = SupplierInvoice::create([
            'business_id' => $this->business->id, 'supplier_id' => $other->id,
            'purchase_order_id' => $po->id, 'supplier_ref' => 'Z-1',
            'issued_at' => now()->toDateString(), 'subtotal' => 900, 'total' => 900,
            'approval_status' => SupplierInvoices::PENDING,
        ]);

        $match = SupplierInvoices::match($invoice);

        $this->assertSame(SupplierInvoices::BLOCKED, $match['status']);
        $this->assertContains(__('السند لمورّدٍ غير مورّد أمر الشراء'), $match['notes']);
    }
}
