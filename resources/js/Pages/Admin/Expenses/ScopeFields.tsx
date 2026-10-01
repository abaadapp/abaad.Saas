import { Plus, X } from 'lucide-react';
import Field, { Select } from '@/Components/Field';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { money } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';

export type ExpenseScopeKind = 'business' | 'branch' | 'split';

export interface ScopeAllocation {
    branch_id: string;
    amount: string;
}

export interface ScopeValue {
    scope: ExpenseScopeKind;
    branch_id: string;
    allocations: ScopeAllocation[];
}

export interface BranchOption {
    id: number;
    name: string;
}

/** للنشاط كلِّه — المعنى القديم لكلّ مصروف، ولا يتبع فرعَ الجلسة */
export const EMPTY_SCOPE: ScopeValue = { scope: 'business', branch_id: '', allocations: [] };

/** المبلغُ أجزاءً من الألف — كما يقارنه الخادم (`ExpenseScope::read`) */
export const mils = (value: string | number): number => {
    const n = typeof value === 'number' ? value : parseFloat(value);
    return Number.isFinite(n) ? Math.round(n * 1000) : 0;
};

/**
 * نطاقُ المصروف — للنشاط كلِّه، أو لفرعٍ محدّد، أو موزَّعٌ على عدة فروع.
 *
 * والمتبقّي يُعرض ليصحّحه التاجرُ بيده: لا يُكمَّل من عندنا، والخادمُ يردّ
 * توزيعًا لا يساوي المبلغ. والحسابُ بأجزاء الألف لا بالكسر: ٠٫١ + ٠٫٢ في
 * المتصفّح ليست ٠٫٣.
 */
export default function ScopeFields({
    value,
    onChange,
    amount,
    branches,
    currency,
    errors = {},
}: {
    value: ScopeValue;
    onChange: (next: ScopeValue) => void;
    amount: string | number;
    branches: BranchOption[];
    currency: { code: string; symbol?: string; decimals?: number };
    errors?: Record<string, string | undefined>;
}) {
    const t = useTranslate();
    const m = (v: number) => money(v, currency as never);

    const total = mils(amount);
    const allocated = value.allocations.reduce((sum, a) => sum + mils(a.amount), 0);
    const remaining = total - allocated;
    const branchOptions = branches.map((b) => ({ label: b.name, value: String(b.id) }));

    const setScope = (scope: ExpenseScopeKind) =>
        onChange({
            scope,
            branch_id: scope === 'branch' ? value.branch_id : '',
            allocations: scope === 'split' ? (value.allocations.length ? value.allocations : [{ branch_id: '', amount: '' }]) : [],
        });

    const setRow = (i: number, patch: Partial<ScopeAllocation>) =>
        onChange({ ...value, allocations: value.allocations.map((a, j) => (j === i ? { ...a, ...patch } : a)) });

    const rowError = (i: number) => errors[`allocations.${i}.branch_id`] ?? errors[`allocations.${i}.amount`];

    return (
        <div className="space-y-3" data-testid="expense-scope">
            <Field label="نطاق المصروف" error={errors.scope}>
                <div role="radiogroup" aria-label={t('نطاق المصروف')} className="grid grid-cols-1 gap-2 sm:grid-cols-3">
                    {(
                        [
                            ['business', 'النشاط بالكامل'],
                            ['branch', 'فرع محدد'],
                            ['split', 'موزع على عدة فروع'],
                        ] as const
                    ).map(([key, label]) => (
                        <button
                            key={key}
                            type="button"
                            role="radio"
                            aria-checked={value.scope === key}
                            disabled={key !== 'business' && branches.length === 0}
                            onClick={() => setScope(key)}
                            className={cn(
                                'rounded-lg border px-3 py-2 text-[13px] transition-colors disabled:opacity-50',
                                value.scope === key
                                    ? 'border-[#111] bg-[#111] text-white'
                                    : 'border-[var(--ui-border,#e8e8e8)] bg-white text-[#111]',
                            )}
                        >
                            {t(label)}
                        </button>
                    ))}
                </div>
            </Field>

            {value.scope === 'branch' && (
                <Field label="الفرع" required error={errors.branch_id}>
                    <Select
                        value={value.branch_id}
                        onChange={(e) => onChange({ ...value, branch_id: e.target.value })}
                        options={branchOptions}
                        placeholder="اختر الفرع…"
                        aria-label={t('الفرع')}
                    />
                </Field>
            )}

            {value.scope === 'split' && (
                <div className="space-y-2 rounded-lg border border-[var(--ui-border,#e8e8e8)] p-3">
                    {value.allocations.map((a, i) => (
                        <div key={i} className="space-y-1" data-testid="allocation-row">
                            <div className="flex items-center gap-2">
                                <Select
                                    className="flex-1"
                                    value={a.branch_id}
                                    onChange={(e) => setRow(i, { branch_id: e.target.value })}
                                    options={branchOptions}
                                    placeholder="الفرع"
                                    aria-label={t('الفرع')}
                                />
                                <Input
                                    type="number"
                                    step="0.001"
                                    min="0"
                                    dir="ltr"
                                    className="w-32"
                                    value={a.amount}
                                    onChange={(e) => setRow(i, { amount: e.target.value })}
                                    placeholder="0.000"
                                    aria-label={t('المبلغ الموزع')}
                                />
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    aria-label={t('إزالة الفرع')}
                                    onClick={() => onChange({ ...value, allocations: value.allocations.filter((_, j) => j !== i) })}
                                >
                                    <X />
                                </Button>
                            </div>
                            {rowError(i) && <p className="text-[12px] text-[#b91c1c]">{rowError(i)}</p>}
                        </div>
                    ))}

                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        disabled={value.allocations.length >= branches.length}
                        onClick={() => onChange({ ...value, allocations: [...value.allocations, { branch_id: '', amount: '' }] })}
                    >
                        <Plus />
                        {t('إضافة فرع')}
                    </Button>

                    <dl className="grid grid-cols-3 gap-2 border-t border-[var(--ui-border,#e8e8e8)] pt-2 text-[12px]">
                        <div>
                            <dt className="text-[#6b7280]">{t('إجمالي المصروف')}</dt>
                            <dd className="tabular-nums text-[#111]">{m(total / 1000)}</dd>
                        </div>
                        <div>
                            <dt className="text-[#6b7280]">{t('المبلغ الموزع')}</dt>
                            <dd className="tabular-nums text-[#111]">{m(allocated / 1000)}</dd>
                        </div>
                        <div>
                            <dt className="text-[#6b7280]">{t('المتبقي')}</dt>
                            <dd data-testid="allocation-remaining" className={cn('tabular-nums', remaining === 0 ? 'text-[#047857]' : 'text-[#b91c1c]')}>
                                {m(remaining / 1000)}
                            </dd>
                        </div>
                    </dl>
                    {errors.allocations && <p className="text-[12px] text-[#b91c1c]">{errors.allocations}</p>}
                </div>
            )}
        </div>
    );
}

/** أيُحفظ؟ — الموزَّعُ لا يُرسَل ومتبقّيه غيرُ صفر */
export function scopeReady(value: ScopeValue, amount: string | number): boolean {
    if (value.scope === 'branch') return value.branch_id !== '';
    if (value.scope === 'split') {
        return (
            value.allocations.length > 0 &&
            value.allocations.every((a) => a.branch_id !== '' && mils(a.amount) > 0) &&
            value.allocations.reduce((s, a) => s + mils(a.amount), 0) === mils(amount)
        );
    }
    return true;
}
