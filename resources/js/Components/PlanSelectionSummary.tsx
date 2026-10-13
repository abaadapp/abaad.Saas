import { usePage } from '@inertiajs/react';
import { Check } from 'lucide-react';
import { money } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import type { Currency, PageProps } from '@/types';

/**
 * باقةٌ كما يقرؤها نموذجٌ يختارها — من `SubscriptionController::planOptions`.
 *
 * سعراها ونصوصُها وسقوفُها من صفّ الباقة نفسِه. ولا قائمةُ القدرات: المعاينةُ
 * تقول ما في هذه الباقة، لا ما في النظام.
 */
export interface PlanOption {
    label: string;
    value: number;
    monthly: number;
    yearly: number;
    /** ما تعرضه الباقة سطرًا سطرًا — كما كتبه مديرُ المنصّة في «تعديل الباقة» */
    features: string[];
    /** السقوف المفروضة — `null` بلا حدّ */
    max_branches: number | null;
    max_employees: number | null;
    max_products: number | null;
}

/**
 * «تفاصيل الباقة المختارة» — تحت اختيار الباقة، لا بديلًا عنه.
 *
 * ═══ ما في الباقة فقط ═══
 *
 * كان اختيارُ الباقة اسمًا في قائمةٍ منسدلة: المشغّلُ يختار «الأساسيّة» ولا يرى
 * ما تبيعه حتى يفتح صفحةَ الباقات. وهنا ما تقوله الباقةُ نفسُها — نصوصُها
 * وسعراها وسقوفُها — ولا شيءَ غيرُه: لا قدراتُ النظام كلُّها، ولا تفاصيلُ
 * الأساسيّة مكتوبةً هنا. يعدّل مديرُ المنصّة الباقةَ غدًا فتتبعه المعاينة.
 *
 * وتتبدّل باختيارٍ آخر ولا تتراكم: باقةٌ واحدةٌ تُعرض، هي المختارة.
 */
export default function PlanSelectionSummary({ plan }: { plan: PlanOption | null | undefined }) {
    const { currency } = usePage<PageProps<{ currency: Currency }>>().props;
    const t = useTranslate();

    if (!plan) {
        return null;
    }

    const cap = (n: number | null) => (n === null ? t('بلا حدّ') : String(n));

    return (
        <section
            data-testid="plan-summary"
            aria-label={t('تفاصيل الباقة المختارة')}
            className="mt-3 rounded-[10px] border border-[var(--ui-border,#e8e8e8)] bg-[#fafaf9] p-4"
        >
            <p className="text-[12px] font-medium text-[#6b7280]">{t('تفاصيل الباقة المختارة')}</p>
            <p className="mt-1 text-[15px] font-bold text-[#111]">{t(plan.label)}</p>

            <p className="mt-1 text-[13px] tabular-nums text-[#111]">
                {money(plan.monthly, currency)} <span className="text-[#6b7280]">/ {t('شهريًا')}</span>
            </p>
            <p className="text-[13px] tabular-nums text-[#111]">
                {money(plan.yearly, currency)} <span className="text-[#6b7280]">/ {t('سنويًا')}</span>
            </p>

            {plan.features.length > 0 && (
                <ul className="mt-3 space-y-1.5">
                    {plan.features.map((f) => (
                        <li key={f} className="flex items-center gap-2 text-[13px] text-[#4b4b4b]">
                            <Check className="size-3.5 shrink-0 text-[#047857]" />
                            {t(f)}
                        </li>
                    ))}
                </ul>
            )}

            {/*
                والسقوفُ المفروضة سطرٌ واحدٌ صغير: النصوصُ أعلاه يكتبها مديرُ
                المنصّة بيده، وهذا ما يفرضه النظامُ فعلًا (`PlanLimits`).
            */}
            <p className="mt-3 text-[11px] text-[#9ca3af]">
                {t('الحدود المطبّقة')}: {t('الفروع')} {cap(plan.max_branches)} · {t('الموظفون النشطون')}{' '}
                {cap(plan.max_employees)} · {t('المنتجات')} {cap(plan.max_products)}
            </p>
        </section>
    );
}
