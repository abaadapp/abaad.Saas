import { router, usePage } from '@inertiajs/react';
import { Package, Receipt, Undo2 } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import SettingsNav from './partials/SettingsNav';
import DataTable, { type Column } from '@/Components/DataTable';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { useTranslate } from '@/lib/i18n';
import { money } from '@/lib/format';
import type { PageProps } from '@/types';

interface TrashedProduct {
    id: number;
    name: string;
    sku: string | null;
    price: number;
    quantity: number;
    deletedAt: string | null;
}

interface TrashedExpense {
    id: number;
    reference: string | null;
    title: string | null;
    amount: number;
    spentAt: string | null;
    deletedAt: string | null;
}

interface Props {
    products: TrashedProduct[];
    expenses: TrashedExpense[];
    windowDays: number;
}

/**
 * المحذوفات.
 *
 * كان حذف المنتج والمصروف محوًا نهائيًّا: ضغطةٌ خاطئة تُذهب التكلفة والباركود
 * والمرفق، والسجلّ يقيّد ما جرى ولا يردّه. هذه الشاشة هي الزرّ المقابل.
 */
export default function Trash() {
    const { products, expenses, windowDays, context } = usePage<PageProps<Props>>().props;
    const t = useTranslate();
    const m = (v: number) => money(v, context!.currency);

    /*
     * لكل نوعٍ مسارُه: الاستعادة تقع تحت صلاحية القسم الذي حُذف منه، لا تحت
     * «الإعدادات» — انظر TrashController::restore.
     */
    const restore = (type: 'product' | 'expense', id: number) =>
        router.post(route(`admin.${type}s.restore`, id), {}, { preserveScroll: true });

    const productColumns: Column<TrashedProduct>[] = [
        {
            key: 'name',
            header: 'المنتج',
            sortable: true,
            value: (p) => p.name,
            cell: (p) => (
                <div className="flex items-center gap-3">
                    <span className="flex size-9 shrink-0 items-center justify-center rounded-[10px] bg-[#f2f2f0] text-[#4b4b4b]">
                        <Package className="size-4" />
                    </span>
                    <span>
                        <span className="block font-medium text-[#111]">{p.name}</span>
                        {p.sku && <span className="block text-[12px] text-[#9ca3af]" dir="ltr">{p.sku}</span>}
                    </span>
                </div>
            ),
        },
        { key: 'price', header: 'السعر', align: 'end', cell: (p) => m(p.price) },
        { key: 'quantity', header: 'الكمية', align: 'center', cell: (p) => p.quantity },
        { key: 'deletedAt', header: 'تاريخ الحذف', cell: (p) => p.deletedAt ?? '—' },
        {
            key: 'actions',
            header: '',
            align: 'end',
            cell: (p) => (
                <Button variant="outline" size="sm" onClick={() => restore('product', p.id)}>
                    <Undo2 className="size-4" />
                    {t('استعادة')}
                </Button>
            ),
        },
    ];

    const expenseColumns: Column<TrashedExpense>[] = [
        {
            key: 'reference',
            header: 'المصروف',
            sortable: true,
            value: (e) => e.reference ?? '',
            cell: (e) => (
                <div className="flex items-center gap-3">
                    <span className="flex size-9 shrink-0 items-center justify-center rounded-[10px] bg-[#f2f2f0] text-[#4b4b4b]">
                        <Receipt className="size-4" />
                    </span>
                    <span>
                        <span className="block font-medium text-[#111]">{e.reference ?? '—'}</span>
                        {e.title && <span className="block text-[12px] text-[#9ca3af]">{e.title}</span>}
                    </span>
                </div>
            ),
        },
        { key: 'amount', header: 'المبلغ', align: 'end', cell: (e) => m(e.amount) },
        { key: 'spentAt', header: 'تاريخ الصرف', cell: (e) => e.spentAt ?? '—' },
        { key: 'deletedAt', header: 'تاريخ الحذف', cell: (e) => e.deletedAt ?? '—' },
        {
            key: 'actions',
            header: '',
            align: 'end',
            cell: (e) => (
                <Button variant="outline" size="sm" onClick={() => restore('expense', e.id)}>
                    <Undo2 className="size-4" />
                    {t('استعادة')}
                </Button>
            ),
        },
    ];

    return (
        <AdminLayout title="المحذوفات">
            <PageHeader
                title="المحذوفات"
                subtitle={t('ما حُذف خلال آخر :days يومًا — يُستعاد بضغطة', { days: windowDays })}
                breadcrumbs={[
                    { label: 'الرئيسية', href: route('admin.dashboard') },
                    { label: 'المحذوفات' },
                ]}
            />

            <div className="grid grid-cols-1 gap-6 lg:grid-cols-[232px_1fr]">
                <SettingsNav current="trash" />

                <div className="min-w-0 space-y-6">
                    <div>
                        <h2 className="mb-3 text-[15px] font-bold text-[#111]">{t('المنتجات')}</h2>
                        <Card className="p-0">
                            <DataTable
                                columns={productColumns}
                                rows={products}
                                rowKey={(p) => p.id}
                                empty={t('لا منتجات محذوفة.')}
                            />
                        </Card>
                    </div>

                    <div>
                        <h2 className="mb-3 text-[15px] font-bold text-[#111]">{t('المصروفات')}</h2>
                        <Card className="p-0">
                            <DataTable
                                columns={expenseColumns}
                                rows={expenses}
                                rowKey={(e) => e.id}
                                empty={t('لا مصروفات محذوفة.')}
                            />
                        </Card>
                    </div>

                </div>
            </div>
        </AdminLayout>
    );
}
