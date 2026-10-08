<?php

namespace App\Support\Lists;

use App\Support\Demo;
use App\Support\Exports\Dataset;
use App\Support\Exports\Workbook;
use App\Support\Search;
use Illuminate\Http\Request;

/**
 * فواتيرُ اشتراكات المنصّة — مرشَّحةً على الخادم، والشاشةُ وملفّاتُها منها.
 *
 * والمبلغُ بالريال العُمانيّ دائمًا: أبعادُ بائعةٌ والمتجرُ مشترٍ، والاشتراكُ
 * يُفوتَر بعملة المنصّة لا بعملة المتجر — كما في `pdf/platform-invoice`.
 */
final class PlatformInvoicesList
{
    public const STATUSES = ['مدفوعة', 'غير مدفوعة'];

    public const SORTS = ['number', 'business', 'amount', 'date'];

    public static function status(Request $request): ?string
    {
        $status = (string) $request->query('status', '');

        return in_array($status, self::STATUSES, true) ? $status : null;
    }

    public static function rows(Request $request, ?array $all = null): array
    {
        $rows = InMemory::search($all ?? Demo::invoices(), Search::term($request), fn ($i) => $i['number'].' '.$i['business']);

        if ($status = self::status($request)) {
            $rows = array_values(array_filter($rows, fn ($i) => $i['status'] === $status));
        }

        return InMemory::sort($rows, $request, [
            'number' => fn ($i) => $i['number'],
            'business' => fn ($i) => $i['business'],
            'amount' => fn ($i) => $i['amount'],
            'date' => fn ($i) => $i['date'],
        ]);
    }

    public static function export(Request $request): Dataset
    {
        $rows = self::rows($request);
        $paid = array_sum(array_map(fn ($i) => $i['status'] === 'مدفوعة' ? (float) $i['amount'] : 0.0, $rows));

        return new Dataset(
            title: __('فواتير الاشتراكات'),
            file: 'subscription-invoices',
            fileParts: [now()->format('Y-m-d')],
            filters: [
                __('البحث') => Search::term($request),
                __('الحالة') => ($s = self::status($request)) ? __($s) : null,
            ],
            perBranch: null,
            columns: [
                __('رقم الفاتورة') => Workbook::CODE,
                __('الشركة') => Workbook::TEXT,
                __('الباقة') => Workbook::TEXT,
                __('المبلغ').' (OMR)' => Workbook::MONEY,
                __('التاريخ') => Workbook::DATE,
                __('الحالة') => Workbook::TEXT,
            ],
            rows: function () use ($rows) {
                foreach ($rows as $i) {
                    yield [$i['number'], $i['business'], $i['plan'], $i['amount'], $i['date'], __((string) $i['status'])];
                }
            },
            totals: [
                [__('إجمالي المدفوع').' (OMR)', round($paid, 3)],
                [__('إجمالي غير المدفوع').' (OMR)', round(array_sum(array_column($rows, 'amount')) - $paid, 3)],
                [__('عدد الفواتير'), count($rows), Workbook::INT],
            ],
            totalsColumn: 3,
        );
    }
}
