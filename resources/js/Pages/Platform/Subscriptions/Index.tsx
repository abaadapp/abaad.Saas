import { usePage } from '@inertiajs/react';
import { FileText, Layers, Plus } from 'lucide-react';
import PlatformLayout from '@/Layouts/PlatformLayout';
import PageHeader from '@/Components/PageHeader';
import SmartLink from '@/Components/SmartLink';
import StatCard, { type Stat } from '@/Components/StatCard';
import DataTable, { type Column, type Filter } from '@/Components/DataTable';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { money } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import type { Currency, PageProps } from '@/types';

interface Subscription {
    business: string;
    plan: string;
    start: string;
    end: string;
    amount: number;
    payment: string;
    status: string;
}

interface Props {
    stats: Stat[];
    subscriptions: Subscription[];
    planNames: string[];
    currency: Currency;
}

export default function SubscriptionsIndex() {
    const { stats, subscriptions, planNames, currency } = usePage<PageProps<Props>>().props;
    const t = useTranslate();

    const columns: Column<Subscription>[] = [
        { key: 'business', header: 'الشركة', cell: (s) => <span className="font-medium text-[#111]">{s.business}</span>, value: (s) => s.business },
        { key: 'plan', header: 'الباقة', cell: (s) => <Badge variant="primary">{t(s.plan)}</Badge>, value: (s) => s.plan },
        { key: 'start', header: 'تاريخ البداية', cell: (s) => <span dir="ltr">{s.start}</span>, value: (s) => s.start },
        { key: 'end', header: 'تاريخ الانتهاء', cell: (s) => <span dir="ltr">{s.end}</span>, value: (s) => s.end, sortable: true },
        {
            key: 'amount',
            header: 'المبلغ',
            align: 'end',
            sortable: true,
            cell: (s) => <span className="font-semibold tabular-nums">{money(s.amount, currency)}</span>,
            value: (s) => s.amount,
        },
        { key: 'payment', header: 'حالة الدفع', cell: (s) => <Badge status={s.payment} />, value: (s) => s.payment },
        { key: 'status', header: 'حالة الاشتراك', cell: (s) => <Badge status={s.status} />, value: (s) => s.status },
    ];

    const filters: Filter<Subscription>[] = [
        {
            label: 'كل الباقات',
            options: planNames.map((p) => ({ label: p, value: p })),
            match: (s, v) => s.plan === v,
        },
        {
            label: 'كل حالات الدفع',
            options: [
                { label: 'مدفوع', value: 'مدفوع' },
                { label: 'غير مدفوع', value: 'غير مدفوع' },
            ],
            match: (s, v) => s.payment === v,
        },
        {
            label: 'كل الحالات',
            options: [
                { label: 'نشط', value: 'نشط' },
                { label: 'منتهي', value: 'منتهي' },
                { label: 'معطل', value: 'معطل' },
            ],
            match: (s, v) => s.status === v,
        },
    ];

    return (
        <PlatformLayout title="الاشتراكات">
            <PageHeader
                title="الاشتراكات"
                subtitle={t('إدارة اشتراكات الشركات في المنصة ومتابعة حالة الدفع والتجديد')}
                breadcrumbs={[
                    { label: 'الرئيسية', href: route('super-admin.dashboard') },
                    { label: 'الاشتراكات' },
                ]}
                actions={
                    <>
                        <Button variant="outline" asChild>
                            <SmartLink
                                routeName="super-admin.subscriptions.plans"
                                href={route('super-admin.subscriptions.plans')}
                            >
                                <Layers />
                                {t('الباقات')}
                            </SmartLink>
                        </Button>
                        <Button variant="outline" asChild>
                            <SmartLink
                                routeName="super-admin.subscriptions.invoices"
                                href={route('super-admin.subscriptions.invoices')}
                            >
                                <FileText />
                                {t('الفواتير')}
                            </SmartLink>
                        </Button>
                        <Button asChild>
                            <SmartLink
                                routeName="super-admin.businesses.create"
                                href={route('super-admin.businesses.create')}
                            >
                                <Plus />
                                {t('اشتراك جديد')}
                            </SmartLink>
                        </Button>
                    </>
                }
            />

            <div className="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                {stats.map((s, i) => (
                    <StatCard key={s.label} stat={s} index={i} />
                ))}
            </div>

            <DataTable
                rows={subscriptions}
                columns={columns}
                rowKey={(s) => `${s.business}-${s.start}-${s.end}`}
                filters={filters}
                searchPlaceholder="ابحث باسم الشركة…"
                searchable={(s) => s.business}
                empty={t('لا توجد اشتراكات بعد')}
            />
        </PlatformLayout>
    );
}
