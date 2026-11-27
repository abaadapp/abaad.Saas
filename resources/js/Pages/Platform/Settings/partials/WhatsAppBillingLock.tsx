import { useState } from 'react';
import { router, useForm } from '@inertiajs/react';
import { Lock, LockOpen } from 'lucide-react';
import { useConfirm } from '@/Components/ConfirmDialog';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { useTranslate } from '@/lib/i18n';

/** رسالةٌ خرجت في التجربة كما صنّفتها Meta — بلا رقم العميل ولا نصّ ولا مبلغ */
export interface TrialMessage {
    id: number;
    tail: string;
    at: string | null;
    kind: string;
    category: string;
    status: string | null;
    delivered: boolean;
    charged: boolean | null;
    why_free: string | null;
}

/** قفلُ الفوترة: أمفتوحٌ النقل، والتجربةُ وما خرج فيها — انظر `App\Support\WhatsAppBillingTrials::state` */
export interface BillingLockState {
    launched: boolean;
    passed: { business: string; at: string | null; by: string | null; kind: string; reference: string | null; amount: string | null } | null;
    trial: {
        id: number;
        business_id: number;
        business: string;
        waba_id: string;
        open: boolean;
        used: number;
        limit: number;
        until: string | null;
        opened_by: string;
        may_pass: boolean;
        /** أرقامُ الاختبار — وحدها تصلها رسائلُ التجربة (`WhatsAppBillingTrials::recipient`) */
        phones: string[];
        messages: TrialMessage[];
    } | null;
    kinds: { key: string; label: string }[];
    defaults: { messages: number; hours: number; max_messages: number; max_hours: number; max_phones: number };
}

/**
 * قفلُ نقل التجّار — لا يُفتح إلّا بتجربةٍ حقيقيّة رُئي فيها رسمُ رسالةٍ على حساب التاجر ولم يُرَ على حساب أبعاد.
 *
 * والقفلُ على الخادم: هذه الشاشةُ تعرضه وتسجّل نتيجته. ولا يُرفع ملفٌّ ولا يُكتب رقم بطاقة.
 */
export default function WhatsAppBillingLock({ state, pilot }: { state: BillingLockState; pilot: { id: number; name: string }[] }) {
    const t = useTranslate();
    const [confirm, dialog] = useConfirm();
    const trial = state.trial;
    const [reason, setReason] = useState('');
    const [error, setError] = useState<string | null>(null);

    const open = useForm({ business_id: String(pilot[0]?.id ?? ''), messages: String(state.defaults.messages), hours: String(state.defaults.hours), phones: '', charges_accepted: false });
    const pass = useForm({ kind: '', price_id: '', waba_id: '', reference: '', abaad_reference: '', amount: '', currency: '', merchant_checked: false, abaad_checked: false });
    const first = (e: Record<string, string | undefined>) => Object.values(e).find(Boolean) ?? null;

    const chargeable = (trial?.messages ?? []).filter((m) => m.delivered && m.charged === true);
    const passReady = pass.data.kind !== '' && pass.data.price_id !== '' && pass.data.waba_id.trim() !== '' && pass.data.reference.trim().length >= 3
        && pass.data.abaad_reference.trim().length >= 3 && pass.data.merchant_checked && pass.data.abaad_checked;

    const close = async () => {
        if (!trial || !(await confirm({ title: t('إغلاق التجربة'), message: t('يتوقف إرسال المتجر الاختباري من رقمه. يبقى تسجيل النتيجة متاحًا.'), action: t('أغلقها') }))) return;
        router.delete(route('super-admin.whatsapp.billing-trial.close', trial.id), { preserveScroll: true });
    };

    const relock = () =>
        router.post(route('super-admin.whatsapp.billing-lock.relock'), { reason }, {
            preserveScroll: true,
            onSuccess: () => { setError(null); setReason(''); },
            onError: (e) => setError(first(e as Record<string, string>)),
        });

    return (
        <section className="mt-4 rounded-[12px] border border-[var(--ui-border,#e8e8e8)] px-4 py-3 text-[13px]" data-testid="wa-lock" data-launched={state.launched ? '1' : '0'}>
            <div className="flex flex-wrap items-center gap-2">
                {state.launched ? <LockOpen className="size-4 text-[#047857]" /> : <Lock className="size-4 text-[#b91c1c]" />}
                <p className="font-semibold text-[#111]">{t('قفل الفوترة')}</p>
                <Badge variant={state.launched ? 'success' : 'danger'}>{state.launched ? t('ثبتت الفوترة المباشرة') : t('النقل مقفل')}</Badge>
            </div>
            <p className="mt-1 text-[12px] leading-relaxed text-[#6b7280]">
                {t('لا يُنقل التجّار الجدد إلى تطبيق أبعاد قبل أن يظهر رسم رسالة حقيقية على حساب التاجر في Meta ولا يظهر على حساب أبعاد. ولكل متجر بعدها مراجعته.')}
            </p>

            {state.passed && (
                <>
                    <p className="mt-2 text-[12px] text-[#047857]" data-testid="wa-lock-passed">
                        {t('نجحت التجربة')}: {state.passed.business} · {state.passed.kind} — {state.passed.reference}
                        {state.passed.amount && <> · <bdi dir="ltr">{state.passed.amount}</bdi> {t('(كما كُتب من Meta)')}</>}
                        {' · '}{state.passed.by} · <span dir="ltr">{state.passed.at}</span>
                    </p>
                    <form className="mt-3 flex flex-wrap items-end gap-2" onSubmit={(e) => { e.preventDefault(); relock(); }}>
                        <label className="flex flex-col gap-1 text-[12px] text-[#6b7280]">
                            {t('سبب إعادة القفل')}
                            <Input value={reason} onChange={(e) => setReason(e.target.value)} className="w-64" data-testid="wa-lock-reason" />
                        </label>
                        <Button type="submit" variant="outline" size="sm" disabled={reason.trim().length < 3} data-testid="wa-lock-relock">{t('أعد القفل')}</Button>
                    </form>
                </>
            )}

            {!state.launched && (!trial || !trial.open) && (
                <form
                    className="mt-3 space-y-2 border-t border-[#f0f0f0] pt-3"
                    data-testid="wa-trial-open"
                    onSubmit={(e) => {
                        e.preventDefault();
                        open.post(route('super-admin.whatsapp.billing-trial.open'), { preserveScroll: true });
                    }}
                >
                    <p className="font-medium text-[#111]">{t('تجربة متجر واحد')}</p>
                    {pilot.length === 0 ? (
                        <p className="text-[12px] text-[#6b7280]" data-testid="wa-trial-no-pilot">{t('اكتب المتجر الاختباري في «متاجر التجربة» واربط رقمه أولًا.')}</p>
                    ) : (
                        <>
                            <div className="flex flex-wrap items-end gap-2">
                                <label className="flex flex-col gap-1 text-[12px] text-[#6b7280]">
                                    {t('المتجر')}
                                    <select className="rounded-[8px] border border-[#e5e7eb] p-2 text-[12px]" value={open.data.business_id} onChange={(e) => open.setData('business_id', e.target.value)} data-testid="wa-trial-shop">
                                        {pilot.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
                                    </select>
                                </label>
                                <label className="flex flex-col gap-1 text-[12px] text-[#6b7280]">
                                    {t('أكثر عدد من الرسائل')}
                                    <Input type="number" min={1} max={state.defaults.max_messages} value={open.data.messages} onChange={(e) => open.setData('messages', e.target.value)} className="w-28" data-testid="wa-trial-messages" />
                                </label>
                                <label className="flex flex-col gap-1 text-[12px] text-[#6b7280]">
                                    {t('المدة بالساعات')}
                                    <Input type="number" min={1} max={state.defaults.max_hours} value={open.data.hours} onChange={(e) => open.setData('hours', e.target.value)} className="w-28" data-testid="wa-trial-hours" />
                                </label>
                            </div>
                            <label className="flex flex-col gap-1 text-[12px] text-[#6b7280]">
                                {t('أرقام الاختبار')}
                                <textarea
                                    rows={2}
                                    dir="ltr"
                                    className="w-full max-w-sm rounded-[8px] border border-[#e5e7eb] p-2 text-[12px]"
                                    value={open.data.phones}
                                    onChange={(e) => open.setData('phones', e.target.value)}
                                    data-testid="wa-trial-phones"
                                />
                                <span className="text-[11px] leading-relaxed">{t('رقم في كل سطر، حتى :max. رسائل التجربة لا تخرج إلا إلى هذه الأرقام — لا لعملاء المتجر.', { max: state.defaults.max_phones })}</span>
                            </label>
                            <label className="flex items-start gap-2 text-[12px] leading-relaxed text-[#374151]">
                                <input type="checkbox" className="mt-0.5" checked={open.data.charges_accepted} onChange={(e) => open.setData('charges_accepted', e.target.checked)} data-testid="wa-trial-accept" />
                                <span>{t('أوافق على رسوم حقيقية من Meta على بطاقة حساب الاختبار — وليست بطاقة أبعاد.')}</span>
                            </label>
                            {first(open.errors as Record<string, string>) && <p className="text-[12px] text-[#b91c1c]" data-testid="wa-trial-open-error">{first(open.errors as Record<string, string>)}</p>}
                            <Button type="submit" size="sm" loading={open.processing} disabled={!open.data.charges_accepted || open.data.phones.trim() === ''} data-testid="wa-trial-start">{t('افتح التجربة')}</Button>
                        </>
                    )}
                </form>
            )}

            {trial && !state.launched && (
                <div className="mt-3 border-t border-[#f0f0f0] pt-3" data-testid="wa-trial">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <p className="text-[12px] text-[#374151]">
                            <strong>{trial.business}</strong> · {trial.open ? t('التجربة مفتوحة') : t('التجربة مغلقة')} · {t('خرجت')} <bdi dir="ltr" data-testid="wa-trial-used">{trial.used} / {trial.limit}</bdi>
                            {trial.open && <> · {t('حتى')} <span dir="ltr">{trial.until}</span></>}
                        </p>
                        {trial.open && <Button type="button" variant="outline" size="sm" onClick={() => void close()} data-testid="wa-trial-close">{t('أغلق التجربة')}</Button>}
                    </div>
                    <p className="mt-1 text-[11px] text-[#6b7280]" data-testid="wa-trial-phones-list">
                        {t('لا تخرج رسائلها إلا إلى')}{' '}
                        {trial.phones.length === 0 ? t('— لا رقم، فلا تخرج رسالة.') : trial.phones.map((p, i) => <span key={p}>{i > 0 ? '، ' : ''}<bdi dir="ltr">+{p}</bdi></span>)}
                    </p>

                    <ul className="mt-2 space-y-0.5 text-[11px] text-[#4b4b4b]" data-testid="wa-trial-messages-list">
                        {trial.messages.length === 0 && <li>{t('لم تصل من Meta أخبار رسائل بعد.')}</li>}
                        {trial.messages.map((m) => (
                            <li key={m.id}>
                                <span dir="ltr">{m.at}</span> · {m.kind} · {m.category} · {m.delivered ? t('وصلت') : t('لم تصل بعد')} ·{' '}
                                {m.charged === true ? <strong>{t('محتسبة')}</strong> : m.charged === false ? <>{t('مجانية')}{m.why_free && <> ({m.why_free})</>}</> : t('لم تقل Meta')}
                                {' '}<bdi className="text-[#9ca3af]" dir="ltr">…{m.tail}</bdi>
                            </li>
                        ))}
                    </ul>

                    {trial.may_pass && (
                        <form
                            className="mt-3 space-y-2"
                            data-testid="wa-trial-pass"
                            onSubmit={(e) => {
                                e.preventDefault();
                                pass.post(route('super-admin.whatsapp.billing-trial.pass', trial.id), { preserveScroll: true });
                            }}
                        >
                            <p className="font-medium text-[#111]">{t('سجّل نتيجة التجربة')}</p>
                            <p className="text-[12px] leading-relaxed text-[#6b7280]">{t('بعد أن ترى رسم الرسالة على حساب التاجر، وتراجع حساب أبعاد في اليوم نفسه. التقدير لا يُقبل، ولا يُكتب رقم بطاقة.')}</p>
                            <select className="w-full rounded-[8px] border border-[#e5e7eb] p-2 text-[12px]" value={pass.data.price_id} onChange={(e) => pass.setData('price_id', e.target.value)} aria-label={t('الرسالة المحتسبة')} data-testid="wa-pass-message">
                                <option value="">{t('الرسالة المحتسبة')}</option>
                                {chargeable.map((m) => <option key={m.id} value={m.id}>{`${m.at ?? ''} · ${m.category} · …${m.tail}`}</option>)}
                            </select>
                            <select className="w-full rounded-[8px] border border-[#e5e7eb] p-2 text-[12px]" value={pass.data.kind} onChange={(e) => pass.setData('kind', e.target.value)} aria-label={t('ما الدليل؟')} data-testid="wa-pass-kind">
                                <option value="">{t('ما الدليل؟')}</option>
                                {state.kinds.map((k) => <option key={k.key} value={k.key}>{k.label}</option>)}
                            </select>
                            <Input value={pass.data.waba_id} onChange={(e) => pass.setData('waba_id', e.target.value)} placeholder={t('معرّف الحساب كما في صفحة ميتا (WABA ID)')} dir="ltr" inputMode="numeric" data-testid="wa-pass-waba" />
                            <Input value={pass.data.reference} onChange={(e) => pass.setData('reference', e.target.value)} placeholder={t('مرجع الرسم على حساب التاجر: رقم الفاتورة أو التاريخ')} data-testid="wa-pass-reference" />
                            <Input value={pass.data.abaad_reference} onChange={(e) => pass.setData('abaad_reference', e.target.value)} placeholder={t('متى راجعت حساب أبعاد وأين')} data-testid="wa-pass-abaad" />
                            <div className="flex flex-wrap gap-2">
                                <Input value={pass.data.amount} onChange={(e) => pass.setData('amount', e.target.value)} placeholder={t('المبلغ كما في Meta (اختياري)')} inputMode="decimal" className="w-56" data-testid="wa-pass-amount" />
                                <Input value={pass.data.currency} onChange={(e) => pass.setData('currency', e.target.value.toUpperCase().slice(0, 3))} placeholder="USD" dir="ltr" className="w-24" data-testid="wa-pass-currency" />
                            </div>
                            <label className="flex items-start gap-2 text-[12px] leading-relaxed text-[#374151]">
                                <input type="checkbox" className="mt-0.5" checked={pass.data.merchant_checked} onChange={(e) => pass.setData('merchant_checked', e.target.checked)} data-testid="wa-pass-merchant-checked" />
                                <span>{t('رأيت رسم هذه الرسالة على حساب التاجر في Meta — فاتورة أو رسمًا ظاهرًا لا تقديرًا.')}</span>
                            </label>
                            <label className="flex items-start gap-2 text-[12px] leading-relaxed text-[#374151]">
                                <input type="checkbox" className="mt-0.5" checked={pass.data.abaad_checked} onChange={(e) => pass.setData('abaad_checked', e.target.checked)} data-testid="wa-pass-abaad-checked" />
                                <span>{t('راجعت «الفوترة والمدفوعات» في حساب أبعاد ولم يظهر فيها هذا الرسم ولا هذا الحساب.')}</span>
                            </label>
                            {first(pass.errors as Record<string, string>) && <p className="text-[12px] text-[#b91c1c]" data-testid="wa-pass-error">{first(pass.errors as Record<string, string>)}</p>}
                            <Button type="submit" size="sm" loading={pass.processing} disabled={!passReady} data-testid="wa-pass-submit">{t('ثبت أن الرسم على التاجر')}</Button>
                        </form>
                    )}
                </div>
            )}
            {error && <p className="mt-2 text-[12px] text-[#b91c1c]" data-testid="wa-lock-error">{error}</p>}
            {dialog}
        </section>
    );
}
