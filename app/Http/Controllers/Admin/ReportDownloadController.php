<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Support\Activity;
use App\Support\Demo;
use App\Support\Exports\PdfRows;
use App\Support\Exports\Workbook;
use App\Support\Pdf;
use App\Support\ReportColumns;
use App\Support\ReportData;
use App\Support\ReportingPeriod;
use App\Support\Reports;
use Illuminate\Http\Request;

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

        /*
         * والفترةُ تُقرأ كما قرأتها الشاشة (`Reports::period`) — بالرابط
         * نفسه الذي حمله `withFilters`. فسبتمبرُ الشاشة سبتمبرُ الملفّ، لا
         * الشهرُ الجاري. وما يقارن مدّتَه بسابقتها يأخذ حدَّيه من/إلى.
         */
        if ($period = Reports::period($report, $request->query())) {
            $filters['_period'] = $period;

            if ((Reports::PERIODS[$report] ?? null) === 'dates') {
                $filters['from'] = $period->fromDate();
                $filters['to'] = $period->toDate();
            }
        }

        /*
         * والملفُّ بلا سقف الشاشة — بصيغه الثلاث.
         *
         * الشاشةُ تعرض خمسمئةً وتقول إنّها مبتورة، والملفُّ وعدُه «كلُّ ما
         * طابق». وورقةُ PDF كذلك: تحمل كلَّ ما طابق أو تُرفض بكلامٍ يُقرأ
         * (`PdfRows`) — لا ورقةٌ ناقصةٌ تُقرأ على أنّها الكلّ.
         */
        return [$filters, ReportData::uncapped(fn () => ReportData::$report(Demo::bid(), $filters))];
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
     * المؤشّرات بطاقاتٍ للورق — بترتيب الشاشة نفسه وبألفاظها.
     *
     * وكانت تُخمَّن هنا: أوّلُ أربعة مفاتيحَ غيرِ مصفوفة في الملخّص، بأسماءٍ
     * من قاموسٍ ثانٍ في هذا الملفّ. فافترق الملفّ عن شاشته في أربعة تقارير،
     * وخرج إقرارُ الضريبة ببطاقاتٍ مكتوبةٍ بالإنجليزية وبلا «الصافي
     * المستحقّ» أصلًا. والتصريحُ الآن إلى جانب أعمدة الجدول — انظر
     * `ReportColumns::CARDS`.
     */
    private function cards(string $report, array $summary): array
    {
        return ReportColumns::cards($report, $summary);
    }

    /** ما يُكتب في ترويسة الملفّ عن مدّته — مسمّاةً كانت أو بحدّين */
    private function periodLabel(string $report, array $filters, array $data): string
    {
        $period = $filters['_period'] ?? null;

        return $data['periodLabel'] ?? ($period?->label() ?? Demo::rangeLabel($filters['range'] ?? 'month'));
    }

    /**
     * سطورُ الرأس بعد الفترة: ما اختير من مرشّحات، ثمّ ملاحظةُ التقرير.
     *
     * والتقريرُ يقولها في بياناته (`activeFilters` و`note`) فتقرؤها الشاشةُ
     * والصيغُ الثلاث من موضعٍ واحد — لا نصٌّ في الشاشة ونصٌّ آخر في الملفّ.
     *
     * @return list<string>
     */
    private function headLines(array $data): array
    {
        $lines = [];
        foreach ($data['activeFilters'] ?? [] as $label => $value) {
            $lines[] = $label.': '.__((string) $value);
        }
        if (! empty($data['note'])) {
            $lines[] = $data['note'];
        }

        return $lines;
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

    /**
     * `report-costs-2026-03-01-to-2026-03-31` حين تُرشَّح المدّةُ بحدّين، وإلّا باسم فترتها.
     *
     * وكان تقريرُ التكاليف يُسمّى `-month-` وهو مرشَّحٌ بتاريخين: `range` يُقحم عليه.
     */
    private function filename(string $report, array $filters, string $ext): string
    {
        /*
         * والفترةُ من `ReportingPeriod::fileParts`: `2025-09`، `2024`،
         * `2025-03-to-2025-08`. وتاريخُ التنزيل يُلحق بالزرّ السريع وحده —
         * «month» تتغيّر كلَّ شهر، و«2025-09» لا تتغيّر.
         */
        $period = $filters['_period'] ?? null;

        if ($period instanceof ReportingPeriod) {
            return Workbook::filename('report-'.$report, [
                ...$period->fileParts(),
                ...($period->preset ? [now()->format('Y-m-d')] : []),
            ], $ext);
        }

        return Workbook::filename('report-'.$report, [$filters['range'] ?? 'all', now()->format('Y-m-d')], $ext);
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
        $book = null;

        foreach ($data['sections'] as $section => $rows) {
            // اللسانُ الأوّل هو ما يُفتح عليه الملفّ — بترتيب الشاشة
            $book = $book === null ? Workbook::blank(__($section)) : $book->newSheet(__($section));

            $book->line(Workbook::businessName(), bold: true, size: 14)
                ->line($this->title($report).' — '.__($section))
                ->line(__('المدّة').': '.$this->periodLabel($report, $filters, $data), bold: true)
                ->gap();

            $book->table($this->columns(ReportColumns::sectionColumns($report, $section)));
            foreach ($rows as $line) {
                $book->row(ReportColumns::sectionCells($report, $section, $line));
            }
            $book->endTable();
        }

        $book ??= Workbook::blank($this->title($report))->endTable();

        Activity::log('report', 'صدّر '.$this->title($report).' (Excel)');

        return $book->download($this->filename($report, $filters, 'xlsx'));
    }

    /**
     * أعمدةُ التقرير بأنواع الورقة: المبلغُ بمنازل عملة النشاط، والعددُ رقم،
     * وما سواهما رقمٌ إن بدا رقمًا ونصٌّ إن لم يبدُ — كما كُتب منذ كان.
     *
     * @param  list<array{key: string, label: string, kind: string}>  $columns
     * @return array<string, string>
     */
    private function columns(array $columns): array
    {
        $out = [];
        foreach ($columns as $column) {
            $out[$column['label']] = match ($column['kind']) {
                'money' => Workbook::MONEY,
                'number' => Workbook::NUMBER,
                default => Workbook::AUTO,
            };
        }

        return $out;
    }

    /* ============================== إكسل ============================== */

    public function xlsx(Request $request, string $report)
    {
        [$filters, $data] = $this->load($request, $report);

        if (ReportColumns::sectioned($report)) {
            return $this->sectionedXlsx($report, $filters, $data);
        }

        $book = Workbook::blank($this->title($report))
            ->line(Workbook::businessName(), bold: true, size: 14)
            ->line($this->title($report).' — '.now()->format('Y-m-d H:i'))
            ->line(__('الفترة').': '.$this->periodLabel($report, $filters, $data), bold: true);
        foreach ($this->headLines($data) as $line) {
            $book->line($line);
        }
        $book->gap();

        // المؤشّرات فوق الجدول: من يفتح الورقة يقرأ الخلاصة قبل الصفوف
        foreach ($this->cards($report, $data['summary'] ?? []) as $card) {
            $book->pair($card['label'], $card['value']);
        }
        $book->gap();

        /*
         * أعمدة المبالغ أرقامٌ لا نصوص.
         *
         * وهي مكتوبةٌ أرقامًا خامًا أصلًا (انظر ReportColumns::cells): من يفتح
         * الورقة أوّلُ ما يفعله أن يجمع عمودًا، ونصٌّ منسَّق لا يُجمع.
         */
        $book->table($this->columns(ReportColumns::for($report)));
        foreach ($data['rows'] ?? [] as $line) {
            $book->row(ReportColumns::cells($report, $line));
        }
        $book->endTable();

        Activity::log('report', 'صدّر '.$this->title($report).' (Excel)');

        return $book->download($this->filename($report, $filters, 'xlsx'));
    }

    /* =============================== CSV =============================== */

    public function csv(Request $request, string $report)
    {
        [$filters, $data] = $this->load($request, $report);

        $lines = [];
        $lines[] = [$this->title($report), $this->periodLabel($report, $filters, $data)];
        foreach ($this->headLines($data) as $line) {
            $lines[] = [$line];
        }
        $lines[] = [];
        foreach ($this->cards($report, $data['summary'] ?? []) as $card) {
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

        return Workbook::signal(response()->streamDownload(function () use ($lines) {
            $out = fopen('php://output', 'w');
            // BOM: بلا هذه تفتح إكسل العربيةَ رموزًا
            fwrite($out, "\xEF\xBB\xBF");
            foreach ($lines as $line) {
                fputcsv($out, $line, escape: '');
            }
            fclose($out);
        }, $this->filename($report, $filters, 'csv'), ['Content-Type' => 'text/csv; charset=UTF-8']));
    }

    /* =============================== PDF =============================== */

    public function pdf(Request $request, string $report)
    {
        [$filters, $data] = $this->load($request, $report);

        $rows = ReportColumns::sectioned($report)
            ? array_sum(array_map('count', $data['sections'] ?? []))
            : count($data['rows'] ?? []);
        if ($refused = PdfRows::refuse($rows)) {
            return $refused;
        }

        $html = view('pdf.report', [
            'business' => Demo::business(auth()->user()->business_id ?? Demo::bid()),
            // والفرعُ الذي رشّح به التقرير إن رشّح — لا «كل الفروع» وهو فرعٌ واحد
            'branch' => ($b = $filters['branch_id'] ?? null)
                ? (string) (Branch::where('business_id', Demo::bid())->whereKey((int) $b)->value('name') ?? Demo::scopeName(false))
                : Demo::scopeName(false),
            'title' => $this->title($report),
            'rangeLabel' => $this->periodLabel($report, $filters, $data),
            'generatedAt' => now()->format('Y-m-d H:i'),
            'cards' => $this->cards($report, $data['summary'] ?? []),
            'activeFilters' => $data['activeFilters'] ?? [],
            'note' => $data['note'] ?? null,
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
        return Pdf::a4($html, pathinfo($this->filename($report, $filters, 'pdf'), PATHINFO_FILENAME), $this->wide($report));
    }
}
