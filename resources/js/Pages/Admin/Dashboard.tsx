import { usePage } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import SmartLink from '@/Components/SmartLink';
import StatGrid, { type CatalogStat } from '@/Components/StatGrid';
import { type Stat } from '@/Components/StatCard';
import AreaChart from '@/Components/charts/AreaChart';
import BarChart from '@/Components/charts/BarChart';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import {
    Table,
    TableBody,
    TableCell,
    TableEmpty,
    TableHead,
    TableHeader,
    TableRow,
} from '@/Components/ui/table';
import useLiveStats from '@/hooks/useLiveStats';
import { money, number } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import type { PageProps } from '@/types';

interface Order {
    id: string;
    customer: string;
    total: number;
    status: string;
    date?: string;
}

interface Product {
    id: number;
    name: string;
    price: number;
    qty: number;
    image?: string;
}

interface Employee {
    // الرقم يصل من Demo::employees لكن الواجهة لم تكن تعلنه، فاضطرّ العرض
    // إلى التمييز بالاسم — وموظفان باسم واحد أمر عادي في متجر حقيقي،
    // فتتصادم مفاتيح React وتُحذف صفوف أو تتكرّر.
    id: number;
    name: string;
    role: string;
    sales: number;
    avatar?: string;
}

/**
 * لا تنبيهات في اللوحة بطلب المالك: كانت تتصدّر الصفحة بما لا يحتاج تدخّلًا
 * فورًا («عميل متعثّر» مثلًا)، فتزاحم الأرقام التي جاء التاجر ليقرأها.
 * المصدر Demo::smartAlertsFor باقٍ لأمر البريد المجدول abaad:smart-alerts.
 */
interface DashboardProps {
    stats: Stat[];
    statCatalog: CatalogStat[];
    salesSeries: { labels: string[]; full: string[]; data: number[]; counts: number[] };
    paymentDistribution: { labels: string[]; series: number[] };
    recentOrders: Order[];
    topProducts: Product[];
    topEmployees: Employee[];
}

export default function Dashboard() {
    const { stats, statCatalog, salesSeries, paymentDistribution, recentOrders, topProducts, topEmployees, context } =
        usePage<PageProps<DashboardProps>>().props;

    const t = useTranslate();
    const currency = context!.currency;
    const fmt = (value: number) => money(value, currency);
    const { stats: liveStats, updatedAt } = useLiveStats(route('admin.dashboard.stats'), stats);

    return (
        <AdminLayout title="لوحة التحكم">
            {/* زرّا «نقطة البيع» و«الموقع الإلكتروني» انتقلا إلى الهيدر ثابتين
                على كل الصفحات، فرُفعا من ترويسة اللوحة كي لا يتكرّرا. */}
            <PageHeader
                title="لوحة التحكم"
                subtitle={t('نظرة عامة على أداء :name', { name: context?.businessName ?? t('متجرك') })}
            />

            <StatGrid stats={liveStats} storageKey="admin" catalog={statCatalog} />
            {updatedAt && (
                <p className="mt-2 text-[12px] text-[#9ca3af]">
                    {t('آخر تحديث')}: <span dir="ltr">{updatedAt}</span>
                </p>
            )}

            <div className="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-3">
                <Card className="lg:col-span-2">
                    <CardHeader>
                        <CardTitle>{t('مبيعات هذه السنة حسب الشهر')}</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <AreaChart
                            labels={salesSeries.labels}
                            fullLabels={salesSeries.full}
                            counts={salesSeries.counts}
                            data={salesSeries.data}
                            format={(v) => number(v, 0)}
                        />
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>{t('طرق الدفع')}</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <BarChart
                            labels={paymentDistribution.labels}
                            series={paymentDistribution.series}
                            format={fmt}
                        />
                    </CardContent>
                </Card>
            </div>

            <div className="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-3">
                <Card className="lg:col-span-2">
                    <CardHeader className="flex-row items-center justify-between">
                        <CardTitle>{t('أحدث الطلبات')}</CardTitle>
                        <Button variant="ghost" size="sm" asChild>
                            <SmartLink routeName={'admin.orders.index'} href={route('admin.orders.index')}>
                                {t('الكل')}
                                {/* يقلب في LTR ليشير للأمام في الاتجاهين */}
                                <ArrowLeft className="size-4 ltr:rotate-180" />
                            </SmartLink>
                        </Button>
                    </CardHeader>
                    <CardContent className="px-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>{t('الطلب')}</TableHead>
                                    <TableHead>{t('العميل')}</TableHead>
                                    <TableHead>{t('الحالة')}</TableHead>
                                    <TableHead className="text-end">{t('الإجمالي')}</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {recentOrders.length === 0 ? (
                                    <TableEmpty colSpan={4}>{t('لا توجد طلبات بعد')}</TableEmpty>
                                ) : (
                                    recentOrders.map((order) => (
                                        <TableRow key={order.id}>
                                            <TableCell className="font-medium">
                                                <SmartLink routeName={'admin.orders.show'} href={route('admin.orders.show', order.id)}
                                                    className="hover:underline"
                                                >
                                                    {order.id}
                                                </SmartLink>
                                            </TableCell>
                                            <TableCell className="text-[#6b7280]">{order.customer}</TableCell>
                                            <TableCell>
                                                <Badge status={order.status} />
                                            </TableCell>
                                            <TableCell className="text-end tabular-nums">
                                                {fmt(order.total)}
                                            </TableCell>
                                        </TableRow>
                                    ))
                                )}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>

                <div className="flex flex-col gap-4">
                    <Card>
                        <CardHeader>
                            <CardTitle>{t('أفضل المنتجات')}</CardTitle>
                        </CardHeader>
                        <CardContent className="flex flex-col gap-3">
                            {topProducts.length === 0 ? (
                                <p className="py-6 text-center text-[13px] text-[#9ca3af]">
                                    {t('لا توجد منتجات بعد')}
                                </p>
                            ) : (
                                topProducts.map((product) => (
                                    <div key={product.id} className="flex items-center justify-between gap-3">
                                        <span className="truncate text-[13px] text-[#111]">{product.name}</span>
                                        <span className="shrink-0 text-[12px] tabular-nums text-[#6b7280]">
                                            {fmt(product.price)}
                                        </span>
                                    </div>
                                ))
                            )}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>{t('أداء الموظفين')}</CardTitle>
                        </CardHeader>
                        <CardContent className="flex flex-col gap-3">
                            {topEmployees.length === 0 ? (
                                <p className="py-6 text-center text-[13px] text-[#9ca3af]">
                                    {t('لا يوجد موظفون بعد')}
                                </p>
                            ) : (
                                topEmployees.map((employee) => (
                                    <div key={employee.id} className="flex items-center justify-between gap-3">
                                        <span className="min-w-0">
                                            <span className="block truncate text-[13px] text-[#111]">
                                                {employee.name}
                                            </span>
                                            <span className="block text-[11px] text-[#9ca3af]">
                                                {t(employee.role)}
                                            </span>
                                        </span>
                                        <span className="shrink-0 text-[12px] tabular-nums text-[#6b7280]">
                                            {fmt(employee.sales)}
                                        </span>
                                    </div>
                                ))
                            )}
                        </CardContent>
                    </Card>
                </div>
            </div>
        </AdminLayout>
    );
}
