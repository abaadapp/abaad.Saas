import { useState } from 'react';
import { Link, router, useForm, usePage } from '@inertiajs/react';
import { Ban, ExternalLink, Link2, MessageCircle } from 'lucide-react';

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
    can: boolean;
    settings_url: string | null;
    links: PaymentLinkRow[];
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

/** نسخٌ وفتحٌ ومشاركةٌ وإلغاء — للرابط الذي يُدفع */
export function LinkActions({ link, can }: { link: PaymentLinkRow; can: boolean }) {
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
                    {can && (
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
                <LinkActions link={link} can={false} />
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
}: {
    panel: PaymentLinksPanel;
    customerId: number;
    invoiceId?: number;
    outstanding?: number;
    title: string;
    action: string;
    /** أيُطلب من هنا رابطٌ جديد؟ — فاتورةٌ صادرةٌ عليها باقٍ، أو عميل */
    allowCreate?: boolean;
}) {
    const t = useTranslate();
    const { context } = usePage<PageProps>().props;
    const m = (v: number) => money(v, context!.currency);
    const [open, setOpen] = useState(false);

    const form = useForm({
        customer_id: customerId,
        customer_invoice_id: invoiceId ?? null,
        amount: outstanding !== undefined ? outstanding.toFixed(3) : '',
        description: '',
    });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.post(route('admin.finance.paymentLinks.store'), {
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

            <JustCreated />

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
                            <LinkActions link={l} can={panel.can} />
                        </li>
                    ))}
                </ul>
            )}
        </Card>
    );
}
