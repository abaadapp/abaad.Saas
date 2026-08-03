import { usePage } from '@inertiajs/react';
import { Layers, Plus } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import SectionTabs, { PRODUCT_TABS } from '@/Components/SectionTabs';
import SmartLink from '@/Components/SmartLink';
import DataTable, { type Column } from '@/Components/DataTable';
import RowActions from '@/Components/RowActions';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { number } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import type { PageProps } from '@/types';
import type { Category } from '@/types/models';

export default function CategoriesIndex() {
    const { categories } = usePage<PageProps<{ categories: Category[] }>>().props;
    const t = useTranslate();

    const columns: Column<Category>[] = [
        {
            key: 'name',
            header: 'القسم',
            sortable: true,
            value: (c) => c.name,
            cell: (c) => (
                <div className="flex items-center gap-3">
                    {/* اللون سداسي دائمًا — Demo::categoryColor يوحّده قبل الإرسال،
                        و1a تجعله خلفية بشفافية 10٪ */}
                    <span
                        className="flex size-9 shrink-0 items-center justify-center rounded-[10px] text-[17px] leading-none"
                        style={{ backgroundColor: `${c.color}1a`, color: c.color }}
                    >
                        {c.icon}
                    </span>
                    <span className="font-medium text-[#111]">{c.name}</span>
                </div>
            ),
        },
        {
            key: 'products',
            header: 'المنتجات',
            align: 'end',
            sortable: true,
            value: (c) => c.products,
            cell: (c) => <span className="tabular-nums font-medium">{number(c.products)}</span>,
        },
        {
            key: 'actions',
            header: '',
            align: 'end',
            /**
             * الحذف يمرّ بالخادم لا بالواجهة: القسم المرتبط بمنتجات أو بأقسام
             * فرعية يُرفض هناك برسالة، فلا نكرّر الشرط هنا ونخاطر باختلافهما.
             */
            cell: (c) => (
                <RowActions
                    edit={{
                        routeName: 'admin.categories.edit',
                        href: route('admin.categories.edit', c.id),
                    }}
                    destroy={{
                        url: route('admin.categories.destroy', c.id),
                        message: `حذف القسم «${c.name}»؟`,
                    }}
                />
            ),
        },
    ];

    return (
        <AdminLayout title="الأقسام">
            <PageHeader
                title="الأقسام"
                subtitle={t('تنظيم منتجات متجرك في أقسام')}
                breadcrumbs={[
                    { label: 'الرئيسية', href: route('admin.dashboard') },
                    { label: 'المنتجات', href: route('admin.products.index') },
                    { label: 'الأقسام' },
                ]}
                actions={
                    <Button asChild>
                        <SmartLink
                            routeName={'admin.categories.create'}
                            href={route('admin.categories.create')}
                        >
                            <Plus />
                            {t('قسم جديد')}
                        </SmartLink>
                    </Button>
                }
            />

            <SectionTabs tabs={PRODUCT_TABS} current="admin.categories.index" />

            {categories.length === 0 ? (
                <Card className="px-5 py-16 text-center">
                    <Layers className="mx-auto size-8 text-[#d1d5db]" />
                    <p className="mt-3 font-medium text-[#111]">{t('لا توجد أقسام')}</p>
                    <p className="mt-1 text-[13px] text-[#9ca3af]">
                        {t('أضِف أول قسم لتنظيم منتجاتك.')}
                    </p>
                    <Button className="mt-5" asChild>
                        <SmartLink
                            routeName={'admin.categories.create'}
                            href={route('admin.categories.create')}
                        >
                            <Plus />
                            {t('قسم جديد')}
                        </SmartLink>
                    </Button>
                </Card>
            ) : (
                <Card className="overflow-hidden">
                    <DataTable
                        rows={categories}
                        columns={columns}
                        rowKey={(c) => c.id}
                        searchPlaceholder="ابحث باسم القسم…"
                        searchable={(c) => `${c.name} ${c.name_en ?? ''}`}
                        empty="لا توجد أقسام"
                        toolbar={
                            <span className="text-[12px] text-[#9ca3af]">
                                {number(categories.length)} {t('قسم')}
                            </span>
                        }
                    />
                </Card>
            )}
        </AdminLayout>
    );
}
