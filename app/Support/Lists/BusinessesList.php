<?php

namespace App\Support\Lists;

use App\Models\Business;
use App\Models\Order;
use App\Models\User;
use App\Support\Demo;
use App\Support\Exports\Dataset;
use App\Support\Exports\Workbook;
use App\Support\Search;
use App\Support\Sort;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * شركاتُ المنصّة — استعلامٌ واحد تقرؤه شاشةُ المنصّة وملفّاتُها.
 *
 * كانت الشاشةُ ترشّح بالبحث والنوع والباقة والحالة، وملفّاتُها الثلاثة
 * تُخرج الشركاتِ كلَّها.
 */
final class BusinessesList
{
    public const SORTS = [
        'name' => 'name',
        'type' => 'type',
        'owner' => 'owner_name',
        'status' => 'status',
        'registered' => 'starts_at',
        'expires' => 'ends_at',
        'branches' => 'branches_count',
    ];

    public static function query(Request $request): Builder
    {
        /*
         * متاجر التجّار وحدها — والتجريبيّة في قسم «الديمو».
         *
         * وبريدُ الدخول لا بريدُ التواصل: هو ما يبحث به الدعم عن تاجرٍ يتّصل.
         */
        $q = Business::real()->with('plan')->addSelect([
            'last_sale' => Order::selectRaw('MAX(ordered_at)')
                ->whereColumn('orders.business_id', 'businesses.id')
                ->sold(),
            'owner_email' => User::select('email')
                ->whereColumn('users.business_id', 'businesses.id')
                ->where('role', 'admin')
                ->orderBy('id')
                ->limit(1),
        ]);

        if ($s = Search::term($request)) {
            // والمعامل يُسأل ولا يُكتب: `like` تفرّق بين الكبير والصغير في PostgreSQL
            $op = Search::like();
            $q->where(fn ($w) => $w->where('name', $op, "%{$s}%")
                ->orWhere('owner_name', $op, "%{$s}%")
                ->orWhere('email', $op, "%{$s}%")
                ->orWhereHas('users', fn ($u) => $u->where('role', 'admin')->where('email', $op, "%{$s}%")));
        }
        if ($t = $request->query('type')) {
            $q->where('type', $t);
        }
        if ($p = $request->query('plan')) {
            $q->whereHas('plan', fn ($w) => $w->where('name', $p));
        }
        if ($st = $request->query('status')) {
            $q->where('status', $st);
        }

        Sort::apply($q, $request, self::SORTS, fn ($w) => $w->orderByDesc('id'));
        $q->orderByDesc('id');

        return $q;
    }

    /** صفوفُ الشاشة كلُّها بشكل ملفّاتها — لورقة PDF */
    public static function rows(Request $request): array
    {
        return self::query($request)->get()->map(fn (Business $b) => Demo::businessRow($b))->all();
    }

    public static function export(Request $request): Dataset
    {
        $query = self::query($request);
        $count = (clone $query)->count();

        return new Dataset(
            title: __('الشركات'),
            file: 'businesses',
            fileParts: [now()->format('Y-m-d')],
            filters: [
                __('البحث') => Search::term($request),
                __('النوع') => $request->query('type'),
                __('الباقة') => $request->query('plan'),
                __('الحالة') => $request->filled('status') ? __((string) $request->query('status')) : null,
            ],
            perBranch: null,
            columns: [
                __('الشركة') => Workbook::TEXT,
                __('النوع') => Workbook::TEXT,
                __('المالك') => Workbook::TEXT,
                __('الهاتف') => Workbook::CODE,
                __('البريد') => Workbook::TEXT,
                __('المدينة') => Workbook::TEXT,
                __('الباقة') => Workbook::TEXT,
                __('الحالة') => Workbook::TEXT,
                __('الفروع') => Workbook::INT,
                __('التسجيل') => Workbook::DATE,
            ],
            rows: function () use ($query) {
                foreach ($query->lazy(500) as $b) {
                    $r = Demo::businessRow($b);
                    yield [$r['name'], $r['type'], $r['owner'], $r['phone'], $b->owner_email ?? $r['email'], $r['city'],
                        $r['plan'], __((string) $r['status']), $r['branches'], $r['registered']];
                }
            },
            totals: [[__('عدد الشركات'), $count, Workbook::INT]],
        );
    }
}
