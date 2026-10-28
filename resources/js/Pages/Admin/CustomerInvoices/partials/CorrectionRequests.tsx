import { useState } from 'react';
import { router } from '@inertiajs/react';
import { Check, ShieldCheck, Undo2, X } from 'lucide-react';
import SmartLink from '@/Components/SmartLink';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';

/** طلبُ تصحيحٍ كما يرسله الخادم — `FinancialCorrections::present` */
export interface CorrectionRequest {
    id: number;
    status: 'pending' | 'approved' | 'rejected' | 'withdrawn' | 'stale';
    reason: string;
    invoice: { id: number; number: string | null };
    summary: {
        kind: string;
        number: string | null;
        money: {
            total_before: number;
            total_after: number;
            difference: number;
            tax_before: number;
            tax_after: number;
            paid: number;
            outstanding_after: number;
            customer_credit: number;
        };
        changes: { field: string; label: string; before: string | null; after: string | null }[];
        customer_changed: boolean;
        period: { mode: string | null; reversal_date: string | null; entry_date: string | null };
    };
    requested_by: string | null;
    requested_at: string | null;
    decided_by: string | null;
    decided_at: string | null;
    decision_note: string | null;
    revision: number | null;
    may_decide: boolean;
    may_withdraw: boolean;
}

/**
 * طلباتُ التصحيح الماليّ — ما طُلب، ومن طلبه، وما قُرّر فيه.
 *
 * المعتمدُ يرى زرَّي «اعتماد» و«رفض» لطلبِ غيره وحده (`may_decide`)، ومقدّمُه
 * يرى «سحب». والخادمُ يعيد السؤالَ كلَّه عند الضغط — هذه لا تقرّر شيئًا.
 */
export default function CorrectionRequests({
    requests,
    m,
    showInvoice = false,
}: {
    requests: CorrectionRequest[];
    m: (v: number) => string;
    showInvoice?: boolean;
}) {
    const t = useTranslate();
    const [rejecting, setRejecting] = useState<number | null>(null);
    const [why, setWhy] = useState('');
    const [busy, setBusy] = useState(false);

    if (requests.length === 0) {
        return null;
    }

    const status: Record<CorrectionRequest['status'], { label: string; tone: string }> = {
        pending: { label: t('بانتظار الاعتماد'), tone: 'bg-[#eff6ff] text-[#1d4ed8]' },
        approved: { label: t('اعتُمد ونُفّذ'), tone: 'bg-[#ecfdf5] text-[#047857]' },
        rejected: { label: t('رُفض'), tone: 'bg-[#fef2f2] text-[#b91c1c]' },
        withdrawn: { label: t('سحبه مقدّمه'), tone: 'bg-[#f3f4f6] text-[#4b5563]' },
        stale: { label: t('تغيّرت الفاتورة — لم يُنفَّذ'), tone: 'bg-[#fffbeb] text-[#92400e]' },
    };

    const post = (name: string, id: number, data: Record<string, string> = {}) => {
        setBusy(true);
        router.post(route(name, id), data, {
            preserveScroll: true,
            onFinish: () => setBusy(false),
            onSuccess: () => {
                setRejecting(null);
                setWhy('');
            },
        });
    };

    const diff = (v: number) => (v > 0 ? `+${m(v)}` : m(v));

    return (
        <Card className="p-4" data-testid="correction-requests">
            <h2 className="mb-3 flex items-center gap-2 font-bold text-[#111]">
                <ShieldCheck className="size-4" />
                {t('طلبات التصحيح المالي')}
            </h2>
            <ul className="space-y-3 text-[13px]">
                {requests.map((r) => (
                    <li key={r.id} className="space-y-2 rounded-lg border border-[var(--ui-border,#e8e8e8)] p-3" data-testid={`correction-${r.id}`}>
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <span className="flex flex-wrap items-center gap-2">
                                <span className={cn('rounded-full px-2 py-0.5 text-[11px] font-medium', status[r.status].tone)} data-testid="correction-status">
                                    {status[r.status].label}
                                </span>
                                {showInvoice && (
                                    <SmartLink
                                        routeName="admin.customerInvoices.show"
                                        href={route('admin.customerInvoices.show', r.invoice.id)}
                                        className="font-semibold text-[#111] underline-offset-2 hover:underline"
                                    >
                                        <bdi>{r.invoice.number ?? r.summary.number ?? `#${r.invoice.id}`}</bdi>
                                    </SmartLink>
                                )}
                            </span>
                            <span className="text-[12px] text-[#6b7280]">
                                {t('طلبه')} <bdi>{r.requested_by ?? '—'}</bdi> · <span dir="ltr">{r.requested_at}</span>
                            </span>
                        </div>

                        <p className="tabular-nums" dir="auto">
                            <span className="font-medium">{t('الإجمالي')}:</span>{' '}
                            <span dir="ltr">
                                {m(r.summary.money.total_before)} ← {m(r.summary.money.total_after)} ({diff(r.summary.money.difference)})
                            </span>
                        </p>
                        {r.summary.money.paid > 0 && (
                            <p className="text-[12px] text-[#4b4b4b]">
                                {t('المقبوض')}: <span dir="ltr">{m(r.summary.money.paid)}</span>
                                {r.summary.money.customer_credit > 0
                                    ? ` — ${t('يصير رصيدًا دائنًا للعميل')}: ${m(r.summary.money.customer_credit)}`
                                    : ` — ${t('الباقي على العميل')}: ${m(r.summary.money.outstanding_after)}`}
                            </p>
                        )}
                        {r.summary.changes.length > 0 && (
                            <ul className="space-y-0.5 text-[12px] text-[#4b4b4b]">
                                {r.summary.changes.map((c) => (
                                    <li key={c.field}>
                                        <span className="font-medium">{c.label}:</span> <bdi>{c.before ?? '—'}</bdi> ← <bdi>{c.after ?? '—'}</bdi>
                                    </li>
                                ))}
                            </ul>
                        )}
                        <p className="text-[12px]">
                            <span className="font-medium">{t('السبب')}:</span> <bdi>{r.reason}</bdi>
                        </p>

                        {r.status !== 'pending' && (
                            <p className="text-[12px] text-[#6b7280]" data-testid="correction-decision">
                                <bdi>{r.decided_by ?? '—'}</bdi> · <span dir="ltr">{r.decided_at}</span>
                                {r.revision !== null && ` — ${t('التعديل')} ${r.revision}`}
                                {r.decision_note && (
                                    <>
                                        {' — '}
                                        <bdi>{r.decision_note}</bdi>
                                    </>
                                )}
                            </p>
                        )}

                        {r.status === 'pending' && r.may_decide && rejecting !== r.id && (
                            <div className="flex flex-wrap gap-2">
                                <Button type="button" size="sm" disabled={busy} onClick={() => post('admin.customerInvoices.corrections.approve', r.id)} data-testid="correction-approve">
                                    <Check />
                                    {t('اعتماد وتنفيذ')}
                                </Button>
                                <Button type="button" size="sm" variant="outline" disabled={busy} onClick={() => setRejecting(r.id)} data-testid="correction-reject">
                                    <X />
                                    {t('رفض')}
                                </Button>
                            </div>
                        )}

                        {rejecting === r.id && (
                            <div className="space-y-2">
                                <textarea
                                    value={why}
                                    onChange={(e) => setWhy(e.target.value)}
                                    maxLength={255}
                                    rows={2}
                                    placeholder={t('سبب الرفض — يراه مقدّم الطلب')}
                                    className="w-full rounded-[10px] border border-[#e5e7eb] p-2 text-[13px]"
                                    data-testid="correction-reject-reason"
                                />
                                <div className="flex flex-wrap gap-2">
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="danger"
                                        disabled={busy || why.trim().length < 3}
                                        onClick={() => post('admin.customerInvoices.corrections.reject', r.id, { reason: why })}
                                        data-testid="correction-reject-confirm"
                                    >
                                        {t('تأكيد الرفض')}
                                    </Button>
                                    <Button type="button" size="sm" variant="ghost" onClick={() => setRejecting(null)}>
                                        {t('إلغاء')}
                                    </Button>
                                </div>
                            </div>
                        )}

                        {r.status === 'pending' && r.may_withdraw && (
                            <Button type="button" size="sm" variant="ghost" disabled={busy} onClick={() => post('admin.customerInvoices.corrections.withdraw', r.id)} data-testid="correction-withdraw">
                                <Undo2 />
                                {t('سحب الطلب')}
                            </Button>
                        )}

                        {r.status === 'pending' && !r.may_decide && !r.may_withdraw && (
                            <p className="text-[12px] text-[#6b7280]">{t('ينتظر موظفًا يملك «اعتماد تصحيح مالي» غير مقدّمه.')}</p>
                        )}
                    </li>
                ))}
            </ul>
        </Card>
    );
}
