<?php

namespace App\Support\Lists;

use App\Models\Customer;
use App\Support\Demo;
use App\Support\Exports\Dataset;
use App\Support\Exports\Workbook;
use App\Support\ListFilters;
use App\Support\Search;
use App\Support\Sort;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * قائمةُ العملاء — استعلامٌ واحد تقرؤه الشاشةُ وملفُّها.
 *
 * وملفُّ الاستيراد (`CustomerImportExportController::exportXlsx`) غيرُه:
 * أعمدةُ الاستيراد بلا ترويسة، ليُعاد رفعُه كما خرج.
 */
final class CustomersList
{
    /**
     * ما يُرتَّب في قائمة العملاء.
     *
     * والمجاميع تُرتَّب بأسماء `withCount`/`withSum` نفسها: هي أعمدةٌ في
     * الاستعلام المُنتَج، فترتيبها لا يحتاج ضمًّا زائدًا.
     */
    public const SORTS = [
        'name' => 'name',
        'orders' => 'orders_count',
        'total_spent' => 'orders_sum_total',
        'last_order' => 'orders_max_ordered_at',
        'points' => 'points',
    ];

    /** المرشِّحاتُ بلا ترتيب */
    public static function filtered(Request $request): Builder
    {
        /*
         * ما اشتراه العميل فعلًا — لا الملغى ولا سلّةً معلّقة.
         *
         * `withCount('orders')` تعدّ العلاقة كما هي فتتجاوز النطاق: فكانت
         * البطاقة فوق الجدول تستثني الملغى وصفوفُه تحتها تجمعه.
         */
        $sold = fn ($q) => $q->sold();

        $q = Customer::where('business_id', auth()->user()->business_id ?? Demo::bid())
            ->withCount(['orders as orders_count' => $sold])
            ->withSum(['orders as orders_sum_total' => $sold], 'total')
            ->withMax(['orders as orders_max_ordered_at' => $sold], 'ordered_at');

        ListFilters::customers($q, $request);

        return $q;
    }

    /** وبترتيب الشاشة: الأحدثُ تسجيلًا ما لم يُختر غيره */
    public static function query(Request $request): Builder
    {
        $q = self::filtered($request);
        Sort::apply($q, $request, self::SORTS, fn ($w) => $w->orderByDesc('id'));
        $q->orderByDesc('id');

        return $q;
    }

    public static function row(Customer $c): array
    {
        return [
            'id' => $c->id, 'name' => $c->name, 'name_en' => $c->name_en,
            'label' => Demo::ln($c->name, $c->name_en),
            'phone' => $c->phone, 'email' => $c->email,
            'orders' => $c->orders_count,
            'total_spent' => (float) ($c->orders_sum_total ?? 0),
            'last_order' => $c->orders_max_ordered_at
                ? Carbon::parse($c->orders_max_ordered_at)->format('Y-m-d') : '—',
            'points' => $c->points,
            'language' => $c->language,
        ];
    }

    public static function export(Request $request): Dataset
    {
        $query = self::query($request);
        $count = (clone $query)->count();

        return new Dataset(
            title: __('العملاء'),
            file: 'customers',
            fileParts: [now()->format('Y-m-d')],
            filters: [
                __('البحث') => Search::term($request),
                __('اللغة') => (string) $request->query('missing') === 'language' ? __('لم تُحدَّد') : null,
            ],
            perBranch: null,
            columns: [
                __('الاسم') => Workbook::TEXT,
                __('الهاتف') => Workbook::CODE,
                __('البريد') => Workbook::TEXT,
                __('عدد الطلبات') => Workbook::INT,
                Workbook::money('إجمالي الإنفاق') => Workbook::MONEY,
                __('آخر طلب') => Workbook::DATE,
                __('النقاط') => Workbook::INT,
                __('اللغة') => Workbook::TEXT,
            ],
            rows: function () use ($query) {
                foreach ($query->cursor() as $c) {
                    $r = self::row($c);
                    yield [
                        $r['label'], $r['phone'], $r['email'], $r['orders'], $r['total_spent'],
                        $r['last_order'], $r['points'],
                        match ($r['language']) {
                            'ar' => __('العربية'), 'en' => 'English', default => '—'
                        },
                    ];
                }
            },
            totals: [[__('عدد العملاء'), $count, Workbook::INT]],
        );
    }
}
