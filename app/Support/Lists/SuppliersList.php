<?php

namespace App\Support\Lists;

use App\Support\Demo;
use App\Support\Exports\Dataset;
use App\Support\Exports\Workbook;
use App\Support\Search;
use Illuminate\Http\Request;

/**
 * المورّدون — مرشَّحون ومرتَّبون على الخادم، والشاشةُ وملفّاتُها منهم.
 *
 * وملفُّ الاستيراد (`SupplierExportController::xlsx`) غيرُه: أعمدةُ الاستيراد
 * بلا ترويسة، ليُعاد رفعُه كما خرج — ويتبع البحثَ كذلك.
 */
final class SuppliersList
{
    public const SORTS = ['name', 'orders_count'];

    /** صفوفُ الشاشة كلُّها بعد البحث والترتيب — بلا ترقيم */
    public static function rows(Request $request, ?array $all = null): array
    {
        $rows = InMemory::search(
            $all ?? Demo::suppliers(),
            Search::term($request),
            fn ($s) => implode(' ', [$s['name'], $s['name_en'] ?? '', $s['phone'] ?? '', $s['email'] ?? '', $s['contact'] ?? '']),
        );

        return InMemory::sort($rows, $request, [
            'name' => fn ($s) => $s['label'] ?? $s['name'],
            'orders_count' => fn ($s) => $s['orders_count'],
        ]);
    }

    public static function export(Request $request): Dataset
    {
        $rows = self::rows($request);

        return new Dataset(
            title: __('الموردون'),
            file: 'suppliers',
            fileParts: [now()->format('Y-m-d')],
            filters: [__('البحث') => Search::term($request)],
            perBranch: null,
            columns: [
                __('الاسم') => Workbook::TEXT,
                __('الهاتف') => Workbook::CODE,
                __('البريد') => Workbook::TEXT,
                __('مسؤول التواصل') => Workbook::TEXT,
                __('أوامر الشراء') => Workbook::INT,
            ],
            rows: function () use ($rows) {
                foreach ($rows as $s) {
                    yield [$s['label'] ?? $s['name'], $s['phone'], $s['email'], $s['contact'], $s['orders_count']];
                }
            },
            total: count($rows),
            totals: [[__('عدد الموردين'), count($rows), Workbook::INT]],
        );
    }
}
