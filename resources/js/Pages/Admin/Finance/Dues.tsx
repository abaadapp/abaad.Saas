import { usePage } from '@inertiajs/react';
import { CheckCircle2 } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import SectionTabs, { FINANCE_TABS } from '@/Components/SectionTabs';
import SmartLink from '@/Components/SmartLink';
import StatCard from '@/Components/StatCard';
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
import { money } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import type { PageProps } from '@/types';

interface Due {
    id: number;
    amount: number;
    due: string | null;
    overdue: boolean;
}

interface ExpenseDue extends Due {
    reference: string | null;
    title: string;
    type: string;
}

interface InvoiceDue extends Due {
    reference: string;
    supplier: string;
}

interface PayrollDue {
    id: number;
    number: string;
    period: string;
    amount: number;
    employees: number;
}

interface Props {
    expenses: ExpenseDue[];
    invoices: InvoiceDue[];
    payroll: PayrollDue[];
    totals: { expenses: number; invoices: number; payroll: number; total: number; overdue: number };
}

/**
 * المبالغ المستحقة — ما على المتجر، في مكانٍ واحد.
 *
 * كان المستحقّ مفرّقًا على ثلاث شاشات: فاتورةٌ غير مدفوعة في المصروفات، وسندُ
 * مورّدٍ في المشتريات، وراتبٌ معتمدٌ لم يُصرف في مسيرة الرواتب. ولا شاشة تجمع
 * الثلاثة — فيدفع التاجر ما تذكّره ويفوته ما نسيه، ويكتشف المتأخّر حين
 * يتّصل به صاحبه.
 *
 * والسداد يقع في شاشته لا هنا: هذه شاشةُ «ماذا عليّ؟» لا شاشةُ دفع، وزرُّ
 * دفعٍ في قائمةٍ جامعة يُضغط على السطر الخطأ.
 */
export default function Dues() {
    const { expenses, invoices, payroll, totals, context } = usePage<PageProps<Props>>().props;
    const t = useTranslate();
    const m = (v: number) => money(v, context!.currency);

    const empty = expenses.length === 0 && invoices.length === 0 && payroll.length === 0;

    const dueCell = (row: Due) =>
        row.due ? (
            <span dir="ltr" className={row.overdue ? 'font-medium text-[#b91c1c]' : 'text-[#6b7280]'}>
                {row.due}
            </span>
        ) : (
            <span className="text-[#9ca3af]">—</span>
        );

    return (
        <AdminLayout title="المبالغ المستحقة">
            <PageHeader
                title="المبالغ المستحقة"
                subtitle={t('ما على المتجر: فواتير لم تُدفع، وسندات موردين، ورواتب اعتُمدت ولم تُصرف')}
            />

            <SectionTabs tabs={FINANCE_TABS} current="admin.finance.dues" />

            <div className="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <StatCard stat={{ label: t('مجموع ما عليك'), value: m(totals.total), icon: 'wallet', color: 'warning' }} index={0} />
                <StatCard stat={{ label: t('فواتير مصروفات'), value: m(totals.expenses), icon: 'receipt', color: 'danger' }} index={1} />
                <StatCard stat={{ label: t('سندات موردين'), value: m(totals.invoices), icon: 'truck', color: 'info' }} index={2} />
                <StatCard stat={{ label: t('رواتب لم تُصرف'), value: m(totals.payroll), icon: 'users', color: 'primary' }} index={3} />
            </div>

            {empty ? (
                <Card className="p-12 text-center">
                    <CheckCircle2 className="mx-auto mb-3 size-8 text-[#a7f3d0]" />
                    <p className="font-medium text-[#374151]">{t('لا شيء مستحقّ الآن')}</p>
                    <p className="mx-auto mt-1 max-w-md text-[13px] text-[#9ca3af]">
                        {t('كلّ ما سُجّل من فواتير وسندات ورواتب قد سُدّد.')}
                    </p>
                </Card>
            ) : (
                <div className="space-y-6">
                    {expenses.length > 0 && (
                        <Card className="overflow-hidden">
                            <div className="flex flex-wrap items-center justify-between gap-2 border-b border-[var(--ui-border,#e8e8e8)] px-5 py-4">
                                <h2 className="text-[15px] font-bold text-[#111]">{t('فواتير مصروفات لم تُدفع')}</h2>
                                <Button variant="outline" size="sm" asChild>
                                    <SmartLink routeName="admin.expenses.index" href={route('admin.expenses.index')}>
                                        {t('شاشة المصروفات')}
                                    </SmartLink>
                                </Button>
                            </div>
                            <div className="overflow-x-auto">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>{t('المرجع')}</TableHead>
                                            <TableHead>{t('البيان')}</TableHead>
                                            <TableHead>{t('النوع')}</TableHead>
                                            <TableHead>{t('تاريخ الاستحقاق')}</TableHead>
                                            <TableHead className="text-end">{t('المبلغ')}</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {expenses.map((e) => (
                                            <TableRow key={e.id}>
                                                <TableCell className="font-mono text-[13px] text-[#6b7280]">
                                                    {e.reference || '—'}
                                                </TableCell>
                                                <TableCell className="text-[#111]">{e.title}</TableCell>
                                                <TableCell>
                                                    <Badge variant="neutral">{e.type}</Badge>
                                                </TableCell>
                                                <TableCell>{dueCell(e)}</TableCell>
                                                <TableCell className="text-end font-semibold tabular-nums">
                                                    {m(e.amount)}
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            </div>
                        </Card>
                    )}

                    {invoices.length > 0 && (
                        <Card className="overflow-hidden">
                            <div className="flex flex-wrap items-center justify-between gap-2 border-b border-[var(--ui-border,#e8e8e8)] px-5 py-4">
                                <h2 className="text-[15px] font-bold text-[#111]">{t('سندات موردين لم تُسدَّد')}</h2>
                                <Button variant="outline" size="sm" asChild>
                                    <SmartLink
                                        routeName="admin.purchases.invoices"
                                        href={route('admin.purchases.invoices')}
                                    >
                                        {t('سندات الموردين')}
                                    </SmartLink>
                                </Button>
                            </div>
                            <div className="overflow-x-auto">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>{t('رقم السند')}</TableHead>
                                            <TableHead>{t('المورّد')}</TableHead>
                                            <TableHead>{t('تاريخ الاستحقاق')}</TableHead>
                                            <TableHead className="text-end">{t('المتبقّي')}</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {invoices.map((i) => (
                                            <TableRow key={i.id}>
                                                <TableCell className="font-mono text-[13px] text-[#6b7280]">
                                                    {i.reference}
                                                </TableCell>
                                                <TableCell className="text-[#111]">{i.supplier}</TableCell>
                                                <TableCell>{dueCell(i)}</TableCell>
                                                <TableCell className="text-end font-semibold tabular-nums">
                                                    {m(i.amount)}
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            </div>
                        </Card>
                    )}

                    {payroll.length > 0 && (
                        <Card className="overflow-hidden">
                            <div className="flex flex-wrap items-center justify-between gap-2 border-b border-[var(--ui-border,#e8e8e8)] px-5 py-4">
                                <h2 className="text-[15px] font-bold text-[#111]">{t('رواتب اعتُمدت ولم تُصرف')}</h2>
                                <Button variant="outline" size="sm" asChild>
                                    <SmartLink
                                        routeName="admin.payroll.payments"
                                        href={route('admin.payroll.payments')}
                                    >
                                        {t('صرف الرواتب')}
                                    </SmartLink>
                                </Button>
                            </div>
                            <div className="overflow-x-auto">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>{t('المسيرة')}</TableHead>
                                            <TableHead>{t('الشهر')}</TableHead>
                                            <TableHead>{t('موظفون')}</TableHead>
                                            <TableHead className="text-end">{t('المتبقّي')}</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {payroll.map((p) => (
                                            <TableRow key={p.id}>
                                                <TableCell className="font-mono text-[13px] text-[#6b7280]">
                                                    {p.number}
                                                </TableCell>
                                                <TableCell dir="ltr" className="text-[#111]">
                                                    {p.period}
                                                </TableCell>
                                                <TableCell className="tabular-nums text-[#6b7280]">
                                                    {p.employees}
                                                </TableCell>
                                                <TableCell className="text-end font-semibold tabular-nums">
                                                    {m(p.amount)}
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            </div>
                        </Card>
                    )}
                </div>
            )}
        </AdminLayout>
    );
}
