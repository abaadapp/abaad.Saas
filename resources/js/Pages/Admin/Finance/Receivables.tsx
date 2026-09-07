import { Link, usePage } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import SectionTabs, { FINANCE_TABS } from '@/Components/SectionTabs';
import StatCard from '@/Components/StatCard';
import { Card } from '@/Components/ui/card';
import { money } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import type { PageProps } from '@/types';

interface Props {
    totals: { total: number; overdue: number; due_soon: number; credit: number; invoices: number; customers: number };
    aging: {
        buckets: { key: string; label: string; amount: number }[];
        customers: { customer_id: number; customer: string; outstanding: number; overdue: number; oldest_invoice: string | null; days_overdue: number }[];
        total: number;
    };
    invoices: { id: number; number: string; customer: string; due_at: string | null; total: number; outstanding: number; days_overdue: number; state: string }[];
    reconciliation: { operational: number; ledger: number; difference: number; balanced: boolean };
}

/**
 * الذممُ المدينة — «ما لك».
 *
 * وهي غير «المستحقّات»: تلك ما على المتجر لمورّديه وموظّفيه، وهذه ما له على
 * عملائه. ورقمان في شاشةٍ واحدة بلا فصلٍ يجعل التاجر لا يعرف أدائنٌ هو أم
 * مدين.
 */
export default function Receivables({ totals, aging, invoices, reconciliation }: Props) {
    const { context } = usePage<PageProps>().props;
    const t = useTranslate();
    const m = (v: number) => money(v, context!.currency);

    return (
        <AdminLayout title={t('الذمم المدينة')}>
            <PageHeader title={t('الذمم المدينة')} subtitle={t('ما لك على عملائك — وما تأخّر منه.')} />
            <SectionTabs tabs={FINANCE_TABS} current="admin.finance.receivables" />

            <div className="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
                <StatCard stat={{ label: t('إجمالي الذمم'), value: m(totals.total), icon: 'wallet', color: 'primary' }} index={0} />
                <StatCard stat={{ label: t('المتأخر'), value: m(totals.overdue), icon: 'alert-triangle', color: 'danger' }} index={1} />
                <StatCard stat={{ label: t('يستحق خلال ٧ أيام'), value: m(totals.due_soon), icon: 'calendar', color: 'warning' }} index={2} />
                <StatCard stat={{ label: t('عملاء مدينون'), value: String(totals.customers), icon: 'users', color: 'success' }} index={3} />
            </div>

            {/* والمطابقةُ تُعرض لا تُخفى: نظامٌ يقول رقمًا يجب أن يستطيع إثباته */}
            <Card
                className={
                    'mb-4 p-3 text-[13px] ' +
                    (reconciliation.balanced
                        ? 'border-[#bbf7d0] bg-[#f0fdf4] text-[#166534]'
                        : 'border-[#fecaca] bg-[#fef2f2] text-[#b91c1c]')
                }
            >
                {reconciliation.balanced
                    ? t('الذمم تطابق حساب «ذمم العملاء» في دفتر الأستاذ.')
                    : t('فرقٌ بين الذمم ودفتر الأستاذ: :n — راجع القيود اليدوية على حساب ذمم العملاء.', {
                          n: m(reconciliation.difference),
                      })}
            </Card>

            <Card className="mb-4 p-4">
                <h3 className="mb-3 text-[13px] font-bold">{t('أعمار الديون')}</h3>
                <div className="grid grid-cols-2 gap-3 lg:grid-cols-5">
                    {aging.buckets.map((b) => (
                        <div key={b.key} className="rounded-[10px] border border-[var(--ui-border,#e8e8e8)] p-3">
                            <div className="text-[12px] text-[#71717a]">{t(b.label)}</div>
                            <div className="text-[15px] font-bold">{m(b.amount)}</div>
                        </div>
                    ))}
                </div>
            </Card>

            <div className="grid gap-4 lg:grid-cols-2">
                <Card className="overflow-x-auto p-0">
                    <h3 className="p-3 text-[13px] font-bold">{t('حسب العميل')}</h3>
                    <table className="w-full text-[13px]">
                        <tbody>
                            {aging.customers.map((c) => (
                                <tr key={c.customer_id} className="border-t border-[var(--ui-border,#e8e8e8)]">
                                    <td className="p-3">
                                        <Link href={`/admin/finance/receivables/${c.customer_id}/statement`} className="text-[#1d4ed8]">
                                            {c.customer}
                                        </Link>
                                        {c.days_overdue > 0 && (
                                            <div className="text-[11px] text-[#b91c1c]">
                                                {t('أقدم متأخّر :ref منذ :n يومًا', { ref: c.oldest_invoice ?? '', n: c.days_overdue })}
                                            </div>
                                        )}
                                    </td>
                                    <td className="p-3 text-end font-medium">{m(c.outstanding)}</td>
                                </tr>
                            ))}
                            {aging.customers.length === 0 && (
                                <tr><td className="p-8 text-center text-[#9ca3af]">{t('لا ذمم قائمة')}</td></tr>
                            )}
                        </tbody>
                    </table>
                </Card>

                <Card className="overflow-x-auto p-0">
                    <h3 className="p-3 text-[13px] font-bold">{t('الفواتير القائمة')}</h3>
                    <table className="w-full text-[13px]">
                        <tbody>
                            {invoices.map((i) => (
                                <tr key={i.id} className="border-t border-[var(--ui-border,#e8e8e8)]">
                                    <td className="p-3">
                                        <Link href={`/admin/customer-invoices/${i.id}`} className="text-[#1d4ed8]">{i.number}</Link>
                                        <div className="text-[11px] text-[#9ca3af]">{i.customer}</div>
                                    </td>
                                    <td className="p-3" dir="ltr">{i.due_at ?? '—'}</td>
                                    <td className="p-3 text-end font-medium">{m(i.outstanding)}</td>
                                </tr>
                            ))}
                            {invoices.length === 0 && (
                                <tr><td className="p-8 text-center text-[#9ca3af]">{t('لا فواتير قائمة')}</td></tr>
                            )}
                        </tbody>
                    </table>
                </Card>
            </div>
        </AdminLayout>
    );
}
