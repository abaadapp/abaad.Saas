<?php

namespace App\Support\Lists;

use App\Models\Expense;
use App\Support\Demo;
use App\Support\ExpenseScope;
use App\Support\Exports\Dataset;
use App\Support\Exports\Workbook;
use App\Support\ListFilters;
use App\Support\Search;
use App\Support\Sort;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * المصروفاتُ المسجّلة — استعلامٌ واحد تقرؤه الشاشةُ وملفّاتُها الثلاثة.
 *
 * كانت الشاشةُ تنسخ المرشِّحاتِ في سطورها بدل `ListFilters::expenses`،
 * وكلُّ ملفٍّ يرتّب على هواه: Excel صاعدًا، وPDF نازلًا، والشاشةُ بما
 * اختار التاجر. فصار الترتيبُ والمرشِّحاتُ هنا، مرّةً واحدة.
 */
final class ExpensesList
{
    /**
     * ما يُرتَّب في قائمة المصروفات.
     *
     * والنوع نصٌّ في الصف نفسه فيُرتَّب، والمرفق لا — وجودُه من عدمه ليس ترتيبًا.
     */
    public const SORTS = [
        'reference' => 'reference',
        'due_date' => 'due_date',
        'type' => 'type',
        'amount' => 'amount',
        'status' => 'status',
    ];

    /** المرشِّحاتُ بلا ترتيب: الشهرُ والبحثُ والنوعُ والحالة */
    public static function filtered(Request $request): Builder
    {
        return ListFilters::expenses(Expense::where('business_id', self::bid()), $request);
    }

    /** وبترتيب الشاشة: الأحدثُ صرفًا ما لم يُختر غيره */
    public static function query(Request $request): Builder
    {
        $q = self::filtered($request);
        Sort::apply($q, $request, self::SORTS, fn ($w) => $w->orderByDesc('spent_at'));
        $q->orderByDesc('id');

        return $q;
    }

    /** الشهرُ المعروض — `Y-m` لشهرٍ واحد، أو `null` لغيره (`ListFilters::expensePeriod`) */
    public static function month(Request $request): ?string
    {
        $period = ListFilters::expensePeriod($request);

        return in_array($period->kind, ['month', 'previous_month'], true) ? $period->start->format('Y-m') : null;
    }

    /** صفوفُ الشاشة كلُّها بترتيبها — لورقة PDF */
    public static function rows(Request $request): array
    {
        return self::query($request)->with('branch:id,name')->get()->map(fn (Expense $e) => [
            'date' => optional($e->spent_at)->format('Y-m-d') ?? '—',
            'reference' => $e->reference,
            'type' => $e->type,
            'description' => $e->description,
            'amount' => (float) $e->amount,
            'status' => $e->status,
            'method' => $e->method,
            'employee' => $e->employee_name,
        ])->all();
    }

    /**
     * ما يُصدَّر: صفوفُ الشاشة كلُّها، والمدفوعُ والمستحقُّ والعددُ عليها كلِّها.
     *
     * والأعمدةُ أعمدةُ الجدول بترتيبه — المرجعُ والاستحقاقُ والنوعُ والنطاقُ
     * والمبلغُ والحالةُ والملاحظات — ومعها تاريخُ الصرف والوسيلةُ والموظّف.
     */
    public static function export(Request $request): Dataset
    {
        $period = ListFilters::expensePeriod($request);
        $filtered = self::filtered($request);
        $paid = round((float) (clone $filtered)->paid()->sum('amount'), 3);
        $unpaid = round((float) (clone $filtered)->unpaid()->sum('amount'), 3);
        $count = (clone $filtered)->count();

        $totals = [[Workbook::money('إجمالي المصروفات المسجلة والمدفوعة'), $paid]];
        if ($unpaid > 0) {
            $totals[] = [Workbook::money('إجمالي غير المدفوع'), $unpaid];
        }
        $totals[] = [__('عدد السجلات'), $count, Workbook::INT];

        return new Dataset(
            title: __('المصروفات المسجلة'),
            file: 'expenses',
            // `expenses-2026-09` كما كان، و`expenses-2024`، و`expenses-all`
            fileParts: $period->fileParts(),
            filters: [
                __('الفترة') => $period->label(),
                __('البحث') => Search::term($request),
                __('النوع') => $request->query('type'),
                __('الحالة') => $request->filled('status') ? __((string) $request->query('status')) : null,
            ],
            perBranch: null,
            columns: [
                __('المرجع') => Workbook::CODE,
                __('تاريخ الصرف') => Workbook::DATE,
                __('تاريخ الإستحقاق') => Workbook::DATE,
                __('النوع') => Workbook::TEXT,
                __('نطاق المصروف') => Workbook::TEXT,
                Workbook::money('المبلغ') => Workbook::MONEY,
                __('الحالة') => Workbook::TEXT,
                __('الطريقة') => Workbook::TEXT,
                __('الموظف') => Workbook::TEXT,
                __('ملاحظات') => Workbook::TEXT,
            ],
            rows: function () use ($request) {
                foreach (self::query($request)->with(['branch:id,name', 'allocations'])->lazy(1000) as $e) {
                    yield [
                        $e->reference, $e->spent_at, $e->due_date, $e->type, self::scopeLabel($e),
                        (float) $e->amount, __((string) $e->status), $e->method, $e->employee_name, $e->description,
                    ];
                }
            },
            totals: $totals,
            totalsColumn: 5,
        );
    }

    private static function scopeLabel(Expense $e): string
    {
        return match (ExpenseScope::of($e)) {
            ExpenseScope::BRANCH => (string) $e->branch?->name,
            ExpenseScope::SPLIT => __('موزع على عدة فروع'),
            default => __('النشاط بالكامل'),
        };
    }

    private static function bid(): int
    {
        return auth()->user()->business_id ?? Demo::bid();
    }
}
