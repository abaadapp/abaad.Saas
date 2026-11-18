import { useState } from 'react';
import { ChevronDown, RotateCw } from 'lucide-react';
import { Button } from '@/Components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import type { OutboxEntry, SyncedSale } from '@/hooks/usePosCart';
import { useTranslate } from '@/lib/i18n';
import { localNumber, reviewOf, stateOf, type SaleState } from '@/lib/outbox';
import { cn } from '@/lib/utils';

/**
 * ‏قائمةُ البيعات المعلّقة على هذا الجهاز — بحال كلٍّ منها وآخر محاولة.
 *
 * بيعةٌ هنا اكتملت عند الصندوق وقُبض ثمنُها. فلا زرَّ يحذفها، ولا يتغيّر مبلغُها
 * ولا أصنافُها: ما يحتاج يدًا (ردّه الخادمُ، أو تكرّر عليه خطؤه) يُعاد إرسالُه
 * بمعرّفه نفسِه بعد أن يُصلَح السبب — فإن كان الخادمُ كتبها ردّ فاتورتَها ولم
 * يكتبها ثانيةً. والتسويةُ الماليّة (بيعةٌ لا تُقبل أبدًا) قرارٌ لم يُتّخذ بعد.
 *
 * ولا يُعرض هنا إلّا ما لمتجر الجلسة (`usePosCart.outbox`).
 */

const STATE_LABEL: Record<SaleState | 'synced', string> = {
    pending: 'معلّقة',
    retrying: 'معلّقة — تُعاد آليًّا',
    sending: 'قيد الإرسال',
    signin: 'بانتظار تسجيل الدخول',
    held: 'مرفوضة',
    stuck: 'يتكرر خطأ الخادم',
    synced: 'تمت مزامنتها',
};

const STATE_TONE: Record<SaleState | 'synced', string> = {
    pending: 'bg-[#fffbeb] text-[#b45309]',
    retrying: 'bg-[#fffbeb] text-[#b45309]',
    sending: 'bg-[#eff6ff] text-[#1d4ed8]',
    signin: 'bg-[#fef2f2] text-[#b91c1c]',
    held: 'bg-[#fef2f2] text-[#b91c1c]',
    stuck: 'bg-[#fef2f2] text-[#b91c1c]',
    synced: 'bg-[#ecfdf5] text-[#047857]',
};

/** ‏ما يُفعل بالبيعة — من الحقل الذي ردّه الخادم، لا من نصّ رسالته */
export function adviceFor(entry: OutboxEntry): string {
    const fields = entry.held?.fields ?? [];
    const has = (prefix: string) => fields.some((f) => f === prefix || f.startsWith(`${prefix}.`));
    if (entry.stuck) return 'يتكرر خطأ الخادم في هذه البيعة وحدها، والبيعات المستقلة تُرسل. أعد الإرسال بعد قليل؛ إن بقي الخطأ فأبلغ الدعم برقمها المحلي.';
    if (has('items')) return 'المخزون أو الصنف: أضف الكمية الناقصة أو أعد تفعيل الصنف من «المنتجات»، ثم أعد الإرسال.';
    if (has('coupon_code')) return 'الكوبون لم يعد صالحًا لهذه البيعة: مدّد صلاحيته أو عدد مراته من «الكوبونات»، ثم أعد الإرسال.';
    if (has('block_override_reason') || has('customer')) return 'العميل موقوف: ارفع الإيقاف من بطاقته، ثم أعد الإرسال.';
    if (has('credit') || has('paid_now')) return 'حد البيع الآجل للعميل: راجعه من بطاقته، ثم أعد الإرسال.';
    if (has('customer_language')) return 'اختر لغة رسائل العميل من بطاقته، ثم أعد الإرسال.';
    if (has('resume_id')) return 'السلة المعلقة أُتمّت من جهاز آخر — قد تكون بيعتين لسلة واحدة. تحتاج تسوية معتمدة، فلا تُعِد إرسالها قبل المراجعة.';

    return 'راجع السبب أعلاه. إعادة الإرسال آمنة: إن كان الخادم سجّلها ردّ فاتورتها ولم يكتبها ثانيةً.';
}

type Item = { name?: string; qty?: number; price?: number };

export default function HeldSalesDialog({
    open,
    onOpenChange,
    sales,
    onRetry,
    money,
    sending = new Set(),
    sessionLost = false,
    canRetry = true,
    synced = [],
    persisted = null,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** بيعاتُ هذا المتجر في الطابور */
    sales: OutboxEntry[];
    onRetry: (uuid: string) => Promise<void>;
    money: (v: number) => string;
    sending?: ReadonlySet<string>;
    sessionLost?: boolean;
    /** متّصلٌ وبجلسةٍ تملك البيع — وإلّا فإعادةُ الإرسال لا تصل */
    canRetry?: boolean;
    synced?: SyncedSale[];
    /** ‏أذن المتصفّحُ ألّا يمحو التخزين حين تضيق المساحة — `null` لم يُسأل أو لا يعرفه */
    persisted?: boolean | null;
}) {
    const t = useTranslate();
    const [busy, setBusy] = useState<string | null>(null);
    const [openId, setOpenId] = useState<string | null>(null);

    const retry = async (uuid: string) => {
        // ‏ضغطتان لا تصيران طلبين: الزرُّ يُقفل حتى يعود الجواب، والطابورُ يرفض ما يُرسل الآن
        if (busy !== null) return;
        setBusy(uuid);
        try {
            await onRetry(uuid);
        } finally {
            setBusy(null);
        }
    };

    const states = sales.map((sale) => ({ sale, state: stateOf(sale, { sending, sessionLost }) }));
    const needs = (s: SaleState) => s === 'held' || s === 'stuck';
    const ordered = [...states.filter((x) => needs(x.state)), ...states.filter((x) => !needs(x.state))];
    const time = (at: number) => new Date(at).toLocaleString();

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-w-lg" data-testid="held-sales">
                <DialogHeader>
                    <DialogTitle>{t('البيعات المعلقة على هذا الجهاز')}</DialogTitle>
                    <DialogDescription>
                        {t('كل بيعة هنا اكتملت عند الصندوق. لا تُحذف ولا يتغير مبلغها — ما يحتاج مراجعة يُعاد إرساله بعد إصلاح سببه، ولا يُكتب مرتين.')}
                    </DialogDescription>
                </DialogHeader>

                <ul className="max-h-[60vh] space-y-2 overflow-y-auto px-5 pb-3">
                    {ordered.length === 0 && <li className="py-6 text-center text-sm text-gray-500">{t('لا بيعات معلقة على هذا الجهاز.')}</li>}
                    {ordered.map(({ sale, state }) => {
                        const items = Array.isArray(sale.payload.items) ? (sale.payload.items as Item[]) : [];
                        const total = typeof sale.payload.expected_total === 'number' ? sale.payload.expected_total : null;
                        const customer = typeof sale.payload.customer === 'string' ? sale.payload.customer : '';
                        const method = typeof sale.payload.payment_method === 'string' ? sale.payload.payment_method : '';
                        const reason = sale.held?.error || (state === 'stuck' || state === 'retrying' ? sale.last?.error : '') || '';
                        const resendable = needs(state);
                        const expanded = openId === sale.uuid;
                        const review = reviewOf(sale);

                        return (
                            <li
                                key={sale.uuid}
                                data-testid="held-sale"
                                data-state={state}
                                className={cn('rounded-xl border p-3 text-sm', needs(state) ? 'border-[#fecaca] bg-[#fef2f2]' : 'border-gray-200 bg-white')}
                            >
                                <div className="flex items-center justify-between gap-2">
                                    <span className="font-bold text-gray-900" dir="ltr">
                                        #{localNumber(sale.uuid)} · {time(sale.at)}
                                    </span>
                                    <span className={cn('rounded-full px-2 py-0.5 text-xs font-bold', STATE_TONE[state])} data-testid="sale-state">
                                        {t(STATE_LABEL[state])}
                                    </span>
                                </div>
                                <p className="mt-1 truncate text-gray-700">
                                    {customer && `${customer} · `}
                                    {items.map((i) => `${i.name ?? ''}${(i.qty ?? 1) > 1 ? ` ×${i.qty}` : ''}`).join('، ')}
                                </p>
                                {total !== null && (
                                    <p className="mt-1 text-gray-900">
                                        {t('الإجمالي المحفوظ')}: <span className="font-bold">{money(total)}</span>
                                    </p>
                                )}
                                {reason && <p className="mt-1 font-medium text-[#b91c1c]">{reason}</p>}
                                {needs(state) && (review.financial || review.manager) && (
                                    <p className="mt-1 flex flex-wrap gap-1">
                                        {review.financial ? (
                                            <span className="rounded-full bg-[#7f1d1d] px-2 py-0.5 text-xs font-bold text-white" data-testid="needs-finance">
                                                {t('تحتاج مراجعة مالية')}
                                            </span>
                                        ) : (
                                            <span className="rounded-full bg-[#fff7ed] px-2 py-0.5 text-xs font-bold text-[#9a3412]" data-testid="needs-manager">
                                                {t('تحتاج مسؤولًا مخوّلًا')}
                                            </span>
                                        )}
                                    </p>
                                )}
                                <p className="mt-1 text-xs text-gray-500" data-testid="server-check">
                                    {sale.checked
                                        ? t('سُئل الخادم :time ولم يجدها مسجّلة', { time: time(sale.checked.at) })
                                        : t('لم يؤكدها الخادم بعد')}
                                </p>
                                {sale.last && (
                                    <p className="mt-1 text-xs text-gray-500" data-testid="last-attempt">
                                        {t('آخر محاولة')}: {time(sale.last.at)} — {sale.last.status ? `${sale.last.status}` : t('لا اتصال')}
                                        {(sale.attempts ?? 0) > 0 && ` · ${t(':n محاولات', { n: sale.attempts ?? 0 })}`}
                                        {state === 'retrying' && sale.nextAt ? ` · ${t('التالية')} ${new Date(sale.nextAt).toLocaleTimeString()}` : ''}
                                    </p>
                                )}

                                <div className="mt-2 flex flex-wrap justify-end gap-2">
                                    <Button size="sm" variant="ghost" onClick={() => setOpenId(expanded ? null : sale.uuid)} aria-expanded={expanded}>
                                        <ChevronDown className={cn('transition-transform', expanded && 'rotate-180')} />
                                        {t('مراجعة العملية')}
                                    </Button>
                                    {resendable && (
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            disabled={busy !== null || !canRetry || sending.has(sale.uuid)}
                                            onClick={() => void retry(sale.uuid)}
                                        >
                                            <RotateCw className={busy === sale.uuid ? 'animate-spin' : undefined} />
                                            {t('أعد الإرسال')}
                                        </Button>
                                    )}
                                </div>

                                {expanded && (
                                    <div className="mt-2 space-y-2 rounded-lg bg-white/70 p-2 text-xs" data-testid="sale-review">
                                        <table className="w-full">
                                            <tbody>
                                                {items.map((i, n) => (
                                                    <tr key={n}>
                                                        <td className="py-0.5">{i.name}</td>
                                                        <td className="py-0.5 text-center">×{i.qty ?? 1}</td>
                                                        <td className="py-0.5 text-end">{typeof i.price === 'number' ? money(i.price) : ''}</td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                        {method && (
                                            <p>
                                                {t('وسيلة الدفع')}: {method}
                                            </p>
                                        )}
                                        <p className="text-gray-600" dir="ltr">
                                            {sale.uuid}
                                        </p>
                                        {needs(state) && <p className="font-medium text-gray-800">{t(adviceFor(sale))}</p>}
                                        {review.safe && <p className="text-gray-700">{t('إعادة الإرسال آمنة: بالمعرّف نفسه، فلا تُكتب البيعة مرتين ولا يُخصم المخزون أو النقاط مرتين.')}</p>}
                                        {needs(state) && !review.useful && <p className="text-gray-700">{t('لن تُقبل إن أُعيدت قبل أن يُصلَح سببها.')}</p>}
                                        {review.financial && (
                                            <p className="font-medium text-[#7f1d1d]" data-testid="finance-note">
                                                {t('قُبض ثمنها على غير ما يقبله الخادم الآن. لا تسوية ولا شطب ولا تعديل مبلغ من الصندوق — قرارها لصاحب المتجر أو مسؤول مالي مخوّل.')}
                                            </p>
                                        )}
                                        {!canRetry && resendable && <p className="text-[#b91c1c]">{t('لا تُعاد الآن: لا اتصال، أو الجلسة لا تملك البيع.')}</p>}
                                    </div>
                                )}
                            </li>
                        );
                    })}
                </ul>

                {/*
                    ‏وما لا يُوعد به: البيعةُ محفوظةٌ في هذا المتصفّح وحده إلى أن يؤكّدها
                    الخادم — بلا تخويف، وبلا وعدٍ بما لا يملكه الصندوق.
                */}
                {ordered.length > 0 && (
                    <p className="px-5 pb-3 text-xs text-gray-600" data-testid="device-note">
                        {t('هذه البيعات محفوظة في هذا المتصفح وحده إلى أن يؤكدها الخادم. إن مُسحت بيانات المتصفح أو تلف الجهاز قبل ذلك فلا يمكن استعادتها، فأبقِ المتصفح كما هو حتى تكتمل المزامنة.')}
                        {persisted === true && ` ${t('وقد أذن المتصفح بحفظها حفظًا دائمًا، فلا يمحوها إن ضاقت مساحة الجهاز.')}`}
                    </p>
                )}

                {synced.length > 0 && (
                    <div className="border-t px-5 pt-3 pb-5" data-testid="synced-sales">
                        <p className="mb-2 text-xs font-bold text-gray-700">{t('تمت مزامنتها في هذه الجلسة')}</p>
                        <ul className="max-h-40 space-y-1 overflow-y-auto text-xs">
                            {synced.map((s) => {
                                // ‏ومبلغٌ كتبه الخادمُ غيرَ ما قُبض يُقال — لا يمرّ صامتًا
                                const differs = s.total !== null && s.expected !== null && Math.abs(s.total - s.expected) > 0.0005;

                                return (
                                    <li key={s.uuid} className="flex items-center justify-between gap-2" data-testid="synced-sale">
                                        <span dir="ltr">#{localNumber(s.uuid)}</span>
                                        <span className={cn('rounded-full px-2 py-0.5 font-bold', STATE_TONE.synced)}>{t(STATE_LABEL.synced)}</span>
                                        <span className="font-medium">{s.invoice ?? ''}</span>
                                        {differs && (
                                            <span className="text-[#b91c1c]" data-testid="synced-differs">
                                                {t('كتبه الخادم :total لا :expected — راجعه', { total: money(s.total ?? 0), expected: money(s.expected ?? 0) })}
                                            </span>
                                        )}
                                    </li>
                                );
                            })}
                        </ul>
                    </div>
                )}
            </DialogContent>
        </Dialog>
    );
}
