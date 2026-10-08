<?php

namespace App\Support\Lists;

use App\Support\Demo;
use App\Support\Exports\Dataset;
use App\Support\Exports\Workbook;
use App\Support\Search;
use Illuminate\Http\Request;

/**
 * المخزون — صفوفُ الفرع المختار، مرشَّحةً ومرتَّبةً على الخادم.
 *
 * الكمّيّةُ والحالةُ والقيمةُ تُحسب من دفاتر الفروع (`Demo::inventory`)،
 * فالترشيحُ بعدها لا قبلها: «منخفض» في الخوض غيرُ «منخفض» في الشركة.
 */
final class InventoryList
{
    /** حالاتُ المرشِّح — ما يُرجعه `Product::statusFor` حرفيًّا */
    public const STATUSES = ['متوفر', 'منخفض', 'نفد المخزون'];

    public const SORTS = ['name', 'qty', 'cost', 'value'];

    /** الحالةُ المطلوبة إن كانت من المسموح — وغيرُها يُهمَل */
    public static function status(Request $request): ?string
    {
        $stock = (string) $request->query('stock', '');

        return in_array($stock, self::STATUSES, true) ? $stock : null;
    }

    /** صفوفُ الشاشة كلُّها بعد البحث والحالة والترتيب — بلا ترقيم */
    public static function rows(Request $request, ?array $all = null): array
    {
        $rows = InMemory::search($all ?? Demo::inventory(), Search::term($request), fn ($i) => $i['name'].' '.$i['sku']);

        if ($status = self::status($request)) {
            $rows = array_values(array_filter($rows, fn ($i) => $i['status'] === $status));
        }

        return InMemory::sort($rows, $request, array_combine(self::SORTS, [
            fn ($i) => $i['name'], fn ($i) => $i['qty'], fn ($i) => $i['cost'], fn ($i) => $i['value'],
        ]));
    }

    public static function export(Request $request): Dataset
    {
        $rows = self::rows($request);

        return new Dataset(
            title: __('جرد المخزون'),
            file: 'inventory',
            fileParts: [now()->format('Y-m-d')],
            filters: [
                __('البحث') => Search::term($request),
                __('الحالة') => ($s = self::status($request)) ? __($s) : null,
            ],
            perBranch: true,
            columns: [
                __('المنتج') => Workbook::TEXT,
                'SKU' => Workbook::CODE,
                __('الكمية الحالية') => Workbook::INT,
                __('الحد الأدنى') => Workbook::INT,
                __('حالة المخزون') => Workbook::TEXT,
                Workbook::money('التكلفة') => Workbook::MONEY,
                Workbook::money('القيمة') => Workbook::MONEY,
                __('آخر تحديث') => Workbook::DATE,
            ],
            rows: function () use ($rows) {
                foreach ($rows as $i) {
                    yield [
                        'cells' => [$i['name'], $i['sku'], $i['qty'], $i['min'], __((string) $i['status']), $i['cost'], $i['value'], $i['updated']],
                        // الناقصُ والمنتهي بلونٍ يُقرأ بنظرة — كما كان
                        'fill' => (int) $i['qty'] <= 0 ? 'FDE8E8' : ((int) $i['qty'] <= (int) $i['min'] ? 'FEF3E2' : null),
                    ];
                }
            },
            total: count($rows),
            totals: [
                [__('عدد الأصناف'), count($rows), Workbook::INT],
                [Workbook::money('القيمة الإجمالية'), round(array_sum(array_column($rows, 'value')), 3)],
            ],
        );
    }
}
