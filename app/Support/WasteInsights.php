<?php

namespace App\Support;

/**
 * ملاحظاتٌ على الهالك — قواعدُ لا نموذجُ لغة.
 *
 * «ذكيّ» هنا يعني أنّ الرقم يُقرأ نيابةً عن صاحب المحلّ، لا أنّ آلةً تخمّن.
 * كلّ ملاحظةٍ هنا حسابٌ محدّد يمكن لأيّ إنسانٍ إعادته بيده والوصول إلى
 * النتيجة نفسها — وهذا شرطُ أن تُصدَّق.
 *
 * والقاعدة الأهمّ في هذا الملفّ هي ما لا يُقال: لا ملاحظة على بياناتٍ قليلة.
 * «هلك مئة بالمئة من التوليب» جملةٌ صحيحة حسابيًّا حين بيعت قطعةٌ وهلكت
 * قطعة، وكاذبةٌ عمليًّا — ومن يقرأ ثلاث جملٍ كهذه يتوقّف عن قراءة الشاشة
 * كلّها. فالحدود الدنيا أدناه ليست تحفّظًا، بل شرطُ بقاء الشاشة مقروءة.
 */
class WasteInsights
{
    /** أقلّ قيمةٍ تستحقّ أن يُنبَّه عليها — ما دونها ضجيج */
    public const MIN_VALUE = 5.0;

    /** أقلّ عددِ صفوفٍ قبل الحديث عن «تكرار» */
    public const MIN_ROWS = 3;

    /** أقلّ نسبة ارتفاعٍ تُذكر */
    public const MIN_GROWTH = 25.0;

    /** أقلّ حصّةٍ من الإجمالي تجعل الفرع أو الصنف جديرًا بالذكر */
    public const MIN_SHARE = 40.0;

    /**
     * @return array<int, array{text: string, tone: string}>
     */
    public static function all(int $businessId, array $filters): array
    {
        $out = [];
        $now = Waste::totals($businessId, $filters);

        // لا شيء يُقال عن لا شيء
        if ($now['value'] < self::MIN_VALUE) {
            return $out;
        }

        foreach ([
            self::growth($businessId, $filters, $now),
            self::topProduct($businessId, $filters, $now),
            self::topBranch($businessId, $filters, $now),
            self::highRate($businessId, $filters),
            self::repeated($businessId, $filters),
        ] as $insight) {
            if ($insight) {
                $out[] = $insight;
            }
        }

        return $out;
    }

    /** ارتفاعُ الهالك عن المدّة السابقة بطولها نفسه */
    private static function growth(int $businessId, array $filters, array $now): ?array
    {
        if (empty($filters['from']) || empty($filters['to'])) {
            return null;
        }

        $window = Waste::previousWindow($filters['from'], $filters['to']);
        $before = Waste::totals($businessId, array_merge($filters, $window));

        // بلا مدّةٍ سابقةٍ فيها شيء لا نسبة: القسمة على صفرٍ تُخرج «∞٪»
        if ($before['value'] < self::MIN_VALUE) {
            return null;
        }

        $change = ($now['value'] - $before['value']) / $before['value'] * 100;

        if (abs($change) < self::MIN_GROWTH) {
            return null;
        }

        return [
            'text' => __(':dir الهالك :pct% عن المدّة السابقة — :now مقابل :before ر.ع.', [
                'dir' => $change > 0 ? __('ارتفع') : __('انخفض'),
                'pct' => number_format(abs($change), 0),
                'now' => number_format($now['value'], 3),
                'before' => number_format($before['value'], 3),
            ]),
            'tone' => $change > 0 ? 'warning' : 'good',
        ];
    }

    /** صنفٌ يبتلع حصّةً كبيرة من الخسارة */
    private static function topProduct(int $businessId, array $filters, array $now): ?array
    {
        $rows = Waste::groupedBy($businessId, 'product', $filters, 1);

        if (! $rows || $now['value'] <= 0) {
            return null;
        }

        $share = $rows[0]['value'] / $now['value'] * 100;

        if ($share < self::MIN_SHARE || $rows[0]['value'] < self::MIN_VALUE) {
            return null;
        }

        return [
            'text' => __(':name وحده :pct% من قيمة الهالك — :value ر.ع.', [
                'name' => $rows[0]['label'],
                'pct' => number_format($share, 0),
                'value' => number_format($rows[0]['value'], 3),
            ]),
            'tone' => 'warning',
        ];
    }

    /** فرعٌ هالكه أعلى بكثير من بقيّة الفروع */
    private static function topBranch(int $businessId, array $filters, array $now): ?array
    {
        // مقارنة الفروع لا معنى لها حين تُقرأ الشاشة على فرعٍ واحد
        if (! empty($filters['branch_id'])) {
            return null;
        }

        $rows = Waste::groupedBy($businessId, 'branch', $filters, 5);

        // فرعٌ واحد ليس أعلى من «بقيّة الفروع» — لا بقيّة هناك
        if (count($rows) < 2 || $now['value'] <= 0) {
            return null;
        }

        $share = $rows[0]['value'] / $now['value'] * 100;

        if ($share < self::MIN_SHARE) {
            return null;
        }

        return [
            'text' => __(':name يمثّل :pct% من إجمالي قيمة الهالك.', [
                'name' => $rows[0]['label'],
                'pct' => number_format($share, 0),
            ]),
            'tone' => 'warning',
        ];
    }

    /** صنفٌ نسبةُ هالكه مرتفعة مقارنةً بما استُهلك منه فعلًا */
    private static function highRate(int $businessId, array $filters): ?array
    {
        $rows = Waste::versusConsumption($businessId, $filters);

        if (! $rows || $rows[0]['rate'] < 10) {
            return null;
        }

        return [
            'text' => __('هالك :name مرتفع مقارنةً باستهلاكه: :waste من :consumed (:pct%).', [
                'name' => $rows[0]['label'],
                'waste' => number_format($rows[0]['waste'], 0),
                'consumed' => number_format($rows[0]['consumed'], 0),
                'pct' => number_format($rows[0]['rate'], 1),
            ]),
            'tone' => 'warning',
        ];
    }

    /** صنفٌ يتكرّر هالكه لا يهلك مرّةً واحدة بحادث */
    private static function repeated(int $businessId, array $filters): ?array
    {
        $rows = Waste::query($businessId, $filters)
            ->selectRaw('product_id, count(*) as n')
            ->groupBy('product_id')
            ->orderByDesc('n')
            ->limit(1)->get();

        if ($rows->isEmpty() || (int) $rows[0]->n < self::MIN_ROWS) {
            return null;
        }

        $name = \App\Models\Product::withTrashed()->find($rows[0]->product_id)?->name;

        if (! $name) {
            return null;
        }

        return [
            'text' => __(':name سُجّل هالكه :n مرّات في هذه المدّة — تكرارٌ لا حادثة.', [
                'name' => $name, 'n' => (int) $rows[0]->n,
            ]),
            'tone' => 'info',
        ];
    }
}
