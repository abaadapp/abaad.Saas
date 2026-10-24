import { useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import { Check, PackageCheck, Truck, X } from 'lucide-react';
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
import { EVENT_LABEL, RESOLUTION_LABEL, TRANSFER_STATUS } from '@/lib/branchTransfers';
import type { PageProps } from '@/types';

interface Line {
    id: number;
    name: string;
    sku: string | null;
    requested: number;
    approved: number | null;
    sent: number | null;
    received: number | null;
    lost: number;
    returned: number;
    late: number;
    outstanding: number;
    available: number;
}

interface Stage {
    by: string | null;
    at: string | null;
}

interface Transfer {
    id: number;
    number: string;
    status: string;
    from: string;
    to: string;
    notes: string | null;
    decision_reason: string | null;
    self_approved: boolean;
    responsible: string | null;
    supply: { id: number; number: string } | null;
    stages: Record<'requested' | 'approved' | 'prepared' | 'dispatched' | 'received', Stage>;
    lines: Line[];
    events: { id: number; action: string; by: string | null; note: string | null; at: string | null; data: Record<string, unknown> | null }[];
}

interface Props {
    transfer: Transfer;
    can: Record<'approve' | 'reject' | 'prepare' | 'dispatch' | 'receive' | 'resolve' | 'cancel', boolean>;
    isOwner: boolean;
    isRequester: boolean;
}

const STAGES: { key: keyof Transfer['stages']; label: string }[] = [
    { key: 'requested', label: 'طُلب' },
    { key: 'approved', label: 'اعتُمد' },
    { key: 'prepared', label: 'جُهّز' },
    { key: 'dispatched', label: 'أُرسل' },
    { key: 'received', label: 'استُلم' },
];

/**
 * سندُ نقلٍ واحد — مراحلُه ومن فعل كلًّا منها، وأسطرُه بكميّاتها، وما يُفعل الآن.
 *
 * والأزرارُ من `can` — يقرؤها الخادم بالقاعدة التي يفحص بها الفعل
 * (`BranchTransfers::abilities`). ولا يُكتب هنا رقمٌ يحسبه الخادم.
 */
export default function TransferShow() {
    const { transfer, can, isOwner, isRequester, errors } = usePage<PageProps<Props>>().props;
    const t = useTranslate();
    const status = TRANSFER_STATUS[transfer.status];
    const error = (errors as Record<string, string>)?.transfer;

    // الكمياتُ المعروضة للمرحلة الحاليّة: المعتمدُ للإرسال، والمرسلُ للاستلام
    const initial = (l: Line) =>
        can.receive ? (l.sent ?? 0) : can.dispatch ? (l.approved ?? l.requested) : l.requested;
    const [qty, setQty] = useState<Record<number, number>>(() => Object.fromEntries(transfer.lines.map((l) => [l.id, initial(l)])));
    const [reason, setReason] = useState('');
    const [note, setNote] = useState('');
    const [selfApprove, setSelfApprove] = useState(false);
    const [resolve, setResolve] = useState<{ line: number; kind: string; quantity: number; reason: string } | null>(null);

    const post = (name: string, data: Record<string, unknown> = {}) =>
        router.post(route(`admin.inventory.transfers.${name}`, transfer.id), data as never, { preserveScroll: true });

    const editable = can.approve || can.dispatch || can.receive;
    const short = can.receive && transfer.lines.some((l) => (qty[l.id] ?? 0) < (l.sent ?? 0));

    return (
        <AdminLayout title={transfer.number}>
            <BackLink routeName="admin.inventory.transfers" href={route('admin.inventory.transfers')} label="كل التحويلات" />

            <PageHeader
                title={`${t('سند نقل')} ${transfer.number}`}
                subtitle={`${transfer.from} ← ${transfer.to}`}
                actions={<Badge variant={status?.tone ?? 'neutral'} data-testid="transfer-status">{t(status?.label ?? transfer.status)}</Badge>}
            />

            {error && (
                <p className="mb-4 rounded-lg bg-[#fef2f2] px-3 py-2 text-[13px] text-[#b91c1c]" role="alert" data-testid="transfer-error">
                    {error}
                </p>
            )}

            <Card className="mb-4 p-4">
                <ol className="grid gap-3 sm:grid-cols-5" data-testid="transfer-stages">
                    {STAGES.map((s) => {
                        const st = transfer.stages[s.key];
                        return (
                            <li key={s.key} className={st.at ? 'text-[#111]' : 'text-[#9ca3af]'}>
                                <p className="text-[12px] font-semibold">{t(s.label)}</p>
                                <p className="text-[12px]">{st.by ?? '—'}</p>
                                <p className="text-[11px] tabular-nums">{st.at ?? ''}</p>
                            </li>
                        );
                    })}
                </ol>
                {transfer.self_approved && (
                    <p className="mt-3 text-[12px] text-[#92400e]">{t('اعتمده صاحب النشاط بنفسه بقرارٍ صريح')}</p>
                )}
                {transfer.decision_reason && (
                    <p className="mt-3 text-[13px]">
                        <span className="text-[#6b7280]">{t('السبب')}: </span>
                        {transfer.decision_reason}
                    </p>
                )}
                {(transfer.notes || transfer.responsible || transfer.supply) && (
                    <div className="mt-3 flex flex-wrap gap-x-6 gap-y-1 text-[13px] text-[#374151]">
                        {transfer.notes && <span>{t('ملاحظات')}: {transfer.notes}</span>}
                        {transfer.responsible && <span>{t('الموظف المسؤول')}: {transfer.responsible}</span>}
                        {transfer.supply && (
                            <SmartLink routeName="admin.inventory.supply.show" href={route('admin.inventory.supply.show', transfer.supply.id)} className="underline">
                                {t('من طلب التزويد')} {transfer.supply.number}
                            </SmartLink>
                        )}
                    </div>
                )}
            </Card>

            <Card className="mb-4 overflow-x-auto no-scrollbar">
                <Table>
                    <TableHeader>
                        <TableRow className="hover:bg-transparent">
                            <TableHead>{t('المنتج')}</TableHead>
                            <TableHead className="text-end">{t('الكمية المطلوبة')}</TableHead>
                            <TableHead className="text-end">{t('المعتمد')}</TableHead>
                            <TableHead className="text-end">{t('المرسل')}</TableHead>
                            <TableHead className="text-end">{t('استُلم')}</TableHead>
                            <TableHead className="text-end">{t('فرق معلّق')}</TableHead>
                            {(can.approve || can.dispatch) && <TableHead className="text-end">{t('المتاح في المصدر')}</TableHead>}
                            {editable && <TableHead className="text-end">{can.receive ? t('وصل') : can.dispatch ? t('يُرسل') : t('يُعتمد')}</TableHead>}
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {transfer.lines.map((l) => (
                            <TableRow key={l.id} data-testid={`transfer-line-${l.id}`}>
                                <TableCell>
                                    <span className="block font-medium">{l.name}</span>
                                    {l.sku && <span className="text-[11px] text-[#9ca3af]">{l.sku}</span>}
                                    {(l.lost > 0 || l.returned > 0 || l.late > 0) && (
                                        <span className="block text-[11px] text-[#6b7280]">
                                            {l.lost > 0 && `${t('فُقد أو تلف')}: ${l.lost} `}
                                            {l.returned > 0 && `${t('أُعيد إلى المصدر')}: ${l.returned} `}
                                            {l.late > 0 && `${t('وصل متأخرًا')}: ${l.late}`}
                                        </span>
                                    )}
                                </TableCell>
                                <TableCell className="text-end tabular-nums">{number(l.requested)}</TableCell>
                                <TableCell className="text-end tabular-nums">{l.approved === null ? '—' : number(l.approved)}</TableCell>
                                <TableCell className="text-end tabular-nums">{l.sent === null ? '—' : number(l.sent)}</TableCell>
                                <TableCell className="text-end tabular-nums">{l.received === null ? '—' : number(l.received)}</TableCell>
                                <TableCell className={l.outstanding > 0 ? 'text-end font-semibold tabular-nums text-[#b91c1c]' : 'text-end tabular-nums'}>
                                    {number(l.outstanding)}
                                </TableCell>
                                {(can.approve || can.dispatch) && <TableCell className="text-end tabular-nums">{number(l.available)}</TableCell>}
                                {editable && (
                                    <TableCell className="text-end">
                                        <Input
                                            type="number"
                                            min={0}
                                            inputMode="numeric"
                                            aria-label={t('الكمية')}
                                            value={qty[l.id] ?? 0}
                                            onChange={(e) => setQty({ ...qty, [l.id]: Math.max(0, parseInt(e.target.value || '0', 10) || 0) })}
                                            className="ms-auto w-24 tabular-nums"
                                        />
                                    </TableCell>
                                )}
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            </Card>

            {(can.approve || can.reject || can.prepare || can.dispatch || can.receive || can.cancel) && (
                <Card className="mb-4 space-y-3 p-4" data-testid="transfer-actions">
                    {can.approve && isRequester && isOwner && (
                        <label className="flex items-center gap-2 text-[13px]">
                            <input type="checkbox" checked={selfApprove} onChange={(e) => setSelfApprove(e.target.checked)} />
                            {t('أنا صاحب الطلب — أعتمده بنفسي بقرارٍ صريح يُسجَّل في السند')}
                        </label>
                    )}
                    {can.receive && (
                        <Input value={note} onChange={(e) => setNote(e.target.value)} placeholder={t('ملاحظة الاستلام (اختيارية)')} />
                    )}
                    {short && (
                        <p className="rounded-lg bg-[#fffbeb] px-3 py-2 text-[12.5px] text-[#92400e]">
                            {t('ما لم يصل يبقى فرقًا معلّقًا قيد النقل، ولا يُحذف من المخزون حتى يُسوّى بإجراءٍ موثّق.')}
                        </p>
                    )}
                    {(can.reject || can.cancel) && (
                        <Input value={reason} onChange={(e) => setReason(e.target.value)} placeholder={t('سبب الرفض أو الإلغاء')} />
                    )}
                    <div className="flex flex-wrap gap-2">
                        {can.approve && (
                            <Button onClick={() => post('approve', { quantities: qty, self_approve: selfApprove })} data-testid="transfer-approve">
                                <Check />
                                {t('اعتماد')}
                            </Button>
                        )}
                        {can.prepare && (
                            <Button onClick={() => post('prepare')} data-testid="transfer-prepare">
                                <PackageCheck />
                                {t('بدء التحضير')}
                            </Button>
                        )}
                        {can.dispatch && (
                            <Button onClick={() => post('dispatch', { quantities: qty })} data-testid="transfer-dispatch">
                                <Truck />
                                {t('تأكيد الإرسال')}
                            </Button>
                        )}
                        {can.receive && (
                            <Button onClick={() => post('receive', { quantities: qty, note })} data-testid="transfer-receive">
                                <PackageCheck />
                                {t('تأكيد الاستلام')}
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
                                {t('إلغاء سند النقل')}
                            </Button>
                        )}
                    </div>
                </Card>
            )}

            {can.resolve && (
                <Card className="mb-4 space-y-3 p-4" data-testid="transfer-resolve">
                    <h2 className="text-[14px] font-bold">{t('معالجة فرق الاستلام')}</h2>
                    {transfer.lines
                        .filter((l) => l.outstanding > 0)
                        .map((l) => {
                            const current = resolve?.line === l.id ? resolve : null;
                            return (
                                <div key={l.id} className="grid gap-2 rounded-lg border border-[#ececec] p-3 sm:grid-cols-[1fr_auto_auto_2fr_auto] sm:items-center">
                                    <span className="text-[13px] font-medium">
                                        {l.name} — {t('معلّق')}: {number(l.outstanding)}
                                    </span>
                                    <Select
                                        value={current?.kind ?? ''}
                                        onChange={(e) => setResolve({ line: l.id, kind: e.target.value, quantity: current?.quantity ?? l.outstanding, reason: current?.reason ?? '' })}
                                        options={Object.entries(RESOLUTION_LABEL).map(([value, label]) => ({ value, label }))}
                                        placeholder="كيف يُسوّى؟"
                                        aria-label={t('كيف يُسوّى؟')}
                                    />
                                    <Input
                                        type="number"
                                        min={1}
                                        max={l.outstanding}
                                        aria-label={t('الكمية')}
                                        value={current?.quantity ?? l.outstanding}
                                        onChange={(e) => setResolve({ line: l.id, kind: current?.kind ?? '', reason: current?.reason ?? '', quantity: Math.max(1, parseInt(e.target.value || '1', 10) || 1) })}
                                        className="w-24 tabular-nums"
                                    />
                                    <Input
                                        value={current?.reason ?? ''}
                                        onChange={(e) => setResolve({ line: l.id, kind: current?.kind ?? '', quantity: current?.quantity ?? l.outstanding, reason: e.target.value })}
                                        placeholder={t('ماذا حدث؟ (يُسجَّل في السند)')}
                                    />
                                    <Button
                                        disabled={!current?.kind || (current?.reason.trim().length ?? 0) < 3}
                                        onClick={() => current && post('resolve', { line_id: l.id, kind: current.kind, quantity: current.quantity, reason: current.reason })}
                                    >
                                        {t('تسوية')}
                                    </Button>
                                </div>
                            );
                        })}
                    <p className="text-[12px] text-[#6b7280]">
                        {t('«فُقد أو تلف» يُقيَّد خسارةً بتكلفة يوم الإرسال على الفرع المصدر. «أُعيد» يعود إلى رفّ المصدر. «وصل متأخرًا» يدخل رفّ المستلم.')}
                    </p>
                </Card>
            )}

            <Card className="p-4">
                <h2 className="mb-3 text-[14px] font-bold">{t('سجل المراحل')}</h2>
                <ul className="space-y-2" data-testid="transfer-events">
                    {transfer.events.map((e) => (
                        <li key={e.id} className="flex flex-wrap gap-x-3 text-[13px]">
                            <span className="tabular-nums text-[#9ca3af]">{e.at}</span>
                            <span className="font-medium">{e.by ?? '—'}</span>
                            <span>{t(EVENT_LABEL[e.action] ?? e.action)}</span>
                            {e.data && typeof e.data.kind === 'string' && (
                                <span className="text-[#6b7280]">
                                    ({t(RESOLUTION_LABEL[e.data.kind] ?? String(e.data.kind))} × {String(e.data.quantity ?? '')})
                                </span>
                            )}
                            {e.note && <span className="text-[#6b7280]">— {e.note}</span>}
                        </li>
                    ))}
                </ul>
            </Card>
        </AdminLayout>
    );
}
