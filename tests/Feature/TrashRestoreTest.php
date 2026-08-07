<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Expense;
use App\Models\JobTitle;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * الحذف صار يُخفي لا يمحو.
 *
 * كانت ضغطة «حذف» على منتجٍ تُذهب التكلفة والباركود والتصنيف بلا رجعة، وعلى
 * مصروفٍ تُذهب قيدًا ماليًّا بمرفقه. والسجلّ يقيّد «حذف المنتج: كذا» — يشهد
 * على الخسارة ولا يردّها.
 */
class TrashRestoreTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private Business $neighbour;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'متجري', 'type' => 'عام', 'status' => 'نشط']);
        $this->neighbour = Business::create(['name' => 'متجر الجار', 'type' => 'عام', 'status' => 'نشط']);

        foreach ([$this->business, $this->neighbour] as $b) {
            Branch::create(['business_id' => $b->id, 'name' => 'الرئيسي']);
            JobTitle::create(['business_id' => $b->id, 'name' => 'مدير', 'role' => 'admin']);
        }

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'سالم', 'email' => 'salem@abaad.om',
            'role' => 'admin', 'job_title' => 'مدير', 'status' => 'نشط', 'password' => 'x',
        ]);
    }

    private function product(?Business $for = null): Product
    {
        return Product::create([
            'business_id' => ($for ?? $this->business)->id,
            'name' => 'ورد جوري', 'sku' => 'FLW-00001', 'price' => 5, 'cost' => 2,
            'quantity' => 10, 'alert_qty' => 2, 'active' => true,
        ]);
    }

    private function expense(?Business $for = null): Expense
    {
        return Expense::create([
            'business_id' => ($for ?? $this->business)->id,
            'type' => 'إيجار', 'description' => 'إيجار المحل', 'amount' => 300,
            'reference' => 'EXP-1001', 'spent_at' => now(), 'attachment' => 'receipts/x.pdf',
        ]);
    }

    public function test_deleting_a_product_hides_it_but_keeps_the_row(): void
    {
        $product = $this->product();

        $this->actingAs($this->owner)
            ->delete(route('admin.products.destroy', $product->id))
            ->assertRedirect();

        $this->assertNull(Product::find($product->id));
        $this->assertNotNull(Product::withTrashed()->find($product->id));
    }

    public function test_a_deleted_product_can_be_brought_back(): void
    {
        $product = $this->product();
        $this->actingAs($this->owner)->delete(route('admin.products.destroy', $product->id));

        $this->actingAs($this->owner)
            ->post(route('admin.products.restore', $product->id))
            ->assertRedirect();

        $back = Product::find($product->id);
        $this->assertNotNull($back);
        // ما يعود يعود كاملًا: التكلفة والباركود والكمية، لا الاسم وحده
        $this->assertSame('FLW-00001', $back->sku);
        $this->assertSame('2.000', $back->cost);
        $this->assertSame(10, (int) $back->quantity);
    }

    public function test_a_deleted_expense_keeps_its_attachment(): void
    {
        $expense = $this->expense();
        $this->actingAs($this->owner)->delete(route('admin.expenses.destroy', $expense->id));

        $this->actingAs($this->owner)
            ->post(route('admin.expenses.restore', $expense->id));

        // مصروفٌ يعود بلا فاتورته نصفُ استعادة: لا شيء يُقدَّم للمحاسب
        $this->assertSame('receipts/x.pdf', Expense::find($expense->id)?->attachment);
    }

    public function test_the_trash_screen_lists_what_was_deleted(): void
    {
        $product = $this->product();
        $expense = $this->expense();
        $this->actingAs($this->owner)->delete(route('admin.products.destroy', $product->id));
        $this->actingAs($this->owner)->delete(route('admin.expenses.destroy', $expense->id));

        $this->actingAs($this->owner)
            ->get(route('admin.settings.trash'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Settings/Trash')
                ->has('products', 1)
                ->has('expenses', 1)
                ->where('products.0.name', 'ورد جوري'));
    }

    public function test_a_live_product_is_not_in_the_trash(): void
    {
        $this->product();

        $this->actingAs($this->owner)
            ->get(route('admin.settings.trash'))
            ->assertInertia(fn ($page) => $page->has('products', 0));
    }

    public function test_the_neighbour_cannot_restore_my_rows(): void
    {
        $mine = $this->product();
        $this->actingAs($this->owner)->delete(route('admin.products.destroy', $mine->id));

        $intruder = User::create([
            'business_id' => $this->neighbour->id, 'name' => 'جار', 'email' => 'jar@abaad.om',
            'role' => 'admin', 'job_title' => 'مدير', 'status' => 'نشط', 'password' => 'x',
        ]);

        // المتجر يُقرأ من الجلسة لا من الطلب
        $this->actingAs($intruder)
            ->post(route('admin.products.restore', $mine->id))
            ->assertNotFound();

        $this->assertNull(Product::find($mine->id));
    }

    public function test_there_is_no_generic_restore_endpoint(): void
    {
        // النوع لا يصل من المتصفّح: لكل نوعٍ مسارُه تحت حارس قسمه
        $this->actingAs($this->owner)
            ->post('/admin/settings/trash/user/1/restore')
            ->assertNotFound();
    }

    public function test_the_delete_toast_carries_an_undo_button(): void
    {
        $product = $this->product();

        /*
         * هنا تصل قيمة السلّة إلى صاحب النشاط: هي مدفونة في الإعدادات، ومن
         * حذف بالخطأ لا يعرف بوجودها — فيردّ الخطأ من الإشعار نفسه.
         */
        $this->actingAs($this->owner)
            ->delete(route('admin.products.destroy', $product->id))
            ->assertSessionHas('toast', fn ($toast) => ($toast['undo']['url'] ?? null) === route('admin.products.restore', $product->id));
    }

    public function test_the_undo_button_follows_the_section_that_deleted(): void
    {
        $product = $this->product();
        $this->actingAs($this->owner)->delete(route('admin.products.destroy', $product->id));

        /*
         * موظفٌ يملك «المنتجات» ولا يملك «الإعدادات» يردّ ما حذف.
         * لو كان المسار تحت الإعدادات لرأى زرّ «تراجع» ثم رُدَّ ٤٠٣.
         */
        $clerk = User::create([
            'business_id' => $this->business->id, 'name' => 'موظف', 'email' => 'clerk@abaad.om',
            'role' => 'cashier', 'job_title' => 'كاشير', 'status' => 'نشط', 'password' => 'x',
            'permissions' => ['products'],
        ]);

        $this->actingAs($clerk)
            ->post(route('admin.products.restore', $product->id))
            ->assertRedirect();

        $this->assertNotNull(Product::find($product->id));

        // والسلّة نفسها تبقى للإعدادات: التصفّح غير التراجع
        $this->actingAs($clerk)->get(route('admin.settings.trash'))->assertForbidden();
    }

    /* ---------------------------- الفروع ---------------------------- */

    public function test_deleting_a_branch_no_longer_wipes_its_registers(): void
    {
        $branch = Branch::create(['business_id' => $this->business->id, 'name' => 'الخوير']);
        $device = \App\Models\PosDevice::create([
            'business_id' => $this->business->id, 'branch_id' => $branch->id,
            'name' => 'صندوق ١', 'token_hash' => str_repeat('a', 64),
            'status' => \App\Models\PosDevice::ACTIVE, 'activated_at' => now(),
        ]);

        $this->actingAs($this->owner)->delete(route('admin.branches.destroy', $branch->id));

        /*
         * pos_devices.branch_id عليه ON DELETE CASCADE: الحذف النهائي كان
         * يمحو كل صندوقٍ في الفرع معه. والصفّ الباقي يمنع تسلسل الحذف.
         */
        $this->assertNull(Branch::find($branch->id));
        $this->assertNotNull(Branch::withTrashed()->find($branch->id));
        $this->assertDatabaseHas('pos_devices', ['id' => $device->id, 'branch_id' => $branch->id]);
    }

    public function test_a_deleted_branch_leaves_no_dangling_orders(): void
    {
        $branch = Branch::create(['business_id' => $this->business->id, 'name' => 'الخوير']);
        Order::create([
            'business_id' => $this->business->id, 'branch_id' => $branch->id,
            'number' => 'INV-9', 'subtotal' => 10, 'total' => 10,
            'payment_method' => 'نقدي', 'status' => 'مكتمل', 'is_held' => false, 'ordered_at' => now(),
        ]);

        $this->actingAs($this->owner)->delete(route('admin.branches.destroy', $branch->id));

        // كانت الفاتورة تبقى معلّقةً على رقمٍ لا وجود له، فتُعرض «—» إلى الأبد
        $this->assertSame('الخوير', Branch::withTrashed()->find($branch->id)?->name);
    }

    public function test_a_deleted_branch_can_be_brought_back(): void
    {
        $branch = Branch::create(['business_id' => $this->business->id, 'name' => 'الخوير', 'phone' => '2444']);
        $this->actingAs($this->owner)->delete(route('admin.branches.destroy', $branch->id));

        $this->actingAs($this->owner)
            ->post(route('admin.branches.restore', $branch->id))
            ->assertRedirect();

        $this->assertSame('2444', Branch::find($branch->id)?->phone);
    }

    public function test_the_trash_screen_does_not_list_branches(): void
    {
        $branch = Branch::create(['business_id' => $this->business->id, 'name' => 'الخوير']);
        $this->actingAs($this->owner)->delete(route('admin.branches.destroy', $branch->id));

        /*
         * حذف الفرع نادرٌ في عمر المتجر، فقسمُه يبقى فارغًا دائمًا — وجدولٌ
         * فارغ أبدًا يجعل الشاشة تُقرأ «لا شيء هنا». الحماية باقية والردّ
         * من زرّ «تراجع» في الإشعار.
         */
        $this->actingAs($this->owner)
            ->get(route('admin.settings.trash'))
            ->assertInertia(fn ($page) => $page->missing('branches'));

        $this->assertNotNull(Branch::withTrashed()->find($branch->id));
    }

    public function test_a_deleted_branch_is_still_undoable_from_the_toast(): void
    {
        $branch = Branch::create(['business_id' => $this->business->id, 'name' => 'الخوير']);

        // الطريق الوحيد لردّ الفرع بعد حذف القسم: زرّ الإشعار
        $this->actingAs($this->owner)
            ->delete(route('admin.branches.destroy', $branch->id))
            ->assertSessionHas('toast', fn ($t) => ($t['undo']['url'] ?? null) === route('admin.branches.restore', $branch->id));
    }

    public function test_a_deleted_branch_disappears_from_the_switcher(): void
    {
        $branch = Branch::create(['business_id' => $this->business->id, 'name' => 'الخوير']);
        $this->actingAs($this->owner)->delete(route('admin.branches.destroy', $branch->id));

        $this->assertFalse(
            Branch::where('business_id', $this->business->id)->where('name', 'الخوير')->exists(),
        );
    }

    /* ------------------- الاستعادة تمحو ولا تُخفي ------------------- */

    public function test_restoring_a_backup_wipes_instead_of_hiding(): void
    {
        $old = $this->product();

        $payload = [
            'meta' => ['app' => 'AbadPOS', 'version' => 2, 'business_id' => $this->business->id],
            'products' => [['name' => 'ورد جديد', 'sku' => 'NEW-1', 'price' => 9, 'quantity' => 3]],
        ];

        $this->actingAs($this->owner)->post(route('admin.backup.restore'), [
            'backup' => \Illuminate\Http\UploadedFile::fake()->createWithContent(
                'b.json', json_encode($payload, JSON_UNESCAPED_UNICODE),
            ),
        ]);

        /*
         * لولا forceDelete لبقي المنتج القديم صفًّا مخفيًّا يظهر في
         * «المحذوفات» بعد الاستعادة، فيستعيده التاجر وتنشأ نسخةٌ ثانية.
         */
        $this->assertNull(Product::withTrashed()->find($old->id));
        $this->assertSame(0, Product::onlyTrashed()->where('business_id', $this->business->id)->count());
    }

    public function test_a_deleted_product_still_names_itself_in_past_sales(): void
    {
        $product = $this->product();
        $this->actingAs($this->owner)->delete(route('admin.products.destroy', $product->id));

        // تقارير المبيعات تصل الأصناف بالمنتج بـleftJoin؛ المحو النهائي كان
        // يُفرغ اسمه من كل بيعةٍ ماضية
        $this->assertSame('ورد جوري', Product::withTrashed()->find($product->id)?->name);
    }
}
