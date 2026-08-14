import { useMemo, useState } from 'react';
import { router, useForm, usePage } from '@inertiajs/react';
import { Banknote, Wallet } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import SectionTabs, { EMPLOYEE_TABS } from '@/Components/SectionTabs';
import SmartLink from '@/Components/SmartLink';
import StatCard from '@/Components/StatCard';
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
    TableEmpty,
    TableHead,
    TableHeader,
    TableRow,
} from '@/Components/ui/table';
import { money, number } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { PageProps } from '@/types';

interface Line {
    id: number;
    name: string;
    net: number;
    paid: boolean;
    paid_at: string | null;
    method: string | null;
}

interface RunRow {
    id: number;
    number: string;
    period: string;
    status: string;
    net: number;
    remaining: number;
    employees: number;
    paid_count: number;
}

interface Props {
    runs: RunRow[];
    current: (RunRow & { lines: Line[] }) | null;
    remaining: number;
    /** ما ينتظر الصرف في المسيرات المعتمدة كلّها */
    due: number;
    today: string;
}

export default function PayrollPayments() {
    const { runs, current, remaining, due, today, context } = usePage<PageProps<Props>>().props;
    const t = useTranslate();
    const m = (v: number) => money(v, context!.currency);

    const unpaid = useMemo(() => (current?.lines ?? []).filter((l) => !l.paid), [current]);
    const [picked, setPicked] = useState<number[]>([]);
    const [paying, setPaying] = useState(false);

    const form = useForm<{ lines: number[]; paid_at: string; from: string }>({
        lines: [],
        paid_at: today,
        from: 'cash',
    });

    const selected = unpaid.filter((l) => picked.includes(l.id));
    const total = selected.reduce((s, l) => s + l.net, 0);

    const toggle = (id: number) =>
        setPicked((prev) => (prev.includes(id) ? prev.filter((x) => x !== id) : [...prev, id]));

    const openPay = (ids: number[]) => {
        form.clearErrors();
        form.setData({ lines: ids, paid_at: today, from: 'cash' });
        setPicked(ids);
        setPaying(true);
    };

    return (
        <AdminLayout title="صرف الرواتب">
            <PageHeader
                title="صرف الرواتب"
                subtitle={t('إخراج المال مقابل مستحقٍّ قُيّد يوم الاعتماد — سطرًا سطرًا أو دفعةً واحدة')}
                breadcrumbs={[
                    { label: 'الرئيسية', href: route('admin.dashboard') },
                    { label: 'الرواتب والموظفين', href: route('admin.employees.index') },
                    { label: 'صرف الرواتب' },
                ]}
            />

            <SectionTabs tabs={EMPLOYEE_TABS} current="admin.payroll.payments" />

            <div className="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2">
                <StatCard
                    stat={{
                        label: t('ينتظر الصرف'),
                        value: m(due),
                        icon: 'wallet',
                        color: due > 0 ? 'warning' : 'success',
                    }}
                    index={0}
                />
                <StatCard
                    stat={{
                        label: t('المسيرة المفتوحة'),
                        value: current ? m(remaining) : m(0),
                        icon: 'users',
                        color: 'info',
                        trend: current ? current.period : undefined,
                        up: true,
                    }}
                    index={1}
                />
            </div>

            {runs.length === 0 ? (
                <Card className="px-5 py-16 text-center">
                    <Banknote className="mx-auto size-8 text-[#d1d5db]" />
                    <p className="mt-3 font-medium text-[#111]">{t('لا مسيرة معتمدة')}</p>
                    <p className="mx-auto mt-1 max-w-md text-[13px] text-[#9ca3af]">
                        {t('لا يُصرف ما لم يُعتمد — اعتمد مسيرة الشهر أولًا لتصير مستحقًّا يُصرف.')}
                    </p>
                    <Button className="mt-5" variant="outline" asChild>
                        <SmartLink routeName="admin.payroll.index" href={route('admin.payroll.index')}>
                            {t('الذهاب إلى المسيرة')}
                        </SmartLink>
                    </Button>
                </Card>
            ) : (
                <div className="grid grid-cols-1 gap-6 lg:grid-cols-[280px_1fr]">
                    <Card className="h-fit overflow-hidden">
                        <p className="border-b border-[var(--ui-border,#e8e8e8)] px-4 py-3 text-[13px] font-medium text-[#374151]">
                            {t('المسيرات المعتمدة')}
                        </p>
                        <ul>
                            {runs.map((r) => (
                                <li key={r.id}>
                                    <button
                                        type="button"
                                        onClick={() => {
                                            setPicked([]);
                                            router.get(route('admin.payroll.payments'), { run: r.id }, {
                                                preserveState: true,
                                                preserveScroll: true,
                                                replace: true,
                                            });
                                        }}
                                        className={cn(
                                            'flex w-full items-center justify-between gap-2 border-b border-[var(--ui-border,#e8e8e8)] px-4 py-3 text-start transition-colors last:border-0',
                                            current?.id === r.id ? 'bg-[#fafafa]' : 'hover:bg-[#fafafa]',
                                        )}
                                    >
                                        <span className="min-w-0">
                                            <span className="block font-medium text-[#111]">{r.period}</span>
                                            <span className="block text-[12px] text-[#9ca3af]">
                                                {number(r.paid_count)}/{number(r.employees)} · {m(r.remaining)}
                                            </span>
                                        </span>
                                        <Badge variant={r.status === 'مصروفة' ? 'success' : 'info'}>
                                            {t(r.status)}
                                        </Badge>
                                    </button>
                                </li>
                            ))}
                        </ul>
                    </Card>

                    {current === null ? (
                        <Card className="px-5 py-16 text-center">
                            <p className="font-medium text-[#111]">{t('اختر مسيرة')}</p>
                        </Card>
                    ) : (
                        <Card className="overflow-hidden">
                            <div className="flex flex-wrap items-center justify-between gap-3 border-b border-[var(--ui-border,#e8e8e8)] px-4 py-3">
                                <div>
                                    <span className="font-bold text-[#111]">{current.period}</span>
                                    <span className="ms-2 font-mono text-[12px] text-[#9ca3af]">{current.number}</span>
                                </div>

                                <div className="flex flex-wrap items-center gap-2">
                                    {selected.length > 0 && (
                                        <span className="text-[13px] text-[#6b7280]">
                                            {t('المختار')}: {number(selected.length)} · {m(total)}
                                        </span>
                                    )}
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        disabled={selected.length === 0}
                                        onClick={() => openPay(picked)}
                                    >
                                        <Wallet />
                                        {t('صرف المختار')}
                                    </Button>
                                    <Button
                                        size="sm"
                                        disabled={unpaid.length === 0}
                                        onClick={() => openPay(unpaid.map((l) => l.id))}
                                    >
                                        <Banknote />
                                        {t('صرف الباقي')}
                                    </Button>
                                </div>
                            </div>

                            <Table>
                                <TableHeader>
                                    <TableRow className="hover:bg-transparent">
                                        <TableHead className="w-10">
                                            <input
                                                type="checkbox"
                                                className="size-4 accent-[#111]"
                                                aria-label={t('تحديد الكل')}
                                                checked={unpaid.length > 0 && picked.length === unpaid.length}
                                                onChange={(e) =>
                                                    setPicked(e.target.checked ? unpaid.map((l) => l.id) : [])
                                                }
                                            />
                                        </TableHead>
                                        <TableHead>{t('الموظّف')}</TableHead>
                                        <TableHead className="text-end">{t('الصافي')}</TableHead>
                                        <TableHead>{t('الحالة')}</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {current.lines.length === 0 ? (
                                        <TableEmpty colSpan={4}>{t('لا سطور')}</TableEmpty>
                                    ) : (
                                        current.lines.map((l) => (
                                            <TableRow key={l.id} className={cn(l.paid && 'opacity-60')}>
                                                <TableCell>
                                                    <input
                                                        type="checkbox"
                                                        className="size-4 accent-[#111]"
                                                        aria-label={l.name}
                                                        disabled={l.paid}
                                                        checked={picked.includes(l.id)}
                                                        onChange={() => toggle(l.id)}
                                                    />
                                                </TableCell>
                                                <TableCell className="font-medium text-[#111]">{l.name}</TableCell>
                                                <TableCell className="text-end font-semibold tabular-nums">
                                                    {m(l.net)}
                                                </TableCell>
                                                <TableCell>
                                                    {l.paid ? (
                                                        <>
                                                            <Badge variant="success">{t('صُرف')}</Badge>
                                                            <span className="ms-2 text-[12px] text-[#9ca3af]">
                                                                {l.paid_at} · {t(l.method ?? '')}
                                                            </span>
                                                        </>
                                                    ) : (
                                                        <Badge variant="warning">{t('ينتظر')}</Badge>
                                                    )}
                                                </TableCell>
                                            </TableRow>
                                        ))
                                    )}
                                </TableBody>
                            </Table>

                            <div className="border-t border-[var(--ui-border,#e8e8e8)] px-4 py-3 text-sm text-[#6b7280]">
                                {t('الصافي')}: <span className="font-semibold text-[#111]">{m(current.net)}</span>
                                {remaining > 0 && (
                                    <>
                                        {' — '}
                                        {t('ما زال ينتظر')}:{' '}
                                        <span className="font-semibold text-[#b45309]">{m(remaining)}</span>
                                    </>
                                )}
                            </div>
                        </Card>
                    )}
                </div>
            )}

            {/* ===== تأكيد الصرف ===== */}
            <Dialog open={paying} onOpenChange={setPaying}>
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>{t('صرف الرواتب')}</DialogTitle>
                    </DialogHeader>

                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            if (!current) return;
                            form.post(route('admin.payroll.pay', current.id), {
                                preserveScroll: true,
                                onSuccess: () => {
                                    setPaying(false);
                                    setPicked([]);
                                },
                            });
                        }}
                        className="space-y-4"
                    >
                        <div className="flex items-center justify-between rounded-[12px] bg-[#fafafa] px-4 py-3 text-sm">
                            <span className="text-[#6b7280]">
                                {number(form.data.lines.length)} {t('موظّفًا')}
                            </span>
                            <span className="font-semibold tabular-nums text-[#111]">
                                {m(
                                    (current?.lines ?? [])
                                        .filter((l) => form.data.lines.includes(l.id))
                                        .reduce((s, l) => s + l.net, 0),
                                )}
                            </span>
                        </div>

                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <Field label="تاريخ الصرف" required error={form.errors.paid_at}>
                                <Input
                                    type="date"
                                    dir="ltr"
                                    value={form.data.paid_at}
                                    onChange={(e) => form.setData('paid_at', e.target.value)}
                                />
                            </Field>
                            <Field label="صُرف من" required error={form.errors.from}>
                                <Select
                                    value={form.data.from}
                                    onChange={(e) => form.setData('from', e.target.value)}
                                    options={[
                                        { value: 'cash', label: 'الصندوق' },
                                        { value: 'bank', label: 'البنك' },
                                    ]}
                                />
                            </Field>
                        </div>

                        {form.errors.lines && <p className="text-[12px] text-[#b91c1c]">{form.errors.lines}</p>}

                        <div className="flex justify-end gap-2">
                            <Button type="button" variant="ghost" onClick={() => setPaying(false)}>
                                {t('إلغاء')}
                            </Button>
                            <Button type="submit" loading={form.processing} disabled={form.data.lines.length === 0}>
                                <Wallet />
                                {t('تأكيد الصرف')}
                            </Button>
                        </div>
                    </form>
                </DialogContent>
            </Dialog>
        </AdminLayout>
    );
}
