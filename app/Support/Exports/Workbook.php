<?php

namespace App\Support\Exports;

use App\Support\Demo;
use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
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
 * - **لا سقف:** كلُّ ما طابق يُكتب. والورقةُ تُكتب إلى القرص صفًّا صفًّا
 *   (`XlsxWriter`) فلا تُمسَك في الذاكرة — انظر هناك لمَ.
 */
final class Workbook
{
    /** أنواعُ الأعمدة */
    public const TEXT = 'text';

    public const CODE = 'code';

    public const INT = 'int';

    public const MONEY = 'money';

    public const NUMBER = 'number';

    public const DATE = 'date';

    public const DATETIME = 'datetime';

    /** رقمٌ إن كانت القيمةُ رقمًا وإلّا نصّ — لخلايا تقارير المركز وبطاقاتها */
    public const AUTO = 'auto';

    private XlsxWriter $writer;

    private int $row = 1;

    /** @var list<string> */
    private array $types = [];

    private ?int $firstDataRow = null;

    private int $dataRows = 0;

    private string $moneyFormat;

    /**
     * @param  array<string, string|null>  $filters  المرشِّحاتُ الفعّالة: عنوانٌ ← قيمة (الفارغةُ لا تُطبع)
     * @param  bool|null  $perBranch  null: التقرير لا يتبع الفرع فلا سطرَ له — انظر `Demo::scopeName`
     */
    public function __construct(string $title, array $filters = [], ?bool $perBranch = null, bool $header = true)
    {
        $this->writer = new XlsxWriter;
        $this->moneyFormat = self::moneyFormat();
        $this->writer->sheet($title, self::rtl());

        if (! $header) {
            return;
        }

        // السطورُ الثلاثة الأولى بمواضعها منذ كانت: الاسم، فالعنوان ووقتُ التصدير، فالفرع
        $this->line(self::businessName(), bold: true, size: 14);
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

    /** ورقةٌ بلا ترويسةٍ موحّدة — لمن يكتب سطورَه بنفسه (مركز التقارير) */
    public static function blank(string $title): self
    {
        return new self($title, header: false);
    }

    /** اسمُ النشاط كما يُكتب في أوّل سطر */
    public static function businessName(): string
    {
        return Demo::business(auth()->user()->business_id ?? Demo::bid())['name'] ?? 'Abad POS';
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

    /**
     * لسانٌ جديد في الملفّ نفسه — والكتابةُ بعده فيه.
     *
     * لتقريرٍ ذي أقسام: لكلّ قراءةٍ لسانُها يُجمع عمودُه وحده.
     */
    public function newSheet(string $title): self
    {
        $this->writer->sheet($title, self::rtl());
        $this->row = 1;
        $this->types = [];
        $this->firstDataRow = null;
        $this->dataRows = 0;

        return $this;
    }

    /** سطرٌ في العمود الأوّل — عنوانٌ أو ملاحظة — ولا يُقاس به عرضُ العمود */
    public function line(string $text, bool $bold = false, ?int $size = null): self
    {
        $this->writer->row($this->row, [1 => [$text, $this->writer->style(array_filter(['bold' => $bold, 'size' => $size]))]], measure: false);
        $this->row++;

        return $this;
    }

    /** سطرٌ فارغ */
    public function gap(): self
    {
        $this->row++;

        return $this;
    }

    /** عنوانٌ وقيمتُه في عمودين — بطاقاتُ الملخّص فوق الجدول */
    public function pair(string $label, mixed $value): self
    {
        $this->writer->row($this->row, [1 => [$label], 2 => $this->typed($value, self::AUTO)]);
        $this->row++;

        return $this;
    }

    /** شريطُ قسمٍ أسود فوق جدول (للتقارير ذات الأقسام) */
    public function section(string $title): self
    {
        $r = $this->row;
        $bar = $this->writer->style(['fill' => '111111']);
        $this->writer->row($r, [
            1 => [$title, $this->writer->style(['bold' => true, 'size' => 12, 'color' => 'FFFFFF', 'fill' => '111111'])],
            2 => ['', $bar], 3 => ['', $bar],
        ], measure: false);
        $this->writer->merge("A{$r}:C{$r}");
        $this->row++;

        return $this;
    }

    /**
     * رأسُ جدول — الأعمدةُ بترتيب الشاشة، ولكلٍّ نوعُه.
     *
     * @param  array<string, string>  $columns  عنوانٌ ← نوع (`TEXT`, `CODE`, `INT`, `MONEY`, `NUMBER`, `DATE`, `DATETIME`, `AUTO`)
     */
    public function table(array $columns): self
    {
        $this->types = array_values($columns);
        $style = $this->writer->style([
            'bold' => true, 'color' => 'FFFFFF', 'fill' => '111111', 'align' => self::rtl() ? 'right' : 'left',
        ]);

        $cells = [];
        foreach (array_keys($columns) as $i => $label) {
            $cells[$i + 1] = [(string) $label, $style];
        }
        $this->writer->row($this->row, $cells);

        $this->row++;
        if ($this->firstDataRow === null) {
            $this->firstDataRow = $this->row;
            $this->writer->freeze($this->row);
        }

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
        $cells = [];
        foreach (array_values($values) as $i => $value) {
            $cells[$i + 1] = $this->typed($value, $this->types[$i] ?? self::TEXT, $fill);
        }
        $this->writer->row($this->row, $cells);

        $this->row++;
        $this->dataRows++;

        return $this;
    }

    /** كم صفَّ بيانات كُتب — لا الرأسُ ولا المجاميع */
    public function count(): int
    {
        return $this->dataRows;
    }

    /** يُغلق الجدول: «لا توجد نتائج» إن لم يُكتب صفّ */
    public function endTable(): self
    {
        if ($this->dataRows === 0) {
            $this->writer->row($this->row, [1 => [__('لا توجد نتائج'), $this->writer->style(['italic' => true])]], measure: false);
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
        $bold = $this->writer->style(['bold' => true]);

        foreach ($rows as $row) {
            [$label, $value] = $row;
            $cell = $this->typed($value, $row[2] ?? self::MONEY, bold: true);
            $this->writer->row($this->row, [$column => [$label, $bold], $column + 1 => $cell]);
            $this->row++;
        }

        $this->row++;

        return $this;
    }

    /** يُنزَّل الملفّ — كُتب صفًّا صفًّا ويُرسَل من القرص ثمّ يُحذف */
    public function download(string $filename): StreamedResponse
    {
        $path = $this->writer->close();

        $response = response()->streamDownload(function () use ($path) {
            $in = fopen($path, 'r');
            $out = fopen('php://output', 'w');
            stream_copy_to_stream($in, $out);
            fclose($in);
            fclose($out);
            @unlink($path);
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Length' => (string) filesize($path),
        ]);

        return self::signal($response);
    }

    /**
     * ورقةُ PhpSpreadsheet تُنزَّل — لتقرير المبيعات وملفّ استيراد العملاء.
     *
     * وكلاهما صغيرٌ بطبعه: ملخّصٌ بمؤشّراتٍ ومحاور، وقالبٌ يُملأ ويُعاد.
     * والقوائمُ لا تمرّ من هنا — تمرّ من `download` بلا ذاكرةٍ تُمسك.
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

    /**
     * كعكةُ «بدأ التنزيل» لرمز الزرّ — أحرفٌ وأرقامٌ لا غير.
     *
     * الزرُّ ينتظر كعكةً بالرمز الذي أرسله ثمّ يعود إلى حاله — فلا يُضغط
     * مرّتين فيُنزَّل الملفّ مرّتين، ولا يبقى «جارٍ التصدير» بعد أن وصل.
     */
    public static function signal($response)
    {
        $token = (string) request()->query('download_token', '');

        if ($token !== '' && preg_match('/^[A-Za-z0-9]{6,40}$/', $token)) {
            $response->headers->setCookie(cookie('download_token', $token, 1, '/', null, null, false, false, 'Lax'));
        }

        return $response;
    }

    /**
     * خليّةٌ بنوعها: [القيمة، النمط، أهي رقم].
     *
     * @return array{0: mixed, 1: int, 2: bool}
     */
    private function typed(mixed $value, string $type, ?string $fill = null, bool $bold = false): array
    {
        $style = fn (array $spec = []) => $this->writer->style(array_filter($spec + ['fill' => $fill, 'bold' => $bold]));

        if ($value === null || $value === '' || $value === '—') {
            return [$value === '—' ? '—' : '', $style(), false];
        }

        switch ($type) {
            case self::MONEY:
                return is_numeric($value)
                    ? [round((float) $value, 3), $style(['format' => $this->moneyFormat]), true]
                    : [(string) $value, $style(), false];
            case self::NUMBER:
                return is_numeric($value) ? [(float) $value, $style(), true] : [(string) $value, $style(), false];
            case self::INT:
                return is_numeric($value) ? [(int) $value, $style(), true] : [(string) $value, $style(), false];
            case self::DATE:
            case self::DATETIME:
                $at = $value instanceof CarbonInterface ? $value : rescue(fn () => Carbon::parse((string) $value), null, false);
                if ($at === null) {
                    return [(string) $value, $style(), false];
                }

                return [
                    ExcelDate::PHPToExcel($type === self::DATE ? $at->copy()->startOfDay() : $at),
                    $style(['format' => $type === self::DATE ? 'yyyy-mm-dd' : 'yyyy-mm-dd hh:mm']),
                    true,
                ];
            case self::AUTO:
                // كما كان PhpSpreadsheet يقرّر: رقمٌ إن بدا رقمًا، إلّا ما بدأ بصفرٍ (هاتفٌ أو رمز)
                if (is_int($value) || is_float($value)
                    || (is_string($value) && is_numeric($value) && ! preg_match('/^[+\s]|^-?0\d/', $value) && strlen($value) <= 15)) {
                    return [$value + 0, $style(), true];
                }

                return [is_bool($value) ? ($value ? 'TRUE' : 'FALSE') : (string) $value, $style(), false];
            default:
                // رقمُ فاتورةٍ أو SKU أو هاتف: نصٌّ لا رقم — وإلا سقطت أصفارُه الأولى
                return [(string) $value, $style(), false];
        }
    }
}
