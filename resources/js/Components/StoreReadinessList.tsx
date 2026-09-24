import { cn } from '@/lib/utils';
import { useTranslate } from '@/lib/i18n';

export interface ReadinessStep {
    key: string;
    label: string;
    why: string;
    done: boolean;
    required: boolean;
    section: string;
}

/**
 * دليلُ تجهيز المتجر — ما تمّ وما بقي.
 *
 * ═══ واللازمُ يُفصل عن المستحسن ═══
 *
 * «بلا عنوانٍ لا يُفتح متجرك» ليست كـ«بلا نبذةٍ يبدو أقلَّ ثقة». وقائمةٌ
 * حمراءُ واحدةٌ تخلطهما تجعل صاحبَها يقرأ الكلَّ تحذيرًا — فلا يقرأ شيئًا،
 * ويبقى العنوانُ الناقص بين عشر ملاحظاتٍ تجميليّة.
 *
 * ═══ والسببُ يُقال لما لم يتمّ وحدَه ═══
 *
 * «صورة الواجهة ✓» لا تحتاج شرحًا. و«صورة الواجهة ·» تحتاجه: من لا يعرف
 * ماذا يخسر لا يفعل شيئًا. وشرحُ التامّ يُطيل القائمةَ فلا تُقرأ.
 */
export default function StoreReadinessList({ steps }: { steps: ReadinessStep[] }) {
    const t = useTranslate();

    const need = steps.filter((s) => s.required);
    const nice = steps.filter((s) => ! s.required);
    const left = need.filter((s) => ! s.done);

    const Row = ({ s }: { s: ReadinessStep }) => (
        <li className="flex items-start gap-2.5 py-2">
            <span
                aria-hidden
                className={cn(
                    'mt-0.5 flex size-[18px] shrink-0 items-center justify-center rounded-full text-[11px] font-bold',
                    s.done ? 'bg-[#dcfce7] text-[#15803d]' : s.required ? 'bg-[#fee2e2] text-[#b91c1c]' : 'bg-[#f3f4f6] text-[#9ca3af]',
                )}
            >
                {s.done ? '✓' : '·'}
            </span>
            <span className="min-w-0">
                <span className={cn('text-[13px]', s.done ? 'text-[#6b7280]' : 'font-medium text-[#111]')}>{t(s.label)}</span>
                {! s.done && <span className="mt-0.5 block text-[12px] leading-relaxed text-[#6b7280]">{t(s.why)}</span>}
            </span>
        </li>
    );

    return (
        <>
            <p
                data-testid="readiness-summary"
                className={cn(
                    'mb-3 rounded-[10px] px-3 py-2 text-[12px] leading-relaxed',
                    left.length === 0 ? 'bg-[#f0fdf4] text-[#15803d]' : 'bg-[#fffbeb] text-[#b45309]',
                )}
            >
                {left.length === 0
                    ? t('متجرك جاهز — وما تحت «يُحسّنه» زيادةٌ لا شرط.')
                    : t(':n خطوةً لازمةً لم تتمّ — وقبلها لا يعمل متجرك كما ينبغي.', { n: left.length })}
            </p>

            <ul className="divide-y divide-[var(--ui-border,#e8e8e8)]">
                {need.map((s) => (
                    <Row key={s.key} s={s} />
                ))}
            </ul>

            {nice.length > 0 && (
                <>
                    <p className="mt-4 mb-1 text-[12px] font-medium text-[#6b7280]">{t('ويُحسّنه')}</p>
                    <ul className="divide-y divide-[var(--ui-border,#e8e8e8)]">
                        {nice.map((s) => (
                            <Row key={s.key} s={s} />
                        ))}
                    </ul>
                </>
            )}
        </>
    );
}
