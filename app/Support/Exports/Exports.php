<?php

namespace App\Support\Exports;

use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * يكتب `Dataset` بصيغةٍ — Excel أو CSV — من الصفوف نفسِها.
 */
final class Exports
{
    /** Excel — الصيغةُ الافتراضيّة */
    public static function xlsx(Dataset $data): StreamedResponse
    {
        $book = new Workbook($data->title, $data->filters, $data->perBranch);
        $book->table($data->columns);

        foreach (($data->rows)() as $row) {
            isset($row['cells']) ? $book->row($row['cells'], $row['fill'] ?? null) : $book->row($row);
        }

        $book->endTable();

        if ($data->totals !== []) {
            $book->totals($data->totals, $data->totalsColumn);
        }

        return $book->download(Workbook::filename($data->file, $data->fileParts));
    }

    /**
     * CSV — لمن يربطه بنظامٍ آخر. والصفوفُ صفوفُ Excel نفسُها.
     *
     * المبالغُ أرقامٌ بلا فواصل آلاف (تُقرأ أرقامًا)، والتواريخُ نصٌّ
     * `Y-m-d H:i`، وBOM في أوّله ليقرأ Excel العربيّة.
     */
    public static function csv(Dataset $data): StreamedResponse
    {
        $types = array_values($data->columns);

        $response = response()->streamDownload(function () use ($data, $types) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            // `escape` فارغٌ صراحةً: CSV القياسيّ يضاعف الاقتباس ولا شرطةَ هروب فيه
            fputcsv($out, array_keys($data->columns), escape: '');

            // بلا سقف: كلُّ صفٍّ يُكتب ساعةَ يصل ولا يُمسَك
            foreach (($data->rows)() as $row) {
                $cells = isset($row['cells']) ? $row['cells'] : $row;
                fputcsv($out, array_map(fn ($v, $i) => self::csvCell($v, $types[$i] ?? Workbook::TEXT), array_values($cells), array_keys(array_values($cells))), escape: '');
            }

            fclose($out);
        }, Workbook::filename($data->file, $data->fileParts, 'csv'), ['Content-Type' => 'text/csv; charset=UTF-8']);

        return Workbook::signal($response);
    }

    private static function csvCell(mixed $value, string $type): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return match ($type) {
            Workbook::MONEY => (string) round((float) $value, 3),
            Workbook::INT => (string) (int) $value,
            Workbook::DATE => $value instanceof \DateTimeInterface ? Carbon::instance($value)->format('Y-m-d') : (string) $value,
            Workbook::DATETIME => $value instanceof \DateTimeInterface ? Carbon::instance($value)->format('Y-m-d H:i') : (string) $value,
            default => (string) $value,
        };
    }
}
