<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Activity;
use App\Support\Demo;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class ReportExportController extends Controller
{
    /** تصدير تقرير المبيعات كملف Excel حقيقي (xlsx) */
    public function xlsx()
    {
        $bid = auth()->user()->business_id ?? Demo::bid();
        $business = Demo::business($bid);

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setRightToLeft(true);
        $sheet->setTitle('تقرير المبيعات');

        $row = 1;
        $title = function (string $text) use ($sheet, &$row) {
            $sheet->setCellValue("A{$row}", $text);
            $sheet->mergeCells("A{$row}:C{$row}");
            $sheet->getStyle("A{$row}")->getFont()->setBold(true)->setSize(12)->getColor()->setRGB('FFFFFF');
            $sheet->getStyle("A{$row}:C{$row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('111111');
            $sheet->getStyle("A{$row}:C{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            $row += 1;
        };
        $head = function (array $cols) use ($sheet, &$row) {
            $sheet->fromArray($cols, null, "A{$row}");
            $last = chr(ord('A') + count($cols) - 1);
            $sheet->getStyle("A{$row}:{$last}{$row}")->getFont()->setBold(true);
            $sheet->getStyle("A{$row}:{$last}{$row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F0F0EE');
            $row += 1;
        };

        // ترويسة التقرير
        $sheet->setCellValue('A1', $business['name'] ?? 'Abad POS');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->setCellValue('A2', 'تقرير المبيعات — ' . now()->format('Y-m-d H:i'));
        $sheet->setCellValue('A3', 'الفرع: ' . Demo::currentBranchName());
        $row = 5;

        // المؤشرات
        $title('المؤشرات الرئيسية');
        $head(['المؤشر', 'القيمة', 'التغيّر']);
        foreach (Demo::adminStats() as $s) {
            $sheet->fromArray([$s['label'], $s['value'], $s['trend'] ?? '—'], null, "A{$row}");
            $row++;
        }
        $row++;

        // المبيعات الشهرية
        $series = Demo::salesSeries();
        $title('المبيعات الشهرية');
        $head(['الشهر', 'المبيعات (ر.ع)']);
        $moneyCells = [];
        foreach ($series['labels'] as $i => $label) {
            $sheet->setCellValue("A{$row}", $label);
            $sheet->setCellValue("B{$row}", round((float) ($series['data'][$i] ?? 0), 3));
            $moneyCells[] = "B{$row}";
            $row++;
        }
        $row++;

        // وسائل الدفع
        $title('وسائل الدفع');
        $head(['الوسيلة', 'الإجمالي (ر.ع)', 'عدد العمليات']);
        foreach (Demo::paymentMethods() as $m) {
            $sheet->setCellValue("A{$row}", $m['name']);
            $sheet->setCellValue("B{$row}", round((float) $m['total'], 3));
            $sheet->setCellValue("C{$row}", (int) $m['count']);
            $moneyCells[] = "B{$row}";
            $row++;
        }
        $row++;

        // أفضل المنتجات
        $title('أفضل المنتجات مبيعًا');
        $head(['المنتج', 'الكمية المباعة', 'الإيراد (ر.ع)']);
        foreach (Demo::topProducts() as $p) {
            $sheet->setCellValue("A{$row}", $p['name']);
            $sheet->setCellValue("B{$row}", (int) $p['qty']);
            $sheet->setCellValue("C{$row}", round((float) $p['total'], 3));
            $moneyCells[] = "C{$row}";
            $row++;
        }

        // تنسيق الأرقام بثلاث خانات عشرية (يمنع ظهور 132.02000000000001)
        foreach ($moneyCells as $cell) {
            $sheet->getStyle($cell)->getNumberFormat()->setFormatCode('#,##0.000');
        }

        foreach (range('A', 'C') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        Activity::log('report', 'صدّر تقرير المبيعات (Excel)');

        $writer = new Xlsx($spreadsheet);
        $filename = 'sales-report-' . now()->format('Y-m-d') . '.xlsx';

        return response()->streamDownload(function () use ($writer) {
            $writer->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }
}
