<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Demo;
use App\Support\ReportData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * الطلب الملغى ليس بيعًا — في كل شاشة.
 *
 * كان شرط الاستثناء يُكتب بيدٍ في كل استعلام على حدة، فكُتب في ثلاثة مواضع
 * ونُسي في أحدٍ وثلاثين: بطاقات التقارير تجمع الملغى والمخطّط تحتها يستثنيه،
 * فتقرأ الشاشةُ الواحدة رقمين متناقضين عن الفترة نفسها — ١٠٠٠ فوق و١٠٠
 * تحت. وأخطرها الإقرار الضريبي: ضريبةٌ تُقرّ على بيعةٍ أُلغيت.
 *
 * فصار موضعًا واحدًا (Order::scopeSold) يقرأ منه الجميع.
 */
class CancelledOrderTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private Branch $branch;

    private User $owner;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'متجري', 'type' => 'عام', 'status' => 'نشط']);
        $this->branch = Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);
        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        $this->product = Product::create([
            'business_id' => $this->business->id, 'name' => 'صنف',
            'price' => 100, 'cost' => 60, 'quantity' => 100, 'active' => true,
        ]);

        $this->actingAs($this->owner);
    }

    private function order(string $status, float $total, float $tax = 0): Order
    {
        $order = Order::create([
            'business_id' => $this->business->id,
            'number' => 'INV-'.str_pad((string) (Order::count() + 1), 6, '0', STR_PAD_LEFT),
            'customer_name' => 'زبون', 'employee_name' => 'سالم',
            'branch_id' => $this->branch->id, 'branch' => 'الرئيسي',
            'payment_method' => 'نقدي', 'payment_status' => 'مدفوع', 'status' => $status,
            'subtotal' => $total - $tax, 'discount' => 0, 'tax' => $tax, 'total' => $total,
            'is_held' => false, 'ordered_at' => now(),
        ]);

        OrderItem::create([
            'order_id' => $order->id, 'product_id' => $this->product->id, 'name' => 'صنف',
            'price' => $total, 'cost' => 60, 'quantity' => 1, 'total' => $total,
        ]);

        return $order;
    }

    /** بيعةٌ كاملة: طلبٌ وصفٌّ في دفتر الحركة معلّقٌ به — كما تكتبها نقطة البيع */
    private function sale(string $status, float $total, string $method = 'نقدي'): Order
    {
        $order = $this->order($status, $total);

        Transaction::create([
            'business_id' => $this->business->id,
            'order_id' => $order->id,
            'reference' => $order->number,
            'description' => 'مبيعات نقطة البيع',
            'method' => $method,
            'kind' => Transaction::SALE,
            'type' => 'دخل',
            'amount' => $total,
            'employee_name' => 'سالم',
            'occurred_at' => now(),
        ]);

        return $order;
    }

    /* ------------------------ الرقم الواحد في الشاشة ------------------------ */

    public function test_the_cards_and_the_chart_now_say_the_same_number(): void
    {
        $this->order('مكتمل', 100);
        $this->order('ملغي', 900);

        $cards = Demo::reportSummary('all')['sales'];
        $chart = array_sum(array_filter(Demo::salesTrend('today')['data'], fn ($v) => $v !== null));

        $this->assertSame(100.0, $cards, 'البطاقات تجمع الملغى');
        $this->assertSame(100.0, $chart);
    }

    public function test_the_vat_return_does_not_declare_a_cancelled_sale(): void
    {
        // أخطرها: ضريبةٌ تُقرّ على بيعةٍ أُلغيت
        $this->order('مكتمل', 105, 5);
        $this->order('ملغي', 945, 45);

        /*
         * ويُسأل المصدرُ الحيّ لا دالّةً على جنبٍ تجيب السؤالَ نفسه.
         *
         * كانت `Demo::vatReport` تُسأل هنا وحدها ولا يقرؤها شيءٌ آخر، وتقدّر
         * ضريبةَ المدخلات بضرب أوامر الشراء في النسبة بينما الشاشةُ تقرأ
         * `tax` من سندات الموردين. جوابان لسؤالٍ واحد، وأحدُهما تخمين —
         * فحُذفت، وبقي الحارسُ على ما يُعرض فعلًا.
         */
        $vat = ReportData::vat($this->business->id, ['range' => 'year']);

        $this->assertSame(5.0, round((float) $vat['summary']['output'], 3));
    }

    public function test_the_dashboard_cards_leave_it_out_too(): void
    {
        $this->order('مكتمل', 100);
        $this->order('ملغي', 900);

        // البطاقة الأولى «مبيعات اليوم» — تُقرأ بموضعها لا باسمها لأن الاسم
        // يمرّ بالترجمة فيختلف بلغة الجلسة
        $today = Demo::adminStats()[0];

        $this->assertStringContainsString('100', (string) $today['value']);
        $this->assertStringNotContainsString('1,000', (string) $today['value']);
    }

    public function test_top_products_and_customers_leave_it_out(): void
    {
        $this->order('مكتمل', 100);
        $this->order('ملغي', 900);

        $this->assertSame(100.0, (float) Demo::topSellingProducts(5, 'all')[0]['revenue']);
    }

    public function test_payment_distribution_leaves_it_out(): void
    {
        $this->order('مكتمل', 100);
        $this->order('ملغي', 900);

        $this->assertSame([100.0], Demo::paymentDistribution('all')['series']);

        // والتوزيع الذي تقرؤه الملفّات هو مصدر المخطّط نفسه — عددًا ونسبةً
        $rows = Demo::paymentBreakdown('all');
        $this->assertCount(1, $rows);
        $this->assertSame(100.0, (float) $rows[0]['total']);
        $this->assertSame(1, $rows[0]['count']);
        $this->assertSame(100, $rows[0]['percent']);
    }

    /**
     * ودفترُ الحركة يستثنيها في التقرير كما يستثنيها في الشاشة.
     *
     * شاشةُ «الحركة المالية» تستثني الملغاة من مجاميعها وتُبقيها في الجدول
     * موسومةً — ومكتوبٌ فوقها لماذا. وتقريرُ الحركة يقرأ الجدول نفسه ولم
     * يكن يستثني شيئًا: يقرأ التاجر «الدخل ١٠٠٠» في التقرير و«١٠٠» في
     * الشاشة عن الفترة نفسها، ولا شيء يقول أيّهما الصحيح.
     */
    public function test_the_movement_report_leaves_a_cancelled_sale_out_of_its_totals(): void
    {
        $this->sale('مكتمل', 100);
        $this->sale('ملغي', 900);

        $report = ReportData::finance($this->business->id, ['range' => 'all']);

        $this->assertSame(100.0, $report['summary']['income'], 'تقريرُ الحركة يجمع بيعةً أُلغيت');
        $this->assertSame(100.0, $report['summary']['net']);

        // والصفُّ يبقى في الجدول موسومًا: مالٌ لم يُقبض لا يُجمع، وسجلٌّ وقع لا يُمحى
        $this->assertCount(2, $report['rows'], 'حُذفت الملغاة من السجلّ بدل أن تُوسم');
        $this->assertTrue(collect($report['rows'])->firstWhere('amount', 900.0)['cancelled']);
        $this->assertFalse(collect($report['rows'])->firstWhere('amount', 100.0)['cancelled']);
    }

    /** وتقريرُ وسائل الدفع يقرأ الرقم نفسه */
    public function test_the_payments_report_leaves_a_cancelled_sale_out(): void
    {
        $this->sale('مكتمل', 100);
        $this->sale('ملغي', 900);

        $report = ReportData::payments($this->business->id, ['range' => 'all']);

        $this->assertSame(100.0, $report['summary']['total'], 'تقريرُ وسائل الدفع يجمع بيعةً أُلغيت');
        $this->assertSame(1, $report['summary']['count']);
        $this->assertSame(100.0, collect($report['rows'])->firstWhere('id', 'نقدي')['total']);
        $this->assertSame(100, collect($report['rows'])->firstWhere('id', 'نقدي')['percent']);
    }

    /** والشاشاتُ الثلاث برقمٍ واحد: الملخّص والحركة والتقرير */
    public function test_the_three_screens_read_one_income(): void
    {
        $this->sale('مكتمل', 100);
        $this->sale('ملغي', 900);

        $report = ReportData::finance($this->business->id, ['range' => 'all'])['summary']['income'];

        $screen = (float) $this->get(route('admin.finance.transactions'))->assertOk()
            ->viewData('page')['props']['summary']['in'];

        $overview = (float) $this->get(route('admin.finance.summary', ['range' => 'all']))->assertOk()
            ->viewData('page')['props']['period']['in'];

        $this->assertSame(100.0, $report);
        $this->assertSame($report, $screen, 'التقريرُ والشاشةُ يقولان في الفترة نفسها قولين');
        $this->assertSame($report, $overview, 'التقريرُ والملخّصُ يقولان في الفترة نفسها قولين');
    }

    /**
     * وبطاقاتُ المالية الأربع لا تنفخها بيعةٌ أُلغيت.
     *
     * وأخطرُها «ضريبة القيمة المضافة المحصّلة»: ضريبةٌ على بيعةٍ أُلغيت لم
     * تُحصَّل، ومن يقرأ الرقم ليُقرّ به يُقرّ بما لا يملك.
     */
    public function test_the_finance_cards_are_not_inflated_by_a_cancelled_sale(): void
    {
        $live = $this->sale('مكتمل', 100);
        $live->update(['tax' => 5]);
        Transaction::where('order_id', $live->id)->update(['tax_amount' => 5]);

        $dead = $this->sale('ملغي', 900);
        Transaction::where('order_id', $dead->id)->update(['tax_amount' => 45]);

        // وتُقرأ بمواضعها لا بأسمائها: الاسم يمرّ بالترجمة فيختلف بلغة الجلسة
        $cards = collect(Demo::financeStats('all'))->pluck('value')->all();

        $this->assertSame(Demo::money(100), $cards[0], 'إجمالي المبيعات نفخته بيعةٌ أُلغيت');
        $this->assertSame(Demo::money(95), $cards[1], 'صافي الإيرادات نفخته بيعةٌ أُلغيت');
        $this->assertSame(Demo::money(5), $cards[2], 'أُقرَّت ضريبةٌ على بيعةٍ أُلغيت');
        $this->assertSame(Demo::money(100), $cards[3], 'المدفوعات النقدية نفخته بيعةٌ أُلغيت');
    }

    public function test_profit_does_not_count_a_cancelled_sale(): void
    {
        $this->order('مكتمل', 100);
        $this->order('ملغي', 900);

        $summary = Demo::reportSummary('all');

        // ١٠٠ بيعًا − ٦٠ تكلفةً = ٤٠. والملغى خارج الطرفين: لا إيرادُه
        // يُجمع ولا تكلفتُه تُخصم — وتكلفةُ بضاعةٍ لم تُبع كانت ستأكل
        // ربحًا حقيقيًّا كما كان إيرادُها ينفخه
        $this->assertSame(60.0, $summary['cogs']);
        $this->assertSame(40.0, $summary['profit']);
    }

    /* ------------------------- والملفُّ يقول ما تقوله ------------------------- */

    /**
     * ملفُّ الحركة المالية يوسم الملغاة ولا يجمعها.
     *
     * نصفا الملفّ كانا يقيسان قياسين: مؤشّراتٌ فوق تستثني الملغاة، وجدولٌ
     * تحت يكتبها صفًّا كأيّ صفّ بلا كلمةٍ تقول ما هي. فيجمع المحاسبُ العمود
     * بنفسه ويخرج برقمٍ لا يطابق ما فوقه.
     */
    public function test_the_finance_csv_marks_a_cancelled_sale(): void
    {
        $this->sale('مكتمل', 100);
        $this->sale('ملغي', 900);

        $csv = $this->get(route('admin.export.transactions', ['range' => 'all']))
            ->assertOk()->streamedContent();

        $this->assertStringContainsString('ملغاة', $csv, 'خرج الملفُّ بصفٍّ ملغًى لا يقول إنه ملغى');

        $lines = array_values(array_filter(explode("\n", trim($csv))));
        $this->assertCount(3, $lines, 'حُذفت الملغاة من الملفّ بدل أن تُوسم');

        // والعمود الأخير هو الحالة: صفٌّ واحد ملغًى وآخرُ حيّ
        $this->assertSame(1, substr_count($csv, 'ملغاة'));
    }

    /** والورقةُ المطبوعة تستثنيها من مجموعها كما تستثنيها مؤشّراتُها */
    public function test_the_finance_pdf_totals_leave_a_cancelled_sale_out(): void
    {
        $this->sale('مكتمل', 100);
        $this->sale('ملغي', 900);

        $rows = Demo::transactions('all', null);

        $this->assertCount(2, $rows);
        $this->assertTrue(collect($rows)->firstWhere('amount', 900.0)['cancelled']);
        $this->assertFalse(collect($rows)->firstWhere('amount', 100.0)['cancelled']);

        $live = collect($rows)->where('cancelled', false);
        $this->assertSame(100.0, (float) $live->where('type', 'دخل')->sum(fn ($t) => abs($t['amount'])));
    }

    /* ------------------------------ الشاشة ------------------------------ */

    public function test_the_list_can_be_filtered_by_status(): void
    {
        $this->order('مكتمل', 100);
        $this->order('ملغي', 900);

        $rows = $this->get(route('admin.orders.index', ['status' => 'ملغي']))
            ->assertOk()->viewData('page')['props']['orders'];

        $this->assertCount(1, $rows);
        $this->assertSame('ملغي', $rows[0]['status']);
    }

    public function test_the_list_can_be_filtered_by_a_date_range(): void
    {
        $this->order('مكتمل', 100)->update(['ordered_at' => now()->subDays(10)]);
        $this->order('مكتمل', 200);

        $rows = $this->get(route('admin.orders.index', [
            'from' => now()->subDays(3)->toDateString(),
            'to' => now()->toDateString(),
        ]))->assertOk()->viewData('page')['props']['orders'];

        $this->assertCount(1, $rows);
    }

    public function test_the_screen_totals_what_was_filtered_not_what_was_shown(): void
    {
        $this->order('مكتمل', 100);
        $this->order('مكتمل', 250);
        $this->order('ملغي', 900);

        $props = $this->get(route('admin.orders.index'))->assertOk()->viewData('page')['props'];

        $this->assertSame(350.0, $props['totalAmount'], 'دخل الملغى مجموع المبيعات');
        $this->assertSame(3, $props['totalCount']);
        $this->assertSame(1, $props['cancelledCount']);
    }

    public function test_the_search_now_finds_the_cashier(): void
    {
        $this->order('مكتمل', 100);

        $rows = $this->get(route('admin.orders.index', ['q' => 'سالم']))
            ->assertOk()->viewData('page')['props']['orders'];

        $this->assertCount(1, $rows);
    }
}
