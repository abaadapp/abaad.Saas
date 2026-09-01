import { usePage } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import PrintReport from '@/Components/PrintReport';
import BackToReports from '@/Components/BackToReports';
import RangeTabs, { type ReportRange } from '@/Components/RangeTabs';
import StatCard from '@/Components/StatCard';
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
import { money, number } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import type { PageProps } from '@/types';

interface Row {
    name: string;
    orders: number;
    total: number;
}

interface Props {
    rows: Row[];
    limit: number;
    summary: { total: number; customers: number; orders: number; average: number };
    range: ReportRange;
    rangeLabel: string;
}

/**
 * العملاء الأكثر إنفاقًا.
 *
 * كان جدولًا في نافذةٍ مشتركة، محسوبًا على الشهر الجاري وحده: و«أكثر
 * العملاء إنفاقًا» في شهرٍ غيرُ «أكثرهم إنفاقًا» منذ فُتح المحل — ولا شيء
 * على الشاشة كان يقول أيَّهما تقرأ.
 */
export default function ReportsCustomers() {
    const { rows, limit, summary, range, rangeLabel, context } = usePage<PageProps<Props>>().props;
    const t = useTranslate();
    const m = (v: number) => money(v, context!.currency);

    const stats = [
        { label: t('إجمالي الإنفاق'), value: m(summary.total), icon: 'wallet', color: 'primary' },
        { label: t('عملاء اشتروا'), value: number(summary.customers), icon: 'users', color: 'info' },
        { label: t('عدد الطلبات'), value: number(summary.orders), icon: 'shopping-cart', color: 'success' },
        { label: t('متوسّط قيمة الطلب'), value: m(summary.average), icon: 'calculator', color: 'warning' },
    ];

    return (
        <AdminLayout title="العملاء الأكثر إنفاقًا">
            {/* الكشف للطابعة: القاعدة العامة تُخفي الصفحة كلّها إلا الإيصال الحراري */}
            <div className="printable-report">
                <div className="no-print">
                    <BackToReports />
                </div>

                <PageHeader
                    title="العملاء الأكثر إنفاقًا"
                    subtitle={t('من يشتري أكثر، وكم طلبًا وكم أنفق')}
                    actions={<PrintReport />}
                />

                {/* الرجوع فوق العنوان: أوّلُ ما تقع عليه العين عند الخروج */}
                <div className="no-print">
                    <RangeTabs current={range} />
                </div>

                <div className="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    {stats.map((s, i) => (
                        <StatCard key={s.label} stat={s} index={i} />
                    ))}
                </div>

                <Card className="overflow-hidden">
                    <div className="border-b border-[var(--ui-border,#e8e8e8)] px-5 py-4">
                        <h3 className="font-bold text-[#111]">{t('التفصيل')}</h3>
                        <p className="mt-0.5 text-[13px] text-[#9ca3af]">{rangeLabel}</p>
                    </div>
                    <Table>
                        <TableHeader>
                            <TableRow className="hover:bg-transparent">
                                <TableHead className="w-14 text-center">#</TableHead>
                                <TableHead>{t('العميل')}</TableHead>
                                <TableHead className="text-end">{t('عدد الطلبات')}</TableHead>
                                <TableHead className="text-end">{t('إجمالي الإنفاق')}</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {rows.length === 0 ? (
                                <TableEmpty colSpan={4}>{t('لا مشتريات في هذه الفترة')}</TableEmpty>
                            ) : (
                                rows.map((r, i) => (
                                    <TableRow key={`${r.name}-${i}`}>
                                        <TableCell className="text-center tabular-nums text-[#9ca3af]">{i + 1}</TableCell>
                                        <TableCell className="font-medium text-[#111]">{r.name}</TableCell>
                                        <TableCell className="text-end tabular-nums">{number(r.orders)}</TableCell>
                                        <TableCell className="text-end tabular-nums">{m(r.total)}</TableCell>
                                    </TableRow>
                                ))
                            )}
                        </TableBody>
                    </Table>
                </Card>

                {/* السقف يُقال ولا يُخفى: قائمةٌ مبتورةٌ بلا ما يقول ذلك تُقرأ على أنها كلُّ العملاء */}
                {rows.length >= limit && (
                    <p className="mt-3 text-[12px] text-[#9ca3af]">
                        {t('تُعرض أعلى :n عميلًا إنفاقًا في هذه الفترة.', { n: limit })}
                    </p>
                )}
            </div>
        </AdminLayout>
    );
}
