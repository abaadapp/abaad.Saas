import { useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import { Check, X } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import BackLink from '@/Components/BackLink';
import SmartLink from '@/Components/SmartLink';
import { Select } from '@/Components/Field';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';
import { number } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import { SUPPLY_EVENT_LABEL, SUPPLY_STATUS, TRANSFER_STATUS } from '@/lib/branchTransfers';
import type { PageProps } from '@/types';

interface Line {
    id: number;
    name: string;
    sku: string | null;
    requested: number;
    approved: number | null;
    source_branch_id: number | null;
    /** [معرّف الفرع] => الرصيد */
    balances: Record<string, number>;
}

interface Supply {
    id: number;
    number: string;
    status: string;
    progress: string;
    branch: string;
    branch_id: number | null;
    requested_by: string | null;
    created_at: string | null;
    notes: string | null;
    decision_reason: string | null;
    decided_by: string | null;
    decided_at: string | null;
    lines: Line[];
    transfers: { id: number; number: string; status: string; from: string; to: string }[];
    events: { id: number; action: string; by: string | null; note: string | null; at: string | null }[];
}

interface Props {
    supply: Supply;
    branches: { id: number; name: string; mine: boolean }[];
    can: { approve: boolean; reject: boolean; cancel: boolean };
    isOwner: boolean;
    isRequester: boolean;
}

/**
 * طلبُ تزويدٍ واحد — ورصيدُ كلّ صنفٍ في كلّ فرع، ليختار المعتمِدُ من يزوّد.
 *
 * والاعتمادُ يكتب سندَ نقلٍ معتمدًا لكلّ فرعٍ مزوِّد (`BranchSupply::approve`)،
 * ولا يحرّك رفًّا: البضاعةُ تخرج عند إرسال السند.
 */
export default function SupplyShow() {
    const { supply, branches, can, isOwner, isRequester, errors } = usePage<PageProps<Props>>().props;
    const t = useTranslate();
    const others = branches.filter((b) => b.id !== supply.branch_id);
    const best = (l: Line) =>
        others.reduce<{ id: number | null; qty: number }>((acc, b) => {
            const q = l.balances[String(b.id)] ?? 0;
            return q > acc.qty ? { id: b.id, qty: q } : acc;
        }, { id: null, qty: 0 }).id;

    const [plan, setPlan] = useState<Record<number, { source_branch_id: string; quantity: number }>>(() =>
        Object.fromEntries(supply.lines.map((l) => [l.id, { source_branch_id: String(best(l) ?? ''), quantity: l.requested }])),
    );
    const [reason, setReason] = useState('');
    const [selfApprove, setSelfApprove] = useState(false);
    const status = SUPPLY_STATUS[supply.progress];
    const error = (errors as Record<string, string>)?.supply;

    const post = (name: string, data: Record<string, unknown>) =>
        router.post(route(`admin.inventory.supply.${name}`, supply.id), data as never, { preserveScroll: true });

    return (
        <AdminLayout title={supply.number}>
            <BackLink routeName="admin.inventory.supply" href={route('admin.inventory.supply')} label="كل طلبات التزويد" />
            <PageHeader
                title={`${t('طلب تزويد')} ${supply.number}`}
                subtitle={`${supply.branch} — ${supply.requested_by ?? ''} — ${supply.created_at ?? ''}`}
                actions={<Badge variant={status?.tone ?? 'neutral'} data-testid="supply-status">{t(status?.label ?? supply.progress)}</Badge>}
            />

            {error && (
                <p className="mb-4 rounded-lg bg-[#fef2f2] px-3 py-2 text-[13px] text-[#b91c1c]" role="alert">
                    {error}
                </p>
            )}

            {(supply.notes || supply.decision_reason) && (
                <Card className="mb-4 space-y-1 p-4 text-[13px]">
                    {supply.notes && <p>{t('ملاحظات')}: {supply.notes}</p>}
                    {supply.decision_reason && <p>{t('السبب')}: {supply.decision_reason}</p>}
                </Card>
            )}

            <Card className="mb-4 overflow-x-auto">
                <Table>
                    <TableHeader>
                        <TableRow className="hover:bg-transparent">
                            <TableHead>{t('المنتج')}</TableHead>
                            <TableHead className="text-end">{t('الكمية المطلوبة')}</TableHead>
                            <TableHead>{t('الرصيد في الفروع')}</TableHead>
                            {can.approve ? (
                                <>
                                    <TableHead>{t('الفرع المزوّد')}</TableHead>
                                    <TableHead className="text-end">{t('يُعتمد')}</TableHead>
                                </>
                            ) : (
                                <TableHead className="text-end">{t('المعتمد')}</TableHead>
                            )}
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {supply.lines.map((l) => (
                            <TableRow key={l.id}>
                                <TableCell>
                                    <span className="block font-medium">{l.name}</span>
                                    {l.sku && <span className="text-[11px] text-[#9ca3af]">{l.sku}</span>}
                                </TableCell>
                                <TableCell className="text-end tabular-nums">{number(l.requested)}</TableCell>
                                <TableCell>
                                    <ul className="text-[12px] text-[#374151]" data-testid={`supply-balances-${l.id}`}>
                                        {branches.map((b) => (
                                            <li key={b.id} className={b.id === supply.branch_id ? 'text-[#9ca3af]' : ''}>
                                                {b.name}: <span className="tabular-nums">{number(l.balances[String(b.id)] ?? 0)}</span>
                                            </li>
                                        ))}
                                    </ul>
                                </TableCell>
                                {can.approve ? (
                                    <>
                                        <TableCell className="min-w-40">
                                            <Select
                                                value={plan[l.id]?.source_branch_id ?? ''}
                                                onChange={(e) => setPlan({ ...plan, [l.id]: { ...plan[l.id], source_branch_id: e.target.value } })}
                                                options={others.map((b) => ({ label: b.name, value: String(b.id) }))}
                                                placeholder="اختر الفرع"
                                                aria-label={t('الفرع المزوّد')}
                                            />
                                        </TableCell>
                                        <TableCell className="text-end">
                                            <Input
                                                type="number"
                                                min={0}
                                                max={l.requested}
                                                inputMode="numeric"
                                                aria-label={t('الكمية')}
                                                value={plan[l.id]?.quantity ?? 0}
                                                onChange={(e) =>
                                                    setPlan({ ...plan, [l.id]: { ...plan[l.id], quantity: Math.max(0, parseInt(e.target.value || '0', 10) || 0) } })
                                                }
                                                className="ms-auto w-24 tabular-nums"
                                            />
                                        </TableCell>
                                    </>
                                ) : (
                                    <TableCell className="text-end tabular-nums">{l.approved === null ? '—' : number(l.approved)}</TableCell>
                                )}
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            </Card>

            {(can.approve || can.reject || can.cancel) && (
                <Card className="mb-4 space-y-3 p-4" data-testid="supply-actions">
                    {can.approve && isRequester && isOwner && (
                        <label className="flex items-center gap-2 text-[13px]">
                            <input type="checkbox" checked={selfApprove} onChange={(e) => setSelfApprove(e.target.checked)} />
                            {t('أنا صاحب الطلب — أعتمده بنفسي بقرارٍ صريح يُسجَّل في السند')}
                        </label>
                    )}
                    {(can.reject || can.cancel) && (
                        <Input value={reason} onChange={(e) => setReason(e.target.value)} placeholder={t('سبب الرفض أو الإلغاء')} />
                    )}
                    <div className="flex flex-wrap gap-2">
                        {can.approve && (
                            <Button
                                data-testid="supply-approve"
                                onClick={() =>
                                    post('approve', {
                                        supply: Object.fromEntries(
                                            Object.entries(plan).map(([id, p]) => [id, { source_branch_id: p.source_branch_id || null, quantity: p.quantity }]),
                                        ),
                                        self_approve: selfApprove,
                                    })
                                }
                            >
                                <Check />
                                {t('اعتماد وتحويل إلى سند نقل')}
                            </Button>
                        )}
                        {can.reject && (
                            <Button variant="outline" disabled={reason.trim().length < 3} onClick={() => post('reject', { reason })}>
                                <X />
                                {t('رفض')}
                            </Button>
                        )}
                        {can.cancel && (
                            <Button variant="outline" disabled={reason.trim().length < 3} onClick={() => post('cancel', { reason })}>
                                {t('إلغاء طلب التزويد')}
                            </Button>
                        )}
                    </div>
                </Card>
            )}

            {supply.transfers.length > 0 && (
                <Card className="mb-4 p-4">
                    <h2 className="mb-2 text-[14px] font-bold">{t('سندات النقل الناتجة')}</h2>
                    <ul className="space-y-1.5 text-[13px]">
                        {supply.transfers.map((tr) => (
                            <li key={tr.id} className="flex flex-wrap items-center gap-2">
                                <SmartLink routeName="admin.inventory.transfers.show" href={route('admin.inventory.transfers.show', tr.id)} className="font-medium underline">
                                    {tr.number}
                                </SmartLink>
                                <span>
                                    {tr.from} ← {tr.to}
                                </span>
                                <Badge variant={TRANSFER_STATUS[tr.status]?.tone ?? 'neutral'}>{t(TRANSFER_STATUS[tr.status]?.label ?? tr.status)}</Badge>
                            </li>
                        ))}
                    </ul>
                </Card>
            )}

            <Card className="p-4">
                <h2 className="mb-3 text-[14px] font-bold">{t('سجل المراحل')}</h2>
                <ul className="space-y-2">
                    {supply.events.map((e) => (
                        <li key={e.id} className="flex flex-wrap gap-x-3 text-[13px]">
                            <span className="tabular-nums text-[#9ca3af]">{e.at}</span>
                            <span className="font-medium">{e.by ?? '—'}</span>
                            <span>{t(SUPPLY_EVENT_LABEL[e.action] ?? e.action)}</span>
                            {e.note && <span className="text-[#6b7280]">— {e.note}</span>}
                        </li>
                    ))}
                </ul>
            </Card>
        </AdminLayout>
    );
}
