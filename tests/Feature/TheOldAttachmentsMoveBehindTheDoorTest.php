<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Expense;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use App\Support\Ledger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * المرفقاتُ القديمة تنتقل خلف الباب.
 *
 * فواتيرُ المصروفات وإيصالاتُ دفع أوامر الشراء كانت على القرص العامّ. و
 * `public/storage` وصلةٌ يخدمها الخادمُ مباشرةً: لا Laravel يُستدعى ولا
 * جلسةَ يُسأل عنها — من عرف المسار قرأ الملفّ، مسجّلًا كان أو غير مسجّل،
 * من هذا المتجر أو من غيره. وفاتورةُ مصروفٍ تقول كم يدفع المتجر ولمن،
 * وإيصالُ دفعٍ يقول بكم اشترى وممّن.
 */
class TheOldAttachmentsMoveBehindTheDoorTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('public');

        $this->business = Business::create(['name' => 'زهور مسقط', 'type' => 'عام', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);
        Ledger::seedChart($this->business->id);
        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
    }

    private function migration(): object
    {
        return require database_path('migrations/2026_09_08_140000_the_old_attachments_move_behind_the_door.php');
    }

    /* ==================== المصروف ==================== */

    /**
     * المرفوعُ اليومَ يذهب إلى القرص الخاصّ — ولا أثرَ له على العامّ.
     *
     * والاختباران معًا: وجودُه هنا لا يكفي إن بقيت نسخةٌ هناك.
     */
    public function test_a_new_expense_invoice_lands_on_the_private_disk(): void
    {
        $this->actingAs($this->owner)->post(route('admin.expenses.store'), [
            'type' => 'إيجار', 'amount' => 300, 'status' => 'غير مدفوع',
            'attachment' => UploadedFile::fake()->create('فاتورة الإيجار.pdf', 40, 'application/pdf'),
        ])->assertSessionHasNoErrors();

        $expense = Expense::firstOrFail();

        $this->assertNotNull($expense->attachment);
        $this->assertStringStartsWith('expenses/', $expense->attachment);
        Storage::disk('local')->assertExists($expense->attachment);
        Storage::disk('public')->assertMissing($expense->attachment);
        // والاسمُ كما سمّاه صاحبُه يبقى، والمخزَّنُ عشوائيّ
        $this->assertSame('فاتورة الإيجار.pdf', $expense->attachment_name);
        $this->assertStringNotContainsString('الإيجار', $expense->attachment);
    }

    /** والشاشةُ تعطي بابًا يسأل، لا مسارًا على `public/storage` */
    public function test_the_expenses_screen_hands_out_a_door_not_a_path(): void
    {
        $expense = $this->expenseWithFile();

        $this->actingAs($this->owner)->get(route('admin.expenses.index'))
            ->assertInertia(fn ($p) => $p->where(
                'expenses.0.attachment',
                route('admin.expenses.attachment', $expense->id),
            )->etc());
    }

    /** ومن لا يملك فتحَ المرفقات لا يُرسم له رابط — أيقونةٌ تُردّ أسوأ من غيابها */
    public function test_the_screen_draws_no_link_for_who_may_not_open_it(): void
    {
        $this->expenseWithFile();

        $blind = User::create([
            'business_id' => $this->business->id, 'name' => 'موظّف', 'email' => 'e@abaad.om',
            'password' => bcrypt('password'), 'role' => 'employee', 'status' => 'نشط',
            'permissions' => ['expenses'],
        ]);

        $this->actingAs($blind)->get(route('admin.expenses.index'))
            ->assertInertia(fn ($p) => $p->where('expenses.0.attachment', null)->etc());
    }

    /** والبابُ يسأل عن الفعل ثمّ عن المتجر */
    public function test_the_expense_door_asks_before_it_opens(): void
    {
        $expense = $this->expenseWithFile();

        $blind = User::create([
            'business_id' => $this->business->id, 'name' => 'موظّف', 'email' => 'b@abaad.om',
            'password' => bcrypt('password'), 'role' => 'employee', 'status' => 'نشط',
            'permissions' => ['expenses'],
        ]);

        $this->actingAs($blind)
            ->get(route('admin.expenses.attachment', $expense->id))->assertForbidden();

        $this->actingAs($this->owner)
            ->get(route('admin.expenses.attachment', $expense->id))->assertOk();
    }

    /** ومرفقُ الجار لا يُفتح برقمٍ يُكتب في العنوان */
    public function test_a_neighbour_cannot_read_the_expense_by_its_number(): void
    {
        $other = Business::create(['name' => 'متجر آخر', 'type' => 'عام', 'status' => 'نشط']);
        $theirs = Expense::create([
            'business_id' => $other->id, 'reference' => 'EXP-9', 'type' => 'إيجار',
            'amount' => 500, 'status' => 'مدفوع', 'spent_at' => now(),
            'attachment' => 'expenses/'.$other->id.'/x.pdf', 'attachment_name' => 'سرّ.pdf',
        ]);
        Storage::disk('local')->put($theirs->attachment, 'محتوى');

        $this->actingAs($this->owner)
            ->get(route('admin.expenses.attachment', $theirs->id))->assertNotFound();
    }

    /* ==================== إيصالُ أمر الشراء ==================== */

    /** وإيصالُ الدفع مثلُه: خاصٌّ، وبابُه غيرُ باب ورقة الشحنة */
    public function test_a_purchase_payment_receipt_lands_on_the_private_disk(): void
    {
        $po = $this->order();

        $this->actingAs($this->owner)->post(route('admin.purchases.receipt', $po->id), [
            'receipt' => UploadedFile::fake()->create('إيصال.pdf', 20, 'application/pdf'),
        ])->assertSessionHasNoErrors();

        $path = $po->fresh()->receipt;

        $this->assertStringStartsWith('purchase-receipts/', $path);
        Storage::disk('local')->assertExists($path);
        Storage::disk('public')->assertMissing($path);

        $this->actingAs($this->owner)
            ->get(route('admin.purchases.receiptFile', $po->id))->assertOk();
    }

    /** وبابُه يسأل كما يسأل غيرُه */
    public function test_the_purchase_receipt_door_asks_before_it_opens(): void
    {
        $po = $this->order();
        $po->update(['receipt' => 'purchase-receipts/1/a.pdf', 'receipt_name' => 'إيصال.pdf']);
        Storage::disk('local')->put($po->receipt, 'محتوى');

        $blind = User::create([
            'business_id' => $this->business->id, 'name' => 'موظّف', 'email' => 'p@abaad.om',
            'password' => bcrypt('password'), 'role' => 'employee', 'status' => 'نشط',
            'permissions' => ['purchases'],
        ]);

        $this->actingAs($blind)
            ->get(route('admin.purchases.receiptFile', $po->id))->assertForbidden();
        $this->actingAs($this->owner)
            ->get(route('admin.purchases.receiptFile', $po->id))->assertOk();

        // ولا يُرسم له رابطٌ في الشاشة أصلًا
        $this->actingAs($blind)->get(route('admin.purchases.orders'))
            ->assertInertia(fn ($p) => $p->where('orders.0.receipt', null)->etc());

        $this->actingAs($this->owner)->get(route('admin.purchases.orders'))
            ->assertInertia(fn ($p) => $p->where(
                'orders.0.receipt',
                route('admin.purchases.receiptFile', $po->id),
            )->etc());
    }

    /* ==================== النقل ==================== */

    /** والقديمُ ينتقل: يُنسخ إلى الخاصّ، ويُرفع عن العامّ */
    public function test_the_migration_moves_what_was_already_there(): void
    {
        Storage::disk('public')->put('expenses/1/old.pdf', 'فاتورةٌ قديمة');
        Storage::disk('public')->put('purchase-receipts/1/old.pdf', 'إيصالٌ قديم');

        $this->migration()->up();

        Storage::disk('local')->assertExists('expenses/1/old.pdf');
        Storage::disk('local')->assertExists('purchase-receipts/1/old.pdf');
        Storage::disk('public')->assertMissing('expenses/1/old.pdf');
        Storage::disk('public')->assertMissing('purchase-receipts/1/old.pdf');
        // والمحتوى هو هو: نقلٌ لا إنشاءُ ملفٍّ فارغ باسمه
        $this->assertSame('فاتورةٌ قديمة', Storage::disk('local')->get('expenses/1/old.pdf'));
    }

    /**
     * وما لا يخصّ المال يبقى عامًّا.
     *
     * صورُ المنتجات والشعارات تُعرض لزوّار المتجر ولا حسابَ لهم، ونقلُها
     * خلف بابٍ يسأل عن الجلسة يُطفئ صورَ المتجر على الإنترنت.
     */
    public function test_the_shop_windows_stay_open(): void
    {
        Storage::disk('public')->put('products/1/photo.jpg', 'صورة');
        Storage::disk('public')->put('logos/1/logo.png', 'شعار');

        $this->migration()->up();

        Storage::disk('public')->assertExists('products/1/photo.jpg');
        Storage::disk('public')->assertExists('logos/1/logo.png');
        Storage::disk('local')->assertMissing('products/1/photo.jpg');
    }

    /** ونصفُ نقلةٍ تُكمَّل ولا تُفسد: الهجرةُ تُعاد فلا تكتب فوق ما وصل */
    public function test_running_the_move_twice_is_safe(): void
    {
        Storage::disk('public')->put('expenses/1/a.pdf', 'أ');
        Storage::disk('local')->put('expenses/1/b.pdf', 'ب — وصلت قبلُ');
        Storage::disk('public')->put('expenses/1/b.pdf', 'ب — نسخةٌ قديمة');

        $this->migration()->up();
        $this->migration()->up();

        $this->assertSame('أ', Storage::disk('local')->get('expenses/1/a.pdf'));
        $this->assertSame('ب — وصلت قبلُ', Storage::disk('local')->get('expenses/1/b.pdf'));
        Storage::disk('public')->assertMissing('expenses/1/b.pdf');
    }

    /** والتراجعُ يعيدها — وإلّا صار الرجوعُ عن الإصدار فقدًا للملفّات */
    public function test_rolling_back_returns_the_files(): void
    {
        Storage::disk('public')->put('expenses/1/old.pdf', 'فاتورة');

        $this->migration()->up();
        $this->migration()->down();

        Storage::disk('public')->assertExists('expenses/1/old.pdf');
        Storage::disk('local')->assertMissing('expenses/1/old.pdf');
    }

    /**
     * والمحوُ يتبع الملفَّ إلى قرصه الجديد.
     *
     * كان المحوُ يقصد القرص العامّ وحده، فمصروفٌ يُمحى صفُّه تبقى فاتورتُه
     * على القرص الخاصّ بلا صفٍّ يشير إليها: ملفٌّ لا يُقرأ ولا يُمحى، ولا
     * يظهر إلّا بعدّ ما على القرص.
     */
    public function test_purging_an_expense_takes_its_invoice_with_it(): void
    {
        $expense = $this->expenseWithFile();
        $path = $expense->attachment;
        $expense->delete();

        $this->actingAs($this->owner)
            ->delete(route('admin.expenses.purge', $expense->id))->assertSessionHasNoErrors();

        Storage::disk('local')->assertMissing($path);
    }

    /* ==================== حارسٌ على المصدر ==================== */

    /**
     * ولا مستندَ ماليٍّ يُكتب على القرص العامّ بعد اليوم.
     *
     * هذا حارسٌ على الشيفرة لا على السلوك: شاشةٌ تُضاف غدًا تنسخ سطرَ رفعٍ
     * من جارتها، فتعود الفاتورةُ إلى `public/storage` بلا أن يسقط اختبار.
     * ونقطةُ الأثر تحرس نفسها.
     */
    public function test_no_financial_upload_writes_to_the_public_disk(): void
    {
        $files = [
            'app/Http/Controllers/Admin/ExpenseController.php',
            'app/Http/Controllers/Admin/PurchaseOrderController.php',
            'app/Http/Controllers/Admin/Purchasing/SupplierInvoiceController.php',
        ];

        foreach ($files as $file) {
            $source = file_get_contents(base_path($file));

            $this->assertStringNotContainsString("'public')", $source, $file);
            $this->assertStringNotContainsString('Storage::url(', $source, $file);
        }
    }

    /* ==================== أدواتٌ ==================== */

    private function expenseWithFile(): Expense
    {
        $expense = Expense::create([
            'business_id' => $this->business->id, 'reference' => 'EXP-1', 'type' => 'إيجار',
            'amount' => 300, 'status' => 'مدفوع', 'spent_at' => now(),
            'attachment' => 'expenses/'.$this->business->id.'/a.pdf',
            'attachment_name' => 'فاتورة.pdf',
        ]);
        Storage::disk('local')->put($expense->attachment, 'محتوى');

        return $expense;
    }

    private function order(): PurchaseOrder
    {
        $supplier = Supplier::create(['business_id' => $this->business->id, 'name' => 'ورد الخليج']);

        return PurchaseOrder::create([
            'business_id' => $this->business->id,
            'branch_id' => Branch::where('business_id', $this->business->id)->value('id'),
            'number' => 'PO-000125', 'supplier_id' => $supplier->id,
            'supplier_name' => $supplier->name, 'status' => 'مُرسل',
            'total' => 100, 'ordered_at' => now(),
        ]);
    }
}
