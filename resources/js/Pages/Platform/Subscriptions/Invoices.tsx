import { router, usePage } from '@inertiajs/react';
import { CircleCheck, Download, Eye, RefreshCw } from 'lucide-react';
import PlatformLayout from '@/Layouts/PlatformLayout';
import PageHeader from '@/Components/PageHeader';
import ExportMenu from '@/Components/ExportMenu';
import SmartLink from '@/Components/SmartLink';
import StatCard, { type Stat } from '@/Components/StatCard';
import DataTable, { type Column, type Filter } from '@/Components/DataTable';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { money } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import type { Currency, PageProps } from '@/types';

interface Invoice {
    id: number;
    number: string;
    business: string;
    plan: string;
    amount: number;
    date: string;
    status: string;
}

interface Props {
    stats: Stat[];
    invoices: Invoice[];
    currency: Currency;
}

export default function Invoices() {
    const { stats, invoices, currency } = usePage<PageProps<Props>>().props;
    const t = useTranslate();

    const columns: Column<Invoice>[] = [
        {
            key: 'number',
            header: 'رقم الفاتورة',
            cell: (i) => <span className="font-mono font-medium text-[#111]">{i.number}</span>,
            value: (i) => i.number,
        },
        { key: 'business', header: 'الشركة', value: (i) => i.business },
        { key: 'plan', header: 'الباقة', cell: (i) => <Badge variant="primary">{t(i.plan)}</Badge>, value: (i) => i.plan },
        {
            key: 'amount',
            header: 'المبلغ',
            align: 'end',
            sortable: true,
            cell: (i) => <span className="font-semibold tabular-nums">{money(i.amount, currency)}</span>,
            value: (i) => i.amount,
        },
        {
            key: 'date',
            header: 'التاريخ',
            sortable: true,
            cell: (i) => <span dir="ltr" className="text-[#6b7280]">{i.date}</span>,
            value: (i) => i.date,
        },
        { key: 'status', header: 'الحالة', cell: (i) => <Badge status={i.status} />, value: (i) => i.status },
        {
            key: 'actions',
            header: 'إجراءات',
            align: 'end',
            cell: (i) => (
                <span className="flex items-center justify-end gap-1">
                    {/* روابط تنزيل حقيقية لا روابط Inertia: الاستجابة ملف لا صفحة */}
                    <Button variant="ghost" size="icon-sm" asChild aria-label={t('عرض')}>
                        <a href={route('super-admin.invoices.pdf', i.number)} target="_blank" rel="noreferrer">
                            <Eye />
                        </a>
                    </Button>
                    <Button variant="ghost" size="icon-sm" asChild aria-label={t('تحميل')}>
                        <a href={route('super-admin.invoices.pdf', i.number)} download>
                            <Download />
                        </a>
                    </Button>
                    {/* السداد يُسجَّل يدويًّا: التحصيل الإلكتروني يحتاج بوّابة دفع */}
                    {i.status !== 'مدفوعة' && (
                        <Button
                            variant="ghost"
                            size="icon-sm"
                            aria-label={t('تسجيل السداد')}
                            title={t('تسجيل السداد')}
                            onClick={() => router.post(route('super-admin.invoices.pay', i.id))}
                        >
                            <CircleCheck className="text-[#047857]" />
                        </Button>
                    )}
                </span>
            ),
        },
    ];

    const filters: Filter<Invoice>[] = [
        {
            label: 'كل الحالات',
            asTabs: true,
            options: [
                { label: 'مدفوعة', value: 'مدفوعة' },
                { label: 'غير مدفوعة', value: 'غير مدفوعة' },
            ],
            match: (i, v) => i.status === v,
        },
    ];

    return (
        <PlatformLayout title="الفواتير">
            <PageHeader
                title="الفواتير"
                subtitle={t('سجل فواتير الاشتراكات وحالة السداد لكل شركة')}
                actions={
                    <>
                        <Button variant="outline" asChild>
                            <SmartLink
                                routeName="super-admin.subscriptions.index"
                                href={route('super-admin.subscriptions.index')}
                            >
                                <RefreshCw />
                                {t('الاشتراكات')}
                            </SmartLink>
                        </Button>
                        <ExportMenu
                            xlsx={route('super-admin.invoices.xlsx')}
                            pdf={route('super-admin.invoices.exportPdf')}
                            csv={route('super-admin.export.invoices')}
                        />
                    </>
                }
            />

            <div className="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
                {stats.map((s, i) => (
                    <StatCard key={s.label} stat={s} index={i} />
                ))}
            </div>

            {/* الترقيم محسوب من الصفوف الفعلية — كان القالب يعلن 128 فاتورة ثابتة */}
            <DataTable
                rows={invoices}
                columns={columns}
                rowKey={(i) => i.number}
                filters={filters}
                searchPlaceholder="ابحث برقم الفاتورة أو اسم الشركة…"
                searchable={(i) => `${i.number} ${i.business}`}
                empty={t('لا توجد فواتير بعد')}
            />
        </PlatformLayout>
    );
}
