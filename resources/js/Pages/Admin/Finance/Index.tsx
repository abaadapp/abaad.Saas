import { useState } from 'react';
import { useForm, usePage } from '@inertiajs/react';
import { Banknote, CreditCard, Landmark, Plus } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import ExportMenu from '@/Components/ExportMenu';
import StatCard, { type Stat } from '@/Components/StatCard';
import SmartLink from '@/Components/SmartLink';
import DataTable, { type Column, type Filter } from '@/Components/DataTable';
import Field, { Select } from '@/Components/Field';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { Input } from '@/Components/ui/input';
import { money, number } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { PageProps } from '@/types';
import type { Transaction } from '@/types/models';

interface ProfitStats {
    net_revenue: number;
    cogs: number;
    gross_profit: number;
    expenses: number;
    net_profit: number;
    margin: number;
}

interface PaymentMethod {
    name: string;
    key: string;
    icon: string;
    color: string;
    total: number;
    count: number;
    percent: number;
}

interface Props {
    financeStats: Stat[];
    profitStats: ProfitStats;
    paymentMethods: PaymentMethod[];
    transactions: Transaction[];
}

/** وسائل الدفع المتاحة للتسجيل اليدوي — القيم هي ما يقبله المتحكّم حرفيًا */
const METHODS = [
    { value: 'نقدي', label: 'نقدي', icon: Banknote },
    { value: 'تحويل بنكي', label: 'تحويل بنكي', icon: Landmark },
    { value: 'بطاقة', label: 'بطاقة (فيزا)', icon: CreditCard },
] as const;

export default function FinanceIndex() {
    const { financeStats, profitStats, paymentMethods, transactions, context } = usePage<PageProps<Props>>().props;
    const t = useTranslate();
    const currency = context!.currency;
    const m = (v: number) => money(v, currency);
    const [adding, setAdding] = useState(false);

    const form = useForm({
        type: 'دخل',
        amount: '',
        description: '',
        method: 'نقدي',
        occurred_at: '',
    });

    const close = () => {
        setAdding(false);
        form.reset();
        form.clearErrors();
    };

    const pl = [
        { label: 'صافي الإيراد', value: profitStats.net_revenue },
        { label: 'تكلفة البضاعة المباعة', value: -profitStats.cogs },
        { label: 'مجمل الربح', value: profitStats.gross_profit, strong: true },
        { label: 'المصروفات التشغيلية', value: -profitStats.expenses },
    ];

    const columns: Column<Transaction>[] = [
        { key: 'id', header: 'المرجع', cell: (x) => <span className="font-mono text-[#4b4b4b]">{x.id}</span> },
        {
            key: 'date',
            header: 'التاريخ',
            sortable: true,
            value: (x) => x.date,
            cell: (x) => <span dir="ltr" className="text-[#6b7280]">{x.date}</span>,
        },
        { key: 'description', header: 'الوصف', cell: (x) => x.description || '—' },
        { key: 'method', header: 'الوسيلة', cell: (x) => t(x.method) },
        { key: 'employee', header: 'الموظف', cell: (x) => x.employee || '—' },
        {
            key: 'type',
            header: 'النوع',
            cell: (x) => <Badge variant={x.type === 'دخل' ? 'success' : 'danger'}>{t(x.type)}</Badge>,
        },
        {
            key: 'amount',
            header: 'المبلغ',
            align: 'end',
            sortable: true,
            value: (x) => x.amount,
            cell: (x) => (
                <span
                    className={cn(
                        'font-semibold tabular-nums',
                        x.type === 'دخل' ? 'text-[#047857]' : 'text-[#b91c1c]',
                    )}
                >
                    {x.type === 'دخل' ? '+' : '−'} {m(Math.abs(x.amount))}
                </span>
            ),
        },
    ];

    const filters: Filter<Transaction>[] = [
        {
            label: 'كل الأنواع',
            options: [
                { label: 'دخل', value: 'دخل' },
                { label: 'مصروف', value: 'مصروف' },
            ],
            match: (x, v) => x.type === v,
        },
    ];

    return (
        <AdminLayout title="المالية">
            <PageHeader
                title="المالية"
                subtitle={t('الإيرادات والمصروفات وقائمة الدخل')}
                breadcrumbs={[{ label: 'الرئيسية', href: route('admin.dashboard') }, { label: 'المالية' }]}
                actions={
                    <>
                        <Button variant="outline" asChild>
                            <SmartLink routeName="admin.finance.statement" href={route('admin.finance.statement')}>
                                {t('كشف الحساب البنكي')}
                            </SmartLink>
                        </Button>
                        <ExportMenu
                            xlsx={route('admin.finance.xlsx')}
                            pdf={route('admin.finance.pdf')}
                            csv={route('admin.export.transactions')}
                        />
                        <Button onClick={() => setAdding(true)}>
                            <Plus />
                            {t('تسجيل معاملة')}
                        </Button>
                    </>
                }
            />

            <div className="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                {financeStats.map((s, i) => (
                    <StatCard key={s.label} stat={s} index={i} />
                ))}
            </div>

            <div className="mb-6 grid grid-cols-1 gap-6 lg:grid-cols-3">
                <Card className="p-5">
                    <h3 className="mb-4 font-bold text-[#111]">{t('قائمة الدخل')}</h3>
                    <ul className="space-y-3 text-sm">
                        {pl.map((r) => (
                            <li
                                key={r.label}
                                className={cn(
                                    'flex items-center justify-between',
                                    r.strong && 'border-t border-[#f5f5f4] pt-3',
                                )}
                            >
                                <span className="text-[#6b7280]">{t(r.label)}</span>
                                <span
                                    className={cn(
                                        'tabular-nums',
                                        r.value < 0 ? 'text-[#b91c1c]' : 'text-[#111]',
                                        r.strong && 'font-semibold',
                                    )}
                                >
                                    {m(r.value)}
                                </span>
                            </li>
                        ))}
                        <li className="mt-1 flex items-center justify-between border-t-2 border-[#ede9fe] pt-3">
                            <span className="font-bold text-[#111]">{t('صافي الربح')}</span>
                            <span
                                className={cn(
                                    'text-[18px] font-bold tabular-nums',
                                    profitStats.net_profit >= 0 ? 'text-[#047857]' : 'text-[#b91c1c]',
                                )}
                            >
                                {m(profitStats.net_profit)}
                            </span>
                        </li>
                        <li className="flex items-center justify-between">
                            <span className="text-[#6b7280]">{t('هامش الربح')}</span>
                            <span className="tabular-nums text-[#4b4b4b]">{number(profitStats.margin)}%</span>
                        </li>
                    </ul>
                </Card>

                <Card className="p-5 lg:col-span-2">
                    <h3 className="mb-4 font-bold text-[#111]">{t('حسب وسيلة الدفع')}</h3>
                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                        {paymentMethods.map((p) => (
                            <div key={p.key} className="rounded-[12px] border border-[var(--ui-border,#e8e8e8)] p-4">
                                <p className="text-[13px] text-[#6b7280]">{t(p.name)}</p>
                                <p className="mt-1 text-[18px] font-bold tabular-nums text-[#111]">{m(p.total)}</p>
                                <p className="mt-1 text-[12px] text-[#9ca3af]">
                                    {number(p.count)} {t('عملية')} · {number(p.percent)}%
                                </p>
                            </div>
                        ))}
                    </div>
                </Card>
            </div>

            <Card className="overflow-hidden">
                <div className="border-b border-[var(--ui-border,#e8e8e8)] px-5 pt-5">
                    <h3 className="font-bold text-[#111]">{t('الحركات المالية')}</h3>
                </div>
                <DataTable
                    rows={transactions}
                    columns={columns}
                    rowKey={(x) => x.key}
                    searchPlaceholder="ابحث بالمرجع أو الوصف…"
                    searchable={(x) => `${x.id} ${x.description} ${x.employee} ${x.method}`}
                    filters={filters}
                    empty="لا توجد حركات مالية بعد"
                />
            </Card>

            <Dialog open={adding} onOpenChange={(o) => !o && close()}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>{t('تسجيل معاملة مالية')}</DialogTitle>
                    </DialogHeader>
                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            form.post(route('admin.finance.store'), {
                                preserveScroll: true,
                                onSuccess: close,
                            });
                        }}
                        className="space-y-4"
                    >
                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <Field label="نوع المعاملة" error={form.errors.type}>
                                <Select
                                    value={form.data.type}
                                    onChange={(e) => form.setData('type', e.target.value)}
                                    options={[
                                        { label: 'دخل', value: 'دخل' },
                                        { label: 'مصروف', value: 'مصروف' },
                                    ]}
                                />
                            </Field>
                            <Field label="المبلغ" required error={form.errors.amount}>
                                <Input
                                    type="number"
                                    step="0.001"
                                    min="0"
                                    dir="ltr"
                                    placeholder="0.000"
                                    value={form.data.amount}
                                    onChange={(e) => form.setData('amount', e.target.value)}
                                    required
                                />
                            </Field>
                        </div>

                        <Field label="الوصف" error={form.errors.description}>
                            <Input
                                value={form.data.description}
                                onChange={(e) => form.setData('description', e.target.value)}
                                placeholder={t('وصف المعاملة أو المصدر')}
                            />
                        </Field>

                        <Field label="التاريخ" error={form.errors.occurred_at}>
                            <Input
                                type="date"
                                dir="ltr"
                                value={form.data.occurred_at}
                                onChange={(e) => form.setData('occurred_at', e.target.value)}
                            />
                        </Field>

                        <Field label="وسيلة الدفع" error={form.errors.method}>
                            <div className="grid grid-cols-3 gap-2">
                                {METHODS.map((x) => (
                                    <button
                                        key={x.value}
                                        type="button"
                                        onClick={() => form.setData('method', x.value)}
                                        className={cn(
                                            'flex flex-col items-center gap-1 rounded-[12px] border py-3 text-[12px] font-medium transition-colors',
                                            form.data.method === x.value
                                                ? 'border-[#111] bg-[#fafafa] text-[#111]'
                                                : 'border-[var(--ui-border,#e8e8e8)] text-[#6b7280] hover:bg-[#fafafa]',
                                        )}
                                    >
                                        <x.icon className="size-5" />
                                        {t(x.label)}
                                    </button>
                                ))}
                            </div>
                        </Field>

                        <div className="flex justify-end gap-2 pt-2">
                            <Button type="button" variant="ghost" onClick={close}>
                                {t('إلغاء')}
                            </Button>
                            <Button type="submit" disabled={form.processing}>
                                {form.processing ? '…' : t('حفظ المعاملة')}
                            </Button>
                        </div>
                    </form>
                </DialogContent>
            </Dialog>
        </AdminLayout>
    );
}
