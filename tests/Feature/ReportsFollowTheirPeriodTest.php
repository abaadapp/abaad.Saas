<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Order;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Demo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * كل تقريرٍ يقيس فترةً واحدة، ويقولها.
 *
 * التقرير الذي لا يتبع فترته يكذب بأرقامٍ صحيحة: كلُّ رقمٍ فيه محسوبٌ حسابًا
 * سليمًا، لكنه محسوبٌ على مدًى غيرِ الذي يظنّه قارئه.
 *
 * وأوضحُ ما وقع: ملفُّ «الحركة المالية» كان نصفاه على فترتين — المؤشّرات بلا
 * فترةٍ فتسقط على الشهر، والجدول بلا فترةٍ فيسقط على كلّ الفترات. فيقرأ
 * التاجر «الدخل ١٠٠» فوق جدولٍ مجموعُه ألف، ولا سطر في الورقة يقول إنهما لا
 * يقيسان الشيء نفسه.
 */
class ReportsFollowTheirPeriodTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'متجري', 'type' => 'عام', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);
        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->actingAs($this->owner);
    }

    private function order(float $total, string $when): void
    {
        Order::create([
            'business_id' => $this->business->id, 'branch_id' => 1,
            'number' => 'INV-'.uniqid(), 'status' => 'مكتمل', 'is_held' => false,
            'payment_method' => 'نقدي', 'subtotal' => $total, 'total' => $total,
            'ordered_at' => $when, 'created_at' => $when, 'updated_at' => $when,
        ]);
    }

    private function trx(float $amount, string $when): void
    {
        Transaction::create([
            'business_id' => $this->business->id,
            'reference' => Transaction::nextReference($this->business->id),
            'description' => 'قيد', 'method' => 'نقدي', 'type' => 'دخل',
            'amount' => $amount, 'employee_name' => 'المالك', 'occurred_at' => $when,
        ]);
    }

    private function salesTotal(string $range): float
    {
        return (float) $this->get(route('admin.reports.sales', ['range' => $range]))
            ->viewData('page')['props']['summary']['sales'];
    }

    /* ---------------------------- ملخّص المبيعات ---------------------------- */

    public function test_each_range_counts_only_what_falls_inside_it(): void
    {
        $this->order(100, now()->toDateTimeString());
        $this->order(400, now()->subYears(2)->toDateTimeString());

        $this->assertSame(100.0, $this->salesTotal('today'), 'تقرير اليوم ابتلع طلبًا قديمًا');
        $this->assertSame(500.0, $this->salesTotal('all'), 'تقرير كلّ الفترات أسقط طلبًا');
    }

    /** والطلب الجديد يظهر في الحال — لا بعد تفريغ ذاكرةٍ ولا في اليوم التالي */
    public function test_a_new_order_appears_at_once(): void
    {
        $before = $this->salesTotal('today');
        $this->order(75, now()->toDateTimeString());

        $this->assertSame($before + 75, $this->salesTotal('today'), 'طلبٌ جديد لم يظهر في تقرير اليوم');
    }

    /** والملفّ يحمل فترة الشاشة لا فترته الخاصّة */
    public function test_the_exported_file_follows_the_screens_period(): void
    {
        $this->order(100, now()->toDateTimeString());
        $this->order(400, now()->subYears(2)->toDateTimeString());

        foreach (['today' => 100, 'all' => 500] as $range => $expected) {
            $feed = $this->getJson(route('admin.reports.feed', ['range' => $range]))->json();
            $this->assertEquals($expected, $feed['summary']['sales'],
                "ملفّ «{$range}» لا يوافق شاشته");
        }
    }

    /* --------------------------- الحركة المالية --------------------------- */

    /**
     * ويُقرأ الملفّ المُنزَّل نفسه لا الدوالُّ تحته.
     *
     * فحصُ الدوالّ بفترةٍ مُمرَّرة يمرّ في الحالين — العطب كان في المتحكّم:
     * ينادي كلًّا منهما بلا فترة فتسقط كلٌّ على افتراضها هي.
     */
    public function test_both_halves_of_the_finance_file_share_one_period(): void
    {
        $this->trx(100, now()->toDateTimeString());
        $this->trx(900, now()->subYears(2)->toDateTimeString());

        $path = tempnam(sys_get_temp_dir(), 'fin').'.xlsx';
        file_put_contents($path, $this->get(route('admin.finance.xlsx', ['range' => 'month']))->streamedContent());

        $cells = IOFactory::load($path)->getActiveSheet()->toArray();
        @unlink($path);

        $flat = json_encode($cells, JSON_UNESCAPED_UNICODE);

        $this->assertStringContainsString(Demo::rangeLabel('month'), $flat,
            'الورقة لا تقول أيّ فترةٍ تحمل');
        $this->assertStringNotContainsString('900', $flat,
            'جدول الورقة حمل قيدًا خارج فترة مؤشّراتها');
    }

    /** والفترة تصل إلى الملفّ من الرابط، ولا تُفرض عليه */
    public function test_the_finance_file_obeys_the_requested_period(): void
    {
        $this->trx(100, now()->toDateTimeString());
        $this->trx(900, now()->subYears(2)->toDateTimeString());

        $month = $this->get(route('admin.export.transactions', ['range' => 'month']))->streamedContent();
        $all = $this->get(route('admin.export.transactions', ['range' => 'all']))->streamedContent();

        $this->assertStringNotContainsString('900', $month, 'ملفّ الشهر حمل قيدًا عمره سنتان');
        $this->assertStringContainsString('900', $all, 'ملفّ كلّ الفترات أسقط قيدًا');
    }

    /* ------------------------- ووعدُ البطاقة يُوفَّى ------------------------- */

    /**
     * الحركة المالية لها بابٌ في الواجهة — لا مسارٌ يُكتب بالعنوان وحده.
     *
     * وبابُها شاشتُها هي: كان الزرّ في «الحسابات البنكية» لأنّ الحركة لم تكن
     * لها شاشة، فوُصل التصدير بأقرب صفحة — وفيها الأرصدة لا المقبوضات
     * والمدفوعات. فلمّا صارت للحركة شاشتُها رجع الزرّ إليها.
     */
    public function test_the_finance_report_is_reachable_from_its_screen(): void
    {
        $screen = file_get_contents(resource_path('js/Pages/Admin/Finance/Transactions.tsx'));

        foreach (['admin.finance.xlsx', 'admin.finance.pdf', 'admin.export.transactions'] as $route) {
            $this->assertStringContainsString($route, $screen,
                "تقرير الحركة المالية بلا زرٍّ يصل إليه: {$route}");
        }
    }

    /** والملفّات الثلاثة تُفتح فعلًا لا تُعطب */
    public function test_the_three_finance_files_open(): void
    {
        $this->trx(100, now()->toDateTimeString());

        foreach (['admin.finance.xlsx', 'admin.finance.pdf', 'admin.export.transactions'] as $route) {
            $this->get(route($route))->assertSuccessful();
        }
    }
}
