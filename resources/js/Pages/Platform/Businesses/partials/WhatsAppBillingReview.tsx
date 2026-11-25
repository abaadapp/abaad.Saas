import { useState } from 'react';
import { router, useForm } from '@inertiajs/react';
import { Check, RefreshCw } from 'lucide-react';
import StatusPill from '@/Components/StatusPill';
import { BILLING_PILL, type BillingState } from '@/Components/WhatsappBilling';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { useTranslate } from '@/lib/i18n';

/** تقديرُ Meta لكلفة حساب — ليس فاتورة (`WhatsAppPrices::estimateView`) */
export interface CostEstimate {
    state: 'read' | 'none' | 'cost_hidden' | 'failed';
    cost: number | null;
    volume: number;
    charged: number;
    rows: { category: string; charged: boolean; volume: number; cost: number | null }[];
    from: string;
    to: string;
    read_at: string | null;
}

/** كم رسالةً وصلت وكم قالت Meta إنّها محتسَبة — عددٌ لا مبلغ */
export interface Classified {
    total: number;
    charged: number;
    free: number;
    unknown: number;
    days: number;
}

/** ما يراه مديرُ المنصّة قبل أن يراجع — انظر `App\Support\WhatsAppBillingLock::platformView` */
export interface PlatformBilling {
    state: BillingState;
    label: string;
    /** أيشمله القفل؟ — وما كان قبله يُراجَع ولا يقف */
    locked: boolean;
    stopped: string | null;
    account: string | null;
    owner: string | null;
    owner_id: string | null;
    may_confirm: boolean;
    may_reopen: boolean;
    may_retry: boolean;
    requested_at: string | null;
    requested_by: string | null;
    facts: {
        owner_known: boolean;
        abaad_known: boolean;
        ownership: string | null;
        partner_owned: boolean;
        funding: string | null;
        abaad_access: string | null;
        read_at: string | null;
    } | null;
    evidence: { kind: string; reference: string | null; reviewed_by: string | null; reviewed_at: string | null } | null;
    estimate: CostEstimate | null;
    classified: Classified;
    trial: boolean;
    kinds: { key: string; label: string }[];
    events: { at: string | null; by: string; what: string; why: string | null }[];
}

const FUNDING: Record<string, string> = {
    present: 'عليه وسيلة دفع (لا نعرف لمن)',
    absent: 'بلا وسيلة دفع',
    unreadable: 'لا تُطلعنا Meta عليها',
};

const ACCESS: Record<string, string> = {
    owned: 'تملكه أبعاد',
    shared: 'مشارك مع أبعاد للربط فقط',
    none: 'لا تملكه أبعاد',
    unknown: 'تعذّرت القراءة',
};

/** تصنيفٌ ثمّ تقدير — وما بينهما وبين الفاتورة يُقال */
export function CostLines({ estimate, classified }: { estimate: CostEstimate | null; classified: Classified }) {
    const t = useTranslate();

    return (
        <div className="space-y-1 text-[11px] text-[#6b7280]" data-testid="wa-cost">
            <p data-testid="wa-cost-classified">
                {t('صنّفت Meta في آخر :d يومًا', { d: String(classified.days) })}: {t('وصلت')} <bdi>{classified.total}</bdi> · {t('محتسبة')} <bdi>{classified.charged}</bdi> · {t('مجانية')} <bdi>{classified.free}</bdi>
                {classified.unknown > 0 && <> · {t('لم تقل')} <bdi>{classified.unknown}</bdi></>}
            </p>
            {estimate === null ? (
                <p>{t('لم يُقرأ تقدير الكلفة بعد.')}</p>
            ) : (
                <>
                    <p data-testid="wa-cost-estimate" data-state={estimate.state}>
                        {t('تقدير Meta')} (<span dir="ltr">{estimate.from} → {estimate.to}</span>):{' '}
                        {estimate.state === 'cost_hidden'
                            ? t('Meta لا تعرض كلفة هذا الحساب')
                            : estimate.cost === null
                              ? t('لا كلفة معروضة')
                              : <><bdi dir="ltr">{estimate.cost}</bdi> {t('بعملة حسابه في Meta')}</>}
                        {' · '}{t('رسائل')} <bdi>{estimate.volume}</bdi>
                    </p>
                    {estimate.rows.length > 0 && (
                        <ul className="ps-3">
                            {estimate.rows.map((r, i) => (
                                <li key={i}>
                                    {r.category} — {r.charged ? t('محتسبة') : t('مجانية')}: <bdi>{r.volume}</bdi>
                                    {r.cost !== null && <> · <bdi dir="ltr">{r.cost}</bdi></>}
                                </li>
                            ))}
                        </ul>
                    )}
                    <p>{t('تقدير قد يختلف عن الفاتورة، ولا يُقبل دليلًا.')}</p>
                </>
            )}
        </div>
    );
}

/**
 * على من رسومُ هذا المتجر عند Meta — حالتُه ووقائعُه ومراجعتُها وسجلُّها.
 *
 * Meta لا تسمّي جهةَ الدفع لتطبيقنا: فالإثباتُ مراجعةُ دليلٍ منها بيد مدير المنصّة، ووقائعُها تنقض ولا تُثبت.
 */
export default function WhatsAppBillingReview({ businessId, review }: { businessId: number; review: PlatformBilling }) {
    const t = useTranslate();
    const form = useForm({ evidence_kind: '', evidence_reference: '', evidence_waba_id: '', evidence_checked: false, evidence_owner: false, evidence_card: false });
    const [reason, setReason] = useState('');
    const [error, setError] = useState<string | null>(null);
    const errors = form.errors as Record<string, string | undefined>;
    const facts = review.facts;
    const ready = form.data.evidence_kind !== '' && form.data.evidence_reference.trim().length >= 3 && form.data.evidence_waba_id.trim() !== ''
        && form.data.evidence_checked && form.data.evidence_owner && form.data.evidence_card;

    const act = (name: 'read' | 'retry' | 'estimate' | 'reopen', data: Record<string, string> = {}) =>
        router.post(route(`super-admin.businesses.whatsapp.billing.${name}`, businessId), data, {
            preserveScroll: true,
            onSuccess: () => setError(null),
            onError: (e) => setError(Object.values(e as Record<string, string>)[0] ?? null),
        });

    const boxes: { key: 'evidence_checked' | 'evidence_owner' | 'evidence_card'; label: string }[] = [
        { key: 'evidence_checked', label: 'رأيت في الدليل معرّف هذا الحساب نفسه — لا اسم النشاط وحده.' },
        { key: 'evidence_owner', label: 'مالك الحساب في Meta نشاط التاجر — لا أبعاد ولا شريك.' },
        { key: 'evidence_card', label: 'وسيلة الدفع للتاجر — ليست بطاقة أبعاد ولا خط ائتمانها ولا خط شريك.' },
    ];

    return (
        <div className="mt-4 rounded-[12px] border border-[var(--ui-border,#e8e8e8)] p-4 text-[13px]" data-testid="wa-billing-review" data-state={review.state}>
            <div className="flex flex-wrap items-center justify-between gap-2">
                <p className="font-semibold text-[#111]">{t('فوترة واتساب')}</p>
                <StatusPill state={BILLING_PILL[review.state]} label={review.label} />
            </div>

            {!review.locked && (
                <p className="mt-1 text-[12px] text-[#6b7280]" data-testid="wa-billing-legacy">
                    {t('هذا الربط من قبل قفل الفوترة — يُراجَع هنا ولا يتوقف إرساله.')}
                </p>
            )}
            {review.stopped && (
                <p className="mt-2 rounded-[10px] bg-[#fef2f2] px-3 py-2 text-[12px] leading-relaxed text-[#b91c1c]" data-testid="wa-billing-stopped">
                    {t('الإرسال متوقف')}: {review.stopped}
                </p>
            )}
            {review.trial && <p className="mt-2 text-[12px] text-[#1d4ed8]">{t('متجر تجربة الفوترة المفتوحة الآن.')}</p>}

            <dl className="mt-2 space-y-0.5 text-[12px] text-[#374151]">
                {review.account && <div>{t('حساب الرسائل المربوط')}: <bdi dir="ltr" data-testid="wa-billing-account">{review.account}</bdi></div>}
                <div>
                    {t('مالكه عند Meta')}: {review.owner ?? t('لم يُقرأ')}
                    {review.owner_id && <>{' '}<bdi className="text-[#9ca3af]" dir="ltr">{review.owner_id}</bdi></>}
                </div>
                {review.requested_at && <div>{t('طلب التحقق')}: {review.requested_by} · <span dir="ltr">{review.requested_at}</span></div>}
            </dl>

            {facts && (
                <ul className="mt-2 space-y-0.5 text-[11px] text-[#6b7280]" data-testid="wa-billing-facts">
                    <li>{t('قُرئ المالك')}: {facts.owner_known ? t('نعم') : t('لا')} · {t('حسابات أبعاد معروفة للنظام')}: {facts.abaad_known ? t('نعم') : t('لا')}</li>
                    <li>{t('نوع الملكية')}: {facts.ownership ?? t('تعذّرت القراءة')}{facts.partner_owned ? ` — ${t('لشريك نيابة عن التاجر')}` : ''}</li>
                    <li>{t('وسيلة الدفع')}: {t(FUNDING[facts.funding ?? 'unreadable'] ?? FUNDING.unreadable)}</li>
                    <li>{t('نشاط أبعاد')}: {t(ACCESS[facts.abaad_access ?? 'unknown'] ?? ACCESS.unknown)}</li>
                    {facts.read_at && <li>{t('قُرئت')}: <span dir="ltr">{facts.read_at}</span></li>}
                </ul>
            )}

            {review.evidence && (
                <p className="mt-2 text-[11px] text-[#047857]" data-testid="wa-billing-evidence">
                    {review.evidence.kind} — {review.evidence.reference} · {review.evidence.reviewed_by} · <span dir="ltr">{review.evidence.reviewed_at}</span>
                </p>
            )}

            <div className="mt-3"><CostLines estimate={review.estimate} classified={review.classified} /></div>

            <div className="mt-3 flex flex-wrap gap-2">
                <Button type="button" variant="outline" size="sm" onClick={() => act('read')} data-testid="wa-billing-read"><RefreshCw />{t('اقرأ من Meta الآن')}</Button>
                <Button type="button" variant="outline" size="sm" onClick={() => act('estimate')} data-testid="wa-billing-estimate">{t('اقرأ تقدير الكلفة')}</Button>
                {review.may_retry && <Button type="button" variant="outline" size="sm" onClick={() => act('retry')} data-testid="wa-billing-platform-retry">{t('أعد المحاولة بعد إصلاح الدفع')}</Button>}
            </div>

            {review.may_confirm && review.state !== 'proven' && review.locked && (
                <form
                    className="mt-3 space-y-2 border-t border-[#f0f0f0] pt-3"
                    data-testid="wa-billing-form"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post(route('super-admin.businesses.whatsapp.billing', businessId), { preserveScroll: true });
                    }}
                >
                    <p className="text-[12px] leading-relaxed text-[#6b7280]">
                        {t('Meta لا تسمّي جهة الدفع، وملكية الحساب لا تكفي. أثبت بعد أن ترى دليلًا من Meta نفسها، ولا تكتب رقم بطاقة.')}
                    </p>
                    <select
                        className="w-full rounded-[8px] border border-[#e5e7eb] p-2 text-[12px]"
                        value={form.data.evidence_kind}
                        onChange={(e) => form.setData('evidence_kind', e.target.value)}
                        aria-label={t('ما الدليل؟')}
                        data-testid="wa-billing-kind"
                    >
                        <option value="">{t('ما الدليل؟')}</option>
                        {review.kinds.map((k) => <option key={k.key} value={k.key}>{k.label}</option>)}
                    </select>
                    <Input value={form.data.evidence_reference} onChange={(e) => form.setData('evidence_reference', e.target.value)} placeholder={t('مرجعه: رقم الفاتورة أو تاريخ الصورة')} data-testid="wa-billing-reference" />
                    <Input value={form.data.evidence_waba_id} onChange={(e) => form.setData('evidence_waba_id', e.target.value)} placeholder={t('معرّف الحساب كما في صفحة ميتا (WABA ID)')} dir="ltr" inputMode="numeric" data-testid="wa-billing-waba" />
                    {boxes.map((b) => (
                        <label key={b.key} className="flex items-start gap-2 text-[12px] leading-relaxed text-[#374151]">
                            <input type="checkbox" className="mt-0.5" checked={form.data[b.key]} onChange={(e) => form.setData(b.key, e.target.checked)} data-testid={`wa-billing-${b.key}`} />
                            <span>{t(b.label)}</span>
                        </label>
                    ))}
                    {Object.values(errors).filter(Boolean).slice(0, 1).map((m) => <p key={m} className="text-[12px] text-[#b91c1c]" data-testid="wa-billing-form-error">{m}</p>)}
                    <Button type="submit" size="sm" loading={form.processing} disabled={!ready} data-testid="wa-billing-confirm">
                        <Check />
                        {t('راجعت الدليل — الفوترة على التاجر')}
                    </Button>
                </form>
            )}

            {review.may_reopen && (
                <form
                    className="mt-3 flex flex-wrap items-end gap-2 border-t border-[#f0f0f0] pt-3"
                    onSubmit={(e) => {
                        e.preventDefault();
                        act('reopen', { reason });
                    }}
                >
                    <label className="flex flex-col gap-1 text-[12px] text-[#6b7280]">
                        {t('سبب إعادة التحقق')}
                        <Input value={reason} onChange={(e) => setReason(e.target.value)} className="w-64" data-testid="wa-billing-reason" />
                    </label>
                    <Button type="submit" variant="outline" size="sm" disabled={reason.trim().length < 3} data-testid="wa-billing-reopen">
                        {review.locked ? t('أعد التحقق وأوقف الإرسال') : t('أعد التحقق')}
                    </Button>
                </form>
            )}
            {error && <p className="mt-2 text-[12px] text-[#b91c1c]" data-testid="wa-billing-action-error">{error}</p>}

            {review.events.length > 0 && (
                <ul className="mt-3 space-y-0.5 border-t border-[#f0f0f0] pt-2 text-[11px] text-[#4b4b4b]" data-testid="wa-billing-events">
                    {review.events.map((e, i) => (
                        <li key={i}><span dir="ltr">{e.at}</span> — {e.by}: {e.what}{e.why && <> ({e.why})</>}</li>
                    ))}
                </ul>
            )}
        </div>
    );
}
