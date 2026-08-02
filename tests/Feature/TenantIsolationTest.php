<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * العزل بين المتاجر على مسارات السجلّ الواحد.
 *
 * كل مسار يحمل {id} هو باب: إن بحث المتحكّم بالمعرّف وحده دون شرط
 * business_id، فتّاجرٌ يكتب رقمًا في شريط العنوان يقرأ — أو يحذف — سجلّ
 * جاره. القراءة كانت مغطّاة للمنتجات فقط؛ هذه تغطّي الكتابة والحذف أيضًا،
 * وهي الأخطر: لا أثر لها على الشاشة حتى يفتقد الجارُ بياناته.
 */
class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Business $mine;

    private Business $theirs;

    private User $me;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mine = Business::create(['name' => 'متجري', 'type' => 'عام', 'status' => 'نشط']);
        $this->theirs = Business::create(['name' => 'متجر الجار', 'type' => 'عام', 'status' => 'نشط']);

        $this->me = User::create([
            'business_id' => $this->mine->id, 'name' => 'أنا', 'email' => 'me@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
    }

    /** ينشئ سجلًّا يخصّ الجار ويعيد معرّفه */
    private function theirRecord(string $table, array $attributes): int
    {
        return (int) \DB::table($table)->insertGetId(array_merge([
            'business_id' => $this->theirs->id,
            'created_at' => now(), 'updated_at' => now(),
        ], $attributes));
    }

    /* ------------------------- الحذف عبر المتاجر ------------------------- */

    public static function deletable(): array
    {
        return [
            'منتج' => ['products', ['name' => 'منتج الجار', 'price' => 10, 'quantity' => 5], 'admin.products.destroy'],
            'قسم' => ['categories', ['name' => 'قسم الجار'], 'admin.categories.destroy'],
            'مورّد' => ['suppliers', ['name' => 'مورّد الجار'], 'admin.suppliers.destroy'],
            'فرع' => ['branches', ['name' => 'فرع الجار'], 'admin.branches.destroy'],
            'إضافة' => ['addons', ['name' => 'إضافة الجار', 'price' => 1], 'admin.addons.destroy'],
            'نوع مصروف' => ['expense_types', ['name' => 'نوع الجار'], 'admin.expenseTypes.destroy'],
            'وظيفة' => ['job_titles', ['name' => 'وظيفة الجار', 'role' => 'cashier'], 'admin.jobTitles.destroy'],
        ];
    }

    #[DataProvider('deletable')]
    public function test_one_business_cannot_delete_another_businesses_record(
        string $table, array $attributes, string $route
    ): void {
        if (! Schema::hasTable($table)) {
            $this->markTestSkipped("الجدول {$table} غير موجود");
        }

        $id = $this->theirRecord($table, $attributes);

        $this->actingAs($this->me)->delete(route($route, $id));

        $this->assertDatabaseHas($table, ['id' => $id], 'sqlite');
    }

    /* ------------------------ التعديل عبر المتاجر ------------------------ */

    public function test_one_business_cannot_rename_another_businesses_product(): void
    {
        $id = $this->theirRecord('products', ['name' => 'منتج الجار', 'price' => 10, 'quantity' => 5]);

        $this->actingAs($this->me)->put(route('admin.products.update', $id), [
            'name' => 'صار لي', 'price' => 1, 'quantity' => 1,
        ]);

        $this->assertSame('منتج الجار', \DB::table('products')->where('id', $id)->value('name'));
    }

    public function test_one_business_cannot_rename_another_businesses_supplier(): void
    {
        $id = $this->theirRecord('suppliers', ['name' => 'مورّد الجار']);

        $this->actingAs($this->me)->put(route('admin.suppliers.update', $id), ['name' => 'صار لي']);

        $this->assertSame('مورّد الجار', \DB::table('suppliers')->where('id', $id)->value('name'));
    }

    public function test_one_business_cannot_touch_another_businesses_employee(): void
    {
        $theirEmployee = User::create([
            'business_id' => $this->theirs->id, 'name' => 'موظف الجار',
            'email' => 'them@abaad.om', 'password' => bcrypt('x'),
            'role' => 'cashier', 'status' => 'نشط',
        ]);

        $this->actingAs($this->me)->post(route('admin.employees.toggle', $theirEmployee->id));
        $this->assertSame('نشط', $theirEmployee->fresh()->status);

        $this->actingAs($this->me)->post(route('admin.employees.resetPassword', $theirEmployee->id));
        $this->assertTrue(
            password_verify('x', $theirEmployee->fresh()->password),
            'أُعيد تعيين كلمة مرور موظف يخصّ متجرًا آخر'
        );
    }

    /* ------------------------- القراءة عبر المتاجر ----------------------- */

    public static function readable(): array
    {
        return [
            'منتج' => ['products', ['name' => 'سرّ الجار', 'price' => 10, 'quantity' => 5], 'admin.products.show'],
            'عميل' => ['customers', ['name' => 'عميل الجار'], 'admin.customers.show'],
        ];
    }

    #[DataProvider('readable')]
    public function test_one_business_cannot_read_another_businesses_record(
        string $table, array $attributes, string $route
    ): void {
        $id = $this->theirRecord($table, $attributes);

        $this->actingAs($this->me)->get(route($route, $id))->assertNotFound();
    }

    public function test_one_business_cannot_open_another_businesses_order(): void
    {
        $id = $this->theirRecord('orders', [
            'number' => 'ORD-JAR-1', 'total' => 99, 'status' => 'مكتمل',
            'is_held' => false, 'ordered_at' => now(),
        ]);

        $this->assertGreaterThan(0, $id);

        $this->actingAs($this->me)->get(route('admin.orders.show', 'ORD-JAR-1'))->assertNotFound();
        $this->actingAs($this->me)->get(route('admin.orders.pdf', 'ORD-JAR-1'))->assertNotFound();
    }

    public function test_one_business_cannot_switch_into_another_businesses_branch(): void
    {
        $id = $this->theirRecord('branches', ['name' => 'فرع سرّي للجار']);

        $this->actingAs($this->me)->get(route('admin.branch.switch', $id))->assertNotFound();

        $this->assertNotEquals($id, session('current_branch'), 'انتقل إلى فرع متجر آخر');
    }

    public function test_a_stale_session_cannot_leak_another_businesses_branch_name(): void
    {
        // حتى لو دخلت القيمة بطريق آخر — جلسة قديمة، أو فرع انتقلت ملكيته —
        // الاسم المعروض في الترويسة لا يجوز أن يأتي من متجر آخر
        $id = $this->theirRecord('branches', ['name' => 'فرع سرّي للجار']);

        $this->actingAs($this->me);
        session(['current_branch' => $id]);

        $this->assertSame(__('كل الفروع'), \App\Support\Demo::currentBranchName());
    }

    public function test_switching_into_your_own_branch_still_works(): void
    {
        $mine = \DB::table('branches')->insertGetId([
            'business_id' => $this->mine->id, 'name' => 'فرعي',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($this->me)->get(route('admin.branch.switch', $mine));

        $this->assertSame($mine, session('current_branch'));
        $this->assertSame('فرعي', \App\Support\Demo::currentBranchName());
    }
}
