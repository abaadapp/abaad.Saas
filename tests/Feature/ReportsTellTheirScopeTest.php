<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchStock;
use App\Models\Business;
use App\Models\Expense;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Demo;
use App\Support\Permissions;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Tests\TestCase;

/**
 * التقرير يقول أيّ فرعٍ يقيس، ويقيس ما يقول.
 *
 * ترويسة كل ورقةٍ تكتب «الفرع: مسقط» لأنّ مسقط هي المختارة في الشريط —
 * مهما كان ما تحتها. وأكثر الأوراق لا تعرف الفروع أصلًا: المبيعات
 * والمصروفات والحركة المالية والمنتجات تُجمع على المتجر كلّه. فيُرسَل
 * الملفّ إلى المحاسب بترويسةٍ تنسبه إلى فرعٍ واحد وجدولٍ يحمل ثلاثة.
 *
 * وأسوأ منه ورقةُ الجرد: كميتُها من الفرع وقيمتُها من الشركة — سطرٌ واحد
 * نصفُه هنا ونصفُه هناك.
 */
class ReportsTellTheirScopeTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    private Branch $muscat;

    private Branch $salalah;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'متجري', 'type' => 'عام', 'status' => 'نشط']);
        $this->muscat = Branch::create(['business_id' => $this->business->id, 'name' => 'مسقط']);
        $this->salalah = Branch::create(['business_id' => $this->business->id, 'name' => 'صلالة']);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
    }

    /** الورقة كما تصل المتصفّح، مقروءةً خليّةً خليّة */
    private function sheetOf(TestResponse $res): Worksheet
    {
        $res->assertOk();
        $path = tempnam(sys_get_temp_dir(), 'xlsx').'.xlsx';
        file_put_contents($path, $res->streamedContent());
        $sheet = IOFactory::load($path)->getActiveSheet();
        @unlink($path);

        return $sheet;
    }

    private function branchLine(TestResponse $res): string
    {
        return (string) $this->sheetOf($res)->getCell('A3')->getValue();
    }

    /* ==================== نطاق الورقة ==================== */

    public function test_a_sheet_that_counts_every_branch_does_not_name_one(): void
    {
        $res = $this->actingAs($this->owner)
            ->withSession(['current_branch' => $this->muscat->id])
            ->get(route('admin.reports.xlsx'));

        $this->assertStringContainsString(
            'كل الفروع',
            $this->branchLine($res),
            'ورقة المبيعات تُجمع على المتجر كلّه، فلا يجوز أن تنسب نفسها إلى فرع',
        );
    }

    public function test_the_finance_sheet_does_not_name_a_branch_it_never_filtered(): void
    {
        $res = $this->actingAs($this->owner)
            ->withSession(['current_branch' => $this->muscat->id])
            ->get(route('admin.finance.xlsx'));

        $this->assertStringContainsString('كل الفروع', $this->branchLine($res));
    }

    public function test_the_orders_sheet_names_the_branch_it_actually_filtered(): void
    {
        $res = $this->actingAs($this->owner)
            ->withSession(['current_branch' => $this->muscat->id])
            ->get(route('admin.orders.xlsx'));

        $this->assertStringContainsString('مسقط', $this->branchLine($res));
    }

    /* ==================== قيمة الجرد ==================== */

    private function stocked(): Product
    {
        $p = Product::create([
            'business_id' => $this->business->id, 'name' => 'وردة', 'sku' => 'F-1',
            'price' => 20, 'cost' => 10, 'quantity' => 30, 'alert_qty' => 2, 'active' => true,
        ]);
        BranchStock::create(['business_id' => $this->business->id, 'branch_id' => $this->muscat->id, 'product_id' => $p->id, 'quantity' => 10]);
        BranchStock::create(['business_id' => $this->business->id, 'branch_id' => $this->salalah->id, 'product_id' => $p->id, 'quantity' => 20]);

        return $p;
    }

    public function test_the_stock_value_measures_the_same_branch_as_the_quantity(): void
    {
        $this->stocked();

        $this->actingAs($this->owner)->withSession(['current_branch' => $this->muscat->id]);
        session(['current_branch' => $this->muscat->id]);

        $row = collect(Demo::inventory())->firstWhere('sku', 'F-1');

        $this->assertSame(10, $row['qty']);
        $this->assertSame(100.0, $row['value'], 'عشر قطعٍ بعشرة ريالات = مئة — لا ثلاثمئة الشركة');
        $this->assertSame(300.0, $row['totalValue'], 'وقيمة الشركة تبقى معروضة باسمها');
    }

    public function test_the_stock_file_carries_the_branchs_value_not_the_companys(): void
    {
        $this->stocked();

        $sheet = $this->sheetOf($this->actingAs($this->owner)
            ->withSession(['current_branch' => $this->muscat->id])
            ->get(route('admin.inventory.xlsx')));

        // الكمية في D والقيمة في F على الصف الأول من البيانات
        $this->assertSame(10, (int) $sheet->getCell('D6')->getValue());
        $this->assertSame(100.0, (float) $sheet->getCell('F6')->getValue());
    }

    /* ==================== الدفتر كاملًا ==================== */

    public function test_the_finance_file_carries_the_whole_ledger_not_its_first_five_hundred(): void
    {
        $this->actingAs($this->owner);

        $rows = [];
        for ($i = 0; $i < 520; $i++) {
            $rows[] = [
                'business_id' => $this->business->id, 'reference' => 'TRX-'.$i,
                'description' => 'بيع', 'method' => 'نقدي', 'type' => 'دخل',
                'amount' => 1, 'tax_amount' => 0, 'employee_name' => 'المالك',
                'occurred_at' => now()->subMinutes($i), 'created_at' => now(), 'updated_at' => now(),
            ];
        }
        Transaction::insert($rows);

        $this->assertCount(520, Demo::transactions('month', null), 'التصدير هو الباب إلى الدفتر كاملًا');
        $this->assertCount(500, Demo::transactions('month'), 'والشاشة تبقى بسقفها');

        // وحتى الورقة نفسها: المراجع في العمود A، والصفوف تبدأ بعد الترويسة
        $sheet = $this->sheetOf($this->get(route('admin.finance.xlsx', ['range' => 'month'])));
        $refs = [];
        foreach ($sheet->getRowIterator() as $row) {
            $ref = (string) $sheet->getCell('A'.$row->getRowIndex())->getValue();
            if (str_starts_with($ref, 'TRX-')) {
                $refs[] = $ref;
            }
        }

        $this->assertCount(520, $refs, 'الورقة تحمل الدفتر كلّه لا أحدث خمسمئة');
    }

    /* ==================== الأسبوع ==================== */

    public function test_the_week_the_report_counts_starts_on_sunday(): void
    {
        $this->travelTo(now()->startOfMonth()->next(CarbonInterface::WEDNESDAY)->setTime(12, 0));

        $start = Demo::rangeStart('week');

        $this->assertSame(
            CarbonInterface::SUNDAY,
            $start->dayOfWeek,
            'أسبوع العمل في عُمان يبدأ الأحد — وبدايةُ الاثنين تُخرج مبيعات الأحد من «هذا الأسبوع»',
        );
    }

    /* ==================== باب الملفّ ==================== */

    public function test_whoever_owns_the_suppliers_owns_their_file(): void
    {
        $keeper = User::create([
            'business_id' => $this->business->id, 'name' => 'أمين المخزن', 'email' => 'k@abaad.om',
            'password' => bcrypt('password'), 'role' => 'inventory', 'status' => 'نشط',
        ]);

        $this->actingAs($keeper)->get(route('admin.export.suppliers'))->assertOk();
    }

    public function test_the_suppliers_file_still_refuses_whoever_does_not_own_them(): void
    {
        $cashier = User::create([
            'business_id' => $this->business->id, 'name' => 'كاشير', 'email' => 'c@abaad.om',
            'password' => bcrypt('password'), 'role' => 'cashier', 'status' => 'نشط',
        ]);

        $this->actingAs($cashier)->get(route('admin.export.suppliers'))->assertForbidden();
    }

    public function test_every_csv_door_knows_which_section_owns_it(): void
    {
        $orphans = [];
        foreach (app('router')->getRoutes() as $route) {
            $name = $route->getName();
            if (! $name || ! str_starts_with($name, 'admin.export.')) {
                continue;
            }
            $what = explode('.', $name)[2] ?? '';
            if (! isset(Permissions::EXPORT_ALIASES[$what])) {
                $orphans[] = $name;
            }
        }

        $this->assertSame([], $orphans, 'ملفٌّ يسقط إلى «الإعدادات» يُمنع عن صاحب قسمه: '.implode('، ', $orphans));
    }

    /* ==================== الربح ==================== */

    /** بيعةٌ واحدة: بضاعةٌ اشتُريت بـ٦٠٠ وبيعت بـ١٠٠٠ شاملةً ضريبةً ٥٠ */
    private function soldAtAProfit(): void
    {
        $product = Product::create([
            'business_id' => $this->business->id, 'name' => 'باقة', 'sku' => 'B-1',
            'price' => 1000, 'cost' => 600, 'quantity' => 5, 'alert_qty' => 1, 'active' => true,
        ]);

        $order = Order::create([
            'business_id' => $this->business->id, 'branch_id' => $this->muscat->id,
            'number' => 'INV-P1', 'status' => 'مكتمل', 'is_held' => false,
            'payment_method' => 'نقدي', 'subtotal' => 950, 'tax' => 50, 'total' => 1000,
            'ordered_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id, 'name' => 'باقة', 'quantity' => 1,
            'price' => 950, 'cost' => 600, 'total' => 950,
        ]);

        Expense::create([
            'business_id' => $this->business->id, 'type' => 'إيجار', 'description' => 'إيجار',
            'amount' => 200, 'method' => 'نقدي', 'status' => 'مدفوع',
            'spent_at' => now(), 'employee_name' => 'المالك',
        ]);
    }

    public function test_net_profit_subtracts_what_the_goods_cost(): void
    {
        $this->actingAs($this->owner);
        $this->soldAtAProfit();

        $summary = Demo::reportSummary('month');

        $this->assertSame(1000.0, $summary['sales']);
        $this->assertSame(600.0, $summary['cogs']);
        $this->assertSame(200.0, $summary['expenses']);
        $this->assertSame(
            150.0,
            $summary['profit'],
            '(١٠٠٠ − ٥٠ ضريبة) − ٦٠٠ تكلفة − ٢٠٠ مصروفات = ١٥٠ — لا ٨٠٠',
        );
    }

    public function test_the_cost_line_reaches_the_file_beside_the_profit(): void
    {
        $this->actingAs($this->owner);
        $this->soldAtAProfit();

        $sheet = $this->sheetOf($this->get(route('admin.reports.xlsx', ['range' => 'month'])));

        $labels = [];
        $values = [];
        foreach (range(7, 20) as $r) {
            $labels[] = (string) $sheet->getCell("A{$r}")->getValue();
            $values[(string) $sheet->getCell("A{$r}")->getValue()] = $sheet->getCell("B{$r}")->getValue();
        }

        $this->assertContains('تكلفة البضاعة المباعة', $labels);
        $this->assertSame(600.0, (float) $values['تكلفة البضاعة المباعة']);
        $this->assertSame(150.0, (float) $values['صافي الربح']);
    }

    public function test_the_screen_and_the_file_read_the_same_profit(): void
    {
        $this->actingAs($this->owner);
        $this->soldAtAProfit();

        $screen = Demo::reportSummary('month')['profit'];
        $feed = $this->get(route('admin.reports.feed', ['range' => 'month']))->json('summary.profit');

        $this->assertSame($screen, (float) $feed);
    }

    /* ==================== الساعة ==================== */

    public function test_the_one_time_clock_correction_writes_nothing_until_it_is_told_to(): void
    {
        $this->actingAs($this->owner);
        Transaction::create([
            'business_id' => $this->business->id, 'reference' => 'TRX-OLD', 'description' => 'بيع',
            'method' => 'نقدي', 'type' => 'دخل', 'amount' => 1, 'tax_amount' => 0,
            'employee_name' => 'المالك', 'occurred_at' => '2026-08-20 16:00:00',
        ]);

        $this->artisan('clock:shift', ['--before' => '2026-09-01'])->assertSuccessful();

        $this->assertSame(
            '2026-08-20 16:00:00',
            (string) Transaction::where('reference', 'TRX-OLD')->value('occurred_at'),
            'بلا ‎--force لا يُكتب حرف: هذه أوقاتُ فواتيرَ وقيود',
        );
    }

    public function test_the_correction_lifts_only_what_was_written_by_the_old_clock(): void
    {
        $this->actingAs($this->owner);

        foreach ([['TRX-OLD', '2026-08-20 16:00:00'], ['TRX-NEW', '2026-09-01 09:00:00']] as [$ref, $at]) {
            Transaction::create([
                'business_id' => $this->business->id, 'reference' => $ref, 'description' => 'بيع',
                'method' => 'نقدي', 'type' => 'دخل', 'amount' => 1, 'tax_amount' => 0,
                'employee_name' => 'المالك', 'occurred_at' => $at,
            ]);
        }

        $this->artisan('clock:shift', ['--before' => '2026-09-01', '--force' => true])->assertSuccessful();

        $this->assertSame('2026-08-20 20:00:00', (string) Transaction::where('reference', 'TRX-OLD')->value('occurred_at'));
        $this->assertSame('2026-09-01 09:00:00', (string) Transaction::where('reference', 'TRX-NEW')->value('occurred_at'), 'وما كُتب بالساعة الصحيحة لا يُزاح مرّةً ثانية');
    }

    public function test_the_cut_can_be_an_hour_not_only_a_day(): void
    {
        /*
         * التصحيح وقع في منتصف يوم عمل: صفوفُ صباحه كُتبت بالساعة القديمة
         * وصفوفُ مسائه بالجديدة. ويومٌ كاملًا حدًّا يُزيح المساء مرّةً ثانية
         * — فيصير الجدول على ثلاث ساعات بدل اثنتين، ولا يُعرف أيُّ صفٍّ في
         * أيّها. وهذا أسوأ من ترك القديم كما هو.
         */
        $this->actingAs($this->owner);

        foreach ([['TRX-AM', '2026-09-01 08:18:00'], ['TRX-PM', '2026-09-01 13:05:00']] as [$ref, $at]) {
            Transaction::create([
                'business_id' => $this->business->id, 'reference' => $ref, 'description' => 'بيع',
                'method' => 'نقدي', 'type' => 'دخل', 'amount' => 1, 'tax_amount' => 0,
                'employee_name' => 'المالك', 'occurred_at' => $at,
            ]);
        }

        $this->artisan('clock:shift', ['--before' => '2026-09-01 09:00:00', '--force' => true])
            ->assertSuccessful();

        $this->assertSame('2026-09-01 12:18:00', (string) Transaction::where('reference', 'TRX-AM')->value('occurred_at'));
        $this->assertSame('2026-09-01 13:05:00', (string) Transaction::where('reference', 'TRX-PM')->value('occurred_at'), 'أُزيح ما كُتب بالساعة الصحيحة');
    }

    public function test_a_cut_that_is_not_a_moment_at_all_is_refused(): void
    {
        // حدٌّ يُقرأ خطأً يُزيح كلّ شيء أو لا شيء — والاثنان لا يُكتشفان إلا بعد الكتابة
        $this->artisan('clock:shift', ['--before' => 'الأمس', '--force' => true])->assertFailed();
        $this->artisan('clock:shift', ['--force' => true])->assertFailed();
    }

    public function test_the_clock_the_shop_reads_is_the_clock_it_lives_by(): void
    {
        $this->assertSame(
            'Asia/Muscat',
            config('app.timezone'),
            'بتوقيت UTC يبدأ «اليوم» عند الرابعة فجرًا بتوقيت عُمان، وتُطبع الفواتير بأربع ساعاتٍ إلى الوراء',
        );
    }
}
