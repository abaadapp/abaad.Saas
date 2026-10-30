import { useState } from 'react';
import { ChevronDown, History } from 'lucide-react';
import SmartLink from '@/Components/SmartLink';
import { Card } from '@/Components/ui/card';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';

/** مستندُ تصحيحٍ أصدره تعديل — يُفتح وحده */
export interface RevisionDocument {
    kind: 'credit' | 'debit';
    number: string;
    amount: number;
    url: string;
}

/** تعديلٌ واحد كما يرسله الخادم — `CustomerInvoiceController::revisionsOf` */
export interface InvoiceRevision {
    revision: number;
    kind: 'draft' | 'issued' | 'paid';
    /** أساسُ التصحيح بعد الإصدار مقروءًا — و`null` للمسودّة */
    basis: string | null;
    reason: string | null;
    at: string | null;
    by: string | null;
    total_before: number;
    total_after: number;
    tax_before: number;
    tax_after: number;
    paid_at_edit: number;
    fields: { field: string; label: string; before: string | null; after: string | null }[];
    lines: {
        change: 'added' | 'removed' | 'changed';
        before: { description: string; quantity: number; unit_price: number } | null;
        after: { description: string; quantity: number; unit_price: number } | null;
    }[];
    customer_credit: number;
    documents: RevisionDocument[];
}

/**
 * سجلُّ تعديلات الفاتورة وتصحيحاتها — ما كانت عليه، ومن غيّرها، ومتى، ولماذا، وبأيّ مستند.
 *
 * يُرسم لمن يُمنح قراءته وحده (`customerInvoice.history`)، ولا مقبضَ فيه
 * يحذف أو يعدّل: السجلُّ يُكتب مرّةً (`CustomerInvoiceRevision`). والمبلغان
 * مبلغُ ما على الورقة قبل التصحيح وبعده — والفاتورةُ نفسُها كما صدرت.
 */
export default function InvoiceRevisions({ revisions, m }: { revisions: InvoiceRevision[]; m: (v: number) => string }) {
    const t = useTranslate();
    const [open, setOpen] = useState<number | null>(null);

    if (revisions.length === 0) {
        return null;
    }

    const kind: Record<string, string> = { draft: t('تعديل مسودة'), issued: t('تصحيح'), paid: t('تصحيح بعد الدفع') };

    return (
        <Card className="p-4" data-testid="invoice-revisions">
            <h2 className="mb-3 flex items-center gap-2 font-bold text-[#111]">
                <History className="size-4" />
                {t('سجل التعديلات والتصحيحات')}
            </h2>
            <ul className="space-y-2 text-[13px]">
                {revisions.map((r) => (
                    <li key={r.revision} className="rounded-lg border border-[var(--ui-border,#e8e8e8)]" data-testid="invoice-revision">
                        <button
                            type="button"
                            className="flex w-full flex-wrap items-center justify-between gap-2 p-3 text-start"
                            onClick={() => setOpen((v) => (v === r.revision ? null : r.revision))}
                            aria-expanded={open === r.revision}
                        >
                            <span className="font-semibold">
                                {kind[r.kind] ?? r.kind} {r.revision}
                                {r.basis && <span className="ms-1 font-normal text-[#6b7280]">— {r.basis}</span>}
                            </span>
                            <span className="flex items-center gap-2 text-[12px] text-[#6b7280]">
                                <bdi>{r.by ?? '—'}</bdi>
                                <span dir="ltr">{r.at}</span>
                                <span dir="ltr" className="tabular-nums">
                                    {m(r.total_before)} ← {m(r.total_after)}
                                </span>
                                <ChevronDown className={cn('size-4 transition-transform', open === r.revision && 'rotate-180')} />
                            </span>
                        </button>
                        {r.reason && (
                            <p className="px-3 pb-2 text-[12px] text-[#4b4b4b]">
                                {t('السبب')}: <bdi>{r.reason}</bdi>
                            </p>
                        )}
                        {r.documents.length > 0 && (
                            <p className="flex flex-wrap gap-3 px-3 pb-2 text-[12px]" data-testid="invoice-revision-documents">
                                {r.documents.map((d) => (
                                    <SmartLink
                                        key={d.number}
                                        routeName={d.kind === 'debit' ? 'admin.customerInvoices.debitNotes.show' : 'admin.customerInvoices.creditNotes.show'}
                                        href={d.url}
                                        className="text-[#6d28d9] hover:underline"
                                    >
                                        {d.kind === 'debit' ? t('إشعار مدين') : t('إشعار دائن')} <span className="font-mono">{d.number}</span> (
                                        {d.kind === 'debit' ? '+' : '−'}
                                        {m(d.amount)})
                                    </SmartLink>
                                ))}
                            </p>
                        )}
                        {open === r.revision && (
                            <div className="space-y-2 border-t border-[var(--ui-border,#e8e8e8)] p-3 text-[12px] text-[#4b4b4b]">
                                {r.fields.map((f) => (
                                    <p key={f.field}>
                                        <span className="font-medium">{f.label}:</span> <bdi>{f.before ?? '—'}</bdi> ← <bdi>{f.after ?? '—'}</bdi>
                                    </p>
                                ))}
                                {r.lines.map((l, i) => (
                                    <p key={i}>
                                        {l.change === 'added' ? '+ ' : l.change === 'removed' ? '− ' : '≈ '}
                                        <bdi>{(l.after ?? l.before)?.description}</bdi>
                                        {l.before && l.after && (
                                            <span dir="ltr" className="ms-1 tabular-nums">
                                                ({l.before.quantity} × {l.before.unit_price} ← {l.after.quantity} × {l.after.unit_price})
                                            </span>
                                        )}
                                    </p>
                                ))}
                                <p>
                                    <span className="font-medium">{t('الضريبة')}:</span>{' '}
                                    <span dir="ltr" className="tabular-nums">
                                        {m(r.tax_before)} ← {m(r.tax_after)}
                                    </span>
                                </p>
                                {r.paid_at_edit > 0 && (
                                    <p>
                                        <span className="font-medium">{t('المقبوض ساعة التعديل')}:</span> {m(r.paid_at_edit)}
                                    </p>
                                )}
                                {r.customer_credit > 0 && (
                                    <p>
                                        <span className="font-medium">{t('صار رصيدًا دائنًا للعميل')}:</span> {m(r.customer_credit)}
                                    </p>
                                )}
                            </div>
                        )}
                    </li>
                ))}
            </ul>
        </Card>
    );
}
