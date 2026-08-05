import { router, usePage } from '@inertiajs/react';
import { AlertTriangle } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import SectionTabs, { REPORTS_TABS } from '@/Components/SectionTabs';
import ExportMenu from '@/Components/ExportMenu';
import StatCard from '@/Components/StatCard';
import SmartLink from '@/Components/SmartLink';
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
import { money } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { PageProps } from '@/types';

interface VatReport {
    label: string;
    period: string;
    rate: number;
    taxable_sales: number;
    gross_sales: number;
    output_vat: number;
    input_base: number;
    input_vat: number;
    net_vat: number;
    months: { label: string; taxable: number; vat: number }[];
    from: string;
    to: string;
}

interface Props {
    report: VatReport;
    settings: { rate: number; number: string | null };
}

const PERIODS = [
    { key: 'month', label: 'هذا الشهر' },
    { key: 'quarter', label: 'هذا الربع' },
    { key: 'year', label: 'هذه السنة' },
];

export default function Vat() {
    const { report, settings, context } = usePage<PageProps<Props>>().props;
    const t = useTranslate();
    const currency = context!.currency;
    const m = (v: number) => money(v, currency);

    const stats = [
        { label: t('المبيعات الخاضعة للضريبة'), value: m(report.taxable_sales), icon: 'receipt', color: 'info' },
        { label: t('ضريبة المخرجات (مبيعات)'), value: m(report.output_vat), icon: 'trending-up', color: 'success' },
        { label: t('ضريبة المدخلات (مشتريات)'), value: m(report.input_vat), icon: 'arrow-down-circle', color: 'warning' },
        {
            label: t('صافي الضريبة المستحقّة'),
            value: m(report.net_vat),
            icon: 'landmark',
            color: report.net_vat >= 0 ? 'primary' : 'success',
        },
    ];

    const rows: { label: string; value: string; tone?: string; strong?: boolean }[] = [
        { label: 'نسبة الضريبة', value: `${report.rate}%`, strong: true },
        { label: 'إجمالي المبيعات (شامل الضريبة)', value: m(report.gross_sales), strong: true },
        { label: 'المبيعات الخاضعة', value: m(report.taxable_sales) },
        { label: 'ضريبة المخرجات', value: `+ ${m(report.output_vat)}`, tone: 'text-[#047857]' },
        { label: 'ضريبة المدخلات', value: `- ${m(report.input_vat)}`, tone: 'text-[#d97706]' },
    ];

    return (
        <AdminLayout title="ضريبة القيمة المضافة">
            <PageHeader
                title="ضريبة القيمة المضافة"
                subtitle={`${t('إقرار VAT')} — ${t(report.label)} (${report.from} — ${report.to})`}
                breadcrumbs={[
                    { label: 'الرئيسية', href: route('admin.dashboard') },
                    { label: 'ضريبة القيمة المضافة' },
                ]}
                actions={
                    <ExportMenu
                        xlsx={route('admin.vat.xlsx', { period: report.period })}
                        pdf={route('admin.vat.pdf', { period: report.period })}
                        csv={route('admin.vat.csv', { period: report.period })}
                    />
                }
            />

            <SectionTabs tabs={REPORTS_TABS} current="admin.vat.index" variant="segmented" />

            <div className="mb-6 flex flex-wrap items-center gap-2">
                {PERIODS.map((p) => (
                    <Button
                        key={p.key}
                        variant={report.period === p.key ? 'primary' : 'outline'}
                        onClick={() =>
                            router.get(route('admin.vat.index'), { period: p.key }, { preserveState: false })
                        }
                    >
                        {t(p.label)}
                    </Button>
                ))}

                {settings.number ? (
                    <span className="ms-auto text-sm text-[#9ca3af]">
                        {t('الرقم الضريبي:')}{' '}
                        <span className="font-mono text-[#4b4b4b]">{settings.number}</span>
                    </span>
                ) : (
                    <SmartLink
                        routeName="admin.settings.index"
                        // #taxes: الإعدادات تفتح على «بيانات النشاط» افتراضيًا،
                        // وكان الرابط يُنزل المستخدم هناك لا عند حقل الرقم الضريبي
                        href={`${route('admin.settings.index')}#taxes`}
                        className="ms-auto flex items-center gap-1 text-sm text-[#d97706] hover:underline"
                    >
                        <AlertTriangle className="size-4" />
                        {t('أضِف الرقم الضريبي (TRN) من الإعدادات')}
                    </SmartLink>
                )}
            </div>

            <div className="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                {stats.map((s, i) => (
                    <StatCard key={s.label} stat={s} index={i} />
                ))}
            </div>

            <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                <Card className="p-5">
                    <h3 className="mb-4 font-bold text-[#111]">{t('ملخّص الإقرار')}</h3>
                    <ul className="space-y-3 text-sm">
                        {rows.map((r, i) => (
                            <li
                                key={r.label}
                                className={cn(
                                    'flex items-center justify-between',
                                    i === 1 && 'border-t border-[#f5f5f4] pt-3',
                                    r.tone,
                                )}
                            >
                                <span className={r.tone ?? 'text-[#6b7280]'}>{t(r.label)}</span>
                                <span className={cn('tabular-nums', r.tone ?? (r.strong ? 'font-semibold text-[#111]' : 'text-[#4b4b4b]'))}>
                                    {r.value}
                                </span>
                            </li>
                        ))}
                        <li className="mt-1 flex items-center justify-between border-t-2 border-[#ede9fe] pt-3">
                            <span className="font-bold text-[#111]">{t('صافي المستحقّ للسداد')}</span>
                            <span className="text-[18px] font-bold tabular-nums text-[#6d28d9]">{m(report.net_vat)}</span>
                        </li>
                    </ul>
                    <p className="mt-4 text-[12px] text-[#9ca3af]">
                        {t('تُعامَل قيمة المشتريات المستلمة كأساس صافٍ لاحتساب ضريبة المدخلات. الأرقام للاسترشاد.')}
                    </p>
                </Card>

                <Card className="overflow-hidden lg:col-span-2">
                    <div className="border-b border-[var(--ui-border,#e8e8e8)] px-5 py-4">
                        <h3 className="font-bold text-[#111]">{t('التفصيل الشهري')}</h3>
                    </div>
                    <Table>
                        <TableHeader>
                            <TableRow className="hover:bg-transparent">
                                <TableHead>{t('الشهر')}</TableHead>
                                <TableHead className="text-end">{t('المبيعات الخاضعة')}</TableHead>
                                <TableHead className="text-end">{t('ضريبة المخرجات')}</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {report.months.length === 0 ? (
                                <TableEmpty colSpan={3}>{t('لا توجد بيانات لهذه الفترة')}</TableEmpty>
                            ) : (
                                report.months.map((mo) => (
                                    <TableRow key={mo.label}>
                                        <TableCell className="font-medium text-[#111]">{mo.label}</TableCell>
                                        <TableCell className="text-end tabular-nums text-[#4b4b4b]">
                                            {m(mo.taxable)}
                                        </TableCell>
                                        <TableCell className="text-end tabular-nums font-medium">{m(mo.vat)}</TableCell>
                                    </TableRow>
                                ))
                            )}
                        </TableBody>
                    </Table>
                </Card>
            </div>
        </AdminLayout>
    );
}
