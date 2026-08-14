import { useMemo, useState } from 'react';
import { useForm, usePage } from '@inertiajs/react';
import { Check, Plus, Trash2 } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import SectionTabs, { FINANCE_TABS } from '@/Components/SectionTabs';
import DataTable, { type Column, type Filter, type ServerPagination } from '@/Components/DataTable';
import Field, { Select } from '@/Components/Field';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { Input } from '@/Components/ui/input';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/Components/ui/table';
import { money } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { PageProps } from '@/types';

interface EntryLine {
    account: string;
    debit: number;
    credit: number;
    memo: string | null;
}

interface Entry {
    id: number;
    number: string;
    date: string | null;
    description: string;
    source: string;
    author: string | null;
    total: number;
    lines: EntryLine[];
}

interface Props {
    entries: Entry[];
    pagination: ServerPagination;
    filters: Record<string, string | null | undefined>;
    accounts: { value: number; label: string; type: string }[];
    sources: string[];
    today: string;
}

/** سطرٌ في نموذج القيد — قبل أن يصير سطرًا في الدفتر */
interface DraftLine {
    account_id: string;
    debit: string;
    credit: string;
    memo: string;
}

const emptyLine = (): DraftLine => ({ account_id: '', debit: '', credit: '', memo: '' });

export default function Journal() {
    const { entries, pagination, filters, accounts, sources, today, context } =
        usePage<PageProps<Props>>().props;
    const t = useTranslate();
    const m = (v: number) => money(v, context!.currency);

    const [viewing, setViewing] = useState<Entry | null>(null);
    const [adding, setAdding] = useState(false);

    const form = useForm({
        entry_date: today,
        description: '',
        lines: [emptyLine(), emptyLine()] as DraftLine[],
    });

    const setLine = (i: number, patch: Partial<DraftLine>) =>
        form.setData(
            'lines',
            form.data.lines.map((l, j) => (j === i ? { ...l, ...patch } : l)),
        );

    const totals = useMemo(() => {
        const debit = form.data.lines.reduce((s, l) => s + (parseFloat(l.debit) || 0), 0);
        const credit = form.data.lines.reduce((s, l) => s + (parseFloat(l.credit) || 0), 0);

        return { debit, credit, diff: Math.round((debit - credit) * 1000) / 1000 };
    }, [form.data.lines]);

    /*
     * زرّ الحفظ يُقفل حتى يتوازن القيد.
     *
     * الخادم يرفض المختلّ على أي حال (Ledger::post)، لكنّ الرفض بعد ملء عشرة
     * حقول يعني إعادة الملء. والفرق معروضٌ لحظةً بلحظة، فيُصحَّح قبل الإرسال
     * لا بعده.
     */
    const filled = form.data.lines.filter(
        (l) => l.account_id && ((parseFloat(l.debit) || 0) > 0 || (parseFloat(l.credit) || 0) > 0),
    );
    const ready =
        filled.length >= 2 && Math.abs(totals.diff) < 0.0005 && totals.debit > 0 && !!form.data.description.trim();

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        // السطور الفارغة لا تُرسل: الخادم يرفض السطر بلا طرف
        form.transform((data) => ({
            ...data,
            lines: (data.lines as DraftLine[]).filter(
                (l) => l.account_id && ((parseFloat(l.debit) || 0) > 0 || (parseFloat(l.credit) || 0) > 0),
            ),
        }));

        form.post(route('admin.finance.journal.store'), {
                preserveScroll: true,
                onSuccess: () => {
                    setAdding(false);
                    form.setData({ entry_date: today, description: '', lines: [emptyLine(), emptyLine()] });
                },
            });
    };

    const columns: Column<Entry>[] = [
        {
            key: 'number',
            header: 'المرجع',
            cell: (e) => <span className="font-mono text-[12px] text-[#4b4b4b]">{e.number}</span>,
        },
        {
            key: 'date',
            header: 'التاريخ',
            cell: (e) => (
                <span dir="ltr" className="text-[#6b7280]">
                    {e.date ?? '—'}
                </span>
            ),
        },
        { key: 'description', header: 'البيان', cell: (e) => e.description },
        {
            key: 'source',
            header: 'المصدر',
            cell: (e) => <Badge variant={e.source === 'يدوي' ? 'neutral' : 'info'}>{t(e.source)}</Badge>,
        },
        {
            key: 'total',
            header: 'المبلغ',
            align: 'end',
            cell: (e) => <span className="font-semibold tabular-nums text-[#111]">{m(e.total)}</span>,
        },
        {
            key: 'actions',
            header: 'إجراءات',
            align: 'end',
            cell: (e) => (
                <Button variant="ghost" size="sm" onClick={() => setViewing(e)}>
                    {t('السطور')}
                </Button>
            ),
        },
    ];

    const tableFilters: Filter<Entry>[] = [
        {
            label: 'كل المصادر',
            param: 'source',
            options: sources.map((s) => ({ label: s, value: s })),
        },
        { label: 'من', type: 'date', param: 'from' },
        { label: 'إلى', type: 'date', param: 'to' },
    ];

    return (
        <AdminLayout title="القيود اليومية">
            <PageHeader
                title="القيود اليومية"
                subtitle={t('كلّ ما دخل الدفتر — يدويًّا أو مُرحَّلًا عن مستند')}
                breadcrumbs={[
                    { label: 'الرئيسية', href: route('admin.dashboard') },
                    { label: 'المالية', href: route('admin.finance.index') },
                    { label: 'القيود اليومية' },
                ]}
                actions={
                    <Button onClick={() => setAdding(true)}>
                        <Plus />
                        {t('قيد جديد')}
                    </Button>
                }
            />

            <SectionTabs tabs={FINANCE_TABS} current="admin.finance.journal" />

            <Card className="overflow-hidden">
                <DataTable
                    rows={entries}
                    columns={columns}
                    rowKey={(e) => e.id}
                    searchPlaceholder="ابحث بالمرجع أو البيان…"
                    searchable={() => ''}
                    filters={tableFilters}
                    empty="لا قيود في الدفتر بعد"
                    server={{ pagination, params: filters }}
                />
            </Card>

            {/* ===== سطور قيدٍ بعينه ===== */}
            <Dialog open={viewing !== null} onOpenChange={(o) => !o && setViewing(null)}>
                <DialogContent className="sm:max-w-2xl">
                    <DialogHeader>
                        <DialogTitle>
                            {viewing?.number} — {viewing?.description}
                        </DialogTitle>
                    </DialogHeader>

                    <p className="text-[12px] text-[#9ca3af]">
                        {viewing?.date} · {t(viewing?.source ?? '')}
                        {viewing?.author ? ` · ${viewing.author}` : ''}
                    </p>

                    <Table>
                        <TableHeader>
                            <TableRow className="hover:bg-transparent">
                                <TableHead>{t('الحساب')}</TableHead>
                                <TableHead className="text-end">{t('مدين')}</TableHead>
                                <TableHead className="text-end">{t('دائن')}</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {(viewing?.lines ?? []).map((l, i) => (
                                <TableRow key={i}>
                                    <TableCell>
                                        {l.account}
                                        {l.memo && (
                                            <span className="block text-[12px] text-[#9ca3af]">{l.memo}</span>
                                        )}
                                    </TableCell>
                                    <TableCell className="text-end tabular-nums">
                                        {l.debit > 0 ? m(l.debit) : '—'}
                                    </TableCell>
                                    <TableCell className="text-end tabular-nums">
                                        {l.credit > 0 ? m(l.credit) : '—'}
                                    </TableCell>
                                </TableRow>
                            ))}
                            <TableRow className="bg-[#fafafa] font-semibold hover:bg-[#fafafa]">
                                <TableCell>{t('المجموع')}</TableCell>
                                <TableCell className="text-end tabular-nums">{m(viewing?.total ?? 0)}</TableCell>
                                <TableCell className="text-end tabular-nums">{m(viewing?.total ?? 0)}</TableCell>
                            </TableRow>
                        </TableBody>
                    </Table>
                </DialogContent>
            </Dialog>

            {/* ===== قيد جديد ===== */}
            <Dialog open={adding} onOpenChange={setAdding}>
                <DialogContent className="sm:max-w-3xl">
                    <DialogHeader>
                        <DialogTitle>{t('قيد جديد')}</DialogTitle>
                    </DialogHeader>

                    <form onSubmit={submit} className="space-y-4">
                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                            <Field label="التاريخ" required error={form.errors.entry_date}>
                                <Input
                                    type="date"
                                    dir="ltr"
                                    value={form.data.entry_date}
                                    onChange={(e) => form.setData('entry_date', e.target.value)}
                                />
                            </Field>
                            <Field label="البيان" required error={form.errors.description} className="sm:col-span-2">
                                <Input
                                    value={form.data.description}
                                    onChange={(e) => form.setData('description', e.target.value)}
                                    placeholder={t('تسوية رصيد الصندوق')}
                                />
                            </Field>
                        </div>

                        <div className="space-y-2">
                            {form.data.lines.map((line, i) => (
                                <div key={i} className="grid grid-cols-12 items-start gap-2">
                                    <div className="col-span-12 sm:col-span-5">
                                        <Select
                                            placeholder="اختر الحساب"
                                            value={line.account_id}
                                            onChange={(e) => setLine(i, { account_id: e.target.value })}
                                            options={accounts}
                                            aria-label={t('الحساب')}
                                        />
                                    </div>
                                    <div className="col-span-6 sm:col-span-2">
                                        <Input
                                            type="number"
                                            step="0.001"
                                            min="0"
                                            dir="ltr"
                                            placeholder={t('مدين')}
                                            aria-label={t('مدين')}
                                            value={line.debit}
                                            // طرفٌ واحد لا طرفان: كتابة المدين تمسح الدائن
                                            onChange={(e) => setLine(i, { debit: e.target.value, credit: '' })}
                                        />
                                    </div>
                                    <div className="col-span-6 sm:col-span-2">
                                        <Input
                                            type="number"
                                            step="0.001"
                                            min="0"
                                            dir="ltr"
                                            placeholder={t('دائن')}
                                            aria-label={t('دائن')}
                                            value={line.credit}
                                            onChange={(e) => setLine(i, { credit: e.target.value, debit: '' })}
                                        />
                                    </div>
                                    <div className="col-span-10 sm:col-span-2">
                                        <Input
                                            placeholder={t('ملاحظة')}
                                            aria-label={t('ملاحظة')}
                                            value={line.memo}
                                            onChange={(e) => setLine(i, { memo: e.target.value })}
                                        />
                                    </div>
                                    <div className="col-span-2 sm:col-span-1">
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon"
                                            className="text-[#b91c1c]"
                                            disabled={form.data.lines.length <= 2}
                                            aria-label={t('حذف السطر')}
                                            onClick={() =>
                                                form.setData(
                                                    'lines',
                                                    form.data.lines.filter((_, j) => j !== i),
                                                )
                                            }
                                        >
                                            <Trash2 />
                                        </Button>
                                    </div>
                                </div>
                            ))}

                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={() => form.setData('lines', [...form.data.lines, emptyLine()])}
                            >
                                <Plus />
                                {t('سطر')}
                            </Button>
                        </div>

                        {/* الميزان لحظةً بلحظة — الفرق يُرى قبل الإرسال لا بعد الرفض */}
                        <div
                            className={cn(
                                'flex flex-wrap items-center justify-between gap-3 rounded-[12px] px-4 py-3 text-sm',
                                Math.abs(totals.diff) < 0.0005 ? 'bg-[#ecfdf5] text-[#047857]' : 'bg-[#fffbeb] text-[#d97706]',
                            )}
                        >
                            <span className="tabular-nums">
                                {t('مدين')} {m(totals.debit)} · {t('دائن')} {m(totals.credit)}
                            </span>
                            <span className="font-semibold tabular-nums">
                                {Math.abs(totals.diff) < 0.0005
                                    ? t('متوازن')
                                    : `${t('الفرق')} ${m(Math.abs(totals.diff))}`}
                            </span>
                        </div>

                        {form.errors.lines && <p className="text-[12px] text-[#b91c1c]">{form.errors.lines}</p>}

                        <div className="flex justify-end gap-2">
                            <Button type="button" variant="ghost" onClick={() => setAdding(false)}>
                                {t('إلغاء')}
                            </Button>
                            <Button type="submit" loading={form.processing} disabled={!ready}>
                                <Check />
                                {t('ترحيل القيد')}
                            </Button>
                        </div>
                    </form>
                </DialogContent>
            </Dialog>
        </AdminLayout>
    );
}
