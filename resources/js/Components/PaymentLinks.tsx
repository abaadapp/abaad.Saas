import { useState } from 'react';
import { Link, router, useForm, usePage } from '@inertiajs/react';
import { AlertTriangle, Ban, CheckCircle2, ExternalLink, Link2, MessageCircle, RotateCcw } from 'lucide-react';

import CopyButton from '@/Components/CopyButton';
import { useConfirm } from '@/Components/ConfirmDialog';
import Field from '@/Components/Field';
import SmartLink from '@/Components/SmartLink';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { money } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import type { PageProps } from '@/types';

/**
 * روابطُ الدفع — طلبُ مالٍ من عميلٍ عبر Paymob بحساب التاجر.
 *
 * والرابطُ تحصيلٌ لا بيع: يُدفع فيُكتب تحصيلٌ على العميل (ومخصَّصٌ لفاتورته
 * إن كان لها)، ولا يمسّ إيرادًا ولا ضريبة. والحالُ من الخادم — الإشعارُ
 * الموقَّع وحده يقول «تم الدفع»، لا هذه الشاشة.
 *
 * ولا سرَّ هنا: ما يُنسخ ويُشارك هو الرابطُ القصيرُ الذي يُرسَل للعميل أصلًا.
 */

export interface PaymentLinkRow {
    id: number;
    reference: string;
    customer: { id: number; name: string };
    invoice: { id: number; number: string } | null;
    description: string | null;
    amount: number;
    currency: string;
    status: 'pending' | 'paid' | 'expired' | 'cancelled' | 'failed';
    is_live: boolean;
    url: string | null;
    created_at: string | null;
    expires_at: string | null;
    paid_at: string | null;
    payment: { id: number; number: string } | null;
    error: string | null;
    /** دفعةٌ ثانية أو استرجاعٌ أبلغت به Paymob — يحتاج نظرةً من صاحب المحلّ */
    review: boolean;
}

export interface PaymentLinksPanel {
    ready: boolean;
    /** يطلب رابطًا جديدًا — فعلُ `paymentLink.create`، لا «المالية» ولا التحصيل */
    can: boolean;
    /** يُلغي رابطًا قائمًا — فعلُ التحصيل وبابُه «المالية»، كما كان */
    can_cancel?: boolean;
    settings_url: string | null;
    links: PaymentLinkRow[];
}

/** آخرُ محاولةٍ لم تُعطِ رابطًا — `PaymentLinks::failureRow` */
export interface PaymentLinkFailure {
    error: string | null;
    at: string | null;
    /** انقطع الاتصال بعد الإرسال: قد يكون الرابطُ عند Paymob — لا يُعاد وحده */
    uncertain: boolean;
    until: string | null;
}

/** حالُ الرابط بكلماتٍ يفهمها من لا يعرف Paymob */
export const LINK_STATUS: Record<PaymentLinkRow['status'], { label: string; variant: 'warning' | 'success' | 'neutral' | 'danger' }> = {
    pending: { label: 'بانتظار الدفع', variant: 'warning' },
    paid: { label: 'تم الدفع', variant: 'success' },
    expired: { label: 'منتهٍ', variant: 'neutral' },
    cancelled: { label: 'ملغى', variant: 'neutral' },
    failed: { label: 'تعذّر إنشاء الرابط', variant: 'danger' },
};

export function LinkStatus({ status }: { status: PaymentLinkRow['status'] }) {
    const t = useTranslate();
    const s = LINK_STATUS[status];

    return (
        <Badge variant={s.variant} data-testid="payment-link-status">
            {t(s.label)}
        </Badge>
    );
}

/**
 * لماذا لم يُدفع؟ — سببُ فشل الإنشاء، أو آخرُ ما وصل من Paymob عن رابطٍ معلَّق.
 *
 * والسببُ محفوظٌ على الرابط من قبل (`error`) ولم يكن يُرسم: فدفعٌ رُدّ أو
 * إشعارٌ بتوقيعٍ خاطئ يترك صاحبَ المحلّ أمام «بانتظار الدفع» وحدها، فلا
 * يعرف أيُصلح إعدادًا أم ينتظر. والمدفوعُ لا سببَ له.
 */
export function LinkWhy({ link }: { link: PaymentLinkRow }) {
    const t = useTranslate();

    if (! link.error || link.status === 'paid') return null;

    const failed = link.status === 'failed';

    return (
        <span
            className={`block text-[12px] ${failed ? 'text-[#b91c1c]' : 'text-[#b45309]'}`}
            dir="auto"
            data-testid="payment-link-why"
        >
            {t(failed ? 'سبب الفشل' : 'آخر ما وصل من Paymob')}: {link.error}
        </span>
    );
}

/** نصُّ المشاركة — الرابطُ والمبلغ، ولا شيءَ غيرُهما */
export const shareText = (link: PaymentLinkRow, amount: string) => `${amount}\n${link.url ?? ''}`;

/**
 * نصُّ المشاركة بواتساب لرابط فاتورة — رقمُها ومبلغُها ورابطُها، ولا شيءَ غيرُها.
 *
 * لا اسمَ العميل ولا هاتفَه ولا بنودَ الفاتورة: الرسالةُ تُرسَل من هاتف
 * الموظّف إلى من يختاره، فلا يُكتب فيها ما لا يلزم من يدفع.
 */
export const invoiceShareText = (link: PaymentLinkRow, amount: string, t: (s: string) => string) =>
    [link.invoice ? `${t('فاتورة')} ${link.invoice.number}` : null, `${t('المبلغ')}: ${amount}`, `${t('رابط الدفع')}: ${link.url ?? ''}`]
        .filter(Boolean)
        .join('\n');

/**
 * ما تعرضه صفحةُ الفاتورة من رابطها: الذي يُرسَل الآن، أو سببُ تعذّره.
 *
 * - **الرابطُ الآن:** ما أُنشئ للتوّ **لهذه الفاتورة** (رسالةُ الجلسة)، وإلّا
 *   القائمُ بانتظار الدفع من الخادم — فيبقى بعد التحديث. والمدفوعُ والملغى
 *   والمنتهي ليست «ما يُرسَل».
 * - **«تم إصدار الفاتورة وإنشاء رابط الدفع»:** بعد الإصدار مع الرابط وحده —
 *   لا بعد «إنشاء رابط دفع» لفاتورةٍ صادرة من قبل.
 * - **إعادةُ المحاولة:** لا رابطَ قائم، والفاتورةُ صادرةٌ وعليها ما يُطلب.
 */
export function invoiceLinkView(
    invoice: { id: number; status: string; outstanding: number },
    links: PaymentLinkRow[],
    flash: { paymentLink?: PaymentLinkRow | null; paymentLinkIssued?: boolean } | undefined,
    failure: PaymentLinkFailure | null | undefined,
): { current: PaymentLinkRow | null; issued: boolean; retry: PaymentLinkFailure | null } {
    const justMade = flash?.paymentLink && flash.paymentLink.invoice?.id === invoice.id ? flash.paymentLink : null;
    const current = justMade ?? links.find((l) => l.status === 'pending' && l.url) ?? null;

    return {
        current,
        issued: !!justMade && !!flash?.paymentLinkIssued,
        retry: !current && failure && invoice.status === 'صادرة' && invoice.outstanding > 0 ? failure : null,
    };
}

/** «إرسال عبر واتساب» — بلا رقمٍ مسبق: يختار الموظّفُ من يُرسل إليه */
export const whatsappShareUrl = (text: string) => `https://wa.me/?text=${encodeURIComponent(text)}`;

/** نسخٌ وفتحٌ ومشاركةٌ وإلغاء — للرابط الذي يُدفع. والإلغاءُ لمن يملكه وحده */
export function LinkActions({ link, canCancel }: { link: PaymentLinkRow; canCancel: boolean }) {
    const t = useTranslate();
    const { context } = usePage<PageProps>().props;
    const amount = money(link.amount, context!.currency);
    const [ask, dialog] = useConfirm();

    if (!link.url) return null;

    return (
        <div className="flex flex-wrap items-center gap-1.5" data-testid="payment-link-actions">
            <CopyButton text={link.url} name="نسخ الرابط" />
            <Button asChild variant="outline" size="sm">
                <a href={link.url} target="_blank" rel="noopener noreferrer" aria-label={t('فتح الرابط')}>
                    <ExternalLink />
                </a>
            </Button>
            {link.status === 'pending' && (
                <>
                    <Button asChild variant="outline" size="sm">
                        <a
                            href={`https://wa.me/?text=${encodeURIComponent(`${t('رابط الدفع')}: ${shareText(link, amount)}`)}`}
                            target="_blank"
                            rel="noopener noreferrer"
                            aria-label={t('مشاركة عبر واتساب')}
                            data-testid="payment-link-whatsapp"
                        >
                            <MessageCircle />
                        </a>
                    </Button>
                    {canCancel && (
                        <Button
                            variant="outline"
                            size="sm"
                            onClick={async () => {
                                if (! (await ask({ message: 'إلغاء رابط الدفع؟ لن يستطيع العميل الدفع به.', action: 'إلغاء الرابط', danger: true }))) return;
                                router.post(route('admin.finance.paymentLinks.cancel', link.id), {}, { preserveScroll: true });
                            }}
                            data-testid="payment-link-cancel"
                        >
                            <Ban />
                            {t('إلغاء')}
                        </Button>
                    )}
                </>
            )}
            {dialog}
        </div>
    );
}

/** «أكمل إعداد Paymob أولًا» — ومعه بابُ الإعداد لمن يملكه */
export function NotConfigured({ settingsUrl }: { settingsUrl: string | null }) {
    const t = useTranslate();

    return (
        <p className="rounded-[10px] border border-[#fde68a] bg-[#fffbeb] p-3 text-[13px] text-[#92400e]" data-testid="payment-links-not-configured">
            {t('أكمل إعداد Paymob أولًا.')}{' '}
            {settingsUrl && (
                <Link href={settingsUrl} className="font-medium underline">
                    {t('إعدادات Paymob')}
                </Link>
            )}
        </p>
    );
}

/** الرابطُ الذي أُنشئ الآن — يُنسخ ويُرسَل فورًا */
export function JustCreated() {
    const t = useTranslate();
    const { flash, context } = usePage<PageProps>().props;
    const link = flash?.paymentLink;

    if (!link?.url) return null;

    return (
        <Card className="mb-3 border-[#bbf7d0] bg-[#f0fdf4] p-3" data-testid="payment-link-created">
            <p className="mb-2 text-[13px] font-medium text-[#166534]">
                {t('تم إنشاء رابط الدفع')} — {money(link.amount, context!.currency)}
            </p>
            <div className="flex flex-wrap items-center gap-2">
                <Input dir="ltr" readOnly value={link.url} className="max-w-full flex-1 sm:max-w-md" aria-label={t('رابط الدفع')} />
                <LinkActions link={link} canCancel={false} />
            </div>
        </Card>
    );
}

/**
 * لوحةُ روابط الفاتورة أو العميل — القائمةُ، وزرُّ الطلب، ونموذجُه.
 *
 * والفاتورةُ تطلب باقيها افتراضًا (`outstanding`) ويجوز أقلُّ منه، والعميلُ
 * يطلب مبلغًا حرًّا يُكتب.
 */
export default function PaymentLinksCard({
    panel,
    customerId,
    invoiceId,
    outstanding,
    title,
    action,
    allowCreate = true,
    showJustCreated = true,
}: {
    panel: PaymentLinksPanel;
    customerId: number;
    invoiceId?: number;
    outstanding?: number;
    title: string;
    action: string;
    /** أيُطلب من هنا رابطٌ جديد؟ — فاتورةٌ صادرةٌ عليها باقٍ، أو عميل */
    allowCreate?: boolean;
    /** صفحةُ الفاتورة ترسم رابطَها القائم بارزًا فوق البطاقة — فلا يُكرَّر هنا */
    showJustCreated?: boolean;
}) {
    const t = useTranslate();
    const { context } = usePage<PageProps>().props;
    const m = (v: number) => money(v, context!.currency);
    const [open, setOpen] = useState(false);

    const form = useForm({
        amount: outstanding !== undefined ? outstanding.toFixed(3) : '',
        description: '',
    });

    /*
     * والطلبُ من بابِ صفحته لا من «المالية»: الفاتورةُ ببابها والعميلُ ببابه
     * (`PaymentRequestController`) — فمن مُنح طلبَ الدفع وحده يطلب من هنا
     * بلا أن يُفتح له قسمُ المالية كلُّه.
     */
    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        const url = invoiceId !== undefined ? route('admin.customerInvoices.paymentLink', invoiceId) : route('admin.customers.paymentLink', customerId);
        form.post(url, {
            preserveScroll: true,
            onSuccess: () => {
                setOpen(false);
                form.reset('description');
            },
        });
    };

    const canStart = panel.can && allowCreate;

    return (
        <Card className="p-4" data-testid="payment-links-card">
            <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
                <h3 className="flex items-center gap-1.5 text-[13px] font-bold">
                    <Link2 className="size-4" />
                    {t(title)}
                </h3>
                {canStart && panel.ready && (
                    <Button size="sm" variant="outline" onClick={() => setOpen((v) => !v)} data-testid="payment-link-new">
                        {t(action)}
                    </Button>
                )}
            </div>

            {canStart && !panel.ready && <NotConfigured settingsUrl={panel.settings_url} />}

            {showJustCreated && <JustCreated />}

            {open && (
                <form onSubmit={submit} className="mb-3 grid grid-cols-1 gap-3 rounded-[10px] border border-[var(--ui-border,#e8e8e8)] p-3 sm:grid-cols-2" data-testid="payment-link-form">
                    <Field label="المبلغ" error={form.errors.amount} hint={outstanding !== undefined ? `${t('الباقي')}: ${m(outstanding)}` : undefined}>
                        <Input
                            dir="ltr"
                            inputMode="decimal"
                            value={form.data.amount}
                            onChange={(e) => form.setData('amount', e.target.value)}
                            aria-label={t('المبلغ')}
                        />
                    </Field>
                    <Field label="الوصف" hint="اختياري — يراه العميل في صفحة الدفع" error={form.errors.description}>
                        <Input value={form.data.description} onChange={(e) => form.setData('description', e.target.value)} aria-label={t('الوصف')} />
                    </Field>
                    <div className="flex items-end gap-2 sm:col-span-2">
                        <Button type="submit" loading={form.processing} data-testid="payment-link-submit">
                            {t('إنشاء الرابط')}
                        </Button>
                        <Button type="button" variant="outline" onClick={() => setOpen(false)}>
                            {t('إلغاء')}
                        </Button>
                    </div>
                </form>
            )}

            {panel.links.length === 0 ? (
                <p className="text-[12.5px] text-[#9ca3af]">{t('لا روابط دفع بعد.')}</p>
            ) : (
                <ul className="divide-y divide-[var(--ui-border,#e8e8e8)]">
                    {panel.links.map((l) => (
                        <li key={l.id} className="flex flex-wrap items-center justify-between gap-2 py-2 text-[13px]" data-testid="payment-link-row">
                            <div className="flex min-w-0 flex-col gap-0.5">
                                <span className="flex flex-wrap items-center gap-2">
                                    <span className="font-semibold tabular-nums">{m(l.amount)}</span>
                                    <LinkStatus status={l.status} />
                                    {!l.is_live && <Badge variant="outline">{t('تجربة')}</Badge>}
                                </span>
                                <span className="text-[11.5px] text-[#71717a]" dir="auto">
                                    {t('أُنشئ')} {l.created_at}
                                    {l.status === 'pending' && l.expires_at ? ` · ${t('ينتهي')} ${l.expires_at}` : ''}
                                    {l.paid_at ? ` · ${t('دُفع')} ${l.paid_at}` : ''}
                                </span>
                                {l.payment && (
                                    <SmartLink
                                        routeName="admin.customerPayments.show"
                                        href={route('admin.customerPayments.show', l.payment.id)}
                                        className="text-[12px] text-[#6d28d9] hover:underline"
                                    >
                                        {t('التحصيل')} {l.payment.number}
                                    </SmartLink>
                                )}
                                {l.review && <span className="text-[12px] text-[#b45309]">{t('تحتاج مراجعة — انظر سجل النشاط')}</span>}
                                <LinkWhy link={l} />
                            </div>
                            <LinkActions link={l} canCancel={!!panel.can_cancel} />
                        </li>
                    ))}
                </ul>
            )}
        </Card>
    );
}

/**
 * رابطُ دفع الفاتورة بارزًا — ما يُرسَل للعميل الآن.
 *
 * بعد «إصدار الفاتورة وإنشاء رابط الدفع» أو «إنشاء رابط دفع»، ويبقى بعد
 * التحديث: الرابطُ القائمُ من الخادم لا من رسالةٍ عابرة. رقمُ الفاتورة،
 * والمبلغُ بعملته، والرابطُ، وحالُه، ومتى ينتهي — ونسخُه وإرسالُه وفتحُه.
 */
export function InvoicePaymentLink({ link, issued }: { link: PaymentLinkRow; issued: boolean }) {
    const t = useTranslate();
    const { context } = usePage<PageProps>().props;
    const amount = `${money(link.amount, context!.currency)}`;
    const share = whatsappShareUrl(invoiceShareText(link, amount, t));

    if (!link.url) return null;

    return (
        <Card className="border-[#bbf7d0] bg-[#f0fdf4] p-4" data-testid="invoice-payment-link">
            <p className="mb-2 flex items-center gap-1.5 text-[14px] font-bold text-[#166534]">
                <CheckCircle2 className="size-4" />
                {t(issued ? 'تم إصدار الفاتورة وإنشاء رابط الدفع' : 'رابط الدفع')}
            </p>
            <dl className="mb-3 grid grid-cols-2 gap-x-4 gap-y-1 text-[12.5px] sm:grid-cols-4">
                {link.invoice && (
                    <div>
                        <dt className="text-[#6b7280]">{t('رقم الفاتورة')}</dt>
                        <dd className="font-semibold" dir="ltr" data-testid="invoice-payment-link-number">{link.invoice.number}</dd>
                    </div>
                )}
                <div>
                    <dt className="text-[#6b7280]">{t('المبلغ')}</dt>
                    <dd className="font-semibold tabular-nums" data-testid="invoice-payment-link-amount">
                        {amount} <span className="text-[11px] text-[#6b7280]" dir="ltr">{link.currency}</span>
                    </dd>
                </div>
                <div>
                    <dt className="text-[#6b7280]">{t('الحالة')}</dt>
                    <dd><LinkStatus status={link.status} /></dd>
                </div>
                {link.expires_at && (
                    <div>
                        <dt className="text-[#6b7280]">{t('ينتهي')}</dt>
                        <dd dir="ltr" data-testid="invoice-payment-link-expires">{link.expires_at}</dd>
                    </div>
                )}
            </dl>
            <Input dir="ltr" readOnly value={link.url} className="mb-2" aria-label={t('رابط الدفع')} data-testid="invoice-payment-link-url" />
            <div className="flex flex-wrap gap-2">
                <CopyButton text={link.url} name="نسخ رابط الدفع" label="نسخ رابط الدفع" />
                {link.status === 'pending' && (
                    <Button asChild variant="outline" size="sm">
                        <a href={share} target="_blank" rel="noopener noreferrer" data-testid="invoice-payment-link-whatsapp">
                            <MessageCircle />
                            {t('إرسال عبر واتساب')}
                        </a>
                    </Button>
                )}
                <Button asChild variant="outline" size="sm">
                    <a href={link.url} target="_blank" rel="noopener noreferrer" data-testid="invoice-payment-link-open">
                        <ExternalLink />
                        {t('فتح رابط الدفع')}
                    </a>
                </Button>
            </div>
        </Card>
    );
}

/**
 * أُصدرت الفاتورة ولم يُنشأ الرابط — لماذا، وأعِد المحاولة على الفاتورة نفسِها.
 *
 * ومحاولةٌ لا تُعرف نتيجتُها (انقطع الاتصال بعد الإرسال) تُقال كذلك: قد يكون
 * رابطُها عند Paymob. فلا تُعاد وحدها — زرُّها يقول ما سيقع، ويُرسل قرارًا
 * صريحًا (`replace_uncertain`).
 */
export function PaymentLinkRetry({ invoiceId, failure, can }: { invoiceId: number; failure: PaymentLinkFailure; can: boolean }) {
    const t = useTranslate();
    const { errors } = usePage<PageProps & { errors: Record<string, string> }>().props;
    const [busy, setBusy] = useState(false);

    const retry = (replace: boolean) => {
        setBusy(true);
        router.post(
            route('admin.customerInvoices.paymentLink', invoiceId),
            replace ? { replace_uncertain: true } : {},
            { preserveScroll: true, onFinish: () => setBusy(false) },
        );
    };

    return (
        <Card className="border-[#fde68a] bg-[#fffbeb] p-4 text-[13px] text-[#92400e]" data-testid="payment-link-failure">
            <p className="mb-1 flex items-center gap-1.5 font-bold">
                <AlertTriangle className="size-4" />
                {t('لم يُنشأ رابط الدفع')}
            </p>
            {failure.error && (
                <p className="mb-2" dir="auto">
                    {failure.error} {failure.at && <span className="text-[11.5px]" dir="ltr">({failure.at})</span>}
                </p>
            )}
            {failure.uncertain && (
                <p className="mb-2" data-testid="payment-link-uncertain">
                    {t('انقطع الاتصال بـ Paymob بعد الإرسال، فقد يكون الرابط أُنشئ هناك دون أن يصلنا. لا يُعاد الطلب وحده: إن طلبت رابطًا جديدًا فأرسل الجديد وحده للعميل.')}
                </p>
            )}
            {(errors?.payment_link || errors?.amount) && (
                <p className="mb-2 text-[#b91c1c]" dir="auto">{errors.payment_link ?? errors.amount}</p>
            )}
            {can && (
                <Button
                    size="sm"
                    variant="outline"
                    disabled={busy}
                    onClick={() => retry(failure.uncertain)}
                    data-testid={failure.uncertain ? 'payment-link-replace' : 'payment-link-retry'}
                >
                    <RotateCcw />
                    {t(failure.uncertain ? 'إنشاء رابط جديد وإرساله وحده' : 'إعادة المحاولة')}
                </Button>
            )}
        </Card>
    );
}
