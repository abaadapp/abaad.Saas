import { useState } from 'react';
import { router, useForm, usePage } from '@inertiajs/react';
import { Ban, CheckCircle2, ExternalLink, FileDown, MessageCircle, RefreshCcw, ShieldAlert } from 'lucide-react';
import PlatformLayout from '@/Layouts/PlatformLayout';
import PageHeader from '@/Components/PageHeader';
import BackLink from '@/Components/BackLink';
import SmartLink from '@/Components/SmartLink';
import CopyButton from '@/Components/CopyButton';
import Field from '@/Components/Field';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { Input } from '@/Components/ui/input';
import { useTranslate } from '@/lib/i18n';
import type { PageProps } from '@/types';
import { STATE_VARIANT, useLiveReload, type DocumentRow } from '@/lib/platformDocuments';

interface Plan {
    name: string;
    cycle_label: string;
    unit: string;
    duration?: string;
    price: string;
    limits: string;
}

interface FieldDef {
    k: string;
    ar: string;
    req: boolean;
    dir?: string;
}

interface Subscription {
    plan: string | null;
    starts_at: string;
    ends_at: string;
    status: string | null;
    pending_invoice: string | null;
    pending_amount: string | null;
    pending_payment: string | null;
}

interface Props {
    document: DocumentRow & {
        title_en: string | null;
        terms: { h: string; b: string }[];
        plan: Plan | null;
        term_starts_on: string;
        term_ends_on: string;
        amount: string | null;
        amount_label: string | null;
        client: Record<string, string>;
        client_phone: string | null;
        notes: string | null;
        created_by: string | null;
        viewed_at: string;
        cancelled_at: string;
        cancel_reason: string | null;
        signer_ip: string | null;
        signer_agent: string | null;
        consent_text: string | null;
        agreed_at: string | null;
        content_hash: string | null;
        pdf_hash: string | null;
        intact: boolean | null;
        signature: string | null;
        link: string;
        whatsapp: string;
        renews: { id: number; number: string; url: string } | null;
        renewals: { id: number; number: string; url: string; state_label: string }[];
        can_renew: boolean;
        subscription: Subscription | null;
    };
    fields: FieldDef[];
}

export default function DocumentShow() {
    const { document: doc, fields } = usePage<PageProps<Props>>().props;
    const t = useTranslate();
    const [cancelling, setCancelling] = useState(false);
    const open = doc.state === 'sent';

    // ما دام ينتظر التوقيع: تُسأل الصفحةُ عن المستند وحده، فيظهر توقيعُ العميل هنا وحده
    useLiveReload(['document'], open);

    /** يُسجَّل أنّ المدير نسخ الرابط أو فتح به واتساب — فعلُه، لا وصولُ الرسالة */
    const shared = (via: 'copy' | 'whatsapp') =>
        router.post(route('super-admin.documents.shared', doc.id), { via }, { preserveScroll: true, preserveState: true, only: ['document'] });

    return (
        <PlatformLayout title={doc.number}>
            <BackLink routeName="super-admin.documents.index" href={route('super-admin.documents.index')} label="المستندات" />
            <PageHeader
                title={doc.title}
                subtitle={`${doc.number}${doc.service ? ' · ' + doc.service : ''}`}
                actions={
                    <>
                        <Button variant="outline" asChild>
                            <a href={route('super-admin.documents.pdf', doc.id)} target="_blank" rel="noreferrer">
                                <FileDown />
                                {doc.state === 'signed' ? t('تحميل PDF الموقّع') : t('PDF')}
                            </a>
                        </Button>
                        {doc.can_renew && (
                            <Button variant="outline" asChild>
                                <SmartLink
                                    routeName="super-admin.documents.index"
                                    href={route('super-admin.documents.index', { renew: doc.id })}
                                >
                                    <RefreshCcw />
                                    {t('عقد تجديد')}
                                </SmartLink>
                            </Button>
                        )}
                        {open && (
                            <Button variant="outline" onClick={() => setCancelling(true)}>
                                <Ban />
                                {t('إلغاء الرابط')}
                            </Button>
                        )}
                    </>
                }
            />

            <div className="grid gap-4 lg:grid-cols-3">
                <div className="space-y-4 lg:col-span-2">
                    <Card className="p-5">
                        <div className="mb-3 flex flex-wrap items-center gap-2">
                            <Badge variant={STATE_VARIANT[doc.state]}>{t(doc.state_label)}</Badge>
                            {doc.renewal && doc.renews && (
                                <SmartLink routeName="super-admin.documents.show" href={doc.renews.url} className="text-[12px] text-[#1d4ed8] hover:underline">
                                    {t('تجديد للعقد :n', { n: doc.renews.number })}
                                </SmartLink>
                            )}
                            <span className="text-[12px] text-[#6b7280]">
                                {t('أُصدر :at', { at: doc.created_at })}
                                {doc.created_by ? ' · ' + doc.created_by : ''}
                            </span>
                        </div>

                        {doc.state !== 'signed' && doc.state !== 'cancelled' && (
                            <div className="space-y-2" data-testid="document-link">
                                <div className="text-[13px] font-medium text-[#111]">{t('رابط التوقيع — أرسله للعميل')}</div>
                                <code
                                    dir="ltr"
                                    className="no-scrollbar block overflow-x-auto whitespace-nowrap rounded-[8px] bg-[#f4f4f5] px-3 py-2 text-[12.5px]"
                                >
                                    {doc.link}
                                </code>
                                <div className="flex flex-wrap items-center gap-2">
                                    <CopyButton text={doc.link} label="نسخ رابط التوقيع" done="نُسخ الرابط" size="md" onCopied={() => shared('copy')} />
                                    <Button variant="outline" asChild>
                                        <a href={doc.whatsapp} target="_blank" rel="noreferrer" onClick={() => shared('whatsapp')}>
                                            <MessageCircle />
                                            {t('إرسال عبر واتساب')}
                                        </a>
                                    </Button>
                                    <Button variant="ghost" size="sm" asChild>
                                        <a href={doc.link} target="_blank" rel="noreferrer">
                                            <ExternalLink />
                                            {t('معاينة')}
                                        </a>
                                    </Button>
                                </div>
                                <p className="text-[12px] text-[#6b7280]" data-testid="whatsapp-note">
                                    {t('يُفتح واتساب على هذا الجهاز برسالة جاهزة إلى :phone، وتُرسل من الحساب المفتوح عليه بعد أن تضغط «إرسال» بنفسك. فتحه لا يعني أن الرسالة أُرسلت أو وصلت.', {
                                        phone: doc.client_phone ?? '—',
                                    })}
                                </p>
                                <p className="text-[12px] text-[#6b7280]" data-testid="shared-state">
                                    {doc.shared
                                        ? t('آخر مشاركة: :how · :at', { how: t(doc.shared), at: doc.shared_at ?? '' })
                                        : t('لم يُشارَك الرابط من هنا بعد')}
                                </p>
                                <p className="text-[12px] text-[#6b7280]">
                                    {doc.state === 'expired'
                                        ? t('انتهت صلاحية الرابط في :at — أصدر مستندًا جديدًا للعميل', { at: doc.expires_at })
                                        : t('صالح حتى :at · فُتح الرابط: :viewed · تتحدث هذه الصفحة وحدها عند التوقيع', {
                                              at: doc.expires_at,
                                              viewed: doc.viewed_at === '—' ? t('لم يُفتح بعد') : doc.viewed_at,
                                          })}
                                </p>
                            </div>
                        )}

                        {doc.state === 'cancelled' && (
                            <p className="text-[13px] text-[#6b7280]">
                                {t('أُلغي في :at', { at: doc.cancelled_at })}
                                {doc.cancel_reason ? ` — ${doc.cancel_reason}` : ''}
                            </p>
                        )}

                        {doc.state === 'signed' && (
                            <div className="space-y-2" data-testid="document-signed">
                                <div className="flex items-center gap-2 text-[14px] font-medium text-[#047857]">
                                    <CheckCircle2 className="size-5" />
                                    {t('وقّعه :name في :at', { name: doc.client_name ?? doc.client_biz ?? '—', at: doc.signed_at })}
                                </div>
                                {doc.signature && (
                                    <img
                                        src={doc.signature}
                                        alt={t('التوقيع')}
                                        className="max-h-28 max-w-[280px] rounded-[8px] border border-[#e7e8ea] bg-white"
                                    />
                                )}
                                {doc.consent_text && (
                                    <div className="rounded-[8px] bg-[#f4f4f5] px-3 py-2 text-[12.5px] text-[#374151]" data-testid="consent-record">
                                        <div className="mb-0.5 text-[12px] text-[#6b7280]">
                                            {t('سجل الموافقة — :at', { at: doc.agreed_at ?? doc.signed_at })}
                                        </div>
                                        {doc.consent_text}
                                    </div>
                                )}
                                <dl className="grid grid-cols-1 gap-1 text-[12px] text-[#6b7280] sm:grid-cols-2">
                                    <div>
                                        {t('عنوان الجهاز')}: <span dir="ltr">{doc.signer_ip ?? '—'}</span>
                                    </div>
                                    <div className="truncate" title={doc.signer_agent ?? ''}>
                                        {t('المتصفح')}: <span dir="ltr">{doc.signer_agent ?? '—'}</span>
                                    </div>
                                </dl>
                                <div className="text-[11.5px] text-[#9ca3af]">
                                    SHA-256: <span dir="ltr" className="break-all font-mono">{doc.content_hash}</span>
                                </div>
                                {doc.pdf_hash && (
                                    <div className="text-[11.5px] text-[#9ca3af]" data-testid="paper-archived">
                                        {t('النسخة الموقّعة PDF محفوظة لدى المنصة')} · <span dir="ltr" className="break-all font-mono">{doc.pdf_hash}</span>
                                    </div>
                                )}
                                {doc.intact === false && (
                                    <div className="flex items-center gap-2 rounded-[8px] bg-[#fef2f2] px-3 py-2 text-[12.5px] text-[#b91c1c]">
                                        <ShieldAlert className="size-4" />
                                        {t('تغيّر محتوى المستند بعد التوقيع — البصمة لا تطابق')}
                                    </div>
                                )}
                            </div>
                        )}
                    </Card>

                    <Card className="p-5">
                        <h3 className="mb-3 text-[14px] font-semibold text-[#111]">{t('بيانات العميل')}</h3>
                        <dl className="grid grid-cols-1 gap-x-6 gap-y-2 text-[13px] sm:grid-cols-2">
                            {fields.map((f) => (
                                <div key={f.k} className="flex min-w-0 justify-between gap-3 border-b border-[#f4f4f5] pb-1.5">
                                    <dt className="text-[#6b7280]">{t(f.ar)}</dt>
                                    <dd dir={f.dir ?? 'auto'} className="truncate text-[#111]">
                                        {doc.client[f.k] || (f.req && doc.state === 'sent' ? t('يكمله العميل') : '—')}
                                    </dd>
                                </div>
                            ))}
                        </dl>
                    </Card>

                    <Card className="p-5">
                        <h3 className="mb-3 text-[14px] font-semibold text-[#111]">{t('الشروط والأحكام')}</h3>
                        <ol className="list-decimal space-y-2 ps-5 text-[13px] leading-relaxed text-[#374151]">
                            {doc.terms.map((term, i) => (
                                <li key={i}>
                                    {term.h && <span className="font-semibold text-[#111]">{term.h} </span>}
                                    {term.b}
                                </li>
                            ))}
                        </ol>
                    </Card>
                </div>

                <div className="space-y-4">
                    {(doc.plan || doc.amount !== null) && (
                        <Card className="space-y-3 p-5">
                            {doc.plan && (
                                <div data-testid="plan-term">
                                    <div className="text-[12px] text-[#6b7280]">{t('الباقة')}</div>
                                    <div className="text-[15px] font-semibold text-[#111]">
                                        {doc.plan.name} · {t(doc.plan.cycle_label)}
                                    </div>
                                    <div className="text-[12px] text-[#6b7280]">{doc.plan.limits}</div>
                                    <div className="mt-1 text-[18px] font-bold text-[#111]">
                                        <span dir="ltr">{doc.plan.price}</span>{' '}
                                        <span className="text-[12px] font-normal text-[#6b7280]">
                                            {t('ر.ع')} / {doc.plan.unit}
                                        </span>
                                    </div>
                                    {doc.term_starts_on !== '—' && (
                                        <div className="mt-2 text-[12.5px] text-[#374151]">
                                            {t('المدة: :d', { d: doc.plan.duration ?? doc.plan.cycle_label })} · {t('من')}{' '}
                                            <span dir="ltr">{doc.term_starts_on}</span> {t('إلى')} <span dir="ltr">{doc.term_ends_on}</span>
                                        </div>
                                    )}
                                </div>
                            )}
                            {doc.amount !== null && (
                                <div>
                                    <div className="text-[12px] text-[#6b7280]">{doc.amount_label}</div>
                                    <div className="text-[18px] font-bold text-[#111]">
                                        <span dir="ltr">{Number(doc.amount).toFixed(3)}</span>{' '}
                                        <span className="text-[12px] font-normal text-[#6b7280]">{t('ر.ع')}</span>
                                    </div>
                                </div>
                            )}
                        </Card>
                    )}
                    {doc.business && (
                        <Card className="space-y-2 p-5 text-[13px]" data-testid="subscription">
                            <div className="text-[12px] text-[#6b7280]">{t('النشاط واشتراكه في المنصة')}</div>
                            <SmartLink
                                routeName="super-admin.businesses.show"
                                href={route('super-admin.businesses.show', doc.business.id)}
                                className="font-medium text-[#111] underline-offset-2 hover:underline"
                            >
                                {doc.business.name ?? '—'}
                            </SmartLink>
                            {doc.subscription && (
                                <>
                                    <div className="text-[12.5px] text-[#374151]">
                                        {doc.subscription.plan ?? '—'} · {doc.subscription.status ?? '—'}
                                        <br />
                                        {t('الاشتراك الحالي: من :s إلى :e', { s: doc.subscription.starts_at, e: doc.subscription.ends_at })}
                                    </div>
                                    {doc.subscription.pending_invoice ? (
                                        <div className="rounded-[8px] bg-[#fffbeb] px-3 py-2 text-[12.5px] text-[#92400e]">
                                            {t('فاتورة تجديد :n بانتظار السداد — :amount ر.ع · :payment', {
                                                n: doc.subscription.pending_invoice,
                                                amount: doc.subscription.pending_amount ?? '',
                                                payment: doc.subscription.pending_payment ?? '',
                                            })}
                                        </div>
                                    ) : (
                                        <div className="text-[12px] text-[#6b7280]">{t('لا فاتورة تجديد بانتظار السداد')}</div>
                                    )}
                                    <p className="text-[12px] text-[#6b7280]">
                                        {t('توقيع العقد لا يمدد الاشتراك — يمتد عند سداد فاتورة التجديد من صفحة النشاط.')}
                                    </p>
                                </>
                            )}
                        </Card>
                    )}
                    {doc.renewals.length > 0 && (
                        <Card className="space-y-1 p-5 text-[13px]">
                            <div className="text-[12px] text-[#6b7280]">{t('عقود التجديد')}</div>
                            {doc.renewals.map((r) => (
                                <div key={r.id} className="flex items-center justify-between gap-2">
                                    <SmartLink routeName="super-admin.documents.show" href={r.url} dir="ltr" className="font-mono text-[12.5px] hover:underline">
                                        {r.number}
                                    </SmartLink>
                                    <span className="text-[12px] text-[#6b7280]">{t(r.state_label)}</span>
                                </div>
                            ))}
                        </Card>
                    )}
                    {doc.notes && (
                        <Card className="p-5 text-[13px]">
                            <div className="text-[12px] text-[#6b7280]">{t('ملاحظة داخلية')}</div>
                            <p className="whitespace-pre-line text-[#111]">{doc.notes}</p>
                        </Card>
                    )}
                </div>
            </div>

            {cancelling && <CancelDialog id={doc.id} onClose={() => setCancelling(false)} />}
        </PlatformLayout>
    );
}

function CancelDialog({ id, onClose }: { id: number; onClose: () => void }) {
    const t = useTranslate();
    const form = useForm({ reason: '' });

    return (
        <Dialog open onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="max-w-md">
                <DialogHeader>
                    <DialogTitle>{t('إلغاء رابط المستند')}</DialogTitle>
                </DialogHeader>
                <form
                    className="space-y-4 px-5 pb-5"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post(route('super-admin.documents.cancel', id), { onSuccess: () => onClose() });
                    }}
                >
                    <p className="text-[13px] text-[#6b7280]">{t('لن يقبل الرابط التوقيع بعد الإلغاء، ويبقى المستند في السجل.')}</p>
                    <Field label="السبب" error={form.errors.reason}>
                        <Input value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} />
                    </Field>
                    <div className="flex justify-end gap-2">
                        <Button type="button" variant="ghost" onClick={onClose}>
                            {t('تراجع')}
                        </Button>
                        <Button type="submit" variant="danger" loading={form.processing}>
                            {t('إلغاء الرابط')}
                        </Button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}
