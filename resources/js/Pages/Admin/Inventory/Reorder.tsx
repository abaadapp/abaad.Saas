import { usePage } from '@inertiajs/react';
import { AlertTriangle, PackageCheck, Plus, ShoppingCart } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import SectionTabs, { INVENTORY_TABS } from '@/Components/SectionTabs';
import SmartLink from '@/Components/SmartLink';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/Components/ui/table';
import { number } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { PageProps } from '@/types';
import type { InventoryItem } from '@/types/models';

export default function InventoryReorder() {
    const { items } = usePage<PageProps<{ items: InventoryItem[] }>>().props;
    const t = useTranslate();

    return (
        <AdminLayout title="إعادة الطلب">
            <PageHeader
                title="إعادة الطلب"
                subtitle={t('الأصناف التي بلغت حد التنبيه أو نفدت — تحتاج إعادة تزويد')}
                breadcrumbs={[
                    { label: 'الرئيسية', href: route('admin.dashboard') },
                    { label: 'المخزون', href: route('admin.inventory.index') },
                    { label: 'إعادة الطلب' },
                ]}
                actions={
                    <Button asChild>
                        <SmartLink routeName="admin.purchases.create" href={route('admin.purchases.create')}>
                            <Plus />
                            {t('إنشاء أمر شراء')}
                        </SmartLink>
                    </Button>
                }
            />

            <SectionTabs tabs={INVENTORY_TABS} current="admin.inventory.reorder" />

            {items.length === 0 ? (
                <Card className="px-5 py-16 text-center">
                    <PackageCheck className="mx-auto size-8 text-[#d1d5db]" />
                    <p className="mt-3 font-medium text-[#111]">{t('لا توجد أصناف تحتاج إعادة طلب')}</p>
                    <p className="mt-1 text-[13px] text-[#9ca3af]">{t('كل الكميات فوق حد التنبيه. أحسنت!')}</p>
                </Card>
            ) : (
                <>
                    <Card className="mb-6 flex items-start gap-3 border-[#fde68a] bg-[#fffbeb]/60 p-4">
                        <AlertTriangle className="mt-0.5 size-5 shrink-0 text-[#d97706]" />
                        <div>
                            <p className="font-medium text-[#111]">{t('أصناف تحتاج إعادة طلب')}</p>
                            <p className="mt-0.5 text-[13px] text-[#4b4b4b]">
                                <span className="font-semibold">{number(items.length)}</span>{' '}
                                {t('صنفًا وصلت إلى حد التنبيه أو أقل. راجعها وأنشئ أمر شراء للمورّد.')}
                            </p>
                        </div>
                    </Card>

                    <Card className="overflow-hidden">
                        <Table>
                            <TableHeader>
                                <TableRow className="hover:bg-transparent">
                                    {['المنتج', 'SKU', 'الكمية الحالية', 'الحد الأدنى', 'النقص المقترح', 'حالة المخزون', 'إجراء'].map(
                                        (h) => (
                                            <TableHead key={h}>{t(h)}</TableHead>
                                        ),
                                    )}
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {items.map((item) => {
                                    // يعيد الكمية إلى ضعف الحد الأدنى، وبحدٍّ أدنى الحدُّ نفسه
                                    const suggested = Math.max(item.min * 2 - item.qty, item.min);

                                    return (
                                        <TableRow key={item.id}>
                                            <TableCell className="font-medium text-[#111]">{item.name}</TableCell>
                                            <TableCell className="font-mono text-[#6b7280]">{item.sku}</TableCell>
                                            <TableCell>
                                                <span
                                                    className={cn(
                                                        'font-semibold tabular-nums',
                                                        item.qty === 0 ? 'text-[#b91c1c]' : 'text-[#d97706]',
                                                    )}
                                                >
                                                    {number(item.qty)}
                                                </span>{' '}
                                                <span className="text-[11px] text-[#9ca3af]">{t('وحدة')}</span>
                                            </TableCell>
                                            <TableCell className="tabular-nums text-[#6b7280]">
                                                {number(item.min)}
                                            </TableCell>
                                            <TableCell className="font-semibold tabular-nums text-[#6d28d9]">
                                                +{number(suggested)}
                                            </TableCell>
                                            <TableCell>
                                                <Badge status={item.status}>{t(item.status)}</Badge>
                                            </TableCell>
                                            <TableCell>
                                                <Button variant="outline" size="sm" asChild>
                                                    <SmartLink
                                                        routeName="admin.purchases.create"
                                                        href={route('admin.purchases.create')}
                                                    >
                                                        <ShoppingCart />
                                                        {t('طلب')}
                                                    </SmartLink>
                                                </Button>
                                            </TableCell>
                                        </TableRow>
                                    );
                                })}
                            </TableBody>
                        </Table>
                    </Card>
                </>
            )}
        </AdminLayout>
    );
}
