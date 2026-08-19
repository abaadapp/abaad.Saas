import { useState } from 'react';
import { router, useForm, usePage } from '@inertiajs/react';
import { BadgeCheck, Check, Plus, Trash2, Users } from 'lucide-react';
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

interface RunRow {
    id: number;
    number: string;
    period: string;
    status: string;
    gross: number;
    deductions: number;
    net: number;
    employees: number;
    paid_count: number;
}

interface Line {
    id: number;
    name: string;
    basic: number;
    allowances: number;
    overtime: number;
    deductions: number;
    net: number;
    paid: boolean;
    paid_at: string | null;
    method: string | null;
    notes: string | null;
}

interface RunDetail extends RunRow {
    editable: boolean;
    approved_at: string | null;
    paid_at: string | null;
    lines: Line[];
}

interface Props {
    runs: RunRow[];
    current: RunDetail | null;
    /** شهورٌ لم تُفتح لها مسيرة — لا يُقترح شهرٌ له مسيرة */
    openPeriods: string[];
    employeeCount: number;
    today: string;
}

const STATUS_TONE: Record<string, string> = {
    مسودة: 'neutral',
    معتمدة: 'info',
    مصروفة: 'success',
};

export default function PayrollIndex() {
    const { runs, current, openPeriods, employeeCount, context } = usePage<PageProps<Props>>().props;
    const t = useTranslate();
    const m = (v: number) => money(v, context!.currency);

    const [opening, setOpening] = useState(false);
    const [editing, setEditing] = useState<Line | null>(null);

    const openForm = useForm({ period: openPeriods[0] ?? '' });
    const lineForm = useForm({ basic: '', allowances: '', overtime: '', deductions: '', notes: '' });

    const editLine = (line: Line) => {
        lineForm.clearErrors();
        lineForm.setData({
            basic: String(line.basic),
            allowances: String(line.allowances),
            overtime: String(line.overtime),
            deductions: String(line.deductions),
            notes: line.notes ?? '',
        });
        setEditing(line);
    };

    return (
        <AdminLayout title="مسيرة الرواتب">
            <PageHeader
                title="مسيرة الرواتب"
                subtitle={t('رواتب الشهر تُحضَّر ثم تُعتمد — والاعتماد يقيّد المستحقّ ولا يصرف مالًا')}
                actions={
                    <Button onClick={() => setOpening(true)} disabled={openPeriods.length === 0}>
                        <Plus />
                        {t('مسيرة شهر')}
                    </Button>
                }
            />

            <SectionTabs tabs={EMPLOYEE_TABS} current="admin.payroll.index" />

            {employeeCount === 0 ? (
                <Card className="px-5 py-16 text-center">
                    <Users className="mx-auto size-8 text-[#d1d5db]" />
                    <p className="mt-3 font-medium text-[#111]">{t('لا موظّف له راتبٌ مسجَّل')}</p>
                    <p className="mx-auto mt-1 max-w-md text-[13px] text-[#9ca3af]">
                        {t('المسيرة تُملأ من رواتب الموظّفين — اضبط الراتب الأساسي والبدلات في صفحة الموظفين أولًا.')}
                    </p>
                    <Button className="mt-5" variant="outline" asChild>
                        <SmartLink routeName="admin.employees.index" href={route('admin.employees.index')}>
                            {t('الذهاب إلى الموظفين')}
                        </SmartLink>
                    </Button>
                </Card>
            ) : (
                <div className="grid grid-cols-1 gap-6 lg:grid-cols-[280px_1fr]">
                    {/* ===== المسيرات ===== */}
                    <Card className="h-fit overflow-hidden">
                        <p className="border-b border-[var(--ui-border,#e8e8e8)] px-4 py-3 text-[13px] font-medium text-[#374151]">
                            {t('المسيرات')}
                        </p>
                        {runs.length === 0 ? (
                            <p className="px-4 py-10 text-center text-[13px] text-[#9ca3af]">
                                {t('لا مسيرة بعد')}
                            </p>
                        ) : (
                            <ul>
                                {runs.map((r) => (
                                    <li key={r.id}>
                                        <button
                                            type="button"
                                            onClick={() =>
                                                router.get(route('admin.payroll.index'), { run: r.id }, {
                                                    preserveState: true,
                                                    preserveScroll: true,
                                                    replace: true,
                                                })
                                            }
                                            className={cn(
                                                'flex w-full items-center justify-between gap-2 border-b border-[var(--ui-border,#e8e8e8)] px-4 py-3 text-start transition-colors last:border-0',
                                                current?.id === r.id ? 'bg-[#fafafa]' : 'hover:bg-[#fafafa]',
                                            )}
                                        >
                                            <span className="min-w-0">
                                                <span className="block font-medium text-[#111]">{r.period}</span>
                                                <span className="block text-[12px] text-[#9ca3af]">
                                                    {number(r.employees)} {t('موظّفًا')} · {m(r.net)}
                                                </span>
                                            </span>
                                            <Badge variant={(STATUS_TONE[r.status] ?? 'neutral') as never}>
                                                {t(r.status)}
                                            </Badge>
                                        </button>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Card>

                    {/* ===== المسيرة المفتوحة ===== */}
                    {current === null ? (
                        <Card className="px-5 py-16 text-center">
                            <p className="font-medium text-[#111]">{t('لا مسيرة مفتوحة')}</p>
                            <p className="mt-1 text-[13px] text-[#9ca3af]">
                                {t('افتح مسيرة الشهر لتُملأ برواتب موظّفيك كما هي اليوم.')}
                            </p>
                        </Card>
                    ) : (
                        <div>
                            <div className="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
                                <StatCard
                                    stat={{ label: t('الإجمالي'), value: m(current.gross), icon: 'coins', color: 'secondary' }}
                                    index={0}
                                />
                                <StatCard
                                    stat={{ label: t('الخصومات'), value: m(current.deductions), icon: 'arrow-down-circle', color: 'warning' }}
                                    index={1}
                                />
                                <StatCard
                                    stat={{ label: t('الصافي'), value: m(current.net), icon: 'wallet', color: 'primary' }}
                                    index={2}
                                />
                            </div>

                            <Card className="overflow-hidden">
                                <div className="flex flex-wrap items-center justify-between gap-3 border-b border-[var(--ui-border,#e8e8e8)] px-4 py-3">
                                    <div>
                                        <span className="font-bold text-[#111]">{current.period}</span>
                                        <span className="ms-2 font-mono text-[12px] text-[#9ca3af]">
                                            {current.number}
                                        </span>
                                        <Badge className="ms-2" variant={(STATUS_TONE[current.status] ?? 'neutral') as never}>
                                            {t(current.status)}
                                        </Badge>
                                    </div>

                                    <div className="flex items-center gap-2">
                                        {current.editable && (
                                            <>
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    className="text-[#b91c1c]"
                                                    onClick={() => {
                                                        if (!confirm(t('حذف المسيرة؟'))) return;
                                                        router.delete(route('admin.payroll.destroy', current.id));
                                                    }}
                                                >
                                                    <Trash2 />
                                                    {t('حذف')}
                                                </Button>
                                                <Button
                                                    size="sm"
                                                    onClick={() => {
                                                        if (!confirm(t('اعتماد المسيرة؟ بعده لا تُعدَّل سطورها.'))) return;
                                                        router.post(
                                                            route('admin.payroll.approve', current.id),
                                                            {},
                                                            { preserveScroll: true },
                                                        );
                                                    }}
                                                >
                                                    <BadgeCheck />
                                                    {t('اعتماد')}
                                                </Button>
                                            </>
                                        )}
                                        {!current.editable && (
                                            <Button variant="outline" size="sm" asChild>
                                                <SmartLink
                                                    routeName="admin.payroll.payments"
                                                    href={route('admin.payroll.payments', { run: current.id })}
                                                >
                                                    {t('الذهاب إلى الصرف')}
                                                </SmartLink>
                                            </Button>
                                        )}
                                    </div>
                                </div>

                                <Table>
                                    <TableHeader>
                                        <TableRow className="hover:bg-transparent">
                                            <TableHead>{t('الموظّف')}</TableHead>
                                            <TableHead className="text-end">{t('الأساسي')}</TableHead>
                                            <TableHead className="text-end">{t('البدلات')}</TableHead>
                                            <TableHead className="text-end">{t('إضافي')}</TableHead>
                                            <TableHead className="text-end">{t('خصومات')}</TableHead>
                                            <TableHead className="text-end">{t('الصافي')}</TableHead>
                                            <TableHead className="text-end">{t('إجراءات')}</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {current.lines.length === 0 ? (
                                            <TableEmpty colSpan={7}>{t('لا سطور في المسيرة')}</TableEmpty>
                                        ) : (
                                            current.lines.map((l) => (
                                                <TableRow key={l.id}>
                                                    <TableCell>
                                                        <span className="font-medium text-[#111]">{l.name}</span>
                                                        {l.paid && (
                                                            <span className="block text-[12px] text-[#047857]">
                                                                {t('صُرف')} {l.paid_at}
                                                            </span>
                                                        )}
                                                    </TableCell>
                                                    <TableCell className="text-end tabular-nums">{m(l.basic)}</TableCell>
                                                    <TableCell className="text-end tabular-nums">
                                                        {m(l.allowances)}
                                                    </TableCell>
                                                    <TableCell className="text-end tabular-nums">
                                                        {l.overtime > 0 ? m(l.overtime) : '—'}
                                                    </TableCell>
                                                    <TableCell className="text-end tabular-nums text-[#b45309]">
                                                        {l.deductions > 0 ? m(l.deductions) : '—'}
                                                    </TableCell>
                                                    <TableCell className="text-end font-semibold tabular-nums text-[#111]">
                                                        {m(l.net)}
                                                    </TableCell>
                                                    <TableCell className="text-end">
                                                        {current.editable ? (
                                                            <div className="flex items-center justify-end gap-1">
                                                                <Button variant="ghost" size="sm" onClick={() => editLine(l)}>
                                                                    {t('تعديل')}
                                                                </Button>
                                                                <Button
                                                                    variant="ghost"
                                                                    size="sm"
                                                                    className="text-[#b91c1c]"
                                                                    onClick={() => {
                                                                        if (!confirm(t('حذف سطر الموظّف من المسيرة؟'))) return;
                                                                        router.delete(
                                                                            route('admin.payroll.lines.destroy', l.id),
                                                                            { preserveScroll: true },
                                                                        );
                                                                    }}
                                                                >
                                                                    <Trash2 />
                                                                </Button>
                                                            </div>
                                                        ) : (
                                                            <span className="text-[12px] text-[#9ca3af]">
                                                                {t(l.paid ? l.method ?? 'صُرف' : 'ينتظر الصرف')}
                                                            </span>
                                                        )}
                                                    </TableCell>
                                                </TableRow>
                                            ))
                                        )}
                                    </TableBody>
                                </Table>
                            </Card>

                            {!current.editable && (
                                <p className="mt-3 text-[12px] text-[#9ca3af]">
                                    {t('المسيرة معتمدة: مصروف الرواتب قُيّد والمستحقّ صار التزامًا على المتجر. والصرف يُنقص المستحقّ لا يُقيّد مصروفًا ثانيًا.')}
                                </p>
                            )}
                        </div>
                    )}
                </div>
            )}

            {/* ===== فتح مسيرة ===== */}
            <Dialog open={opening} onOpenChange={setOpening}>
                <DialogContent className="sm:max-w-sm">
                    <DialogHeader>
                        <DialogTitle>{t('فتح مسيرة شهر')}</DialogTitle>
                    </DialogHeader>

                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            openForm.post(route('admin.payroll.store'), {
                                onSuccess: () => setOpening(false),
                            });
                        }}
                        className="space-y-4 px-5 pb-5"
                    >
                        <Field
                            label="الشهر"
                            required
                            hint="تُملأ برواتب الموظّفين كما هي اليوم، ثم تُعدَّل"
                            error={openForm.errors.period}
                        >
                            <Select
                                value={openForm.data.period}
                                onChange={(e) => openForm.setData('period', e.target.value)}
                                options={openPeriods.map((p) => ({ value: p, label: p }))}
                            />
                        </Field>

                        <div className="flex justify-end gap-2">
                            <Button type="button" variant="ghost" onClick={() => setOpening(false)}>
                                {t('إلغاء')}
                            </Button>
                            <Button type="submit" loading={openForm.processing} disabled={!openForm.data.period}>
                                <Check />
                                {t('فتح')}
                            </Button>
                        </div>
                    </form>
                </DialogContent>
            </Dialog>

            {/* ===== تعديل سطر ===== */}
            <Dialog open={editing !== null} onOpenChange={(o) => !o && setEditing(null)}>
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>{editing?.name}</DialogTitle>
                    </DialogHeader>

                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            if (!editing) return;
                            lineForm.put(route('admin.payroll.lines.update', editing.id), {
                                preserveScroll: true,
                                onSuccess: () => setEditing(null),
                            });
                        }}
                        className="space-y-4 px-5 pb-5"
                    >
                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <Field label="الأساسي" required error={lineForm.errors.basic}>
                                <Input
                                    type="number"
                                    step="0.001"
                                    min="0"
                                    dir="ltr"
                                    value={lineForm.data.basic}
                                    onChange={(e) => lineForm.setData('basic', e.target.value)}
                                />
                            </Field>
                            <Field label="البدلات" required error={lineForm.errors.allowances}>
                                <Input
                                    type="number"
                                    step="0.001"
                                    min="0"
                                    dir="ltr"
                                    value={lineForm.data.allowances}
                                    onChange={(e) => lineForm.setData('allowances', e.target.value)}
                                />
                            </Field>
                            <Field label="عمل إضافي" required error={lineForm.errors.overtime}>
                                <Input
                                    type="number"
                                    step="0.001"
                                    min="0"
                                    dir="ltr"
                                    value={lineForm.data.overtime}
                                    onChange={(e) => lineForm.setData('overtime', e.target.value)}
                                />
                            </Field>
                            <Field label="خصومات" required error={lineForm.errors.deductions}>
                                <Input
                                    type="number"
                                    step="0.001"
                                    min="0"
                                    dir="ltr"
                                    value={lineForm.data.deductions}
                                    onChange={(e) => lineForm.setData('deductions', e.target.value)}
                                />
                            </Field>
                        </div>

                        <Field label="ملاحظة" error={lineForm.errors.notes}>
                            <Input
                                value={lineForm.data.notes}
                                onChange={(e) => lineForm.setData('notes', e.target.value)}
                            />
                        </Field>

                        {/* الصافي أمام العين: الخصم يُدخَل وأثره لا يُرى إلا بعد الحفظ */}
                        <div className="flex items-center justify-between rounded-[12px] bg-[#fafafa] px-4 py-3 text-sm">
                            <span className="text-[#6b7280]">{t('الصافي')}</span>
                            <span className="font-semibold tabular-nums text-[#111]">
                                {m(
                                    Math.max(
                                        0,
                                        (parseFloat(lineForm.data.basic) || 0) +
                                            (parseFloat(lineForm.data.allowances) || 0) +
                                            (parseFloat(lineForm.data.overtime) || 0) -
                                            (parseFloat(lineForm.data.deductions) || 0),
                                    ),
                                )}
                            </span>
                        </div>

                        <div className="flex justify-end gap-2">
                            <Button type="button" variant="ghost" onClick={() => setEditing(null)}>
                                {t('إلغاء')}
                            </Button>
                            <Button type="submit" loading={lineForm.processing}>
                                <Check />
                                {t('حفظ')}
                            </Button>
                        </div>
                    </form>
                </DialogContent>
            </Dialog>
        </AdminLayout>
    );
}
