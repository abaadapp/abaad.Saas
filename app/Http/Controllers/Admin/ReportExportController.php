<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Activity;
use App\Support\Demo;
use App\Support\Reports;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class ReportExportController extends Controller
{
    /** الصف الحالي أثناء بناء الورقة */
    private int $row = 5;

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

    /** تجهيز ورقة RTL بترويسة موحّدة، وإرجاع [الورقة، دالة العنوان، دالة رأس الجدول] */
    private function sheet(Spreadsheet $spreadsheet, string $reportTitle, ?string $range = null): array
    {
        $business = Demo::business(auth()->user()->business_id ?? Demo::bid());

        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setRightToLeft(true);
        $sheet->setTitle($reportTitle);

        $sheet->setCellValue('A1', $business['name'] ?? 'Abad POS');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->setCellValue('A2', $reportTitle.' — '.now()->format('Y-m-d H:i'));
        $sheet->setCellValue('A3', __('الفرع').': '.Demo::currentBranchName());

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

    /** رأس جدول مسطّح بخلفية سوداء — يُرجع رقم أول صف بيانات */
    private function tableHead($sheet, array $cols): int
    {
        $r = $this->row;
        $last = chr(ord('A') + count($cols) - 1);
        $sheet->fromArray($cols, null, "A{$r}");
        $sheet->getStyle("A{$r}:{$last}{$r}")->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $sheet->getStyle("A{$r}:{$last}{$r}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('111111');
        $sheet->getStyle("A{$r}:{$last}{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $this->row++;

        return $this->row;
    }

    /** إنهاء الورقة: تنسيق المبالغ + عرض تلقائي + تنزيل */
    private function download(Spreadsheet $spreadsheet, $sheet, array $moneyCells, string $filename)
    {
        // تنسيق الأرقام بثلاث خانات عشرية (يمنع ظهور 132.02000000000001)
        foreach ($moneyCells as $cell) {
            $sheet->getStyle($cell)->getNumberFormat()->setFormatCode('#,##0.000');
        }
        foreach (range('A', $sheet->getHighestColumn()) as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $writer = new Xlsx($spreadsheet);

        return response()->streamDownload(function () use ($writer) {
            $writer->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /** تصدير المنتجات كملف Excel حقيقي (xlsx) */
    public function xlsx()
    {
        $range = $this->range();
        // الورقة تُبنى من حمولة الشاشة نفسها — انظر Support\Reports::salesReport
        $report = Reports::salesReport($range);
        $spreadsheet = new Spreadsheet;
        [$sheet, $title, $head] = $this->sheet($spreadsheet, __('تقرير المبيعات'), $range);
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
        foreach (Reports::summaryRows($report['summary']) as $s) {
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
        $head([__('الفترة'), __('المبيعات (ر.ع)'), __('عدد الطلبات')]);
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
        $title(__('توزيع وسائل الدفع'));
        $head([__('الوسيلة'), __('الإجمالي (ر.ع)'), __('عدد العمليات')]);
        foreach (Demo::paymentBreakdown($range) as $m) {
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
        $head([__('المنتج'), __('القسم'), __('المُباع'), __('الإيراد (ر.ع)')]);
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

    public function productsXlsx()
    {
        $spreadsheet = new Spreadsheet;
        [$sheet, $title, $head] = $this->sheet($spreadsheet, __('المنتجات'));

        $firstDataRow = $this->tableHead($sheet, [__('المعرّف'), __('الاسم'), __('القسم'), 'SKU', __('الباركود'), __('السعر (ر.ع)'), __('التكلفة (ر.ع)'), __('الكمية'), __('حد التنبيه'), __('حالة المخزون'), __('الحالة')]);
        $money = [];
        foreach (Demo::products() as $p) {
            $r = $this->row;
            $sheet->setCellValue("A{$r}", (int) $p['id']);
            $sheet->setCellValue("B{$r}", $p['name']);
            $sheet->setCellValue("C{$r}", $p['cat']);
            $sheet->setCellValueExplicit("D{$r}", (string) $p['sku'], DataType::TYPE_STRING);
            $sheet->setCellValueExplicit("E{$r}", (string) $p['barcode'], DataType::TYPE_STRING);
            $sheet->setCellValue("F{$r}", round((float) $p['price'], 3));
            $sheet->setCellValue("G{$r}", round((float) $p['cost'], 3));
            $sheet->setCellValue("H{$r}", (int) $p['qty']);
            $sheet->setCellValue("I{$r}", (int) $p['alert']);
            $sheet->setCellValue("J{$r}", $p['stock_status']);
            $sheet->setCellValue("K{$r}", $p['active'] ? __('مفعّل') : __('معطّل'));
            $money[] = "F{$r}";
            $money[] = "G{$r}";
            $this->row++;
        }

        // تجميد الترويسة حتى تبقى أسماء الأعمدة ظاهرة عند التمرير
        $sheet->freezePane("A{$firstDataRow}");

        Activity::log('report', 'صدّر المنتجات (Excel)');

        return $this->download($spreadsheet, $sheet, $money, 'products-'.now()->format('Y-m-d').'.xlsx');
    }

    /** تصدير جرد المخزون كملف Excel حقيقي (xlsx) */
    public function inventoryXlsx()
    {
        $spreadsheet = new Spreadsheet;
        [$sheet, $title, $head] = $this->sheet($spreadsheet, __('جرد المخزون'));

        $firstDataRow = $this->tableHead($sheet, [__('المعرّف'), __('المنتج'), 'SKU', __('الكمية الحالية'), __('الحد الأدنى'), __('القيمة (ر.ع)'), __('حالة المخزون'), __('آخر تحديث')]);
        $money = [];

        foreach (Demo::inventory() as $i) {
            $r = $this->row;
            $sheet->setCellValue("A{$r}", (int) $i['id']);
            $sheet->setCellValue("B{$r}", $i['name']);
            $sheet->setCellValueExplicit("C{$r}", (string) $i['sku'], DataType::TYPE_STRING);
            $sheet->setCellValue("D{$r}", (int) $i['qty']);
            $sheet->setCellValue("E{$r}", (int) $i['min']);
            $sheet->setCellValue("F{$r}", round((float) $i['value'], 3));
            $sheet->setCellValue("G{$r}", $i['status']);
            $sheet->setCellValue("H{$r}", $i['updated']);
            $money[] = "F{$r}";

            // إبراز الأصناف المنخفضة أو المنتهية بلون تحذيري
            if ((int) $i['qty'] <= 0) {
                $sheet->getStyle("A{$r}:H{$r}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FDE8E8');
            } elseif ((int) $i['qty'] <= (int) $i['min']) {
                $sheet->getStyle("A{$r}:H{$r}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FEF3E2');
            }
            $this->row++;
        }

        $sheet->freezePane("A{$firstDataRow}");

        Activity::log('report', 'صدّر جرد المخزون (Excel)');

        return $this->download($spreadsheet, $sheet, $money, 'inventory-'.now()->format('Y-m-d').'.xlsx');
    }

    /** تصدير المعاملات المالية كملف Excel حقيقي (xlsx) */
    public function financeXlsx()
    {
        /*
         * نصفا الملفّ على فترةٍ واحدة.
         *
         * كانت المؤشّرات تُقرأ بلا فترةٍ فتسقط على الشهر، والجدولُ بلا فترةٍ
         * فيسقط على كلّ الفترات — فيقرأ التاجر «الدخل ١٠٠» فوق جدولٍ مجموعُه
         * ألف، ولا سطر في الورقة يقول إنهما لا يقيسان الشيء نفسه. ولم يكن
         * الملفّ يقبل فترةً أصلًا: يخرج بفترته الخاصّة مهما اختار.
         */
        $range = $this->range();

        $spreadsheet = new Spreadsheet;
        [$sheet, $title, $head] = $this->sheet($spreadsheet, __('المعاملات المالية'), $range);
        $money = [];

        // المؤشرات المالية
        $title(__('المؤشرات المالية'));
        $head([__('المؤشر'), __('القيمة'), __('التغيّر')]);
        foreach (Demo::financeStats($range) as $st) {
            $sheet->fromArray([$st['label'], $st['value'], $st['trend'] ?? '—'], null, 'A'.$this->row);
            $this->row++;
        }
        $this->row++;

        // المعاملات
        $firstDataRow = $this->tableHead($sheet, [__('المرجع'), __('التاريخ'), __('البيان'), __('الوسيلة'), __('النوع'), __('المبلغ (ر.ع)'), __('الموظف')]);
        foreach (Demo::transactions($range) as $t) {
            $r = $this->row;
            $sheet->setCellValueExplicit("A{$r}", (string) $t['id'], DataType::TYPE_STRING);
            $sheet->setCellValue("B{$r}", $t['date']);
            $sheet->setCellValue("C{$r}", $t['description']);
            $sheet->setCellValue("D{$r}", $t['method']);
            // النوع كما حدث لا كاتّجاهٍ وحده — انظر Books::MOVEMENTS
            $sheet->setCellValue("E{$r}", $t['kind_label']);
            $sheet->setCellValue("F{$r}", round((float) $t['amount'], 3));
            $sheet->setCellValue("G{$r}", $t['employee']);
            $money[] = "F{$r}";

            // تمييز ما خرج بالأحمر الفاتح لتُقرأ بنظرة — والتحويل لا يخرج
            if ($t['type'] === 'مصروف') {
                $sheet->getStyle("A{$r}:G{$r}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FDF0F0');
            }
            $this->row++;
        }
        $sheet->freezePane("A{$firstDataRow}");

        Activity::log('report', 'صدّر المعاملات المالية (Excel)');

        return $this->download($spreadsheet, $sheet, $money, 'finance-'.now()->format('Y-m-d').'.xlsx');
    }

    /** تصدير قائمة الطلبات كملف Excel حقيقي (xlsx) */
    public function ordersXlsx()
    {
        $spreadsheet = new Spreadsheet;
        [$sheet, $title, $head] = $this->sheet($spreadsheet, __('الطلبات'));
        $money = [];

        $orders = Demo::orders();

        // ملخّص سريع
        $title(__('ملخّص الطلبات'));
        $head([__('عدد الطلبات'), __('إجمالي القيمة (ر.ع)')]);
        $sheet->setCellValue('A'.$this->row, count($orders));
        $sheet->setCellValue('B'.$this->row, round(array_sum(array_map(fn ($o) => (float) $o['total'], $orders)), 3));
        $money[] = 'B'.$this->row;
        $this->row += 2;

        // جدول الطلبات
        $firstDataRow = $this->tableHead($sheet, [
            __('رقم الطلب'), __('العميل'), __('الموظف'), __('الفرع'),
            __('عدد الأصناف'), __('الإجمالي (ر.ع)'), __('الدفع'), __('الحالة'), __('التاريخ'),
        ]);
        foreach ($orders as $o) {
            $r = $this->row;
            $sheet->setCellValueExplicit("A{$r}", (string) $o['id'], DataType::TYPE_STRING);
            $sheet->setCellValue("B{$r}", $o['customer']);
            $sheet->setCellValue("C{$r}", $o['employee']);
            $sheet->setCellValue("D{$r}", $o['branch']);
            $sheet->setCellValue("E{$r}", (int) $o['items_count']);
            $sheet->setCellValue("F{$r}", round((float) $o['total'], 3));
            $sheet->setCellValue("G{$r}", $o['payment']);
            $sheet->setCellValue("H{$r}", $o['status']);
            $sheet->setCellValue("I{$r}", $o['date']);
            $money[] = "F{$r}";
            $this->row++;
        }
        $sheet->freezePane("A{$firstDataRow}");

        Activity::log('report', 'صدّر قائمة الطلبات (Excel)');

        return $this->download($spreadsheet, $sheet, $money, 'orders-'.now()->format('Y-m-d').'.xlsx');
    }

    /** تصدير المصروفات كملف Excel */
    public function expensesXlsx()
    {
        $spreadsheet = new Spreadsheet;
        [$sheet] = $this->sheet($spreadsheet, __('المصروفات'));
        $firstDataRow = $this->tableHead($sheet, [__('التاريخ'), __('النوع'), __('الوصف'), __('المبلغ (ر.ع)'), __('الطريقة'), __('الموظف')]);
        $money = [];
        foreach (Demo::expenses() as $e) {
            $r = $this->row;
            $sheet->setCellValue("A{$r}", $e['date']);
            $sheet->setCellValue("B{$r}", $e['type']);
            $sheet->setCellValue("C{$r}", $e['description']);
            $sheet->setCellValue("D{$r}", round((float) $e['amount'], 3));
            $sheet->setCellValue("E{$r}", $e['method']);
            $sheet->setCellValue("F{$r}", $e['employee']);
            $money[] = "D{$r}";
            $this->row++;
        }
        $sheet->freezePane("A{$firstDataRow}");
        Activity::log('report', 'صدّر المصروفات (Excel)');

        return $this->download($spreadsheet, $sheet, $money, 'expenses-'.now()->format('Y-m-d').'.xlsx');
    }

    /** تصدير الشركات كملف Excel (لوحة المنصة) */
    public function businessesXlsx()
    {
        $spreadsheet = new Spreadsheet;
        [$sheet] = $this->sheet($spreadsheet, __('الشركات'));
        $firstDataRow = $this->tableHead($sheet, [__('المعرّف'), __('الشركة'), __('النوع'), __('المالك'), __('الهاتف'), __('البريد'), __('المدينة'), __('الباقة'), __('الحالة'), __('الفروع'), __('التسجيل')]);
        foreach (Demo::businesses() as $b) {
            $r = $this->row;
            $sheet->setCellValue("A{$r}", (int) $b['id']);
            $sheet->setCellValue("B{$r}", $b['name']);
            $sheet->setCellValue("C{$r}", $b['type']);
            $sheet->setCellValue("D{$r}", $b['owner']);
            $sheet->setCellValueExplicit("E{$r}", (string) $b['phone'], DataType::TYPE_STRING);
            $sheet->setCellValue("F{$r}", $b['email']);
            $sheet->setCellValue("G{$r}", $b['city']);
            $sheet->setCellValue("H{$r}", $b['plan']);
            $sheet->setCellValue("I{$r}", $b['status']);
            $sheet->setCellValue("J{$r}", (int) $b['branches']);
            $sheet->setCellValue("K{$r}", $b['registered']);
            $this->row++;
        }
        $sheet->freezePane("A{$firstDataRow}");
        Activity::log('report', 'صدّر الشركات (Excel)');

        return $this->download($spreadsheet, $sheet, [], 'businesses-'.now()->format('Y-m-d').'.xlsx');
    }

    /** تصدير فواتير الاشتراكات كملف Excel (لوحة المنصة) */
    public function invoicesXlsx()
    {
        $spreadsheet = new Spreadsheet;
        [$sheet] = $this->sheet($spreadsheet, __('فواتير الاشتراكات'));
        $firstDataRow = $this->tableHead($sheet, [__('رقم الفاتورة'), __('الشركة'), __('الباقة'), __('المبلغ (ر.ع)'), __('التاريخ'), __('الحالة')]);
        $money = [];
        foreach (Demo::invoices() as $i) {
            $r = $this->row;
            $sheet->setCellValueExplicit("A{$r}", (string) $i['number'], DataType::TYPE_STRING);
            $sheet->setCellValue("B{$r}", $i['business']);
            $sheet->setCellValue("C{$r}", $i['plan']);
            $sheet->setCellValue("D{$r}", round((float) $i['amount'], 3));
            $sheet->setCellValue("E{$r}", $i['date']);
            $sheet->setCellValue("F{$r}", $i['status']);
            $money[] = "D{$r}";
            $this->row++;
        }
        $sheet->freezePane("A{$firstDataRow}");
        Activity::log('report', 'صدّر فواتير الاشتراكات (Excel)');

        return $this->download($spreadsheet, $sheet, $money, 'invoices-'.now()->format('Y-m-d').'.xlsx');
    }
}
