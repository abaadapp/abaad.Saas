<?php

namespace App\Support\Exports;

use RuntimeException;
use ZipArchive;

/**
 * كاتبُ xlsx يكتب الصفَّ إلى القرص ساعةَ يصل — لا يُمسك الورقة في الذاكرة.
 *
 * PhpSpreadsheet يبني كلَّ خليّةٍ كائنًا حتى الحفظ: عشرةُ آلاف صفٍّ قرابةُ
 * ١٨٠ ميغا، وسبعون ألفًا تتجاوز ذاكرة الطلب. فكان للتصدير سقفٌ يقصّ ما
 * فوقه — والوعدُ «كلُّ ما طابق». وهذا يكتب كلَّ صفٍّ XML في ملفٍّ مؤقّت ويُفلته،
 * فالذاكرةُ ذاكرةُ صفٍّ واحد مهما طال الجدول، ثمّ يُضغط الملفُّ عند الإغلاق.
 *
 * وما يكتبه هو ما تحتاجه أوراقنا لا أكثر: نصوصٌ داخليّة (`inlineStr`)،
 * وأرقام، وأنماطٌ (خطٌّ عريضٌ وحجمٌ ولونٌ وخلفيّةٌ وصيغةُ رقمٍ ومحاذاة)،
 * ودمجُ خلايا، وتجميدُ الرأس، والاتّجاه، وعرضُ الأعمدة من أطول ما كُتب فيها.
 */
final class XlsxWriter
{
    /** @var list<array{name: string, rtl: bool, path: string, handle: resource, row: int, widths: array<int, int>, merges: list<string>, freeze: ?int}> */
    private array $sheets = [];

    /** @var array<string, int> مفتاحُ النمط ← رقمُه في `cellXfs` */
    private array $xfs = ['' => 0];

    /** @var list<array{font: int, fill: int, numFmt: int, align: ?string}> */
    private array $xfList = [['font' => 0, 'fill' => 0, 'numFmt' => 0, 'align' => null]];

    /** @var array<string, int> */
    private array $fonts = ['||||' => 0];

    /** @var array<string, int> الأوّلان محجوزان في المواصفة: none وgray125 */
    private array $fills = ['none' => 0, 'gray125' => 1];

    /** @var array<string, int> الصيغُ المخصّصة تبدأ من ١٦٤ */
    private array $numFmts = [];

    /** @var array<int, string> رقمُ النمط ← صيغتُه — ليُقاس عرضُ الرقم كما يُعرض */
    private array $formats = [];

    private bool $closed = false;

    /** يفتح لسانًا جديدًا ويجعله الحاليّ — يُعاد رقمُه */
    public function sheet(string $name, bool $rtl): int
    {
        $path = tempnam(sys_get_temp_dir(), 'xls');
        $handle = fopen($path, 'w');
        if ($handle === false) {
            throw new RuntimeException('Cannot open a temporary sheet file.');
        }

        $this->sheets[] = [
            'name' => $this->uniqueName($name), 'rtl' => $rtl, 'path' => $path, 'handle' => $handle,
            'row' => 0, 'widths' => [], 'merges' => [], 'freeze' => null,
        ];

        return count($this->sheets) - 1;
    }

    /**
     * رقمُ نمطٍ لخليّة — يُسجَّل مرّةً ويُعاد رقمُه.
     *
     * @param  array{bold?: bool, italic?: bool, size?: int, color?: string, fill?: string, format?: string, align?: string}  $spec
     */
    public function style(array $spec): int
    {
        $key = json_encode($spec);
        if (isset($this->xfs[$key])) {
            return $this->xfs[$key];
        }

        $fontKey = implode('|', [
            ! empty($spec['bold']) ? 'b' : '', ! empty($spec['italic']) ? 'i' : '',
            $spec['size'] ?? '', $spec['color'] ?? '', '',
        ]);
        $font = $this->fonts[$fontKey] ??= count($this->fonts);

        $fill = 0;
        if (! empty($spec['fill'])) {
            $fill = $this->fills[$spec['fill']] ??= count($this->fills);
        }

        $numFmt = 0;
        if (! empty($spec['format'])) {
            $numFmt = $this->numFmts[$spec['format']] ??= 164 + count($this->numFmts);
        }

        $this->xfList[] = ['font' => $font, 'fill' => $fill, 'numFmt' => $numFmt, 'align' => $spec['align'] ?? null];
        $id = count($this->xfList) - 1;
        if (! empty($spec['format'])) {
            $this->formats[$id] = $spec['format'];
        }

        return $this->xfs[$key] = $id;
    }

    /**
     * صفٌّ في اللسان الحاليّ — والصفوفُ تُكتب بترتيبها لا رجوعَ إلى سابق.
     *
     * @param  array<int, array{0: mixed, 1?: int, 2?: bool}>  $cells  رقمُ العمود (من ١) ← [القيمة، النمط، أهي رقم]
     * @param  bool  $measure  أيُحسب عرضُ الأعمدة من هذا الصفّ؟ (سطورُ الترويسة الطويلة لا)
     */
    public function row(int $row, array $cells, bool $measure = true): void
    {
        $sheet = &$this->sheets[count($this->sheets) - 1];
        if ($row <= $sheet['row']) {
            throw new RuntimeException("Row {$row} is written after row {$sheet['row']}.");
        }
        $sheet['row'] = $row;

        ksort($cells);
        $xml = '<row r="'.$row.'">';
        foreach ($cells as $col => $cell) {
            [$value, $style, $numeric] = $cell + [null, 0, false];
            if ($value === null) {
                continue;
            }
            $ref = self::column($col).$row;
            $s = $style ? ' s="'.$style.'"' : '';

            if ($numeric) {
                $text = self::number($value);
                $xml .= '<c r="'.$ref.'"'.$s.'><v>'.$text.'</v></c>';
            } else {
                $text = self::clean((string) $value);
                $xml .= '<c r="'.$ref.'"'.$s.' t="inlineStr"><is><t xml:space="preserve">'
                    .htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8').'</t></is></c>';
            }

            if ($measure) {
                // الرقمُ بعرضه معروضًا: التاريخُ بطول صيغته، والمبلغُ بفواصله
                $format = $this->formats[$style] ?? '';
                $width = ! $numeric ? mb_strwidth($text)
                    : (str_contains($format, 'y') ? strlen($format) : strlen(number_format((float) $value, 2)));
                if (($sheet['widths'][$col] ?? 0) < $width) {
                    $sheet['widths'][$col] = $width;
                }
            }
        }
        fwrite($sheet['handle'], $xml.'</row>');
        unset($sheet);
    }

    /** يدمج نطاقًا في اللسان الحاليّ — `A5:C5` */
    public function merge(string $range): void
    {
        $this->sheets[count($this->sheets) - 1]['merges'][] = $range;
    }

    /** يجمّد ما فوق الصفّ `$row` في اللسان الحاليّ */
    public function freeze(int $row): void
    {
        $this->sheets[count($this->sheets) - 1]['freeze'] = $row;
    }

    /** يكتب الملفّ إلى مسارٍ مؤقّت ويردّه — والأجزاءُ المؤقّتة تُحذف */
    public function close(): string
    {
        if ($this->closed) {
            throw new RuntimeException('The workbook is already closed.');
        }
        $this->closed = true;

        if ($this->sheets === []) {
            $this->sheet('Sheet', false);
        }

        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Cannot create the xlsx archive.');
        }

        $parts = [];
        foreach ($this->sheets as $i => $sheet) {
            fclose($sheet['handle']);
            $parts[] = $part = $this->assemble($sheet);
            $zip->addFile($part, 'xl/worksheets/sheet'.($i + 1).'.xml');
        }

        $zip->addFromString('[Content_Types].xml', $this->contentTypes());
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>');
        $zip->addFromString('xl/workbook.xml', $this->workbook());
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRels());
        $zip->addFromString('xl/styles.xml', $this->styles());

        // ZipArchive يقرأ الملفّات المضافة عند الإغلاق — فلا تُحذف قبله
        $ok = $zip->close();
        foreach ($this->sheets as $sheet) {
            @unlink($sheet['path']);
        }
        foreach ($parts as $part) {
            @unlink($part);
        }
        if (! $ok) {
            @unlink($path);
            throw new RuntimeException('Cannot write the xlsx archive.');
        }

        return $path;
    }

    /** حرفُ العمود من رقمه — ١ ← A، ٢٧ ← AA */
    public static function column(int $index): string
    {
        $letters = '';
        while ($index > 0) {
            $index--;
            $letters = chr(65 + $index % 26).$letters;
            $index = intdiv($index, 26);
        }

        return $letters;
    }

    /** رقمٌ كما يقرؤه Excel — بلا فواصل ولا منازلَ عائمةٍ زائدة */
    private static function number(mixed $value): string
    {
        if (is_int($value)) {
            return (string) $value;
        }
        $float = (float) $value;
        if (! is_finite($float)) {
            return '0';
        }

        // أقصرُ تمثيلٍ يعود إلى الرقم نفسه (`serialize_precision`): 132.02 لا
        // 132.02000000000001، ووقتُ التاريخ بكامل دقّته فلا تصير ٥:٠٠ ٤:٥٩
        return var_export($float, true);
    }

    /** يحذف ما لا يقبله XML من محارف التحكّم، ويُصلح UTF-8 المكسور */
    private static function clean(string $text): string
    {
        if (! mb_check_encoding($text, 'UTF-8')) {
            $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        }

        return (string) preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $text);
    }

    /** اسمُ لسانٍ يقبله Excel: ٣١ محرفًا بلا `: \ / ? * [ ]` وغيرُ مكرّر */
    private function uniqueName(string $name): string
    {
        $base = trim(mb_substr(str_replace(['*', ':', '/', '\\', '?', '[', ']'], ' ', $name), 0, 28)) ?: 'Sheet';
        $taken = array_map(fn ($s) => mb_strtolower($s['name']), $this->sheets);
        $candidate = $base;
        for ($n = 2; in_array(mb_strtolower($candidate), $taken, true); $n++) {
            $candidate = $base.' '.$n;
        }

        return $candidate;
    }

    /** اللسانُ كاملًا: رأسُه (الاتّجاه والتجميد والعروض) ثمّ صفوفُه ثمّ دمجُه */
    private function assemble(array $sheet): string
    {
        $path = tempnam(sys_get_temp_dir(), 'xlw');
        $out = fopen($path, 'w');

        $view = '<sheetView workbookViewId="0"'.($sheet['rtl'] ? ' rightToLeft="1"' : '');
        if ($sheet['freeze'] !== null && $sheet['freeze'] > 1) {
            $top = 'A'.$sheet['freeze'];
            $view .= '><pane ySplit="'.($sheet['freeze'] - 1).'" topLeftCell="'.$top.'" activePane="bottomLeft" state="frozen"/>'
                .'<selection pane="bottomLeft" activeCell="'.$top.'" sqref="'.$top.'"/></sheetView>';
        } else {
            $view .= '/>';
        }

        $cols = '';
        if ($sheet['widths'] !== []) {
            ksort($sheet['widths']);
            $cols = '<cols>';
            foreach ($sheet['widths'] as $col => $chars) {
                // عرضُ أطول ما في العمود وهامشٌ صغير — بين ٨ و٦٠ محرفًا
                $width = min(60, max(8, $chars * 1.15 + 2));
                $cols .= '<col min="'.$col.'" max="'.$col.'" width="'.round($width, 2).'" customWidth="1"/>';
            }
            $cols .= '</cols>';
        }

        fwrite($out, '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheetViews>'.$view.'</sheetViews><sheetFormatPr defaultRowHeight="15"/>'.$cols.'<sheetData>');

        $in = fopen($sheet['path'], 'r');
        stream_copy_to_stream($in, $out);
        fclose($in);

        $tail = '</sheetData>';
        if ($sheet['merges'] !== []) {
            $tail .= '<mergeCells count="'.count($sheet['merges']).'">';
            foreach ($sheet['merges'] as $range) {
                $tail .= '<mergeCell ref="'.$range.'"/>';
            }
            $tail .= '</mergeCells>';
        }
        fwrite($out, $tail.'</worksheet>');
        fclose($out);

        return $path;
    }

    private function contentTypes(): string
    {
        $sheets = '';
        foreach (array_keys($this->sheets) as $i) {
            $sheets .= '<Override PartName="/xl/worksheets/sheet'.($i + 1).'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            .$sheets.'</Types>';
    }

    private function workbook(): string
    {
        $sheets = '';
        foreach ($this->sheets as $i => $sheet) {
            $sheets .= '<sheet name="'.htmlspecialchars($sheet['name'], ENT_XML1 | ENT_QUOTES, 'UTF-8').'" sheetId="'.($i + 1).'" r:id="rId'.($i + 1).'"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<bookViews><workbookView activeTab="0"/></bookViews><sheets>'.$sheets.'</sheets></workbook>';
    }

    private function workbookRels(): string
    {
        $rels = '';
        foreach (array_keys($this->sheets) as $i) {
            $rels .= '<Relationship Id="rId'.($i + 1).'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.($i + 1).'.xml"/>';
        }
        $styles = count($this->sheets) + 1;

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.$rels
            .'<Relationship Id="rId'.$styles.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            .'</Relationships>';
    }

    private function styles(): string
    {
        $esc = fn (string $s) => htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');

        $numFmts = '';
        foreach ($this->numFmts as $code => $id) {
            $numFmts .= '<numFmt numFmtId="'.$id.'" formatCode="'.$esc($code).'"/>';
        }

        $fonts = '';
        foreach (array_keys($this->fonts) as $key) {
            [$bold, $italic, $size, $color] = explode('|', $key);
            $fonts .= '<font>'.($bold ? '<b/>' : '').($italic ? '<i/>' : '')
                .'<sz val="'.($size !== '' ? $size : 11).'"/>'
                .($color !== '' ? '<color rgb="FF'.$color.'"/>' : '')
                .'<name val="Calibri"/><family val="2"/></font>';
        }

        $fills = '';
        foreach (array_keys($this->fills) as $key) {
            $fills .= match ($key) {
                'none' => '<fill><patternFill patternType="none"/></fill>',
                'gray125' => '<fill><patternFill patternType="gray125"/></fill>',
                default => '<fill><patternFill patternType="solid"><fgColor rgb="FF'.$key.'"/><bgColor indexed="64"/></patternFill></fill>',
            };
        }

        $xfs = '';
        foreach ($this->xfList as $xf) {
            $xfs .= '<xf numFmtId="'.$xf['numFmt'].'" fontId="'.$xf['font'].'" fillId="'.$xf['fill'].'" borderId="0" xfId="0"'
                .($xf['numFmt'] ? ' applyNumberFormat="1"' : '').($xf['font'] ? ' applyFont="1"' : '').($xf['fill'] ? ' applyFill="1"' : '')
                .($xf['align'] ? ' applyAlignment="1"><alignment horizontal="'.$xf['align'].'"/></xf>' : '/>');
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .($numFmts !== '' ? '<numFmts count="'.count($this->numFmts).'">'.$numFmts.'</numFmts>' : '')
            .'<fonts count="'.count($this->fonts).'">'.$fonts.'</fonts>'
            .'<fills count="'.count($this->fills).'">'.$fills.'</fills>'
            .'<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="'.count($this->xfList).'">'.$xfs.'</cellXfs>'
            .'<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            .'</styleSheet>';
    }
}
