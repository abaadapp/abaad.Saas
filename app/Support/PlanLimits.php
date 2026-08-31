<?php

namespace App\Support;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Product;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * حدود الباقة — تُفرَض عند الإنشاء لا تُعرَض في شاشة.
 *
 * كانت `max_branches` و`max_employees` و`max_products` أعمدةً لا يقرؤها شيء
 * خارج البذور ونموذج التعديل: تُباع «الباقة الأساسية: فرعٌ واحد» ولا شيء
 * يمنع فتح عشرة. والحدّ الذي لا يُفرَض ليس حدًّا، وإنما وعدٌ يكسره أول من
 * ينتبه إليه — ويُكافأ عليه.
 *
 * والمنع عند الإنشاء لا عند الاستعمال: من فتح فرعه العاشر ثم مُنع من البيع
 * فيه يخسر عمله، ومن مُنع من فتحه أصلًا يُرقّي باقته.
 */
class PlanLimits
{
    /** المفتاح في جدول الباقات → عادّه، وتسميته كما تُقال للتاجر */
    private const LIMITS = [
        'branches' => ['column' => 'max_branches', 'label' => 'الفروع'],
        'employees' => ['column' => 'max_employees', 'label' => 'الموظفين'],
        'products' => ['column' => 'max_products', 'label' => 'المنتجات'],
    ];

    /** العدد المستعمل الآن من هذا المورد */
    public static function used(int $businessId, string $key): int
    {
        return match ($key) {
            'branches' => Branch::where('business_id', $businessId)->count(),
            'employees' => User::where('business_id', $businessId)->count(),
            'products' => Product::where('business_id', $businessId)->count(),
            default => 0,
        };
    }

    /**
     * سقف الباقة لهذا المورد — null إن لا باقة أو لا سقف.
     *
     * بلا باقة لا سقف: متجرٌ أُنشئ قبل الباقات لا يُقفل لأن حقلًا فيه فارغ.
     */
    public static function cap(?Business $business, string $key): ?int
    {
        $column = self::LIMITS[$key]['column'] ?? null;
        $cap = $column ? $business?->plan?->{$column} : null;

        return $cap ? (int) $cap : null;
    }

    /** هل بلغ المتجر سقفه؟ */
    public static function reached(?Business $business, string $key): bool
    {
        $cap = self::cap($business, $key);

        return $cap !== null && self::used($business->id, $key) >= $cap;
    }

    /**
     * يرمي خطأ تحقّقٍ إن بلغ السقف.
     *
     * خطأُ تحقّقٍ لا 403: الرسالة تظهر فوق النموذج الذي يملأه التاجر، وتقول
     * الباقة والسقف — «403» وحدها لا تخبره بما يفعل.
     */
    public static function enforce(?Business $business, string $key, string $field = 'name'): void
    {
        if (! self::reached($business, $key)) {
            return;
        }

        throw ValidationException::withMessages([
            $field => __('بلغت حدّ باقة «:plan»: :cap من :label. رقِّ الباقة لإضافة المزيد.', [
                'plan' => $business->plan->name,
                'cap' => self::cap($business, $key),
                'label' => __(self::LIMITS[$key]['label']),
            ]),
        ]);
    }

    /**
     * ما يتجاوز به المتجرُ باقةً بعينها — قائمةٌ تُقرأ، وفارغةٌ إن وسِعته.
     *
     * تُسأل قبل تنزيل الباقة: `reached` تحرس الإنشاء وتقيس السقف الحاليّ،
     * وهذه تقيس سقفًا مقترَحًا على استهلاكٍ قائم.
     *
     * @return list<string>
     */
    public static function exceededBy(Business $business, \App\Models\Plan $plan): array
    {
        $over = [];

        foreach (self::LIMITS as $key => $meta) {
            $cap = $plan->{$meta['column']};
            if (! $cap) {
                continue;
            }

            $used = self::used($business->id, $key);
            if ($used > (int) $cap) {
                $over[] = __(':label :used من :cap', [
                    'label' => __($meta['label']), 'used' => $used, 'cap' => (int) $cap,
                ]);
            }
        }

        return $over;
    }

    /** الاستهلاك مقابل السقف — تعرضه لوحة المنصة في صفحة الشركة */
    public static function usage(Business $business): array
    {
        return collect(self::LIMITS)->map(fn ($meta, $key) => [
            'key' => $key,
            'label' => __($meta['label']),
            'used' => self::used($business->id, $key),
            'cap' => self::cap($business, $key),
        ])->values()->all();
    }
}
