import { usePage } from '@inertiajs/react';
import { ArrowLeft, ChevronLeft, Plus } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import SectionTabs, { INVENTORY_TABS } from '@/Components/SectionTabs';
import SmartLink from '@/Components/SmartLink';
import StatGrid from '@/Components/StatGrid';
import { type Stat } from '@/Components/StatCard';
import { Button } from '@/Components/ui/button';
import { Card, CardHeader, CardTitle, CardContent } from '@/Components/ui/card';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/Components/ui/dropdown-menu';
import { money, number } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import type { PageProps } from '@/types';

interface Alert {
    key: string;
    label: string;
    count: number;
    tone: 'danger' | 'warning' | 'info';
    href: string;
}

interface Movement {
    id: number;
    product: string;
    type: string;
    qty: string;
    branch: string;
    employee: string;
    date: string;
}

interface Props {
    stats: {
        value: number;
        inStock: number;
        low: number;
        out: number;
        openOrders: number;
    };
    alerts: Alert[];
    recent: Movement[];
}

/* نبرة التنبيه لونًا ونصًا معًا — اللون وحده لا يُقرأ لمن لا يميّزه */
const TONE: Record<Alert['tone'], string> = {
    danger: 'border-[#fecaca] bg-[#fef2f2] text-[#b91c1c]',
    warning: 'border-[#fed7aa] bg-[#fff7ed] text-[#c2410c]',
    info: 'border-[#bfdbfe] bg-[#eff6ff] text-[#1d4ed8]',
};

export default function InventoryOverview() {
    const { stats, alerts, recent, context } = usePage<PageProps<Props>>().props;
    const t = useTranslate();
    const currency = context!.currency;

    const cards: Stat[] = [
        { label: 'قيمة المخزون', value: money(stats.value, currency), icon: 'wallet', color: 'primary' },
        { label: 'منتجات متوفرة', value: number(stats.inStock), icon: 'package', color: 'success' },
        { label: 'تحت الحد الأدنى', value: number(stats.low), icon: 'alert-triangle', color: 'warning' },
        { label: 'نفد مخزونها', value: number(stats.out), icon: 'badge-x', color: 'danger' },
        { label: 'أوامر شراء مفتوحة', value: number(stats.openOrders), icon: 'truck', color: 'secondary' },
    ];

    return (
        <AdminLayout title="المخزون">
            <PageHeader
                title="المخزون"
                subtitle={t('نظرة عامة على الكميات والتنبيهات وآخر الحركات')}
                breadcrumbs={[
                    { label: 'الرئيسية', href: route('admin.dashboard') },
                    { label: 'المخزون' },
                ]}
                actions={
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <Button>
                                <Plus />
                                {t('عملية مخزون')}
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" className="w-52">
                            <DropdownMenuItem asChild>
                                <SmartLink
                                    routeName="admin.purchases.create"
                                    href={route('admin.purchases.create')}
                                >
                                    {t('إنشاء أمر شراء')}
                                </SmartLink>
                            </DropdownMenuItem>
                            <DropdownMenuItem asChild>
                                <SmartLink
                                    routeName="admin.inventory.stocktake"
                                    href={route('admin.inventory.stocktake')}
                                >
                                    {t('بدء جرد')}
                                </SmartLink>
                            </DropdownMenuItem>
                            <DropdownMenuItem asChild>
                                <SmartLink
                                    routeName="admin.inventory.movements"
                                    href={route('admin.inventory.movements')}
                                >
                                    {t('تسجيل حركة مخزون')}
                                </SmartLink>
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                }
            />

            <SectionTabs tabs={INVENTORY_TABS} current="admin.inventory.overview" />

            <StatGrid stats={cards} storageKey="inventory" />

            <Card className="mt-4">
                <CardHeader>
                    <CardTitle>{t('يحتاج إلى انتباهك')}</CardTitle>
                </CardHeader>
                <CardContent>
                    {alerts.length === 0 ? (
                        <p className="py-6 text-center text-[13px] text-[#9ca3af]">
                            {t('لا شيء يحتاج انتباهك — المخزون في وضع سليم.')}
                        </p>
                    ) : (
                        <div className="flex flex-col gap-2">
                            {/* التنبيه رابط يحمل الفلتر معه، فيصل الضغط إلى الصفوف المقصودة لا إلى القائمة كاملة */}
                            {alerts.map((a) => (
                                <a
                                    key={a.key}
                                    href={a.href}
                                    className={`flex items-center gap-3 rounded-[12px] border px-4 py-3 text-sm transition-colors hover:brightness-[0.97] ${TONE[a.tone]}`}
                                >
                                    <span className="flex h-6 min-w-6 items-center justify-center rounded-full bg-white/70 px-1.5 text-[12px] font-bold tabular-nums">
                                        {number(a.count)}
                                    </span>
                                    <span className="min-w-0 flex-1 font-medium">{t(a.label)}</span>
                                    <ChevronLeft className="size-4 shrink-0 rtl:rotate-0" />
                                </a>
                            ))}
                        </div>
                    )}
                </CardContent>
            </Card>

            <Card className="mt-4">
                <CardHeader className="flex-row items-center justify-between">
                    <CardTitle>{t('آخر حركات المخزون')}</CardTitle>
                    <Button variant="ghost" size="sm" asChild>
                        <SmartLink
                            routeName="admin.inventory.movements"
                            href={route('admin.inventory.movements')}
                        >
                            {t('عرض الكل')}
                            <ArrowLeft />
                        </SmartLink>
                    </Button>
                </CardHeader>
                <CardContent>
                    {recent.length === 0 ? (
                        <p className="py-6 text-center text-[13px] text-[#9ca3af]">
                            {t('لا توجد حركات مخزون بعد.')}
                        </p>
                    ) : (
                        <ul className="flex flex-col divide-y divide-[var(--ui-border,#e8e8e8)]">
                            {recent.map((m) => (
                                <li key={m.id} className="flex items-center gap-3 py-2.5 text-sm">
                                    <span className="min-w-0 flex-1 truncate font-medium text-[#111]">
                                        {m.product}
                                    </span>
                                    <span className="shrink-0 text-[12px] text-[#6b7280]">{t(m.type)}</span>
                                    <span
                                        dir="ltr"
                                        className={`w-14 shrink-0 text-end tabular-nums font-semibold ${
                                            String(m.qty).startsWith('-') ? 'text-[#b91c1c]' : 'text-[#047857]'
                                        }`}
                                    >
                                        {m.qty}
                                    </span>
                                    <span className="hidden w-28 shrink-0 truncate text-[12px] text-[#9ca3af] sm:block">
                                        {m.branch}
                                    </span>
                                    <span dir="ltr" className="hidden w-24 shrink-0 text-end text-[12px] text-[#9ca3af] md:block">
                                        {m.date}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}
                </CardContent>
            </Card>
        </AdminLayout>
    );
}
