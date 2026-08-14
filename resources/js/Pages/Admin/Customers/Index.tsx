import { useState } from 'react';
import { useForm, usePage } from '@inertiajs/react';
import { Eye, FileDown, FileSpreadsheet, FileText, MoreVertical, Upload, UserPlus } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import SectionTabs, { CUSTOMER_TABS } from '@/Components/SectionTabs';
import StatCard, { type Stat } from '@/Components/StatCard';
import DataTable, { type Column, type Filter, type ServerPagination } from '@/Components/DataTable';
import Field, { Select } from '@/Components/Field';
import SmartLink from '@/Components/SmartLink';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/Components/ui/dropdown-menu';
import { Input } from '@/Components/ui/input';
import { money, number } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import type { PageProps } from '@/types';
import type { Branch, Customer } from '@/types/models';

interface Props {
    customers: Customer[];
    pagination: ServerPagination;
    filters: Record<string, string | null>;
    stats: Stat[];
    branches: Branch[];
    currentBranchId: number | null;
}

export default function CustomersIndex() {
    const { customers, pagination, filters, stats, branches, currentBranchId, context } =
        usePage<PageProps<Props>>().props;
    const t = useTranslate();
    const currency = context!.currency;
    const [adding, setAdding] = useState(false);
    const [importing, setImporting] = useState(false);

    /**
     * حقل اسم واحد بلا مقابل إنجليزي: Customers::localizeName يكتشف لغة
     * المُدخَل — اللاتيني يُنقل إلى العربية في name ويُحفظ الأصل في name_en،
     * والعربي يبقى كما هو. أي name_en يُرسل يدويًا يُكتب فوقه.
     */
    const add = useForm({
        name: '',
        phone: '',
        email: '',
        branch_id: currentBranchId ? String(currentBranchId) : '',
    });

    const upload = useForm<{ file: File | null; branch_id: string }>({ file: null, branch_id: '' });

    const branchOptions = branches.map((b) => ({ label: b.name, value: b.id }));

    const submitAdd = (e: React.FormEvent) => {
        e.preventDefault();
        add.post(route('admin.customers.store'), {
            preserveScroll: true,
            onSuccess: () => {
                add.reset();
                setAdding(false);
            },
        });
    };

    const submitImport = (e: React.FormEvent) => {
        e.preventDefault();
        upload.post(route('admin.customers.import.upload'), { forceFormData: true });
    };

    const columns: Column<Customer>[] = [
        {
            key: 'name',
            header: 'العميل',
            cell: (c) => (
                <div className="flex items-center gap-3">
                    {c.avatar ? (
                        <img src={c.avatar} alt="" className="size-9 rounded-full object-cover ring-2 ring-[#f2f2f0]" loading="lazy" />
                    ) : (
                        <span className="size-9 rounded-full bg-[#f2f2f0]" />
                    )}
                    <span className="min-w-0">
                        <span className="block truncate font-medium text-[#111]">{c.label || c.name}</span>
                        {c.name_en && c.label !== c.name && (
                            <span className="block truncate text-[11px] text-[#9ca3af]">{c.name}</span>
                        )}
                        <span className="block font-mono text-[11px] text-[#9ca3af]">#{c.id}</span>
                    </span>
                </div>
            ),
        },
        {
            key: 'phone',
            header: 'الهاتف',
            cell: (c) => (c.phone ? <span dir="ltr" className="text-[#4b4b4b]">{c.phone}</span> : '—'),
        },
        {
            key: 'email',
            header: 'البريد',
            cell: (c) => (c.email ? <span dir="ltr" className="text-[#6b7280]">{c.email}</span> : '—'),
        },
        {
            key: 'orders',
            header: 'عدد الطلبات',
            align: 'end',
            cell: (c) => <span className="tabular-nums">{number(c.orders)}</span>,
        },
        {
            key: 'total_spent',
            header: 'إجمالي المشتريات',
            align: 'end',
            cell: (c) => <span className="tabular-nums font-semibold">{money(c.total_spent, currency)}</span>,
        },
        {
            key: 'last_order',
            header: 'آخر طلب',
            cell: (c) => <span dir="ltr" className="text-[#6b7280]">{c.last_order}</span>,
        },
        {
            key: 'points',
            header: 'نقاط الولاء',
            align: 'end',
            cell: (c) => (
                <Badge variant="warning">
                    {number(c.points)} {t('نقطة')}
                </Badge>
            ),
        },
        {
            key: 'actions',
            header: 'إجراءات',
            align: 'end',
            cell: (c) => (
                <Button variant="outline" size="sm" asChild>
                    <SmartLink routeName="admin.customers.show" href={route('admin.customers.show', c.id)}>
                        <Eye />
                        {t('عرض')}
                    </SmartLink>
                </Button>
            ),
        },
    ];

    const sortFilter: Filter<Customer>[] = [
        {
            label: 'الأحدث تسجيلًا',
            param: 'sort',
            options: [
                { label: 'الأعلى إنفاقًا', value: 'spent' },
                { label: 'الأكثر طلبات', value: 'orders' },
                { label: 'الأعلى نقاطًا', value: 'points' },
                { label: 'الاسم (أ - ي)', value: 'name' },
            ],
        },
    ];

    return (
        <AdminLayout title="العملاء">
            <PageHeader
                title="العملاء"
                subtitle={t('إدارة قاعدة عملاء المحل وسجل مشترياتهم ونقاط ولائهم')}
                breadcrumbs={[{ label: 'الرئيسية', href: route('admin.dashboard') }, { label: 'العملاء' }]}
                actions={
                    <>
                        <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                                <Button variant="outline" size="icon" aria-label={t('المزيد')}>
                                    <MoreVertical />
                                </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end" className="w-60">
                                <DropdownMenuLabel>{t('تصدير')}</DropdownMenuLabel>
                                <DropdownMenuItem asChild>
                                    <a href={route('admin.customers.export.xlsx')}>
                                        <FileSpreadsheet className="text-[#059669]" />
                                        {t('تصدير Excel (xlsx)')}
                                    </a>
                                </DropdownMenuItem>
                                <DropdownMenuItem asChild>
                                    <a href={route('admin.customers.export.pdf')} target="_blank" rel="noreferrer">
                                        <FileText className="text-[#dc2626]" />
                                        {t('تصدير PDF')}
                                    </a>
                                </DropdownMenuItem>
                                <DropdownMenuItem asChild>
                                    <a href={route('admin.export.customers')}>
                                        <FileDown className="text-[#6b7280]" />
                                        {t('تصدير CSV')}
                                    </a>
                                </DropdownMenuItem>
                                <DropdownMenuSeparator />
                                <DropdownMenuLabel>{t('استيراد')}</DropdownMenuLabel>
                                <DropdownMenuItem onSelect={() => setImporting(true)}>
                                    <Upload className="text-[#6d28d9]" />
                                    {t('استيراد من ملف…')}
                                </DropdownMenuItem>
                            </DropdownMenuContent>
                        </DropdownMenu>

                        <Button onClick={() => setAdding(true)}>
                            <UserPlus />
                            {t('إضافة عميل')}
                        </Button>
                    </>
                }
            />

            <SectionTabs tabs={CUSTOMER_TABS} current="admin.customers.index" />

            <div className="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                {stats.map((s, i) => (
                    <StatCard key={s.label} stat={s} index={i} />
                ))}
            </div>

            <Card className="overflow-hidden">
                <DataTable
                    rows={customers}
                    columns={columns}
                    rowKey={(c) => c.id}
                    searchPlaceholder="ابحث بالاسم أو رقم الهاتف أو البريد…"
                    searchable={() => ''}
                    filters={sortFilter}
                    empty="لا يوجد عملاء بعد"
                    server={{ pagination, params: filters }}
                />
            </Card>

            {/* إضافة عميل */}
            <Dialog open={adding} onOpenChange={setAdding}>
                <DialogContent className="max-w-lg">
                    <DialogHeader>
                        <DialogTitle>{t('إضافة عميل جديد')}</DialogTitle>
                    </DialogHeader>
                    <form onSubmit={submitAdd} className="space-y-4 px-5 pb-5">
                        <Field label="اسم العميل" required error={add.errors.name}>
                            <Input
                                value={add.data.name}
                                onChange={(e) => add.setData('name', e.target.value)}
                                placeholder={t('مثال: محمد سالم')}
                                required
                            />
                        </Field>
                        <Field label="رقم الهاتف" required error={add.errors.phone}>
                            <Input
                                type="tel"
                                dir="ltr"
                                value={add.data.phone}
                                onChange={(e) => add.setData('phone', e.target.value)}
                                placeholder="+968 9xxxxxxx"
                                required
                            />
                        </Field>
                        <Field
                            label="البريد الإلكتروني"
                            hint="اختياري — لإرسال الفواتير والعروض"
                            error={add.errors.email}
                        >
                            <Input
                                type="email"
                                dir="ltr"
                                value={add.data.email}
                                onChange={(e) => add.setData('email', e.target.value)}
                                placeholder="name@example.com"
                            />
                        </Field>
                        <Field label="الفرع" hint="اختياري — يُربط العميل بالفرع المحدد." error={add.errors.branch_id}>
                            <Select
                                value={add.data.branch_id}
                                onChange={(e) => add.setData('branch_id', e.target.value)}
                                options={branchOptions}
                                placeholder="بدون فرع (عام)"
                            />
                        </Field>

                        <div className="flex justify-end gap-2 pt-1">
                            <Button type="button" variant="ghost" onClick={() => setAdding(false)}>
                                {t('إلغاء')}
                            </Button>
                            <Button type="submit" loading={add.processing}>
                                {t('حفظ العميل')}
                            </Button>
                        </div>
                    </form>
                </DialogContent>
            </Dialog>

            {/* استيراد العملاء */}
            <Dialog open={importing} onOpenChange={setImporting}>
                <DialogContent className="max-w-lg">
                    <DialogHeader>
                        <DialogTitle>{t('استيراد العملاء من ملف')}</DialogTitle>
                    </DialogHeader>
                    <form onSubmit={submitImport} className="space-y-4 px-5 pb-5">
                        <Field
                            label="ملف العملاء"
                            hint="الصيغ المدعومة: CSV، XLS، XLSX، XLSM — الأعمدة: الاسم، الهاتف، البريد، العنوان، الفرع، النقاط. يمكنك تصدير ملف ثم تعديله وإعادة استيراده."
                            error={upload.errors.file}
                            required
                        >
                            <Input
                                type="file"
                                accept=".csv,.xls,.xlsx,.xlsm"
                                required
                                onChange={(e) => upload.setData('file', e.target.files?.[0] ?? null)}
                                className="h-auto py-2 file:me-3 file:rounded-lg file:bg-[#111] file:px-4 file:py-2 file:text-white"
                            />
                        </Field>

                        <Field
                            label="الفرع الافتراضي"
                            hint="يُستخدم فقط للصفوف التي لا تحوي عمود «الفرع» في الملف — وإلا يُعتمد فرع الملف."
                            error={upload.errors.branch_id}
                        >
                            <Select
                                value={upload.data.branch_id}
                                onChange={(e) => upload.setData('branch_id', e.target.value)}
                                options={branchOptions}
                                placeholder="بدون فرع (عام)"
                            />
                        </Field>

                        <p className="rounded-[12px] bg-[#f5f3ff] px-3 py-2.5 text-[12px] text-[#6d28d9]">
                            {t('العملاء الموجودون مسبقًا (بنفس الهاتف) يُحدَّثون بدل تكرارهم، مع الحفاظ على فروعهم. وستظهر معاينة كاملة قبل التأكيد.')}
                        </p>

                        <div className="flex justify-end gap-2 pt-1">
                            <Button type="button" variant="ghost" onClick={() => setImporting(false)}>
                                {t('إلغاء')}
                            </Button>
                            <Button type="submit" loading={upload.processing}>
                                <Eye />
                                {t('معاينة الملف')}
                            </Button>
                        </div>
                    </form>
                </DialogContent>
            </Dialog>
        </AdminLayout>
    );
}
