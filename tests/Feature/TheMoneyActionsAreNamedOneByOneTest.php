<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\GoodsReceiptNote;
use App\Models\PayrollRun;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\User;
use App\Support\Demo;
use App\Support\GoodsReceipts;
use App\Support\Ledger;
use App\Support\Permissions;
use App\Support\SupplierInvoices;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * أفعالُ المال تُسمّى واحدًا واحدًا.
 *
 * كان مفتاحٌ واحدٌ يجمع ثلاثة قرارات: من مُنح «المشتريات» يكتب السند، ويقرّ
 * بأنّ المتجر مدينٌ به، ويُخرج المال مقابله. وهي في المتاجر ثلاثةُ أشخاص.
 * وكذلك الرواتب: من مُنح «الرواتب والموظفين» ليصحّح مسمّى موظّفٍ كان يقرأ
 * رواتب الجميع ويعتمد المسيرة ويصرفها.
 *
 * ولكلّ حارسٍ هنا وجهان يُختبران معًا: البابُ يُغلق على من لا يملك، ويُفتح
 * لمن يملك. وحارسٌ يُختبر بنصفه الأوّل وحدَه يمرّ وهو مغلقٌ في وجه الجميع.
 */
class TheMoneyActionsAreNamedOneByOneTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

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
        $this->supplier = Supplier::create(['business_id' => $this->business->id, 'name' => 'ورد الخليج']);
        $this->product = Product::create([
            'business_id' => $this->business->id, 'name' => 'باقة',
            'price' => 10, 'cost' => 4, 'quantity' => 0,
        ]);
    }

    /** موظّفٌ بقائمةٍ يدوية — لا يملك إلا ما يُكتب له بحرفه */
    private function staff(array $permissions): User
    {
        return User::create([
            'business_id' => $this->business->id,
            'name' => 'موظّف', 'email' => 'e'.random_int(1000, 999999).'@abaad.om',
            'password' => bcrypt('password'), 'role' => 'employee', 'status' => 'نشط',
            'permissions' => $permissions,
        ]);
    }

    private function order(int $qty = 100, float $cost = 9): PurchaseOrder
    {
        $po = PurchaseOrder::create([
            'business_id' => $this->business->id,
            'branch_id' => Branch::where('business_id', $this->business->id)->value('id'),
            'number' => 'PO-'.random_int(1000, 9999),
            'supplier_id' => $this->supplier->id, 'supplier_name' => $this->supplier->name,
            'status' => 'مُرسل', 'total' => $qty * $cost, 'ordered_at' => now(),
        ]);
        $po->items()->create([
            'product_id' => $this->product->id, 'name' => $this->product->name,
            'cost' => $cost, 'quantity' => $qty,
        ]);

        return $po->refresh();
    }

    private function pendingNote(): GoodsReceiptNote
    {
        $po = $this->order();

        return GoodsReceipts::record($po, [$po->items()->first()->id => 10], [], $this->owner);
    }

    private function invoice(string $status = SupplierInvoices::PENDING): SupplierInvoice
    {
        return SupplierInvoice::create([
            'business_id' => $this->business->id,
            'supplier_id' => $this->supplier->id,
            'supplier_ref' => 'S-'.random_int(1000, 999999),
            'issued_at' => now()->toDateString(),
            'subtotal' => 100, 'tax' => 0, 'total' => 100, 'paid' => 0,
            'status' => 'غير مدفوع', 'approval_status' => $status,
        ]);
    }

    /* ==================== القائمةُ نفسها ==================== */

    /**
     * كلُّ فعلٍ في الجدول له اسمٌ يُعرض ودورٌ يملكه — ومالكُ المتجر منهم.
     *
     * فعلٌ يُضاف بلا صفٍّ في `ACTION_ROLES` لا يملكه أحد: لا المالك ولا
     * المدير، لأنّ `'*'` لا يمتدّ إلى الأفعال. فيصير البابُ مغلقًا على
     * صاحب المتجر في متجره، ولا يفتحه إلا منحٌ يدويٌّ لا يعرف أنه يحتاجه.
     */
    public function test_every_action_has_a_label_and_an_owner(): void
    {
        foreach (Permissions::actions() as $action) {
            $this->assertNotEmpty(Permissions::ACTIONS[$action] ?? '', $action);
            $this->assertContains(
                'admin',
                Permissions::ACTION_ROLES[$action] ?? [],
                "الفعل {$action} لا يملكه صاحبُ المتجر",
            );
        }
    }

    /** وما تمنحه الترقيةُ فعلٌ قائم — لا اسمٌ ماتَ في قائمةٍ لم تُحدَّث */
    public function test_the_upgrade_grants_only_actions_that_exist(): void
    {
        foreach (Permissions::LEGACY_SECTION_ACTIONS as $section => $actions) {
            $this->assertContains($section, Permissions::SECTIONS, $section);

            foreach ($actions as $action) {
                $this->assertArrayHasKey($action, Permissions::ACTIONS, $action);
            }
        }
    }

    /**
     * والترقيةُ تمنح من خُصِّصت صلاحياتُه ما كان يفعله أمسِ — ولا تمسّ الوارث.
     *
     * `NULL` تعني «اتبع الدور»؛ وكتابةُ قائمةٍ فوقها تقطع الوراثة، فيصير
     * الموظّف محبوسًا في ما كُتب له يوم الهجرة ولا يبلغه فعلٌ يُضاف غدًا.
     */
    public function test_the_upgrade_grants_what_the_section_used_to_allow(): void
    {
        $manual = $this->staff(['purchases', 'employees']);
        $inherits = User::create([
            'business_id' => $this->business->id, 'name' => 'وارث', 'email' => 'h@abaad.om',
            'password' => bcrypt('password'), 'role' => 'manager', 'status' => 'نشط',
        ]);

        DB::table('users')->where('id', $manual->id)
            ->update(['permissions' => json_encode(['purchases', 'employees'], JSON_UNESCAPED_UNICODE)]);

        (require database_path('migrations/2026_09_08_120000_the_money_actions_are_named_one_by_one.php'))->up();

        $after = $manual->fresh();
        $this->assertTrue($after->may(Permissions::INVOICE_CREATE));
        $this->assertTrue($after->may(Permissions::INVOICE_PAY));
        $this->assertTrue($after->may(Permissions::PAYROLL_VIEW));
        $this->assertTrue($after->may(Permissions::PAYROLL_PAY));
        // ولا يُمنح ما لم يكن قسمُه يبيحه: الاعتمادُ أُفرد فعلًا من قبل
        $this->assertFalse($after->may(Permissions::INVOICE_APPROVE));
        $this->assertFalse($after->may(Permissions::RECEIPT_APPROVE));
        // ولا قسمًا لم يملكه
        $this->assertFalse($after->allows('finance'));

        $this->assertNull($inherits->fresh()->permissions);
    }

    /* ==================== إشعارُ الاستلام ==================== */

    /** شاشةُ الاستلامات تحمل تكلفةَ كلّ صنف — فقراءتُها تُمنح باسمها */
    public function test_the_receipts_screen_asks_for_its_own_action(): void
    {
        $this->actingAs($this->staff(['inventory']))
            ->get(route('admin.inventory.receipts'))->assertForbidden();

        $this->actingAs($this->staff(['inventory', Permissions::RECEIPT_VIEW]))
            ->get(route('admin.inventory.receipts'))->assertOk();
    }

    /** وكتابةُ الاستلام فعلٌ غيرُ قراءته */
    public function test_writing_a_receipt_asks_for_its_own_action(): void
    {
        $po = $this->order();
        $line = $po->items()->first()->id;

        $this->actingAs($this->staff(['purchases']))
            ->post(route('admin.purchases.receive', $po->id), ['items' => [['id' => $line, 'quantity' => 5]]])
            ->assertForbidden();

        $this->assertSame(0, GoodsReceiptNote::count());

        $this->actingAs($this->staff(['purchases', Permissions::RECEIPT_CREATE]))
            ->post(route('admin.purchases.receive', $po->id), ['items' => [['id' => $line, 'quantity' => 5]]])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, GoodsReceiptNote::count());
    }

    /**
     * والرفضُ نصفُ المراجعة الآمن: يُمنح لمن لا يُمنح الاعتماد.
     *
     * ورقةٌ تُرفض لا تُحرّك رفًّا ولا تكتب قيدًا، وورقةٌ تُعتمد تفعل الأمرين.
     */
    public function test_rejecting_a_receipt_is_not_approving_it(): void
    {
        // والقسمُ يُمنح للاثنين: الحارسُ المختبَر هو الفعل لا الباب
        $rejector = $this->staff(['inventory', 'purchases', Permissions::RECEIPT_REJECT]);
        $approver = $this->staff(['inventory', 'purchases', Permissions::RECEIPT_APPROVE]);

        $note = $this->pendingNote();
        $this->actingAs($rejector)
            ->post(route('admin.purchases.receipts.approve', $note->id))->assertForbidden();
        $this->assertSame(GoodsReceipts::PENDING, $note->fresh()->status);

        $this->actingAs($approver)
            ->post(route('admin.purchases.receipts.reject', $note->id), ['reason' => 'ناقصة'])
            ->assertForbidden();
        $this->assertSame(GoodsReceipts::PENDING, $note->fresh()->status);

        $this->actingAs($rejector)
            ->post(route('admin.purchases.receipts.reject', $note->id), ['reason' => 'ناقصة'])
            ->assertSessionHasNoErrors();
        $this->assertSame(GoodsReceipts::REJECTED, $note->fresh()->status);
    }

    /* ==================== سندُ المورّد ==================== */

    /** شاشةُ السندات تقول ما على المتجر وبكم اشترى — وقراءتُها تُمنح */
    public function test_the_supplier_invoice_screen_asks_for_its_own_action(): void
    {
        $this->actingAs($this->staff(['purchases']))
            ->get(route('admin.purchases.invoices'))->assertForbidden();

        $this->actingAs($this->staff(['purchases', Permissions::INVOICE_VIEW]))
            ->get(route('admin.purchases.invoices'))->assertOk();
    }

    /** وإدخالُ السند فعلٌ غيرُ قراءته */
    public function test_writing_a_supplier_invoice_asks_for_its_own_action(): void
    {
        $payload = [
            'supplier_id' => $this->supplier->id, 'supplier_ref' => 'S-100',
            'issued_at' => now()->toDateString(), 'subtotal' => 100, 'tax' => 0,
        ];

        $this->actingAs($this->staff(['purchases', Permissions::INVOICE_VIEW]))
            ->post(route('admin.purchases.invoices.store'), $payload)->assertForbidden();
        $this->assertSame(0, SupplierInvoice::count());

        $this->actingAs($this->staff(['purchases', Permissions::INVOICE_CREATE]))
            ->post(route('admin.purchases.invoices.store'), $payload)->assertSessionHasNoErrors();
        $this->assertSame(1, SupplierInvoice::count());
    }

    /** ورفعُ ورقةٍ لم تُعتمد من عمل من كتبها */
    public function test_removing_an_unapproved_invoice_asks_for_the_writing_action(): void
    {
        $invoice = $this->invoice();

        $this->actingAs($this->staff(['purchases', Permissions::INVOICE_VIEW]))
            ->delete(route('admin.purchases.invoices.destroy', $invoice->id))->assertForbidden();
        $this->assertDatabaseHas('supplier_invoices', ['id' => $invoice->id]);

        $this->actingAs($this->staff(['purchases', Permissions::INVOICE_CREATE]))
            ->delete(route('admin.purchases.invoices.destroy', $invoice->id))->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('supplier_invoices', ['id' => $invoice->id]);
    }

    /** والرفضُ غيرُ الاعتماد هنا أيضًا */
    public function test_rejecting_a_supplier_invoice_is_not_approving_it(): void
    {
        $invoice = $this->invoice();

        $this->actingAs($this->staff(['purchases', Permissions::INVOICE_APPROVE]))
            ->post(route('admin.purchases.invoices.reject', $invoice->id), ['reason' => 'مكرّرة'])
            ->assertForbidden();
        $this->assertSame(SupplierInvoices::PENDING, $invoice->fresh()->approval_status);

        $this->actingAs($this->staff(['purchases', Permissions::INVOICE_REJECT]))
            ->post(route('admin.purchases.invoices.approve', $invoice->id))->assertForbidden();
        $this->assertSame(SupplierInvoices::PENDING, $invoice->fresh()->approval_status);

        $this->actingAs($this->staff(['purchases', Permissions::INVOICE_REJECT]))
            ->post(route('admin.purchases.invoices.reject', $invoice->id), ['reason' => 'مكرّرة'])
            ->assertSessionHasNoErrors();
        $this->assertSame(SupplierInvoices::REJECTED, $invoice->fresh()->approval_status);
    }

    /**
     * والسدادُ فعلٌ ثالث: مالٌ يخرج من الصندوق.
     *
     * وأمينُ المخزن كان يسدّده لأنّه يملك «المشتريات» — وإخراجُ المال ليس
     * من عمله.
     */
    public function test_paying_a_supplier_asks_for_its_own_action(): void
    {
        $invoice = $this->invoice(SupplierInvoices::APPROVED);
        $body = ['amount' => 100, 'paid_at' => now()->toDateString(), 'from' => 'cash'];

        $this->actingAs($this->staff(['purchases', Permissions::INVOICE_APPROVE]))
            ->post(route('admin.purchases.invoices.pay', $invoice->id), $body)->assertForbidden();
        $this->assertSame(0.0, (float) $invoice->fresh()->paid);

        $this->actingAs($this->staff(['purchases', Permissions::INVOICE_PAY]))
            ->post(route('admin.purchases.invoices.pay', $invoice->id), $body)
            ->assertSessionHasNoErrors();
        $this->assertSame(100.0, (float) $invoice->fresh()->paid);
    }

    /** وأمينُ المخزن بدوره لا يسدّد — وهو أضيقُ ممّا كان عمدًا */
    public function test_the_storekeeper_role_no_longer_pays_suppliers(): void
    {
        $keeper = User::create([
            'business_id' => $this->business->id, 'name' => 'أمين المخزن', 'email' => 'w@abaad.om',
            'password' => bcrypt('password'), 'role' => 'inventory', 'status' => 'نشط',
        ]);

        $this->assertTrue($keeper->may(Permissions::INVOICE_VIEW));
        $this->assertTrue($keeper->may(Permissions::INVOICE_CREATE));
        $this->assertFalse($keeper->may(Permissions::INVOICE_PAY));
        $this->assertFalse($keeper->may(Permissions::INVOICE_APPROVE));
    }

    /* ==================== الرواتب ==================== */

    /** راتبُ كلّ موظّفٍ في المتجر لا يُقرأ بصلاحية تصحيح مسمًّى وظيفيّ */
    public function test_the_payroll_screens_ask_for_their_own_action(): void
    {
        $this->actingAs($this->staff(['employees']))
            ->get(route('admin.payroll.index'))->assertForbidden();
        $this->actingAs($this->staff(['employees']))
            ->get(route('admin.payroll.payments'))->assertForbidden();

        $reader = $this->staff(['employees', Permissions::PAYROLL_VIEW]);
        $this->actingAs($reader)->get(route('admin.payroll.index'))->assertOk();
        $this->actingAs($reader)->get(route('admin.payroll.payments'))->assertOk();
    }

    /**
     * وحارسُ الطلب يوافق حارسَ الشاشة.
     *
     * بابٌ مغلقٌ في الشاشة مفتوحٌ في الطلب ليس بابًا: من مُنع من قراءة
     * المسيرة كان يكتبها بطلبٍ مباشر.
     */
    public function test_the_payroll_write_routes_are_shut_with_the_screen(): void
    {
        $blind = $this->staff(['employees']);

        $this->actingAs($blind)->post(route('admin.payroll.store'), [
            'period' => now()->format('Y-m'),
        ])->assertForbidden();

        $this->assertSame(0, PayrollRun::count());
    }

    /** والاعتمادُ يُنشئ الالتزام، والصرفُ يُخرج المال — فعلان لا قراءة */
    public function test_approving_and_paying_payroll_are_two_more_actions(): void
    {
        User::create([
            'business_id' => $this->business->id, 'name' => 'سالم', 'email' => 's@abaad.om',
            'password' => bcrypt('password'), 'role' => 'sales', 'status' => 'نشط',
            'basic_salary' => 300,
        ]);
        $reader = $this->staff(['employees', Permissions::PAYROLL_VIEW]);

        $this->actingAs($reader)->post(route('admin.payroll.store'), [
            'period' => now()->format('Y-m'),
        ])->assertSessionHasNoErrors();

        $run = PayrollRun::firstOrFail();
        $this->assertGreaterThan(0, $run->lines()->count(), 'المسيرة بلا سطور');

        $this->actingAs($reader)
            ->post(route('admin.payroll.approve', $run->id))->assertForbidden();
        $this->assertSame('مسودة', $run->fresh()->status);

        $approver = $this->staff(['employees', Permissions::PAYROLL_VIEW, Permissions::PAYROLL_APPROVE]);
        $this->actingAs($approver)
            ->post(route('admin.payroll.approve', $run->id))->assertSessionHasNoErrors();
        $this->assertSame('معتمدة', $run->fresh()->status);

        $line = $run->lines()->first();
        $body = ['lines' => [$line->id], 'paid_at' => now()->toDateString(), 'from' => 'cash'];

        $this->actingAs($approver)
            ->post(route('admin.payroll.pay', $run->id), $body)->assertForbidden();
        $this->assertNull($line->fresh()->paid_at);

        $payer = $this->staff(['employees', Permissions::PAYROLL_VIEW, Permissions::PAYROLL_PAY]);
        $this->actingAs($payer)
            ->post(route('admin.payroll.pay', $run->id), $body)->assertSessionHasNoErrors();
        $this->assertNotNull($line->fresh()->paid_at);
    }

    /* ==================== المرفقات ==================== */

    /** ورقةُ المورّد فيها أسعارُ الشراء — فبابُها يسأل سؤالين لا سؤالًا */
    public function test_the_attachment_door_asks_for_its_own_action(): void
    {
        $note = $this->pendingNote();
        $note->update(['attachment' => 'receipts/x.pdf', 'attachment_name' => 'ورقة.pdf']);
        $invoice = $this->invoice();
        $invoice->update(['attachment' => 'supplier-invoices/x.pdf']);

        $blind = $this->staff(['inventory', 'purchases', Permissions::RECEIPT_VIEW, Permissions::INVOICE_VIEW]);

        $this->actingAs($blind)
            ->get(route('admin.inventory.receipts.attachment', $note->id))->assertForbidden();
        $this->actingAs($blind)
            ->get(route('admin.purchases.invoices.attachment', $invoice->id))->assertForbidden();

        /*
         * ومن مُنح الفعل يعبر البابَ إلى الملفّ — و404 هنا هو الجواب الصحيح:
         * الملفُّ غيرُ موجودٍ على القرص، والمهمّ أنّ الحارس لم يعد يردّه.
         */
        $seeing = $this->staff(['inventory', 'purchases', Permissions::ATTACHMENT_VIEW]);
        $this->actingAs($seeing)
            ->get(route('admin.inventory.receipts.attachment', $note->id))->assertNotFound();
    }

    /* ==================== الواجهة ==================== */

    /** والأفعالُ تُرسل إلى الواجهة لتُخفي ما لا يُفتح */
    public function test_the_screen_is_told_which_actions_are_held(): void
    {
        $this->actingAs($this->staff(['inventory', Permissions::RECEIPT_VIEW]))
            ->get(route('admin.inventory.receipts'))
            ->assertInertia(fn ($p) => $p
                ->where('auth.mayActions', fn ($a) => collect($a)->contains(Permissions::RECEIPT_VIEW)
                    && ! collect($a)->contains(Permissions::RECEIPT_APPROVE))
                ->where('canApprove', false)
                ->where('canReject', false)
                ->where('canSeeAttachment', false)
                ->etc());
    }

    /**
     * وكلُّ شاشةٍ تقول لواجهتها أيَّ الأزرار تَرسم.
     *
     * زرٌّ يُرسم لمن يُردّ عند ضغطه أسوأ من غيابه: الموظّف يظنّ العطب في
     * النظام ويعيد المحاولة، والتاجر يظنّ أنّ صلاحياته لم تُحفظ. ولا تكفي
     * شاشةٌ واحدة تُختبر: كلُّ خانةٍ منها تُقرأ في مكانٍ من الواجهة.
     */
    public function test_each_screen_names_the_buttons_it_will_draw(): void
    {
        $this->actingAs($this->staff(['purchases', Permissions::INVOICE_VIEW, Permissions::INVOICE_PAY]))
            ->get(route('admin.purchases.invoices'))
            ->assertInertia(fn ($p) => $p
                ->where('canApprove', false)->where('canReject', false)
                ->where('canOverride', false)->where('canCreate', false)
                ->where('canPay', true)->where('canSeeAttachment', false)
                ->etc());

        // وقارئٌ لا يسدّد: لولا هذا لَمرّت خانةُ السداد وهي تقرأ مفتاح القراءة
        $this->actingAs($this->staff(['purchases', Permissions::INVOICE_VIEW]))
            ->get(route('admin.purchases.invoices'))
            ->assertInertia(fn ($p) => $p->where('canPay', false)->etc());

        $this->actingAs($this->staff(['employees', Permissions::PAYROLL_VIEW, Permissions::PAYROLL_APPROVE]))
            ->get(route('admin.payroll.index'))
            ->assertInertia(fn ($p) => $p
                ->where('canApprove', true)->where('canPay', false)->etc());

        $this->actingAs($this->staff(['employees', Permissions::PAYROLL_VIEW]))
            ->get(route('admin.payroll.payments'))
            ->assertInertia(fn ($p) => $p->where('canPay', false)->etc());
    }

    /**
     * والجرسُ لا يقود إلى بابٍ مغلق.
     *
     * من مُنح الاعتماد ولم يُمنح القراءة كان التنبيهُ يقوده إلى ٤٠٣.
     */
    public function test_the_bell_does_not_point_at_a_shut_door(): void
    {
        $this->pendingNote();

        $blind = $this->staff(['inventory', Permissions::RECEIPT_APPROVE]);
        $this->actingAs($blind);
        $this->assertEmpty($this->grnNotifications());

        $seeing = $this->staff(['inventory', Permissions::RECEIPT_APPROVE, Permissions::RECEIPT_VIEW]);
        $this->actingAs($seeing);
        $this->assertNotEmpty($this->grnNotifications());
    }

    /** @return list<array<string, mixed>> */
    private function grnNotifications(): array
    {
        return array_values(array_filter(
            Demo::notifications(),
            fn ($n) => str_starts_with((string) ($n['key'] ?? ''), 'grn-'),
        ));
    }
}
