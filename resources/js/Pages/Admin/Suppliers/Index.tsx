import { useEffect, useState } from 'react';
import { useForm, usePage } from '@inertiajs/react';
import { ClipboardList, Phone, Plus } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import SectionTabs, { CUSTOMER_TABS } from '@/Components/SectionTabs';
import DataTable, { type Column } from '@/Components/DataTable';
import RowActions from '@/Components/RowActions';
import SmartLink from '@/Components/SmartLink';
import StatCard from '@/Components/StatCard';
import Field from '@/Components/Field';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { Input, Textarea } from '@/Components/ui/input';
import { number } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import type { PageProps } from '@/types';
import type { Supplier } from '@/types/models';

const BLANK = { name: '', phone: '', contact_person: '', email: '', notes: '' };

export default function SuppliersIndex() {
    const { suppliers } = usePage<PageProps<{ suppliers: Supplier[] }>>().props;
    const t = useTranslate();
    const [adding, setAdding] = useState(false);
    const [editing, setEditing] = useState<Supplier | null>(null);

    const form = useForm({ ...BLANK });

    // كل فتح يبدأ من بيانات الصف المختار لا من بقايا الفتح السابق
    useEffect(() => {
        if (editing) {
            form.setData({
                name: editing.name,
                phone: editing.phone ?? '',
                contact_person: editing.contact ?? '',
                email: editing.email ?? '',
                notes: editing.notes ?? '',
            });
        } else if (adding) {
            form.setData({ ...BLANK });
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [editing, adding]);

    const close = () => {
        setAdding(false);
        setEditing(null);
        form.clearErrors();
    };

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        const done = { preserveScroll: true, onSuccess: close };
        if (editing) form.put(route('admin.suppliers.update', editing.id), done);
        else form.post(route('admin.suppliers.store'), done);
    };

    const stats = [
        { label: t('إجمالي المورّدين'), value: number(suppliers.length), icon: 'truck', color: 'primary' },
        {
            label: t('مورّدون لديهم أوامر'),
            value: number(suppliers.filter((s) => s.orders_count > 0).length),
            icon: 'clipboard-check',
            color: 'success',
        },
        {
            label: t('إجمالي أوامر الشراء'),
            value: number(suppliers.reduce((n, s) => n + s.orders_count, 0)),
            icon: 'package',
            color: 'info',
        },
    ];

    const columns: Column<Supplier>[] = [
        { key: 'name', header: 'المورّد', sortable: true, value: (s) => s.name },
        {
            key: 'phone',
            header: 'الهاتف',
            cell: (s) => (
                <span dir="ltr" className="text-[#6b7280]">
                    {s.phone || '—'}
                </span>
            ),
        },
        { key: 'contact', header: 'الشخص المسؤول', cell: (s) => s.contact || '—' },
        {
            key: 'email',
            header: 'البريد',
            cell: (s) => (
                <span dir="ltr" className="text-[#6b7280]">
                    {s.email || '—'}
                </span>
            ),
        },
        {
            key: 'orders_count',
            header: 'أوامر الشراء',
            align: 'end',
            sortable: true,
            value: (s) => s.orders_count,
            cell: (s) => <Badge variant="info">{`${number(s.orders_count)} ${t('أمر')}`}</Badge>,
        },
        {
            key: 'actions',
            header: 'إجراءات',
            align: 'end',
            cell: (s) => (
                <RowActions
                    destroy={{ url: route('admin.suppliers.destroy', s.id), message: 'حذف المورّد؟' }}
                    extra={[
                        ...(s.phone
                            ? [{ label: 'اتصال', icon: <Phone className="size-4" />, href: `tel:${s.phone}` }]
                            : []),
                        { label: 'تعديل', onSelect: () => setEditing(s) },
                    ]}
                />
            ),
        },
    ];

    const fields = (
        <div className="space-y-4">
            <Field label="اسم المورّد" required error={form.errors.name}>
                <Input value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} required />
            </Field>
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <Field label="الهاتف" error={form.errors.phone}>
                    <Input dir="ltr" value={form.data.phone} onChange={(e) => form.setData('phone', e.target.value)} />
                </Field>
                <Field label="الشخص المسؤول" error={form.errors.contact_person}>
                    <Input
                        value={form.data.contact_person}
                        onChange={(e) => form.setData('contact_person', e.target.value)}
                    />
                </Field>
            </div>
            <Field label="البريد الإلكتروني" error={form.errors.email}>
                <Input
                    type="email"
                    dir="ltr"
                    value={form.data.email}
                    onChange={(e) => form.setData('email', e.target.value)}
                />
            </Field>
            <Field label="ملاحظات" error={form.errors.notes}>
                <Textarea rows={2} value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} />
            </Field>
        </div>
    );

    return (
        <AdminLayout title="المورّدون">
            <PageHeader
                title="المورّدون"
                subtitle={t('إدارة موردي البضاعة وبيانات التواصل معهم')}
                breadcrumbs={[{ label: 'الرئيسية', href: route('admin.dashboard') }, { label: 'المورّدون' }]}
                actions={
                    <>
                        <Button variant="outline" asChild>
                            <SmartLink routeName="admin.purchases.index" href={route('admin.purchases.index')}>
                                <ClipboardList />
                                {t('أوامر الشراء')}
                            </SmartLink>
                        </Button>
                        <Button onClick={() => setAdding(true)}>
                            <Plus />
                            {t('مورّد جديد')}
                        </Button>
                    </>
                }
            />

            <SectionTabs tabs={CUSTOMER_TABS} current="admin.suppliers.index" />

            <div className="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
                {stats.map((s, i) => (
                    <StatCard key={s.label} stat={s} index={i} />
                ))}
            </div>

            <Card className="overflow-hidden">
                <DataTable
                    rows={suppliers}
                    columns={columns}
                    rowKey={(s) => s.id}
                    searchPlaceholder="ابحث باسم المورّد أو الهاتف…"
                    searchable={(s) => `${s.name} ${s.phone} ${s.email} ${s.contact}`}
                    empty="أضِف أول مورّد لبدء إنشاء أوامر الشراء."
                />
            </Card>

            <Dialog open={adding || editing !== null} onOpenChange={(o) => !o && close()}>
                {/*
                    نفس قياسات نافذة «إضافة حركة مخزون» حرفيًّا: max-w-lg على
                    الحاوية، و px-5 pb-5 على النموذج. بدونهما كانت الحقول تلتصق
                    بحافّتَي النافذة وتتمدّد النافذة بلا سقف — والمحتوى كما هو.
                */}
                <DialogContent className="max-w-lg">
                    <DialogHeader>
                        <DialogTitle>{t(editing ? 'تعديل بيانات المورّد' : 'إضافة مورّد جديد')}</DialogTitle>
                    </DialogHeader>
                    <form onSubmit={submit} className="space-y-4 px-5 pb-5">
                        {fields}
                        <div className="flex justify-end gap-2 pt-1">
                            <Button type="button" variant="ghost" onClick={close}>
                                {t('إلغاء')}
                            </Button>
                            <Button type="submit" loading={form.processing}>
                                {t(editing ? 'حفظ' : 'إضافة')}
                            </Button>
                        </div>
                    </form>
                </DialogContent>
            </Dialog>
        </AdminLayout>
    );
}
