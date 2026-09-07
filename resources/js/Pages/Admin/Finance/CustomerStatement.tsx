import { router, useForm, usePage } from '@inertiajs/react';
import { FileText } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import StatCard from '@/Components/StatCard';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { money } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import type { PageProps } from '@/types';

interface Props {
    customer: {
        id: number;
        name: string;
        type: string;
        allow_credit_sales: boolean;
        credit_limit: number | null;
        payment_terms_days: number | null;
    };
    statement: {
        opening: number;
        rows: { at: string | null; kind: string; ref: string; debit: number; credit: number; balance: number }[];
        closing: number;
    };
    summary: { outstanding: number; credit: number; headroom: number | null };
    range: { from: string; to: string };
}

/** حسابُ العميل — رصيدُه وحركتُه وشروطُ ائتمانه في شاشةٍ واحدة */
export default function CustomerStatement({ customer, statement, summary, range }: Props) {
    const { context } = usePage<PageProps>().props;
    const t = useTranslate();
    const m = (v: number) => money(v, context!.currency);

    const credit = useForm({
        allow_credit_sales: customer.allow_credit_sales,
        credit_limit: customer.credit_limit === null ? '' : String(customer.credit_limit),
        payment_terms_days: customer.payment_terms_days === null ? '' : String(customer.payment_terms_days),
    });

    return (
        <AdminLayout title={t('كشف الحساب')}>
            <PageHeader
                title={customer.name}
                subtitle={t('كشف الحساب وشروط الائتمان')}
                actions={
                    <Button variant="outline" asChild>
                        <a
                            href={`/admin/finance/receivables/${customer.id}/statement/pdf?from=${range.from}&to=${range.to}`}
                            target="_blank"
                            rel="noreferrer"
                        >
                            <FileText />
                            {t('تصدير PDF')}
                        </a>
                    </Button>
                }
            />

            <div className="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
                <StatCard stat={{ label: t('المستحق'), value: m(summary.outstanding), icon: 'wallet', color: 'primary' }} index={0} />
                <StatCard stat={{ label: t('رصيد دائن'), value: m(summary.credit), icon: 'coins', color: 'success' }} index={1} />
                <StatCard
                    stat={{
                        label: t('المتاح من حد الائتمان'),
                        value: summary.headroom === null ? t('بلا حد') : m(summary.headroom),
                        icon: 'shield',
                        color: 'warning',
                    }}
                    index={2}
                />
                <StatCard stat={{ label: t('رصيد آخر المدة'), value: m(statement.closing), icon: 'file-text', color: 'primary' }} index={3} />
            </div>

            <Card className="mb-4 flex flex-wrap items-end gap-3 p-4">
                <label className="flex items-center gap-2 text-[13px]">
                    <input
                        type="checkbox"
                        checked={credit.data.allow_credit_sales}
                        onChange={(e) => credit.setData('allow_credit_sales', e.target.checked)}
                    />
                    {t('السماح بالبيع الآجل')}
                </label>
                <label className="text-[13px]">
                    {t('حد الائتمان')}
                    <Input value={credit.data.credit_limit} onChange={(e) => credit.setData('credit_limit', e.target.value)} />
                </label>
                <label className="text-[13px]">
                    {t('مدة السداد (يومًا)')}
                    <Input value={credit.data.payment_terms_days} onChange={(e) => credit.setData('payment_terms_days', e.target.value)} />
                </label>
                <Button
                    disabled={credit.processing}
                    onClick={() => credit.put(`/admin/customers/${customer.id}/credit`, { preserveScroll: true })}
                >
                    {t('حفظ')}
                </Button>
            </Card>

            <Card className="mb-4 flex flex-wrap items-end gap-2 p-3">
                <label className="text-[13px]">
                    {t('من')}
                    <Input
                        type="date"
                        defaultValue={range.from}
                        onChange={(e) => router.get(window.location.pathname, { from: e.target.value, to: range.to }, { preserveState: true })}
                    />
                </label>
                <label className="text-[13px]">
                    {t('إلى')}
                    <Input
                        type="date"
                        defaultValue={range.to}
                        onChange={(e) => router.get(window.location.pathname, { from: range.from, to: e.target.value }, { preserveState: true })}
                    />
                </label>
            </Card>

            <Card className="overflow-x-auto p-0">
                <table className="w-full text-[13px]">
                    <thead className="bg-[#fafafa] text-[12px] text-[#71717a]">
                        <tr>
                            <th className="p-3 text-start">{t('التاريخ')}</th>
                            <th className="p-3 text-start">{t('البيان')}</th>
                            <th className="p-3 text-start">{t('المرجع')}</th>
                            <th className="p-3 text-start">{t('مدين')}</th>
                            <th className="p-3 text-start">{t('دائن')}</th>
                            <th className="p-3 text-start">{t('الرصيد')}</th>
                        </tr>
                    </thead>
                    <tbody>
                        {/* الرصيدُ الافتتاحيّ سطرٌ صريح: كشفٌ يبدأ من صفرٍ وهو ليس صفرًا
                            يجعل العميل يحتجّ على رقمٍ لا يعرف من أين جاء */}
                        <tr className="border-t border-[var(--ui-border,#e8e8e8)] font-medium">
                            <td className="p-3" colSpan={5}>{t('رصيد ما قبل المدة')}</td>
                            <td className="p-3">{m(statement.opening)}</td>
                        </tr>
                        {statement.rows.map((r, i) => (
                            <tr key={i} className="border-t border-[var(--ui-border,#e8e8e8)]">
                                <td className="p-3" dir="ltr">{r.at ?? '—'}</td>
                                <td className="p-3">{t(r.kind)}</td>
                                <td className="p-3" dir="ltr">{r.ref}</td>
                                <td className="p-3">{r.debit > 0 ? m(r.debit) : '—'}</td>
                                <td className="p-3">{r.credit > 0 ? m(r.credit) : '—'}</td>
                                <td className="p-3">{m(r.balance)}</td>
                            </tr>
                        ))}
                        {statement.rows.length === 0 && (
                            <tr><td colSpan={6} className="p-8 text-center text-[#9ca3af]">{t('لا حركة في هذه المدة')}</td></tr>
                        )}
                    </tbody>
                </table>
            </Card>
        </AdminLayout>
    );
}
