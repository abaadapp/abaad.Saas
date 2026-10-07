<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Expense;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Support\Books;
use App\Support\Demo;
use App\Support\Document\Pdf\Driver as PdfDriver;
use App\Support\Pdf;
use App\Support\Reports;
use App\Support\SalesChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Response;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * تقريرُ المبيعات يقرأ الفرعَ الذي اختاره الشريط — كما تقرؤه اللوحة.
 *
 * ═══ الحال التي أنشأت هذا الملفّ ═══
 *
 * اللوحةُ كانت تتبع الفرع المختار في بطاقاتها ومخطّطاتها وأفضلِ أصنافها،
 * وتقريرُ المبيعات لا يتبعه في شيء: يُختار «مسقط» فيبقى الملخّصُ والمخطّطُ
 * وتوزيعُ الدفع وأفضلُ المبيعات على المتجر كلِّه. فيقرأ التاجر في شاشتين
 * رقمين لشهرٍ واحد، ولا شيء يقول أيّهما على أيّ نطاق.
 *
 * ═══ وما يُحرَس هنا ═══
 *
 * أنّ سلسلةَ التقرير كلَّها تتبع الفرع: الملخّصُ والتكلفةُ والمخطّطُ
 * وتوزيعُ وسائل الدفع وأفضلُ الأصناف — لا واحدٌ منها دون إخوته، فورقةٌ
 * نصفُها فرعٌ ونصفُها شركةٌ أسوأ من ورقتين.
 *
 * وأنّ «كل الفروع» تبقى حصيلةَ النشاط كما كانت.
 *
 * وأنّ الفرعَ والقناةَ مرشِّحان يتقاطعان ولا يلغي أحدهما الآخر.
 *
 * وأنّ الملفَّ الخارجَ من التقرير يحمل نطاقَ الشاشة نفسَه: شاشةُ فرعٍ
 * وملفُّ شركةٍ خدعةٌ تُرسَل إلى المحاسب. وبالمرشِّحين كليهما — فالفرعُ
 * كان يخرج إلى الملفّات والقناةُ تُترك عندها، فجدولُ وسائل الدفع في
 * الملفّات الثلاثة يعدّ قنواتٍ لم تُختر ويخالف الملخّصَ فوقه في الملفّ
 * الواحد.
 *
 * ═══ وما لا يُحرَس هنا لأنّه لم يتغيّر ═══
 *
 * `activeBranchId` وبيعُ الكاشير وخصمُ المخزون — «كل الفروع» عرضٌ لا موضعُ
 * بيع، والبيعُ يقع في فرعٍ بعينه كما كان. وحارسُ ذلك أدناه أيضًا: لئلّا
 * يُظنّ أنّ مرشِّح العرض صار يحكم موضعَ البيع.
 */
class TheSalesReportFollowsTheChosenBranchTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private User $owner;

    private Branch $muscat;

    private Branch $salalah;

    private Product $rose;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->setLocale('ar');

        $this->shop = Business::create(['name' => 'متجري', 'type' => 'عام', 'status' => 'نشط']);
        $this->muscat = Branch::create(['business_id' => $this->shop->id, 'name' => 'مسقط']);
        $this->salalah = Branch::create(['business_id' => $this->shop->id, 'name' => 'صلالة']);

        $this->owner = User::create([
            'business_id' => $this->shop->id, 'name' => 'المالك', 'email' => 'o@abaad.om',
            'password' => 'secret', 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->rose = Product::create([
            'business_id' => $this->shop->id, 'name' => 'باقة ورد',
            'price' => 100, 'cost' => 60, 'quantity' => 500, 'alert_qty' => 1, 'active' => true,
        ]);
    }

    /** بيعةٌ مكتوبةٌ مباشرةً — الفرعُ والقناةُ والمبلغُ هي كلُّ ما يُقرأ هنا */
    private function sell(
        Branch $branch,
        float $total,
        ?string $channel = SalesChannel::POS,
        string $method = 'نقدي',
        ?Product $product = null,
        int $qty = 1,
    ): Order {
        $order = Order::create([
            'business_id' => $this->shop->id, 'branch_id' => $branch->id,
            'customer_name' => 'زبون', 'employee_name' => 'المالك', 'user_id' => $this->owner->id,
            'number' => 'INV-'.Order::count().'-'.$branch->id, 'status' => 'مكتمل',
            'payment_status' => 'مدفوع', 'is_held' => false, 'payment_method' => $method,
            'channel' => $channel,
            'subtotal' => $total, 'discount' => 0, 'tax' => 0, 'total' => $total,
            'ordered_at' => now(),
        ]);

        $p = $product ?? $this->rose;

        OrderItem::create([
            'order_id' => $order->id, 'product_id' => $p->id, 'name' => $p->name,
            'price' => $total / max($qty, 1), 'quantity' => $qty,
            'cost' => (float) $p->cost, 'total' => $total,
        ]);

        return $order;
    }

    /** التقريرُ كما تبنيه الشاشةُ والملفّات — بجلسةٍ عليها فرعٌ أو بلا */
    private function report(?int $branchId = null, ?string $channel = null): array
    {
        session($branchId ? ['current_branch' => $branchId] : []);
        if (! $branchId) {
            session()->forget('current_branch');
        }

        $this->actingAs($this->owner);

        return Reports::salesReport('month', $channel);
    }

    private function seriesTotal(array $report): float
    {
        return round(array_sum(array_map(fn ($v) => (float) $v, $report['salesSeries']['data'])), 3);
    }

    /* ═══════════════ الملخّص ═══════════════ */

    /** مبيعاتُ الفرع وحدَه — لا مبيعاتُ جاره */
    public function test_the_summary_counts_the_chosen_branch_only(): void
    {
        $this->sell($this->muscat, 100);
        $this->sell($this->salalah, 700);

        $this->assertSame(100.0, (float) $this->report($this->muscat->id)['summary']['sales']);
        $this->assertSame(700.0, (float) $this->report($this->salalah->id)['summary']['sales']);
    }

    /** و«كل الفروع» حصيلةُ النشاط كما كانت */
    public function test_every_branch_still_reads_the_whole_shop(): void
    {
        $this->sell($this->muscat, 100);
        $this->sell($this->salalah, 700);

        $this->assertSame(800.0, (float) $this->report()['summary']['sales']);
    }

    /** والتكلفةُ تتبع الفرع كما تتبعه المبيعات — وإلّا قُرئت خسارةٌ لم تقع */
    public function test_the_cost_of_goods_follows_the_same_branch(): void
    {
        $this->sell($this->muscat, 100);
        $this->sell($this->salalah, 500, qty: 5);

        // مسقط: قطعةٌ واحدة بتكلفة 60
        $this->assertSame(60.0, (float) $this->report($this->muscat->id)['summary']['cogs']);
        // صلالة: خمسٌ بتكلفة 300
        $this->assertSame(300.0, (float) $this->report($this->salalah->id)['summary']['cogs']);
        $this->assertSame(360.0, (float) $this->report()['summary']['cogs']);
    }

    /**
     * والمصروفاتُ لا تُنسب إلى فرع — فيُقال «مُجمل الربح» لا «صافيه».
     *
     * جدولُ المصروفات لا عمودَ فرعٍ فيه: الإيجارُ والرواتب تُكتب للمتجر.
     * فطرحُها كاملةً من مبيعات فرعٍ واحد يجعله خاسرًا وهو رابح — ورقمٌ
     * كهذا يُتّخذ عليه قرارُ إغلاق. وهي القاعدةُ نفسُها في اختيار قناة.
     */
    public function test_a_branch_report_reads_gross_profit_and_carries_no_company_expense(): void
    {
        $this->sell($this->muscat, 100);
        Books::recordExpense(Expense::create([
            'business_id' => $this->shop->id, 'type' => 'إيجار', 'description' => 'إيجار المحلّ',
            'amount' => 400, 'method' => 'نقدي', 'employee_name' => 'المالك',
            'status' => 'مدفوع', 'spent_at' => now(),
        ]));

        $branch = $this->report($this->muscat->id)['summary'];

        $this->assertSame('gross', $branch['profit_kind']);
        $this->assertSame(0.0, (float) $branch['expenses']);
        // 100 − 0 ضريبة − 60 تكلفة، بلا إيجارٍ يخصّ المتجر كلَّه
        $this->assertSame(40.0, (float) $branch['profit']);

        // وللمتجر كلِّه يبقى «صافي الربح» على تعريفه: بالمصروفات
        $whole = $this->report()['summary'];
        $this->assertSame('net', $whole['profit_kind']);
        $this->assertSame(400.0, (float) $whole['expenses']);
        $this->assertSame(-360.0, (float) $whole['profit']);
    }

    /* ═══════════════ المخطّط ═══════════════ */

    /** مخطّطُ الفرع لا يرسم بيعَ غيره */
    public function test_the_trend_draws_the_chosen_branch_only(): void
    {
        $this->sell($this->muscat, 100);
        $this->sell($this->salalah, 700);

        $this->assertSame(100.0, $this->seriesTotal($this->report($this->muscat->id)));
        $this->assertSame(800.0, $this->seriesTotal($this->report()));
    }

    /* ═══════════════ وسائل الدفع ═══════════════ */

    /** وتوزيعُ الدفع من طلبات ذلك الفرع */
    public function test_payment_distribution_comes_from_the_branchs_own_orders(): void
    {
        $this->sell($this->muscat, 100, method: 'نقدي');
        $this->sell($this->salalah, 700, method: 'بطاقة');

        $muscat = $this->report($this->muscat->id)['paymentDistribution'];
        $this->assertSame([100.0], array_map(fn ($v) => (float) $v, $muscat['series']));
        $this->assertCount(1, $muscat['labels']);

        $whole = $this->report()['paymentDistribution'];
        $this->assertCount(2, $whole['labels']);
    }

    /* ═══════════════ أفضل الأصناف ═══════════════ */

    /** وأفضلُ ما يُباع في الفرع من طلباته هو */
    public function test_top_products_come_from_the_branchs_orders(): void
    {
        $candle = Product::create([
            'business_id' => $this->shop->id, 'name' => 'شمعة',
            'price' => 50, 'cost' => 20, 'quantity' => 100, 'alert_qty' => 1, 'active' => true,
        ]);

        $this->sell($this->muscat, 100);                     // باقة ورد
        $this->sell($this->salalah, 500, product: $candle);  // شمعة

        $names = fn (array $r) => array_column($r['topSellingProducts'], 'name');

        $this->assertSame(['باقة ورد'], $names($this->report($this->muscat->id)));
        $this->assertSame(['شمعة'], $names($this->report($this->salalah->id)));
        $this->assertEqualsCanonicalizing(['باقة ورد', 'شمعة'], $names($this->report()));
    }

    /* ═══════════════ الفرع والقناة معًا ═══════════════ */

    /**
     * مرشِّحان يتقاطعان — ولا يلغي أحدهما الآخر.
     *
     * وهذا موضعُ الخطأ المحتمل: من يكتب الفرعَ مكان القناة يجعل اختيار
     * «الموقع» يُظهر بيعَ الكاشير، أو اختيارَ الفرع يُلغي حصرَ القناة.
     */
    public function test_branch_and_channel_narrow_together(): void
    {
        $this->sell($this->muscat, 100, channel: SalesChannel::POS);
        $this->sell($this->muscat, 300, channel: SalesChannel::WEBSITE);
        $this->sell($this->salalah, 700, channel: SalesChannel::WEBSITE);

        $this->assertSame(300.0, (float) $this->report($this->muscat->id, SalesChannel::WEBSITE)['summary']['sales']);
        $this->assertSame(100.0, (float) $this->report($this->muscat->id, SalesChannel::POS)['summary']['sales']);
        $this->assertSame(1000.0, (float) $this->report(null, SalesChannel::WEBSITE)['summary']['sales']);
        $this->assertSame(400.0, (float) $this->report($this->muscat->id)['summary']['sales']);
    }

    /* ═══════════════ العزل ═══════════════ */

    /**
     * وفرعُ متجرٍ آخر في الجلسة لا يُرشِّح ولا يُسمّى.
     *
     * الحارسُ على مسار التبديل يمنع الدخول، لكنّ جلسةً عمّرت — أو نُقل
     * فرعٌ إلى مالكٍ آخر — تترك رقمًا عالقًا. فلو رُشِّح به لَقرأ التاجر
     * شاشةً فارغة، ولو سُمّي لَقرأ اسمَ فرعِ جاره فوق أرقامه.
     */
    public function test_a_stale_branch_of_another_shop_neither_filters_nor_names(): void
    {
        $theirs = Business::create(['name' => 'محل آخر', 'type' => 'عام', 'status' => 'نشط']);
        $theirBranch = Branch::create(['business_id' => $theirs->id, 'name' => 'فرعهم']);

        $this->sell($this->muscat, 100);
        $this->sell($this->salalah, 700);

        $report = $this->report($theirBranch->id);

        $this->assertSame(800.0, (float) $report['summary']['sales'], 'رشّح بفرعٍ ليس له');
        $this->assertSame('كل الفروع', $report['branchName']);
        $this->assertNull($report['branchId']);
    }

    /** واختيارُ «كل الفروع» يمحو الاختيار من الجلسة */
    public function test_choosing_every_branch_clears_the_session(): void
    {
        $this->actingAs($this->owner)
            ->withSession(['current_branch' => $this->muscat->id])
            ->get(route('admin.branch.switch', 'all'));

        $this->assertNull(session('current_branch'));
    }

    /* ═══════════════ الملفّات تتبع الشاشة ═══════════════ */

    /** ورقةُ إكسل تحمل أرقامَ الفرع نفسِه لا أرقامَ الشركة */
    public function test_the_xlsx_carries_the_same_numbers_as_the_screen(): void
    {
        $this->sell($this->muscat, 100);
        $this->sell($this->salalah, 700);

        $res = $this->actingAs($this->owner)
            ->withSession(['current_branch' => $this->muscat->id])
            ->get(route('admin.reports.xlsx'));

        $res->assertOk();
        $path = tempnam(sys_get_temp_dir(), 'xlsx').'.xlsx';
        file_put_contents($path, $res->streamedContent());
        $sheet = IOFactory::load($path)->getActiveSheet();
        @unlink($path);

        $cells = [];
        foreach ($sheet->toArray() as $row) {
            $cells[] = implode('|', array_map(fn ($c) => (string) $c, $row));
        }
        $text = implode("\n", $cells);

        $this->assertStringContainsString('مسقط', $text, 'الورقة لا تقول أيّ فرعٍ تقيس');
        $this->assertStringContainsString('100', $text);
        $this->assertStringNotContainsString('700', $text, 'تسرّبت أرقامُ فرعٍ آخر إلى ورقة الفرع');
    }

    /** وملفُّ CSV كذلك */
    public function test_the_csv_carries_the_branch_scope(): void
    {
        $this->sell($this->muscat, 100);
        $this->sell($this->salalah, 700);

        $res = $this->actingAs($this->owner)
            ->withSession(['current_branch' => $this->muscat->id])
            ->get(route('admin.export.reports'));

        $res->assertOk();
        $body = $res->streamedContent();

        $this->assertStringContainsString('مسقط', $body);
        $this->assertStringContainsString('100', $body);
        $this->assertStringNotContainsString('700', $body);
    }

    /* ═══════════════ والمرشِّحان معًا يخرجان إلى الملفّات ═══════════════ */

    /**
     * أربعُ بيعاتٍ تفرّق الفرعَ عن أخيه والقناةَ عن أختها.
     *
     * ولكلّ بيعةٍ وسيلةُ دفعٍ تُعرَف بها وحدَها: رقمٌ في نصّ ملفٍّ يصدّقه
     * رقمٌ يشبهه في عمودٍ آخر، أمّا اسمُ الوسيلة فلا يُكتب إلّا في جدول
     * التوزيع. فإن ظهر «شيك» في ورقةِ مسقطٍ على الموقع، قيل أيُّ بيعةٍ
     * تسرّبت — لا أنّ رقمًا لا يطابق.
     */
    private function fourWays(): void
    {
        $this->sell($this->muscat, 321, channel: SalesChannel::WEBSITE, method: 'مدى');
        $this->sell($this->muscat, 411, channel: SalesChannel::POS, method: 'نقدي');
        $this->sell($this->salalah, 511, channel: SalesChannel::WEBSITE, method: 'تحويل بنكي');
        $this->sell($this->salalah, 611, channel: SalesChannel::POS, method: 'شيك');
    }

    /** توزيعُ الشاشة على صورةٍ تُقارن بها الملفّات: [الوسيلة => المبلغ] */
    private function screenPayments(?int $branchId, ?string $channel): array
    {
        $d = $this->report($branchId, $channel)['paymentDistribution'];

        return array_combine($d['labels'], array_map(fn ($v) => round((float) $v, 3), $d['series']));
    }

    /**
     * الصفوفُ التي تحت عنوانٍ حتّى أوّل صفٍّ فارغ — [الوسيلة => المبلغ].
     *
     * ويُقرأ الجدولُ بعينه لا نصُّ الملفّ كلُّه: مبلغٌ غائبٌ عن جدول الدفع
     * قد يكون حاضرًا في جدول المبيعات فوقَه، فيمرّ الفحصُ على خللٍ قائم.
     */
    private function sectionRows(array $rows, string $heading): array
    {
        $out = [];
        $inside = false;

        foreach ($rows as $row) {
            $first = trim((string) ($row[0] ?? ''));

            if (! $inside) {
                $inside = $first === $heading;

                continue;
            }
            if ($first === '') {
                break;
            }
            if ($first === __('الوسيلة')) {
                continue;
            }

            $out[$first] = round((float) ($row[1] ?? 0), 3);
        }

        $this->assertNotEmpty($out, "لا صفوفَ تحت «{$heading}»");

        return $out;
    }

    /** جدولُ وسائل الدفع من ورقة إكسل */
    private function xlsxPayments(?int $branchId, ?string $channel): array
    {
        $res = $this->actingAs($this->owner)
            ->withSession($branchId ? ['current_branch' => $branchId] : [])
            ->get(route('admin.reports.xlsx', ['channel' => $channel]));

        $res->assertOk();
        $path = tempnam(sys_get_temp_dir(), 'xlsx').'.xlsx';
        file_put_contents($path, $res->streamedContent());
        $rows = IOFactory::load($path)->getActiveSheet()->toArray();
        @unlink($path);

        return $this->sectionRows($rows, __('توزيع وسائل الدفع'));
    }

    /** ومن ملفّ CSV */
    private function csvPayments(?int $branchId, ?string $channel): array
    {
        $res = $this->actingAs($this->owner)
            ->withSession($branchId ? ['current_branch' => $branchId] : [])
            ->get(route('admin.export.reports', ['channel' => $channel]));

        $res->assertOk();
        $body = str_replace("\xEF\xBB\xBF", '', $res->streamedContent());
        $rows = array_map(fn ($l) => str_getcsv($l, escape: ''), explode("\n", trim($body)));

        return $this->sectionRows($rows, '— '.__('توزيع وسائل الدفع').' —');
    }

    /**
     * ورسمُ ورقة PDF يُلتقط قبل المحرّك.
     *
     * فالمخرَجُ ملفٌّ مضغوطٌ لا يُقرأ فيه نصّ، والمحرّكُ خلف واجهةٍ تُبدَّل
     * (`Pdf::swap`) — فيُقرأ ما كان سيُطبع.
     */
    private function pdfHtml(?int $branchId, ?string $channel): string
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
            $this->actingAs($this->owner)
                ->withSession($branchId ? ['current_branch' => $branchId] : [])
                ->get(route('admin.reports.pdf', ['channel' => $channel]))
                ->assertOk();
        } finally {
            Pdf::swap($was);
        }

        $at = mb_strpos($fake->html, __('توزيع وسائل الدفع'));
        $this->assertNotFalse($at, 'لا جدولَ وسائلِ دفعٍ في الورقة');
        $slice = mb_substr($fake->html, $at);
        $end = mb_strpos($slice, '</table>');

        return $end === false ? $slice : mb_substr($slice, 0, $end);
    }

    /** الشاشةُ تحصر الفرعَ والقناةَ معًا في كلّ أرقامها */
    public function test_the_screen_narrows_to_one_branch_and_one_channel(): void
    {
        $this->fourWays();

        $report = $this->report($this->muscat->id, SalesChannel::WEBSITE);

        $this->assertSame(321.0, (float) $report['summary']['sales'], 'الملخّص');
        $this->assertSame(321.0, $this->seriesTotal($report), 'المخطّط');
        $this->assertSame(['مدى' => 321.0], $this->screenPayments($this->muscat->id, SalesChannel::WEBSITE));
        $this->assertSame([321.0], array_map(fn ($p) => round((float) $p['revenue'], 3), $report['topSellingProducts']));
        $this->assertSame(1, (int) $report['topSellingProducts'][0]['sold'], 'دخلت بيعةُ كاشيرٍ أو بيعةُ صلالة');
    }

    /** وورقةُ إكسل تحمل جدولَ الدفع نفسَه — لا قناةً لم تُختر */
    public function test_the_xlsx_payment_table_narrows_with_the_screen(): void
    {
        $this->fourWays();

        $this->assertSame(['مدى' => 321.0], $this->xlsxPayments($this->muscat->id, SalesChannel::WEBSITE));
        $this->assertSame(
            $this->screenPayments($this->muscat->id, SalesChannel::WEBSITE),
            $this->xlsxPayments($this->muscat->id, SalesChannel::WEBSITE),
            'الورقةُ تخالف الشاشة',
        );
    }

    /** وملفُّ CSV كذلك */
    public function test_the_csv_payment_table_narrows_with_the_screen(): void
    {
        $this->fourWays();

        $this->assertSame(['مدى' => 321.0], $this->csvPayments($this->muscat->id, SalesChannel::WEBSITE));
        $this->assertSame(
            $this->screenPayments($this->muscat->id, SalesChannel::WEBSITE),
            $this->csvPayments($this->muscat->id, SalesChannel::WEBSITE),
            'الملفُّ يخالف الشاشة',
        );
    }

    /** وورقةُ PDF كذلك */
    public function test_the_pdf_payment_table_narrows_with_the_screen(): void
    {
        $this->fourWays();

        $slice = $this->pdfHtml($this->muscat->id, SalesChannel::WEBSITE);

        $this->assertStringContainsString('مدى', $slice);
        $this->assertStringContainsString('321', $slice);

        foreach (['نقدي' => '411', 'تحويل بنكي' => '511', 'شيك' => '611'] as $method => $amount) {
            $this->assertStringNotContainsString($method, $slice, "تسرّبت بيعةُ «{$method}» إلى الورقة");
            $this->assertStringNotContainsString($amount, $slice, "تسرّب مبلغُ «{$method}» إلى الورقة");
        }
    }

    /** وفرعٌ بلا قناةٍ يبقى على قنواته كلِّها — في الشاشة والملفّات */
    public function test_one_branch_with_no_channel_keeps_all_its_channels(): void
    {
        $this->fourWays();

        $want = ['نقدي' => 411.0, 'مدى' => 321.0]; // مرتَّبةٌ بالمبلغ لا بالاسم

        $this->assertSame($want, $this->screenPayments($this->muscat->id, null));
        $this->assertSame($want, $this->xlsxPayments($this->muscat->id, null));
        $this->assertSame($want, $this->csvPayments($this->muscat->id, null));
    }

    /** و«كل الفروع» بقناةٍ واحدة: تلك القناةُ من فروع النشاط كلِّها */
    public function test_every_branch_with_one_channel_keeps_only_that_channel(): void
    {
        $this->fourWays();

        $want = ['تحويل بنكي' => 511.0, 'مدى' => 321.0];

        $this->assertSame($want, $this->screenPayments(null, SalesChannel::WEBSITE));
        $this->assertSame($want, $this->xlsxPayments(null, SalesChannel::WEBSITE));
        $this->assertSame($want, $this->csvPayments(null, SalesChannel::WEBSITE));
    }

    /* ═══════════════ والملفُّ يقول بأيّ قناةٍ رُشِّح ═══════════════ */

    /** صفوفُ ترويسة CSV — [الاسم => القيمة] حتّى أوّل صفٍّ فارغ */
    private function csvMeta(?int $branchId, ?string $channel): array
    {
        $res = $this->actingAs($this->owner)
            ->withSession($branchId ? ['current_branch' => $branchId] : [])
            ->get(route('admin.export.reports', ['channel' => $channel]));

        $res->assertOk();
        $body = str_replace("\xEF\xBB\xBF", '', $res->streamedContent());
        $out = [];

        foreach (explode("\n", trim($body)) as $line) {
            $row = str_getcsv($line, escape: '');
            if (trim((string) ($row[0] ?? '')) === '') {
                break;
            }
            $out[trim((string) $row[0])] = trim((string) ($row[1] ?? ''));
        }

        return $out;
    }

    /** وعمودُ ترويسة ورقة إكسل حتّى أوّل صفٍّ فارغ */
    private function xlsxHeader(?int $branchId, ?string $channel): array
    {
        $res = $this->actingAs($this->owner)
            ->withSession($branchId ? ['current_branch' => $branchId] : [])
            ->get(route('admin.reports.xlsx', ['channel' => $channel]));

        $res->assertOk();
        $path = tempnam(sys_get_temp_dir(), 'xlsx').'.xlsx';
        file_put_contents($path, $res->streamedContent());
        $rows = IOFactory::load($path)->getActiveSheet()->toArray();
        @unlink($path);

        $out = [];
        foreach ($rows as $row) {
            if (trim((string) ($row[0] ?? '')) === '') {
                break;
            }
            $out[] = trim((string) $row[0]);
        }

        return $out;
    }

    /** ورسمُ ورقة PDF كاملًا — ترويستُها تُقرأ فيه */
    private function pdfRaw(?int $branchId, ?string $channel): string
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
            $this->actingAs($this->owner)
                ->withSession($branchId ? ['current_branch' => $branchId] : [])
                ->get(route('admin.reports.pdf', ['channel' => $channel]))
                ->assertOk();
        } finally {
            Pdf::swap($was);
        }

        return $fake->html;
    }

    /**
     * القناةُ المختارةُ مكتوبةٌ في الملفّات الثلاثة.
     *
     * فالأرقامُ صارت تُرشَّح بها، والملفُّ يغادر الشاشةَ ولا مبدّلَ فوقه:
     * مبيعاتُ الموقع وحدَه تُقرأ بيعَ الشهر كلَّه إن لم يُكتب بأيّ قناةٍ
     * رُشِّحت — وهي أقلُّ من الحقيقة، فتُقرأ تراجعًا.
     */
    public function test_the_files_name_the_chosen_channel(): void
    {
        $this->fourWays();

        $this->assertStringContainsString(
            __('القناة:').' '.__('الموقع الإلكتروني'),
            $this->pdfRaw($this->muscat->id, SalesChannel::WEBSITE),
        );
        $this->assertSame(__('الموقع الإلكتروني'), $this->csvMeta($this->muscat->id, SalesChannel::WEBSITE)[__('القناة')] ?? null);
        $this->assertContains(
            __('القناة').': '.__('الموقع الإلكتروني'),
            $this->xlsxHeader($this->muscat->id, SalesChannel::WEBSITE),
        );
    }

    /**
     * وبلا مُرشِّحٍ تقول «كل القنوات» — لا «غير محدّدة».
     *
     * وهذا موضعُ الخطأ المحتمل: `SalesChannel::label(null)` تردّ «غير
     * محدّدة»، وهي صفةُ **طلبٍ** لا يُعرف من أيّ بابٍ دخل — طلباتُ ما قبل
     * العمود. أمّا `null` في المُرشِّح فغيابُه: القنواتُ كلُّها. فمن كتبها
     * بتلك الدالّة أخرج ورقةً تقول «غير محدّدة» فوق بيع المتجر كلِّه.
     */
    public function test_no_channel_reads_every_channel_not_unspecified(): void
    {
        $this->fourWays();

        $pdf = $this->pdfRaw($this->muscat->id, null);
        $this->assertStringContainsString(__('القناة:').' '.__('كل القنوات'), $pdf);
        $this->assertStringNotContainsString(__('غير محدّدة'), $pdf);

        $this->assertSame(__('كل القنوات'), $this->csvMeta($this->muscat->id, null)[__('القناة')] ?? null);
        $this->assertContains(__('القناة').': '.__('كل القنوات'), $this->xlsxHeader($this->muscat->id, null));
    }

    /**
     * وقناةُ «غير محدّدة» تبقى على اسمها حين تُختار مُرشِّحًا.
     *
     * فهي قيمةٌ تصل (`unknown`) لا `null` — ولو خُلطت بالغياب لَقرأ من
     * يُرشِّح بطلبات ما قبل العمود ورقةً تقول «كل القنوات» فوق جزءٍ منها.
     */
    public function test_the_unspecified_channel_keeps_its_own_name(): void
    {
        $this->sell($this->muscat, 90, channel: null, method: 'نقدي');

        $pdf = $this->pdfRaw($this->muscat->id, SalesChannel::UNKNOWN);
        $this->assertStringContainsString(__('القناة:').' '.__('غير محدّدة'), $pdf);
        $this->assertStringNotContainsString(__('كل القنوات'), $pdf);

        $this->assertSame(__('غير محدّدة'), $this->csvMeta($this->muscat->id, SalesChannel::UNKNOWN)[__('القناة')] ?? null);
        $this->assertContains(__('القناة').': '.__('غير محدّدة'), $this->xlsxHeader($this->muscat->id, SalesChannel::UNKNOWN));
    }

    /**
     * وسطرُ القناة لا يلتصق بأوّل جدولٍ في الورقة.
     *
     * فالفراغُ الفاصلُ كان بين الترويسة وأوّل عنوان، والسطرُ الجديد يشغله
     * إن لم يُدفَع الجدولُ سطرًا — فتُقرأ «القناة» رأسًا لجدول المؤشّرات.
     */
    public function test_the_channel_line_does_not_touch_the_first_table(): void
    {
        $this->fourWays();

        $header = $this->xlsxHeader($this->muscat->id, SalesChannel::WEBSITE);

        // آخرُ الترويسة هو سطرُ القناة — فالقطعُ وقع على الفراغ الذي بعده
        $this->assertSame(__('القناة').': '.__('الموقع الإلكتروني'), end($header));
        $this->assertNotContains(__('المؤشرات الرئيسية'), $header, 'التصق الجدولُ بالترويسة');
    }

    /* ═══════════════ الكاشيرُ لم يُمسّ ═══════════════ */

    /**
     * و«كل الفروع» عرضٌ لا موضعُ بيع.
     *
     * `activeBranchId` تبقى على معناها: البيعُ يقع في فرعٍ بعينه، فإن لم
     * يُختر واحدٌ فأوّلُ فروع المتجر. ولو تبع هذا مرشِّحَ العرض لَوقع بيعٌ
     * بلا فرعٍ يُخصم منه مخزونُه.
     */
    public function test_the_operational_branch_is_untouched_by_the_view_filter(): void
    {
        $this->actingAs($this->owner);

        session()->forget('current_branch');
        $this->assertNull(Demo::currentBranchId());
        $this->assertSame($this->muscat->id, Demo::activeBranchId(), 'البيع بلا فرع');

        session(['current_branch' => $this->salalah->id]);
        $this->assertSame($this->salalah->id, Demo::activeBranchId());
    }
}
