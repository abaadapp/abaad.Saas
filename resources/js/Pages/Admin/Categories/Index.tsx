import { usePage } from '@inertiajs/react';
import { motion } from 'framer-motion';
import { Plus } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import SmartLink from '@/Components/SmartLink';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { number } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import type { PageProps } from '@/types';
import type { Category } from '@/types/models';

export default function CategoriesIndex() {
    const { categories } = usePage<PageProps<{ categories: Category[] }>>().props;
    const t = useTranslate();

    return (
        <AdminLayout title="التصنيفات">
            <PageHeader
                title="التصنيفات"
                subtitle={`${number(categories.length)} تصنيف`}
                breadcrumbs={[
                    { label: 'الرئيسية', href: route('admin.dashboard') },
                    { label: 'التصنيفات' },
                ]}
                actions={
                    <Button asChild>
                        <SmartLink routeName={'admin.categories.create'} href={route('admin.categories.create')}>
                            <Plus />
                            {t('تصنيف جديد')}
                        </SmartLink>
                    </Button>
                }
            />

            {categories.length === 0 ? (
                <Card className="p-14 text-center text-sm text-[#9ca3af]">{t('لا توجد تصنيفات بعد')}</Card>
            ) : (
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                    {categories.map((category, i) => (
                        <motion.div
                            key={category.id}
                            initial={{ opacity: 0, y: 10 }}
                            animate={{ opacity: 1, y: 0 }}
                            transition={{ duration: 0.25, delay: Math.min(i * 0.04, 0.3) }}
                        >
                            <Card className="flex items-center gap-3 p-4">
                                <span
                                    className="flex size-11 shrink-0 items-center justify-center rounded-[12px] text-[18px]"
                                    style={{ backgroundColor: `${category.color}1a`, color: category.color }}
                                >
                                    {category.icon}
                                </span>
                                <span className="min-w-0">
                                    <span className="block truncate text-[14px] font-semibold text-[#111]">
                                        {category.name}
                                    </span>
                                    <span className="block text-[12px] text-[#6b7280]">
                                        {number(category.products)} منتج
                                    </span>
                                </span>
                            </Card>
                        </motion.div>
                    ))}
                </div>
            )}
        </AdminLayout>
    );
}
