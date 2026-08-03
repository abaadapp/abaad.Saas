import { useState } from 'react';
import { useForm, usePage } from '@inertiajs/react';
import { ArrowRight, Check, Plus } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import SectionTabs, { INVENTORY_TABS } from '@/Components/SectionTabs';
import DataTable, { type Column, type Filter } from '@/Components/DataTable';
import SmartLink from '@/Components/SmartLink';
import Field, { Select } from '@/Components/Field';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { Input, Textarea } from '@/Components/ui/input';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { PageProps } from '@/types';
import type { Branch, Movement, Product } from '@/types/models';

interface Props {
    movements: Movement[];
    branches: Branch[];
    products: Product[];
    currentBranchId: number | null;
}

const TYPES = ['إضافة كمية', 'خصم كمية', 'مرتجع', 'تلف', 'تعديل يدوي'];

const TYPE_VARIANT: Record<string, 'success' | 'danger' | 'info' | 'warning' | 'primary'> = {
    'إضافة كمية': 'success',
    'خصم كمية': 'danger',
    مرتجع: 'info',
    تلف: 'warning',
    'تعديل يدوي': 'primary',
};

export default function Movements() {
    const { movements, branches, products, currentBranchId } = usePage<PageProps<Props>>().props;
    const t = useTranslate();
    const [adding, setAdding] = useState(false);

    const form = useForm({
        branch_id: currentBranchId ? String(currentBranchId) : '',
        product_id: '',
        type: '',
        quantity: '',
        note: '',
    });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.post(route('admin.inventory.store'), {
            onSuccess: () => {
                form.reset();
                setAdding(false);
            },
        });
    };

    const columns: Column<Movement>[] = [
        { key: 'product', header: 'المنتج', sortable: true, value: (m) => m.product, cell: (m) => (
            <span className="font-medium text-[#111]">{m.product}</span>
        ) },
        { key: 'sku', header: 'SKU', cell: (m) => <span className="font-mono text-[#6b7280]">{m.sku}</span> },
        { key: 'branch', header: 'الفرع', cell: (m) => m.branch || '—' },
        {
            key: 'type',
            header: 'نوع الحركة',
            cell: (m) => <Badge variant={TYPE_VARIANT[m.type] ?? 'neutral'}>{t(m.type)}</Badge>,
        },
        {
            key: 'qty',
            header: 'الكمية',
            cell: (m) => (
                <span
                    dir="ltr"
                    className={cn(
                        'font-semibold tabular-nums',
                        String(m.qty).startsWith('+') ? 'text-[#047857]' : 'text-[#b91c1c]',
                    )}
                >
                    {m.qty}
                </span>
            ),
        },
        { key: 'employee', header: 'الموظف', cell: (m) => m.employee || '—' },
        { key: 'date', header: 'التاريخ', sortable: true, value: (m) => m.date, cell: (m) => (
            <span dir="ltr" className="text-[#6b7280]">{m.date}</span>
        ) },
    ];

    const filters: Filter<Movement>[] = [
        {
            label: 'كل أنواع الحركات',
            options: TYPES.map((x) => ({ label: x, value: x })),
            match: (m, v) => m.type === v,
        },
    ];

    return (
        <AdminLayout title="حركات المخزون">
            <PageHeader
                title="حركات المخزون"
                subtitle={t('سجل كامل لجميع عمليات الإضافة والخصم والمرتجعات والتلف')}
                breadcrumbs={[
                    { label: 'الرئيسية', href: route('admin.dashboard') },
                    { label: 'المخزون', href: route('admin.inventory.index') },
                    { label: 'حركات المخزون' },
                ]}
                actions={
                    <>
                        <Button variant="outline" asChild>
                            <SmartLink routeName="admin.inventory.index" href={route('admin.inventory.index')}>
                                <ArrowRight />
                                {t('رجوع للمخزون')}
                            </SmartLink>
                        </Button>
                        <Button onClick={() => setAdding(true)}>
                            <Plus />
                            {t('إضافة حركة')}
                        </Button>
                    </>
                }
            />

            <SectionTabs tabs={INVENTORY_TABS} current="admin.inventory.movements" />

            <Card className="overflow-hidden">
                <DataTable
                    rows={movements}
                    columns={columns}
                    rowKey={(m) => m.id}
                    searchPlaceholder="ابحث باسم المنتج أو رمز SKU…"
                    searchable={(m) => `${m.product} ${m.sku} ${m.branch ?? ''} ${m.employee}`}
                    filters={filters}
                    empty="لا توجد حركات مخزون بعد"
                    pageSize={10}
                />
            </Card>

            <Dialog open={adding} onOpenChange={setAdding}>
                <DialogContent className="max-w-lg">
                    <DialogHeader>
                        <DialogTitle>{t('إضافة حركة مخزون')}</DialogTitle>
                    </DialogHeader>
                    <form onSubmit={submit} className="space-y-4 px-5 pb-5">
                        <Field label="الفرع" required error={form.errors.branch_id}>
                            <Select
                                value={form.data.branch_id}
                                onChange={(e) => form.setData('branch_id', e.target.value)}
                                options={branches.map((b) => ({ label: b.name, value: b.id }))}
                                placeholder="اختر الفرع…"
                                required
                            />
                        </Field>
                        <Field label="المنتج" required error={form.errors.product_id}>
                            <Select
                                value={form.data.product_id}
                                onChange={(e) => form.setData('product_id', e.target.value)}
                                options={products.map((p) => ({ label: p.name, value: p.id }))}
                                placeholder="اختر المنتج…"
                                required
                            />
                        </Field>
                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <Field label="نوع الحركة" required error={form.errors.type}>
                                <Select
                                    value={form.data.type}
                                    onChange={(e) => form.setData('type', e.target.value)}
                                    options={TYPES.map((x) => ({ label: x, value: x }))}
                                    placeholder="اختر النوع…"
                                    required
                                />
                            </Field>
                            <Field label="الكمية" required error={form.errors.quantity}>
                                <Input
                                    type="number"
                                    dir="ltr"
                                    value={form.data.quantity}
                                    onChange={(e) => form.setData('quantity', e.target.value)}
                                    placeholder="0"
                                    required
                                />
                            </Field>
                        </div>
                        <Field label="ملاحظة" error={form.errors.note}>
                            <Textarea
                                rows={2}
                                value={form.data.note}
                                onChange={(e) => form.setData('note', e.target.value)}
                                placeholder={t('سبب الحركة أو أي تفاصيل إضافية…')}
                            />
                        </Field>
                        <div className="flex justify-end gap-2 pt-1">
                            <Button type="button" variant="ghost" onClick={() => setAdding(false)}>
                                {t('إلغاء')}
                            </Button>
                            <Button type="submit" loading={form.processing}>
                                <Check />
                                {t('تسجيل الحركة')}
                            </Button>
                        </div>
                    </form>
                </DialogContent>
            </Dialog>
        </AdminLayout>
    );
}
