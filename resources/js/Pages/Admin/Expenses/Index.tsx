import { useState } from 'react';
import { router, useForm, usePage } from '@inertiajs/react';
import { AlertTriangle, Check, CheckCircle2, FolderOpen, Paperclip, Plus, Tags } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import SectionTabs, { FINANCE_TABS } from '@/Components/SectionTabs';
import Tabs from '@/Components/Tabs';
import ExportMenu from '@/Components/ExportMenu';
import DataTable, { type Column, type Filter, type ServerPagination } from '@/Components/DataTable';
import RowActions from '@/Components/RowActions';
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

interface ExpenseRow {
    id: number;
    reference: string | null;
    due_date: string | null;
    type: string;
    amount: number;
    status: string;
    attachment: string | null;
    attachment_name: string | null;
    description: string | null;
}

interface ExpenseType {
    id: number;
    name: string;
    description: string | null;
    count: number;
    total: number;
}

interface Props {
    expenses: ExpenseRow[];
    pagination: ServerPagination;
    types: ExpenseType[];
    filters: Record<string, string | null>;
    totalAmount: number;
    totalCount: number;
    unpaidAmount: number;
    unpaidCount: number;
    dueSoonCount: number;
    overdueCount: number;
    /** الشهر المعروض (Y-m) — فارغًا يعني كل الشهور */
    month: string;
    /** مجموع الشهر المعروض — يُحسب على الشهر كلّه لا على صفحته */
    monthTotal: number | null;
    monthUnpaid: number | null;
    monthCount: number | null;
    /** الشهور التي فيها مصروفٌ فعلًا — أحدثها أوّلًا */
    months: string[];
    today: string;
}

export default function ExpensesIndex() {
    const { expenses, pagination, types, filters, totalAmount, totalCount, unpaidAmount, unpaidCount,
        dueSoonCount, overdueCount, month, monthTotal, monthUnpaid, monthCount, months, today, context } =
        usePage<PageProps<Props>>().props;
    const t = useTranslate();
    const currency = context!.currency;
    const m = (v: number) => money(v, currency);

    const [tab, setTab] = useState<'expenses' | 'types'>(filters.tab === 'types' ? 'types' : 'expenses');
    const [addingExpense, setAddingExpense] = useState(false);
    const [addingType, setAddingType] = useState(false);

    const expense = useForm<{
        type: string;
        amount: string;
        description: string;
        spent_at: string;
        due_date: string;
        method: string;
        status: string;
        attachment: File | null;
    }>({
        type: '',
        amount: '',
        description: '',
        spent_at: today,
        due_date: '',
        method: 'نقدي',
        status: 'مدفوع',
        attachment: null,
    });

    const typeForm = useForm({ name: '', description: '' });

    const submitExpense = (e: React.FormEvent) => {
        e.preventDefault();
        expense.post(route('admin.expenses.store'), {
            forceFormData: true,
            onSuccess: () => {
                expense.reset();
                setAddingExpense(false);
            },
        });
    };

    const submitType = (e: React.FormEvent) => {
        e.preventDefault();
        typeForm.post(route('admin.expenseTypes.store'), {
            onSuccess: () => {
                typeForm.reset();
                setAddingType(false);
            },
        });
    };

    const typeOptions = types.map((x) => ({ label: x.name, value: x.name }));

    const expenseColumns: Column<ExpenseRow>[] = [
        {
            key: 'reference',
            header: 'الرقم المرجعي',
            cell: (e) => <span className="font-mono font-medium text-[#111]">{e.reference || '—'}</span>,
        },
        {
            key: 'due_date',
            header: 'تاريخ الإستحقاق',
            cell: (e) => <span dir="ltr" className="text-[#6b7280]">{e.due_date || '—'}</span>,
        },
        { key: 'type', header: 'أنواع المصروفات', cell: (e) => <Badge variant="primary">{e.type}</Badge> },
        {
            key: 'amount',
            header: 'المبلغ',
            align: 'end',
            cell: (e) => <span className="tabular-nums font-semibold">{m(e.amount)}</span>,
        },
        { key: 'status', header: 'الحالة', cell: (e) => <Badge status={e.status}>{t(e.status)}</Badge> },
        {
            key: 'attachment',
            header: 'المرفق',
            cell: (e) =>
                e.attachment ? (
                    <a
                        href={e.attachment}
                        target="_blank"
                        rel="noreferrer"
                        title={e.attachment_name ?? ''}
                        className="inline-flex items-center gap-1.5 text-sm text-[#4b4b4b] hover:underline"
                    >
                        <Paperclip className="size-4 text-[#9ca3af]" />
                        {t('عرض')}
                    </a>
                ) : (
                    <span className="text-[#d1d5db]">—</span>
                ),
        },
        { key: 'description', header: 'ملاحظات', cell: (e) => e.description || '—' },
        {
            key: 'actions',
            header: '',
            align: 'end',
            cell: (e) => (
                <RowActions
                    destroy={{ url: route('admin.expenses.destroy', e.id), message: 'حذف هذا المصروف؟' }}
                    extra={
                        e.status === 'مدفوع'
                            ? []
                            : [
                                  {
                                      // لحظة خروج المال هي لحظة قيده في الدفتر
                                      label: 'تسجيل السداد',
                                      icon: <CheckCircle2 className="size-4" />,
                                      onSelect: () =>
                                          router.post(route('admin.expenses.paid', e.id), {}, { preserveScroll: true }),
                                  },
                              ]
                    }
                />
            ),
        },
    ];

    const expenseFilters: Filter<ExpenseRow>[] = [
        { label: 'كل الأنواع', param: 'type', options: typeOptions },
        {
            label: 'كل الحالات',
            param: 'status',
            options: [
                { label: 'مدفوع', value: 'مدفوع' },
                { label: 'غير مدفوع', value: 'غير مدفوع' },
            ],
        },
    ];

    const typeColumns: Column<ExpenseType>[] = [
        { key: 'name', header: 'الاسم', sortable: true, value: (x) => x.name },
        { key: 'description', header: 'الوصف', cell: (x) => x.description || '—' },
        {
            key: 'count',
            header: 'الاستخدام',
            cell: (x) => (
                <span className="text-[#4b4b4b]">
                    {number(x.count)} {t('مصروف')}
                    {x.total > 0 && <span className="text-[#9ca3af]"> — {m(x.total)}</span>}
                </span>
            ),
        },
        {
            key: 'actions',
            header: '',
            align: 'end',
            cell: (x) => (
                <RowActions
                    destroy={{
                        url: route('admin.expenseTypes.destroy', x.id),
                        message: `حذف نوع «${x.name}»؟ لن تتأثر المصروفات المسجّلة سابقًا.`,
                    }}
                />
            ),
        },
    ];

    return (
        <AdminLayout title="مصاريف شهرية">
            <PageHeader
                title="مصاريف شهرية"
                subtitle={t('ما أُنفق في الشهر المعروض — وأنواع المصروفات')}
                breadcrumbs={[
                    { label: 'الرئيسية', href: route('admin.dashboard') },
                    { label: 'المالية', href: route('admin.finance.index') },
                    { label: 'مصاريف شهرية' },
                ]}
                actions={
                    <>
                        {/*
                            الشهر يُختار هنا لا في شريط التصفية: هو محور الشاشة
                            كلّها — العنوان والمجاميع والقائمة تتبعه، لا عمودٌ
                            من أعمدة الترشيح.
                        */}
                        <Select
                            value={month}
                            aria-label={t('الشهر')}
                            className="w-40"
                            placeholder="كل الشهور"
                            onChange={(e) =>
                                router.get(
                                    route('admin.expenses.index'),
                                    { ...filters, month: e.target.value || 'all', page: null },
                                    { preserveState: true, preserveScroll: true, replace: true },
                                )
                            }
                            options={months.map((x) => ({ value: x, label: x }))}
                        />
                        <Button onClick={() => setAddingExpense(true)}>
                            <Plus />
                            {t('مصروف جديد')}
                        </Button>
                    </>
                }
            />

            <SectionTabs tabs={FINANCE_TABS} current="admin.expenses.index" />

            {/*
                ما يستحقّ يُرى قبل أن يفوت لا بعده — والفائت أوّلًا.
                ولا يظهر الشريط حين لا يكون له ما يقوله.
            */}
            {(overdueCount > 0 || dueSoonCount > 0) && (
                <button
                    type="button"
                    onClick={() => router.get(route('admin.expenses.index'), { status: 'غير مدفوع' }, {
                        preserveScroll: true,
                        preserveState: true,
                    })}
                    className={cn(
                        'mb-4 flex w-full items-center gap-2 rounded-[12px] border px-4 py-3 text-start text-[13px] transition-colors',
                        overdueCount > 0
                            ? 'border-[#fecaca] bg-[#fef2f2] text-[#b91c1c] hover:bg-[#fee2e2]'
                            : 'border-[#fde68a] bg-[#fffbeb] text-[#b45309] hover:bg-[#fef3c7]',
                    )}
                >
                    <AlertTriangle className="size-4 shrink-0" />
                    <span className="font-medium">
                        {overdueCount > 0
                            ? t(':n فاتورة تجاوزت استحقاقها', { n: String(overdueCount) })
                            : t(':n فاتورة تستحقّ خلال أسبوع', { n: String(dueSoonCount) })}
                    </span>
                    <span className="flex-1" />
                    <span className="text-[12px] underline">{t('عرضها')}</span>
                </button>
            )}

            {/* تبويبات داخل الصفحة: تبدّل جزءًا منها ولا تنقل إلى مسار.
                وشكلها شكل شريط القسم فوقها — يفصل بينهما التباعد لا اختلاف
                الشريط، منذ صار للنظام شكل تبويبٍ واحد. */}
            <Tabs
                tabs={[
                    { key: 'expenses', label: 'المصروفات' },
                    { key: 'types', label: 'أنواع المصروفات' },
                ]}
                current={tab}
                onChange={(k) => setTab(k as 'expenses' | 'types')}
            />

            {tab === 'expenses' ? (
                expenses.length === 0 && !filters.q && !filters.type && !filters.status && !month ? (
                    <Card className="px-5 py-16 text-center">
                        <FolderOpen className="mx-auto size-8 text-[#d1d5db]" />
                        <p className="mt-3 font-medium text-[#111]">{t('لا توجد مصروفات')}</p>
                        <p className="mt-1 text-[13px] text-[#9ca3af]">{t('أنشئ أول مصروف')}</p>
                        <Button className="mt-5" onClick={() => setAddingExpense(true)}>
                            <Plus />
                            {t('مصروف جديد')}
                        </Button>
                    </Card>
                ) : (
                    <Card className="overflow-hidden">
                        <DataTable
                            rows={expenses}
                            columns={expenseColumns}
                            rowKey={(e) => e.id}
                            searchPlaceholder="البحث بالمرجع"
                            searchable={() => ''}
                            filters={expenseFilters}
                            empty="لا توجد مصروفات"
                            server={{ pagination, params: filters }}
                            toolbar={
                                <ExportMenu
                                    xlsx={route('admin.expenses.xlsx')}
                                    pdf={route('admin.expenses.exportPdf')}
                                    csv={route('admin.export.expenses')}
                                />
                            }
                        />
                        {/*
                            مجموع الشهر أوّلًا ومجموع العمر بعده.
                            السؤال المطروح على شاشةٍ شهرية هو «كم أنفقتُ هذا
                            الشهر؟» — ورقمُ العمر كلّه يجيب سؤالًا آخر، فيبقى
                            معروضًا ولا يتصدّر.
                        */}
                        {month && monthTotal !== null && (
                            <div className="border-t border-[var(--ui-border,#e8e8e8)] bg-[#fafafa] px-4 py-3 text-sm text-[#374151]">
                                <span className="font-medium">{month}</span> — {t('المدفوع')}:{' '}
                                <span className="font-semibold text-[#111]">{m(monthTotal)}</span>
                                {(monthUnpaid ?? 0) > 0 && (
                                    <>
                                        {' — '}
                                        {t('مستحقّ عليك')}:{' '}
                                        <span className="font-semibold text-[#b45309]">{m(monthUnpaid ?? 0)}</span>
                                    </>
                                )}
                                <span className="text-[#9ca3af]"> ({number(monthCount ?? 0)})</span>
                            </div>
                        )}
                        <div className="border-t border-[var(--ui-border,#e8e8e8)] px-4 py-3 text-sm text-[#6b7280]">
                            {t('كل الشهور')}: {number(totalCount)} — {t('المدفوع')}:{' '}
                            <span className="font-semibold text-[#111]">{m(totalAmount)}</span>
                            {/* المستحقّ لا يُجمع مع المدفوع: الأول التزامٌ عليك والثاني نقدٌ خرج */}
                            {unpaidCount > 0 && (
                                <>
                                    {' — '}
                                    {t('مستحقّ عليك')}:{' '}
                                    <span className="font-semibold text-[#b45309]">{m(unpaidAmount)}</span>
                                    <span className="text-[#9ca3af]"> ({number(unpaidCount)})</span>
                                </>
                            )}
                        </div>
                    </Card>
                )
            ) : types.length === 0 ? (
                <Card className="px-5 py-16 text-center">
                    <Tags className="mx-auto size-8 text-[#d1d5db]" />
                    <p className="mt-3 font-medium text-[#111]">{t('لا توجد أنواع')}</p>
                    <p className="mt-1 text-[13px] text-[#9ca3af]">{t('أضف أول نوع مصروف')}</p>
                    <Button className="mt-5" onClick={() => setAddingType(true)}>
                        <Plus />
                        {t('إضافة نوع')}
                    </Button>
                </Card>
            ) : (
                <Card className="overflow-hidden">
                    <DataTable
                        rows={types}
                        columns={typeColumns}
                        rowKey={(x) => x.id}
                        searchPlaceholder="بحث"
                        searchable={(x) => `${x.name} ${x.description ?? ''}`}
                        empty="لا توجد أنواع"
                        toolbar={
                            <Button onClick={() => setAddingType(true)}>
                                <Plus />
                                {t('إضافة نوع')}
                            </Button>
                        }
                    />
                    <div className="border-t border-[var(--ui-border,#e8e8e8)] px-4 py-3 text-sm text-[#6b7280]">
                        {t('أنواع المصروفات')}: {number(types.length)}
                    </div>
                </Card>
            )}

            {/* مصروف جديد */}
            <Dialog open={addingExpense} onOpenChange={setAddingExpense}>
                <DialogContent className="max-w-lg">
                    <DialogHeader>
                        <DialogTitle>{t('مصروف جديد')}</DialogTitle>
                    </DialogHeader>
                    <form onSubmit={submitExpense} className="space-y-4 px-5 pb-5">
                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <Field label="نوع المصروف" required error={expense.errors.type}>
                                <Select
                                    value={expense.data.type}
                                    onChange={(e) => expense.setData('type', e.target.value)}
                                    options={typeOptions}
                                    placeholder="اختر النوع…"
                                    required
                                />
                            </Field>
                            <Field label={`${t('المبلغ')} (${currency.symbol ?? t('ر.ع')})`} required error={expense.errors.amount}>
                                <Input
                                    type="number"
                                    step="0.001"
                                    dir="ltr"
                                    value={expense.data.amount}
                                    onChange={(e) => expense.setData('amount', e.target.value)}
                                    placeholder="0.000"
                                    required
                                />
                            </Field>
                        </div>

                        <Field label="ملاحظات" error={expense.errors.description}>
                            <Input
                                value={expense.data.description}
                                onChange={(e) => expense.setData('description', e.target.value)}
                                placeholder={t('مثال: إيجار المحل لشهر يوليو')}
                            />
                        </Field>

                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <Field label="تاريخ الصرف" required error={expense.errors.spent_at}>
                                <Input
                                    type="date"
                                    value={expense.data.spent_at}
                                    onChange={(e) => expense.setData('spent_at', e.target.value)}
                                    required
                                />
                            </Field>
                            <Field label="طريقة الدفع" error={expense.errors.method}>
                                <Select
                                    value={expense.data.method}
                                    onChange={(e) => expense.setData('method', e.target.value)}
                                    options={[
                                        { label: 'نقدي', value: 'نقدي' },
                                        { label: 'بطاقة', value: 'بطاقة' },
                                        { label: 'تحويل بنكي', value: 'تحويل بنكي' },
                                    ]}
                                />
                            </Field>
                        </div>

                        <Field
                            label="الحالة"
                            hint="«غير مدفوع» التزامٌ عليك لا نقدٌ خرج — لا يُخصم من الربح حتى تسدّده"
                            error={expense.errors.status}
                        >
                            <Select
                                value={expense.data.status}
                                onChange={(e) => expense.setData('status', e.target.value)}
                                options={[
                                    { label: 'مدفوع', value: 'مدفوع' },
                                    { label: 'غير مدفوع', value: 'غير مدفوع' },
                                ]}
                            />
                        </Field>

                        {/* تاريخ الاستحقاق لا معنى له على فاتورةٍ سُدِّدت */}
                        {expense.data.status !== 'مدفوع' && (
                            <Field label="تاريخ الاستحقاق" error={expense.errors.due_date}>
                                <Input
                                    type="date"
                                    value={expense.data.due_date}
                                    onChange={(e) => expense.setData('due_date', e.target.value)}
                                />
                            </Field>
                        )}

                        <Field
                            label="المرفق"
                            hint="الصيغ المدعومة: JPG، PNG، PDF، WEBP، HEIC — بحد أقصى 10 ميجابايت."
                            error={expense.errors.attachment}
                        >
                            <Input
                                type="file"
                                accept=".jpg,.jpeg,.png,.pdf,.webp,.heic"
                                onChange={(e) => expense.setData('attachment', e.target.files?.[0] ?? null)}
                                className="h-auto py-2 file:me-3 file:rounded-lg file:bg-[#111] file:px-4 file:py-2 file:text-white"
                            />
                        </Field>

                        <div className="flex justify-end gap-2 pt-1">
                            <Button type="button" variant="ghost" onClick={() => setAddingExpense(false)}>
                                {t('إلغاء')}
                            </Button>
                            <Button type="submit" loading={expense.processing}>
                                <Check />
                                {t('حفظ المصروف')}
                            </Button>
                        </div>
                    </form>
                </DialogContent>
            </Dialog>

            {/* نوع مصروف جديد */}
            <Dialog open={addingType} onOpenChange={setAddingType}>
                <DialogContent className="max-w-md">
                    <DialogHeader>
                        <DialogTitle>{t('إضافة نوع مصروف')}</DialogTitle>
                    </DialogHeader>
                    <form onSubmit={submitType} className="space-y-4 px-5 pb-5">
                        <Field label="الاسم" required error={typeForm.errors.name}>
                            <Input
                                value={typeForm.data.name}
                                onChange={(e) => typeForm.setData('name', e.target.value)}
                                placeholder={t('مثال: اشتراكات وبرمجيات')}
                                required
                            />
                        </Field>
                        <Field label="الوصف" error={typeForm.errors.description}>
                            <Input
                                value={typeForm.data.description}
                                onChange={(e) => typeForm.setData('description', e.target.value)}
                                placeholder={t('وصف مختصر للنوع')}
                            />
                        </Field>
                        <div className="flex justify-end gap-2 pt-1">
                            <Button type="button" variant="ghost" onClick={() => setAddingType(false)}>
                                {t('إلغاء')}
                            </Button>
                            <Button type="submit" loading={typeForm.processing}>
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
