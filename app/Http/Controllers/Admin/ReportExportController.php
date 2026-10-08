<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Activity;
use App\Support\Demo;
use App\Support\Exports\Exports;
use App\Support\Exports\Workbook;
use App\Support\Lists\BusinessesList;
use App\Support\Lists\CustomersList;
use App\Support\Lists\ExpensesList;
use App\Support\Lists\InventoryList;
use App\Support\Lists\OrdersList;
use App\Support\Lists\PlatformInvoicesList;
use App\Support\Lists\ProductsList;
use App\Support\Lists\SuppliersList;
use App\Support\Lists\TransactionsList;
use App\Support\Reports;
use App\Support\SalesChannel;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;

class ReportExportController extends Controller
{
    /** الصف الحالي أثناء بناء الورقة */
    private int $row = 5;

    /**
     * رأسُ عمودٍ ماليّ — باسمه ثمّ رمزِ عملة المتجر بين قوسين.
     *
     * وكان مكتوبًا «(ر.ع)» في عشرة رؤوس: تاجرٌ في دبي يفتح ملفَّ إكسل
     * مصدَّرًا من نظامه فيجد عمودًا اسمُه «المبيعات (ر.ع)» وأرقامُه بالدرهم.
     * ولا يظهر في اختبارٍ مكتوبٍ بالريال — انظر `Support\Money`.
     */
    private function moneyHead(string $label): string
    {
        return __($label).' ('.Demo::baseCurrency()['code'].')';
    }

    /**
     * الفترة التي كان التاجر ينظر إليها لحظة الضغط على «تصدير».
     *
     * كان الملفّ يخرج بفترته الخاصّة مهما اختار: من يقرأ تقرير «اليوم» ويضغط
     * تصدير يخرج باثني عشر شهرًا ولا سطر فيه يقول ذلك.
     */
    private function range(): string
    {
        return Demo::range(request()->query('range'));
    }

    /**
     * تجهيز ورقة RTL بترويسة موحّدة، وإرجاع [الورقة، دالة العنوان، دالة رأس الجدول].
     *
     * $perBranch: هل يرشّح هذا التقرير بالفرع فعلًا؟ — انظر Demo::scopeName.
     * الورقة التي تُجمع على المتجر كلّه لا تنسب نفسها إلى فرعٍ مختارٍ في
     * الشريط، وإلا خرج ملفّان لفرعين يحملان الأرقام نفسها بترويستين.
     */
    private function sheet(Spreadsheet $spreadsheet, string $reportTitle, ?string $range = null, bool $perBranch = false): array
    {
        $business = Demo::business(auth()->user()->business_id ?? Demo::bid());

        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setRightToLeft(true);
        $sheet->setTitle($reportTitle);

        $sheet->setCellValue('A1', $business['name'] ?? 'Abad POS');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->setCellValue('A2', $reportTitle.' — '.now()->format('Y-m-d H:i'));
        $sheet->setCellValue('A3', __('الفرع').': '.Demo::scopeName($perBranch));

        // الفترة تُطبع دائمًا حتى في الأوراق التي لا فترة لها (جرد، منتجات):
        // سطرٌ ناقص أسهل أن يُقرأ على أنه «كل شيء» من سطرٍ مكتوب
        if ($range !== null) {
            $sheet->setCellValue('A4', __('الفترة').': '.Demo::rangeLabel($range));
            $sheet->getStyle('A4')->getFont()->setBold(true);
        }

        $this->row = $range !== null ? 6 : 5;
        $title = function (string $text) use ($sheet) {
            $r = $this->row;
            $sheet->setCellValue("A{$r}", $text);
            $sheet->mergeCells("A{$r}:C{$r}");
            $sheet->getStyle("A{$r}")->getFont()->setBold(true)->setSize(12)->getColor()->setRGB('FFFFFF');
            $sheet->getStyle("A{$r}:C{$r}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('111111');
            $sheet->getStyle("A{$r}:C{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            $this->row++;
        };
        $head = function (array $cols) use ($sheet) {
            $r = $this->row;
            $sheet->fromArray($cols, null, "A{$r}");
            $last = chr(ord('A') + count($cols) - 1);
            $sheet->getStyle("A{$r}:{$last}{$r}")->getFont()->setBold(true);
            $sheet->getStyle("A{$r}:{$last}{$r}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F0F0EE');
            $this->row++;
        };

        return [$sheet, $title, $head];
    }

    /** إنهاء الورقة: تنسيق المبالغ بمنازل عملة النشاط + عرض تلقائي لكلّ عمود + تنزيل */
    private function download(Spreadsheet $spreadsheet, $sheet, array $moneyCells, string $filename)
    {
        // يمنع ظهور 132.02000000000001 — وبمنازل العملة لا بثلاثٍ لكلّ عملة
        foreach ($moneyCells as $cell) {
            $sheet->getStyle($cell)->getNumberFormat()->setFormatCode(Workbook::moneyFormat());
        }
        $highest = Coordinate::columnIndexFromString($sheet->getHighestColumn());
        for ($i = 1; $i <= $highest; $i++) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($i))->setAutoSize(true);
        }

        return Workbook::stream($spreadsheet, $filename);
    }

    /** تصدير المنتجات كملف Excel حقيقي (xlsx) */
    public function xlsx()
    {
        $range = $this->range();
        // الورقة تُبنى من حمولة الشاشة نفسها — انظر Support\Reports::salesReport
        $report = Reports::salesReport($range, request()->query('channel'), request()->query('boutique'));
        $spreadsheet = new Spreadsheet;
        // `perBranch: true` — صارت أرقامُ هذه الورقة تُرشَّح بالفرع المختار
        // فعلًا، فترويستُها تقول اسمَه. وبقيّةُ الأوراق على حالها.
        [$sheet, $title, $head] = $this->sheet($spreadsheet, __('تقرير المبيعات'), $range, perBranch: true);

        /*
         * والقناةُ في ترويسة هذه الورقة وحدَها — لا في `sheet()` المشترك.
         *
         * أوراقُ الجرد والمنتجات والموردين لا تُرشَّح بقناةٍ أصلًا، وسطرٌ
         * يقول «القناة: كل القنوات» في ورقةِ جردٍ يوهم أنّها تعرفها.
         *
         * ويُكتب في السطر الذي قبل أوّل جدول ثمّ يُدفَع الجدولُ سطرًا: فيبقى
         * الفراغُ الفاصلُ كما كان ولا تلتصق الترويسةُ بأوّل عنوان. ويُحسب
         * الموضعُ من `row` ولا يُكتب رقمًا، فلا يزحف إن زاد سطرٌ في الترويسة.
         *
         * و«كل القنوات» لا «غير محدّدة» — انظر `PdfController::salesReport`.
         */
        $sheet->setCellValue('A'.($this->row - 1), __('القناة').': '.($report['channel'] === null
            ? __('كل القنوات')
            : SalesChannel::label($report['channel'])));
        $this->row++;

        // ونطاقُ البوتيك تحتها بالطريقة نفسها — لمن عنده بوتيكات وحده
        if ($report['boutiques'] !== []) {
            $sheet->setCellValue('A'.($this->row - 1), __('نطاق التقرير').': '.$report['boutiqueLabel']);
            $this->row++;
        }

        $money = [];

        /*
         * المؤشرات: بطاقات الشاشة نفسها بفترتها.
         *
         * كانت `Demo::adminStats()` — أرقامُ اليوم والشهر مهما كانت الفترة
         * المطلوبة، ومحصورةٌ بالفرع الحالي بينما ما تحتها في الورقة ليس
         * كذلك. فتخرج ورقةٌ ترويستها «اليوم» وأوّل جدولٍ فيها الشهرُ كلّه.
         */
        $title(__('المؤشرات الرئيسية'));
        $head([__('المؤشر'), __('القيمة')]);
        foreach (Reports::rowsFor($report) as $s) {
            $r = $this->row;
            $sheet->setCellValue("A{$r}", $s['label']);
            $sheet->setCellValue("B{$r}", $s['value']);
            if ($s['money']) {
                $money[] = "B{$r}";
            }
            $this->row++;
        }
        $this->row++;

        // المبيعات على محور الفترة — ساعاتٍ أو أيّامًا أو أشهرًا، وبعدد الطلبات
        $series = $report['salesSeries'];
        $title(__('المبيعات').' — '.Demo::rangeLabel($range));
        $head([__('الفترة'), $this->moneyHead('المبيعات'), __('عدد الطلبات')]);
        foreach ($series['full'] as $i => $label) {
            // ما لم يأتِ بعدُ لا يُكتب: صفٌّ بصفرٍ عن يوم غدٍ رقمٌ لا واقعة
            if (($series['data'][$i] ?? null) === null) {
                continue;
            }
            $r = $this->row;
            $sheet->setCellValue("A{$r}", $label);
            $sheet->setCellValue("B{$r}", round((float) ($series['data'][$i] ?? 0), 3));
            $sheet->setCellValue("C{$r}", (int) ($series['counts'][$i] ?? 0));
            $money[] = "B{$r}";
            $this->row++;
        }
        $this->row++;

        // وسائل الدفع — من الطلبات كما في مخطّط الشاشة، لا من دفتر المقبوضات
        // وبنطاقها نفسِه: الفرعُ والقناةُ من الحمولة المطبَّعة
        $title(__('توزيع وسائل الدفع'));
        if ($report['boutique'] !== null) {
            // الدفعُ على الطلب كاملًا لا على بنده — فلا يُنسب إلى نطاقٍ بالظنّ
            $sheet->setCellValue("A{$this->row}", __('لا يُنسب إلى بوتيك: الدفع يُسجَّل على الطلب كاملًا.'));
            $this->row++;
        }
        $head([__('الوسيلة'), $this->moneyHead('الإجمالي'), __('عدد العمليات')]);
        foreach ($report['boutique'] !== null ? [] : Demo::paymentBreakdown($range, $report['channel'], $report['branchId']) as $m) {
            $r = $this->row;
            $sheet->setCellValue("A{$r}", $m['name']);
            $sheet->setCellValue("B{$r}", $m['total']);
            $sheet->setCellValue("C{$r}", $m['count']);
            $money[] = "B{$r}";
            $this->row++;
        }
        $this->row++;

        // أفضل المنتجات — بترتيب الإيراد كما في جدول الشاشة، لا بترتيب الكمية
        $title(__('الأكثر مبيعًا'));
        $head([__('المنتج'), __('القسم'), __('المُباع'), $this->moneyHead('الإيراد')]);
        foreach ($report['topSellingProducts'] as $p) {
            $r = $this->row;
            $sheet->setCellValue("A{$r}", $p['name']);
            $sheet->setCellValue("B{$r}", $p['cat']);
            $sheet->setCellValue("C{$r}", (int) $p['sold']);
            $sheet->setCellValue("D{$r}", $p['revenue']);
            $money[] = "D{$r}";
            $this->row++;
        }

        Activity::log('report', 'صدّر تقرير المبيعات (Excel)');

        return $this->download($spreadsheet, $sheet, $money, 'sales-report-'.$range.'-'.now()->format('Y-m-d').'.xlsx');
    }

    /** المنتجات — ما في الشاشة بمرشِّحاتها وترتيبها (`ProductsList`) */
    public function productsXlsx()
    {
        Activity::log('report', 'صدّر المنتجات (Excel)');

        return Exports::xlsx(ProductsList::export(request()));
    }

    /**
     * العملاء — تقريرُ الشاشة بمرشِّحاتها وترتيبها (`CustomersList`).
     *
     * وملفُّ الاستيراد بابُه `customers.export.xlsx`: أعمدةُ الاستيراد بلا ترويسة.
     */
    public function customersXlsx()
    {
        Activity::log('report', 'صدّر تقرير العملاء (Excel)');

        return Exports::xlsx(CustomersList::export(request()));
    }

    /** جرد المخزون — بحثُ الشاشة وحالتُها وترتيبُها، للفرع المختار (`InventoryList`) */
    public function inventoryXlsx()
    {
        Activity::log('report', 'صدّر جرد المخزون (Excel)');

        return Exports::xlsx(InventoryList::export(request()));
    }

    /** المورّدون — تقريرُ الشاشة ببحثها وترتيبها (`SuppliersList`) */
    public function suppliersXlsx()
    {
        Activity::log('report', 'صدّر تقرير الموردين (Excel)');

        return Exports::xlsx(SuppliersList::export(request()));
    }

    /**
     * الحركةُ الماليّة — بحثُ الشاشة ونوعُها ومن/إلى وترتيبُها، كلُّها لا صفحتُها.
     *
     * كانت تقرأ `range` وحده — وليس في الشاشة — فتخرج «هذا الشهر» مهما رشّح
     * التاجر. والمجاميعُ (الدخلُ والمصروفُ والتحويلاتُ) على ما رُشّح كلِّه.
     */
    public function financeXlsx()
    {
        Activity::log('report', 'صدّر المعاملات المالية (Excel)');

        return Exports::xlsx(TransactionsList::export(request()));
    }

    /**
     * قائمةُ الطلبات — ما في الشاشة بمرشِّحاتها وترتيبها، كلُّه لا صفحتُه.
     *
     * الصفوفُ والمجاميعُ من `OrdersList` الذي ترقّمه الشاشة نفسُه.
     */
    public function ordersXlsx()
    {
        Activity::log('report', 'صدّر قائمة الطلبات (Excel)');

        return Exports::xlsx(OrdersList::export(request()));
    }

    /**
     * المصروفاتُ المسجّلة — شهرُ الشاشة ومرشِّحاتُها وترتيبُها، كلُّها لا صفحتُها.
     *
     * الشهرُ شهرُ الصفحة (`ListFilters::expenseSpan`): زرُّ التصدير يحمل ما في
     * الرابط، فمن يعرض ٢٠٢٦-١٠ يُنزّل أكتوبر وحده، و«كل الشهور» العمرَ كلَّه.
     * وفي آخرها المدفوعُ والمستحقُّ والعدد — انظر `ExpensesList::export`.
     */
    public function expensesXlsx()
    {
        Activity::log('report', 'صدّر المصروفات المسجلة (Excel) — '.(ExpensesList::month(request()) ?? 'كل الشهور'));

        return Exports::xlsx(ExpensesList::export(request()));
    }

    /** شركاتُ المنصّة — بحثُ الشاشة ونوعُها وباقتُها وحالتُها وترتيبُها (`BusinessesList`) */
    public function businessesXlsx()
    {
        Activity::log('report', 'صدّر الشركات (Excel)');

        return Exports::xlsx(BusinessesList::export(request()));
    }

    /** فواتيرُ الاشتراكات — بحثُ الشاشة وحالتُها وترتيبُها (`PlatformInvoicesList`) */
    public function invoicesXlsx()
    {
        Activity::log('report', 'صدّر فواتير الاشتراكات (Excel)');

        return Exports::xlsx(PlatformInvoicesList::export(request()));
    }
}
