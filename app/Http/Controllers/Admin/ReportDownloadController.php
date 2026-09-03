<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Activity;
use App\Support\Demo;
use App\Support\Pdf;
use App\Support\ReportColumns;
use App\Support\ReportData;
use App\Support\Reports;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * تنزيل أيّ تقريرٍ بالصيغ الثلاث المعتمدة — إكسل وPDF وCSV.
 *
 * وبابٌ واحد لا ثلاثةٌ لكلّ تقرير: ستّةَ عشرَ تقريرًا في ثلاث صيغ تعني
 * ثمانيةً وأربعين مسارًا ومتحكّمًا، تتفرّق أعمدتُها عن أعمدة الشاشة واحدًا
 * بعد واحد ولا يُكتشف الفرق إلا حين يُقارَن ملفٌّ بشاشته.
 *
 * والملفّ يقرأ ما تقرؤه الشاشة بعينه — `ReportData` نفسها بالمرشّحات نفسها
 * من سلسلة الاستعلام. فما في اليد هو ما كان على الشاشة لحظة الضغط، لا
 * فترةً أخرى ولا فرعًا آخر.
 */
class ReportDownloadController extends Controller
{
    /**
     * التقرير وبياناته — بحارس قسمه لا بصلاحية «التقارير».
     *
     * المسارات تحت `admin.reports.*` فيقيسها حارس المسار بصلاحية الفهرس،
     * وهذه قراءاتٌ على أقسامٍ أخرى: رواتبُ الموظفين، وإنفاقُ العملاء،
     * وحركةُ المال. والتنزيل يُخرجها من النظام إلى ملفٍّ يُرسَل — فحراسته
     * أولى لا أهون.
     */
    private function load(Request $request, string $report): array
    {
        abort_unless(ReportColumns::has($report) || ReportColumns::sectioned($report), 404);

        $section = Reports::sectionForRoute('admin.reports.'.$report);
        abort_if($section === null, 404);
        abort_unless(
            auth()->user()?->allows($section),
            403,
            __('ليس لديك صلاحية للوصول إلى قسم «:section».', ['section' => $section]),
        );

        $filters = $request->query();

        /*
         * والفترةُ لا تُفرض على تقريرٍ لا يعرفها: الهالك يُرشَّح بمدّةٍ بحدّين
         * (`from` و`to`) لأنّ شاشته تقارن المدّة بسابقتها. وإقحامُ `range`
         * عليه يجعل الملفّ يقرأ مدّةً غير التي كانت معروضة.
         */
        if (! ReportColumns::sectioned($report)) {
            $filters['range'] = Demo::range($request->query('range'));
        }

        return [$filters, ReportData::$report(Demo::bid(), $filters)];
    }

    /** عنوان التقرير كما في الفهرس — لا اسمٌ ثانٍ للشيء الواحد */
    private function title(string $report): string
    {
        foreach (Reports::ALL as $entry) {
            if ($entry['key'] === $report) {
                return __($entry['title']);
            }
        }

        return __('تقرير');
    }

    /**
     * المؤشّرات بطاقاتٍ للورق — بترتيب الشاشة نفسه.
     *
     * والمبالغ تُنسَّق هنا لا في القالب: القالب لا يعرف أيُّ مفتاحٍ مبلغٌ
     * وأيُّه عدد، فيطبع الرصيد بثلاث خاناتٍ عشرية والعدد كذلك.
     */
    private function cards(array $summary): array
    {
        $out = [];
        foreach ($summary as $key => $value) {
            if (is_array($value)) {
                continue;
            }
            $out[] = [
                'label' => __(self::LABELS[$key] ?? $key),
                'value' => is_float($value) ? Demo::money($value) : (string) ($value ?? '—'),
            ];
        }

        return array_slice($out, 0, 4);
    }

    /** أسماء مفاتيح المؤشّرات بالعربية — تُقرأ على الورق لا كمفاتيح */
    private const LABELS = [
        'income' => 'المقبوضات', 'outgo' => 'المدفوعات', 'net' => 'الصافي', 'count' => 'العدد',
        'total' => 'الإجمالي', 'average' => 'المتوسّط', 'topType' => 'أعلى نوع', 'topTotal' => 'قيمته',
        'lines' => 'الأسطر', 'matched' => 'المطابَق', 'unmatched' => 'غير المطابَق',
        'cancelled' => 'الملغاة', 'products' => 'المنتجات', 'revenue' => 'الإيراد', 'profit' => 'الربح',
        'sold' => 'ما بيع منها', 'items' => 'الأصناف', 'quantity' => 'الكمية', 'value' => 'القيمة',
        'below' => 'تحت الحدّ', 'received' => 'المستلَمة', 'pending' => 'المعلّقة',
        'suppliers' => 'المورّدون', 'active' => 'النشط', 'orders' => 'الطلبات',
        'users' => 'المستخدمون', 'topAction' => 'أكثر إجراء', 'topCount' => 'مرّاته',
        'coupons' => 'الكوبونات', 'used' => 'المستخدَم', 'uses' => 'مرات الاستخدام', 'discount' => 'الخصومات',
        'operations' => 'عمليات الجرد', 'shortage' => 'قيمة النقص', 'surplus' => 'قيمة الزيادة',
        'staff' => 'الموظفون', 'sellers' => 'من باع', 'topName' => 'الأعلى', 'topSales' => 'مبيعاته',
        'customers' => 'العملاء',
    ];

    /** ما يُكتب في ترويسة الملفّ عن مدّته — مسمّاةً كانت أو بحدّين */
    private function periodLabel(string $report, array $filters, array $data): string
    {
        return $data['periodLabel'] ?? Demo::rangeLabel($filters['range'] ?? 'month');
    }

    /**
     * هل يحتاج هذا التقرير ورقةً عرضيّة؟
     *
     * سبعةُ أعمدةٍ على عرض A4 القائم تعني ٢٦ مم للعمود الواحد — أي أنّ
     * «باقة ورد أحمر فاخرة» تنكسر أربعة أسطر، ويصير ارتفاعُ الصفّ أربعةَ
     * أضعافه، وتخرج ورقتان مكان واحدة. والعدد يُقرأ من رأس الجدول نفسه،
     * فتقريرٌ يُضاف إليه عمودٌ يتبدّل اتجاهُ ورقته من نفسه.
     */
    private function wide(string $report): bool
    {
        if (ReportColumns::sectioned($report)) {
            return collect(ReportColumns::sectionsOf($report))
                ->contains(fn ($section) => count(ReportColumns::sectionColumns($report, $section)) >= 7);
        }

        return count(ReportColumns::headings($report)) >= 7;
    }

    private function filename(string $report, array $filters): string
    {
        return 'report-'.$report.'-'.($filters['range'] ?? ($filters['from'] ?? 'all')).'-'.now()->format('Y-m-d');
    }

    /**
     * أقسامُ الورق: عنوانٌ ورأسٌ وصفوف لكلٍّ.
     *
     * @return list<array{title: string, headings: list<string>, rows: list<array>}>
     */
    private function pdfSections(string $report, array $data): array
    {
        $out = [];
        foreach ($data['sections'] as $section => $rows) {
            $out[] = [
                'title' => __($section),
                'headings' => array_column(ReportColumns::sectionColumns($report, $section), 'label'),
                'rows' => array_map(fn ($l) => ReportColumns::sectionCells($report, $section, $l), $rows),
            ];
        }

        return $out;
    }

    /**
     * ورقةٌ لكلّ قراءة في ملفّ إكسل واحد.
     *
     * وهي الصيغة التي تحتمل ذلك: من فتح الملفّ وجد ستّ ألسنةٍ يقرأ منها ما
     * يريد ويجمع عمودَه — بدل ستّة جداولَ ملصوقةٍ في ورقةٍ واحدة لا يُعرف
     * أين ينتهي أوّلُها.
     */
    private function sectionedXlsx(string $report, array $filters, array $data)
    {
        $spreadsheet = new Spreadsheet;
        $business = Demo::business(auth()->user()->business_id ?? Demo::bid());
        $first = true;

        foreach ($data['sections'] as $section => $rows) {
            $sheet = $first ? $spreadsheet->getActiveSheet() : $spreadsheet->createSheet();
            $first = false;
            $sheet->setRightToLeft(true);
            // اسمُ اللسان يُقصّ عند ٣١ محرفًا في إكسل، ويرفض بعض الرموز
            $sheet->setTitle(mb_substr(__($section), 0, 30));

            $sheet->setCellValue('A1', $business['name'] ?? 'Abad POS');
            $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
            $sheet->setCellValue('A2', $this->title($report).' — '.__($section));
            $sheet->setCellValue('A3', __('المدّة').': '.$this->periodLabel($report, $filters, $data));
            $sheet->getStyle('A3')->getFont()->setBold(true);

            $columns = ReportColumns::sectionColumns($report, $section);
            $row = 5;
            $last = chr(ord('A') + max(0, count($columns) - 1));
            $sheet->fromArray(array_column($columns, 'label'), null, 'A'.$row);
            $sheet->getStyle("A{$row}:{$last}{$row}")->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
            $sheet->getStyle("A{$row}:{$last}{$row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('111111');
            $sheet->getStyle("A{$row}:{$last}{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            $row++;

            $start = $row;
            foreach ($rows as $line) {
                $sheet->fromArray(ReportColumns::sectionCells($report, $section, $line), null, 'A'.$row);
                $row++;
            }

            foreach ($columns as $i => $column) {
                if ($column['kind'] === 'money' && $row > $start) {
                    $letter = chr(ord('A') + $i);
                    $sheet->getStyle("{$letter}{$start}:{$letter}".($row - 1))
                        ->getNumberFormat()->setFormatCode('#,##0.000');
                }
            }
            foreach (range('A', $sheet->getHighestColumn()) as $col) {
                $sheet->getColumnDimension($col)->setAutoSize(true);
            }
        }

        // اللسان الأوّل هو ما يُفتح عليه الملفّ لا آخرُ ما كُتب
        $spreadsheet->setActiveSheetIndex(0);

        Activity::log('report', 'صدّر '.$this->title($report).' (Excel)');

        $writer = new Xlsx($spreadsheet);

        return response()->streamDownload(function () use ($writer) {
            $writer->save('php://output');
        }, $this->filename($report, $filters).'.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /* ============================== إكسل ============================== */

    public function xlsx(Request $request, string $report)
    {
        [$filters, $data] = $this->load($request, $report);

        if (ReportColumns::sectioned($report)) {
            return $this->sectionedXlsx($report, $filters, $data);
        }

        $columns = ReportColumns::for($report);

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setRightToLeft(true);

        $business = Demo::business(auth()->user()->business_id ?? Demo::bid());
        $sheet->setCellValue('A1', $business['name'] ?? 'Abad POS');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->setCellValue('A2', $this->title($report).' — '.now()->format('Y-m-d H:i'));
        $sheet->setCellValue('A3', __('الفترة').': '.$this->periodLabel($report, $filters, $data));
        $sheet->getStyle('A3')->getFont()->setBold(true);

        // المؤشّرات فوق الجدول: من يفتح الورقة يقرأ الخلاصة قبل الصفوف
        $row = 5;
        foreach ($this->cards($data['summary'] ?? []) as $card) {
            $sheet->setCellValue('A'.$row, $card['label']);
            $sheet->setCellValue('B'.$row, $card['value']);
            $row++;
        }
        $row++;

        $last = chr(ord('A') + max(0, count($columns) - 1));
        $sheet->fromArray(ReportColumns::headings($report), null, 'A'.$row);
        $sheet->getStyle("A{$row}:{$last}{$row}")->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $sheet->getStyle("A{$row}:{$last}{$row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('111111');
        $sheet->getStyle("A{$row}:{$last}{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $row++;

        $first = $row;
        foreach ($data['rows'] ?? [] as $line) {
            $sheet->fromArray(ReportColumns::cells($report, $line), null, 'A'.$row);
            $row++;
        }

        /*
         * أعمدة المبالغ تُنسَّق أرقامًا لا نصوصًا.
         *
         * وهي مكتوبةٌ أرقامًا خامًا أصلًا (انظر ReportColumns::cells): من يفتح
         * الورقة أوّلُ ما يفعله أن يجمع عمودًا، ونصٌّ منسَّق لا يُجمع.
         */
        foreach ($columns as $i => $column) {
            if ($column['kind'] === 'money' && $row > $first) {
                $letter = chr(ord('A') + $i);
                $sheet->getStyle("{$letter}{$first}:{$letter}".($row - 1))
                    ->getNumberFormat()->setFormatCode('#,##0.000');
            }
        }
        foreach (range('A', $sheet->getHighestColumn()) as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        Activity::log('report', 'صدّر '.$this->title($report).' (Excel)');

        $writer = new Xlsx($spreadsheet);

        return response()->streamDownload(function () use ($writer) {
            $writer->save('php://output');
        }, $this->filename($report, $filters).'.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /* =============================== CSV =============================== */

    public function csv(Request $request, string $report)
    {
        [$filters, $data] = $this->load($request, $report);

        $lines = [];
        $lines[] = [$this->title($report), $this->periodLabel($report, $filters, $data)];
        $lines[] = [];
        foreach ($this->cards($data['summary'] ?? []) as $card) {
            $lines[] = [$card['label'], $card['value']];
        }
        if (ReportColumns::sectioned($report)) {
            /*
             * الملفّ الواحد لا يحمل أوراقًا، فتُكتب الأقسام متتابعةً بعنوانٍ
             * لكلٍّ وسطرٍ فارغ بينها — وإلّا التصق جدولٌ بجدولٍ وقُرئا واحدًا.
             */
            foreach ($data['sections'] as $section => $rows) {
                $lines[] = [];
                $lines[] = ['— '.__($section).' —'];
                $lines[] = array_column(ReportColumns::sectionColumns($report, $section), 'label');
                foreach ($rows as $line) {
                    $lines[] = ReportColumns::sectionCells($report, $section, $line);
                }
            }
        } else {
            $lines[] = [];
            $lines[] = ReportColumns::headings($report);
            foreach ($data['rows'] ?? [] as $line) {
                $lines[] = ReportColumns::cells($report, $line);
            }
        }

        Activity::log('report', 'صدّر '.$this->title($report).' (CSV)');

        return response()->streamDownload(function () use ($lines) {
            $out = fopen('php://output', 'w');
            // BOM: بلا هذه تفتح إكسل العربيةَ رموزًا
            fwrite($out, "\xEF\xBB\xBF");
            foreach ($lines as $line) {
                fputcsv($out, $line);
            }
            fclose($out);
        }, $this->filename($report, $filters).'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /* =============================== PDF =============================== */

    public function pdf(Request $request, string $report)
    {
        [$filters, $data] = $this->load($request, $report);

        $html = view('pdf.report', [
            'business' => Demo::business(auth()->user()->business_id ?? Demo::bid()),
            'branch' => Demo::scopeName(false),
            'title' => $this->title($report),
            'rangeLabel' => $this->periodLabel($report, $filters, $data),
            'generatedAt' => now()->format('Y-m-d H:i'),
            'cards' => $this->cards($data['summary'] ?? []),
            'headings' => ReportColumns::sectioned($report) ? [] : ReportColumns::headings($report),
            'rows' => ReportColumns::sectioned($report)
                ? []
                : array_map(fn ($l) => ReportColumns::cells($report, $l), $data['rows'] ?? []),
            'sections' => ReportColumns::sectioned($report) ? $this->pdfSections($report, $data) : [],
            'truncated' => $data['truncated'] ?? null,
        ])->render();

        Activity::log('report', 'صدّر '.$this->title($report).' (PDF)');

        /*
         * والتقريرُ العريض يخرج عرضيًّا.
         *
         * جدولٌ بسبعة أعمدةٍ أو أكثر على ورقةٍ قائمة يخرج بأعمدةٍ ملتصقة
         * تُقرأ بالتخمين، وأسماءُ الأصناف تنكسر ثلاثة أسطر في خانةٍ عرضُها
         * كلمة. والقرار يُقرأ من عدد الأعمدة نفسه لا من قائمةٍ بأسماء
         * تقاريرَ تُنسى عند أوّل تقريرٍ يُضاف.
         */
        return Pdf::a4($html, $this->filename($report, $filters), $this->wide($report));
    }
}
