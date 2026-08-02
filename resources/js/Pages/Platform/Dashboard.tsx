import { usePage } from '@inertiajs/react';
import { Activity as ActivityIcon, Eye, Plus } from 'lucide-react';
import PlatformLayout from '@/Layouts/PlatformLayout';
import PageHeader from '@/Components/PageHeader';
import SmartLink from '@/Components/SmartLink';
import StatGrid from '@/Components/StatGrid';
import { type Stat } from '@/Components/StatCard';
import AreaChart from '@/Components/charts/AreaChart';
import BarChart from '@/Components/charts/BarChart';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
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
import { money } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import type { Currency, PageProps } from '@/types';

interface BusinessRow {
    id: number;
    name: string;
    type: string;
    owner: string;
    plan: string;
    status: string;
    registered: string;
}

interface SubscriptionRow {
    business: string;
    plan: string;
    end: string;
    amount: number;
    status: string;
}

interface ActivityRow {
    text: string;
    time: string;
    icon: string;
    color: string;
}

interface Props {
    stats: Stat[];
    revenueSeries: { labels: string[]; data: number[] };
    growthSeries: { labels: string[]; data: number[] };
    latestBusinesses: BusinessRow[];
    activities: ActivityRow[];
    expiringSubscriptions: SubscriptionRow[];
    currency: Currency;
}

const TONE: Record<string, string> = {
    primary: 'bg-[#f5f3ff] text-[#6d28d9]',
    secondary: 'bg-[#fdf2f8] text-[#be185d]',
    success: 'bg-[#ecfdf5] text-[#047857]',
    warning: 'bg-[#fffbeb] text-[#d97706]',
    danger: 'bg-[#fef2f2] text-[#b91c1c]',
    info: 'bg-[#eff6ff] text-[#2563eb]',
    gray: 'bg-[#f2f2f0] text-[#6b7280]',
};

export default function PlatformDashboard() {
    // العملة تأتي كخاصية صفحة لا من context: مدير المنصة بلا business_id
    // فالسياق المشترك يصله null — انظر PageController::page
    const { stats, revenueSeries, growthSeries, latestBusinesses, activities, expiringSubscriptions, currency } =
        usePage<PageProps<Props>>().props;
    const t = useTranslate();
    const { stats: liveStats, updatedAt } = useLiveStats(route('super-admin.dashboard.stats'), stats);

    return (
        <PlatformLayout title="لوحة التحكم">
            <PageHeader
                title="لوحة التحكم"
                subtitle={t('نظرة عامة على أداء منصة أبعاد')}
                actions={
                    <Button asChild>
                        <SmartLink
                            routeName="super-admin.businesses.create"
                            href={route('super-admin.businesses.create')}
                        >
                            <Plus />
                            {t('إضافة شركة جديدة')}
                        </SmartLink>
                    </Button>
                }
            />

            <StatGrid stats={liveStats} storageKey="platform" />
            <p className="mb-6 mt-2 min-h-[18px] text-[12px] text-[#9ca3af]">
                {updatedAt && (
                    <>
                        {t('آخر تحديث')}: <span dir="ltr">{updatedAt}</span>
                    </>
                )}
            </p>

            <div className="mb-6 grid grid-cols-1 gap-4 lg:grid-cols-2">
                <Card className="p-5">
                    <div className="mb-4 flex items-center justify-between">
                        <h3 className="font-bold text-[#111]">{t('الإيرادات الشهرية')}</h3>
                        <span className="text-[12px] text-[#9ca3af]">{t('آخر 6 أشهر')}</span>
                    </div>
                    <AreaChart
                        labels={revenueSeries.labels}
                        data={revenueSeries.data}
                        format={(v) => money(v, currency)}
                    />
                </Card>

                <Card className="p-5">
                    <div className="mb-4 flex items-center justify-between">
                        <h3 className="font-bold text-[#111]">{t('نمو الشركات')}</h3>
                        <span className="text-[12px] text-[#9ca3af]">{t('شركات جديدة شهريًا')}</span>
                    </div>
                    <BarChart labels={growthSeries.labels} series={growthSeries.data} />
                </Card>
            </div>

            <div className="mb-6 grid grid-cols-1 gap-6 xl:grid-cols-3">
                <div className="xl:col-span-2">
                    <div className="mb-3 flex items-center justify-between">
                        <h3 className="font-bold text-[#111]">{t('آخر الشركات المسجلة')}</h3>
                        <SmartLink
                            routeName="super-admin.businesses.index"
                            href={route('super-admin.businesses.index')}
                            className="text-sm font-medium text-[#111] hover:underline"
                        >
                            {t('عرض الكل')}
                        </SmartLink>
                    </div>
                    <Card className="overflow-hidden">
                        <Table>
                            <TableHeader>
                                <TableRow className="hover:bg-transparent">
                                    {['الشركة', 'النوع', 'المالك', 'الباقة', 'الحالة', 'تاريخ التسجيل'].map((h) => (
                                        <TableHead key={h}>{t(h)}</TableHead>
                                    ))}
                                    <TableHead />
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {latestBusinesses.length === 0 ? (
                                    <TableEmpty colSpan={7}>{t('لا توجد شركات بعد')}</TableEmpty>
                                ) : (
                                    latestBusinesses.map((b) => (
                                        <TableRow key={b.id}>
                                            <TableCell className="font-medium text-[#111]">{b.name}</TableCell>
                                            <TableCell className="text-[#4b4b4b]">{t(b.type)}</TableCell>
                                            <TableCell className="text-[#4b4b4b]">{b.owner || '—'}</TableCell>
                                            <TableCell className="text-[#4b4b4b]">{t(b.plan)}</TableCell>
                                            <TableCell>
                                                <Badge>{t(b.status)}</Badge>
                                            </TableCell>
                                            <TableCell dir="ltr" className="text-[#6b7280]">
                                                {b.registered}
                                            </TableCell>
                                            <TableCell>
                                                <Button variant="ghost" size="sm" asChild>
                                                    <SmartLink
                                                        routeName="super-admin.businesses.show"
                                                        href={route('super-admin.businesses.show', b.id)}
                                                    >
                                                        <Eye />
                                                        {t('عرض')}
                                                    </SmartLink>
                                                </Button>
                                            </TableCell>
                                        </TableRow>
                                    ))
                                )}
                            </TableBody>
                        </Table>
                    </Card>
                </div>

                <div>
                    <h3 className="mb-3 font-bold text-[#111]">{t('أحدث الأنشطة')}</h3>
                    <Card className="p-5">
                        {activities.length === 0 ? (
                            <p className="py-8 text-center text-sm text-[#9ca3af]">{t('لا يوجد نشاط بعد')}</p>
                        ) : (
                            <ul className="space-y-4">
                                {activities.map((a, i) => (
                                    <li key={i} className="flex items-start gap-3">
                                        <span
                                            className={`flex size-9 shrink-0 items-center justify-center rounded-[12px] ${TONE[a.color] ?? TONE.primary}`}
                                        >
                                            <ActivityIcon className="size-4" />
                                        </span>
                                        <div className="min-w-0">
                                            <p className="text-sm leading-snug text-[#4b4b4b]">{a.text}</p>
                                            <p className="mt-0.5 text-[12px] text-[#9ca3af]">{a.time}</p>
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Card>
                </div>
            </div>

            <div>
                <div className="mb-3 flex items-center justify-between">
                    <h3 className="font-bold text-[#111]">{t('اشتراكات ستنتهي قريبًا')}</h3>
                    <SmartLink
                        routeName="super-admin.subscriptions.index"
                        href={route('super-admin.subscriptions.index')}
                        className="text-sm font-medium text-[#111] hover:underline"
                    >
                        {t('إدارة الاشتراكات')}
                    </SmartLink>
                </div>
                <Card className="overflow-hidden">
                    <Table>
                        <TableHeader>
                            <TableRow className="hover:bg-transparent">
                                {['الشركة', 'الباقة', 'تاريخ الانتهاء', 'المبلغ', 'الحالة'].map((h) => (
                                    <TableHead key={h}>{t(h)}</TableHead>
                                ))}
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {expiringSubscriptions.length === 0 ? (
                                <TableEmpty colSpan={5}>{t('لا توجد اشتراكات بعد')}</TableEmpty>
                            ) : (
                                expiringSubscriptions.map((s, i) => (
                                    <TableRow key={i}>
                                        <TableCell className="font-medium text-[#111]">{s.business}</TableCell>
                                        <TableCell className="text-[#4b4b4b]">{t(s.plan)}</TableCell>
                                        <TableCell dir="ltr" className="text-[#6b7280]">
                                            {s.end}
                                        </TableCell>
                                        <TableCell className="font-medium tabular-nums text-[#111]">
                                            {money(s.amount, currency)}
                                        </TableCell>
                                        <TableCell>
                                            <Badge>{t(s.status)}</Badge>
                                        </TableCell>
                                    </TableRow>
                                ))
                            )}
                        </TableBody>
                    </Table>
                </Card>
            </div>
        </PlatformLayout>
    );
}
