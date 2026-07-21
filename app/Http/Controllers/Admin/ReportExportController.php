<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Activity;
use App\Support\Demo;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class ReportExportController extends Controller
{
    /** الصف الحالي أثناء بناء الورقة */
    private int $row = 5;

    /** تجهيز ورقة RTL بترويسة موحّدة، وإرجاع [الورقة، دالة العنوان، دالة رأس الجدول] */
    private function sheet(Spreadsheet $spreadsheet, string $reportTitle): array
    {
        $business = Demo::business(auth()->user()->business_id ?? Demo::bid());

        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setRightToLeft(true);
        $sheet->setTitle($reportTitle);

        $sheet->setCellValue('A1', $business['name'] ?? 'Abad POS');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->setCellValue('A2', $reportTitle . ' — ' . now()->format('Y-m-d H:i'));
        $sheet->setCellValue('A3', __('الفرع') . ': ' . Demo::currentBranchName());

        $this->row = 5;
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

    /** تصدير تقرير المبيعات كملف Excel حقيقي (xlsx) */
    public function xlsx()
    {
        $spreadsheet = new Spreadsheet();
        [$sheet, $title, $head] = $this->sheet($spreadsheet, __('تقرير المبيعات'));
        $money = [];

        // المؤشرات
        $title(__('المؤشرات الرئيسية'));
        $head([__('المؤشر'), __('القيمة'), __('التغيّر')]);
        foreach (Demo::adminStats() as $s) {
            $sheet->fromArray([$s['label'], $s['value'], $s['trend'] ?? '—'], null, 'A' . $this->row);
            $this->row++;
        }
        $this->row++;

        // المبيعات الشهرية
        $series = Demo::salesSeries();
        $title(__('المبيعات الشهرية'));
        $head([__('الشهر'), __('المبيعات (ر.ع)')]);
        foreach ($series['labels'] as $i => $label) {
            $r = $this->row;
            $sheet->setCellValue("A{$r}", $label);
            $sheet->setCellValue("B{$r}", round((float) ($series['data'][$i] ?? 0), 3));
            $money[] = "B{$r}";
            $this->row++;
        }
        $this->row++;

        // وسائل الدفع
        $title(__('وسائل الدفع'));
        $head([__('الوسيلة'), __('الإجمالي (ر.ع)'), __('عدد العمليات')]);
        foreach (Demo::paymentMethods() as $m) {
            $r = $this->row;
            $sheet->setCellValue("A{$r}", $m['name']);
            $sheet->setCellValue("B{$r}", round((float) $m['total'], 3));
            $sheet->setCellValue("C{$r}", (int) $m['count']);
            $money[] = "B{$r}";
            $this->row++;
        }
        $this->row++;

        // أفضل المنتجات
        $title(__('أفضل المنتجات مبيعًا'));
        $head([__('المنتج'), __('الكمية المباعة'), __('الإيراد (ر.ع)')]);
        foreach (Demo::topProducts() as $p) {
            $r = $this->row;
            $sheet->setCellValue("A{$r}", $p['name']);
            $sheet->setCellValue("B{$r}", (int) $p['qty']);
            $sheet->setCellValue("C{$r}", round((float) $p['total'], 3));
            $money[] = "C{$r}";
            $this->row++;
        }

        Activity::log('report', 'صدّر تقرير المبيعات (Excel)');

        return $this->download($spreadsheet, $sheet, $money, 'sales-report-' . now()->format('Y-m-d') . '.xlsx');
    }

    /** تصدير التحليلات المتقدمة كملف Excel حقيقي (xlsx) */
    public function analyticsXlsx()
    {
        $spreadsheet = new Spreadsheet();
        [$sheet, $title, $head] = $this->sheet($spreadsheet, __('تحليلات متقدمة'));
        $money = [];

        // مقارنة الفترات
        $title(__('مقارنة الفترات (هذا الشهر مقابل السابق)'));
        $head([__('المؤشر'), __('الشهر الحالي'), __('الشهر السابق')]);
        foreach (Demo::periodComparison() as $m) {
            $sheet->fromArray([$m['label'], $m['cur'], $m['prev']], null, 'A' . $this->row);
            $this->row++;
        }
        $this->row++;

        // أفضل المنتجات
        $title(__('أفضل المنتجات مبيعًا'));
        $head([__('المنتج'), __('الكمية المباعة'), __('الإيراد (ر.ع)')]);
        foreach (Demo::topProducts() as $p) {
            $r = $this->row;
            $sheet->setCellValue("A{$r}", $p['name']);
            $sheet->setCellValue("B{$r}", (int) $p['qty']);
            $sheet->setCellValue("C{$r}", round((float) $p['total'], 3));
            $money[] = "C{$r}";
            $this->row++;
        }
        $this->row++;

        // أفضل العملاء
        $title(__('أفضل العملاء إنفاقًا'));
        $head([__('العميل'), __('عدد الطلبات'), __('إجمالي الإنفاق (ر.ع)')]);
        foreach (Demo::topCustomers() as $c) {
            $r = $this->row;
            $sheet->setCellValue("A{$r}", $c['name']);
            $sheet->setCellValue("B{$r}", (int) $c['orders']);
            $sheet->setCellValue("C{$r}", round((float) $c['total'], 3));
            $money[] = "C{$r}";
            $this->row++;
        }
        $this->row++;

        // المبيعات حسب التصنيف
        $cat = Demo::categorySales();
        $title(__('المبيعات حسب التصنيف'));
        $head([__('التصنيف'), __('المبيعات (ر.ع)')]);
        foreach ($cat['labels'] as $i => $label) {
            $r = $this->row;
            $sheet->setCellValue("A{$r}", $label);
            $sheet->setCellValue("B{$r}", round((float) ($cat['series'][$i] ?? 0), 3));
            $money[] = "B{$r}";
            $this->row++;
        }
        $this->row++;

        // المبيعات حسب أيام الأسبوع
        $wd = Demo::salesByWeekday();
        $title(__('المبيعات حسب أيام الأسبوع'));
        $head([__('اليوم'), __('المبيعات (ر.ع)')]);
        foreach ($wd['labels'] as $i => $label) {
            $r = $this->row;
            $sheet->setCellValue("A{$r}", $label);
            $sheet->setCellValue("B{$r}", round((float) ($wd['data'][$i] ?? 0), 3));
            $money[] = "B{$r}";
            $this->row++;
        }
        $this->row++;

        // أوقات الذروة
        $hr = Demo::salesByHour();
        $title(__('أوقات الذروة (حسب الساعة)'));
        $head([__('الساعة'), __('المبيعات (ر.ع)')]);
        foreach ($hr['labels'] as $i => $label) {
            $r = $this->row;
            $sheet->setCellValue("A{$r}", $label);
            $sheet->setCellValue("B{$r}", round((float) ($hr['data'][$i] ?? 0), 3));
            $money[] = "B{$r}";
            $this->row++;
        }

        Activity::log('report', 'صدّر التحليلات المتقدمة (Excel)');

        return $this->download($spreadsheet, $sheet, $money, 'analytics-report-' . now()->format('Y-m-d') . '.xlsx');
    }

    /** تصدير المنتجات كملف Excel حقيقي (xlsx) */
    public function productsXlsx()
    {
        $spreadsheet = new Spreadsheet();
        [$sheet, $title, $head] = $this->sheet($spreadsheet, __('المنتجات'));

        $firstDataRow = $this->tableHead($sheet, [__('المعرّف'), __('الاسم'), __('التصنيف'), 'SKU', __('الباركود'), __('السعر (ر.ع)'), __('التكلفة (ر.ع)'), __('الكمية'), __('حد التنبيه'), __('حالة المخزون'), __('الحالة')]);
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

        return $this->download($spreadsheet, $sheet, $money, 'products-' . now()->format('Y-m-d') . '.xlsx');
    }

    /** تصدير جرد المخزون كملف Excel حقيقي (xlsx) */
    public function inventoryXlsx()
    {
        $spreadsheet = new Spreadsheet();
        [$sheet, $title, $head] = $this->sheet($spreadsheet, __('جرد المخزون'));

        $firstDataRow = $this->tableHead($sheet, [__('المعرّف'), __('المنتج'), 'SKU', __('الكمية الحالية'), __('الحد الأدنى'), __('حالة المخزون'), __('آخر تحديث')]);

        foreach (Demo::inventory() as $i) {
            $r = $this->row;
            $sheet->setCellValue("A{$r}", (int) $i['id']);
            $sheet->setCellValue("B{$r}", $i['name']);
            $sheet->setCellValueExplicit("C{$r}", (string) $i['sku'], DataType::TYPE_STRING);
            $sheet->setCellValue("D{$r}", (int) $i['qty']);
            $sheet->setCellValue("E{$r}", (int) $i['min']);
            $sheet->setCellValue("F{$r}", $i['status']);
            $sheet->setCellValue("G{$r}", $i['updated']);

            // إبراز الأصناف المنخفضة أو المنتهية بلون تحذيري
            if ((int) $i['qty'] <= 0) {
                $sheet->getStyle("A{$r}:G{$r}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FDE8E8');
            } elseif ((int) $i['qty'] <= (int) $i['min']) {
                $sheet->getStyle("A{$r}:G{$r}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FEF3E2');
            }
            $this->row++;
        }

        $sheet->freezePane("A{$firstDataRow}");

        Activity::log('report', 'صدّر جرد المخزون (Excel)');

        return $this->download($spreadsheet, $sheet, [], 'inventory-' . now()->format('Y-m-d') . '.xlsx');
    }

    /** تصدير المعاملات المالية كملف Excel حقيقي (xlsx) */
    public function financeXlsx()
    {
        $spreadsheet = new Spreadsheet();
        [$sheet, $title, $head] = $this->sheet($spreadsheet, __('المعاملات المالية'));
        $money = [];

        // المؤشرات المالية
        $title(__('المؤشرات المالية'));
        $head([__('المؤشر'), __('القيمة'), __('التغيّر')]);
        foreach (Demo::financeStats() as $st) {
            $sheet->fromArray([$st['label'], $st['value'], $st['trend'] ?? '—'], null, 'A' . $this->row);
            $this->row++;
        }
        $this->row++;

        // المعاملات
        $firstDataRow = $this->tableHead($sheet, [__('المرجع'), __('التاريخ'), __('البيان'), __('الوسيلة'), __('النوع'), __('المبلغ (ر.ع)'), __('الموظف')]);
        foreach (Demo::transactions() as $t) {
            $r = $this->row;
            $sheet->setCellValueExplicit("A{$r}", (string) $t['id'], DataType::TYPE_STRING);
            $sheet->setCellValue("B{$r}", $t['date']);
            $sheet->setCellValue("C{$r}", $t['description']);
            $sheet->setCellValue("D{$r}", $t['method']);
            $sheet->setCellValue("E{$r}", $t['type']);
            $sheet->setCellValue("F{$r}", round((float) $t['amount'], 3));
            $sheet->setCellValue("G{$r}", $t['employee']);
            $money[] = "F{$r}";

            // تمييز المصروفات بالأحمر الفاتح لتُقرأ بنظرة
            if ($t['type'] !== 'دخل') {
                $sheet->getStyle("A{$r}:G{$r}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FDF0F0');
            }
            $this->row++;
        }
        $sheet->freezePane("A{$firstDataRow}");

        Activity::log('report', 'صدّر المعاملات المالية (Excel)');

        return $this->download($spreadsheet, $sheet, $money, 'finance-' . now()->format('Y-m-d') . '.xlsx');
    }
}
