<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Customer;
use App\Models\JobTitle;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Product;
use App\Models\User;
use App\Support\DemoStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * لا يرى تاجرٌ بيانات تاجرٍ آخر — ولو كتب المعرّف بيده.
 *
 * أخطر ما في نظامٍ يسكنه أكثر من تاجر. والعزل قائمٌ بـ`business_id` في كل
 * استعلام، وهو يعمل ما دام كلّ استعلامٍ يذكره: يكفي مسارٌ واحد يقرأ
 * `Model::find($id)` بلا شرطٍ ليُفتح دفتر تاجرٍ على آخر بتغيير رقمٍ في
 * الرابط. ولا يظهر ذلك في اختبارٍ يعمل على متجرٍ واحد.
 */
class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private User $mine;

    private Business $theirs;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');

        Plan::updateOrCreate(['name' => 'الباقة الاحترافية'], [
            'monthly_price' => 30, 'yearly_price' => 300,
            'max_branches' => 3, 'max_employees' => 15, 'max_products' => 100000,
        ]);

        $mineBusiness = Business::create(['name' => 'متجري', 'type' => 'عام', 'status' => 'نشط']);
        Branch::create(['business_id' => $mineBusiness->id, 'name' => 'الرئيسي']);
        JobTitle::create(['business_id' => $mineBusiness->id, 'name' => 'مدير', 'role' => 'admin']);
        $this->mine = User::create([
            'business_id' => $mineBusiness->id, 'name' => 'أنا', 'email' => 'me@abaadapp.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        // متجر الجار ممتلئ: لكلّ نوعٍ من الصفوف مثالٌ يُجرَّب عليه
        $this->theirs = DemoStore::create('متجر الجار', 'صغير');
    }

    /** صفٌّ من متجر الجار لكلّ شاشةٍ تحمل معرّفًا */
    public static function doors(): array
    {
        return [
            'بطاقة عميل' => ['admin.customers.show', Customer::class],
            'كشف حساب عميل' => ['admin.customers.statement', Customer::class],
            'بطاقة موظف' => ['admin.employees.show', User::class],
            'تعديل موظف' => ['admin.employees.edit', User::class],
            'بطاقة منتج' => ['admin.products.show', Product::class],
            'تعديل منتج' => ['admin.products.edit', Product::class],
        ];
    }

    public function test_a_door_of_another_shop_does_not_open(): void
    {
        $opened = [];

        foreach (self::doors() as $label => [$route, $model]) {
            $id = $model::where('business_id', $this->theirs->id)->value('id');
            $this->assertNotNull($id, "لا صفَّ في متجر الجار لفحص «{$label}»");

            $status = $this->actingAs($this->mine)->get(route($route, $id))->getStatusCode();

            if (! in_array($status, [403, 404, 302], true)) {
                $opened[] = $label.' ('.$route.') → '.$status;
            }
        }

        $this->assertSame([], $opened, 'أبوابٌ فُتحت على متجرٍ آخر');
    }

    public function test_an_order_of_another_shop_does_not_open(): void
    {
        $number = Order::where('business_id', $this->theirs->id)->value('number');
        $this->assertNotNull($number);

        foreach (['admin.orders.show', 'admin.orders.pdf', 'admin.orders.taxInvoice',
            'pos.order-details', 'pos.receipts.show', 'pos.receipt.pdf'] as $route) {
            $response = $this->actingAs($this->mine)->get(route($route, $number));

            $this->assertContains($response->getStatusCode(), [403, 404, 302],
                "«{$route}» فتح فاتورةً من متجرٍ آخر بحالة ".$response->getStatusCode());
        }
    }

    public function test_a_branch_of_another_shop_cannot_be_switched_into(): void
    {
        $id = Branch::where('business_id', $this->theirs->id)->value('id');

        $this->actingAs($this->mine)->get(route('admin.branch.switch', $id));

        $this->assertNotSame($id, session('current_branch'), 'دخل فرعَ متجرٍ آخر');
    }

    /**
     * والكتابة أضعف من القراءة عادةً.
     *
     * شاشةٌ تعرض تقرأ بشرط `business_id` لأنّها تبني قائمة، ومسارٌ يحذف صفًّا
     * بمعرّفٍ من الرابط قد يكتفي بـ`find($id)`. والفرق بينهما أنّ الأولى
     * تكشف بيانات الجار، والثانية **تتلفها**.
     */
    public function test_no_row_of_another_shop_can_be_touched(): void
    {
        $bid = $this->theirs->id;

        $rows = [
            'منتج' => \App\Models\Product::where('business_id', $bid)->value('id'),
            'عميل' => Customer::where('business_id', $bid)->value('id'),
            'موظف' => User::where('business_id', $bid)->where('role', '!=', 'admin')->value('id'),
            'فرع' => Branch::where('business_id', $bid)->value('id'),
            'مصروف' => \App\Models\Expense::where('business_id', $bid)->value('id'),
            'مورّد' => \App\Models\Supplier::where('business_id', $bid)->value('id'),
            'أمر شراء' => \App\Models\PurchaseOrder::where('business_id', $bid)->value('id'),
        ];

        $doors = [
            ['delete', 'admin.products.destroy', 'منتج'],
            ['post', 'admin.products.duplicate', 'منتج'],
            ['delete', 'admin.customers.destroy', 'عميل'],
            ['post', 'admin.customers.note', 'عميل'],
            ['post', 'admin.employees.toggle', 'موظف'],
            ['post', 'admin.employees.resetPassword', 'موظف'],
            ['delete', 'admin.branches.destroy', 'فرع'],
            ['delete', 'admin.expenses.destroy', 'مصروف'],
            ['post', 'admin.expenses.paid', 'مصروف'],
            ['delete', 'admin.suppliers.destroy', 'مورّد'],
            ['delete', 'admin.purchases.destroy', 'أمر شراء'],
            ['post', 'admin.purchases.receive', 'أمر شراء'],
        ];

        $before = [
            'products' => \App\Models\Product::where('business_id', $bid)->count(),
            'customers' => Customer::where('business_id', $bid)->count(),
            'branches' => Branch::where('business_id', $bid)->count(),
            'expenses' => \App\Models\Expense::where('business_id', $bid)->count(),
            'suppliers' => \App\Models\Supplier::where('business_id', $bid)->count(),
            'purchase_orders' => \App\Models\PurchaseOrder::where('business_id', $bid)->count(),
        ];

        $touched = [];

        foreach ($doors as [$verb, $name, $key]) {
            $id = $rows[$key] ?? null;
            if ($id === null) {
                continue;
            }
            if (! \Illuminate\Support\Facades\Route::has($name)) {
                continue;
            }

            $status = $this->actingAs($this->mine)->{$verb}(route($name, $id))->getStatusCode();

            if (! in_array($status, [403, 404, 302], true)) {
                $touched[] = $name.' → '.$status;
            }
        }

        $this->assertSame([], $touched, 'مساراتٌ قبلت معرّفًا من متجرٍ آخر');

        foreach ($before as $table => $count) {
            $this->assertSame($count, \Illuminate\Support\Facades\DB::table($table)
                ->where('business_id', $bid)->count(), "نقص صفٌّ من «{$table}» في متجر الجار");
        }
    }

    public function test_the_platform_business_screen_is_closed_to_a_merchant(): void
    {
        foreach (['super-admin.businesses.show', 'super-admin.users.show'] as $route) {
            $response = $this->actingAs($this->mine)->get(route($route, $this->theirs->id));

            $this->assertContains($response->getStatusCode(), [403, 404, 302],
                "«{$route}» انفتح لتاجر بحالة ".$response->getStatusCode());
        }
    }
}
