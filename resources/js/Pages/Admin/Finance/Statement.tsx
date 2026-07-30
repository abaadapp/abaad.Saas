import { usePage } from '@inertiajs/react';
import { ArrowRight, Landmark } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
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
import { money, number } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { PageProps } from '@/types';

interface StatementRow {
    id: number;
    reference: string;
    date: string;
    description: string;
    method: string;
    debit: number;
    credit: number;
    balance: number;
}

interface Props {
    account: {
        bank_name: string | null;
        account_name: string | null;
        iban: string | null;
        opening_balance: number;
        opening_date: string | null;
    };
    statement: { opening: number; rows: StatementRow[]; closing: number };
    reconciliation: {
        lines: number;
        matched: number;
        unmatched_bank: number;
        unmatched_system: number;
        bank_total: number;
    };
}

export default function FinanceStatement() {
    const { account, statement, reconciliation, context } = usePage<PageProps<Props>>().props;
    const t = useTranslate();
    const currency = context!.currency;
    const m = (v: number) => money(v, currency);

    const facts = [
        { label: 'البنك', value: account.bank_name },
        { label: 'اسم الحساب', value: account.account_name },
        { label: 'الآيبان', value: account.iban, ltr: true },
        { label: 'تاريخ الافتتاح', value: account.opening_date, ltr: true },
    ];

    const recon = [
        { label: 'أسطر كشف البنك', value: reconciliation.lines },
        { label: 'مطابَقة', value: reconciliation.matched },
        { label: 'غير مطابَقة (بنك)', value: reconciliation.unmatched_bank },
        { label: 'غير مطابَقة (نظام)', value: reconciliation.unmatched_system },
    ];

    return (
        <AdminLayout title="كشف الحساب البنكي">
            <PageHeader
                title="كشف الحساب البنكي"
                subtitle={t('الحركات الداخلة والخارجة ورصيد الحساب')}
                breadcrumbs={[
                    { label: 'الرئيسية', href: route('admin.dashboard') },
                    { label: 'المالية', href: route('admin.finance.index') },
                    { label: 'كشف الحساب' },
                ]}
                actions={
                    <Button variant="outline" asChild>
                        <SmartLink routeName="admin.finance.index" href={route('admin.finance.index')}>
                            <ArrowRight />
                            {t('رجوع للمالية')}
                        </SmartLink>
                    </Button>
                }
            />

            <div className="mb-6 grid grid-cols-1 gap-6 lg:grid-cols-3">
                <Card className="p-5">
                    <h3 className="mb-4 flex items-center gap-2 font-bold text-[#111]">
                        <Landmark className="size-4 text-[#6d28d9]" />
                        {t('بيانات الحساب')}
                    </h3>
                    <ul className="space-y-3 text-sm">
                        {facts.map((f) => (
                            <li key={f.label} className="flex items-center justify-between gap-3">
                                <span className="text-[#6b7280]">{t(f.label)}</span>
                                <span dir={f.ltr ? 'ltr' : undefined} className="truncate text-[#111]">
                                    {f.value || '—'}
                                </span>
                            </li>
                        ))}
                    </ul>
                </Card>

                <Card className="p-5">
                    <h3 className="mb-4 font-bold text-[#111]">{t('الأرصدة')}</h3>
                    <ul className="space-y-3 text-sm">
                        <li className="flex items-center justify-between">
                            <span className="text-[#6b7280]">{t('الرصيد الافتتاحي')}</span>
                            <span className="tabular-nums text-[#111]">{m(statement.opening)}</span>
                        </li>
                        <li className="flex items-center justify-between border-t border-[#f5f5f4] pt-3">
                            <span className="font-bold text-[#111]">{t('الرصيد الختامي')}</span>
                            <span
                                className={cn(
                                    'text-[18px] font-bold tabular-nums',
                                    statement.closing >= 0 ? 'text-[#047857]' : 'text-[#b91c1c]',
                                )}
                            >
                                {m(statement.closing)}
                            </span>
                        </li>
                    </ul>
                </Card>

                <Card className="p-5">
                    <h3 className="mb-4 font-bold text-[#111]">{t('المطابقة البنكية')}</h3>
                    <ul className="space-y-3 text-sm">
                        {recon.map((r) => (
                            <li key={r.label} className="flex items-center justify-between">
                                <span className="text-[#6b7280]">{t(r.label)}</span>
                                <span className="tabular-nums text-[#111]">{number(r.value)}</span>
                            </li>
                        ))}
                    </ul>
                </Card>
            </div>

            <Card className="overflow-hidden">
                <div className="border-b border-[var(--ui-border,#e8e8e8)] px-5 py-4">
                    <h3 className="font-bold text-[#111]">{t('الحركات')}</h3>
                </div>
                <Table>
                    <TableHeader>
                        <TableRow className="hover:bg-transparent">
                            <TableHead>{t('المرجع')}</TableHead>
                            <TableHead>{t('التاريخ')}</TableHead>
                            <TableHead>{t('الوصف')}</TableHead>
                            <TableHead>{t('الوسيلة')}</TableHead>
                            <TableHead className="text-end">{t('مدين')}</TableHead>
                            <TableHead className="text-end">{t('دائن')}</TableHead>
                            <TableHead className="text-end">{t('الرصيد')}</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {statement.rows.length === 0 ? (
                            <TableEmpty colSpan={7}>{t('لا توجد حركات بعد')}</TableEmpty>
                        ) : (
                            statement.rows.map((r) => (
                                <TableRow key={r.id}>
                                    <TableCell className="font-mono text-[#4b4b4b]">{r.reference}</TableCell>
                                    <TableCell dir="ltr" className="text-[#6b7280]">
                                        {r.date}
                                    </TableCell>
                                    <TableCell>{r.description}</TableCell>
                                    <TableCell className="text-[#6b7280]">{t(r.method)}</TableCell>
                                    <TableCell className="text-end tabular-nums text-[#b91c1c]">
                                        {r.debit > 0 ? m(r.debit) : '—'}
                                    </TableCell>
                                    <TableCell className="text-end tabular-nums text-[#047857]">
                                        {r.credit > 0 ? m(r.credit) : '—'}
                                    </TableCell>
                                    <TableCell
                                        className={cn(
                                            'text-end font-medium tabular-nums',
                                            r.balance < 0 ? 'text-[#b91c1c]' : 'text-[#111]',
                                        )}
                                    >
                                        {m(r.balance)}
                                    </TableCell>
                                </TableRow>
                            ))
                        )}
                    </TableBody>
                </Table>
            </Card>
        </AdminLayout>
    );
}
