<?php

namespace App\Support\Archive;

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;

/**
 * ورقةُ إكسل واحدة تُكتب سطرًا سطرًا ثمّ تُغلَق.
 *
 * ═══ ولمَ لم تُعَد كتابةُ `ReportExportController` هنا ═══
 *
 * في المستودع طبقةُ تصديرٍ ناضجة: ترويسةٌ باسم المتجر، ورؤوسٌ سوداءُ بخطٍّ
 * أبيض، وتنسيقُ مبلغٍ بثلاث منازل، وعرضٌ تلقائيّ. وهي **غيرُ صالحةٍ للنداء
 * من وظيفةٍ في الطابور**: تقرأ المتجرَ من `auth()` والفترةَ من
 * `request()->query('range')`، وتبني `Demo::orders()` مصفوفةً كاملةً في
 * الذاكرة.
 *
 * فما أُعيد استعمالُه هو **شكلُ الورقة** — الألوانُ والمنازلُ والتجميد —
 * وما لم يُعَد هو ربطُها بالطلب. ولا تُلمس تلك: اثنا عشرَ تصديرًا يعمل بها
 * اليوم، وتغييرُها لأجل هذا لا يُضيف للتاجر شيئًا ويكسر ما يعمل.
 *
 * ═══ والذاكرة ═══
 *
 * PhpSpreadsheet يُمسك الخلايا في الذاكرة — لا كاتبَ متدفّقًا فيه. فالحدُّ
 * هنا ثلاثة:
 *
 *  ١) ورقةٌ لكلّ ملفّ، و`close()` تفصل أوراقها وتُفلتها بعد الحفظ. فذروةُ
 *     الذاكرة ذروةُ **أكبر** ملفّ لا مجموعُ الاثني عشر.
 *  ٢) الصفوفُ تصل من `cursor()` — القاعدة تُرسلها سطرًا سطرًا ولا تُبنى
 *     مصفوفةُ نماذجَ كاملة.
 *  ٣) سقفٌ للصفوف: تجاوزُه **يُسقط الأرشيف برسالةٍ تُقرأ**، ولا يقصّ
 *     الورقةَ صامتًا. وملفٌّ ناقصٌ يُقدَّم على أنّه كامل أسوأ من غيابه.
 */
final class Book
{
    /**
     * سقفُ الصفوف في الورقة الواحدة.
     *
     * ومئةُ ألفٍ ليست حدَّ إكسل (هو مليون) بل حدَّ الذاكرة: ورقةٌ بمئة ألف
     * صفٍّ واثني عشر عمودًا تقارب ٤٠٠ ميجابايت في PhpSpreadsheet. وشهرٌ
     * واحد لأكبر متجرٍ عندنا لا يبلغ عُشرَها — فمن بلغها فحالُه يُقرأ لا
     * يُقَصّ.
     */
    public const MAX_ROWS = 100_000;

    private Spreadsheet $book;

    private Worksheet $sheet;

    private int $row = 1;

    private int $dataRows = 0;

    private int $firstDataRow = 0;

    /** أعمدةُ المال — تُنسَّق دفعةً عند الحفظ لا خليّةً خليّة */
    private array $moneyColumns = [];

    private int $columns = 0;

    private function __construct(string $title, bool $rtl)
    {
        $this->book = new Spreadsheet;
        $this->sheet = $this->book->getActiveSheet();
        $this->sheet->setRightToLeft($rtl);

        /*
         * واسمُ اللسان يُقصّ إلى إحدى وثلاثين.
         *
         * إكسل يرفض ما زاد، ويرفض `: \ / ? * [ ]`. واسمٌ مترجَمٌ طويل يُسقط
         * الحفظَ كلَّه باستثناءٍ لا علاقةَ له بالبيانات.
         */
        $this->sheet->setTitle(self::tabName($title));
    }

    public static function start(string $title, bool $rtl = true): self
    {
        return new self($title, $rtl);
    }

    /** سطرُ ترويسةٍ يقول لمن هذه الورقة وعن أيّ فترة */
    public function caption(array $lines): self
    {
        foreach ($lines as $line) {
            $this->sheet->setCellValue('A'.$this->row, $line);
            $this->row++;
        }

        $this->sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $this->row++;

        return $this;
    }

    /** رؤوسُ الجدول — سوداءُ بخطٍّ أبيض كما في بقيّة تصديرات النظام */
    public function head(array $columns): self
    {
        $this->columns = count($columns);
        $r = $this->row;
        $last = self::columnLetter($this->columns);

        $this->sheet->fromArray($columns, null, "A{$r}");
        $this->sheet->getStyle("A{$r}:{$last}{$r}")->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $this->sheet->getStyle("A{$r}:{$last}{$r}")->getFill()
            ->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('111111');
        $this->sheet->getStyle("A{$r}:{$last}{$r}")->getAlignment()
            ->setHorizontal($this->sheet->getRightToLeft() ? Alignment::HORIZONTAL_RIGHT : Alignment::HORIZONTAL_LEFT);

        $this->row++;
        $this->firstDataRow = $this->row;

        return $this;
    }

    /**
     * صفُّ بيانات.
     *
     * والنصُّ الذي يبدو رقمًا يُكتب نصًّا صريحًا: رقمُ فاتورةٍ
     * «CINV-000121» يمرّ، لكنّ «0012» يصير ١٢ فيُفقد الصفران — وهو ما يجعل
     * المحاسب لا يجد الفاتورة حين يبحث عنها برقمها.
     *
     * @param  array<int, mixed>  $cells  قيمٌ، أو `Cell::text('…')` لما يُجبَر نصًّا
     */
    public function row(array $cells): self
    {
        if ($this->dataRows >= self::MAX_ROWS) {
            throw new RuntimeException(__('تجاوزت ورقةٌ في الأرشيف :max صفًّا — الفترةُ أكبر من أن تُكتب في ملفٍّ واحد.', [
                'max' => self::MAX_ROWS,
            ]));
        }

        $r = $this->row;
        $index = 0;

        foreach ($cells as $value) {
            $column = self::columnLetter(++$index);

            if ($value instanceof Text) {
                $this->sheet->setCellValueExplicit($column.$r, $value->value, DataType::TYPE_STRING);

                continue;
            }

            if ($value instanceof Amount) {
                $this->sheet->setCellValue($column.$r, round($value->value, 3));
                $this->moneyColumns[$column] = true;

                continue;
            }

            $this->sheet->setCellValue($column.$r, $value);
        }

        $this->row++;
        $this->dataRows++;

        return $this;
    }

    public function rowCount(): int
    {
        return $this->dataRows;
    }

    /**
     * يحفظ الورقةَ في مسارٍ على نظام الملفّات.
     *
     * والتنسيقُ هنا لا مع كلّ صفّ: ضبطُ نمطِ خليّةٍ في حلقةٍ على عشرين ألف
     * صفٍّ يضاعف الزمنَ والذاكرة، والنتيجةُ واحدة.
     */
    public function saveTo(string $path): void
    {
        foreach (array_keys($this->moneyColumns) as $column) {
            $this->sheet->getStyle($column.$this->firstDataRow.':'.$column.max($this->row - 1, $this->firstDataRow))
                ->getNumberFormat()->setFormatCode('#,##0.000');
        }

        if ($this->firstDataRow > 0) {
            $this->sheet->freezePane('A'.$this->firstDataRow);
        }

        for ($i = 1; $i <= max($this->columns, 1); $i++) {
            $this->sheet->getColumnDimension(self::columnLetter($i))->setAutoSize(true);
        }

        $writer = new Xlsx($this->book);
        // لا صيغَ في هذه الأوراق — وحسابُها يمرّ على كلّ خليّةٍ بلا طائل
        $writer->setPreCalculateFormulas(false);
        $writer->save($path);
    }

    /**
     * يُفلت أوراقَ الكتاب.
     *
     * و`disconnectWorksheets` ليست زينة: PhpSpreadsheet يربط الورقةَ
     * بكتابها والكتابَ بورقته، فمرجعان دائريّان لا يجمعهما جامعُ القمامة.
     * واثنا عشر ملفًّا في وظيفةٍ واحدة تُبقي أثرَ كلٍّ منها إلى آخرها.
     */
    public function close(): void
    {
        $this->book->disconnectWorksheets();
    }

    /** حرفُ العمود من رقمه — ويتجاوز Z إلى AA */
    public static function columnLetter(int $index): string
    {
        $letter = '';

        while ($index > 0) {
            $rest = ($index - 1) % 26;
            $letter = chr(65 + $rest).$letter;
            $index = (int) (($index - $rest - 1) / 26);
        }

        return $letter ?: 'A';
    }

    /** اسمُ لسانٍ يقبله إكسل — ٣١ حرفًا بلا محارف ممنوعة */
    public static function tabName(string $title): string
    {
        $clean = preg_replace('/[\\\\\\/\\?\\*\\[\\]:]/u', '-', $title) ?? $title;

        return mb_substr(trim($clean) ?: 'Sheet', 0, 31);
    }
}
