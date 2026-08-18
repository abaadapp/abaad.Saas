<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Supplier;
use App\Support\Activity;
use App\Support\Demo;
use Mpdf\Mpdf;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * تصدير قائمة المورّدين — إكسل وPDF.
 *
 * كان قسم العملاء يُصدَّر بثلاث صيغ والمورّدون بلا صيغةٍ واحدة، وهما قائمتا
 * أسماءٍ وأرقامِ تواصل بالبنية نفسها: من يريد مراجعة مورّديه مع محاسبه كان
 * ينسخهم بيده من الشاشة.
 *
 * وعدد أوامر الشراء عمودٌ في الملف لأنه السؤال الأوّل عن أي مورّد: من
 * يُشترى منه فعلًا، ومن بقي اسمًا في القائمة بلا أمرٍ واحد.
 */
class SupplierExportController extends Controller
{
    private function bid(): int { return auth()->user()->business_id ?? Demo::bid(); }

    /** أعمدة الملف — واحدةٌ للصيغتين فلا تفترقان عند أوّل تعديل */
    private function columns(): array
    {
        return [__('الاسم'), __('الهاتف'), __('البريد'), __('مسؤول التواصل'), __('أوامر الشراء'), __('ملاحظات')];
    }

    private function suppliers()
    {
        return Supplier::where('business_id', $this->bid())
            ->withCount('purchaseOrders')->orderBy('name')->get();
    }

    public function xlsx()
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setRightToLeft(true);
        $sheet->setTitle(__('الموردين'));

        $sheet->fromArray($this->columns(), null, 'A1');

        $lastCol = 'F';
        $sheet->getStyle("A1:{$lastCol}1")->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $sheet->getStyle("A1:{$lastCol}1")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('111111');
        $sheet->getStyle("A1:{$lastCol}1")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $rows = $this->suppliers()->map(fn ($s) => [
            $s->name,
            $s->phone ?? '',
            $s->email ?? '',
            $s->contact_person ?? '',
            (int) $s->purchase_orders_count,
            $s->notes ?? '',
        ])->all();

        $sheet->fromArray($rows, null, 'A2');

        foreach (range('A', $lastCol) as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        Activity::log('report', 'صدّر قائمة الموردين (Excel)');

        $writer = new Xlsx($spreadsheet);
        $filename = 'suppliers-'.now()->format('Y-m-d').'.xlsx';

        return response()->streamDownload(function () use ($writer) {
            $writer->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    public function pdf()
    {
        $html = view('pdf.suppliers-list', [
            'business' => Demo::business($this->bid()),
            'suppliers' => $this->suppliers(),
            'generatedAt' => now()->format('Y-m-d H:i'),
        ])->render();

        Activity::log('report', 'صدّر قائمة الموردين (PDF)');

        $mpdf = new Mpdf([
            'mode' => 'utf-8', 'format' => 'A4',
            'margin_left' => 12, 'margin_right' => 12, 'margin_top' => 14, 'margin_bottom' => 14,
            'directionality' => 'rtl', 'autoScriptToLang' => true, 'autoLangToFont' => true,
        ]);
        $mpdf->WriteHTML($html);
        $name = 'suppliers-'.now()->format('Y-m-d');

        return response($mpdf->Output($name.'.pdf', 'S'), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$name.'.pdf"',
        ]);
    }
}
