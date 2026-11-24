import { useEffect, useState } from 'react';
import { AlertTriangle } from 'lucide-react';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { money, number } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import type { Currency } from '@/types';

/**
 * فواتيرُ عملاءٍ قديمةٌ فيها صنفُ هذا البوتيك ولم تدخل حسابَه — للمراجعة وحدها.
 *
 * صدرت قبل أن تُحفظ حصّةُ البوتيك على بنود الفاتورة. فتُعرض هنا كما كُتبت: لا مستحقَّ يُحسب ولا نسبةُ اليوم تُستعمل
 * (`BoutiqueInvoiceReview`). ومن لا يقرأ مالَ البوتيك يردّه البابُ فلا يُرسم شيء.
 */

type PaymentState = 'paid' | 'partial' | 'unpaid' | 'credited';

export interface ReviewRow {
    id: number;
    invoice_id: number;
    number: string;
    issued_at: string;
    source: 'manual' | 'whatsapp';
    description: string;
    quantity: number;
    unit_price: number;
    discount: number;
    amount: number;
    payment: PaymentState;
    credited: number;
    boutique_today: string | null;
    evidence: { boutique: string | null; rate: number | null; order: string; at: string } | null;
    url: string | null;
}

interface Estimate {
    reliable: { lines: number; gross: number; commission: number; net: number };
    manual: Record<'before_only' | 'conflict' | 'none' | 'credited' | 'unpaid', { lines: number; amount: number }>;
    shop: { lines: number; amount: number };
}

/** لماذا لا يُحسب لها شيء — كلٌّ بسببه */
export const MANUAL_LABEL: Record<keyof Estimate['manual'], string> = {
    none: 'لا بيع محفوظ قبلها',
    before_only: 'لا بيع محفوظ بعدها',
    conflict: 'النسبة أو البوتيك قبلها غير ما بعدها',
    credited: 'عليها إشعار دائن بلا بنود',
    unpaid: 'لم يكتمل تحصيلها',
};

interface Review {
    /** المحتملُ غيرُ المسجَّل — حين يُطلب وحده */
    estimate?: Estimate | null;
    summary: { lines: number; invoices: number; amount: number };
    rows: ReviewRow[];
    page: number;
    last_page: number;
}

export const PAYMENT_LABEL: Record<PaymentState, string> = {
    paid: 'مدفوعة',
    partial: 'مدفوعة جزئيًا',
    unpaid: 'غير مدفوعة',
    credited: 'رُدّت بإشعار دائن',
};

const PAYMENT_VARIANT: Record<PaymentState, 'success' | 'warning' | 'neutral'> = {
    paid: 'success',
    partial: 'warning',
    unpaid: 'neutral',
    credited: 'neutral',
};

async function ask(params: Record<string, string | number>): Promise<Review | null> {
    try {
        const res = await fetch(route('admin.boutiques.invoiceReview', params), {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        });

        return res.ok ? ((await res.json()) as Review) : null;
    } catch {
        return null;
    }
}

export default function InvoiceReviewPanel({ boutiqueId, currency }: { boutiqueId: number; currency: Currency }) {
    const t = useTranslate();
    const [summary, setSummary] = useState<Review['summary'] | null>(null);
    const [open, setOpen] = useState(false);
    const [page, setPage] = useState(1);
    const [review, setReview] = useState<Review | null>(null);
    const [estimate, setEstimate] = useState<Estimate | null>(null);
    const [asking, setAsking] = useState(false);

    useEffect(() => {
        let live = true;
        ask({ boutique: boutiqueId, summary: 1 }).then((r) => live && setSummary(r?.summary ?? null));
        return () => {
            live = false;
        };
    }, [boutiqueId]);

    useEffect(() => {
        if (!open) return;
        let live = true;
        ask({ boutique: boutiqueId, page }).then((r) => live && setReview(r));
        return () => {
            live = false;
        };
    }, [open, page, boutiqueId]);

    const askEstimate = () => {
        setAsking(true);
        ask({ boutique: boutiqueId, summary: 1, estimate: 1 }).then((r) => {
            setEstimate(r?.estimate ?? null);
            setAsking(false);
        });
    };

    // لا شيءَ يُراجَع، أو لا إذنَ بالمال: لا يُرسم القسم
    if (!summary || summary.lines === 0) return null;

    const fmt = (v: number) => money(v, currency);
    // التاريخُ والرقمُ وسط سطرٍ عربيّ يُعزلان — وإلّا قُلب «2026-11-24» على الشاشة
    const ltr = (v: string) => `\u2066${v}\u2069`;
    const evidence = (r: ReviewRow) => {
        if (!r.evidence) return t('لا بيع محفوظ قبلها');
        return r.evidence.boutique === null
            ? t('صنف المتجر — :order في :at', { order: ltr(r.evidence.order), at: ltr(r.evidence.at) })
            : t(':boutique بنسبة :rate٪ — :order في :at', {
                boutique: r.evidence.boutique, rate: number(r.evidence.rate ?? 0, Number.isInteger(r.evidence.rate ?? 0) ? 0 : 2), order: ltr(r.evidence.order), at: ltr(r.evidence.at),
            });
    };

    return (
        <Card className="mb-5 overflow-hidden" data-testid="boutique-invoice-review">
            <div className="flex flex-col gap-3 border-b border-[var(--ui-border,#e8e8e8)] px-4 py-3 sm:flex-row sm:items-start sm:justify-between">
                <div className="min-w-0">
                    <h2 className="flex items-center gap-2 text-sm font-semibold text-[#111]">
                        <AlertTriangle className="size-4 shrink-0 text-[#b45309]" />
                        {t('فواتير عملاء قديمة لا تدخل حساب هذا البوتيك')}
                    </h2>
                    <p className="mt-1 text-[12px] leading-5 text-[#6b7280]">
                        {t(':lines بندًا في :invoices فاتورة، كُتبت بمبلغ :amount. صدرت قبل أن تُحفظ حصة البوتيك على بنود الفاتورة، فلا تدخل مبيعاته ولا مستحقه ولا أوراقه.', {
                            lines: number(summary.lines), invoices: number(summary.invoices), amount: fmt(summary.amount),
                        })}
                    </p>
                    <p className="text-[12px] leading-5 text-[#6b7280]">{t('للمراجعة فقط: لا يُحسب هنا مستحق ولا يُعدَّل شيء.')}</p>
                </div>
                <Button variant="outline" size="sm" className="min-h-[44px] shrink-0 sm:min-h-0" aria-expanded={open} onClick={() => setOpen(!open)}>
                    {t(open ? 'إخفاء البنود' : 'عرض البنود')}
                </Button>
            </div>

            {open && review && (
                <>
                    <div className="border-b border-[var(--ui-border,#e8e8e8)] px-4 py-3 text-[13px] leading-6" data-testid="review-estimate">
                        {estimate === null ? (
                            <Button variant="outline" size="sm" disabled={asking} onClick={askEstimate}>{t('احسب المحتمل غير المسجّل')}</Button>
                        ) : (
                            <>
                                <p>
                                    {t('معلوم صاحبها ونسبتها يومها: :lines بندًا بإجمالي :gross — عمولة :commission وصافٍ محتمل :net. رقم للقرار، لم يُسجَّل منه شيء.', {
                                        lines: number(estimate.reliable.lines), gross: fmt(estimate.reliable.gross), commission: fmt(estimate.reliable.commission), net: fmt(estimate.reliable.net),
                                    })}
                                </p>
                                <ul className="text-[12px] text-[#b45309]">
                                    {(Object.keys(MANUAL_LABEL) as (keyof Estimate['manual'])[]).filter((k) => estimate.manual[k].lines > 0).map((k) => (
                                        <li key={k}>{t('للمراجعة بيد — :why: :lines بندًا', { why: t(MANUAL_LABEL[k]), lines: number(estimate.manual[k].lines) })}</li>
                                    ))}
                                </ul>
                                {estimate.shop.lines > 0 && (
                                    <p className="text-[12px] text-[#6b7280]">{t('كانت للمتجر يومها: :lines بندًا', { lines: number(estimate.shop.lines) })}</p>
                                )}
                                <p className="text-[12px] text-[#6b7280]">{t('لا يُحسب بنسبة اليوم، ولا يُسجَّل ولا يُسدَّد من هنا.')}</p>
                            </>
                        )}
                    </div>
                    <ul className="divide-y divide-[var(--ui-border,#e8e8e8)]">
                        {review.rows.map((r) => (
                            <li key={r.id} className="grid grid-cols-1 gap-1 px-4 py-3 text-sm lg:grid-cols-[1.2fr_1.4fr_1fr_1.4fr] lg:gap-4">
                                <div className="min-w-0">
                                    {r.url ? (
                                        <a href={r.url} className="font-medium text-[#6d28d9] hover:underline">{r.number}</a>
                                    ) : (
                                        <span className="font-medium text-[#111]">{r.number}</span>
                                    )}
                                    <p className="text-[12px] text-[#6b7280]">
                                        {r.issued_at} · {t(r.source === 'whatsapp' ? 'طلب واتساب' : 'فاتورة يدوية')}
                                    </p>
                                </div>
                                <div className="min-w-0">
                                    <p className="truncate text-[#111]">{r.description}</p>
                                    <p className="text-[12px] text-[#6b7280] tabular-nums">
                                        {number(r.quantity)} × {fmt(r.unit_price)}
                                        {r.discount > 0 && <> − {fmt(r.discount)}</>}
                                    </p>
                                </div>
                                <div className="flex items-center gap-2 lg:block">
                                    <p className="font-semibold tabular-nums text-[#111]">{fmt(r.amount)}</p>
                                    <Badge variant={PAYMENT_VARIANT[r.payment]}>{t(PAYMENT_LABEL[r.payment])}</Badge>
                                    {r.credited > 0 && (
                                        <p className="text-[12px] text-[#b45309]">{t('إشعار دائن :amount — لا يُعرف أي بند رُدّ', { amount: fmt(r.credited) })}</p>
                                    )}
                                </div>
                                <div className="min-w-0 text-[12px] leading-5 text-[#6b7280]">
                                    <p>{t('بوتيك الصنف اليوم: :name', { name: r.boutique_today ?? t('لا بوتيك') })}</p>
                                    <p>{t('آخر بيع قبلها: :what', { what: evidence(r) })}</p>
                                </div>
                            </li>
                        ))}
                    </ul>
                    {review.last_page > 1 && (
                        <div className="flex items-center justify-between gap-2 border-t border-[var(--ui-border,#e8e8e8)] px-4 py-3 text-[13px]">
                            <span className="text-[#6b7280]">{t('صفحة :page من :last', { page: number(review.page), last: number(review.last_page) })}</span>
                            <div className="flex gap-2">
                                <Button variant="outline" size="sm" disabled={review.page <= 1} onClick={() => setPage(review.page - 1)}>{t('السابق')}</Button>
                                <Button variant="outline" size="sm" disabled={review.page >= review.last_page} onClick={() => setPage(review.page + 1)}>{t('التالي')}</Button>
                            </div>
                        </div>
                    )}
                </>
            )}
        </Card>
    );
}
