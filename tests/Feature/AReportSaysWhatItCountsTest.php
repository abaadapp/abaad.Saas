<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Expense;
use App\Models\JournalEntry;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Document\Pdf\Driver as PdfDriver;
use App\Support\Pdf;
use App\Support\ReportColumns;
use App\Support\ReportData;
use App\Support\Reports;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Tests\TestCase;

/**
 * التقريرُ يقول ما يعدّه — بطاقاتُه ونطاقُه وألفاظُه.
 *
 * ستُّ قراءاتٍ كانت تُقرأ على غير ما هي:
 *
 * - بطاقاتُ «العملاء الأكثر إنفاقًا» تُجمع من الخمسين المعروضين، فيُقرأ
 *   «إجمالي الإنفاق» إنفاقَ العملاء كلِّهم.
 * - «إجمالي المصروفات» يجمع المدفوعَ وما لم يُدفع بلا تمييز.
 * - «الربح» في تقرير المنتجات بتكلفة اليوم — تقديرٌ يُقرأ ربحًا محاسبيًّا.
 * - تقريرُ المخزون للنشاط كلِّه ويُقرأ تحت اسم الفرع المختار في الشريط.
 * - أوصافٌ في فهرس التقارير تعد بما ليس في التقرير.
 * - أيقونةٌ مسمّاةٌ في الفهرس لا ترسمها الشاشة.
 *
 * ولا يمسّ شيءٌ منها الحساب: لا دفترَ ولا ربحيّةَ ولا تكلفةَ مبيعات.
 */
class AReportSaysWhatItCountsTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private User $owner;

    private Branch $khuwair;

    private Branch $seeb;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-15 12:00:00');
        // الألفاظُ تُقاس بالعربيّة — لغةُ التاجر هنا
        app()->setLocale('ar');

        $this->shop = Business::create(['name' => 'متجر التقارير', 'email' => 'r@x.local', 'status' => 'نشط']);
        $this->khuwair = Branch::create(['business_id' => $this->shop->id, 'name' => 'الخوير']);
        $this->seeb = Branch::create(['business_id' => $this->shop->id, 'name' => 'السيب']);
        $this->owner = User::create([
            'business_id' => $this->shop->id, 'name' => 'مالك', 'email' => 'o@r.local',
            'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط', 'locale' => 'ar',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /* ============================== أدوات ============================== */

    private function body(TestResponse $res): string
    {
        ob_start();
        $res->baseResponse->sendContent();

        return (string) ob_get_clean();
    }

    private function book(TestResponse $res): Worksheet
    {
        $res->assertOk();
        $path = tempnam(sys_get_temp_dir(), 'xl');
        file_put_contents($path, $this->body($res));
        $sheet = IOFactory::load($path)->getActiveSheet();
        @unlink($path);

        return $sheet;
    }

    /** خلايا الورقة نصًّا واحدًا — للبحث عن عنوانٍ أو سطر */
    private function text(Worksheet $sheet): string
    {
        return json_encode($sheet->toArray(null, false, false), JSON_UNESCAPED_UNICODE);
    }

    private function csv(TestResponse $res): string
    {
        return $this->body($res->assertOk());
    }

    /** رسمُ ورقة PDF قبل المحرّك */
    private function pdf(string $report, array $params = []): string
    {
        $fake = new class implements PdfDriver
        {
            public string $html = '';

            public function sheet(string $html, string $name, array $preset, bool $landscape = false, ?string $runningHeader = null, ?string $context = null): Response
            {
                $this->html = $html;

                return response('PDF');
            }

            public function strip(string $html, string $name, int $widthMm): Response
            {
                return response('PDF');
            }

            public function stripHeight(string $html, int $widthMm): float
            {
                return 1.0;
            }
        };

        $was = Pdf::swap($fake);
        try {
            $this->actingAs($this->owner)->get(route('admin.reports.export.pdf', ['report' => $report] + $params))->assertOk();
        } finally {
            Pdf::swap($was);
        }

        return $fake->html;
    }

    private function order(string $customer, float $total, array $over = []): Order
    {
        static $n = 0;
        $n++;

        return Order::create($over + [
            'business_id' => $this->shop->id, 'branch_id' => $this->khuwair->id, 'branch' => 'الخوير',
            'number' => 'INV-'.$n, 'customer_name' => $customer, 'employee_name' => 'كاشير',
            'status' => 'مكتمل', 'payment_method' => 'نقدي', 'is_held' => false,
            'subtotal' => $total, 'tax' => 0, 'total' => $total, 'ordered_at' => now()->subDays(2),
        ]);
    }

    private function expense(float $amount, string $status, string $type = 'تشغيلية'): Expense
    {
        return Expense::create(['business_id' => $this->shop->id, 'description' => 'سجل-'.$amount,
            'type' => $type, 'amount' => $amount, 'method' => 'نقدي', 'status' => $status,
            'employee_name' => 'المالك', 'spent_at' => now()->subDays(3)]);
    }

    /* ================= ١) العملاء: الجدولُ للأعلى، والبطاقاتُ للكلّ ================= */

    public function test_customer_cards_count_every_buyer_while_the_table_keeps_the_top_fifty(): void
    {
        // ٥٥ عميلًا: الأوّل بطلبين، والبقيّة بطلبٍ واحد بمبالغ متفاوتة
        for ($i = 1; $i <= 55; $i++) {
            $this->order('عميل '.$i, $i);
        }
        $this->order('عميل 1', 4);

        // وما لا يُحسب: ملغًى، ومعلَّق، وبلا اسم، وقبل الفترة، ومتجرٌ آخر
        $this->order('عميل 2', 1000, ['status' => Order::CANCELLED]);
        $this->order('عميل 3', 1000, ['is_held' => true]);
        $this->order('عميل 4', 1000, ['ordered_at' => now()->subMonths(3)]);
        $other = Business::create(['name' => 'جار', 'email' => 'j@x.local', 'status' => 'نشط']);
        $this->order('عميل الجار', 1000, ['business_id' => $other->id, 'branch_id' => null]);

        $data = ReportData::customers($this->shop->id, ['range' => 'month']);

        // الجدولُ كما كان: أعلى خمسين بترتيب الإنفاق
        $this->assertCount(50, $data['rows']);
        $this->assertSame('عميل 55', $data['rows'][0]['name']);

        // والبطاقاتُ على الخمسة والخمسين كلِّهم — لا على الخمسين
        $total = array_sum(range(1, 55)) + 4;  // 1544
        $this->assertSame(55, $data['summary']['customers']);
        $this->assertSame(56, $data['summary']['orders']);
        $this->assertEqualsWithDelta($total, $data['summary']['total'], 0.001);
        $this->assertEqualsWithDelta(round($total / 56, 3), $data['summary']['average'], 0.001);

        // والجدولُ أقلُّ من البطاقات: إنفاقُ الخمسين ليس إنفاقَ الكلّ
        $this->assertLessThan($data['summary']['total'], array_sum(array_column($data['rows'], 'total')));

        // والملفُّ يحمل الجدولَ نفسَه والبطاقاتِ نفسَها
        $sheet = $this->book($this->actingAs($this->owner)->get(route('admin.reports.export.xlsx', ['report' => 'customers', 'range' => 'month'])));
        $this->assertSame(50, substr_count($this->text($sheet), '"عميل '));
        $cards = [];
        foreach ($sheet->toArray(null, false, false) as $row) {
            if (in_array($row[0], ['عملاء اشتروا', 'عدد الطلبات'], true)) {
                $cards[$row[0]] = $row[1];
            }
        }
        $this->assertEquals(['عملاء اشتروا' => '55', 'عدد الطلبات' => '56'], $cards);

        // والشاشةُ تقول إنّ البطاقات للكلّ حين يفترقان
        $this->assertStringContainsString('customers-cards-note', file_get_contents(resource_path('js/Pages/Admin/Reports/Customers.tsx')));
    }

    /* ================= ٢) المصروفات: المسجَّلُ والمدفوعُ وغيرُ المدفوع ================= */

    public function test_expense_cards_separate_paid_from_unpaid_and_the_status_filter_reaches_every_file(): void
    {
        $this->expense(10, Expense::PAID);
        $this->expense(6, Expense::PAID, 'إيجار');
        $this->expense(4, Expense::UNPAID);
        $journal = JournalEntry::count();
        $transactions = Transaction::count();

        // بلا مرشّح: كلُّ السجلّات، والمدفوعُ مفصولٌ عمّا لم يُدفع
        $all = ReportData::expenses($this->shop->id, ['range' => 'month']);
        $this->assertCount(3, $all['rows']);
        $this->assertSame(['total' => 20.0, 'paid' => 16.0, 'unpaid' => 4.0, 'count' => 3], $all['summary']);

        // والحالاتُ من السجلّات نفسها — لا قائمةٌ مكتوبةٌ هنا
        $this->assertEqualsCanonicalizing([Expense::PAID, Expense::UNPAID], array_column($all['options']['statuses'], 'value'));

        // بمرشّح الحالة: ما طابق وحده، والمجاميعُ عليه
        $unpaid = ReportData::expenses($this->shop->id, ['range' => 'month', 'status' => Expense::UNPAID]);
        $this->assertCount(1, $unpaid['rows']);
        $this->assertSame(['total' => 4.0, 'paid' => 0.0, 'unpaid' => 4.0, 'count' => 1], $unpaid['summary']);

        $paid = ReportData::expenses($this->shop->id, ['range' => 'month', 'status' => Expense::PAID]);
        $this->assertSame(['total' => 16.0, 'paid' => 16.0, 'unpaid' => 0.0, 'count' => 2], $paid['summary']);

        // والشاشةُ تمرّر الحالة إلى التقرير
        $props = $this->actingAs($this->owner)->get(route('admin.reports.expenses', ['status' => Expense::UNPAID]))
            ->assertOk()->viewData('page')['props'];
        $this->assertSame(Expense::UNPAID, $props['filters']['status']);
        $this->assertSame(4.0, $props['summary']['total']);
        $this->assertStringContainsString('دفتر الأستاذ', $props['note']);

        $params = ['report' => 'expenses', 'range' => 'month', 'status' => Expense::UNPAID];

        // Excel: صفٌّ واحد، والحالةُ في الرأس، والبطاقاتُ بأرقام المرشَّح
        $sheet = $this->book($this->actingAs($this->owner)->get(route('admin.reports.export.xlsx', $params)));
        $text = $this->text($sheet);
        $this->assertStringContainsString('الحالة: غير مدفوع', $text);
        $this->assertStringContainsString('دفتر الأستاذ', $text);
        $this->assertSame(1, substr_count($text, '"سجل-'));
        $this->assertStringContainsString('إجمالي المصروفات المسجلة', $text);

        // CSV: كذلك
        $csv = $this->csv($this->actingAs($this->owner)->get(route('admin.reports.export.csv', $params)));
        $this->assertStringContainsString('الحالة: غير مدفوع', $csv);
        $this->assertSame(1, substr_count($csv, 'سجل-'));

        // PDF: كذلك
        $html = $this->pdf('expenses', ['range' => 'month', 'status' => Expense::UNPAID]);
        // في الرأس لا في عمود الحالة وحده — ذاك يحمله الجدولُ على كلّ حال
        $this->assertStringContainsString('<span class="k">الحالة:</span> غير مدفوع', $html);
        $this->assertStringContainsString('دفتر الأستاذ', $html);
        $this->assertSame(1, substr_count($html, 'سجل-'));
        $this->assertStringNotContainsString('سجل-10', $html);

        // والقراءةُ لا تكتب: لا قيدَ ولا حركةَ نشأت من التقرير وملفّاته
        $this->assertSame($journal, JournalEntry::count());
        $this->assertSame($transactions, Transaction::count());
    }

    /* ================= ٣) المنتجات: «الربح التقديري» في كلّ موضع ================= */

    public function test_product_profit_is_called_estimated_on_screen_and_in_every_file(): void
    {
        $p = Product::create(['business_id' => $this->shop->id, 'name' => 'باقة', 'price' => 10, 'cost' => 4,
            'quantity' => 5, 'alert_qty' => 1, 'active' => true]);
        $o = $this->order('زبون', 20);
        OrderItem::create(['order_id' => $o->id, 'product_id' => $p->id, 'name' => 'باقة',
            'price' => 10, 'quantity' => 2, 'cost' => 4, 'total' => 20]);

        $columns = array_column(ReportColumns::for('products'), 'label');
        $this->assertContains('الربح التقديري', $columns);
        $this->assertNotContains('الربح', $columns);

        $params = ['report' => 'products', 'range' => 'month'];
        $sheet = $this->book($this->actingAs($this->owner)->get(route('admin.reports.export.xlsx', $params)));
        $this->assertStringContainsString('"الربح التقديري"', $this->text($sheet));
        $this->assertStringNotContainsString('"الربح"', $this->text($sheet));
        $this->assertStringContainsString('تكلفة المنتج الحالية', $this->text($sheet));

        $csv = $this->csv($this->actingAs($this->owner)->get(route('admin.reports.export.csv', $params)));
        $this->assertStringContainsString('الربح التقديري', $csv);
        $this->assertDoesNotMatchRegularExpression('/(^|,)الربح(,|$)/mu', $csv);

        $html = $this->pdf('products', ['range' => 'month']);
        $this->assertStringContainsString('<th>الربح التقديري</th>', $html);
        $this->assertStringNotContainsString('<th>الربح</th>', $html);

        // والشاشة: عنوانُ العمود وملاحظةُ التقدير
        $screen = file_get_contents(resource_path('js/Pages/Admin/Reports/Products.tsx'));
        $this->assertStringContainsString("t('الربح التقديري')}</TableHead>", $screen);
        $this->assertStringNotContainsString("t('الربح')}</TableHead>", $screen);
        $props = $this->actingAs($this->owner)->get(route('admin.reports.products'))->assertOk()->viewData('page')['props'];
        $this->assertStringContainsString('تقدير', $props['note']);

        // والمعادلةُ نفسُها: الإيرادُ ناقصَ الوحداتِ بتكلفة اليوم — لم تتغيّر
        $this->assertSame(12.0, ReportData::products($this->shop->id, ['range' => 'month'])['summary']['profit']);
    }

    /* ================= ٤) المخزون: النشاطُ كلُّه، لا فرعُ الشريط ================= */

    public function test_the_inventory_report_says_it_is_the_whole_business_whatever_branch_is_chosen(): void
    {
        Product::create(['business_id' => $this->shop->id, 'name' => 'ورد', 'price' => 3, 'cost' => 2,
            'quantity' => 30, 'alert_qty' => 2, 'active' => true]);

        $whole = ReportData::inventory($this->shop->id, []);

        // فرعُ السيب مختارٌ في الشريط — والأرقامُ والنطاقُ لا يتغيّران
        $this->actingAs($this->owner)->withSession(['current_branch' => $this->seeb->id]);
        session(['current_branch' => $this->seeb->id]);
        $chosen = ReportData::inventory($this->shop->id, []);

        $this->assertSame($whole['summary'], $chosen['summary']);
        $this->assertSame('النشاط بالكامل — كل الفروع', $chosen['summary']['scope_name']);
        $this->assertSame('الرصيد الحالي', $chosen['periodLabel']);

        // الشاشة: النطاقُ ورصيدُ اللحظة — لا «هذا الشهر» ولا اسمُ الفرع
        $props = $this->actingAs($this->owner)->withSession(['current_branch' => $this->seeb->id])
            ->get(route('admin.reports.inventory'))->assertOk()->viewData('page')['props'];
        $this->assertSame('النشاط بالكامل — كل الفروع', $props['summary']['scope_name']);
        $this->assertSame('الرصيد الحالي', $props['periodLabel']);
        $this->assertStringContainsString('${summary.scope_name} · ${periodLabel}', file_get_contents(resource_path('js/Pages/Admin/Reports/Inventory.tsx')));

        // الملفّات: نطاقُ التقرير مكتوب، واسمُ الفرع المختار لا يظهر
        $sheet = $this->book($this->actingAs($this->owner)->withSession(['current_branch' => $this->seeb->id])
            ->get(route('admin.reports.export.xlsx', ['report' => 'inventory'])));
        $text = $this->text($sheet);
        $this->assertStringContainsString('النشاط بالكامل — كل الفروع', $text);
        $this->assertStringContainsString('الفترة: الرصيد الحالي', $text);
        $this->assertStringNotContainsString('السيب', $text);

        $csv = $this->csv($this->actingAs($this->owner)->withSession(['current_branch' => $this->seeb->id])
            ->get(route('admin.reports.export.csv', ['report' => 'inventory'])));
        $this->assertStringContainsString('النشاط بالكامل — كل الفروع', $csv);
        $this->assertStringNotContainsString('السيب', $csv);

        $this->withSession(['current_branch' => $this->seeb->id]);
        $html = $this->pdf('inventory');
        $this->assertStringContainsString('النشاط بالكامل — كل الفروع', $html);
        $this->assertStringNotContainsString('السيب', $html);
    }

    /* ================= ٥) الفهرس: الوصفُ يصف التقرير ================= */

    public function test_the_report_index_describes_what_each_report_shows(): void
    {
        $desc = array_column(Reports::ALL, 'desc', 'key');

        $this->assertSame('مبيعات كل موظف في الفترة المختارة وفرعه وحالته.', $desc['staff']);
        $this->assertSame('المقبوضات والمدفوعات وصافي الحركة المالية خلال الفترة المختارة.', $desc['finance']);
        $this->assertSame('استخدام الكوبونات وقيمة الخصومات والإيراد المرتبط بها.', $desc['marketing']);

        // وما كان يعد بما ليس فيه لا يعود
        $this->assertStringNotContainsString('هذا الشهر', $desc['staff']);
        $this->assertStringNotContainsString('أرصدة الحسابات البنكية', $desc['finance']);
        $this->assertStringNotContainsString('شرائح العملاء', $desc['marketing']);
    }

    /* ================= ٦) الفهرس: كلُّ أيقونةٍ مسمّاةٍ مرسومة ================= */

    public function test_every_icon_named_in_the_report_index_is_drawn_by_its_screen(): void
    {
        $source = file_get_contents(resource_path('js/Pages/Admin/Reports/Index.tsx'));
        preg_match('/const ICONS[^{]*\{(.*?)\n\};/s', $source, $block);
        $this->assertNotEmpty($block, 'لا خريطةَ أيقوناتٍ في الفهرس');

        $missing = [];
        foreach (array_unique(array_column(Reports::ALL, 'icon')) as $icon) {
            if (! preg_match('/^\s*(\''.preg_quote($icon, '/').'\'|'.preg_quote($icon, '/').'):/m', $block[1])) {
                $missing[] = $icon;
            }
        }

        // أيقونةٌ بلا مقابل تُرسم رسمًا بيانيًّا عامًّا — بطاقةُ الجرد كانت كذلك
        $this->assertSame([], $missing, 'أيقوناتٌ في Reports::ALL لا ترسمها الشاشة: '.implode(', ', $missing));
    }
}
