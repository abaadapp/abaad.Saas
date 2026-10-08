<?php

namespace App\Support\Lists;

use App\Models\Business;
use App\Models\Product;
use App\Support\Boutiques;
use App\Support\Demo;
use App\Support\Exports\Dataset;
use App\Support\Exports\Workbook;
use App\Support\ListFilters;
use App\Support\Search;
use App\Support\Sort;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * قائمةُ المنتجات — استعلامٌ واحد تقرؤه الشاشةُ وملفّاتُها.
 *
 * وليست ملفَّ الاستيراد: ذاك (`ProductImportExportController`) الكتالوجُ
 * كلُّه بأعمدة الاستيراد، يُعاد رفعُه كما خرج — ولا يتبع مرشِّحًا.
 */
final class ProductsList
{
    /**
     * ما يُرتَّب في قائمة المنتجات.
     *
     * والقسم ليس منها: اسمه في جدولٍ آخر، وترتيبه يلزمه ضمٌّ يُثقل استعلامًا
     * يُقرأ في كل فتحة. والهامش كذلك — يُحسب من السعر والتكلفة.
     */
    public const SORTS = [
        'name' => 'name',
        'price' => 'price',
        'cost' => 'cost',
        'qty' => 'quantity',
        'active' => 'active',
    ];

    /** بوتيكاتُ المتجر — فارغةٌ لمن لا بوتيكَ عنده فلا عمودَ ولا مرشِّح */
    public static function boutiques(): array
    {
        return Boutiques::options(Business::find(self::bid()));
    }

    public static function query(Request $request, ?array $boutiques = null): Builder
    {
        $boutiques ??= self::boutiques();

        $q = Product::where('business_id', self::bid())->with('category')
            // واسمُ البوتيك بضمٍّ واحد للصفحة لا باستعلامٍ لكلّ صفّ
            ->when($boutiques !== [], fn ($w) => $w->with('boutique:id,name,name_en'));

        ListFilters::products($q, $request);

        /*
         * الأحدث أوّلًا — كما في كلّ قائمةٍ أخرى في اللوحة.
         *
         * كانت تصعد بالمعرّف: فمتجرٌ فيه مئةٌ وعشرون صنفًا يضع الصنف المضاف
         * حديثًا في الصفحة العاشرة، والتاجر يُعاد بعد الحفظ إلى الأولى فيحسب
         * أن شيئًا لم يُحفظ. والملفُّ كان يصعد وحده — فصار يتبع الشاشة.
         */
        Sort::apply($q, $request, self::SORTS, fn ($w) => $w->orderByDesc('id'));
        $q->orderByDesc('id');

        return $q;
    }

    public static function row(Product $p, array $boutiques): array
    {
        return [
            'id' => $p->id, 'name' => $p->name, 'cat' => $p->category?->name ?? '—',
            'price' => (float) $p->price, 'cost' => (float) $p->cost, 'qty' => $p->quantity,
            'sku' => $p->sku, 'barcode' => $p->barcode, 'image' => $p->image,
            'stock_status' => $p->stock_status, 'active' => (bool) $p->active,
            'alert' => $p->alert_qty, 'tax' => (float) $p->tax, 'discount' => (float) $p->discount,
            'tracks_stock' => $p->tracksStock(),
            /*
             * لمن الصنفُ اليوم — `null` لصنف المحلّ.
             *
             * ولا يُرسل لمن لا بوتيكَ عنده: الحمولةُ تبقى كما كانت، والعمودُ
             * لا يُرسم.
             */
            ...($boutiques !== [] ? ['boutique' => $p->boutique?->label()] : []),
        ];
    }

    /** صفوفُ الشاشة كلُّها — لورقة PDF */
    public static function rows(Request $request): array
    {
        $boutiques = self::boutiques();

        return self::query($request, $boutiques)->get()->map(fn (Product $p) => self::row($p, $boutiques))->all();
    }

    public static function export(Request $request): Dataset
    {
        $boutiques = self::boutiques();
        $query = self::query($request, $boutiques);
        $hasBoutiques = $boutiques !== [];
        $count = (clone $query)->count();

        return new Dataset(
            title: __('المنتجات'),
            file: 'products',
            fileParts: [now()->format('Y-m-d')],
            filters: [
                __('البحث') => Search::term($request),
                __('القسم') => $request->query('category'),
                __('الحالة') => match ((string) $request->query('status')) {
                    'active' => __('مفعّل'), 'inactive' => __('معطّل'), default => null,
                },
                __('المخزون') => $request->filled('stock') ? __((string) $request->query('stock')) : null,
                __('البوتيك') => $hasBoutiques && $request->filled('boutique')
                    ? (collect($boutiques)->firstWhere('value', (string) $request->query('boutique'))['label'] ?? null)
                    : null,
            ],
            perBranch: null,
            columns: array_filter([
                __('الاسم') => Workbook::TEXT,
                __('القسم') => Workbook::TEXT,
                __('البوتيك') => $hasBoutiques ? Workbook::TEXT : null,
                'SKU' => Workbook::CODE,
                __('الباركود') => Workbook::CODE,
                Workbook::money('السعر') => Workbook::MONEY,
                Workbook::money('التكلفة') => Workbook::MONEY,
                __('الكمية') => Workbook::INT,
                __('حد التنبيه') => Workbook::INT,
                __('حالة المخزون') => Workbook::TEXT,
                __('الحالة') => Workbook::TEXT,
            ]),
            rows: function () use ($query, $boutiques, $hasBoutiques) {
                foreach ($query->lazy(1000) as $p) {
                    $r = self::row($p, $boutiques);
                    yield array_values(array_filter([
                        'name' => $r['name'], 'cat' => $r['cat'],
                        'boutique' => $hasBoutiques ? ($r['boutique'] ?? __('المحل')) : false,
                        'sku' => $r['sku'], 'barcode' => $r['barcode'],
                        'price' => $r['price'], 'cost' => $r['cost'],
                        'qty' => $r['qty'], 'alert' => $r['alert'],
                        'stock' => __((string) $r['stock_status']),
                        'active' => $r['active'] ? __('مفعّل') : __('معطّل'),
                    ], fn ($v) => $v !== false));
                }
            },
            total: $count,
            totals: [[__('عدد المنتجات'), $count, Workbook::INT]],
        );
    }

    private static function bid(): int
    {
        return auth()->user()->business_id ?? Demo::bid();
    }
}
