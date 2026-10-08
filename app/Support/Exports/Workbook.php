<?php

namespace App\Support\Exports;

use App\Support\Demo;
use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * ورقةُ Excel لقائمةٍ أو تقرير — ترويسةٌ واحدة، وخلايا بأنواعها، وتنزيلٌ واحد.
 *
 * كانت كلُّ ورقةٍ تبني نفسها: اسمُ المتجر والعنوانُ والفرعُ وتنسيقُ المبالغ
 * والعرضُ التلقائيّ والتنزيلُ مكتوبةٌ في كلّ دالّة، ومختلفةٌ بينها — عرضٌ
 * تلقائيٌّ يقف عند العمود Z، ومبالغُ بثلاث منازل لعملةٍ منزلتاها اثنتان،
 * وأرقامٌ تُكتب نصًّا لا يُجمع.
 *
 * ═══ ما تعد به كلُّ ورقة ═══
 *
 * - **الترويسة:** اسمُ النشاط، والعنوان، ووقتُ التصدير، ثمّ المرشِّحاتُ
 *   الفعّالة وحدها (`filters`) — لا «الحالة: الكل».
 * - **الاتّجاه:** من لغة القارئ — العربيّة من اليمين.
 * - **المبالغ:** خلايا رقميّة حقيقيّة بمنازل عملة النشاط، ورأسُ العمود يحمل
 *   رمزَها (`money`). والقيمةُ لا تُكتب `number_format` — تلك نصٌّ لا يُجمع.
 * - **التواريخ:** تاريخٌ يفهمه Excel بصيغة `yyyy-mm-dd` (و`hh:mm` للوقت)،
 *   بتوقيت التطبيق الذي تعرض به الشاشة.
 * - **لا نتائج:** صفٌّ يقول ذلك تحت الرأس، لا ملفٌّ فارغٌ يُظنّ معطوبًا.
 * - **السقف:** `MAX_ROWS` صفًّا، وما زاد يُقال في الورقة لا يُقصّ بصمت.
 */
final class Workbook
{
    /**
     * أكثرُ ما تحمله ورقةٌ واحدة.
     *
     * PhpSpreadsheet يبني الورقة كلَّها في الذاكرة: خمسون ألفَ صفٍّ بعشرة
     * أعمدة قرابةُ ٢٠٠ ميغا. وما فوقه يُكتب في الورقة سطرًا يقول كم بقي —
     * فلا يسقط الطلبُ بنفاد الذاكرة ولا يُقصّ الملفُّ بلا علم.
     */
    public const MAX_ROWS = 50000;

    /** أنواعُ الأعمدة */
    public const TEXT = 'text';

    public const CODE = 'code';

    public const INT = 'int';

    public const MONEY = 'money';

    public const NUMBER = 'number';

    public const DATE = 'date';

    public const DATETIME = 'datetime';

    private Spreadsheet $book;

    private Worksheet $sheet;

    private int $row = 1;

    /** @var list<string> */
    private array $types = [];

    private ?int $firstDataRow = null;

    private int $dataRows = 0;

    private string $moneyFormat;

    private bool $capped = false;

    /**
     * @param  array<string, string|null>  $filters  المرشِّحاتُ الفعّالة: عنوانٌ ← قيمة (الفارغةُ لا تُطبع)
     * @param  bool|null  $perBranch  null: التقرير لا يتبع الفرع فلا سطرَ له — انظر `Demo::scopeName`
     */
    public function __construct(string $title, array $filters = [], ?bool $perBranch = null)
    {
        $this->book = new Spreadsheet;
        $this->sheet = $this->book->getActiveSheet();
        $this->sheet->setRightToLeft(self::rtl());
        // اسمُ الورقة: ٣١ حرفًا بلا الرموز التي يرفضها Excel
        $this->sheet->setTitle(Str::limit(str_replace(['*', ':', '/', '\\', '?', '[', ']'], ' ', $title), 28, '') ?: 'Sheet');
        $this->moneyFormat = self::moneyFormat();

        $business = Demo::business(auth()->user()->business_id ?? Demo::bid());
        // السطورُ الثلاثة الأولى بمواضعها منذ كانت: الاسم، فالعنوان ووقتُ التصدير، فالفرع
        $this->line($business['name'] ?? 'Abad POS', bold: true, size: 14);
        $this->line($title.' — '.__('تاريخ التصدير').': '.now()->format('Y-m-d H:i'), bold: true);

        if ($perBranch !== null) {
            $this->line(__('الفرع').': '.Demo::scopeName($perBranch));
        }

        foreach ($filters as $label => $value) {
            if ($value !== null && trim((string) $value) !== '') {
                $this->line($label.': '.$value, bold: true);
            }
        }

        $this->row++;
    }

    /** لغةُ القارئ من اليمين؟ — العربيّة نعم، والإنجليزيّة لا */
    public static function rtl(): bool
    {
        return app()->getLocale() !== 'en';
    }

    /** رأسُ عمودٍ ماليّ: اسمُه ثمّ رمزُ عملة النشاط — «الإجمالي (OMR)» */
    public static function money(string $label): string
    {
        return __($label).' ('.Demo::baseCurrency()['code'].')';
    }

    /** صيغةُ المبالغ بمنازل عملة النشاط — `#,##0.000` للريال، `#,##0.00` للدرهم */
    public static function moneyFormat(): string
    {
        $decimals = Money::decimals(Demo::baseCurrency());

        return $decimals > 0 ? '#,##0.'.str_repeat('0', $decimals) : '#,##0';
    }

    /**
     * اسمُ ملفٍّ آمن: أحرفٌ لاتينيّة وأرقامٌ وشرطات — `expenses-2026-10.xlsx`.
     *
     * نصُّ المستخدم لا يصل اسمَ الملفّ كما كتبه: «/» و«..» ومسافاتٌ ومحارفُ
     * عربيّةٌ تكسره في بعض المتصفّحات وأنظمة الملفّات.
     *
     * @param  list<string|null>  $parts
     */
    public static function filename(string $base, array $parts = [], string $ext = 'xlsx'): string
    {
        $clean = fn (?string $s) => trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '-', Str::ascii((string) $s)), '-.');
        $name = implode('-', array_filter(array_map($clean, [$base, ...$parts]), fn ($p) => $p !== ''));

        return ($name !== '' ? $name : 'export').'.'.$ext;
    }

    /** شريطُ قسمٍ أسود فوق جدول (للتقارير ذات الأقسام) */
    public function section(string $title): self
    {
        $r = $this->row;
        $this->sheet->setCellValue("A{$r}", $title);
        $this->sheet->mergeCells("A{$r}:C{$r}");
        $this->sheet->getStyle("A{$r}")->getFont()->setBold(true)->setSize(12)->getColor()->setRGB('FFFFFF');
        $this->sheet->getStyle("A{$r}:C{$r}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('111111');
        $this->row++;

        return $this;
    }

    /**
     * رأسُ جدول — الأعمدةُ بترتيب الشاشة، ولكلٍّ نوعُه.
     *
     * @param  array<string, string>  $columns  عنوانٌ ← نوع (`TEXT`, `CODE`, `INT`, `MONEY`, `NUMBER`, `DATE`, `DATETIME`)
     */
    public function table(array $columns): self
    {
        $this->types = array_values($columns);
        $r = $this->row;
        $last = Coordinate::stringFromColumnIndex(count($columns));

        $this->sheet->fromArray(array_keys($columns), null, "A{$r}");
        $style = $this->sheet->getStyle("A{$r}:{$last}{$r}");
        $style->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $style->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('111111');
        $style->getAlignment()->setHorizontal(self::rtl() ? Alignment::HORIZONTAL_RIGHT : Alignment::HORIZONTAL_LEFT);

        $this->row++;
        $this->firstDataRow ??= $this->row;

        return $this;
    }

    /**
     * صفُّ بيانات — القيمُ بترتيب الأعمدة، وكلٌّ يُكتب بنوعه.
     *
     * @param  list<mixed>  $values
     * @param  string|null  $fill  لونُ خلفيّةٍ يميّز الصفّ (ملغاة، ناقصة…)
     */
    public function row(array $values, ?string $fill = null): self
    {
        if ($this->dataRows >= self::MAX_ROWS) {
            $this->capped = true;

            return $this;
        }

        $r = $this->row;
        foreach (array_values($values) as $i => $value) {
            $this->cell(Coordinate::stringFromColumnIndex($i + 1).$r, $value, $this->types[$i] ?? self::TEXT);
        }

        if ($fill !== null && $values !== []) {
            $last = Coordinate::stringFromColumnIndex(count($values));
            $this->sheet->getStyle("A{$r}:{$last}{$r}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($fill);
        }

        $this->row++;
        $this->dataRows++;

        return $this;
    }

    /** كم صفَّ بيانات كُتب — لا الرأسُ ولا المجاميع */
    public function count(): int
    {
        return $this->dataRows;
    }

    /**
     * يُغلق الجدول: «لا توجد نتائج» إن لم يُكتب صفّ، وسطرُ السقف إن بلغه.
     *
     * @param  int|null  $total  كم صفًّا طابق المرشِّحات — ليُقال «أوّل ٥٠٠٠٠ من …»
     */
    public function endTable(?int $total = null): self
    {
        if ($this->dataRows === 0) {
            $this->sheet->setCellValue("A{$this->row}", __('لا توجد نتائج'));
            $this->sheet->getStyle("A{$this->row}")->getFont()->setItalic(true);
            $this->row++;
        }

        if ($this->capped) {
            $this->sheet->setCellValue("A{$this->row}", __('الملف يحمل أول :shown صفًّا من :total — ضيّق المرشّحات لتصدير الباقي.', [
                'shown' => self::MAX_ROWS,
                'total' => $total ?? __('أكثر'),
            ]));
            $this->sheet->getStyle("A{$this->row}")->getFont()->setBold(true)->getColor()->setRGB('B91C1C');
            $this->row++;
        }

        $this->row++;

        return $this;
    }

    /**
     * مجاميعُ أسفل الجدول — عنوانٌ في عمود وقيمتُه في العمود الذي يليه.
     *
     * @param  list<array{0: string, 1: int|float, 2?: string}>  $rows  [العنوان، القيمة، النوع (MONEY افتراضًا)]
     * @param  int  $column  رقمُ عمود العنوان (يبدأ من ١)
     */
    public function totals(array $rows, int $column = 1): self
    {
        $labelCol = Coordinate::stringFromColumnIndex($column);
        $valueCol = Coordinate::stringFromColumnIndex($column + 1);

        foreach ($rows as $row) {
            [$label, $value] = $row;
            $r = $this->row;
            $this->sheet->setCellValue("{$labelCol}{$r}", $label);
            $this->cell("{$valueCol}{$r}", $value, $row[2] ?? self::MONEY);
            $this->sheet->getStyle("{$labelCol}{$r}:{$valueCol}{$r}")->getFont()->setBold(true);
            $this->row++;
        }

        $this->row++;

        return $this;
    }

    /** الورقةُ نفسُها — لما لا تقوله هذه الطبقة (ألوانٌ خاصّة، دمج) */
    public function sheet(): Worksheet
    {
        return $this->sheet;
    }

    /** يُنزَّل الملفّ — عرضٌ تلقائيٌّ لكلّ عمود، وتجميدُ الرأس */
    public function download(string $filename): StreamedResponse
    {
        if ($this->firstDataRow !== null) {
            $this->sheet->freezePane("A{$this->firstDataRow}");
        }

        $highest = Coordinate::columnIndexFromString($this->sheet->getHighestColumn());
        for ($i = 1; $i <= $highest; $i++) {
            $this->sheet->getColumnDimension(Coordinate::stringFromColumnIndex($i))->setAutoSize(true);
        }

        return self::stream($this->book, $filename);
    }

    /**
     * التنزيلُ نفسُه — ويُعلِم زرَّ التصدير أنّ الملفّ بدأ (`download_token`).
     *
     * الزرُّ ينتظر كعكةً بالرمز الذي أرسله ثمّ يعود إلى حاله — فلا يُضغط
     * مرّتين فيُنزَّل الملفّ مرّتين، ولا يبقى «جارٍ التصدير» بعد أن وصل.
     */
    public static function stream(Spreadsheet $book, string $filename): StreamedResponse
    {
        $writer = new Xlsx($book);

        $response = response()->streamDownload(function () use ($writer) {
            $writer->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);

        return self::signal($response);
    }

    /** كعكةُ «بدأ التنزيل» لرمز الزرّ — أحرفٌ وأرقامٌ لا غير */
    public static function signal($response)
    {
        $token = (string) request()->query('download_token', '');

        if ($token !== '' && preg_match('/^[A-Za-z0-9]{6,40}$/', $token)) {
            $response->headers->setCookie(cookie('download_token', $token, 1, '/', null, null, false, false, 'Lax'));
        }

        return $response;
    }

    private function line(string $text, bool $bold = false, ?int $size = null): void
    {
        $this->sheet->setCellValue("A{$this->row}", $text);
        $font = $this->sheet->getStyle("A{$this->row}")->getFont()->setBold($bold);
        if ($size !== null) {
            $font->setSize($size);
        }
        $this->row++;
    }

    private function cell(string $coordinate, mixed $value, string $type): void
    {
        if ($value === null || $value === '' || $value === '—') {
            $this->sheet->setCellValueExplicit($coordinate, $value === '—' ? '—' : '', DataType::TYPE_STRING);

            return;
        }

        switch ($type) {
            case self::MONEY:
                $this->sheet->setCellValue($coordinate, round((float) $value, 3));
                $this->sheet->getStyle($coordinate)->getNumberFormat()->setFormatCode($this->moneyFormat);
                break;
            case self::NUMBER:
                $this->sheet->setCellValue($coordinate, (float) $value);
                break;
            case self::INT:
                $this->sheet->setCellValue($coordinate, (int) $value);
                break;
            case self::DATE:
            case self::DATETIME:
                $at = $value instanceof CarbonInterface ? $value : rescue(fn () => Carbon::parse((string) $value), null, false);
                if ($at === null) {
                    $this->sheet->setCellValueExplicit($coordinate, (string) $value, DataType::TYPE_STRING);
                    break;
                }
                $this->sheet->setCellValue($coordinate, ExcelDate::PHPToExcel($type === self::DATE ? $at->copy()->startOfDay() : $at));
                $this->sheet->getStyle($coordinate)->getNumberFormat()->setFormatCode($type === self::DATE ? 'yyyy-mm-dd' : 'yyyy-mm-dd hh:mm');
                break;
            case self::CODE:
                // رقمُ فاتورةٍ أو SKU أو هاتف: نصٌّ لا رقم — وإلا سقطت أصفارُه الأولى
                $this->sheet->setCellValueExplicit($coordinate, (string) $value, DataType::TYPE_STRING);
                break;
            default:
                $this->sheet->setCellValueExplicit($coordinate, (string) $value, DataType::TYPE_STRING);
        }
    }
}
