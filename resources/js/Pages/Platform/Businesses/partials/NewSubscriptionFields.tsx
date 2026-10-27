import Field, { Select } from '@/Components/Field';
import { Input } from '@/Components/ui/input';
import { money } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import type { Currency } from '@/types';

type Cycle = 'monthly' | 'semiannual' | 'yearly';
type Prices = Record<Cycle, number>;

/** ما يرسله الخادم لشاشة الإنشاء — `NewMerchantSubscription::options` */
export interface NewSubscriptionOptions {
    policy: 'legacy' | 'addons_v1';
    trial_days: number;
    basic_plan_id: number | null;
    cycles: { value: Cycle; label: string }[];
    plans: {
        id: number;
        name: string;
        /** بسعرها في سياسة المتجر الجديد — وما لم يُعتمد فيها فارغ */
        prices: Prices | null;
        included: { id: number; name: string }[];
        eligible: { id: number; name: string; pricing: 'paid' | 'free' | 'quote'; counted: boolean; prices: Prices | null }[];
    }[];
}

export interface NewSubscriptionValues {
    subscription_mode: 'trial' | 'paid' | 'grant';
    cycle: Cycle | '';
    grant_until: string;
    grant_reason: string;
    addons: { addon_id: number; quantity: string; unit_price: string }[];
}

interface Props {
    options: NewSubscriptionOptions;
    planId: string;
    values: NewSubscriptionValues;
    set: <K extends keyof NewSubscriptionValues>(key: K, value: NewSubscriptionValues[K]) => void;
    errors: Partial<Record<string, string>>;
    currency: Currency;
}

const MODES = [
    { value: 'trial', label: 'تجربة مجانية', hint: 'الأساسية — بلا فاتورة' },
    { value: 'paid', label: 'اشتراك مدفوع', hint: 'يُفتح بتسجيل السداد' },
    { value: 'grant', label: 'منحة إدارية', hint: 'بتاريخ وسبب' },
] as const;

/**
 * اشتراكُ التاجر الجديد — يختار مديرُ المنصّة طريقةً صريحة، ويرى ما سيُفوتَر قبل التأكيد.
 *
 * والخادمُ يحكم بها كلِّها (`NewMerchantSubscription`): السياسةُ سياسةُ المتجر يومَ يُنشأ،
 * والسعرُ سعرُ الباقة فيها، والإضافاتُ ما يُباع في الباقة. والملخّصُ هنا قراءةٌ لما سيكتبه.
 */
export default function NewSubscriptionFields({ options, planId, values, set, errors, currency }: Props) {
    const t = useTranslate();
    const mode = values.subscription_mode;
    const plan = options.plans.find((p) => String(p.id) === (mode === 'trial' ? String(options.basic_plan_id ?? planId) : planId));
    const cycle = values.cycle || null;
    const addonsOpen = options.policy === 'addons_v1' && mode !== 'trial';

    const picked = (id: number) => values.addons.find((a) => a.addon_id === id);
    const toggle = (id: number) =>
        set('addons', picked(id) ? values.addons.filter((a) => a.addon_id !== id) : [...values.addons, { addon_id: id, quantity: '1', unit_price: '' }]);
    const patch = (id: number, key: 'quantity' | 'unit_price', value: string) =>
        set('addons', values.addons.map((a) => (a.addon_id === id ? { ...a, [key]: value } : a)));

    // الإضافاتُ المختارةُ من باقةٍ أخرى لا تُرسل — تُسقط حين تتغيّر الباقة
    const lines = (plan?.eligible ?? [])
        .filter((e) => picked(e.id))
        .map((e) => {
            const row = picked(e.id)!;
            const unit = row.unit_price !== '' ? Number(row.unit_price) : e.pricing === 'free' ? 0 : cycle && e.prices ? e.prices[cycle] : null;
            const qty = e.counted ? Math.max(1, Number(row.quantity) || 1) : 1;

            return { id: e.id, name: e.name, amount: unit === null ? null : Math.round(unit * qty * 1000) / 1000 };
        });
    const planAmount = cycle && plan?.prices ? plan.prices[cycle] : null;
    const total = [planAmount, ...lines.map((l) => l.amount)].every((v) => v !== null)
        ? Math.round(([planAmount ?? 0, ...lines.map((l) => l.amount ?? 0)].reduce((s, v) => s + v, 0)) * 1000) / 1000
        : null;

    return (
        <div className="space-y-5" data-testid="new-subscription">
            <Field label="طريقة الاشتراك" required error={errors.subscription_mode}>
                <div className="grid grid-cols-1 gap-2 sm:grid-cols-3" role="radiogroup">
                    {MODES.map((m) => (
                        <label
                            key={m.value}
                            className={`flex cursor-pointer flex-col rounded-[10px] border p-3 text-[13px] ${mode === m.value ? 'border-[#111] bg-[#fafaf9]' : 'border-[var(--ui-border,#e8e8e8)]'}`}
                        >
                            <span className="flex items-center gap-2 font-semibold text-[#111]">
                                <input
                                    type="radio"
                                    name="subscription_mode"
                                    value={m.value}
                                    checked={mode === m.value}
                                    onChange={() => set('subscription_mode', m.value)}
                                    data-testid={`mode-${m.value}`}
                                />
                                {t(m.label)}
                            </span>
                            <span className="mt-1 text-[12px] text-[#6b7280]">{t(m.hint)}</span>
                        </label>
                    ))}
                </div>
            </Field>

            <p className="text-[12px] text-[#6b7280]" data-testid="new-subscription-policy">
                {options.policy === 'addons_v1'
                    ? t('سياسة الحساب الجديد: الإضافات — الأسعار والإضافات أدناه بسعرها فيها.')
                    : t('سياسة الحساب الجديد: السابقة — مزايا الباقة كلّها مشمولة بسعرها الحالي، ولا إضافات تُطلب.')}
            </p>

            {mode !== 'trial' && (
                <Field label="مدة الاشتراك" required={mode === 'paid'} hint={mode === 'grant' ? t('اختياريّ: تصدر فاتورة تُسدَّد، وتبدأ دورتها بعد المنحة') : undefined} error={errors.cycle}>
                    <Select
                        value={values.cycle}
                        placeholder={mode === 'grant' ? 'بلا فاتورة' : 'اختر المدة…'}
                        onChange={(e) => set('cycle', e.target.value as Cycle | '')}
                        options={options.cycles.map((c) => ({
                            value: c.value,
                            label: plan?.prices ? `${t(c.label)} · ${money(plan.prices[c.value], currency)}` : t(c.label),
                        }))}
                    />
                </Field>
            )}

            {mode === 'grant' && (
                <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                    <Field label="المنحة حتى" required error={errors.grant_until}>
                        <Input type="date" dir="ltr" value={values.grant_until} onChange={(e) => set('grant_until', e.target.value)} data-testid="grant-until" />
                    </Field>
                    <Field label="سبب المنحة" required error={errors.grant_reason}>
                        <Input value={values.grant_reason} onChange={(e) => set('grant_reason', e.target.value)} data-testid="grant-reason" />
                    </Field>
                </div>
            )}

            {addonsOpen && plan && (
                <div className="rounded-[10px] border border-[var(--ui-border,#e8e8e8)] p-4" data-testid="new-subscription-addons">
                    {plan.included.length > 0 && (
                        <p className="text-[12px] text-[#4b4b4b]">
                            <span className="font-semibold">{t('مشمولة في الباقة')}:</span> {plan.included.map((i) => i.name).join('، ')}
                        </p>
                    )}
                    <p className="mt-2 text-[13px] font-semibold text-[#111]">{t('إضافات مدفوعة تُفعَّل للعميل')}</p>
                    {plan.eligible.length === 0 ? (
                        <p className="mt-1 text-[12px] text-[#9ca3af]">{t('لا إضافات تُشترى في هذه الباقة.')}</p>
                    ) : (
                        <ul className="mt-2 space-y-2">
                            {plan.eligible.map((e) => {
                                const row = picked(e.id);

                                return (
                                    <li key={e.id} className="flex flex-wrap items-center gap-3 text-[13px]" data-testid={`new-addon-${e.id}`}>
                                        <label className="flex min-w-0 flex-1 items-center gap-2">
                                            <input type="checkbox" checked={!!row} onChange={() => toggle(e.id)} />
                                            <span className="truncate">{e.name}</span>
                                            <span className="text-[12px] text-[#6b7280] tabular-nums">
                                                {e.pricing === 'quote' ? t('حسب الطلب') : e.pricing === 'free' ? t('مجانية') : cycle && e.prices ? money(e.prices[cycle], currency) : ''}
                                            </span>
                                        </label>
                                        {row && e.counted && (
                                            <Input className="w-20" type="number" min="1" max="50" dir="ltr" aria-label={t('العدد')} value={row.quantity} onChange={(ev) => patch(e.id, 'quantity', ev.target.value)} />
                                        )}
                                        {row && (
                                            <Input
                                                className="w-28"
                                                type="number"
                                                step="0.001"
                                                min="0"
                                                dir="ltr"
                                                aria-label={t('سعر الوحدة')}
                                                placeholder={e.pricing === 'quote' ? t('السعر المتّفق') : t('سعر الكتالوج')}
                                                value={row.unit_price}
                                                onChange={(ev) => patch(e.id, 'unit_price', ev.target.value)}
                                            />
                                        )}
                                    </li>
                                );
                            })}
                        </ul>
                    )}
                    {errors.addons && <p className="mt-2 text-[12px] text-[#b91c1c]">{errors.addons}</p>}
                </div>
            )}

            {/* ما سيُكتب عند التأكيد — يُقرأ قبل الضغط */}
            <div className="rounded-[10px] bg-[#fafaf9] p-4 text-[13px] tabular-nums" data-testid="new-subscription-summary">
                <p className="font-semibold text-[#111]">{t('ملخص الاشتراك')}</p>
                {mode === 'trial' ? (
                    <p className="mt-1 text-[#4b4b4b]">{t('تجربة الأساسية :d يومًا — بلا فاتورة ولا دفع.', { d: options.trial_days })}</p>
                ) : (
                    <>
                        {plan && cycle && (
                            <p className="mt-1">
                                {t('الباقة')}: {plan.name} · {t(options.cycles.find((c) => c.value === cycle)?.label ?? '')} ·{' '}
                                {planAmount === null ? t('سعرها في السياسة لم يُعتمد') : money(planAmount, currency)}
                            </p>
                        )}
                        {lines.map((l) => (
                            <p key={l.id}>
                                {l.name} · {l.amount === null ? t('يحتاج سعرًا') : money(l.amount, currency)}
                            </p>
                        ))}
                        {cycle && total !== null && (
                            <p className="mt-1 font-semibold text-[#111]" data-testid="new-subscription-total">
                                {t('الإجمالي')}: {money(total, currency)} · {t(':n فاتورة', { n: 1 + lines.length })}
                            </p>
                        )}
                        <p className="mt-1 text-[#6b7280]">
                            {mode === 'paid'
                                ? t('الحساب «بانتظار السداد»: يُفتح حين يُسجَّل سداد فاتورة الباقة، والإضافات بسداد فواتيرها.')
                                : t('يُفتح الحساب الآن بلا سداد حتى :d — منحةٌ مكتوبة بسببها في السجلّ.', { d: values.grant_until || '—' })}
                        </p>
                    </>
                )}
            </div>
        </div>
    );
}
