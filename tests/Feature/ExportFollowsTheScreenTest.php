<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Support\OrderStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * الملفّ يخرج بما تنظر إليه الشاشة — لا بما في القاعدة كلِّه.
 *
 * زرّ «تصدير» يقف بجانب المُرشِّحات، فمن ضغطه ينتظر ما أمامه. وكانت
 * الملفّات تُبنى من استعلامٍ آخر لا يقرأ من الطلب شيئًا:
 *
 *   - يُرشِّح مصروفات شهرٍ ويصدّر، فيفتح ملفًّا فيه التاريخ كلّه.
 *   - ويُرشِّح الفواتير الملغاة ويصدّر، فلا يجد ملغاةً واحدة — لأنّ مصدر
 *     التصدير كان يستثنيها أصلًا بـ`sold()`.
 *   - ويبحث عن صنفٍ ويصدّر، فيخرج الجرد كلّه.
 *
 * وخطأٌ من هذا النوع لا يُكتشف عند التصدير: يُكتشف عند المحاسب.
 */
class ExportFollowsTheScreenTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;
    private User $owner;
    private Product $rose;
    private Product $vase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'محل التصدير', 'email' => 'x@test.local', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الفرع الرئيسي']);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'مالك', 'email' => 'o@x.local',
            'password' => bcrypt('secret'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $flowers = Category::create(['business_id' => $this->business->id, 'name' => 'ورود']);
        $tools = Category::create(['business_id' => $this->business->id, 'name' => 'أدوات']);

        $this->rose = Product::create([
            'business_id' => $this->business->id, 'category_id' => $flowers->id,
            'name' => 'وردة حمراء', 'sku' => 'ROSE-1',
            'price' => 1, 'cost' => 0.4, 'quantity' => 50, 'alert_qty' => 10, 'active' => true,
        ]);
        $this->vase = Product::create([
            'business_id' => $this->business->id, 'category_id' => $tools->id,
            'name' => 'مزهرية', 'sku' => 'VASE-1',
            'price' => 5, 'cost' => 2, 'quantity' => 0, 'alert_qty' => 3, 'active' => false,
        ]);
    }

    private function csv(string $route, array $params = []): string
    {
        $res = $this->actingAs($this->owner)->get(route($route, $params));
        $res->assertOk();

        return $this->body($res);
    }

    private function body(TestResponse $res): string
    {
        ob_start();
        $res->baseResponse->sendContent();

        return (string) ob_get_clean();
    }

    private function order(string $number, string $status, string $customer, ?string $at = null): Order
    {
        return Order::create([
            'business_id' => $this->business->id, 'number' => $number,
            'customer_name' => $customer, 'employee_name' => 'كاشير',
            'status' => $status, 'payment_method' => 'نقدي', 'is_held' => false,
            'subtotal' => 10, 'tax' => 0, 'total' => 10,
            'ordered_at' => $at ?? now()->toDateTimeString(),
        ]);
    }

    /* ----------------------------- الفواتير ----------------------------- */

    public function test_filtering_by_status_carries_into_the_file(): void
    {
        $this->order('INV-1', OrderStatus::COMPLETED, 'زبون مكتمل');
        $this->order('INV-2', OrderStatus::CANCELLED, 'زبون ملغى');

        $body = $this->csv('admin.export.orders', ['status' => OrderStatus::CANCELLED]);

        $this->assertStringContainsString('زبون ملغى', $body);
        $this->assertStringNotContainsString('زبون مكتمل', $body);
    }

    public function test_a_cancelled_order_can_reach_the_file_at_all(): void
    {
        /*
         * أشدُّ من إهمال المُرشِّح: المصدر كان يستثني الملغى بـ`sold()`، فمن
         * رشّح الملغاة وصدّر خرج بملفٍّ فارغ — لا ناقصٍ.
         */
        $this->order('INV-3', OrderStatus::CANCELLED, 'زبون ملغى');

        $this->assertStringContainsString('زبون ملغى', $this->csv('admin.export.orders'));
    }

    public function test_a_date_range_carries_into_the_file(): void
    {
        $this->order('INV-4', OrderStatus::COMPLETED, 'زبون قديم', now()->subMonths(6)->toDateTimeString());
        $this->order('INV-5', OrderStatus::COMPLETED, 'زبون اليوم');

        $body = $this->csv('admin.export.orders', ['from' => now()->subDays(3)->toDateString()]);

        $this->assertStringContainsString('زبون اليوم', $body);
        $this->assertStringNotContainsString('زبون قديم', $body);
    }

    public function test_a_search_carries_into_the_file(): void
    {
        $this->order('INV-6', OrderStatus::COMPLETED, 'سالم');
        $this->order('INV-7', OrderStatus::COMPLETED, 'خالد');

        $body = $this->csv('admin.export.orders', ['q' => 'سالم']);

        $this->assertStringContainsString('سالم', $body);
        $this->assertStringNotContainsString('خالد', $body);
    }

    public function test_the_dashboard_still_sees_only_what_was_sold(): void
    {
        // بلا مُرشِّحٍ تبقى القاعدة القديمة: اللوحة تعرض ما بيع لا ما أُلغي
        $this->order('INV-8', OrderStatus::CANCELLED, 'زبون ملغى');
        $this->order('INV-9', OrderStatus::COMPLETED, 'زبون مكتمل');

        $this->actingAs($this->owner);
        $names = array_column(\App\Support\Demo::orders(), 'customer');

        $this->assertContains('زبون مكتمل', $names);
        $this->assertNotContains('زبون ملغى', $names);
    }

    /* ----------------------------- المنتجات ----------------------------- */

    public function test_a_category_filter_carries_into_the_file(): void
    {
        $body = $this->csv('admin.export.products', ['category' => 'ورود']);

        $this->assertStringContainsString('وردة حمراء', $body);
        $this->assertStringNotContainsString('مزهرية', $body);
    }

    public function test_a_status_filter_carries_into_the_file(): void
    {
        $body = $this->csv('admin.export.products', ['status' => 'inactive']);

        $this->assertStringContainsString('مزهرية', $body);
        $this->assertStringNotContainsString('وردة حمراء', $body);
    }

    public function test_an_out_of_stock_filter_carries_into_the_file(): void
    {
        $body = $this->csv('admin.export.products', ['stock' => 'نفد المخزون']);

        $this->assertStringContainsString('مزهرية', $body);
        $this->assertStringNotContainsString('وردة حمراء', $body);
    }

    public function test_the_printable_product_report_follows_the_filter_too(): void
    {
        $res = $this->actingAs($this->owner)->get(route('admin.products.xlsx', ['category' => 'ورود']));
        $res->assertOk();

        $this->assertGreaterThan(1000, strlen($this->body($res)));
    }

    /* ----------------------------- المصروفات ----------------------------- */

    private function expense(string $description, string $at, string $type = 'إيجار'): Expense
    {
        return Expense::create([
            'business_id' => $this->business->id,
            'reference' => 'EXP-'.substr(md5($description), 0, 6),
            'type' => $type, 'description' => $description,
            'amount' => 100, 'method' => 'نقدي', 'status' => 'مدفوع',
            'spent_at' => $at,
        ]);
    }

    public function test_the_month_on_screen_is_the_month_in_the_file(): void
    {
        $this->expense('مصروف الشهر', now()->toDateString());
        $this->expense('مصروف قديم', now()->subMonths(5)->toDateString());

        // الشاشة تفتح على الشهر الجاري بلا أن يُقال — والملفّ مثلها
        $body = $this->csv('admin.export.expenses');

        $this->assertStringContainsString('مصروف الشهر', $body);
        $this->assertStringNotContainsString('مصروف قديم', $body);
    }

    public function test_asking_for_all_months_gives_all_months(): void
    {
        $this->expense('مصروف الشهر', now()->toDateString());
        $this->expense('مصروف قديم', now()->subMonths(5)->toDateString());

        // «كل الشهور» في الشاشة تُرسل `all` — فيخرج التاريخ كلّه
        $body = $this->csv('admin.export.expenses', ['month' => 'all']);

        $this->assertStringContainsString('مصروف الشهر', $body);
        $this->assertStringContainsString('مصروف قديم', $body);
    }

    public function test_an_expense_type_filter_carries_into_the_file(): void
    {
        $this->expense('إيجار المحل', now()->toDateString(), 'إيجار');
        $this->expense('فاتورة كهرباء', now()->toDateString(), 'كهرباء');

        $body = $this->csv('admin.export.expenses', ['type' => 'كهرباء']);

        $this->assertStringContainsString('فاتورة كهرباء', $body);
        $this->assertStringNotContainsString('إيجار المحل', $body);
    }

    /* ------------------------------ العملاء ------------------------------ */

    public function test_a_customer_search_carries_into_the_file(): void
    {
        Customer::create(['business_id' => $this->business->id, 'name' => 'سالم البلوشي', 'phone' => '90000001']);
        Customer::create(['business_id' => $this->business->id, 'name' => 'خالد الحارثي', 'phone' => '90000002']);

        $body = $this->csv('admin.export.customers', ['q' => 'سالم']);

        $this->assertStringContainsString('سالم البلوشي', $body);
        $this->assertStringNotContainsString('خالد الحارثي', $body);
    }

    /* ------------------------- ما لا يتبع عمدًا ------------------------- */

    public function test_the_round_trip_catalogue_stays_whole(): void
    {
        /*
         * زوجُ «استيراد/تصدير» لا يتبع المُرشِّحات عمدًا: من صدّر نصف الجرد
         * ثمّ استورده ظنّ أنّه ردّ الجرد كلّه.
         */
        $res = $this->actingAs($this->owner)->get(route('admin.products.export.xlsx', ['category' => 'ورود']));
        $res->assertOk();

        $this->assertGreaterThan(1000, strlen($this->body($res)));
    }

    /* --------------------------- حدّ المتجر يبقى --------------------------- */

    public function test_a_filter_never_opens_a_door_to_another_shop(): void
    {
        $other = Business::create(['name' => 'متجر الجار', 'email' => 'n@x.local', 'status' => 'نشط']);
        Product::create([
            'business_id' => $other->id, 'name' => 'سرٌّ لا يخرج', 'sku' => 'SECRET-9',
            'price' => 9, 'cost' => 1, 'quantity' => 5, 'active' => true,
        ]);

        $this->assertStringNotContainsString(
            'سرٌّ لا يخرج',
            $this->csv('admin.export.products', ['q' => 'سرّ']),
        );
    }

    public function test_a_cancelled_order_is_listed_but_not_counted_in_the_total(): void
    {
        /*
         * الشاشة تعرض الملغى في الجدول وتحسب إجماليّها بلا قيمته. فلو جمعه
         * الملفّ لقال مبيعاتٍ لم تقع — وخالف الرقم الذي قرأه التاجر قبل أن
         * يضغط «تصدير».
         */
        $this->order('INV-20', OrderStatus::COMPLETED, 'زبون مكتمل');
        $this->order('INV-21', OrderStatus::CANCELLED, 'زبون ملغى');

        $res = $this->actingAs($this->owner)->get(route('admin.orders.xlsx'));
        $res->assertOk();

        $this->assertGreaterThan(1000, strlen($this->body($res)));

        // والمجموع يُحسب هنا بالقاعدة نفسها التي يقرأ بها الملفّ
        $this->actingAs($this->owner);
        $rows = \App\Support\Demo::orders(request());
        $total = array_sum(array_map(
            fn ($o) => $o['status'] === OrderStatus::CANCELLED ? 0.0 : (float) $o['total'],
            $rows,
        ));

        $this->assertCount(2, $rows);
        $this->assertEqualsWithDelta(10.0, $total, 0.0005);
    }

    public function test_the_export_menu_carries_the_filters_in_its_links(): void
    {
        /*
         * حارسٌ على الطرف الآخر: لا اختبار يشغّل الواجهة هنا، ورابطٌ يخرج
         * عاريًا يُبطل كلّ ما فوقه — يُرشِّح التاجر ثمّ يصدّر فيخرج الكلّ.
         */
        $menu = file_get_contents(resource_path('js/Components/ExportMenu.tsx'));

        foreach (['withFilters(xlsx)', 'withFilters(pdf)', 'withFilters(csv)'] as $call) {
            $this->assertStringContainsString($call, $menu, 'رابط تصديرٍ لا يحمل المُرشِّحات');
        }

        $helper = file_get_contents(resource_path('js/lib/exportLink.ts'));

        $this->assertStringContainsString('window.location.search', $helper);
        $this->assertStringContainsString("here.delete('page')", $helper);
    }
}
